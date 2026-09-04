<?php
if (!defined('ABSPATH')) { exit; }

/** Local staging persistence for read-only Drive discovery runs. */
class Olama_Media_Drive_Inventory_Repository
{
    private $runs;
    private $observations;
    private $queue;

    public function __construct()
    {
        global $wpdb;
        $this->runs = $wpdb->prefix . 'olama_drive_scan_runs';
        $this->observations = $wpdb->prefix . 'olama_drive_scan_observations';
        $this->queue = $wpdb->prefix . 'olama_drive_scan_queue';
    }

    public function create_run($root_folder_id, $root_name, $root_config_hash)
    {
        global $wpdb;
        $uuid = wp_generate_uuid4();
        $now = current_time('mysql');
        $wpdb->insert($this->runs, array(
            'run_uuid' => $uuid,
            'run_type' => 'inventory_discovery',
            'status' => 'scanning',
            'root_folder_id' => sanitize_text_field($root_folder_id),
            'root_name' => sanitize_text_field($root_name),
            'root_config_hash' => sanitize_text_field($root_config_hash),
            'started_at' => $now,
            'created_by' => get_current_user_id(),
        ));
        if (!$wpdb->insert_id) {
            return new WP_Error('inventory_run_create_failed', __('Could not create the Drive inventory run.', 'olama-media-library'));
        }
        $run_id = (int) $wpdb->insert_id;
        if (!$this->enqueue_folder($run_id, $root_folder_id, '', $root_name, 0)) {
            $this->finish_run($run_id, 'failed', array('error' => 'Could not initialize the inventory queue.'));
            return new WP_Error('inventory_queue_create_failed', __('Could not initialize the Drive inventory queue.', 'olama-media-library'));
        }
        return $this->get_run_by_uuid($uuid);
    }

