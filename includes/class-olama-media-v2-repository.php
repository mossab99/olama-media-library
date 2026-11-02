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
            return false === $wpdb->update($this->files, $data, array('id' => $existing->id)) ? 0 : (int) $existing->id;
        }
        $data['first_seen_at'] = $data['first_seen_at'] ?? $now;
        $data['created_at'] = $data['created_at'] ?? $now;
        return $wpdb->insert($this->files, $data) ? (int) $wpdb->insert_id : 0;
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

    /** Shared upload/picker/refresh attachment. Retrying never resets review decisions. */
    public function attach_video($data, $explicit = false, $expected_revision = '')
    {
        global $wpdb;
        $locks = array('olama_video_' . substr(hash('sha256', $data['drive_file_id']), 0, 40), 'olama_lesson_' . absint($data['lesson_id']));
        $acquired = array();
        try {
            foreach ($locks as $lock) {
                if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) { return new WP_Error('video_busy', __('الفيديو قيد التحديث. أعد المحاولة.', 'olama-media-library')); }
                $acquired[] = $lock;
            }
            $existing = $this->get_link_by_drive_file_id($data['drive_file_id']);
            if ($expected_revision !== '' && (!$existing || $existing->link_status !== 'active')) { return new WP_Error('link_changed', __('تغيّر الربط منذ الفحص. حدّث النتائج قبل التصحيح.', 'olama-media-library')); }
            if ($existing && $existing->link_status === 'active') {
                $same_target = true;
                foreach (array('academic_year_id', 'semester_id', 'grade_id', 'subject_id', 'unit_id', 'lesson_id') as $field) {
                    if (absint($existing->$field) !== absint($data[$field])) { $same_target = false; }
                }
                if ($same_target) { return array('link_id'=>absint($existing->id), 'already_linked'=>true); }
                if (!$explicit || $expected_revision === '' || !hash_equals(self::link_revision($existing), $expected_revision)) {
                    return new WP_Error('video_already_linked', __('تغيّر الربط أو أن الفيديو مرتبط بدرس آخر. حدّث النتائج وراجع الربط الحالي قبل تغييره.', 'olama-media-library'));
                }
            }
            if ($existing && !$explicit && ($existing->link_status === 'ignored' || $existing->approval_status === 'rejected')) { return new WP_Error('video_dismissed', __('تم استبعاد هذا الفيديو سابقاً.', 'olama-media-library')); }
            if (!$explicit) {
                foreach ($this->get_links_for_lesson($data['lesson_id']) as $link) {
                    if (empty($data['part_number']) || empty($link->part_number) || (int) $link->part_number === (int) $data['part_number']) { return new WP_Error('lesson_occupied', __('يوجد فيديو لهذا الدرس أو الجزء. راجع الربط يدوياً.', 'olama-media-library')); }
                }
            }
            $data['updated_at'] = current_time('mysql');
            if ($existing) {
                $data['approved_by'] = null; $data['approved_at'] = null;
                $where = array('id'=>$existing->id);
                if ($expected_revision !== '') {
                    foreach (array('academic_year_id','semester_id','grade_id','subject_id','unit_id','lesson_id','part_number','sequence_order','link_status','approval_status','approved_by','approved_at','updated_at') as $field) { $where[$field] = $existing->$field ?? null; }
                }
                $ok = $wpdb->update($this->links, $data, $where);
                if ($expected_revision !== '' && $ok !== 1) { return new WP_Error('link_changed', __('تغيّر الربط أثناء الحفظ. حدّث النتائج قبل التصحيح.', 'olama-media-library')); }
                $id = absint($existing->id);
            } else {
                $data['created_at'] = current_time('mysql');
                $ok = $wpdb->insert($this->links, $data);
                $id = absint($wpdb->insert_id);
            }
            return false !== $ok && $id ? array('link_id'=>$id, 'already_linked'=>false) : new WP_Error('video_save_failed', __('تعذر حفظ ربط الفيديو. أعد المحاولة.', 'olama-media-library'));
        } finally { foreach (array_reverse($acquired) as $lock) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); } }
    }

    public static function link_revision($link)
    {
        $fields = array();
        foreach (array('id','academic_year_id','semester_id','grade_id','subject_id','unit_id','lesson_id','part_number','sequence_order','link_status','approval_status','approved_by','approved_at','updated_at') as $field) {
            $fields[$field] = $link->$field ?? null;
        }
        return hash('sha256', wp_json_encode($fields));
    }

    public function dismiss_video($data)
    {
        global $wpdb;
        $lock = 'olama_video_' . substr(hash('sha256', $data['drive_file_id']), 0, 40);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) { return new WP_Error('video_busy', __('الفيديو قيد التحديث. أعد المحاولة.', 'olama-media-library')); }
        try {
            $existing = $this->get_link_by_drive_file_id($data['drive_file_id']);
            if ($existing && $existing->link_status === 'active') { return new WP_Error('active_video', __('لا يمكن تجاهل فيديو مرتبط. راجع ربطه الحالي أولاً.', 'olama-media-library')); }
            if ($existing) {
                foreach (array('academic_year_id','semester_id','grade_id','subject_id') as $field) {
                    if (absint($existing->$field) !== absint($data[$field])) { return new WP_Error('video_scope_conflict', __('الفيديو مسجل لمادة أخرى. راجع الربط أولاً.', 'olama-media-library')); }
                }
            }
            $data = array_merge($data, array('lesson_id'=>0,'unit_id'=>null,'part_number'=>null,'sequence_order'=>0,'link_status'=>'ignored','approval_status'=>'pending','match_method'=>'manual_ignored','match_confidence'=>0,'updated_at'=>current_time('mysql')));
            if ($existing) {
                $ok = $wpdb->update($this->links, $data, array('id'=>$existing->id,'link_status'=>$existing->link_status,'lesson_id'=>$existing->lesson_id,'approval_status'=>$existing->approval_status,'updated_at'=>$existing->updated_at)); $id = $existing->id;
                if ($ok === 0 && $existing->link_status !== 'ignored') { return new WP_Error('link_changed', __('تغيّر الربط أثناء الحفظ. أعد تحميل النتائج.', 'olama-media-library')); }
            }
            else { $data['created_at'] = current_time('mysql'); $ok = $wpdb->insert($this->links, $data); $id = $wpdb->insert_id; }
            return $ok !== false && $id ? array('dismissed'=>true) : new WP_Error('dismiss_failed', __('تعذر حفظ التجاهل. أعد المحاولة.', 'olama-media-library'));
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }

    /** Uploaded through this plugin and already linked explicitly to a curriculum lesson. */
    public function get_active_manual_upload_links_for_scope($academic_year_id, $semester_id, $grade_id, $subject_id)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.drive_file_id,l.unit_id,l.lesson_id,l.part_number,l.match_confidence,l.approval_status,
                    f.filename,f.drive_path,f.drive_folder_id,f.web_view_link
             FROM {$this->links} l
             INNER JOIN {$this->files} f ON f.drive_file_id=l.drive_file_id
             WHERE l.academic_year_id=%d AND l.semester_id=%d AND l.grade_id=%d AND l.subject_id=%d
               AND l.link_status='active' AND l.match_method='manual_upload' AND f.scan_status='active'
             ORDER BY l.unit_id,l.lesson_id,l.sequence_order,l.id",
            absint($academic_year_id), absint($semester_id), absint($grade_id), absint($subject_id)
        ));
    }

    public function get_links_for_scope($scope)
    {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare(
            "SELECT l.*,f.filename,f.drive_path,f.web_view_link FROM {$this->links} l
             LEFT JOIN {$this->files} f ON f.drive_file_id=l.drive_file_id
             WHERE l.academic_year_id=%d AND l.semester_id=%d AND l.grade_id=%d AND l.subject_id=%d AND l.link_status='active' ORDER BY l.id",
            ...$scope
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
        return false !== $wpdb->update($this->links, array('link_status'=>'ignored','updated_at'=>current_time('mysql')), array('id'=>absint($link_id)));
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
