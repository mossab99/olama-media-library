<?php
if (!defined('ABSPATH')) {
    exit;
}

class Olama_Media_V2_Repository
{
    private $files;
    private $links;
    private $runs;
    private $events;

    public function __construct()
    {
        global $wpdb;
        $this->files = $wpdb->prefix . 'olama_drive_files';
        $this->links = $wpdb->prefix . 'olama_lesson_video_links';
        $this->runs = $wpdb->prefix . 'olama_drive_sync_runs';
        $this->events = $wpdb->prefix . 'olama_drive_sync_events';
    }

    public function upsert_drive_file($data)
    {
        global $wpdb;
        $existing = $this->get_drive_file_by_file_id($data['drive_file_id'] ?? '');
        $now = current_time('mysql');
        $data['updated_at'] = $now;
        $data['last_seen_at'] = $data['last_seen_at'] ?? $now;
        if ($existing) {
            unset($data['first_seen_at'], $data['created_at']);
            $wpdb->update($this->files, $data, array('id' => $existing->id));
            return (int) $existing->id;
        }
        $data['first_seen_at'] = $data['first_seen_at'] ?? $now;
        $data['created_at'] = $data['created_at'] ?? $now;
        $wpdb->insert($this->files, $data);
        return (int) $wpdb->insert_id;
    }

    public function mark_missing_drive_files_not_seen_since($run_started_at, $path_prefix = '')
    {
        global $wpdb;
        $sql = "UPDATE {$this->files} SET scan_status='missing', updated_at=%s WHERE last_seen_at < %s AND scan_status='active'";
        $params = array(current_time('mysql'), $run_started_at);
        if ($path_prefix !== '') {
            $sql .= ' AND drive_path LIKE %s';
            $params[] = $wpdb->esc_like($path_prefix) . '/%';
        }
        return (int) $wpdb->query($wpdb->prepare($sql, $params));
    }

