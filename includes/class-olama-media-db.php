<?php
if (!defined('ABSPATH')) {
    exit;
}

class Olama_Media_DB
{
    public $assets_table;
    public $jobs_table;
    public $events_table;
    public $legacy_table;

    public function __construct()
    {
        global $wpdb;
        $this->assets_table = $wpdb->prefix . 'olama_media_assets';
        $this->jobs_table = $wpdb->prefix . 'olama_media_upload_jobs';
        $this->events_table = $wpdb->prefix . 'olama_media_job_events';
        $this->legacy_table = $wpdb->prefix . 'academy_media_uploads';
    }

    public function create_tables()
    {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta("CREATE TABLE {$this->assets_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            academic_year_id BIGINT UNSIGNED NULL,
            semester_id BIGINT UNSIGNED NULL,
            grade_id BIGINT UNSIGNED NULL,
            subject_id BIGINT UNSIGNED NULL,
            unit_id BIGINT UNSIGNED NULL,
            lesson_id BIGINT UNSIGNED NULL,
            title VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) NULL,
            mime_type VARCHAR(100) NULL,
            file_size BIGINT UNSIGNED NULL,
            drive_file_id VARCHAR(255) NULL,
            drive_folder_id VARCHAR(255) NULL,
            web_view_link TEXT NULL,
            web_content_link TEXT NULL,
            thumbnail_link TEXT NULL,
            upload_status VARCHAR(50) NOT NULL DEFAULT 'none',
            preview_status VARCHAR(50) NOT NULL DEFAULT 'not_checked',
            approval_status VARCHAR(50) NOT NULL DEFAULT 'pending',
            notes TEXT NULL,
            uploaded_by BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            uploaded_at DATETIME NULL,
            approved_at DATETIME NULL,
            preview_checked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY lesson_id (lesson_id),
            KEY subject_id (subject_id),
            KEY grade_id (grade_id),
            KEY drive_file_id (drive_file_id),
            KEY upload_status (upload_status),
            KEY preview_status (preview_status),
            KEY approval_status (approval_status)
        ) $charset_collate;");

        dbDelta("CREATE TABLE {$this->jobs_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            asset_id BIGINT UNSIGNED NULL,
            job_uuid VARCHAR(100) NOT NULL,
            original_filename VARCHAR(255) NULL,
            mime_type VARCHAR(100) NULL,
            file_size BIGINT UNSIGNED NULL,
            total_chunks INT UNSIGNED NULL,
            uploaded_chunks INT UNSIGNED NOT NULL DEFAULT 0,
            drive_upload_uri TEXT NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'created',
            error_message TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY job_uuid (job_uuid),
            KEY asset_id (asset_id),
            KEY status (status)
        ) $charset_collate;");

