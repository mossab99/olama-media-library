<?php
if (!defined('ABSPATH')) { exit; }

class Olama_Media_Drive_Indexer
{
    private $drive;
    private $repository;
    private $normalizer;
    private $visited = array();
    private $run_started_at;
    private $stats = array();

    public function __construct($drive = null, $repository = null, $normalizer = null)
    {
        $this->drive = $drive ?: new Olama_Media_Drive();
        $this->repository = $repository ?: new Olama_Media_V2_Repository();
        $this->normalizer = $normalizer ?: new Olama_Media_Normalizer();
    }

    public function scan($options = array())
    {
        $dry_run = !empty($options['dry_run']);
        $max_depth = min(20, max(1, absint($options['max_depth'] ?? 10)));
        $this->run_started_at = current_time('mysql');
        $this->stats = array('files_scanned'=>0,'files_new'=>0,'files_updated'=>0,'files_missing'=>0,'errors'=>0);
        $run_id = $this->repository->create_sync_run(array('run_type'=>'drive_scan','dry_run'=>$dry_run,'started_at'=>$this->run_started_at));
        $root = $this->drive->get_root_folder_id();
        if (!$root) {
            $this->stats['errors']++;
            $this->repository->finish_sync_run($run_id, 'failed', $this->stats);
            return new WP_Error('missing_root', __('Root Folder ID is missing.', 'olama-media-library'));
        }
        $root_info = $this->drive->test_connection();
        $root_path = is_wp_error($root_info) ? array() : array(sanitize_text_field($root_info['name'] ?? ''));
        $result = $this->scan_folder_recursive($root, $root_path, $run_id, $max_depth, 0, $dry_run);
        if (is_wp_error($result)) {
            $this->stats['errors']++;
            $this->repository->log_sync_event($run_id, 'scan_failed', 'error', $result->get_error_message());
            $this->repository->finish_sync_run($run_id, 'failed', $this->stats);
            return $result;
        }
        if (!$dry_run) {
            $this->stats['files_missing'] = $this->repository->mark_missing_drive_files_not_seen_since($this->run_started_at);
        }
        $this->stats['run_id'] = $run_id;
        $this->repository->log_sync_event($run_id, 'drive_scan_completed', 'info', 'Drive scan completed.', $this->stats);
        $this->repository->finish_sync_run($run_id, 'completed', $this->stats);
        return $this->stats;
    }

    public function scan_folder_recursive($folder_id, $path_parts, $run_id, $max_depth = 10, $depth = 0, $dry_run = false)
    {
        if (isset($this->visited[$folder_id]) || $depth > $max_depth) { return array(); }
        $this->visited[$folder_id] = true;
        $children = $this->drive->list_folder_children($folder_id);
        if (is_wp_error($children)) { return $children; }
        foreach ($children as $file) {
            if (!empty($file['trashed'])) { continue; }
            if (($file['mime_type'] ?? '') === 'application/vnd.google-apps.folder') {
                $child_path = array_merge($path_parts, array(sanitize_text_field($file['name'])));
                $result = $this->scan_folder_recursive($file['id'], $child_path, $run_id, $max_depth, $depth + 1, $dry_run);
                if (is_wp_error($result)) { return $result; }
                continue;
            }
            if (!$this->is_video_file($file)) { continue; }
            $this->stats['files_scanned']++;
            $existing = $this->repository->get_drive_file_by_file_id($file['id']);
            $existing ? $this->stats['files_updated']++ : $this->stats['files_new']++;
            if ($dry_run) { continue; }
            $path = implode('/', array_merge($path_parts, array($file['name'])));
            $this->repository->upsert_drive_file(array(
                'drive_file_id'=>sanitize_text_field($file['id']), 'drive_folder_id'=>sanitize_text_field($folder_id),
                'drive_parent_ids'=>wp_json_encode(array_map('sanitize_text_field', (array) $file['parents'])),
                'drive_path'=>sanitize_text_field($path), 'drive_path_hash'=>hash('sha256', $path),
                'filename'=>sanitize_text_field($file['name']), 'normalized_filename'=>$this->normalizer->normalize_filename($file['name']),
                'extension'=>$this->normalizer->extract_extension($file['name']), 'mime_type'=>sanitize_text_field($file['mime_type']),
                'file_size'=>absint($file['size']), 'modified_time'=>$this->mysql_date($file['modified_time'] ?? ''),
                'web_view_link'=>esc_url_raw($file['web_view_link']), 'web_content_link'=>esc_url_raw($file['web_content_link']),
                'thumbnail_link'=>esc_url_raw($file['thumbnail_link']), 'video_metadata'=>wp_json_encode($file['video_media_metadata']),
                'scan_status'=>'active', 'last_seen_at'=>$this->run_started_at,
            ));
        }
        return $this->stats;
    }

    public function is_video_file($file)
    {
        $mime = strtolower((string) ($file['mime_type'] ?? ''));
        return strpos($mime, 'video/') === 0 || in_array($this->normalizer->extract_extension($file['name'] ?? ''), array('mp4','mov','m4v','avi','mkv','webm','mpg','mpeg'), true);
    }

    private function mysql_date($value)
    {
        $time = $value ? strtotime($value) : false;
        return $time ? gmdate('Y-m-d H:i:s', $time) : null;
    }
}