    public function get_drive_file_by_file_id($drive_file_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->files} WHERE drive_file_id=%s LIMIT 1", sanitize_text_field($drive_file_id)));
    }

    public function get_active_drive_files()
    {
        global $wpdb;
        // Deduplicate by drive_path_hash: keep only one file per unique path (the one with the highest id).
        // This prevents duplicate Google Drive file IDs (shared/copied files) from causing
        // multiple video links for the same lesson.
        return $wpdb->get_results(
            "SELECT f.*
             FROM {$this->files} f
             INNER JOIN (
                 SELECT MAX(id) AS max_id
                 FROM {$this->files}
                 WHERE scan_status='active' AND drive_path_hash IS NOT NULL AND drive_path_hash != ''
                 GROUP BY drive_path_hash
             ) dedup ON dedup.max_id = f.id
             WHERE f.scan_status='active'
             UNION
             SELECT f.*
             FROM {$this->files} f
             WHERE f.scan_status='active'
               AND (f.drive_path_hash IS NULL OR f.drive_path_hash = '')
             ORDER BY drive_path, filename"
        );
    }

    /**
     * Return all active drive files WITHOUT deduplication.
     * Used for diagnostics and admin reporting only.
     */
    public function get_all_active_drive_files_raw()
    {
        global $wpdb;
        return $wpdb->get_results("SELECT * FROM {$this->files} WHERE scan_status='active' ORDER BY drive_path,filename");
    }

    public function create_sync_run($data)
    {
        global $wpdb;
        $defaults = array(
            'run_uuid' => wp_generate_uuid4(), 'run_type' => 'full_sync', 'dry_run' => 0, 'status' => 'running',
            'files_scanned' => 0, 'files_new' => 0, 'files_updated' => 0, 'files_missing' => 0,
            'auto_linked' => 0, 'needs_review' => 0, 'unmatched' => 0, 'ambiguous' => 0, 'errors' => 0,
            'started_at' => current_time('mysql'), 'created_by' => get_current_user_id(),
        );
        $wpdb->insert($this->runs, array_merge($defaults, $data));
        return (int) $wpdb->insert_id;
    }

    public function finish_sync_run($run_id, $status, $summary)
    {
        global $wpdb;
        $counts = array();
        foreach (array('files_scanned','files_new','files_updated','files_missing','auto_linked','needs_review','unmatched','ambiguous','errors') as $key) {
            if (isset($summary[$key])) {
                $counts[$key] = absint($summary[$key]);
            }
        }
        $wpdb->update($this->runs, array_merge($counts, array(
            'status' => sanitize_key($status),
            'summary' => wp_json_encode($summary),
            'finished_at' => current_time('mysql'),
        )), array('id' => absint($run_id)));
    }

    public function log_sync_event($run_id, $event_type, $severity, $message, $context = array())
    {
        global $wpdb;
        $wpdb->insert($this->events, array(
            'run_id' => absint($run_id) ?: null,
            'event_type' => sanitize_key($event_type),
            'severity' => in_array($severity, array('info','warning','error'), true) ? $severity : 'info',
            'drive_file_id' => sanitize_text_field($context['drive_file_id'] ?? ''),
            'lesson_id' => absint($context['lesson_id'] ?? 0) ?: null,
            'message' => sanitize_textarea_field($message),
            'context' => $context ? wp_json_encode($context) : null,
            'created_at' => current_time('mysql'),
        ));
    }

    public function upsert_lesson_video_link($data)
    {
        global $wpdb;
        $file_id = sanitize_text_field($data['drive_file_id'] ?? '');
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->links} WHERE drive_file_id=%s LIMIT 1", $file_id));
        $data['updated_at'] = current_time('mysql');
        if ($existing) {
            $wpdb->update($this->links, $data, array('id' => $existing->id));
            return (int) $existing->id;
        }
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($this->links, $data);
        return (int) $wpdb->insert_id;
    }

    public function get_link_by_drive_file_id($drive_file_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->links} WHERE drive_file_id=%s LIMIT 1", sanitize_text_field($drive_file_id)));
    }

    public function clear_pending_generated_links_for_scope($academic_year_id, $semester_id, $grade_id, $subject_id)
    {
        global $wpdb;
        return (int) $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->links}
             WHERE academic_year_id=%d AND semester_id=%d AND grade_id=%d AND subject_id=%d
               AND approval_status='pending'
               AND match_method IN ('filename_lesson_part','filename_lesson_number','folder_and_title')",
            absint($academic_year_id), absint($semester_id), absint($grade_id), absint($subject_id)
        ));
    }

    public function get_links_for_lesson($lesson_id)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.id link_id,l.*,f.filename,f.drive_path,f.web_view_link,f.web_content_link,f.thumbnail_link,f.file_size
             FROM {$this->links} l JOIN {$this->files} f ON f.drive_file_id=l.drive_file_id
             WHERE l.lesson_id=%d AND l.link_status='active' AND f.scan_status='active'
             ORDER BY COALESCE(l.part_number,999999),l.sequence_order,l.id", absint($lesson_id)
        ));
    }

    public function get_active_link_counts_for_lessons($lesson_ids)
    {
        global $wpdb;
        $lesson_ids = array_values(array_unique(array_filter(array_map('absint', (array) $lesson_ids))));
        if (!$lesson_ids) { return array(); }
        $placeholders = implode(',', array_fill(0, count($lesson_ids), '%d'));
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT l.lesson_id,COUNT(DISTINCT l.drive_file_id) video_count
             FROM {$this->links} l INNER JOIN {$this->files} f ON f.drive_file_id=l.drive_file_id
             WHERE l.lesson_id IN ({$placeholders}) AND l.link_status='active' AND f.scan_status='active'
             GROUP BY l.lesson_id",
            $lesson_ids
        ));
        $counts = array();
        foreach ($rows as $row) { $counts[absint($row->lesson_id)] = absint($row->video_count); }
        return $counts;
    }

    public function get_curriculum_with_v2_links($academic_year_id, $semester_id, $grade_id, $subject_id)
    {
        $db = new Olama_Media_DB();
        $units = $db->get_curriculum_with_assets($academic_year_id, $semester_id, $grade_id, $subject_id);
        if (is_wp_error($units)) {
            return $units;
        }
        foreach ($units as $unit) {
            foreach ($unit->lessons as $lesson) {
                $lesson->legacy_media_record_id = $lesson->media_record_id;
                $lesson->video_links = $this->get_links_for_lesson($lesson->id);
                $lesson->video_count = count($lesson->video_links);
                if ($lesson->video_count) {
                    $first = $lesson->video_links[0];
                    $lesson->drive_file_id = $first->drive_file_id;
                    $lesson->drive_file_url = $first->web_view_link;
                    $lesson->web_content_link = $first->web_content_link;
                    $lesson->upload_status = 'uploaded_to_drive';
                    $lesson->preview_status = 'ready';
                    $lesson->approval_status = $first->approval_status;
                } else {
                    $lesson->media_record_id = null;
                    $lesson->upload_status = 'none';
                    $lesson->preview_status = 'not_checked';
                    $lesson->approval_status = 'pending';
                    $lesson->drive_file_id = null;
                    $lesson->drive_file_url = null;
                    $lesson->web_content_link = null;
                    $lesson->uploaded_at = null;
                }
            }
        }
        return $units;
    }

    public function get_review_queue($filters = array())
    {
        global $wpdb;
        $where = array("l.link_status IN ('active','unlinked')", "(l.approval_status='pending' OR l.match_confidence < 90)");
        $params = array();
        foreach (array('academic_year_id','semester_id','grade_id','subject_id','unit_id','lesson_id') as $key) {
            if (!empty($filters[$key])) { $where[] = "l.{$key}=%d"; $params[] = absint($filters[$key]); }
        }
        if (!empty($filters['approval_status'])) { $where[] = 'l.approval_status=%s'; $params[] = sanitize_key($filters['approval_status']); }
        if (!empty($filters['link_status'])) { $where[] = 'l.link_status=%s'; $params[] = sanitize_key($filters['link_status']); }
        $sql = "SELECT l.*,f.filename,f.drive_path,f.web_view_link,cl.lesson_title,cl.lesson_number,cu.unit_name
                FROM {$this->links} l JOIN {$this->files} f ON f.drive_file_id=l.drive_file_id
                LEFT JOIN {$wpdb->prefix}olama_curriculum_lessons cl ON cl.id=l.lesson_id
                LEFT JOIN {$wpdb->prefix}olama_curriculum_units cu ON cu.id=l.unit_id
                WHERE " . implode(' AND ', $where) . ' ORDER BY l.match_confidence DESC,l.id DESC LIMIT 500';
        return $wpdb->get_results($params ? $wpdb->prepare($sql, $params) : $sql);
    }

    public function approve_link($link_id, $user_id)
    {
        global $wpdb;
        return false !== $wpdb->update($this->links, array('approval_status'=>'approved','link_status'=>'active','approved_by'=>absint($user_id),'approved_at'=>current_time('mysql'),'updated_at'=>current_time('mysql')), array('id'=>absint($link_id)));
    }

    public function reject_link($link_id, $user_id, $notes = '')
    {
        global $wpdb;
        return false !== $wpdb->update($this->links, array('approval_status'=>'rejected','link_status'=>'ignored','approved_by'=>absint($user_id),'notes'=>sanitize_textarea_field($notes),'updated_at'=>current_time('mysql')), array('id'=>absint($link_id)));
    }

    public function unlink_drive_file($link_id)
    {
        global $wpdb;
        return false !== $wpdb->update($this->links, array('link_status'=>'unlinked','updated_at'=>current_time('mysql')), array('id'=>absint($link_id)));
    }

    public function next_sequence_order($lesson_id)
    {
        global $wpdb;
        return 1 + (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(MAX(sequence_order),0) FROM {$this->links} WHERE lesson_id=%d AND link_status='active'", absint($lesson_id)));
    }

    public function reset_v2_index($scope)
    {
        global $wpdb;
        $counts = array('links'=>0,'manifest'=>0,'runs'=>0,'events'=>0);
        if (in_array($scope, array('links_only','all_v2'), true)) { $counts['links'] = (int) $wpdb->query("DELETE FROM {$this->links}"); }
        if (in_array($scope, array('manifest_only','all_v2'), true)) { $counts['manifest'] = (int) $wpdb->query("DELETE FROM {$this->files}"); }
        if ($scope === 'all_v2') {
            $counts['events'] = (int) $wpdb->query("DELETE FROM {$this->events}");
            $counts['runs'] = (int) $wpdb->query("DELETE FROM {$this->runs}");
        }
        return $counts;
    }

    public function get_latest_runs($limit = 20)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM {$this->runs} ORDER BY id DESC LIMIT %d", min(100, max(1, absint($limit)))));
    }
}
