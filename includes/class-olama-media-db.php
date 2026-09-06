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
            transport_method VARCHAR(50) NULL,
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
            drive_file_id VARCHAR(255) NULL,
            drive_upload_uri TEXT NULL,
            transport_method VARCHAR(50) NOT NULL DEFAULT 'wordpress_streamed',
            direct_upload_url_hash VARCHAR(191) NULL,
            direct_upload_started_at DATETIME NULL,
            direct_upload_completed_at DATETIME NULL,
            expected_file_size BIGINT UNSIGNED NULL,
            uploaded_bytes BIGINT UNSIGNED NULL,
            status VARCHAR(50) NOT NULL DEFAULT 'created',
            error_message TEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY job_uuid (job_uuid),
            KEY asset_id (asset_id),
            KEY drive_file_id (drive_file_id),
            KEY status (status),
            KEY transport_method (transport_method)
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

        $drive_files = $wpdb->prefix . 'olama_drive_files';
        $links = $wpdb->prefix . 'olama_lesson_video_links';
        $runs = $wpdb->prefix . 'olama_drive_sync_runs';
        $sync_events = $wpdb->prefix . 'olama_drive_sync_events';
        $drive_folders = $wpdb->prefix . 'olama_drive_folders';
        $drive_maps = $wpdb->prefix . 'olama_curriculum_drive_map';
        $mapping_candidates = $wpdb->prefix . 'olama_drive_mapping_candidates';
        $scan_runs = $wpdb->prefix . 'olama_drive_scan_runs';
        $scan_observations = $wpdb->prefix . 'olama_drive_scan_observations';
        $scan_queue = $wpdb->prefix . 'olama_drive_scan_queue';
        $sync_locks = $wpdb->prefix . 'olama_drive_sync_locks';
        $reconciliation_items = $wpdb->prefix . 'olama_drive_reconciliation_items';
        $folder_plans = $wpdb->prefix . 'olama_drive_folder_plans';
        $folder_plan_items = $wpdb->prefix . 'olama_drive_folder_plan_items';

        dbDelta("CREATE TABLE {$drive_files} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            drive_file_id VARCHAR(191) NOT NULL,
            drive_folder_id VARCHAR(191) NULL,
            drive_parent_ids LONGTEXT NULL,
            drive_path TEXT NULL,
            drive_path_hash VARCHAR(64) NULL,
            filename VARCHAR(255) NOT NULL,
            normalized_filename VARCHAR(255) NULL,
            extension VARCHAR(20) NULL,
            mime_type VARCHAR(100) NULL,
            file_size BIGINT UNSIGNED NULL,
            modified_time DATETIME NULL,
            web_view_link TEXT NULL,
            web_content_link TEXT NULL,
            thumbnail_link TEXT NULL,
            video_metadata LONGTEXT NULL,
            scan_status VARCHAR(30) NOT NULL DEFAULT 'active',
            academic_year_id BIGINT UNSIGNED NULL,
            semester_id BIGINT UNSIGNED NULL,
            grade_id BIGINT UNSIGNED NULL,
            subject_id BIGINT UNSIGNED NULL,
            unit_id BIGINT UNSIGNED NULL,
            last_seen_scan_id BIGINT UNSIGNED NULL,
            consecutive_absent_scans INT UNSIGNED NOT NULL DEFAULT 0,
            presence_status VARCHAR(30) NOT NULL DEFAULT 'present',
            first_seen_at DATETIME NOT NULL,
            last_seen_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY drive_file_id (drive_file_id),
            KEY drive_folder_id (drive_folder_id),
            KEY drive_path_hash (drive_path_hash),
            KEY scan_status (scan_status),
            KEY curriculum (academic_year_id,semester_id,grade_id,subject_id),
            KEY presence_status (presence_status),
            KEY modified_time (modified_time)
        ) ENGINE=InnoDB $charset_collate;");

        dbDelta("CREATE TABLE {$links} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            drive_file_id VARCHAR(191) NOT NULL,
            drive_file_row_id BIGINT UNSIGNED NULL,
            academic_year_id BIGINT UNSIGNED NULL,
            semester_id BIGINT UNSIGNED NULL,
            grade_id BIGINT UNSIGNED NULL,
            subject_id BIGINT UNSIGNED NULL,
            unit_id BIGINT UNSIGNED NULL,
            lesson_id BIGINT UNSIGNED NOT NULL,
            part_number INT UNSIGNED NULL,
            sequence_order INT UNSIGNED NOT NULL DEFAULT 1,
            match_method VARCHAR(50) NOT NULL DEFAULT 'manual',
            match_confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
            approval_status VARCHAR(30) NOT NULL DEFAULT 'pending',
            link_status VARCHAR(30) NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            linked_by BIGINT UNSIGNED NULL,
            approved_by BIGINT UNSIGNED NULL,
            approved_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY drive_file_id (drive_file_id),
            KEY lesson_id (lesson_id),
            KEY curriculum (academic_year_id,semester_id,grade_id,subject_id),
            KEY unit_lesson (unit_id,lesson_id),
            KEY approval_status (approval_status),
            KEY link_status (link_status),
            KEY match_confidence (match_confidence)
        ) ENGINE=InnoDB $charset_collate;");

        dbDelta("CREATE TABLE {$runs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_uuid VARCHAR(100) NOT NULL,
            run_type VARCHAR(50) NOT NULL,
            dry_run TINYINT(1) NOT NULL DEFAULT 0,
            status VARCHAR(30) NOT NULL DEFAULT 'running',
            academic_year_id BIGINT UNSIGNED NULL,
            semester_id BIGINT UNSIGNED NULL,
            grade_id BIGINT UNSIGNED NULL,
            subject_id BIGINT UNSIGNED NULL,
            files_scanned INT UNSIGNED NOT NULL DEFAULT 0,
            files_new INT UNSIGNED NOT NULL DEFAULT 0,
            files_updated INT UNSIGNED NOT NULL DEFAULT 0,
            files_missing INT UNSIGNED NOT NULL DEFAULT 0,
            auto_linked INT UNSIGNED NOT NULL DEFAULT 0,
            needs_review INT UNSIGNED NOT NULL DEFAULT 0,
            unmatched INT UNSIGNED NOT NULL DEFAULT 0,
            ambiguous INT UNSIGNED NOT NULL DEFAULT 0,
            errors INT UNSIGNED NOT NULL DEFAULT 0,
            summary LONGTEXT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY run_uuid (run_uuid),
            KEY run_type (run_type),
            KEY status (status),
            KEY curriculum (academic_year_id,semester_id,grade_id,subject_id)
        ) ENGINE=InnoDB $charset_collate;");

        dbDelta("CREATE TABLE {$sync_events} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(100) NOT NULL,
            severity VARCHAR(20) NOT NULL DEFAULT 'info',
            drive_file_id VARCHAR(191) NULL,
            lesson_id BIGINT UNSIGNED NULL,
            message TEXT NULL,
            context LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY run_id (run_id),
            KEY event_type (event_type),
            KEY severity (severity),
            KEY drive_file_id (drive_file_id),
            KEY lesson_id (lesson_id)
        ) ENGINE=InnoDB $charset_collate;");

        // Phase 1 tables are additive. Discovery writes only to scan runs,
        // observations, and queue; authoritative folder/file state is untouched.
        dbDelta("CREATE TABLE {$drive_folders} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            drive_folder_id VARCHAR(191) NOT NULL,
            parent_drive_folder_id VARCHAR(191) NULL,
            folder_name VARCHAR(255) NOT NULL,
            normalized_name VARCHAR(255) NULL,
            path_snapshot TEXT NULL,
            root_config_hash VARCHAR(64) NULL,
            presence_status VARCHAR(30) NOT NULL DEFAULT 'present',
            last_seen_scan_id BIGINT UNSIGNED NULL,
            consecutive_absent_scans INT UNSIGNED NOT NULL DEFAULT 0,
            metadata_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY drive_folder_id (drive_folder_id),
            KEY parent_drive_folder_id (parent_drive_folder_id),
            KEY normalized_name (normalized_name),
            KEY presence_status (presence_status)
        ) ENGINE=InnoDB $charset_collate;");

        dbDelta("CREATE TABLE {$drive_maps} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scope_key VARCHAR(191) NOT NULL,
            scope_type VARCHAR(30) NOT NULL,
            academic_year_id BIGINT UNSIGNED NULL,
            semester_id BIGINT UNSIGNED NULL,
            grade_id BIGINT UNSIGNED NULL,
            subject_id BIGINT UNSIGNED NULL,
            unit_id BIGINT UNSIGNED NULL,
            drive_folder_id VARCHAR(191) NOT NULL,
            mapping_status VARCHAR(30) NOT NULL DEFAULT 'proposed',
            confirmation_method VARCHAR(40) NULL,
            confirmation_evidence LONGTEXT NULL,
            root_config_hash VARCHAR(64) NULL,
            confirmed_by BIGINT UNSIGNED NULL,
            confirmed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY scope_key (scope_key),
            KEY drive_folder_id (drive_folder_id),
            KEY mapping_status (mapping_status)
        ) ENGINE=InnoDB $charset_collate;");

        dbDelta("CREATE TABLE {$mapping_candidates} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            discovery_run_id BIGINT UNSIGNED NOT NULL,
            scope_key VARCHAR(191) NOT NULL,
            drive_folder_id VARCHAR(191) NOT NULL,
            confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
            reasons LONGTEXT NULL,
            conflict_reason TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY run_scope_folder (discovery_run_id,scope_key,drive_folder_id),
            KEY scope_key (scope_key),
            KEY drive_folder_id (drive_folder_id)
        ) ENGINE=InnoDB $charset_collate;");

        dbDelta("CREATE TABLE {$scan_runs} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            run_uuid VARCHAR(100) NOT NULL,
            run_type VARCHAR(40) NOT NULL DEFAULT 'inventory_discovery',
            status VARCHAR(30) NOT NULL DEFAULT 'scanning',
            root_folder_id VARCHAR(191) NOT NULL,
            root_name VARCHAR(255) NULL,
            root_config_hash VARCHAR(64) NOT NULL,
            folders_observed INT UNSIGNED NOT NULL DEFAULT 0,
            files_observed INT UNSIGNED NOT NULL DEFAULT 0,
            shortcuts_observed INT UNSIGNED NOT NULL DEFAULT 0,
            errors INT UNSIGNED NOT NULL DEFAULT 0,
            summary LONGTEXT NULL,
            started_at DATETIME NOT NULL,
            finished_at DATETIME NULL,
            created_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY run_uuid (run_uuid),
            KEY status (status),
            KEY root_config_hash (root_config_hash)
        ) ENGINE=InnoDB $charset_collate;");

        dbDelta("CREATE TABLE {$scan_observations} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scan_run_id BIGINT UNSIGNED NOT NULL,
            drive_item_id VARCHAR(191) NOT NULL,
            item_type VARCHAR(20) NOT NULL,
            resolved_target_id VARCHAR(191) NULL,
            parent_drive_folder_id VARCHAR(191) NULL,
            item_name VARCHAR(255) NOT NULL,
            normalized_name VARCHAR(255) NULL,
            mime_type VARCHAR(100) NULL,
            file_size BIGINT UNSIGNED NULL,
            modified_time DATETIME NULL,
            path_snapshot TEXT NULL,
            web_view_link TEXT NULL,
            metadata_json LONGTEXT NULL,
            observed_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY run_item (scan_run_id,drive_item_id),
            KEY scan_run_id (scan_run_id),
            KEY parent_drive_folder_id (parent_drive_folder_id),
            KEY item_type (item_type),
            KEY normalized_name (normalized_name)
        ) ENGINE=InnoDB $charset_collate;");

        dbDelta("CREATE TABLE {$scan_queue} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            scan_run_id BIGINT UNSIGNED NOT NULL,
            drive_folder_id VARCHAR(191) NOT NULL,
            parent_drive_folder_id VARCHAR(191) NULL,
            path_snapshot TEXT NULL,
            depth INT UNSIGNED NOT NULL DEFAULT 0,
            page_token TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY run_folder (scan_run_id,drive_folder_id),
            KEY run_status (scan_run_id,status)
        ) ENGINE=InnoDB $charset_collate;");

        dbDelta("CREATE TABLE {$sync_locks} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            lock_key VARCHAR(191) NOT NULL,
            run_id BIGINT UNSIGNED NULL,
            acquired_at DATETIME NOT NULL,
            heartbeat_at DATETIME NOT NULL,
            expires_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY lock_key (lock_key),
            KEY expires_at (expires_at)
        ) ENGINE=InnoDB $charset_collate;");

        dbDelta("CREATE TABLE {$reconciliation_items} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            discovery_run_id BIGINT UNSIGNED NOT NULL,
            subject_mapping_id BIGINT UNSIGNED NOT NULL,
            drive_file_id VARCHAR(191) NOT NULL,
            proposed_unit_id BIGINT UNSIGNED NULL,
            proposed_lesson_id BIGINT UNSIGNED NULL,
            confidence TINYINT UNSIGNED NOT NULL DEFAULT 0,
            proposal_status VARCHAR(30) NOT NULL,
            decision_status VARCHAR(30) NOT NULL DEFAULT 'pending',
            selected_unit_id BIGINT UNSIGNED NULL,
            selected_lesson_id BIGINT UNSIGNED NULL,
            reviewed_by BIGINT UNSIGNED NULL,
            reviewed_at DATETIME NULL,
            commit_status VARCHAR(30) NOT NULL DEFAULT 'pending',
            committed_link_id BIGINT UNSIGNED NULL,
            commit_run_id BIGINT UNSIGNED NULL,
            committed_at DATETIME NULL,
            commit_action VARCHAR(30) NULL,
            previous_link_state LONGTEXT NULL,
            committed_link_fingerprint VARCHAR(64) NULL,
            committed_drive_file_row_id BIGINT UNSIGNED NULL,
            previous_drive_file_state LONGTEXT NULL,
            committed_drive_file_fingerprint VARCHAR(64) NULL,
            rollback_run_id BIGINT UNSIGNED NULL,
            rolled_back_at DATETIME NULL,
            filename VARCHAR(255) NOT NULL,
            path_snapshot TEXT NULL,
            reasons LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY mapping_run_file (subject_mapping_id,discovery_run_id,drive_file_id),
            KEY proposal_status (proposal_status),
            KEY decision_status (decision_status),
            KEY commit_status (commit_status),
            KEY proposed_lesson_id (proposed_lesson_id)
        ) ENGINE=InnoDB $charset_collate;");

        dbDelta("CREATE TABLE {$folder_plans} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_uuid VARCHAR(100) NOT NULL,
            discovery_run_id BIGINT UNSIGNED NOT NULL,
            subject_mapping_id BIGINT UNSIGNED NOT NULL,
            subject_drive_folder_id VARCHAR(191) NOT NULL,
            root_config_hash VARCHAR(64) NOT NULL,
            plan_hash VARCHAR(64) NOT NULL,
            plan_status VARCHAR(30) NOT NULL DEFAULT 'preview',
            items_total INT UNSIGNED NOT NULL DEFAULT 0,
            existing_count INT UNSIGNED NOT NULL DEFAULT 0,
            create_count INT UNSIGNED NOT NULL DEFAULT 0,
            conflict_count INT UNSIGNED NOT NULL DEFAULT 0,
            summary LONGTEXT NULL,
            created_by BIGINT UNSIGNED NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY plan_uuid (plan_uuid),
            UNIQUE KEY mapping_run_hash (subject_mapping_id,discovery_run_id,plan_hash),
            KEY plan_status (plan_status)
        ) ENGINE=InnoDB $charset_collate;");

        dbDelta("CREATE TABLE {$folder_plan_items} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            plan_id BIGINT UNSIGNED NOT NULL,
            unit_id BIGINT UNSIGNED NOT NULL,
            unit_number VARCHAR(50) NULL,
            expected_name VARCHAR(255) NOT NULL,
            normalized_name VARCHAR(255) NOT NULL,
            planned_action VARCHAR(30) NOT NULL,
            parent_drive_folder_id VARCHAR(191) NOT NULL,
            existing_drive_folder_id VARCHAR(191) NULL,
            candidate_drive_folder_ids LONGTEXT NULL,
            candidate_names LONGTEXT NULL,
            path_snapshot TEXT NULL,
            reasons LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY plan_unit (plan_id,unit_id),
            KEY planned_action (planned_action),
            KEY parent_drive_folder_id (parent_drive_folder_id)
        ) ENGINE=InnoDB $charset_collate;");
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
                    a.original_filename, a.file_size, j.job_uuid, j.status AS job_status,
                    u.display_name AS uploader_name
             FROM {$lessons_table} l
             LEFT JOIN {$this->assets_table} a ON a.id = (
                 SELECT a2.id
                 FROM {$this->assets_table} a2
                 WHERE a2.lesson_id = l.id
                 ORDER BY
                     CASE
                         WHEN a2.drive_file_id IS NOT NULL AND a2.drive_file_id <> '' THEN 0
                         WHEN a2.upload_status IN ('uploading', 'processing') THEN 1
                         ELSE 2
                     END ASC,
                     a2.id DESC
                 LIMIT 1
             )
             LEFT JOIN {$this->jobs_table} j ON j.asset_id = a.id AND j.id = (
                 SELECT MAX(j2.id) FROM {$this->jobs_table} j2 WHERE j2.asset_id = a.id
             )
             LEFT JOIN {$users_table} u ON a.uploaded_by = u.ID
             WHERE l.unit_id IN ({$placeholders})
             ORDER BY l.unit_id ASC, CAST(l.lesson_number AS UNSIGNED) ASC, l.lesson_number ASC",
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

    /** Return one row per lesson for curriculum video coverage reporting. */
    public function get_video_coverage($semester_id, $grade_id = 0, $subject_id = 0)
    {
        global $wpdb;
        $units = $wpdb->prefix . 'olama_curriculum_units';
        $lessons = $wpdb->prefix . 'olama_curriculum_lessons';
        $grades = $wpdb->prefix . 'olama_grades';
        $subjects = $wpdb->prefix . 'olama_subjects';
        $v2_links = $wpdb->prefix . 'olama_lesson_video_links';
        $drive_files = $wpdb->prefix . 'olama_drive_files';

        foreach (array($units, $lessons, $grades, $subjects) as $table) {
            if (!$this->table_exists($table)) {
                return new WP_Error('missing_curriculum_tables', __('Curriculum tables are not available.', 'olama-media-library'));
            }
        }

        $where = array('u.semester_id = %d');
        $params = array(absint($semester_id));
        if ($grade_id) {
            $where[] = 'u.grade_id = %d';
            $params[] = absint($grade_id);
        }
        if ($subject_id) {
            $where[] = 'u.subject_id = %d';
            $params[] = absint($subject_id);
        }

        $sql = "SELECT g.id grade_id, g.grade_name, g.grade_level, s.id subject_id, s.subject_name,
                       u.id unit_id, u.unit_number, u.unit_name,
                       l.id lesson_id, l.lesson_number, l.lesson_title,
                       MAX(CASE WHEN vl.link_status = 'active' AND df.scan_status = 'active' THEN 1 ELSE 0 END) has_video
                FROM {$units} u
                INNER JOIN {$lessons} l ON l.unit_id = u.id
                INNER JOIN {$grades} g ON g.id = u.grade_id
                INNER JOIN {$subjects} s ON s.id = u.subject_id
                LEFT JOIN {$v2_links} vl ON vl.lesson_id = l.id
                LEFT JOIN {$drive_files} df ON df.drive_file_id = vl.drive_file_id
                WHERE " . implode(' AND ', $where) . "
                GROUP BY g.id, g.grade_name, g.grade_level, s.id, s.subject_name, u.id, u.unit_number, u.unit_name, l.id, l.lesson_number, l.lesson_title
                ORDER BY CAST(g.grade_level AS UNSIGNED), g.grade_name, s.subject_name,
                         CAST(u.unit_number AS UNSIGNED), u.unit_number, CAST(l.lesson_number AS UNSIGNED), l.lesson_number";

        return $wpdb->get_results($wpdb->prepare($sql, $params));
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

    public function get_events($page = 1, $per_page = 20, $filters = array())
    {
        global $wpdb;
        $offset = max(0, ((int) $page - 1) * (int) $per_page);
        $where = array('1=1');
        $params = array();

        if (!empty($filters['event_type'])) {
            $where[] = 'e.event_type = %s';
            $params[] = sanitize_key($filters['event_type']);
        }
        if (!empty($filters['job_uuid'])) {
            $where[] = 'j.job_uuid = %s';
            $params[] = sanitize_key($filters['job_uuid']);
        }
        if (!empty($filters['error_code'])) {
            $where[] = 'e.context LIKE %s';
            $params[] = '%"error_code":"' . $wpdb->esc_like(sanitize_key($filters['error_code'])) . '"%';
        }

        $where_sql = implode(' AND ', $where);
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT e.*, a.title, a.upload_status, a.preview_status, a.approval_status
             FROM {$this->events_table} e
             LEFT JOIN {$this->assets_table} a ON e.asset_id = a.id
             LEFT JOIN {$this->jobs_table} j ON e.job_id = j.id
             WHERE {$where_sql}
             ORDER BY e.created_at DESC
             LIMIT %d, %d",
            array_merge($params, array($offset, $per_page))
        ));
        $count_sql = "SELECT COUNT(*)
             FROM {$this->events_table} e
             LEFT JOIN {$this->jobs_table} j ON e.job_id = j.id
             WHERE {$where_sql}";
        $total = (int) $wpdb->get_var($params ? $wpdb->prepare($count_sql, $params) : $count_sql);

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
