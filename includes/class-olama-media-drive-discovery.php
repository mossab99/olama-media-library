<?php
if (!defined('ABSPATH')) { exit; }

/** Read-only, resumable Drive inventory discovery. Never commits authoritative state. */
class Olama_Media_Drive_Discovery
{
    private $drive;
    private $repository;
    private $normalizer;
    private $curriculum;
    private $mapping;

    public function __construct($drive = null, $repository = null, $normalizer = null, $curriculum = null, $mapping = null)
    {
        $this->drive = $drive ?: new Olama_Media_Drive();
        $this->repository = $repository ?: new Olama_Media_Drive_Inventory_Repository();
        $this->normalizer = $normalizer ?: new Olama_Media_Normalizer();
        $this->curriculum = $curriculum;
        $this->mapping = $mapping;
    }

    public function start()
    {
        $root = $this->drive->test_connection();
        if (is_wp_error($root)) { return $root; }
        $root_id = sanitize_text_field($root['id'] ?? '');
        $root_name = sanitize_text_field($root['name'] ?? '');
        if ($root_id === '') { return new WP_Error('missing_root', __('Root Folder ID is missing.', 'olama-media-library')); }
        $root_hash = $this->root_config_hash($root_id);
        return $this->repository->create_run($root_id, $root_name, $root_hash);
    }

    /** Start a merged inventory refresh for one selected curriculum subject. */
    public function start_scope($academic_year_id, $semester_id, $grade_id, $subject_id)
    {
        if (!$this->curriculum) { $this->curriculum = new Olama_Media_Curriculum_Adapter(); }
        if (!$this->mapping) { $this->mapping = new Olama_Media_Drive_Mapping($this->repository, $this->curriculum, $this->normalizer); }
        $ids = array_map('absint', array($academic_year_id, $semester_id, $grade_id, $subject_id));
        if (in_array(0, $ids, true)) {
            return new WP_Error('partial_inventory_scope_invalid', __('Select the academic year, semester, grade, and subject before starting a partial inventory.', 'olama-media-library'));
        }
        $root = $this->drive->test_connection();
        if (is_wp_error($root)) { return $root; }
        $root_id = sanitize_text_field($root['id'] ?? '');
        $root_hash = $this->root_config_hash($root_id);
        $source_run = $this->repository->get_latest_completed_run();
        if (!$source_run || !hash_equals((string) $source_run->root_config_hash, $root_hash)) {
            return new WP_Error('partial_inventory_full_required', __('Run a complete safe inventory before using the folder-specific inventory.', 'olama-media-library'));
        }

        $scope_key = sprintf('subject:%d:%d:%d:%d', $ids[0], $ids[1], $ids[2], $ids[3]);
        $mapping = $this->mapping->get_confirmed_mapping_for_scope($scope_key);
        $target = $mapping ? $this->repository->get_observation_by_drive_id($source_run->id, $mapping->drive_folder_id) : null;
        if (!$target) {
            $target = $this->find_unique_scope_folder($source_run->id, $ids);
            if (is_wp_error($target)) { return $target; }
        }
        if (!$target || $target->item_type !== 'folder') {
            return new WP_Error('partial_inventory_folder_missing', __('The selected subject folder is not uniquely known. Run the complete inventory, then review its folder mapping.', 'olama-media-library'));
        }

        $live = $this->drive->get_file_metadata($target->drive_item_id);
        if (is_wp_error($live)) { return $live; }
        if (!empty($live['trashed']) || ($live['mime_type'] ?? '') !== 'application/vnd.google-apps.folder') {
            return new WP_Error('partial_inventory_target_invalid', __('The selected Drive item is no longer an active folder. Run a complete inventory.', 'olama-media-library'));
        }
        $parent_id = sanitize_text_field(((array) ($live['parents'] ?? array()))[0] ?? '');
        $parent_path = '';
        if ($parent_id === (string) $source_run->root_folder_id) {
            $parent_path = (string) $source_run->root_name;
        } elseif ($parent_id !== '') {
            $parent = $this->repository->get_observation_by_drive_id($source_run->id, $parent_id);
            $parent_path = $parent ? (string) $parent->path_snapshot : '';
        }
        if ($parent_path === '') {
            return new WP_Error('partial_inventory_parent_unknown', __('The selected folder moved beneath an unknown parent. Run a complete inventory to rebuild the route safely.', 'olama-media-library'));
        }
        $target_path = rtrim($parent_path, '/') . '/' . sanitize_text_field($live['name'] ?? $target->item_name);
        $run = $this->repository->create_partial_run($source_run, array(
            'id' => (string) $target->drive_item_id,
            'parent_id' => $parent_id,
            'old_path' => (string) $target->path_snapshot,
            'path' => $target_path,
            'depth' => max(0, substr_count($target_path, '/') - substr_count((string) $source_run->root_name, '/')),
        ), $root_hash, array(
            'academic_year_id' => $ids[0], 'semester_id' => $ids[1],
            'grade_id' => $ids[2], 'subject_id' => $ids[3], 'scope_key' => $scope_key,
        ));
        if (is_wp_error($run)) { return $run; }

        $saved = $this->repository->upsert_observation(array(
            'scan_run_id' => $run->id,
            'drive_item_id' => $target->drive_item_id,
            'item_type' => 'folder',
            'parent_drive_folder_id' => $parent_id,
            'item_name' => $live['name'] ?? $target->item_name,
            'normalized_name' => $this->normalizer->normalize_text($live['name'] ?? $target->item_name),
            'mime_type' => $live['mime_type'],
            'file_size' => $live['size'] ?? 0,
            'path_snapshot' => $target_path,
            'web_view_link' => $live['web_view_link'] ?? '',
            'metadata_json' => wp_json_encode(array('parents' => (array) ($live['parents'] ?? array()))),
        ));
        if (!$saved) {
            $this->repository->finish_run($run->id, 'failed', array('error' => 'Could not refresh the selected folder observation.'));
            return new WP_Error('partial_inventory_target_save_failed', __('Could not stage the selected folder for inventory.', 'olama-media-library'));
        }
        return $run;
    }