    public function get_run_by_uuid($uuid)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->runs} WHERE run_uuid=%s LIMIT 1", sanitize_text_field($uuid)));
    }

    public function enqueue_folder($run_id, $folder_id, $parent_id, $path, $depth)
    {
        global $wpdb;
        $now = current_time('mysql');
        return false !== $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$this->queue}
             (scan_run_id,drive_folder_id,parent_drive_folder_id,path_snapshot,depth,status,attempts,created_at,updated_at)
             VALUES (%d,%s,%s,%s,%d,'pending',0,%s,%s)",
            absint($run_id), sanitize_text_field($folder_id), sanitize_text_field($parent_id),
            sanitize_text_field($path), absint($depth), $now, $now
        ));
    }

    public function claim_next_queue_item($run_id)
    {
        global $wpdb;
        $stale_before = gmdate('Y-m-d H:i:s', current_time('timestamp') - 300);
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->queue}
             WHERE scan_run_id=%d AND (status='pending' OR (status='processing' AND updated_at < %s AND attempts < 3))
             ORDER BY depth,id LIMIT 1",
            absint($run_id), $stale_before
        ));
        if (!$item) { return null; }
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$this->queue} SET status='processing',attempts=attempts+1,updated_at=%s
             WHERE id=%d AND (status='pending' OR (status='processing' AND updated_at < %s AND attempts < 3))",
            current_time('mysql'), absint($item->id), $stale_before
        ));
        return $updated === 1 ? $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->queue} WHERE id=%d", absint($item->id))) : null;
    }

    public function finish_queue_page($queue_id, $next_page_token)
    {
        global $wpdb;
        $data = array('updated_at' => current_time('mysql'));
        if ($next_page_token !== '') {
            $data['status'] = 'pending';
            $data['page_token'] = sanitize_text_field($next_page_token);
        } else {
            $data['status'] = 'completed';
            $data['page_token'] = null;
        }
        return false !== $wpdb->update($this->queue, $data, array('id' => absint($queue_id)));
    }

    public function fail_queue_item($queue_id, $message)
    {
        global $wpdb;
        return false !== $wpdb->update($this->queue, array(
            'status' => 'failed',
            'last_error' => sanitize_textarea_field($message),
            'updated_at' => current_time('mysql'),
        ), array('id' => absint($queue_id)));
    }

    public function upsert_observation($data)
    {
        global $wpdb;
        $data = array_merge(array(
            'resolved_target_id' => '', 'parent_drive_folder_id' => '', 'file_size' => 0,
            'modified_time' => null, 'web_view_link' => '', 'metadata_json' => null,
            'observed_at' => current_time('mysql'),
        ), $data);
        $sql = "INSERT INTO {$this->observations}
            (scan_run_id,drive_item_id,item_type,resolved_target_id,parent_drive_folder_id,item_name,normalized_name,mime_type,file_size,modified_time,path_snapshot,web_view_link,metadata_json,observed_at)
            VALUES (%d,%s,%s,%s,%s,%s,%s,%s,%d,%s,%s,%s,%s,%s)
            ON DUPLICATE KEY UPDATE item_type=VALUES(item_type),resolved_target_id=VALUES(resolved_target_id),parent_drive_folder_id=VALUES(parent_drive_folder_id),item_name=VALUES(item_name),normalized_name=VALUES(normalized_name),mime_type=VALUES(mime_type),file_size=VALUES(file_size),modified_time=VALUES(modified_time),path_snapshot=VALUES(path_snapshot),web_view_link=VALUES(web_view_link),metadata_json=VALUES(metadata_json),observed_at=VALUES(observed_at)";
        return false !== $wpdb->query($wpdb->prepare($sql,
            absint($data['scan_run_id']), sanitize_text_field($data['drive_item_id']), sanitize_key($data['item_type']),
            sanitize_text_field($data['resolved_target_id']), sanitize_text_field($data['parent_drive_folder_id']),
            sanitize_text_field($data['item_name']), sanitize_text_field($data['normalized_name']), sanitize_text_field($data['mime_type']),
            absint($data['file_size']), $data['modified_time'], sanitize_text_field($data['path_snapshot']),
            esc_url_raw($data['web_view_link']), $data['metadata_json'], $data['observed_at']
        ));
    }

    public function refresh_run_counts($run_id)
    {
        global $wpdb;
        $counts = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(item_type='folder') folders,SUM(item_type='file') files,SUM(item_type='shortcut') shortcuts
             FROM {$this->observations} WHERE scan_run_id=%d",
            absint($run_id)
        ));
        return false !== $wpdb->update($this->runs, array(
            'folders_observed' => absint($counts->folders ?? 0),
            'files_observed' => absint($counts->files ?? 0),
            'shortcuts_observed' => absint($counts->shortcuts ?? 0),
        ), array('id' => absint($run_id)));
    }

    public function pending_count($run_id)
    {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->queue} WHERE scan_run_id=%d AND status IN ('pending','processing')",
            absint($run_id)
        ));
    }

    public function finish_run($run_id, $status, $summary = array())
    {
        global $wpdb;
        return false !== $wpdb->update($this->runs, array(
            'status' => sanitize_key($status),
            'summary' => wp_json_encode($summary),
            'finished_at' => current_time('mysql'),
        ), array('id' => absint($run_id)));
    }

    public function report($run_id)
    {
        global $wpdb;
        $run = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->runs} WHERE id=%d", absint($run_id)), ARRAY_A);
        if (!$run) { return new WP_Error('inventory_run_not_found', __('Drive inventory run was not found.', 'olama-media-library')); }
        $duplicates = $wpdb->get_results($wpdb->prepare(
            "SELECT parent_drive_folder_id,normalized_name,COUNT(*) duplicate_count,GROUP_CONCAT(drive_item_id ORDER BY drive_item_id SEPARATOR ',') drive_item_ids,GROUP_CONCAT(item_name ORDER BY item_name SEPARATOR ' | ') names
             FROM {$this->observations}
             WHERE scan_run_id=%d AND item_type='folder'
             GROUP BY parent_drive_folder_id,normalized_name HAVING COUNT(*) > 1
             ORDER BY duplicate_count DESC,normalized_name LIMIT 200",
            absint($run_id)
        ), ARRAY_A);
        $queue = $wpdb->get_results($wpdb->prepare(
            "SELECT status,COUNT(*) count FROM {$this->queue} WHERE scan_run_id=%d GROUP BY status",
            absint($run_id)
        ), ARRAY_A);
        return array('run' => $run, 'queue' => $queue, 'duplicate_sibling_folders' => $duplicates);
    }
}
