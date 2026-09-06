<?php
if (!defined('ABSPATH')) { exit; }

/** Reverses one reconciliation commit inside WordPress. Never calls Google Drive. */
class Olama_Media_Reconciliation_Rollback
{
    const CONFIRMATION_PHRASE = 'ROLLBACK REVIEWED LINKS';

    private $mapping;

    public function __construct($mapping = null)
    {
        $this->mapping = $mapping ?: new Olama_Media_Drive_Mapping();
    }

    public function readiness($mapping_id)
    {
        global $wpdb;
        foreach (array(
            $wpdb->prefix . 'olama_drive_reconciliation_items',
            $wpdb->prefix . 'olama_drive_files',
            $wpdb->prefix . 'olama_lesson_video_links',
            $wpdb->prefix . 'olama_drive_sync_runs',
            $wpdb->prefix . 'olama_drive_sync_events',
            $wpdb->prefix . 'olama_curriculum_drive_map',
        ) as $table) {
            $status = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name=%s', $table));
            if (!$status || strtoupper((string) $status->Engine) !== 'INNODB') {
                return new WP_Error('reconciliation_rollback_engine_required', sprintf(__('The table %s must use InnoDB before reconciliation can be rolled back.', 'olama-media-library'), $table));
            }
        }

        $context = $this->context($mapping_id);
        if (is_wp_error($context)) { return $context; }
        return $this->assess($context, false);
    }