    public function batch($run_uuid, $max_pages = 3, $page_size = 200)
    {
        $run = $this->repository->get_run_by_uuid($run_uuid);
        if (!$run) { return new WP_Error('inventory_run_not_found', __('Drive inventory run was not found.', 'olama-media-library')); }
        if ($run->status === 'completed') { return $this->repository->report($run->id); }
        if ($run->status !== 'scanning') { return new WP_Error('inventory_run_not_active', __('Drive inventory run is not active.', 'olama-media-library')); }
        if (!hash_equals((string) $run->root_config_hash, $this->root_config_hash($this->drive->get_root_folder_id()))) {
            $message = __('Drive root configuration changed during discovery. This run was stopped without committing any authoritative state.', 'olama-media-library');
            $this->repository->finish_run($run->id, 'failed', array('error' => $message));
            return new WP_Error('inventory_root_changed', $message);
        }

        $processed = 0;
        while ($processed < min(10, max(1, absint($max_pages)))) {
            $queue = $this->repository->claim_next_queue_item($run->id);
            if (!$queue) { break; }
            $page = $this->drive->list_folder_children_page($queue->drive_folder_id, (string) $queue->page_token, $page_size);
            if (is_wp_error($page)) {
                $this->repository->fail_queue_item($queue->id, $page->get_error_message());
                $this->repository->finish_run($run->id, 'failed', array('error' => $page->get_error_message()));
                return $page;
            }
            $counts = array('folders' => 0, 'files' => 0, 'shortcuts' => 0);
            foreach ((array) ($page['items'] ?? array()) as $item) {
                if (!empty($item['trashed'])) { continue; }
                $type = $this->item_type($item['mime_type'] ?? '');
                $path = trim((string) $queue->path_snapshot, '/');
                $path = ($path === '' ? '' : $path . '/') . (string) ($item['name'] ?? '');
                $modified_time = !empty($item['modified_time']) && strtotime($item['modified_time'])
                    ? gmdate('Y-m-d H:i:s', strtotime($item['modified_time'])) : null;
                $observed = $this->repository->upsert_observation(array(
                    'scan_run_id' => $run->id,
                    'drive_item_id' => $item['id'],
                    'item_type' => $type,
                    'resolved_target_id' => $item['shortcut_target_id'] ?? '',
                    'parent_drive_folder_id' => $queue->drive_folder_id,
                    'item_name' => $item['name'],
                    'normalized_name' => $this->normalizer->normalize_text($item['name']),
                    'mime_type' => $item['mime_type'],
                    'file_size' => $item['size'],
                    'modified_time' => $modified_time,
                    'path_snapshot' => $path,
                    'web_view_link' => $item['web_view_link'],
                    'metadata_json' => wp_json_encode(array(
                        'parents' => $item['parents'], 'drive_id' => $item['drive_id'],
                        'shortcut_target_mime_type' => $item['shortcut_target_mime_type'],
                    )),
                ));
                if (!$observed) {
                    return $this->fail_run($run, $queue, __('Could not stage a Drive inventory observation.', 'olama-media-library'));
                }
                $counts[$type === 'folder' ? 'folders' : ($type === 'shortcut' ? 'shortcuts' : 'files')]++;
                // Shortcuts are recorded but never traversed, even when targeting a folder.
                if ($type === 'folder') {
                    if (!$this->repository->enqueue_folder($run->id, $item['id'], $queue->drive_folder_id, $path, absint($queue->depth) + 1)) {
                        return $this->fail_run($run, $queue, __('Could not persist a Drive inventory queue item.', 'olama-media-library'));
                    }
                }
            }
            if (!$this->repository->finish_queue_page($queue->id, (string) ($page['next_page_token'] ?? ''))) {
                return $this->fail_run($run, $queue, __('Could not checkpoint the Drive inventory queue.', 'olama-media-library'));
            }
            $processed++;
        }

        if (!$this->repository->refresh_run_counts($run->id)) {
            $this->repository->finish_run($run->id, 'failed', array('error' => 'Could not refresh inventory counts.'));
            return new WP_Error('inventory_count_failed', __('Could not update Drive inventory counts.', 'olama-media-library'));
        }

        if ($this->repository->pending_count($run->id) === 0) {
            $report = $this->repository->report($run->id);
            $summary = is_wp_error($report) ? array() : array(
                'duplicate_sibling_folders' => count($report['duplicate_sibling_folders']),
                'authoritative_state_changed' => false,
                'drive_mutations' => 0,
            );
            $this->repository->finish_run($run->id, 'completed', $summary);
        }
        return $this->repository->report($run->id);
    }

