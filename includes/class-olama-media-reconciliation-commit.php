<?php
if (!defined('ABSPATH')) { exit; }

/** Commits fully reviewed staging rows to WordPress only. Never calls Google Drive. */
class Olama_Media_Reconciliation_Commit
{
    const CONFIRMATION_PHRASE = 'COMMIT REVIEWED LINKS';

    private $inventory;
    private $mapping;

    public function __construct($inventory = null, $mapping = null)
    {
        $this->inventory = $inventory ?: new Olama_Media_Drive_Inventory_Repository();
        $this->mapping = $mapping ?: new Olama_Media_Drive_Mapping();
    }

    public function readiness($mapping_id)
    {
        global $wpdb;
        $transaction_tables = array(
            $wpdb->prefix . 'olama_drive_reconciliation_items', $wpdb->prefix . 'olama_drive_files',
            $wpdb->prefix . 'olama_lesson_video_links', $wpdb->prefix . 'olama_drive_sync_runs',
            $wpdb->prefix . 'olama_drive_sync_events', $wpdb->prefix . 'olama_curriculum_drive_map',
            $wpdb->prefix . 'olama_drive_scan_runs',
        );
        foreach ($transaction_tables as $table) {
            $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s', $table));
            if (!$status || strtoupper((string) $status->Engine) !== 'INNODB') {
                return new WP_Error('reconciliation_transaction_engine_required', sprintf(__('The table %s must use InnoDB before reconciliation can be committed.', 'olama-media-library'), $table));
            }
        }
        $context = $this->context($mapping_id);
        if (is_wp_error($context)) { return $context; }

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}olama_drive_reconciliation_items WHERE discovery_run_id=%d AND subject_mapping_id=%d ORDER BY id",
            absint($context['run']->id), absint($context['mapping']->id)
        ));
        if (!$items) { return new WP_Error('reconciliation_preview_required', __('Create and review a reconciliation preview first.', 'olama-media-library')); }

        return $this->assess($context, $items);
    }

    public function commit($mapping_id, $confirmation_text)
    {
        global $wpdb;
        if (!hash_equals(self::CONFIRMATION_PHRASE, trim((string) $confirmation_text))) {
            return new WP_Error('reconciliation_confirmation_required', __('Type the exact confirmation phrase before committing reviewed links.', 'olama-media-library'));
        }

        $before = $this->readiness($mapping_id);
        if (is_wp_error($before)) { return $before; }
        if (!empty($before['already_committed'])) {
            return $before + array('committed'=>0, 'existing'=>absint($before['accepted']), 'skipped'=>absint($before['decisions']['rejected']));
        }
        if (empty($before['ready'])) {
            return new WP_Error('reconciliation_not_ready', __('The reconciliation has pending decisions or conflicts and cannot be committed.', 'olama-media-library'), $before);
        }

        $context = $this->context($mapping_id);
        if (is_wp_error($context)) { return $context; }
        $mapping = $context['mapping'];
        $run = $context['run'];
        $staging = $wpdb->prefix . 'olama_drive_reconciliation_items';
        $files = $wpdb->prefix . 'olama_drive_files';
        $links = $wpdb->prefix . 'olama_lesson_video_links';
        $sync_runs = $wpdb->prefix . 'olama_drive_sync_runs';
        $sync_events = $wpdb->prefix . 'olama_drive_sync_events';

        if (false === $wpdb->query('START TRANSACTION')) {
            return new WP_Error('reconciliation_commit_transaction_failed', __('Could not start the reconciliation commit transaction.', 'olama-media-library'));
        }

        try {
            $locked_mapping = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}olama_curriculum_drive_map WHERE id=%d FOR UPDATE", absint($mapping->id)
            ));
            $locked_run = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}olama_drive_scan_runs WHERE id=%d FOR UPDATE", absint($run->id)
            ));
            if (!$locked_mapping || sanitize_key($locked_mapping->mapping_status) !== 'confirmed' || !$locked_run ||
                sanitize_key($locked_run->status) !== 'completed' ||
                !hash_equals((string) $locked_mapping->root_config_hash, (string) $locked_run->root_config_hash) ||
                (string) $locked_mapping->drive_folder_id !== (string) $mapping->drive_folder_id) {
                throw new RuntimeException('The confirmed mapping or inventory changed before commit.');
            }
            $items = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$staging} WHERE discovery_run_id=%d AND subject_mapping_id=%d ORDER BY id FOR UPDATE",
                absint($run->id), absint($mapping->id)
            ));
            $locked = $this->assess($context, $items);
            if (is_wp_error($locked) || empty($locked['ready'])) {
                throw new RuntimeException('The locked reconciliation state is no longer ready.');
            }

            $commit_uuid = wp_generate_uuid4();
            $now = current_time('mysql');
            $inserted = $wpdb->insert($sync_runs, array(
                'run_uuid'=>$commit_uuid, 'run_type'=>'reconciliation_commit', 'dry_run'=>0, 'status'=>'running',
                'academic_year_id'=>absint($mapping->academic_year_id), 'semester_id'=>absint($mapping->semester_id),
                'grade_id'=>absint($mapping->grade_id), 'subject_id'=>absint($mapping->subject_id),
                'started_at'=>$now, 'created_by'=>get_current_user_id(),
            ));
            if (!$inserted) { throw new RuntimeException('Could not create the reconciliation commit audit run.'); }
            $commit_run_id = absint($wpdb->insert_id);
            $counts = array('committed'=>0, 'existing'=>0, 'skipped'=>0, 'manual'=>0);

            foreach ($items as $item) {
                $decision = sanitize_key($item->decision_status);
                if ($decision === 'rejected') {
                    if (false === $wpdb->update($staging, array(
                        'commit_status'=>'skipped', 'commit_run_id'=>$commit_run_id, 'committed_at'=>$now, 'updated_at'=>$now,
                    ), array('id'=>absint($item->id)))) { throw new RuntimeException('Could not mark a rejected staging row as skipped.'); }
                    $counts['skipped']++;
                    continue;
                }

                $observation = $context['observations'][(string) $item->drive_file_id];
                $scope = array(
                    'academic_year_id'=>absint($mapping->academic_year_id), 'semester_id'=>absint($mapping->semester_id),
                    'grade_id'=>absint($mapping->grade_id), 'subject_id'=>absint($mapping->subject_id),
                    'unit_id'=>absint($item->selected_unit_id),
                );
                $drive_row_id = $this->persist_drive_file($files, $observation, $run, $scope, $now);
                if (!$drive_row_id) { throw new RuntimeException('Could not persist an inventoried Drive file.'); }

                $existing_link = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$links} WHERE drive_file_id=%s LIMIT 1 FOR UPDATE", $item->drive_file_id));
                if ($existing_link) {
                    if (!$this->same_committed_link($existing_link, $item, $mapping)) {
                        throw new RuntimeException('A Drive file link conflict appeared during commit.');
                    }
                    $link_id = absint($existing_link->id);
                    $counts['existing']++;
                } else {
                    $reasons = json_decode((string) $item->reasons, true);
                    $part_number = absint($reasons['part_number'] ?? 0) ?: null;
                    $sequence = 1 + (int) $wpdb->get_var($wpdb->prepare(
                        "SELECT COALESCE(MAX(sequence_order),0) FROM {$links} WHERE lesson_id=%d AND link_status='active'",
                        absint($item->selected_lesson_id)
                    ));
                    $manual = $decision === 'manual';
                    $saved = $wpdb->insert($links, array_merge($scope, array(
                        'drive_file_id'=>sanitize_text_field($item->drive_file_id), 'drive_file_row_id'=>$drive_row_id,
                        'lesson_id'=>absint($item->selected_lesson_id), 'part_number'=>$part_number, 'sequence_order'=>$sequence,
                        'match_method'=>$manual ? 'reconciliation_manual' : 'reconciliation_approved',
                        'match_confidence'=>$manual ? 100 : absint($item->confidence),
                        'approval_status'=>'approved', 'link_status'=>'active',
                        'notes'=>$manual ? sprintf('Manual reconciliation override for Drive filename: %s', sanitize_text_field($item->filename)) : null,
                        'linked_by'=>get_current_user_id(), 'approved_by'=>get_current_user_id(), 'approved_at'=>$now,
                        'created_at'=>$now, 'updated_at'=>$now,
                    )));
                    if (!$saved) { throw new RuntimeException('Could not create an approved lesson video link.'); }
                    $link_id = absint($wpdb->insert_id);
                    $counts['committed']++;
                    if ($manual) { $counts['manual']++; }
                }

                if (false === $wpdb->update($staging, array(
                    'commit_status'=>'committed', 'committed_link_id'=>$link_id, 'commit_run_id'=>$commit_run_id,
                    'committed_at'=>$now, 'updated_at'=>$now,
                ), array('id'=>absint($item->id)))) { throw new RuntimeException('Could not finalize a reconciliation staging row.'); }
            }

            $summary = array(
                'mapping_id'=>absint($mapping->id), 'discovery_run_id'=>absint($run->id),
                'accepted'=>absint($locked['accepted']), 'committed'=>$counts['committed'], 'existing'=>$counts['existing'],
                'skipped'=>$counts['skipped'], 'manual'=>$counts['manual'], 'drive_mutations'=>0,
            );
            if (false === $wpdb->update($sync_runs, array(
                'status'=>'completed', 'files_scanned'=>absint($locked['total']), 'auto_linked'=>absint($locked['accepted']),
                'summary'=>wp_json_encode($summary), 'finished_at'=>$now,
            ), array('id'=>$commit_run_id))) { throw new RuntimeException('Could not finish the reconciliation audit run.'); }
            if (!$wpdb->insert($sync_events, array(
                'run_id'=>$commit_run_id, 'event_type'=>'reconciliation_commit_completed', 'severity'=>'info',
                'message'=>'Reviewed reconciliation links committed to WordPress.', 'context'=>wp_json_encode($summary), 'created_at'=>$now,
            ))) { throw new RuntimeException('Could not write the reconciliation commit audit event.'); }
            if (false === $wpdb->query('COMMIT')) { throw new RuntimeException('Could not commit the reconciliation transaction.'); }

            return array_merge($summary, array(
                'run_uuid'=>$commit_uuid, 'commit_run_id'=>$commit_run_id, 'status'=>'completed',
                'authoritative_links_changed'=>$counts['committed'] > 0, 'drive_mutations'=>0,
            ));
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('reconciliation_commit_failed', $error->getMessage());
        }
    }

    private function context($mapping_id)
    {
        global $wpdb;
        $mapping = $this->mapping->get_confirmed_mapping_by_id($mapping_id);
        if (!$mapping) { return new WP_Error('confirmed_mapping_required', __('A confirmed subject Drive mapping is required.', 'olama-media-library')); }
        $run = $this->inventory->get_latest_completed_run();
        if (!$run || !hash_equals((string) $mapping->root_config_hash, (string) $run->root_config_hash)) {
            return new WP_Error('mapping_inventory_mismatch', __('The mapping and latest inventory generation do not match.', 'olama-media-library'));
        }
        $units = (new Olama_Media_DB())->get_curriculum_with_assets(
            $mapping->academic_year_id, $mapping->semester_id, $mapping->grade_id, $mapping->subject_id
        );
        if (is_wp_error($units)) { return $units; }
        $lessons = array();
        foreach ((array) $units as $unit) {
            foreach ((array) $unit->lessons as $lesson) {
                $lessons[absint($lesson->id)] = array('unit_id'=>absint($unit->id), 'title'=>(string) $lesson->lesson_title);
            }
        }
        $observations = array();
        foreach ($this->inventory->get_all_observations($run->id) as $observation) {
            if ($observation->item_type === 'file') { $observations[(string) $observation->drive_item_id] = $observation; }
        }
        return array('mapping'=>$mapping, 'run'=>$run, 'lessons'=>$lessons, 'observations'=>$observations);
    }

    private function assess($context, $items)
    {
        global $wpdb;
        $mapping = $context['mapping'];
        $decisions = array('pending'=>0, 'approved'=>0, 'manual'=>0, 'rejected'=>0);
        $commits = array('pending'=>0, 'committed'=>0, 'skipped'=>0);
        $conflicts = array();
        $manual_overrides = array();

        foreach ((array) $items as $item) {
            $decision = sanitize_key($item->decision_status ?? 'pending');
            if (!isset($decisions[$decision])) { $decision = 'pending'; }
            $decisions[$decision]++;
            $commit_status = sanitize_key($item->commit_status ?? 'pending');
            if (!isset($commits[$commit_status])) { $commit_status = 'pending'; }
            $commits[$commit_status]++;
            if ($decision === 'rejected') { continue; }

            $lesson_id = absint($item->selected_lesson_id);
            $lesson = $context['lessons'][$lesson_id] ?? null;
            if (!$lesson || absint($lesson['unit_id']) !== absint($item->selected_unit_id) ||
                absint($item->selected_unit_id) !== absint($item->proposed_unit_id)) {
                $conflicts[] = array('drive_file_id'=>$item->drive_file_id, 'filename'=>$item->filename, 'type'=>'invalid_curriculum_selection');
                continue;
            }
            if (!isset($context['observations'][(string) $item->drive_file_id])) {
                $conflicts[] = array('drive_file_id'=>$item->drive_file_id, 'filename'=>$item->filename, 'type'=>'inventory_observation_missing');
                continue;
            }
            if ($decision === 'manual') {
                $manual_overrides[] = array('filename'=>$item->filename, 'lesson_id'=>$lesson_id, 'lesson_title'=>$lesson['title']);
            }

            $existing_file = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}olama_drive_files WHERE drive_file_id=%s LIMIT 1", $item->drive_file_id
            ));
            if ($existing_file && !$this->scope_is_empty_or_same($existing_file, $item, $mapping)) {
                $conflicts[] = array('drive_file_id'=>$item->drive_file_id, 'filename'=>$item->filename, 'type'=>'existing_drive_file_scope_conflict');
            }
            $existing_link = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}olama_lesson_video_links WHERE drive_file_id=%s LIMIT 1", $item->drive_file_id
            ));
            if ($existing_link && !$this->same_committed_link($existing_link, $item, $mapping)) {
                $conflicts[] = array('drive_file_id'=>$item->drive_file_id, 'filename'=>$item->filename, 'type'=>'existing_link_conflict');
            }
        }

        $accepted = $decisions['approved'] + $decisions['manual'];
        $reviewed = $accepted + $decisions['rejected'];
        $all_committed = $accepted > 0 && $commits['committed'] === $accepted && $commits['pending'] === 0;
        return array(
            'mapping_id'=>absint($mapping->id), 'run_uuid'=>$context['run']->run_uuid, 'total'=>count($items),
            'reviewed'=>$reviewed, 'accepted'=>$accepted, 'decisions'=>$decisions, 'commit_statuses'=>$commits,
            'conflicts'=>$conflicts, 'manual_overrides'=>$manual_overrides,
            'ready'=>$accepted > 0 && $decisions['pending'] === 0 && !$conflicts && !$all_committed,
            'already_committed'=>$all_committed, 'confirmation_phrase'=>self::CONFIRMATION_PHRASE,
            'authoritative_links_changed'=>false, 'drive_mutations'=>0,
        );
    }

    private function scope_is_empty_or_same($file, $item, $mapping)
    {
        $expected = array(
            'academic_year_id'=>absint($mapping->academic_year_id), 'semester_id'=>absint($mapping->semester_id),
            'grade_id'=>absint($mapping->grade_id), 'subject_id'=>absint($mapping->subject_id),
            'unit_id'=>absint($item->selected_unit_id),
        );
        foreach ($expected as $key=>$value) {
            $current = absint($file->{$key} ?? 0);
            if ($current && $current !== $value) { return false; }
        }
        return true;
    }

    private function same_committed_link($link, $item, $mapping)
    {
        return absint($link->academic_year_id) === absint($mapping->academic_year_id)
            && absint($link->semester_id) === absint($mapping->semester_id)
            && absint($link->grade_id) === absint($mapping->grade_id)
            && absint($link->subject_id) === absint($mapping->subject_id)
            && absint($link->unit_id) === absint($item->selected_unit_id)
            && absint($link->lesson_id) === absint($item->selected_lesson_id)
            && sanitize_key($link->link_status) === 'active'
            && sanitize_key($link->approval_status) === 'approved';
    }

    private function persist_drive_file($table, $observation, $run, $scope, $now)
    {
        global $wpdb;
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE drive_file_id=%s LIMIT 1 FOR UPDATE", $observation->drive_item_id));
        $data = array_merge($scope, array(
            'drive_file_id'=>sanitize_text_field($observation->drive_item_id),
            'drive_folder_id'=>sanitize_text_field($observation->parent_drive_folder_id),
            'drive_parent_ids'=>wp_json_encode(array((string) $observation->parent_drive_folder_id)),
            'drive_path'=>sanitize_text_field($observation->path_snapshot),
            'drive_path_hash'=>hash('sha256', (string) $observation->path_snapshot),
            'filename'=>sanitize_text_field($observation->item_name),
            'normalized_filename'=>sanitize_text_field($observation->normalized_name),
            'extension'=>sanitize_key(pathinfo((string) $observation->item_name, PATHINFO_EXTENSION)),
            'mime_type'=>sanitize_text_field($observation->mime_type), 'file_size'=>absint($observation->file_size),
            'modified_time'=>$observation->modified_time ?: null, 'web_view_link'=>esc_url_raw($observation->web_view_link),
            'video_metadata'=>$observation->metadata_json, 'scan_status'=>'active', 'presence_status'=>'present',
            'last_seen_scan_id'=>absint($run->id), 'consecutive_absent_scans'=>0,
            'last_seen_at'=>$now, 'updated_at'=>$now,
        ));
        if ($existing) {
            foreach ($scope as $key=>$value) {
                $current = absint($existing->{$key} ?? 0);
                if ($current && $current !== absint($value)) { return 0; }
            }
            return false === $wpdb->update($table, $data, array('id'=>absint($existing->id))) ? 0 : absint($existing->id);
        }
        $data['first_seen_at'] = $now;
        $data['created_at'] = $now;
        return $wpdb->insert($table, $data) ? absint($wpdb->insert_id) : 0;
    }
}