    public function rollback($mapping_id, $confirmation_text)
    {
        global $wpdb;
        if (!hash_equals(self::CONFIRMATION_PHRASE, trim((string) $confirmation_text))) {
            return new WP_Error('reconciliation_rollback_confirmation_required', __('Type the exact rollback confirmation phrase.', 'olama-media-library'));
        }

        $before = $this->readiness($mapping_id);
        if (is_wp_error($before)) { return $before; }
        if (!empty($before['already_rolled_back'])) { return $before; }
        if (empty($before['ready'])) {
            return new WP_Error('reconciliation_rollback_not_ready', __('The latest reconciliation commit cannot be rolled back safely.', 'olama-media-library'), $before);
        }

        $context = $this->context($mapping_id);
        if (is_wp_error($context)) { return $context; }
        $staging = $wpdb->prefix . 'olama_drive_reconciliation_items';
        $files = $wpdb->prefix . 'olama_drive_files';
        $links = $wpdb->prefix . 'olama_lesson_video_links';
        $runs = $wpdb->prefix . 'olama_drive_sync_runs';
        $events = $wpdb->prefix . 'olama_drive_sync_events';

        if (false === $wpdb->query('START TRANSACTION')) {
            return new WP_Error('reconciliation_rollback_transaction_failed', __('Could not start the rollback transaction.', 'olama-media-library'));
        }

        try {
            $locked_mapping = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}olama_curriculum_drive_map WHERE id=%d FOR UPDATE", absint($context['mapping']->id)
            ));
            $locked_commit = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$runs} WHERE id=%d AND run_type='reconciliation_commit' AND status='completed' FOR UPDATE",
                absint($context['commit_run']->id)
            ));
            if (!$locked_mapping || sanitize_key($locked_mapping->mapping_status) !== 'confirmed' || !$locked_commit) {
                throw new RuntimeException('The mapping or reconciliation commit changed before rollback.');
            }
            $context['items'] = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$staging} WHERE subject_mapping_id=%d AND commit_run_id=%d ORDER BY id FOR UPDATE",
                absint($locked_mapping->id), absint($locked_commit->id)
            ));
            $locked = $this->assess($context, true);
            if (is_wp_error($locked) || empty($locked['ready'])) {
                throw new RuntimeException('The locked reconciliation commit is no longer safe to roll back.');
            }

            $now = current_time('mysql');
            $rollback_uuid = wp_generate_uuid4();
            if (!$wpdb->insert($runs, array(
                'run_uuid'=>$rollback_uuid, 'run_type'=>'reconciliation_rollback', 'dry_run'=>0, 'status'=>'running',
                'academic_year_id'=>absint($locked_mapping->academic_year_id), 'semester_id'=>absint($locked_mapping->semester_id),
                'grade_id'=>absint($locked_mapping->grade_id), 'subject_id'=>absint($locked_mapping->subject_id),
                'started_at'=>$now, 'created_by'=>get_current_user_id(),
            ))) { throw new RuntimeException('Could not create the rollback audit run.'); }
            $rollback_run_id = absint($wpdb->insert_id);
            $counts = array('deleted'=>0, 'restored'=>0, 'unchanged'=>0, 'skipped'=>0, 'drive_index_deleted'=>0, 'drive_index_restored'=>0);

            foreach ($context['items'] as $item) {
                $action = sanitize_key($item->commit_action ?? '');
                if ($action === 'created') {
                    $deleted = $wpdb->delete($links, array('id'=>absint($item->committed_link_id)));
                    if ($deleted !== 1) { throw new RuntimeException('Could not remove a link created by the reconciliation commit.'); }
                    $counts['deleted']++;
                } elseif (in_array($action, array('promoted', 'reassigned'), true)) {
                    $previous = json_decode((string) $item->previous_link_state, true);
                    if (!$this->valid_link_state($previous) || false === $wpdb->update($links, $previous, array('id'=>absint($item->committed_link_id)))) {
                        throw new RuntimeException('Could not restore a link changed by the reconciliation commit.');
                    }
                    $counts['restored']++;
                } elseif ($action === 'existing') {
                    $counts['unchanged']++;
                } elseif ($action === 'skipped') {
                    $counts['skipped']++;
                } else {
                    throw new RuntimeException('Unknown reconciliation commit action.');
                }

                if ($action !== 'skipped') {
                    $previous_drive_file = json_decode((string) $item->previous_drive_file_state, true);
                    if ($this->valid_drive_file_state($previous_drive_file)) {
                        if (false === $wpdb->update($files, $previous_drive_file, array('id'=>absint($item->committed_drive_file_row_id)))) {
                            throw new RuntimeException('Could not restore a Drive index row changed by the reconciliation commit.');
                        }
                        $counts['drive_index_restored']++;
                    } elseif ($previous_drive_file === null) {
                        $references = (int) $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$links} WHERE drive_file_row_id=%d", absint($item->committed_drive_file_row_id)
                        ));
                        if ($references > 0 || $wpdb->delete($files, array('id'=>absint($item->committed_drive_file_row_id))) !== 1) {
                            throw new RuntimeException('Could not safely remove a Drive index row created by the reconciliation commit.');
                        }
                        $counts['drive_index_deleted']++;
                    } else {
                        throw new RuntimeException('The previous Drive index snapshot is invalid.');
                    }
                }

                if (false === $wpdb->update($staging, array(
                    'commit_status'=>'rolled_back', 'rollback_run_id'=>$rollback_run_id,
                    'rolled_back_at'=>$now, 'updated_at'=>$now,
                ), array('id'=>absint($item->id), 'commit_run_id'=>absint($locked_commit->id)))) {
                    throw new RuntimeException('Could not mark a reconciliation item as rolled back.');
                }
            }

            $summary = array_merge($counts, array(
                'mapping_id'=>absint($locked_mapping->id), 'commit_run_id'=>absint($locked_commit->id),
                'rollback_run_id'=>$rollback_run_id, 'drive_mutations'=>0,
            ));
            if (false === $wpdb->update($runs, array(
                'status'=>'completed', 'files_scanned'=>count($context['items']), 'files_updated'=>$counts['restored'],
                'summary'=>wp_json_encode($summary), 'finished_at'=>$now,
            ), array('id'=>$rollback_run_id))) { throw new RuntimeException('Could not finish the rollback audit run.'); }
            if (!$wpdb->insert($events, array(
                'run_id'=>$rollback_run_id, 'event_type'=>'reconciliation_rollback_completed', 'severity'=>'warning',
                'message'=>'Reviewed reconciliation links rolled back inside WordPress.',
                'context'=>wp_json_encode($summary), 'created_at'=>$now,
            ))) { throw new RuntimeException('Could not write the rollback audit event.'); }
            if (false === $wpdb->query('COMMIT')) { throw new RuntimeException('Could not commit the rollback transaction.'); }

            return array_merge($summary, array('run_uuid'=>$rollback_uuid, 'status'=>'completed', 'drive_mutations'=>0));
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('reconciliation_rollback_failed', $error->getMessage());
        }
    }

    private function context($mapping_id)
    {
        global $wpdb;
        $mapping = $this->mapping->get_confirmed_mapping_by_id($mapping_id);
        if (!$mapping) { return new WP_Error('confirmed_mapping_required', __('A confirmed subject Drive mapping is required.', 'olama-media-library')); }
        $staging = $wpdb->prefix . 'olama_drive_reconciliation_items';
        $runs = $wpdb->prefix . 'olama_drive_sync_runs';
        $commit_run = $wpdb->get_row($wpdb->prepare(
            "SELECT r.* FROM {$runs} r INNER JOIN {$staging} s ON s.commit_run_id=r.id
             WHERE s.subject_mapping_id=%d AND r.run_type='reconciliation_commit' AND r.status='completed'
             ORDER BY r.id DESC LIMIT 1", absint($mapping->id)
        ));
        if (!$commit_run) { return new WP_Error('reconciliation_commit_required', __('No completed reconciliation commit exists for this mapping.', 'olama-media-library')); }
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$staging} WHERE subject_mapping_id=%d AND commit_run_id=%d ORDER BY id",
            absint($mapping->id), absint($commit_run->id)
        ));
        return array('mapping'=>$mapping, 'commit_run'=>$commit_run, 'items'=>$items);
    }

    private function assess($context, $lock_links)
    {
        global $wpdb;
        $links = $wpdb->prefix . 'olama_lesson_video_links';
        $counts = array('created'=>0, 'promoted'=>0, 'reassigned'=>0, 'existing'=>0, 'skipped'=>0, 'rolled_back'=>0);
        $conflicts = array();
        foreach ((array) $context['items'] as $item) {
            $status = sanitize_key($item->commit_status ?? '');
            if ($status === 'rolled_back') { $counts['rolled_back']++; continue; }
            $action = sanitize_key($item->commit_action ?? '');
            if (!isset($counts[$action])) {
                $conflicts[] = array('filename'=>$item->filename, 'type'=>'rollback_snapshot_missing');
                continue;
            }
            $counts[$action]++;
            if ($action === 'skipped') { continue; }
            if ($status !== 'committed') {
                $conflicts[] = array('filename'=>$item->filename, 'type'=>'rollback_item_status_changed');
                continue;
            }
            $suffix = $lock_links ? ' FOR UPDATE' : '';
            $current = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$links} WHERE id=%d LIMIT 1{$suffix}", absint($item->committed_link_id)));
            if (!$current) {
                $conflicts[] = array('filename'=>$item->filename, 'type'=>'rollback_link_missing');
                continue;
            }
            if (!hash_equals((string) $item->committed_link_fingerprint, $this->link_fingerprint($current))) {
                $conflicts[] = array('filename'=>$item->filename, 'type'=>'rollback_link_changed_after_commit');
                continue;
            }
            if (in_array($action, array('promoted', 'reassigned'), true) && !$this->valid_link_state(json_decode((string) $item->previous_link_state, true))) {
                $conflicts[] = array('filename'=>$item->filename, 'type'=>'rollback_previous_state_invalid');
            }
            $drive_file = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}olama_drive_files WHERE id=%d LIMIT 1{$suffix}", absint($item->committed_drive_file_row_id)
            ));
            if (!$drive_file) {
                $conflicts[] = array('filename'=>$item->filename, 'type'=>'rollback_drive_index_missing');
                continue;
            }
            if (!hash_equals((string) $item->committed_drive_file_fingerprint, $this->drive_file_fingerprint($drive_file))) {
                $conflicts[] = array('filename'=>$item->filename, 'type'=>'rollback_drive_index_changed_after_commit');
                continue;
            }
            $previous_drive_file = json_decode((string) $item->previous_drive_file_state, true);
            if ($previous_drive_file !== null && !$this->valid_drive_file_state($previous_drive_file)) {
                $conflicts[] = array('filename'=>$item->filename, 'type'=>'rollback_previous_drive_index_invalid');
            }
        }
        $total = count((array) $context['items']);
        $mutable = $counts['created'] + $counts['promoted'] + $counts['reassigned'] + $counts['existing'];
        if ($counts['rolled_back'] > 0 && $counts['rolled_back'] < $total) {
            $conflicts[] = array('filename'=>'-', 'type'=>'rollback_partial_state_detected');
        }
        return array(
            'mapping_id'=>absint($context['mapping']->id), 'commit_run_id'=>absint($context['commit_run']->id),
            'commit_run_uuid'=>$context['commit_run']->run_uuid, 'total'=>$total, 'counts'=>$counts,
            'conflicts'=>$conflicts, 'ready'=>$mutable > 0 && !$conflicts && $counts['rolled_back'] === 0,
            'already_rolled_back'=>$total > 0 && $counts['rolled_back'] === $total,
            'confirmation_phrase'=>self::CONFIRMATION_PHRASE, 'drive_mutations'=>0,
        );
    }

    private function link_columns()
    {
        return array(
            'drive_file_id', 'drive_file_row_id', 'academic_year_id', 'semester_id', 'grade_id', 'subject_id',
            'unit_id', 'lesson_id', 'part_number', 'sequence_order', 'match_method', 'match_confidence',
            'approval_status', 'link_status', 'notes', 'linked_by', 'approved_by', 'approved_at', 'created_at', 'updated_at',
        );
    }

    private function link_state($link)
    {
        $state = array();
        foreach ($this->link_columns() as $column) { $state[$column] = $link->{$column} ?? null; }
        return $state;
    }

    private function valid_link_state($state)
    {
        return is_array($state) && array_keys($state) === $this->link_columns() && !empty($state['drive_file_id']) && absint($state['lesson_id']) > 0;
    }

    private function link_fingerprint($link)
    {
        return hash('sha256', wp_json_encode($this->link_state($link)));
    }

    private function drive_file_columns()
    {
        return array(
            'drive_file_id', 'drive_folder_id', 'drive_parent_ids', 'drive_path', 'drive_path_hash', 'filename',
            'normalized_filename', 'extension', 'mime_type', 'file_size', 'modified_time', 'web_view_link',
            'web_content_link', 'thumbnail_link', 'video_metadata', 'scan_status', 'academic_year_id',
            'semester_id', 'grade_id', 'subject_id', 'unit_id', 'last_seen_scan_id', 'consecutive_absent_scans',
            'presence_status', 'first_seen_at', 'last_seen_at', 'created_at', 'updated_at',
        );
    }

    private function drive_file_state($file)
    {
        $state = array();
        foreach ($this->drive_file_columns() as $column) { $state[$column] = $file->{$column} ?? null; }
        return $state;
    }

    private function valid_drive_file_state($state)
    {
        return is_array($state) && array_keys($state) === $this->drive_file_columns() && !empty($state['drive_file_id']);
    }

    private function drive_file_fingerprint($file)
    {
        return hash('sha256', wp_json_encode($this->drive_file_state($file)));
    }
}