        dbDelta("CREATE TABLE {$this->events_table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            job_id BIGINT UNSIGNED NULL,
            asset_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(100) NOT NULL,
            message TEXT NULL,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY  (id),
            KEY job_id (job_id),
            KEY asset_id (asset_id),
            KEY event_type (event_type)
        ) $charset_collate;");
    }

    public function get_curriculum_with_assets($academic_year_id, $semester_id, $grade_id, $subject_id)
    {
        global $wpdb;
        $units_table = $wpdb->prefix . 'olama_curriculum_units';
        $lessons_table = $wpdb->prefix . 'olama_curriculum_lessons';
        $users_table = $wpdb->users;

        if (!$this->table_exists($units_table) || !$this->table_exists($lessons_table)) {
            return new WP_Error('missing_curriculum_tables', __('Curriculum tables are not available.', 'olama-media-library'));
        }

        $units = $wpdb->get_results($wpdb->prepare(
            "SELECT id, unit_number, unit_name
             FROM {$units_table}
             WHERE grade_id = %d AND subject_id = %d AND semester_id = %d
             ORDER BY CAST(unit_number AS UNSIGNED) ASC, unit_number ASC",
            $grade_id,
            $subject_id,
            $semester_id
        ));

        if (!$units) {
            return array();
        }

        $unit_ids = wp_list_pluck($units, 'id');
        $placeholders = implode(',', array_fill(0, count($unit_ids), '%d'));
        $lessons = $wpdb->get_results($wpdb->prepare(
            "SELECT l.id, l.unit_id, l.lesson_number, l.lesson_title,
                    a.id AS media_record_id, a.upload_status, a.preview_status, a.approval_status,
                    a.notes AS comments, a.uploaded_by AS uploader_id, a.uploaded_at,
                    a.drive_file_id, a.web_view_link AS drive_file_url, a.web_content_link, a.thumbnail_link,
                    a.original_filename, a.file_size, u.display_name AS uploader_name
             FROM {$lessons_table} l
             LEFT JOIN {$this->assets_table} a ON a.lesson_id = l.id
             LEFT JOIN {$users_table} u ON a.uploaded_by = u.ID
             WHERE l.unit_id IN ({$placeholders})
             ORDER BY l.unit_id ASC, CAST(l.lesson_number AS UNSIGNED) ASC, l.lesson_number ASC, a.id ASC",
            $unit_ids
        ));

        $lessons_by_unit = array();
        foreach ($lessons as $lesson) {
            $lessons_by_unit[(int) $lesson->unit_id][] = $lesson;
        }

        foreach ($units as $unit) {
            $unit->lessons = isset($lessons_by_unit[(int) $unit->id]) ? $lessons_by_unit[(int) $unit->id] : array();
        }

        return $units;
    }

    public function upsert_asset($data)
    {
        global $wpdb;
        $now = current_time('mysql');
        $id = !empty($data['id']) ? absint($data['id']) : 0;
        unset($data['id']);
        $data['updated_at'] = $now;

        if ($id) {
            $wpdb->update($this->assets_table, $data, array('id' => $id));
            return $id;
        }

        if (empty($data['created_at'])) {
            $data['created_at'] = $now;
        }

        if (empty($data['lesson_id']) && !empty($data['drive_file_id'])) {
            $existing = $this->get_asset_by_drive_file_id($data['drive_file_id']);
            if ($existing) {
                $wpdb->update($this->assets_table, $data, array('id' => $existing->id));
                return (int) $existing->id;
            }
        }

        $wpdb->insert($this->assets_table, $data);
        return (int) $wpdb->insert_id;
    }

    public function get_asset($id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->assets_table} WHERE id = %d", $id));
    }

    public function get_asset_by_drive_file_id($drive_file_id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->assets_table} WHERE drive_file_id = %s LIMIT 1", $drive_file_id));
    }

    public function update_asset($id, $data)
    {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        return $wpdb->update($this->assets_table, $data, array('id' => absint($id)));
    }

    public function create_or_update_job($job_uuid, $data)
    {
        global $wpdb;
        $job = $this->get_job($job_uuid);
        $data['updated_at'] = current_time('mysql');

        if ($job) {
            $wpdb->update($this->jobs_table, $data, array('job_uuid' => $job_uuid));
            return (int) $job->id;
        }

        $data['job_uuid'] = $job_uuid;
        $data['created_at'] = current_time('mysql');
        $wpdb->insert($this->jobs_table, $data);
        return (int) $wpdb->insert_id;
    }

    public function get_job($job_uuid)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$this->jobs_table} WHERE job_uuid = %s", $job_uuid));
    }

    public function update_job($job_id, $data)
    {
        global $wpdb;
        $data['updated_at'] = current_time('mysql');
        return $wpdb->update($this->jobs_table, $data, array('id' => absint($job_id)));
    }

    public function insert_event($data)
    {
        global $wpdb;
        return $wpdb->insert($this->events_table, $data);
    }

    public function get_events($page = 1, $per_page = 20)
    {
        global $wpdb;
        $offset = max(0, ((int) $page - 1) * (int) $per_page);
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, a.title, a.upload_status, a.preview_status, a.approval_status
             FROM {$this->events_table} e
             LEFT JOIN {$this->assets_table} a ON e.asset_id = a.id
             ORDER BY e.created_at DESC
             LIMIT %d, %d",
            $offset,
            $per_page
        ));
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$this->events_table}");

        return array(
            'items' => $items,
            'total' => $total,
            'pages' => $per_page ? (int) ceil($total / $per_page) : 1,
        );
    }

    public function migrate_legacy($dry_run = false)
    {
        global $wpdb;
        if (!$this->table_exists($this->legacy_table)) {
            return array('found' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0);
        }

        $rows = $wpdb->get_results("SELECT * FROM {$this->legacy_table} WHERE drive_file_id IS NOT NULL OR drive_file_url IS NOT NULL");
        $stats = array('found' => count($rows), 'created' => 0, 'updated' => 0, 'skipped' => 0);

        foreach ($rows as $row) {
            $drive_file_id = $row->drive_file_id ? $row->drive_file_id : $this->extract_drive_id($row->drive_file_url);
            if (!$drive_file_id) {
                $stats['skipped']++;
                continue;
            }

            $existing = $this->get_asset_by_drive_file_id($drive_file_id);
            if ($dry_run) {
                $existing ? $stats['updated']++ : $stats['created']++;
                continue;
            }

            $asset_id = $this->upsert_asset(array(
                'id' => $existing ? $existing->id : 0,
                'unit_id' => absint($row->unit_id),
                'lesson_id' => absint($row->lesson_id),
                'title' => sanitize_text_field($row->lesson_name ?: __('Untitled media', 'olama-media-library')),
                'drive_file_id' => $drive_file_id,
                'drive_folder_id' => sanitize_text_field($row->drive_folder_id),
                'web_view_link' => esc_url_raw($row->drive_file_url),
                'web_content_link' => $drive_file_id ? 'https://drive.google.com/uc?export=download&id=' . rawurlencode($drive_file_id) : null,
                'upload_status' => $this->map_legacy_upload_status($row->upload_status),
                'preview_status' => $this->map_legacy_upload_status($row->upload_status) === 'uploaded_to_drive' ? 'processing' : 'not_checked',
                'approval_status' => sanitize_key($row->approval_status ?: 'pending'),
                'notes' => sanitize_textarea_field($row->comments),
                'uploaded_by' => absint($row->uploader_id),
                'uploaded_at' => $row->uploaded_at,
            ));

            $existing ? $stats['updated']++ : $stats['created']++;
        }

        return $stats;
    }

    public function table_exists($table)
    {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    }

    private function extract_drive_id($url)
    {
        if ($url && preg_match('/[-\w]{25,}/', $url, $matches)) {
            return $matches[0];
        }
        return '';
    }

    private function map_legacy_upload_status($status)
    {
        if ($status === 'completed') {
            return 'uploaded_to_drive';
        }
        if ($status === 'failed') {
            return 'failed';
        }
        return 'none';
    }
}
