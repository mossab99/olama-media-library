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
            $counts = array('committed'=>0, 'existing'=>0, 'promoted'=>0, 'reassigned'=>0, 'skipped'=>0, 'manual'=>0);

            foreach ($items as $item) {
                $decision = sanitize_key($item->decision_status);
                if ($decision === 'rejected') {
                    if (false === $wpdb->update($staging, array(
                        'commit_status'=>'skipped', 'commit_run_id'=>$commit_run_id, 'committed_at'=>$now, 'updated_at'=>$now,
                        'commit_action'=>'skipped', 'previous_link_state'=>null, 'committed_link_fingerprint'=>null,
                        'committed_drive_file_row_id'=>null, 'previous_drive_file_state'=>null,
                        'committed_drive_file_fingerprint'=>null,
                        'rollback_run_id'=>null, 'rolled_back_at'=>null,
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
                $previous_drive_file = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$files} WHERE drive_file_id=%s LIMIT 1 FOR UPDATE", $item->drive_file_id
                ));
                $previous_drive_file_state = $previous_drive_file ? $this->drive_file_state($previous_drive_file) : null;
                $drive_row_id = $this->persist_drive_file($files, $observation, $run, $scope, $now);
                if (!$drive_row_id) { throw new RuntimeException('Could not persist an inventoried Drive file.'); }
                $committed_drive_file = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$files} WHERE id=%d LIMIT 1 FOR UPDATE", $drive_row_id));
                if (!$committed_drive_file) { throw new RuntimeException('Could not verify the committed Drive index row.'); }

                $existing_link = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$links} WHERE drive_file_id=%s LIMIT 1 FOR UPDATE", $item->drive_file_id));
                $previous_link_state = $existing_link ? $this->link_state($existing_link) : null;
                $commit_action = 'created';
                if ($existing_link) {
                    if ($this->same_committed_link($existing_link, $item, $mapping)) {
                        $link_id = absint($existing_link->id);
                        $counts['existing']++;
                        $commit_action = 'existing';
                    } elseif ($this->is_replaceable_pending_generated_link($existing_link)) {
                        $reasons = json_decode((string) $item->reasons, true);
                        $part_number = absint($reasons['part_number'] ?? 0) ?: null;
                        $same_target = $this->same_link_target($existing_link, $item, $mapping);
                        $sequence = $same_target ? max(1, absint($existing_link->sequence_order)) : 1 + (int) $wpdb->get_var($wpdb->prepare(
                            "SELECT COALESCE(MAX(sequence_order),0) FROM {$links} WHERE lesson_id=%d AND link_status='active' AND id<>%d",
                            absint($item->selected_lesson_id), absint($existing_link->id)
                        ));
                        $manual = $decision === 'manual';
                        $updated = $wpdb->update($links, array_merge($scope, array(
                            'drive_file_row_id'=>$drive_row_id, 'lesson_id'=>absint($item->selected_lesson_id),
                            'part_number'=>$part_number, 'sequence_order'=>$sequence,
                            'match_method'=>$manual ? 'reconciliation_manual' : 'reconciliation_approved',
                            'match_confidence'=>$manual ? 100 : absint($item->confidence),
                            'approval_status'=>'approved', 'link_status'=>'active',
                            'notes'=>$manual
                                ? sprintf('Manual reconciliation override for Drive filename: %s', sanitize_text_field($item->filename))
                                : 'Human-reviewed reconciliation replaced a pending generated link.',
                            'linked_by'=>get_current_user_id(), 'approved_by'=>get_current_user_id(),
                            'approved_at'=>$now, 'updated_at'=>$now,
                        )), array('id'=>absint($existing_link->id)));
                        if (false === $updated) { throw new RuntimeException('Could not promote a reviewed pending generated link.'); }
                        $link_id = absint($existing_link->id);
                        $counts[$same_target ? 'promoted' : 'reassigned']++;
                        $commit_action = $same_target ? 'promoted' : 'reassigned';
                        if ($manual) { $counts['manual']++; }
                    } else {
                        throw new RuntimeException('A Drive file link conflict appeared during commit.');
                    }
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

                $committed_link = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$links} WHERE id=%d LIMIT 1 FOR UPDATE", $link_id));
                if (!$committed_link) { throw new RuntimeException('Could not verify the committed lesson video link.'); }

                if (false === $wpdb->update($staging, array(
                    'commit_status'=>'committed', 'committed_link_id'=>$link_id, 'commit_run_id'=>$commit_run_id,
                    'committed_at'=>$now, 'commit_action'=>$commit_action,
                    'previous_link_state'=>$previous_link_state ? wp_json_encode($previous_link_state) : null,
                    'committed_link_fingerprint'=>$this->link_fingerprint($committed_link),
                    'committed_drive_file_row_id'=>$drive_row_id,
                    'previous_drive_file_state'=>$previous_drive_file_state ? wp_json_encode($previous_drive_file_state) : null,
                    'committed_drive_file_fingerprint'=>$this->drive_file_fingerprint($committed_drive_file),
                    'rollback_run_id'=>null, 'rolled_back_at'=>null, 'updated_at'=>$now,
                ), array('id'=>absint($item->id)))) { throw new RuntimeException('Could not finalize a reconciliation staging row.'); }
            }

            $summary = array(
                'mapping_id'=>absint($mapping->id), 'discovery_run_id'=>absint($run->id),
                'accepted'=>absint($locked['accepted']), 'committed'=>$counts['committed'], 'existing'=>$counts['existing'],
                'promoted'=>$counts['promoted'], 'reassigned'=>$counts['reassigned'],
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
                'authoritative_links_changed'=>($counts['committed'] + $counts['promoted'] + $counts['reassigned']) > 0,
                'drive_mutations'=>0,
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
        $planned_link_updates = array('promote_same_target'=>0, 'reassign_target'=>0);

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
                $conflicts[] = array(
                    'drive_file_id'=>$item->drive_file_id, 'filename'=>$item->filename,
                    'type'=>'existing_drive_file_scope_conflict',
                    'current'=>array(
                        'academic_year_id'=>absint($existing_file->academic_year_id), 'semester_id'=>absint($existing_file->semester_id),
                        'grade_id'=>absint($existing_file->grade_id), 'subject_id'=>absint($existing_file->subject_id),
                        'unit_id'=>absint($existing_file->unit_id),
                    ),
                    'proposed'=>array(
                        'academic_year_id'=>absint($mapping->academic_year_id), 'semester_id'=>absint($mapping->semester_id),
                        'grade_id'=>absint($mapping->grade_id), 'subject_id'=>absint($mapping->subject_id),
                        'unit_id'=>absint($item->selected_unit_id),
                    ),
                );
            }
            $existing_link = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}olama_lesson_video_links WHERE drive_file_id=%s LIMIT 1", $item->drive_file_id
            ));
            if ($existing_link && !$this->same_committed_link($existing_link, $item, $mapping)) {
                $same_target = $this->same_link_target($existing_link, $item, $mapping);
                if ($this->is_replaceable_pending_generated_link($existing_link)) {
                    $planned_link_updates[$same_target ? 'promote_same_target' : 'reassign_target']++;
                } else {
                    $conflicts[] = array(
                        'drive_file_id'=>$item->drive_file_id, 'filename'=>$item->filename,
                        'type'=>$same_target ? 'existing_link_same_target_not_approved' : 'existing_link_target_conflict',
                        'current'=>array(
                            'link_id'=>absint($existing_link->id), 'academic_year_id'=>absint($existing_link->academic_year_id),
                            'semester_id'=>absint($existing_link->semester_id), 'grade_id'=>absint($existing_link->grade_id),
                            'subject_id'=>absint($existing_link->subject_id), 'unit_id'=>absint($existing_link->unit_id),
                            'lesson_id'=>absint($existing_link->lesson_id), 'approval_status'=>sanitize_key($existing_link->approval_status),
                            'link_status'=>sanitize_key($existing_link->link_status), 'match_method'=>sanitize_key($existing_link->match_method),
                        ),
                        'proposed'=>array(
                            'academic_year_id'=>absint($mapping->academic_year_id), 'semester_id'=>absint($mapping->semester_id),
                            'grade_id'=>absint($mapping->grade_id), 'subject_id'=>absint($mapping->subject_id),
                            'unit_id'=>absint($item->selected_unit_id), 'lesson_id'=>absint($item->selected_lesson_id),
                            'decision_status'=>$decision,
                        ),
                    );
                }
            }
        }

        $accepted = $decisions['approved'] + $decisions['manual'];
        $reviewed = $accepted + $decisions['rejected'];
        $all_committed = $accepted > 0 && $commits['committed'] === $accepted && $commits['pending'] === 0;
        $conflict_types = array();
        foreach ($conflicts as $conflict) {
            $type = sanitize_key($conflict['type'] ?? 'unknown');
            $conflict_types[$type] = absint($conflict_types[$type] ?? 0) + 1;
        }
        return array(
            'mapping_id'=>absint($mapping->id), 'run_uuid'=>$context['run']->run_uuid, 'total'=>count($items),
            'reviewed'=>$reviewed, 'accepted'=>$accepted, 'decisions'=>$decisions, 'commit_statuses'=>$commits,
            'conflicts'=>$conflicts, 'conflict_types'=>$conflict_types, 'manual_overrides'=>$manual_overrides,
            'planned_link_updates'=>$planned_link_updates,
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
        return $this->same_link_target($link, $item, $mapping)
            && sanitize_key($link->link_status) === 'active'
            && sanitize_key($link->approval_status) === 'approved';
    }

    private function same_link_target($link, $item, $mapping)
    {
        return absint($link->academic_year_id) === absint($mapping->academic_year_id)
            && absint($link->semester_id) === absint($mapping->semester_id)
            && absint($link->grade_id) === absint($mapping->grade_id)
            && absint($link->subject_id) === absint($mapping->subject_id)
            && absint($link->unit_id) === absint($item->selected_unit_id)
            && absint($link->lesson_id) === absint($item->selected_lesson_id);
    }

    /** Only unapproved links produced by a known automatic matcher may be superseded by reviewed staging. */
    private function is_replaceable_pending_generated_link($link)
    {
        $generated_methods = array(
            'filename_lesson_part', 'filename_lesson_number', 'folder_and_title',
            'filename_title', 'filename_title_part', 'filename_lesson_number_part',
            'folder_only', 'folder_only_part',
        );
        return sanitize_key($link->approval_status ?? '') === 'pending'
            && sanitize_key($link->link_status ?? '') === 'active'
            && in_array(sanitize_key($link->match_method ?? ''), $generated_methods, true);
    }

    private function link_state($link)
    {
        $columns = array(
            'drive_file_id', 'drive_file_row_id', 'academic_year_id', 'semester_id', 'grade_id', 'subject_id',
            'unit_id', 'lesson_id', 'part_number', 'sequence_order', 'match_method', 'match_confidence',
            'approval_status', 'link_status', 'notes', 'linked_by', 'approved_by', 'approved_at', 'created_at', 'updated_at',
        );
        $state = array();
        foreach ($columns as $column) { $state[$column] = $link->{$column} ?? null; }
        return $state;
    }

    private function link_fingerprint($link)
    {
        return hash('sha256', wp_json_encode($this->link_state($link)));
    }

    private function drive_file_state($file)
    {
        $columns = array(
            'drive_file_id', 'drive_folder_id', 'drive_parent_ids', 'drive_path', 'drive_path_hash', 'filename',
            'normalized_filename', 'extension', 'mime_type', 'file_size', 'modified_time', 'web_view_link',
            'web_content_link', 'thumbnail_link', 'video_metadata', 'scan_status', 'academic_year_id',
            'semester_id', 'grade_id', 'subject_id', 'unit_id', 'last_seen_scan_id', 'consecutive_absent_scans',
            'presence_status', 'first_seen_at', 'last_seen_at', 'created_at', 'updated_at',
        );
        $state = array();
        foreach ($columns as $column) { $state[$column] = $file->{$column} ?? null; }
        return $state;
    }

    private function drive_file_fingerprint($file)
    {
        return hash('sha256', wp_json_encode($this->drive_file_state($file)));
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
