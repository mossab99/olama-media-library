<?php
if (!defined('ABSPATH')) { exit; }

/** Read-only, resumable Drive inventory discovery. Never commits authoritative state. */
class Olama_Media_Drive_Discovery
{
    private $drive;
    private $repository;
    private $normalizer;

    public function __construct($drive = null, $repository = null, $normalizer = null)
    {
        $this->drive = $drive ?: new Olama_Media_Drive();
        $this->repository = $repository ?: new Olama_Media_Drive_Inventory_Repository();
        $this->normalizer = $normalizer ?: new Olama_Media_Normalizer();
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