    private function item_type($mime_type)
    {
        if ($mime_type === 'application/vnd.google-apps.folder') { return 'folder'; }
        if ($mime_type === 'application/vnd.google-apps.shortcut') { return 'shortcut'; }
        return 'file';
    }

    private function find_unique_scope_folder($run_id, $ids)
    {
        $names = $this->curriculum->get_names($ids[0], $ids[1], $ids[2], $ids[3]);
        $subject = $this->normalizer->normalize_text($names['subject'] ?? '');
        if ($subject === '') { return null; }
        $context = array_filter(array_map(array($this->normalizer, 'normalize_text'), array(
            $names['academic_year'] ?? '', $names['semester'] ?? '', $names['grade'] ?? '',
        )));
        $matches = array();
        foreach ($this->repository->get_folder_observations($run_id) as $folder) {
            $actual = (string) $folder->normalized_name;
            if ($actual !== $subject && str_replace(' ', '', $actual) !== str_replace(' ', '', $subject)) { continue; }
            $path = $this->normalizer->normalize_text($folder->path_snapshot);
            $complete = true;
            foreach ($context as $segment) {
                if (strpos(' ' . $path . ' ', ' ' . $segment . ' ') === false) { $complete = false; break; }
            }
            if ($complete) { $matches[] = $folder; }
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function fail_run($run, $queue, $message)
    {
        $this->repository->fail_queue_item($queue->id, $message);
        $this->repository->finish_run($run->id, 'failed', array('error' => $message));
        return new WP_Error('inventory_staging_failed', $message);
    }

    private function root_config_hash($root_folder_id)
    {
        $settings = get_option('academy_media_library_settings', array());
        return hash('sha256', wp_json_encode(array(
            'root_folder_id' => sanitize_text_field($root_folder_id),
            'root_scope_level' => sanitize_key($settings['root_scope_level'] ?? 'unknown'),
            'root_scope_id' => absint($settings['root_scope_id'] ?? 0),
        )));
    }
}
