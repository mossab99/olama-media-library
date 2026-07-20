<?php
if (!defined('ABSPATH')) {
    exit;
}

class Olama_Media_Ajax
{
    private $db;
    private $curriculum;
    private $logger;
    private $settings_cache = null;

    public function __construct($db, $curriculum, $logger)
    {
        $this->db = $db;
        $this->curriculum = $curriculum;
        $this->logger = $logger;

        add_action('wp_ajax_olama_get_subjects', array($this, 'get_subjects'), 5);
        add_action('wp_ajax_olama_media_get_subjects', array($this, 'get_subjects'));
        add_action('wp_ajax_olama_media_get_semesters', array($this, 'get_semesters'));
        add_action('wp_ajax_academy_load_media_curriculum', array($this, 'load_curriculum'));
        add_action('wp_ajax_olama_media_video_coverage', array($this, 'video_coverage'));
        add_action('wp_ajax_olama_media_start_upload_job', array($this, 'start_upload_job'));
        add_action('wp_ajax_olama_media_refresh_upload_nonce', array($this, 'refresh_upload_nonce'));
        add_action('wp_ajax_olama_media_start_direct_upload', array($this, 'start_direct_upload'));
        add_action('wp_ajax_academy_upload_media_video_chunk', array($this, 'upload_chunk'));
        add_action('wp_ajax_olama_media_finalize_upload', array($this, 'finalize_upload'));
        add_action('wp_ajax_olama_media_finalize_direct_upload', array($this, 'finalize_direct_upload'));
        add_action('wp_ajax_olama_media_probe_direct_upload', array($this, 'probe_direct_upload'));
        add_action('wp_ajax_olama_media_log_direct_upload_event', array($this, 'log_direct_upload_event'));
        add_action('wp_ajax_olama_media_check_preview_status', array($this, 'check_preview_status'));
        add_action('wp_ajax_nopriv_olama_media_refresh_upload_nonce', array($this, 'refresh_upload_nonce'));
        add_action('wp_ajax_nopriv_olama_media_start_direct_upload', array($this, 'start_direct_upload'));
        add_action('wp_ajax_nopriv_academy_upload_media_video_chunk', array($this, 'upload_chunk'));
        add_action('wp_ajax_nopriv_olama_media_finalize_upload', array($this, 'finalize_upload'));
        add_action('wp_ajax_nopriv_olama_media_finalize_direct_upload', array($this, 'finalize_direct_upload'));
        add_action('wp_ajax_nopriv_olama_media_probe_direct_upload', array($this, 'probe_direct_upload'));
        add_action('wp_ajax_nopriv_olama_media_log_direct_upload_event', array($this, 'log_direct_upload_event'));
        add_action('wp_ajax_nopriv_olama_media_check_preview_status', array($this, 'check_preview_status'));
        add_action('wp_ajax_olama_media_save_notes', array($this, 'save_notes'));
        add_action('wp_ajax_academy_update_media_status', array($this, 'update_media_status'));
        add_action('wp_ajax_olama_media_delete_asset', array($this, 'delete_asset'));
        add_action('wp_ajax_academy_save_drive_settings', array($this, 'save_settings'));
        add_action('wp_ajax_academy_test_drive_connection', array($this, 'test_connection'));
        add_action('wp_ajax_olama_media_get_upload_log', array($this, 'get_upload_log'));
        add_action('wp_ajax_academy_get_upload_log', array($this, 'get_upload_log'));
        add_action('wp_ajax_olama_media_migrate_legacy', array($this, 'migrate_legacy'));
        add_action('wp_ajax_olama_media_sync_drive', array($this, 'sync_drive'));
        add_action('wp_ajax_olama_media_v2_scan_drive', array($this, 'v2_scan_drive'));
        add_action('wp_ajax_olama_media_v2_match_subject', array($this, 'v2_match_subject'));
        add_action('wp_ajax_olama_media_v2_get_review_queue', array($this, 'v2_get_review_queue'));
        add_action('wp_ajax_olama_media_v2_approve_link', array($this, 'v2_approve_link'));
        add_action('wp_ajax_olama_media_v2_reject_link', array($this, 'v2_reject_link'));
        add_action('wp_ajax_olama_media_v2_manual_link', array($this, 'v2_manual_link'));
        add_action('wp_ajax_olama_media_v2_unlink', array($this, 'v2_unlink'));
        add_action('wp_ajax_olama_media_v2_reset_index', array($this, 'v2_reset_index'));
        add_action('wp_ajax_olama_media_v2_import_legacy', array($this, 'v2_import_legacy'));
        add_action('wp_ajax_olama_media_v2_latest_runs', array($this, 'v2_latest_runs'));
    }

    public function get_subjects()
    {
        $this->verify_nonce();
        $this->require_upload();
        $grade_id = absint($_REQUEST['grade_id'] ?? 0);
        wp_send_json_success($this->curriculum->get_subjects($grade_id));
    }

    public function get_semesters()
    {
        $this->verify_nonce();
        $this->require_manage();
        wp_send_json_success($this->curriculum->get_semesters(absint($_REQUEST['academic_year_id'] ?? 0)));
    }

    public function load_curriculum()
    {
        $this->verify_nonce();
        $this->require_upload();

        $year_id = absint($_REQUEST['academic_year_id'] ?? 0);
        $semester_id = absint($_REQUEST['semester_id'] ?? 0);
        $grade_id = absint($_REQUEST['grade_id'] ?? 0);
        $subject_id = absint($_REQUEST['subject_id'] ?? 0);

        if (!$semester_id || !$grade_id || !$subject_id) {
            wp_send_json_error(__('Missing curriculum filters.', 'olama-media-library'));
        }

        $data = (new Olama_Media_V2_Repository())->get_curriculum_with_v2_links($year_id, $semester_id, $grade_id, $subject_id);
        if (is_wp_error($data)) {
            wp_send_json_error($data->get_error_message());
        }

        wp_send_json_success($data);
    }

    public function video_coverage()
    {
        $this->verify_nonce();
        $this->require_manage();
        $semester_id = absint($_REQUEST['semester_id'] ?? 0);
        if (!$semester_id) {
            wp_send_json_error(__('Select a semester first.', 'olama-media-library'));
        }

        $rows = $this->db->get_video_coverage(
            $semester_id,
            absint($_REQUEST['grade_id'] ?? 0),
            absint($_REQUEST['subject_id'] ?? 0)
        );
        if (is_wp_error($rows)) {
            wp_send_json_error($rows->get_error_message());
        }
        $video_counts = (new Olama_Media_V2_Repository())->get_active_link_counts_for_lessons(wp_list_pluck($rows, 'lesson_id'));
        foreach ($rows as $row) {
            $row->video_count = absint($video_counts[absint($row->lesson_id)] ?? 0);
            $row->has_video = $row->video_count > 0 ? 1 : 0;
        }
        wp_send_json_success(array('rows' => $rows));
    }

    public function start_upload_job()
    {
        $this->verify_upload_request('upload');

        $job_uuid = sanitize_key($_POST['file_uuid'] ?? wp_generate_uuid4());
        $filename = sanitize_file_name($_POST['filename'] ?? '');
        $total_size = absint($_POST['total_size'] ?? 0);
        $total_chunks = absint($_POST['total_chunks'] ?? 0);
        $validation = $this->validate_video($filename, $total_size);
        if (is_wp_error($validation)) {
            $this->send_upload_error('invalid_file', 'file_validation', __('ملف الفيديو غير صالح.', 'olama-media-library'), $validation->get_error_message(), false, array(
                'job_uuid' => $job_uuid,
            ));
        }

        $job_id = $this->db->create_or_update_job($job_uuid, array(
            'original_filename' => $filename,
            'mime_type' => 'video/mp4',
            'file_size' => $total_size,
            'total_chunks' => $total_chunks,
            'status' => 'created',
            'created_by' => get_current_user_id(),
        ));
        $this->logger->log('upload_job_created', 'Upload job created.', array('filename' => $filename), $job_id);
        wp_send_json_success(array('job_uuid' => $job_uuid, 'job_id' => $job_id));
    }

    public function refresh_upload_nonce()
    {
        if (!is_user_logged_in()) {
            $this->send_upload_error('auth_session_expired', 'auth_session_check', __('انتهت جلسة الدخول. يرجى تسجيل الدخول من جديد ثم إعادة المحاولة.', 'olama-media-library'), 'WordPress login session expired.', false, array('http_status' => 403));
        }

        if (!Olama_Media_Admin::can_upload()) {
            $this->send_upload_error('capability_denied', 'capability_check', __('لا تملك صلاحية رفع الفيديوهات.', 'olama-media-library'), 'Current user cannot upload media videos.', false, array('http_status' => 403));
        }

        $drive = new Olama_Media_Drive();
        $health = $drive->get_auth_health();
        $auth_warning = '';
        if (!$health['is_configured'] || !$health['has_refresh_token'] || !$health['can_refresh']) {
            $auth_warning = __('تنبيه: اتصال Google Drive غير مكتمل. لن تنجح عملية رفع الفيديوهات حتى تتم إعادة المصادقة.', 'olama-media-library');
        }

        wp_send_json_success(array(
            'nonce' => wp_create_nonce('olama_media_library_nonce'),
            'drive_authenticated' => $health['is_configured'] && $health['has_refresh_token'] && $health['can_refresh'],
            'has_refresh_token' => (bool) $health['has_refresh_token'],
            'auth_warning' => $auth_warning,
        ));
    }

    public function start_direct_upload()
    {
        $handler_start = microtime(true);
        $this->verify_upload_request('upload');

        $job_uuid = sanitize_key($_POST['file_uuid'] ?? wp_generate_uuid4());
        $filename = sanitize_file_name($_POST['file_name'] ?? $_POST['filename'] ?? '');
        $file_size = absint($_POST['file_size'] ?? $_POST['total_size'] ?? 0);
        $mime_type = sanitize_text_field($_POST['mime_type'] ?? 'video/mp4');

        if ($mime_type !== 'video/mp4') {
            $this->send_upload_error('unsupported_mime_type', 'file_validation', __('ملف الفيديو غير مدعوم. يدعم الرفع المباشر ملفات MP4 فقط حالياً.', 'olama-media-library'), 'Direct upload MVP supports video/mp4 only.', false, array(
                'job_uuid' => $job_uuid,
            ));
        }

        $validation = $this->validate_video($filename, $file_size);
        if (is_wp_error($validation)) {
            $this->send_upload_error('invalid_file', 'file_validation', __('ملف الفيديو غير صالح.', 'olama-media-library'), $validation->get_error_message(), false, array(
                'job_uuid' => $job_uuid,
            ));
        }

        $drive = new Olama_Media_Drive();
        $health = $drive->get_auth_health();
        if (!$health['is_configured'] || !$health['has_refresh_token']) {
            $this->send_upload_error('google_refresh_token_missing', 'google_auth_check', __('اتصال Google Drive غير مكتمل. يرجى إعادة المصادقة من إعدادات Drive.', 'olama-media-library'), 'Google Drive is not configured or refresh token is missing.', false, array(
                'job_uuid' => $job_uuid,
                'event_type' => 'google_auth_error',
            ));
        }
        if (!$health['can_refresh']) {
            $this->send_upload_error('google_token_refresh_failed', 'google_token_refresh', __('فشل تجديد اتصال Google Drive. يرجى إعادة المصادقة مع Google ثم المحاولة مرة أخرى.', 'olama-media-library'), $health['last_error_message'] ?: 'Google Drive token refresh failed.', false, array(
                'job_uuid' => $job_uuid,
                'event_type' => 'google_auth_error',
            ));
        }

        $meta = $this->prepare_upload_meta($filename, $file_size, 1);
        if (is_wp_error($meta)) {
            $this->send_wp_upload_error($meta, 'request_validation', array(
                'job_uuid' => $job_uuid,
            ));
        }

        $folder_id = $drive->get_or_create_nested_folder($meta['path']);
        if (is_wp_error($folder_id)) {
            $this->send_wp_upload_error($folder_id, 'drive_session_create', array(
                'job_uuid' => $job_uuid,
            ));
        }

        $reserved_file = $drive->create_metadata_file($meta['target_filename'], $folder_id, 'video/mp4');
        if (is_wp_error($reserved_file)) {
            $this->send_wp_upload_error($reserved_file, 'drive_metadata_file_create', array(
                'job_uuid' => $job_uuid,
            ));
        }
        $drive_file_id = sanitize_text_field($reserved_file['id'] ?? '');

        $asset_id = $this->db->upsert_asset(array(
            'id' => $meta['record_id'],
            'academic_year_id' => $meta['academic_year_id'],
            'semester_id' => $meta['semester_id'],
            'grade_id' => $meta['grade_id'],
            'subject_id' => $meta['subject_id'],
            'unit_id' => $meta['unit_id'],
            'lesson_id' => $meta['lesson_id'],
            'title' => $meta['lesson_name'],
            'original_filename' => $filename,
            'mime_type' => 'video/mp4',
            'file_size' => $file_size,
            'drive_file_id' => $drive_file_id,
            'drive_folder_id' => $folder_id,
            'web_view_link' => esc_url_raw($reserved_file['web_view_link'] ?? ''),
            'web_content_link' => esc_url_raw($reserved_file['web_content_link'] ?? ''),
            'thumbnail_link' => esc_url_raw($reserved_file['thumbnail_link'] ?? ''),
            'transport_method' => 'direct_google',
            'upload_status' => 'uploading',
            'preview_status' => 'not_checked',
            'approval_status' => 'pending',
            'uploaded_by' => get_current_user_id(),
        ));

        $job_id = $this->db->create_or_update_job($job_uuid, array(
            'asset_id' => $asset_id,
            'original_filename' => $filename,
            'mime_type' => 'video/mp4',
            'file_size' => $file_size,
            'total_chunks' => 1,
            'uploaded_chunks' => 0,
            'drive_file_id' => $drive_file_id,
            'status' => 'direct_file_reserved',
            'transport_method' => 'direct_google',
            'expected_file_size' => $file_size,
            'uploaded_bytes' => 0,
            'created_by' => get_current_user_id(),
        ));

        $session = $drive->create_direct_resumable_update_session($drive_file_id, 'video/mp4', $file_size);
        if (is_wp_error($session)) {
            $this->db->update_asset($asset_id, array('upload_status' => 'failed'));
            $this->db->update_job($job_id, array('status' => 'direct_session_failed', 'error_message' => $session->get_error_message()));
            $this->logger->log('direct_reserved_file_incomplete', 'Direct upload reserved file has no upload session.', array(
                'job_uuid' => $job_uuid,
                'asset_id' => $asset_id,
                'drive_file_id' => $drive_file_id,
                'file_size' => $file_size,
                'transport_method' => 'direct_google',
                'message_en' => $session->get_error_message(),
                'memory_peak_mb' => $this->memory_mb(memory_get_peak_usage(true)),
            ), $job_id, $asset_id);
            $this->send_wp_upload_error($session, 'drive_session_create', array(
                'job_uuid' => $job_uuid,
                'asset_id' => $asset_id,
                'job_id' => $job_id,
                'mark_failed' => true,
            ));
        }

        $this->db->update_job($job_id, array(
            'status' => 'direct_session_created',
            'drive_upload_uri' => esc_url_raw($session['upload_url']),
            'direct_upload_url_hash' => sanitize_text_field($session['upload_url_hash']),
        ));

        $this->logger->log('direct_upload_session_created', 'Direct Google upload session created.', array(
            'job_uuid' => $job_uuid,
            'asset_id' => $asset_id,
            'file_name' => $filename,
            'file_size' => $file_size,
            'mime_type' => 'video/mp4',
            'folder_id' => $folder_id,
            'drive_file_id' => $drive_file_id,
            'transport_method' => 'direct_google',
            'upload_url_hash' => sanitize_text_field($session['upload_url_hash']),
            'memory_peak_mb' => $this->memory_mb(memory_get_peak_usage(true)),
            'total_handler_ms' => $this->elapsed_ms($handler_start),
        ), $job_id, $asset_id);

        wp_send_json_success(array(
            'upload_url' => esc_url_raw($session['upload_url']),
            'job_uuid' => $job_uuid,
            'asset_id' => $asset_id,
            'drive_file_id' => $drive_file_id,
            'file_name' => $filename,
            'file_size' => $file_size,
            'mime_type' => 'video/mp4',
            'expires_hint_seconds' => absint($session['expires_hint_seconds']),
            'transport_method' => 'direct_google',
        ));
    }

    public function upload_chunk()
    {
        $handler_start = microtime(true);
        $timings = array();
        set_time_limit(0);
        ignore_user_abort(true);

        $stage_start = microtime(true);
        $this->verify_upload_request('upload');

        $file_uuid = sanitize_key($_POST['file_uuid'] ?? '');
        $chunk_index = absint($_POST['chunk_index'] ?? 0);
        $total_chunks = absint($_POST['total_chunks'] ?? 0);
        $filename = sanitize_file_name($_POST['filename'] ?? '');
        $total_size = absint($_POST['total_size'] ?? 0);
        $start_byte = absint($_POST['start_byte'] ?? 0);
        $expected_chunk_size = absint($_POST['chunk_size'] ?? 0);

        if (!$file_uuid || !$total_chunks || empty($_FILES['video_chunk'])) {
            $this->send_upload_error('invalid_job', 'request_validation', __('طلب رفع الفيديو غير صالح. يرجى إعادة المحاولة.', 'olama-media-library'), 'Invalid upload chunk request.', false, array(
                'failed_chunk_index' => $chunk_index,
                'job_uuid' => $file_uuid,
            ));
        }

        $validation = $this->validate_video($filename, $total_size);
        if (is_wp_error($validation)) {
            $this->send_upload_error('invalid_file', 'file_validation', __('ملف الفيديو غير صالح.', 'olama-media-library'), $validation->get_error_message(), false, array(
                'failed_chunk_index' => $chunk_index,
                'job_uuid' => $file_uuid,
            ));
        }

        $chunk_validation = $this->validate_chunk_request($chunk_index, $total_chunks, $start_byte, $expected_chunk_size);
        if (is_wp_error($chunk_validation)) {
            $this->send_upload_error('invalid_content_range', 'content_range_validation', __('موضع جزء الفيديو غير صحيح. يرجى إعادة محاولة الرفع.', 'olama-media-library'), $chunk_validation->get_error_message(), false, array(
                'chunk_index' => $chunk_index,
                'total_chunks' => $total_chunks,
                'start_byte' => $start_byte,
                'expected_chunk_size' => $expected_chunk_size,
                'failed_chunk_index' => $chunk_index,
                'job_uuid' => $file_uuid,
            ));
        }
        $timings['request_validation_ms'] = $this->elapsed_ms($stage_start);

        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit($upload_dir['basedir']) . 'olama-media-temp/' . $file_uuid;
        wp_mkdir_p($temp_dir);
        $meta_path = trailingslashit($temp_dir) . 'meta.json';

        $job_id = 0;
        $asset_id = 0;

        try {
            $stage_start = microtime(true);
            $drive = new Olama_Media_Drive();
            $drive_health = $drive->get_auth_health();

            if (file_exists($meta_path)) {
                $meta = json_decode(file_get_contents($meta_path), true);
                if (!is_array($meta) || empty($meta['resume_uri'])) {
                    throw new Exception(__('Upload metadata is corrupted. Please restart the upload.', 'olama-media-library'));
                }
            } else {
                $db_lookup_start = microtime(true);
                $existing_job = $this->db->get_job($file_uuid);
                $timings['db_job_lookup_ms'] = $this->elapsed_ms($db_lookup_start);
            }

            if (!isset($meta) && !empty($existing_job) && !empty($existing_job->drive_upload_uri)) {
                $meta = $this->recover_upload_meta_from_db($file_uuid, $filename, $total_size, $total_chunks);
                if (is_wp_error($meta)) {
                    throw new Exception($meta->get_error_message());
                }

                file_put_contents($meta_path, wp_json_encode($meta));
                $asset_id = absint($meta['asset_id'] ?? 0);
                $job_id = absint($meta['job_id'] ?? 0);
                $this->logger->log('upload_meta_recovered_from_db', 'Upload metadata recovered from DB.', array(
                    'chunk_index' => $chunk_index,
                    'file_uuid' => $file_uuid,
                ), $job_id, $asset_id);
            } elseif (!isset($meta)) {
                if ($chunk_index > 0) {
                    throw new Exception(__('Upload session was lost. Please restart the upload.', 'olama-media-library'));
                }

                $meta = $this->prepare_upload_meta($filename, $total_size, $total_chunks);
                if (is_wp_error($meta)) {
                    $this->send_wp_upload_error($meta, 'request_validation', array(
                        'failed_chunk_index' => $chunk_index,
                        'job_uuid' => $file_uuid,
                    ));
                }

                if (!$drive_health['has_refresh_token']) {
                    $this->send_upload_error('google_refresh_token_missing', 'google_auth_check', __('اتصال Google Drive غير مكتمل. يرجى إعادة المصادقة مع Google من إعدادات Drive.', 'olama-media-library'), 'Google Drive refresh token is missing.', false, array(
                        'failed_chunk_index' => $chunk_index,
                        'job_uuid' => $file_uuid,
                        'event_type' => 'google_auth_error',
                    ));
                }

                if (!$drive_health['can_refresh']) {
                    $this->send_upload_error('google_token_refresh_failed', 'google_token_refresh', __('فشل تجديد اتصال Google Drive. يرجى إعادة المصادقة مع Google ثم المحاولة مرة أخرى.', 'olama-media-library'), $drive_health['last_error_message'] ?: 'Google Drive token refresh failed.', false, array(
                        'failed_chunk_index' => $chunk_index,
                        'job_uuid' => $file_uuid,
                        'event_type' => 'google_auth_error',
                    ));
                }

                $folder_id = $drive->get_or_create_nested_folder($meta['path']);
                if (is_wp_error($folder_id)) {
                    $this->send_wp_upload_error($folder_id, 'drive_session_create', array(
                        'failed_chunk_index' => $chunk_index,
                        'job_uuid' => $file_uuid,
                    ));
                }

                $resume_uri = $drive->init_resumable_upload($meta['target_filename'], 'video/mp4', $folder_id, $total_size);
                if (is_wp_error($resume_uri)) {
                    $this->send_wp_upload_error($resume_uri, 'drive_session_create', array(
                        'failed_chunk_index' => $chunk_index,
                        'job_uuid' => $file_uuid,
                    ));
                }

                $asset_id = $this->db->upsert_asset(array(
                    'id' => $meta['record_id'],
                    'academic_year_id' => $meta['academic_year_id'],
                    'semester_id' => $meta['semester_id'],
                    'grade_id' => $meta['grade_id'],
                    'subject_id' => $meta['subject_id'],
                    'unit_id' => $meta['unit_id'],
                    'lesson_id' => $meta['lesson_id'],
                    'title' => $meta['lesson_name'],
                    'original_filename' => $filename,
                    'mime_type' => 'video/mp4',
                    'file_size' => $total_size,
                    'drive_folder_id' => $folder_id,
                    'upload_status' => 'uploading',
                    'preview_status' => 'not_checked',
                    'approval_status' => 'pending',
                    'uploaded_by' => get_current_user_id(),
                ));

                $job_id = $this->db->create_or_update_job($file_uuid, array(
                    'asset_id' => $asset_id,
                    'original_filename' => $filename,
                    'mime_type' => 'video/mp4',
                    'file_size' => $total_size,
                    'total_chunks' => $total_chunks,
                    'drive_upload_uri' => esc_url_raw($resume_uri),
                    'status' => 'uploading',
                    'created_by' => get_current_user_id(),
                ));

                $meta['resume_uri'] = $resume_uri;
                $meta['asset_id'] = $asset_id;
                $meta['job_id'] = $job_id;
                file_put_contents($meta_path, wp_json_encode($meta));
                $this->logger->log('upload_started', 'Upload started.', array('filename' => $filename), $job_id, $asset_id);
            }
            if (!isset($timings['db_job_lookup_ms'])) {
                $timings['db_job_lookup_ms'] = 0;
            }
            $timings['meta_load_or_recovery_ms'] = $this->elapsed_ms($stage_start);

            $asset_id = absint($meta['asset_id'] ?? 0);
            $job_id = absint($meta['job_id'] ?? 0);
            $chunk_file = $_FILES['video_chunk'];

            $stage_start = microtime(true);
            $file_validation = $this->validate_uploaded_chunk_file($chunk_file);
            if (is_wp_error($file_validation)) {
                $this->send_wp_upload_error($file_validation, 'file_validation', array(
                    'failed_chunk_index' => $chunk_index,
                    'job_uuid' => $file_uuid,
                    'asset_id' => $asset_id,
                    'job_id' => $job_id,
                    'mark_failed' => true,
                ));
            }

            $chunk_size_bytes = absint($chunk_file['size']);
            $chunk_end_byte = $start_byte + $chunk_size_bytes - 1;
            $timings['temp_file_validation_ms'] = $this->elapsed_ms($stage_start);

            $stage_start = microtime(true);
            $memory_before = $this->memory_mb(memory_get_usage(true));
            $result = $drive->put_upload_chunk_streamed($meta['resume_uri'], $chunk_file['tmp_name'], $start_byte, $chunk_end_byte, $total_size, 'application/octet-stream');
            $memory_after = $this->memory_mb(memory_get_usage(true));
            @unlink($chunk_file['tmp_name']);
            $timings['drive_chunk_upload_ms'] = $this->elapsed_ms($stage_start);
            $timings['chunk_size_bytes'] = $chunk_size_bytes;
            $timings['memory_before_mb'] = $memory_before;
            $timings['memory_after_mb'] = $memory_after;
            $timings['memory_peak_mb'] = $this->memory_mb(memory_get_peak_usage(true));
            $timings['transfer_method'] = is_wp_error($result) ? 'unknown' : sanitize_key($result['transfer_method'] ?? 'unknown');
            $timings['http_status'] = is_wp_error($result) ? 0 : absint($result['http_status'] ?? 0);

            if (is_wp_error($result)) {
                $this->send_wp_upload_error($result, 'drive_chunk_upload', array(
                    'failed_chunk_index' => $chunk_index,
                    'job_uuid' => $file_uuid,
                    'asset_id' => $asset_id,
                    'job_id' => $job_id,
                    'mark_failed' => true,
                    'memory_peak_mb' => $this->memory_mb(memory_get_peak_usage(true)),
                ));
            }

            if (($result['transfer_method'] ?? '') === 'fallback_wp_http') {
                $this->logger->log('drive_streaming_unavailable_fallback_used', 'cURL streaming unavailable; fallback WP HTTP transfer used.', array(
                    'chunk_index' => $chunk_index,
                    'total_chunks' => $total_chunks,
                    'chunk_size_bytes' => $chunk_size_bytes,
                    'transfer_method' => 'fallback_wp_http',
                    'http_status' => absint($result['http_status'] ?? 0),
                ), $job_id, $asset_id);
            }

            $stage_start = microtime(true);
            $this->db->update_job($job_id, array('uploaded_chunks' => $chunk_index + 1));
            $timings['db_progress_update_ms'] = $this->elapsed_ms($stage_start);
            $this->logger->log('chunk_uploaded_to_drive', 'Chunk uploaded to Drive.', array('chunk' => $chunk_index + 1, 'total' => $total_chunks), $job_id, $asset_id);

            if ($result['status'] === 'completed') {
                $stage_start = microtime(true);
                $this->db->update_asset($asset_id, array(
                    'drive_file_id' => $result['file_id'],
                ));
                $this->db->update_job($job_id, array(
                    'drive_file_id' => $result['file_id'],
                    'uploaded_chunks' => $total_chunks,
                    'status' => 'uploading',
                ));
                $timings['finalization_ms'] = 0;
                $timings['asset_update_ms'] = $this->elapsed_ms($stage_start);
                $timings['drive_metadata_fetch_ms'] = 0;
                $timings['drive_permission_update_ms'] = 0;
                $timings['total_handler_ms'] = $this->elapsed_ms($handler_start);
                $this->logger->log('upload_chunk_timing', 'Upload chunk timing.', $this->timing_context($timings, $chunk_index, $total_chunks), $job_id, $asset_id);
                $this->logger->log('upload_chunk_drive_timing', 'Upload chunk Drive timing.', array(
                    'drive_chunk_upload_ms' => $timings['drive_chunk_upload_ms'],
                    'chunk_size_bytes' => $timings['chunk_size_bytes'],
                    'memory_before_mb' => $timings['memory_before_mb'],
                    'memory_after_mb' => $timings['memory_after_mb'],
                    'memory_peak_mb' => $timings['memory_peak_mb'],
                    'transfer_method' => $timings['transfer_method'],
                    'http_status' => $timings['http_status'],
                    'chunk_index' => $chunk_index,
                    'total_chunks' => $total_chunks,
                ), $job_id, $asset_id);
                $this->recursive_rmdir($temp_dir);

                wp_send_json_success(array(
                    'completed' => true,
                    'needs_finalize' => true,
                    'asset_id' => $asset_id,
                    'job_uuid' => $file_uuid,
                ));
            }

            $timings['finalization_ms'] = 0;
            $timings['drive_metadata_fetch_ms'] = 0;
            $timings['drive_permission_update_ms'] = 0;
            $timings['asset_update_ms'] = 0;
            $timings['total_handler_ms'] = $this->elapsed_ms($handler_start);
            $this->logger->log('upload_chunk_timing', 'Upload chunk timing.', $this->timing_context($timings, $chunk_index, $total_chunks), $job_id, $asset_id);
            $this->logger->log('upload_chunk_drive_timing', 'Upload chunk Drive timing.', array(
                'drive_chunk_upload_ms' => $timings['drive_chunk_upload_ms'],
                'chunk_size_bytes' => $timings['chunk_size_bytes'],
                'memory_before_mb' => $timings['memory_before_mb'],
                'memory_after_mb' => $timings['memory_after_mb'],
                'memory_peak_mb' => $timings['memory_peak_mb'],
                'transfer_method' => $timings['transfer_method'],
                'http_status' => $timings['http_status'],
                'chunk_index' => $chunk_index,
                'total_chunks' => $total_chunks,
            ), $job_id, $asset_id);

            wp_send_json_success(array('completed' => false, 'needs_finalize' => false, 'asset_id' => $asset_id));
        } catch (Exception $e) {
            if ($asset_id) {
                $this->db->update_asset($asset_id, array('upload_status' => 'failed'));
            }
            if ($job_id) {
                $this->db->update_job($job_id, array('status' => 'failed', 'error_message' => $e->getMessage()));
            }
            $this->logger->critical('upload_error', $e->getMessage(), array(
                'file_uuid' => $file_uuid,
                'failed_chunk_index' => $chunk_index,
                'total_chunks' => $total_chunks,
                'total_handler_ms' => $this->elapsed_ms($handler_start),
            ), $job_id, $asset_id);
            $this->send_upload_error('upload_failed', 'drive_chunk_upload', __('فشل رفع هذا الجزء من الفيديو. يرجى المحاولة مرة أخرى.', 'olama-media-library'), $e->getMessage(), $this->is_retryable_upload_error($e->getMessage()), array(
                'failed_chunk_index' => $chunk_index,
                'job_uuid' => $file_uuid,
                'asset_id' => $asset_id,
                'http_status' => 200,
                'memory_peak_mb' => $this->memory_mb(memory_get_peak_usage(true)),
            ));
        }
    }

    public function finalize_upload()
    {
        $handler_start = microtime(true);
        $timings = array();

        $this->verify_upload_request('upload');

        $job_uuid = sanitize_key($_POST['job_uuid'] ?? '');
        $asset_id = absint($_POST['asset_id'] ?? 0);
        $job = $job_uuid ? $this->db->get_job($job_uuid) : null;
        $asset = $asset_id ? $this->db->get_asset($asset_id) : null;

        if (!$asset && $job && $job->asset_id) {
            $asset_id = absint($job->asset_id);
            $asset = $this->db->get_asset($asset_id);
        }

        if (!$asset) {
            $this->send_upload_error('invalid_job', 'finalize_upload', __('تعذر العثور على سجل الفيديو. يرجى إعادة تحميل الصفحة والمحاولة مرة أخرى.', 'olama-media-library'), 'Media asset not found for finalize.', false, array(
                'job_uuid' => $job_uuid,
                'asset_id' => $asset_id,
            ));
        }

        $job_id = $job ? absint($job->id) : 0;
        $drive_file_id = sanitize_text_field($_POST['drive_file_id'] ?? '');
        if (!$drive_file_id && $job && !empty($job->drive_file_id)) {
            $drive_file_id = $job->drive_file_id;
        }
        if (!$drive_file_id && !empty($asset->drive_file_id)) {
            $drive_file_id = $asset->drive_file_id;
        }

        if (!$drive_file_id) {
            $this->send_upload_error('invalid_job', 'finalize_upload', __('ملف Google Drive غير مرتبط بسجل الفيديو. يرجى إعادة المحاولة.', 'olama-media-library'), 'Drive file id is missing for finalize.', false, array(
                'job_uuid' => $job_uuid,
                'asset_id' => $asset_id,
            ));
        }

        try {
            $finalization_start = microtime(true);
            $drive = new Olama_Media_Drive();

            $stage_start = microtime(true);
            $metadata = $drive->get_file_metadata($drive_file_id);
            $timings['drive_metadata_fetch_ms'] = $this->elapsed_ms($stage_start);
            if (is_wp_error($metadata)) {
                $this->send_wp_upload_error($metadata, 'finalize_upload', array(
                    'job_uuid' => $job_uuid,
                    'asset_id' => $asset_id,
                ));
            }

            $stage_start = microtime(true);
            $permission = $drive->ensure_file_permissions($drive_file_id);
            $timings['drive_permission_update_ms'] = $this->elapsed_ms($stage_start);
            if (is_wp_error($permission)) {
                $this->send_wp_upload_error($permission, 'finalize_upload', array(
                    'job_uuid' => $job_uuid,
                    'asset_id' => $asset_id,
                ));
            }

            $stage_start = microtime(true);
            $download_link = $metadata['web_content_link'] ?: 'https://drive.google.com/uc?export=download&id=' . rawurlencode($drive_file_id);
            $this->db->update_asset($asset_id, array(
                'drive_file_id' => $drive_file_id,
                'web_view_link' => esc_url_raw($metadata['web_view_link']),
                'web_content_link' => esc_url_raw($download_link),
                'thumbnail_link' => esc_url_raw($metadata['thumbnail_link']),
                'upload_status' => 'uploaded_to_drive',
                'preview_status' => 'processing',
                'uploaded_at' => current_time('mysql'),
            ));
            $this->register_v2_uploaded_file($asset, $drive_file_id, $metadata, $download_link);

            if ($job_id) {
                $this->db->update_job($job_id, array(
                    'drive_file_id' => $drive_file_id,
                    'status' => 'completed',
                    'completed_at' => current_time('mysql'),
                ));
            }
            $timings['asset_update_ms'] = $this->elapsed_ms($stage_start);
            $timings['finalization_ms'] = $this->elapsed_ms($finalization_start);
            $timings['total_handler_ms'] = $this->elapsed_ms($handler_start);
            $this->logger->log('upload_finalize_timing', 'Upload finalize timing.', $this->timing_context($timings, null, null), $job_id, $asset_id);
            $this->logger->log('upload_finalized', 'Upload finalized after Drive metadata registration.', array(
                'drive_file_id' => $drive_file_id,
                'permission_already_exists' => is_array($permission) && !empty($permission['already_exists']),
            ), $job_id, $asset_id);

            wp_send_json_success(array(
                'asset_id' => $asset_id,
                'job_uuid' => $job_uuid,
                'url' => esc_url_raw($metadata['web_view_link']),
                'download_url' => esc_url_raw($download_link),
                'upload_status' => 'uploaded_to_drive',
                'preview_status' => 'processing',
            ));
        } catch (Exception $e) {
            if ($job_id) {
                $this->db->update_job($job_id, array('status' => 'finalize_failed', 'error_message' => $e->getMessage()));
            }

            $this->db->update_asset($asset_id, array(
                'drive_file_id' => $drive_file_id,
                'upload_status' => 'uploaded_to_drive',
                'preview_status' => $asset->preview_status && $asset->preview_status !== 'not_checked' ? $asset->preview_status : 'not_checked',
            ));

            $timings['total_handler_ms'] = $this->elapsed_ms($handler_start);
            $this->logger->log('upload_finalize_timing', 'Upload finalize timing after failure.', $this->timing_context($timings, null, null), $job_id, $asset_id);
            $this->logger->log('upload_finalize_failed', $e->getMessage(), array('drive_file_id' => $drive_file_id), $job_id, $asset_id);

            $this->send_upload_error('finalize_failed', 'finalize_upload', __('تم رفع الملف، لكن فشل تثبيت بيانات الفيديو. يمكنك إعادة المحاولة بدون رفع الملف من جديد.', 'olama-media-library'), $e->getMessage(), true, array(
                'asset_id' => $asset_id,
                'job_uuid' => $job_uuid,
                'memory_peak_mb' => $this->memory_mb(memory_get_peak_usage(true)),
            ));

            wp_send_json_error(array(
                'retryable' => true,
                'asset_id' => $asset_id,
                'job_uuid' => $job_uuid,
                'message' => __('تم رفع الملف، لكن فشل تثبيت بيانات الفيديو. يمكنك إعادة المحاولة بدون رفع الملف من جديد.', 'olama-media-library'),
            ));
        }
    }

    public function finalize_direct_upload()
    {
        $handler_start = microtime(true);
        $this->verify_upload_request('upload');

        $job_uuid = sanitize_key($_POST['job_uuid'] ?? '');
        $asset_id = absint($_POST['asset_id'] ?? 0);
        $drive_file_id = sanitize_text_field($_POST['drive_file_id'] ?? '');

        $job = $job_uuid ? $this->db->get_job($job_uuid) : null;
        $asset = $asset_id ? $this->db->get_asset($asset_id) : null;
        if (!$asset && $job && $job->asset_id) {
            $asset_id = absint($job->asset_id);
            $asset = $this->db->get_asset($asset_id);
        }

        if (!$job || !$asset) {
            $this->send_upload_error('invalid_job', 'direct_finalize', __('تعذر العثور على سجل الرفع المباشر. يرجى إعادة تحميل الصفحة والمحاولة مرة أخرى.', 'olama-media-library'), 'Direct upload job or asset was not found.', false, array(
                'job_uuid' => $job_uuid,
                'asset_id' => $asset_id,
            ));
        }

        if ((int) $job->created_by !== get_current_user_id() && !Olama_Media_Admin::can_manage()) {
            $this->send_upload_error('capability_denied', 'capability_check', __('لا تملك صلاحية تثبيت بيانات هذا الفيديو.', 'olama-media-library'), 'Current user does not own this direct upload job.', false, array(
                'job_uuid' => $job_uuid,
                'asset_id' => $asset_id,
                'http_status' => 403,
            ));
        }

        if (!$drive_file_id && !empty($job->drive_file_id)) {
            $drive_file_id = sanitize_text_field($job->drive_file_id);
        }
        if (!$drive_file_id && !empty($asset->drive_file_id)) {
            $drive_file_id = sanitize_text_field($asset->drive_file_id);
        }

        if (!$drive_file_id) {
            $this->db->update_job($job->id, array('status' => 'finalize_failed', 'error_message' => 'Drive file id missing from stored direct upload job.'));
            $this->send_upload_error('direct_drive_file_id_missing', 'direct_finalize', __('تعذر العثور على رقم ملف Google Drive المرتبط بهذا الرفع المباشر. يمكنك إعادة المحاولة أو استخدام الرفع عبر WordPress.', 'olama-media-library'), 'Drive file id is missing from stored direct upload job.', true, array(
                'job_uuid' => $job_uuid,
                'asset_id' => $asset_id,
                'job_id' => $job->id,
                'event_type' => 'direct_upload_failed',
            ));
        }

        $this->logger->log('direct_upload_finalize_started', 'Direct upload finalize started.', array(
            'job_uuid' => $job_uuid,
            'asset_id' => $asset_id,
            'transport_method' => 'direct_google',
            'memory_peak_mb' => $this->memory_mb(memory_get_peak_usage(true)),
        ), $job->id, $asset_id);

        $drive = new Olama_Media_Drive();
        $metadata = $drive->get_file_metadata($drive_file_id);
        if (is_wp_error($metadata)) {
            $this->db->update_job($job->id, array('status' => 'finalize_failed', 'error_message' => $metadata->get_error_message()));
            $this->send_wp_upload_error($metadata, 'direct_finalize', array(
                'job_uuid' => $job_uuid,
                'asset_id' => $asset_id,
                'job_id' => $job->id,
                'event_type' => 'direct_upload_failed',
            ));
        }

        $expected_size = absint($job->expected_file_size ?: $job->file_size);
        $actual_size = absint($metadata['size'] ?? 0);
        $expected_folder = sanitize_text_field($asset->drive_folder_id ?? '');
        $parents = array_map('sanitize_text_field', (array) ($metadata['parents'] ?? array()));

        if (!empty($metadata['trashed']) || ($metadata['mime_type'] ?? '') !== 'video/mp4' || ($expected_size && $actual_size && $expected_size !== $actual_size) || ($expected_folder && $parents && !in_array($expected_folder, $parents, true))) {
            $this->db->update_job($job->id, array('status' => 'finalize_failed', 'error_message' => 'Direct upload Drive metadata validation failed.'));
            $this->logger->log('direct_upload_failed', 'Direct upload Drive metadata validation failed.', array(
                'job_uuid' => $job_uuid,
                'asset_id' => $asset_id,
                'expected_file_size' => $expected_size,
                'actual_file_size' => $actual_size,
                'mime_type' => sanitize_text_field($metadata['mime_type'] ?? ''),
                'trashed' => !empty($metadata['trashed']),
                'expected_folder_match' => !$expected_folder || !$parents || in_array($expected_folder, $parents, true),
                'transport_method' => 'direct_google',
            ), $job->id, $asset_id);
            $this->send_upload_error('direct_metadata_validation_failed', 'direct_finalize', __('فشل التحقق من ملف Google Drive بعد الرفع المباشر. يرجى إعادة المحاولة أو استخدام الرفع عبر WordPress.', 'olama-media-library'), 'Direct upload Drive metadata validation failed.', false, array(
                'job_uuid' => $job_uuid,
                'asset_id' => $asset_id,
                'job_id' => $job->id,
                'event_type' => 'direct_upload_failed',
            ));
        }

        $permission = $drive->ensure_file_permissions($drive_file_id);
        if (is_wp_error($permission)) {
            $this->db->update_job($job->id, array('status' => 'finalize_failed', 'error_message' => $permission->get_error_message()));
            $this->send_wp_upload_error($permission, 'direct_finalize', array(
                'job_uuid' => $job_uuid,
                'asset_id' => $asset_id,
                'job_id' => $job->id,
                'event_type' => 'direct_upload_failed',
            ));
        }

        $download_link = $metadata['web_content_link'] ?: 'https://drive.google.com/uc?export=download&id=' . rawurlencode($drive_file_id);
        $this->db->update_asset($asset_id, array(
            'drive_file_id' => $drive_file_id,
            'web_view_link' => esc_url_raw($metadata['web_view_link']),
            'web_content_link' => esc_url_raw($download_link),
            'thumbnail_link' => esc_url_raw($metadata['thumbnail_link']),
            'upload_status' => 'uploaded_to_drive',
            'preview_status' => 'processing',
            'transport_method' => 'direct_google',
            'uploaded_at' => current_time('mysql'),
        ));
        $this->register_v2_uploaded_file($asset, $drive_file_id, $metadata, $download_link);
        $this->db->update_job($job->id, array(
            'drive_file_id' => $drive_file_id,
            'status' => 'completed',
            'uploaded_chunks' => 1,
            'uploaded_bytes' => $actual_size ?: $expected_size,
            'direct_upload_completed_at' => current_time('mysql'),
            'completed_at' => current_time('mysql'),
        ));

        $this->logger->log('direct_upload_finalized', 'Direct upload finalized.', array(
            'job_uuid' => $job_uuid,
            'asset_id' => $asset_id,
            'file_size' => $actual_size ?: $expected_size,
            'transport_method' => 'direct_google',
            'permission_already_exists' => is_array($permission) && !empty($permission['already_exists']),
            'memory_peak_mb' => $this->memory_mb(memory_get_peak_usage(true)),
            'total_handler_ms' => $this->elapsed_ms($handler_start),
        ), $job->id, $asset_id);

        wp_send_json_success(array(
            'asset_id' => $asset_id,
            'job_uuid' => $job_uuid,
            'upload_status' => 'uploaded_to_drive',
            'preview_status' => 'processing',
            'web_view_link' => esc_url_raw($metadata['web_view_link']),
            'web_content_link' => esc_url_raw($download_link),
        ));
    }

    public function probe_direct_upload()
    {
        $this->verify_upload_request('upload');

        $job_uuid = sanitize_key($_POST['job_uuid'] ?? '');
        $asset_id = absint($_POST['asset_id'] ?? 0);
        $total_size = absint($_POST['total_size'] ?? 0);
        $job = $job_uuid ? $this->db->get_job($job_uuid) : null;
        $asset = $asset_id ? $this->db->get_asset($asset_id) : null;

        if (!$asset && $job && $job->asset_id) {
            $asset_id = absint($job->asset_id);
            $asset = $this->db->get_asset($asset_id);
        }

        if (!$job || !$asset || $job->transport_method !== 'direct_google') {
            $this->send_upload_error('invalid_job', 'direct_upload_probe', __('تعذر العثور على جلسة الرفع المباشر.', 'olama-media-library'), 'Direct upload job was not found for probe.', false, array(
                'job_uuid' => $job_uuid,
                'asset_id' => $asset_id,
            ));
        }

        if ((int) $job->created_by !== get_current_user_id() && !Olama_Media_Admin::can_manage()) {
            $this->send_upload_error('capability_denied', 'capability_check', __('لا تملك صلاحية فحص جلسة رفع هذا الفيديو.', 'olama-media-library'), 'Current user cannot probe this direct upload job.', false, array(
                'job_uuid' => $job_uuid,
                'asset_id' => $asset_id,
                'http_status' => 403,
            ));
        }

        $upload_uri = esc_url_raw($job->drive_upload_uri ?? '');
        if (!$upload_uri) {
            $this->send_upload_error('direct_session_missing', 'direct_upload_probe', __('تعذر العثور على جلسة Google Drive لهذا الرفع المباشر. يرجى إعادة المحاولة.', 'olama-media-library'), 'Direct upload session URL is missing from the job.', false, array(
                'job_uuid' => $job_uuid,
                'asset_id' => $asset_id,
            ));
        }

        $total_size = $total_size ?: absint($job->expected_file_size ?: $job->file_size);
        $response = wp_remote_request($upload_uri, array(
            'method' => 'PUT',
            'headers' => array(
                'Content-Range' => 'bytes */' . $total_size,
                'Content-Length' => '0',
            ),
            'body' => '',
            'timeout' => 60,
        ));

        $http_status = is_wp_error($response) ? 0 : absint(wp_remote_retrieve_response_code($response));
        $range_header = is_wp_error($response) ? '' : sanitize_text_field(wp_remote_retrieve_header($response, 'range'));
        $next_start = 0;
        $complete = false;
        $error_code = '';
        $retryable = false;

        if (is_wp_error($response)) {
            $error_code = 'direct_probe_transport_error';
            $retryable = true;
        } elseif ($http_status === 200 || $http_status === 201) {
            $complete = true;
            $next_start = $total_size;
        } elseif ($http_status === 308) {
            $next_start = $this->next_start_from_range($range_header);
        } elseif ($http_status === 404) {
            $error_code = 'direct_session_expired';
        } elseif ($http_status >= 400 && $http_status < 500) {
            $error_code = 'direct_session_invalid';
        } elseif ($http_status >= 500) {
            $error_code = 'direct_probe_server_error';
            $retryable = true;
        } else {
            $error_code = 'direct_probe_unexpected_status';
            $retryable = true;
        }

        $context = array(
            'job_uuid' => $job_uuid,
            'asset_id' => $asset_id,
            'google_http_status' => $http_status,
            'range_header' => $range_header,
            'next_start' => $next_start,
            'total_size' => $total_size,
            'complete' => $complete,
            'error_code' => $error_code,
            'transport_method' => 'direct_google',
        );
        $this->logger->log('direct_upload_probe', $error_code ? 'Direct upload probe returned an error state.' : 'Direct upload probe completed.', $context, absint($job->id), $asset_id);

        if ($error_code) {
            wp_send_json_error(array(
                'error_code' => $error_code,
                'message_ar' => $error_code === 'direct_session_expired'
                    ? __('انتهت صلاحية جلسة الرفع المباشر. يرجى إعادة المحاولة أو استخدام الرفع عبر WordPress.', 'olama-media-library')
                    : __('تعذر التحقق من جلسة الرفع المباشر. يمكنك إعادة المحاولة أو استخدام الرفع عبر WordPress.', 'olama-media-library'),
                'message_en' => is_wp_error($response) ? $response->get_error_message() : 'Direct upload probe failed.',
                'retryable' => $retryable,
                'stage' => 'direct_upload_probe',
                'job_uuid' => $job_uuid,
                'asset_id' => $asset_id,
                'http_status' => 200,
                'drive_http_status' => $http_status,
                'next_start' => $next_start,
                'complete' => false,
            ));
        }

        wp_send_json_success(array(
            'complete' => $complete,
            'next_start' => $next_start,
            'total_size' => $total_size,
            'google_http_status' => $http_status,
            'range_header' => $range_header,
        ));
    }

    public function log_direct_upload_event()
    {
        $this->verify_upload_request('upload');

        $job_uuid = sanitize_key($_POST['job_uuid'] ?? '');
        $asset_id = absint($_POST['asset_id'] ?? 0);
        $event_type = sanitize_key($_POST['event_type'] ?? '');
        $allowed = array(
            'direct_upload_selected',
            'direct_upload_started',
            'direct_upload_progress_checkpoint',
            'direct_missing_range_header',
            'direct_upload_browser_error',
            'direct_upload_completed_browser',
            'direct_upload_failed',
            'direct_upload_fallback_selected',
        );
        if (!in_array($event_type, $allowed, true)) {
            wp_send_json_error(__('Invalid event type.', 'olama-media-library'));
        }

        $job = $job_uuid ? $this->db->get_job($job_uuid) : null;
        $context = array(
            'job_uuid' => $job_uuid,
            'asset_id' => $asset_id,
            'file_size' => absint($_POST['file_size'] ?? 0),
            'uploaded_bytes' => absint($_POST['uploaded_bytes'] ?? 0),
            'loaded_bytes' => absint($_POST['loaded_bytes'] ?? $_POST['uploaded_bytes'] ?? 0),
            'total_bytes' => absint($_POST['total_bytes'] ?? $_POST['file_size'] ?? 0),
            'percent' => absint($_POST['percent'] ?? 0),
            'transport_method' => 'direct_google',
            'error_code' => sanitize_key($_POST['error_code'] ?? ''),
            'stage' => sanitize_key($_POST['stage'] ?? ''),
            'xhr_status' => absint($_POST['xhr_status'] ?? 0),
            'xhr_status_text' => sanitize_text_field($_POST['xhr_status_text'] ?? ''),
            'response_text_preview' => sanitize_textarea_field(substr((string) ($_POST['response_text_preview'] ?? ''), 0, 500)),
            'response_headers_preview' => sanitize_textarea_field(substr((string) ($_POST['response_headers_preview'] ?? ''), 0, 500)),
            'chunk_start' => absint($_POST['chunk_start'] ?? 0),
            'chunk_end' => absint($_POST['chunk_end'] ?? 0),
            'chunk_index' => absint($_POST['chunk_index'] ?? 0),
            'direct_chunk_size' => absint($_POST['direct_chunk_size'] ?? 0),
            'message_en' => sanitize_text_field($_POST['message_en'] ?? ''),
        );

        if ($job) {
            $update = array();
            if ($context['uploaded_bytes']) {
                $update['uploaded_bytes'] = $context['uploaded_bytes'];
            }
            if ($event_type === 'direct_upload_started') {
                $update['status'] = 'direct_uploading';
                $update['direct_upload_started_at'] = current_time('mysql');
            } elseif ($event_type === 'direct_upload_completed_browser') {
                $update['status'] = 'direct_browser_completed';
                $update['direct_upload_completed_at'] = current_time('mysql');
            } elseif ($event_type === 'direct_upload_failed' || $event_type === 'direct_upload_browser_error') {
                $update['status'] = 'direct_upload_failed';
                $update['error_message'] = $context['message_en'];
                if ($asset_id) {
                    $this->db->update_asset($asset_id, array('upload_status' => 'failed'));
                }
                $asset = $asset_id ? $this->db->get_asset($asset_id) : null;
                if ($asset && !empty($asset->drive_file_id)) {
                    $this->logger->log('direct_reserved_file_incomplete', 'Direct upload reserved Drive file is incomplete after browser failure.', array(
                        'job_uuid' => $job_uuid,
                        'asset_id' => $asset_id,
                        'drive_file_id' => sanitize_text_field($asset->drive_file_id),
                        'uploaded_bytes' => $context['uploaded_bytes'],
                        'total_bytes' => $context['total_bytes'],
                        'error_code' => $context['error_code'],
                        'stage' => $context['stage'],
                        'transport_method' => 'direct_google',
                    ), $job->id, $asset_id);
                }
            }
            if ($update) {
                $this->db->update_job($job->id, $update);
            }
        }

        $message = sanitize_text_field($_POST['message_en'] ?? $event_type);
        $this->logger->log($event_type, $message, $context, $job ? absint($job->id) : 0, $asset_id);
        wp_send_json_success();
    }

    public function check_preview_status()
    {
        $this->verify_upload_request('manage');

        $asset_id = absint($_POST['asset_id'] ?? 0);
        $asset = $this->db->get_asset($asset_id);
        if (!$asset || !$asset->drive_file_id) {
            $this->send_upload_error('invalid_job', 'preview_status_check', __('تعذر العثور على سجل الفيديو.', 'olama-media-library'), 'Media asset not found for preview status check.', false, array(
                'asset_id' => $asset_id,
            ));
        }

        $drive = new Olama_Media_Drive();
        $metadata = $drive->get_file_metadata($asset->drive_file_id);
        if (is_wp_error($metadata)) {
            $this->logger->log('drive_metadata_failed', $metadata->get_error_message(), array(), null, $asset_id);
            $this->send_wp_upload_error($metadata, 'preview_status_check', array(
                'asset_id' => $asset_id,
            ));
        }

        if (!empty($metadata['trashed'])) {
            $preview_status = 'failed';
        } elseif (!empty($metadata['video_media_metadata'])) {
            $preview_status = 'ready';
        } else {
            $preview_status = 'processing';
        }
        $this->db->update_asset($asset_id, array(
            'preview_status' => $preview_status,
            'web_view_link' => esc_url_raw($metadata['web_view_link']),
            'web_content_link' => esc_url_raw($metadata['web_content_link'] ?: $asset->web_content_link),
            'thumbnail_link' => esc_url_raw($metadata['thumbnail_link']),
            'preview_checked_at' => current_time('mysql'),
        ));
        $this->logger->log('drive_metadata_fetched', 'Drive metadata fetched.', array('preview_status' => $preview_status), null, $asset_id);

        wp_send_json_success(array('preview_status' => $preview_status, 'metadata' => $metadata));
    }

    public function save_notes()
    {
        $this->verify_nonce();
        $this->require_manage();

        $asset_id = absint($_POST['asset_id'] ?? 0);
        $notes = sanitize_textarea_field($_POST['notes'] ?? '');
        $this->db->update_asset($asset_id, array('notes' => $notes));
        wp_send_json_success();
    }

    public function update_media_status()
    {
        $this->verify_nonce();
        $this->require_approve();

        $asset_id = absint($_POST['media_id'] ?? $_POST['asset_id'] ?? 0);
        $status = sanitize_key($_POST['status'] ?? '');
        $notes = isset($_POST['comment']) ? sanitize_textarea_field($_POST['comment']) : null;
        if (!$asset_id || !in_array($status, array('approved', 'rejected', 'pending'), true)) {
            wp_send_json_error(__('Invalid approval status.', 'olama-media-library'));
        }

        $data = array('approval_status' => $status);
        if ($status === 'approved') {
            $data['approved_by'] = get_current_user_id();
            $data['approved_at'] = current_time('mysql');
        }
        if ($notes !== null) {
            $data['notes'] = $notes;
        }

        $this->db->update_asset($asset_id, $data);
        wp_send_json_success(__('Status updated.', 'olama-media-library'));
    }

    public function delete_asset()
    {
        $this->verify_nonce();
        $this->require_upload();
        $asset_id = absint($_POST['asset_id'] ?? 0);
        $this->db->update_asset($asset_id, array(
            'upload_status' => 'none',
            'preview_status' => 'not_checked',
            'approval_status' => 'pending',
            'drive_file_id' => null,
            'web_view_link' => null,
            'web_content_link' => null,
            'thumbnail_link' => null,
        ));
        wp_send_json_success();
    }

    public function save_settings()
    {
        $this->verify_nonce();
        if (!current_user_can('manage_options') && !current_user_can('olama_media_drive_settings')) {
            wp_send_json_error(__('Unauthorized.', 'olama-media-library'));
        }

        $current = get_option('academy_media_library_settings', array());
        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        $client_secret = sanitize_text_field($_POST['client_secret'] ?? '');
        $transport_mode = sanitize_key($_POST['olama_media_upload_transport_mode'] ?? $_POST['upload_transport_mode'] ?? 'auto');
        if (!in_array($transport_mode, array('wordpress_streamed', 'direct_google', 'auto'), true)) {
            $transport_mode = 'auto';
        }
        $settings = array(
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'root_folder_id' => trim(sanitize_text_field($_POST['root_folder_id'] ?? ''), " \t\n\r\0\x0B."),
            'max_file_size' => max(1, absint($_POST['max_file_size'] ?? 2048)),
            'olama_media_upload_transport_mode' => $transport_mode,
            'olama_media_direct_upload_threshold_mb' => max(1, absint($_POST['olama_media_direct_upload_threshold_mb'] ?? $_POST['direct_upload_threshold_mb'] ?? 20)),
            'olama_media_direct_chunk_size_mb' => max(1, absint($_POST['olama_media_direct_chunk_size_mb'] ?? 16)),
            'refresh_token' => $current['refresh_token'] ?? '',
            'access_token' => $current['access_token'] ?? null,
        );
        if (($current['client_id'] ?? '') !== $client_id || ($current['client_secret'] ?? '') !== $client_secret) {
            $settings['refresh_token'] = '';
            $settings['access_token'] = null;
        }

        update_option('academy_media_library_settings', $settings);
        wp_send_json_success(__('Settings saved.', 'olama-media-library'));
    }

    public function test_connection()
    {
        $this->verify_nonce();
        if (!current_user_can('manage_options') && !current_user_can('olama_media_drive_settings')) {
            wp_send_json_error(__('Unauthorized.', 'olama-media-library'));
        }
        $result = (new Olama_Media_Drive())->test_connection();
        is_wp_error($result) ? wp_send_json_error($result->get_error_message()) : wp_send_json_success($result);
    }

    public function get_upload_log()
    {
        $this->verify_nonce();
        $this->require_manage();
        wp_send_json_success($this->db->get_events(absint($_REQUEST['paged'] ?? 1), 20, array(
            'job_uuid' => sanitize_key($_REQUEST['job_uuid'] ?? ''),
            'event_type' => sanitize_key($_REQUEST['event_type'] ?? ''),
            'error_code' => sanitize_key($_REQUEST['error_code'] ?? ''),
        )));
    }

    public function migrate_legacy()
    {
        $this->verify_nonce();
        if (!current_user_can('manage_options') && !current_user_can('olama_media_drive_settings')) {
            wp_send_json_error(__('Unauthorized.', 'olama-media-library'));
        }
        $dry_run = !empty($_POST['dry_run']);
        wp_send_json_success($this->db->migrate_legacy($dry_run));
    }

    public function v2_scan_drive()
    {
        $this->verify_nonce(); $this->require_manage();
        $options = array('dry_run'=>!empty($_POST['dry_run']), 'max_depth'=>absint($_POST['max_depth'] ?? 10));
        $ids = array_map('absint', array($_POST['academic_year_id'] ?? 0, $_POST['semester_id'] ?? 0, $_POST['grade_id'] ?? 0, $_POST['subject_id'] ?? 0));
        $full_scan = !empty($_POST['full_scan']);
        if (!$full_scan && in_array(0, $ids, true)) {
            wp_send_json_error(__('Select the academic year, semester, grade, and subject before scanning Drive.', 'olama-media-library'));
        }
        if (!$full_scan) {
            $names = $this->curriculum->get_names($ids[0], $ids[1], $ids[2], $ids[3]);
            $drive = new Olama_Media_Drive();
            $candidate_paths = array(
                array($names['academic_year'], $names['semester'], $names['grade'], $names['subject']),
                array($names['semester'], $names['grade'], $names['subject']),
                array($names['grade'], $names['subject']),
                array($names['subject']),
            );
            foreach ($candidate_paths as $path) {
                $folder_id = $drive->find_nested_folder($path);
                if (is_wp_error($folder_id)) { wp_send_json_error($folder_id->get_error_message()); }
                if ($folder_id) { $options['start_folder_id'] = $folder_id; $options['path_parts'] = $path; break; }
            }
            if (empty($options['start_folder_id'])) { wp_send_json_error(__('The selected subject folder was not found in Drive.', 'olama-media-library')); }
        }
        $result = (new Olama_Media_Drive_Indexer(isset($drive) ? $drive : null))->scan($options);
        is_wp_error($result) ? wp_send_json_error($result->get_error_message()) : wp_send_json_success($result);
    }

    public function v2_match_subject()
    {
        $this->verify_nonce(); $this->require_manage();
        $ids = array_map('absint', array(
            $_POST['academic_year_id'] ?? 0, $_POST['semester_id'] ?? 0,
            $_POST['grade_id'] ?? 0, $_POST['subject_id'] ?? 0,
        ));
        if (in_array(0, $ids, true)) { wp_send_json_error(__('Select all curriculum filters.', 'olama-media-library')); }
        $result = (new Olama_Media_Matcher())->match_subject($ids[0], $ids[1], $ids[2], $ids[3], array(
            'dry_run'=>!empty($_POST['dry_run']), 'auto_apply'=>!empty($_POST['auto_apply']),
            'force_relink'=>!empty($_POST['force_relink']), 'save_review'=>!empty($_POST['save_review']),
        ));
        is_wp_error($result) ? wp_send_json_error($result->get_error_message()) : wp_send_json_success($result);
    }

    public function v2_get_review_queue()
    {
        $this->verify_nonce(); $this->require_manage();
        $filters = array();
        foreach (array('academic_year_id','semester_id','grade_id','subject_id','unit_id','lesson_id') as $key) {
            $filters[$key] = absint($_REQUEST[$key] ?? 0);
        }
        $filters['approval_status'] = sanitize_key($_REQUEST['approval_status'] ?? '');
        $filters['link_status'] = sanitize_key($_REQUEST['link_status'] ?? '');
        wp_send_json_success((new Olama_Media_V2_Repository())->get_review_queue($filters));
    }

    public function v2_approve_link()
    {
        $this->verify_nonce(); $this->require_manage();
        wp_send_json_success((new Olama_Media_V2_Repository())->approve_link(absint($_POST['link_id'] ?? 0), get_current_user_id()));
    }

    public function v2_reject_link()
    {
        $this->verify_nonce(); $this->require_manage();
        wp_send_json_success((new Olama_Media_V2_Repository())->reject_link(absint($_POST['link_id'] ?? 0), get_current_user_id(), wp_unslash($_POST['notes'] ?? '')));
    }

    public function v2_manual_link()
    {
        $this->verify_nonce(); $this->require_manage();
        global $wpdb;
        $file_id = sanitize_text_field($_POST['drive_file_id'] ?? '');
        $lesson_id = absint($_POST['lesson_id'] ?? 0);
        $file = (new Olama_Media_V2_Repository())->get_drive_file_by_file_id($file_id);
        $lesson = $wpdb->get_row($wpdb->prepare(
            "SELECT l.id lesson_id,l.unit_id,u.semester_id,u.grade_id,u.subject_id,s.academic_year_id
             FROM {$wpdb->prefix}olama_curriculum_lessons l
             JOIN {$wpdb->prefix}olama_curriculum_units u ON u.id=l.unit_id
             JOIN {$wpdb->prefix}olama_semesters s ON s.id=u.semester_id WHERE l.id=%d", $lesson_id
        ));
        if (!$file || !$lesson) { wp_send_json_error(__('Drive file or lesson was not found.', 'olama-media-library')); }
        $repo = new Olama_Media_V2_Repository();
        $part_number = absint($_POST['part_number'] ?? 0);
        $link_id = $repo->upsert_lesson_video_link(array(
            'drive_file_id'=>$file_id,'drive_file_row_id'=>absint($file->id),'academic_year_id'=>absint($lesson->academic_year_id),
            'semester_id'=>absint($lesson->semester_id),'grade_id'=>absint($lesson->grade_id),'subject_id'=>absint($lesson->subject_id),
            'unit_id'=>absint($lesson->unit_id),'lesson_id'=>$lesson_id,'part_number'=>$part_number ?: null,
            'sequence_order'=>absint($_POST['sequence_order'] ?? 0) ?: ($part_number ?: $repo->next_sequence_order($lesson_id)),
            'match_method'=>'manual','match_confidence'=>100,'approval_status'=>'pending','link_status'=>'active','linked_by'=>get_current_user_id(),
        ));
        wp_send_json_success(array('link_id'=>$link_id));
    }

    public function v2_unlink()
    {
        $this->verify_nonce(); $this->require_manage();
        wp_send_json_success((new Olama_Media_V2_Repository())->unlink_drive_file(absint($_POST['link_id'] ?? 0)));
    }

    public function v2_reset_index()
    {
        $this->verify_nonce(); $this->require_manage();
        if (sanitize_text_field(wp_unslash($_POST['confirmation_text'] ?? '')) !== 'RESET V2 MEDIA INDEX') {
            wp_send_json_error(__('Confirmation text does not match.', 'olama-media-library'));
        }
        $scope = sanitize_key($_POST['scope'] ?? '');
        if (!in_array($scope, array('links_only','manifest_only','all_v2'), true)) { wp_send_json_error(__('Invalid reset scope.', 'olama-media-library')); }
        wp_send_json_success((new Olama_Media_V2_Repository())->reset_v2_index($scope));
    }

    public function v2_latest_runs()
    {
        $this->verify_nonce(); $this->require_manage();
        wp_send_json_success((new Olama_Media_V2_Repository())->get_latest_runs(20));
    }

    public function v2_import_legacy()
    {
        $this->verify_nonce(); $this->require_manage();
        global $wpdb;
        $repo = new Olama_Media_V2_Repository();
        $normalizer = new Olama_Media_Normalizer();
        $include_stale = !empty($_POST['include_stale']);
        $rows = $wpdb->get_results("SELECT * FROM {$this->db->assets_table} WHERE drive_file_id IS NOT NULL AND drive_file_id<>''");
        $report = array('imported'=>0,'skipped_missing_lesson'=>0,'duplicate_drive_files'=>0,'errors'=>0);
        foreach ($rows as $row) {
            $lesson_exists = $row->lesson_id && $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}olama_curriculum_lessons WHERE id=%d", $row->lesson_id));
            if (!$lesson_exists && !$include_stale) { $report['skipped_missing_lesson']++; continue; }
            $existing = $repo->get_drive_file_by_file_id($row->drive_file_id);
            if ($existing) { $report['duplicate_drive_files']++; }
            $file_row_id = $repo->upsert_drive_file(array(
                'drive_file_id'=>$row->drive_file_id,'drive_folder_id'=>$row->drive_folder_id,'filename'=>$row->original_filename ?: $row->title,
                'normalized_filename'=>$normalizer->normalize_filename($row->original_filename ?: $row->title),
                'extension'=>$normalizer->extract_extension($row->original_filename),'mime_type'=>$row->mime_type,'file_size'=>$row->file_size,
                'web_view_link'=>$row->web_view_link,'web_content_link'=>$row->web_content_link,'thumbnail_link'=>$row->thumbnail_link,
                'scan_status'=>'active','last_seen_at'=>current_time('mysql'),
            ));
            if ($lesson_exists) {
                $repo->upsert_lesson_video_link(array(
                    'drive_file_id'=>$row->drive_file_id,'drive_file_row_id'=>$file_row_id,'academic_year_id'=>$row->academic_year_id,
                    'semester_id'=>$row->semester_id,'grade_id'=>$row->grade_id,'subject_id'=>$row->subject_id,'unit_id'=>$row->unit_id,
                    'lesson_id'=>$row->lesson_id,'sequence_order'=>$repo->next_sequence_order($row->lesson_id),'match_method'=>'legacy_import',
                    'match_confidence'=>80,'approval_status'=>$row->approval_status ?: 'pending','link_status'=>'active','linked_by'=>get_current_user_id(),
                ));
            }
            $report['imported']++;
        }
        wp_send_json_success($report);
    }

    public function sync_drive()
    {
        $this->verify_nonce();
        if (!current_user_can('manage_options') && !current_user_can('olama_media_drive_settings')) {
            wp_send_json_error(__('Unauthorized.', 'olama-media-library'));
        }

        $year_id = absint($_POST['academic_year_id'] ?? 0);
        $semester_id = absint($_POST['semester_id'] ?? 0);
        $grade_id = absint($_POST['grade_id'] ?? 0);
        $subject_id = absint($_POST['subject_id'] ?? 0);
        $dry_run = !empty($_POST['dry_run']);
        if (!$year_id || !$semester_id || !$grade_id || !$subject_id) {
            wp_send_json_error(__('Select the academic year, semester, grade, and subject first.', 'olama-media-library'));
        }

        $units = $this->db->get_curriculum_with_assets($year_id, $semester_id, $grade_id, $subject_id);
        if (is_wp_error($units)) {
            wp_send_json_error($units->get_error_message());
        }

        $drive = new Olama_Media_Drive();
        $names = $this->curriculum->get_names($year_id, $semester_id, $grade_id, $subject_id);
        $base_path = array($names['academic_year'], $names['semester'], $names['grade'], $names['subject']);
        $report = array(
            'sync_engine' => '1.2.1-arabic-filenames',
            'dry_run' => $dry_run,
            'folders_checked' => 0,
            'files_found' => 0,
            'matched' => 0,
            'created' => 0,
            'updated' => 0,
            'already_linked' => 0,
            'unmatched' => array(),
            'ambiguous' => array(),
            'missing_folders' => array(),
        );

        foreach ((array) $units as $unit) {
            $unit_name = sanitize_text_field($unit->unit_name);
            $candidate_paths = array(
                array_merge($base_path, array($unit_name)),
                array($names['grade'], $names['subject'], $unit_name),
                array($names['subject'], $unit_name),
                array($unit_name),
            );
            $folder_id = '';
            foreach ($candidate_paths as $candidate_path) {
                $folder_id = $drive->find_nested_folder($candidate_path);
                if (is_wp_error($folder_id)) {
                    wp_send_json_error($folder_id->get_error_message());
                }
                if ($folder_id) {
                    break;
                }
            }
            if (!$folder_id) {
                $fallback_folders = $drive->find_folders_by_name_recursive($unit_name);
                if (is_wp_error($fallback_folders)) {
                    wp_send_json_error($fallback_folders->get_error_message());
                }
                if (count($fallback_folders) === 1) {
                    $folder_id = $fallback_folders[0];
                } elseif (count($fallback_folders) > 1) {
                    $report['ambiguous'][] = array(
                        'unit' => $unit_name,
                        'reason' => 'More than one Drive folder has this unit name.',
                    );
                    continue;
                } else {
                    $report['missing_folders'][] = $unit_name;
                    continue;
                }
            }

            $report['folders_checked']++;
            $files = $drive->list_video_files($folder_id);
            if (is_wp_error($files)) {
                wp_send_json_error($files->get_error_message());
            }
            $report['files_found'] += count($files);

            $lesson_file_counts = array();
            foreach ($files as $candidate_file) {
                foreach ((array) $unit->lessons as $candidate_lesson) {
                    if ($this->drive_filename_matches_lesson($candidate_file['name'], $candidate_lesson)) {
                        $lesson_key = (string) absint($candidate_lesson->id);
                        $lesson_file_counts[$lesson_key] = isset($lesson_file_counts[$lesson_key]) ? $lesson_file_counts[$lesson_key] + 1 : 1;
                    }
                }
            }

            foreach ($files as $file) {
                $matches = array();
                foreach ((array) $unit->lessons as $lesson) {
                    if ($this->drive_filename_matches_lesson($file['name'], $lesson)) {
                        $matches[] = $lesson;
                    }
                }

                if (count($matches) !== 1) {
                    $key = count($matches) ? 'ambiguous' : 'unmatched';
                    $report[$key][] = array('file' => $file['name'], 'unit' => $unit->unit_name);
                    continue;
                }

                $lesson = $matches[0];
                if (($lesson_file_counts[(string) absint($lesson->id)] ?? 0) > 1) {
                    $report['ambiguous'][] = array('file' => $file['name'], 'unit' => $unit->unit_name, 'reason' => 'More than one Drive file matches this lesson.');
                    continue;
                }
                $report['matched']++;
                $existing_drive = $this->db->get_asset_by_drive_file_id($file['id']);
                if ($existing_drive && absint($existing_drive->lesson_id) === absint($lesson->id)) {
                    $report['already_linked']++;
                    continue;
                }

                $existing_lesson = !empty($lesson->media_record_id) ? $this->db->get_asset($lesson->media_record_id) : null;
                if ($existing_drive && absint($existing_drive->lesson_id) && absint($existing_drive->lesson_id) !== absint($lesson->id)) {
                    $report['ambiguous'][] = array('file' => $file['name'], 'unit' => $unit->unit_name, 'reason' => 'File is already linked to another lesson.');
                    continue;
                }
                if ($existing_lesson && !empty($existing_lesson->drive_file_id) && $existing_lesson->drive_file_id !== $file['id']) {
                    $report['ambiguous'][] = array('file' => $file['name'], 'unit' => $unit->unit_name, 'reason' => 'Lesson already has another Drive video.');
                    continue;
                }

                $existing = $existing_drive ?: $existing_lesson;
                $existing ? $report['updated']++ : $report['created']++;
                if ($dry_run) {
                    continue;
                }

                $permission = $drive->ensure_file_permissions($file['id']);
                if (is_wp_error($permission)) {
                    $existing ? $report['updated']-- : $report['created']--;
                    $report['unmatched'][] = array('file' => $file['name'], 'unit' => $unit->unit_name, 'reason' => $permission->get_error_message());
                    continue;
                }

                $download_link = $file['web_content_link'] ?: 'https://drive.google.com/uc?export=download&id=' . rawurlencode($file['id']);
                $this->db->upsert_asset(array(
                    'id' => $existing ? absint($existing->id) : 0,
                    'academic_year_id' => $year_id,
                    'semester_id' => $semester_id,
                    'grade_id' => $grade_id,
                    'subject_id' => $subject_id,
                    'unit_id' => absint($unit->id),
                    'lesson_id' => absint($lesson->id),
                    'title' => sanitize_text_field($lesson->lesson_title),
                    'original_filename' => sanitize_file_name($file['name']),
                    'mime_type' => sanitize_text_field($file['mime_type']),
                    'file_size' => absint($file['size']),
                    'drive_file_id' => sanitize_text_field($file['id']),
                    'drive_folder_id' => sanitize_text_field($folder_id),
                    'web_view_link' => esc_url_raw($file['web_view_link']),
                    'web_content_link' => esc_url_raw($download_link),
                    'thumbnail_link' => esc_url_raw($file['thumbnail_link']),
                    'transport_method' => 'drive_sync',
                    'upload_status' => 'uploaded_to_drive',
                    'preview_status' => !empty($file['video_media_metadata']) ? 'ready' : 'processing',
                    'approval_status' => $existing ? sanitize_key($existing->approval_status) : 'pending',
                    'uploaded_by' => get_current_user_id(),
                    'uploaded_at' => current_time('mysql'),
                ));
            }
        }

        $this->logger->log('drive_sync_completed', $dry_run ? 'Google Drive sync dry run completed.' : 'Google Drive sync completed.', $report);
        wp_send_json_success($report);
    }

    private function drive_filename_matches_lesson($filename, $lesson)
    {
        $basename = pathinfo((string) $filename, PATHINFO_FILENAME);
        $file_key = $this->normalize_match_text($basename);
        $title_key = $this->normalize_match_text($lesson->lesson_title ?? '');
        $number = trim((string) ($lesson->lesson_number ?? ''));
        $standard_key = $this->normalize_match_text('Lesson ' . $number . ' ' . ($lesson->lesson_title ?? ''));
        $arabic_key = $this->normalize_match_text("\u{062F}\u{0631}\u{0633} " . $number . ' ' . ($lesson->lesson_title ?? ''));
        $without_arabic_prefix = preg_replace('/^\s*\x{062F}\x{0631}\x{0633}\s*[\p{N}]+\s*/u', '', $basename);
        $without_english_prefix = preg_replace('/^\s*lesson\s*[\p{N}]+\s*/iu', '', $basename);
        $prefixless_keys = array(
            $this->normalize_match_text($without_arabic_prefix),
            $this->normalize_match_text($without_english_prefix),
        );

        return $file_key !== '' && (
            in_array($file_key, array($title_key, $standard_key, $arabic_key), true)
            || in_array($title_key, $prefixless_keys, true)
        );
    }

    private function normalize_match_text($value)
    {
        $value = wp_strip_all_tags((string) $value);
        $value = preg_replace('/[\x{064B}-\x{065F}\x{0670}\x{06D6}-\x{06ED}]/u', '', $value);
        // Normalize equivalent Arabic letters using code points to keep this source encoding-safe.
        $value = strtr($value, array(
            "\u{0623}" => "\u{0627}",
            "\u{0625}" => "\u{0627}",
            "\u{0622}" => "\u{0627}",
            "\u{0671}" => "\u{0627}",
            "\u{0649}" => "\u{064A}",
            "\u{0624}" => "\u{0648}",
            "\u{0626}" => "\u{064A}",
            "\u{0640}" => '',
        ));
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));
    }

    private function prepare_upload_meta($filename, $total_size, $total_chunks)
    {
        $lesson_id = absint($_POST['lesson_id'] ?? 0);
        $unit_id = absint($_POST['unit_id'] ?? 0);
        $lesson_name = sanitize_text_field($_POST['lesson_name'] ?? '');
        if (!$lesson_id || !$unit_id || !$lesson_name) {
            return new WP_Error('missing_lesson', __('Missing lesson data.', 'olama-media-library'));
        }

        $academic_year_id = absint($_POST['academic_year_id'] ?? 0);
        $semester_id = absint($_POST['semester_id'] ?? 0);
        $grade_id = absint($_POST['grade_id'] ?? 0);
        $subject_id = absint($_POST['subject_id'] ?? 0);
        $names = $this->curriculum->get_names($academic_year_id, $semester_id, $grade_id, $subject_id);
        $lesson_number = sanitize_text_field($_POST['lesson_number'] ?? '');
        $part_number = absint($_POST['part_number'] ?? 0);
        $extension = 'mp4';
        $target = $part_number
            ? sprintf('Lesson %s Part %d %s.%s', $lesson_number, $part_number, $lesson_name, $extension)
            : sprintf('Lesson %s %s.%s', $lesson_number, $lesson_name, $extension);

        return array(
            'record_id' => absint($_POST['id'] ?? 0),
            'academic_year_id' => $academic_year_id,
            'semester_id' => $semester_id,
            'grade_id' => $grade_id,
            'subject_id' => $subject_id,
            'unit_id' => $unit_id,
            'lesson_id' => $lesson_id,
            'lesson_name' => $lesson_name,
            'target_filename' => sanitize_file_name($target),
            'path' => array($names['academic_year'], $names['semester'], $names['grade'], $names['subject'], sanitize_text_field($_POST['unit_name'] ?? '')),
            'total_chunks' => $total_chunks,
            'total_size' => $total_size,
            'original_filename' => $filename,
        );
    }

    private function recover_upload_meta_from_db($file_uuid, $filename, $total_size, $total_chunks)
    {
        $job = $this->db->get_job($file_uuid);
        if (!$job || empty($job->drive_upload_uri)) {
            return new WP_Error('missing_upload_meta', __('Upload metadata was lost and no resumable Drive session was found. Please restart the upload.', 'olama-media-library'));
        }

        $asset = $job->asset_id ? $this->db->get_asset($job->asset_id) : null;
        $meta = $this->prepare_upload_meta($filename ?: $job->original_filename, $total_size ?: $job->file_size, $total_chunks ?: $job->total_chunks);
        if (is_wp_error($meta)) {
            return $meta;
        }

        $meta['resume_uri'] = esc_url_raw($job->drive_upload_uri);
        $meta['asset_id'] = absint($job->asset_id);
        $meta['job_id'] = absint($job->id);
        $meta['record_id'] = $asset ? absint($asset->id) : absint($meta['record_id']);
        $meta['original_filename'] = sanitize_file_name($job->original_filename ?: $meta['original_filename']);
        $meta['total_chunks'] = absint($job->total_chunks ?: $meta['total_chunks']);
        $meta['total_size'] = absint($job->file_size ?: $meta['total_size']);

        $this->db->update_job($job->id, array('status' => 'uploading'));
        if ($asset) {
            $this->db->update_asset($asset->id, array('upload_status' => 'uploading'));
        }

        return $meta;
    }

    private function validate_chunk_request($chunk_index, $total_chunks, $start_byte, $expected_chunk_size)
    {
        if ($chunk_index >= $total_chunks) {
            return new WP_Error('chunk_index_out_of_range', __('Invalid chunk number for this upload.', 'olama-media-library'));
        }

        if ($expected_chunk_size > 0) {
            $expected_start = $chunk_index * $expected_chunk_size;
            if ($start_byte !== $expected_start) {
                return new WP_Error('chunk_start_mismatch', __('Upload chunk position does not match the expected file position.', 'olama-media-library'));
            }
        }

        return true;
    }

    private function next_start_from_range($range_header)
    {
        if (preg_match('/bytes=0-(\d+)/i', (string) $range_header, $matches)) {
            return absint($matches[1]) + 1;
        }
        return 0;
    }

    private function validate_uploaded_chunk_file($chunk_file)
    {
        $error = isset($chunk_file['error']) ? absint($chunk_file['error']) : UPLOAD_ERR_NO_FILE;
        if ($error !== UPLOAD_ERR_OK) {
            return new WP_Error('chunk_upload_error', sprintf(__('Uploaded chunk failed before processing. PHP upload error: %d', 'olama-media-library'), $error));
        }

        if (empty($chunk_file['tmp_name']) || !is_uploaded_file($chunk_file['tmp_name'])) {
            return new WP_Error('missing_chunk_file', __('Uploaded chunk file is missing.', 'olama-media-library'));
        }

        if (empty($chunk_file['size']) || absint($chunk_file['size']) < 1) {
            return new WP_Error('empty_chunk_file', __('Uploaded chunk is empty.', 'olama-media-library'));
        }

        return true;
    }

    private function send_chunk_error($message, $chunk_index, $retryable, $job_id = 0, $asset_id = 0, $event_type = 'upload_error', $context = array())
    {
        $context['failed_chunk_index'] = absint($chunk_index);
        if ($asset_id) {
            $this->db->update_asset($asset_id, array('upload_status' => 'failed'));
        }
        if ($job_id) {
            $this->db->update_job($job_id, array('status' => 'failed', 'error_message' => sanitize_textarea_field($message)));
        }
        $this->logger->log($event_type, $message, $context, $job_id, $asset_id);
        wp_send_json_error(array(
            'failed_chunk_index' => absint($chunk_index),
            'retryable' => (bool) $retryable,
            'message' => $message,
        ));
    }

    private function is_retryable_upload_error($message)
    {
        $message = strtolower((string) $message);
        $non_retryable = array('invalid', 'missing lesson', 'file is too large', 'only mp4', 'metadata was lost', 'session was lost');
        foreach ($non_retryable as $needle) {
            if (strpos($message, $needle) !== false) {
                return false;
            }
        }
        return true;
    }

    private function validate_video($filename, $size)
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if ($ext !== 'mp4') {
            return new WP_Error('invalid_file_type', __('Only MP4 video files are allowed in Phase 1.', 'olama-media-library'));
        }
        $settings = $this->get_settings();
        $max = max(1, absint($settings['max_file_size'] ?? 2048)) * 1024 * 1024;
        if ($size > $max) {
            return new WP_Error('file_too_large', sprintf(__('File is too large. Max size allowed: %s MB', 'olama-media-library'), absint($settings['max_file_size'] ?? 2048)));
        }
        return true;
    }

    private function get_settings()
    {
        if ($this->settings_cache === null) {
            $this->settings_cache = get_option('academy_media_library_settings', array());
        }
        return $this->settings_cache;
    }

    private function elapsed_ms($start)
    {
        return round((microtime(true) - $start) * 1000, 2);
    }

    private function timing_context($timings, $chunk_index = null, $total_chunks = null)
    {
        $keys = array(
            'request_validation_ms',
            'temp_file_validation_ms',
            'meta_load_or_recovery_ms',
            'db_job_lookup_ms',
            'drive_chunk_upload_ms',
            'db_progress_update_ms',
            'finalization_ms',
            'drive_metadata_fetch_ms',
            'drive_permission_update_ms',
            'asset_update_ms',
            'total_handler_ms',
            'chunk_size_bytes',
            'memory_before_mb',
            'memory_after_mb',
            'memory_peak_mb',
            'transfer_method',
            'http_status',
        );

        $context = array();
        foreach ($keys as $key) {
            if ($key === 'transfer_method') {
                $context[$key] = isset($timings[$key]) ? sanitize_key($timings[$key]) : 'unknown';
            } elseif ($key === 'chunk_size_bytes' || $key === 'http_status') {
                $context[$key] = isset($timings[$key]) ? absint($timings[$key]) : 0;
            } else {
                $context[$key] = isset($timings[$key]) ? (float) $timings[$key] : 0;
            }
        }

        if ($chunk_index !== null) {
            $context['chunk_index'] = absint($chunk_index);
        }
        if ($total_chunks !== null) {
            $context['total_chunks'] = absint($total_chunks);
        }

        return $context;
    }

    private function memory_mb($bytes)
    {
        return round($bytes / 1048576, 2);
    }

    private function verify_upload_request($capability = 'upload')
    {
        if (!is_user_logged_in()) {
            $this->send_upload_error('auth_session_expired', 'auth_session_check', __('انتهت جلسة الدخول. يرجى تسجيل الدخول من جديد ثم إعادة المحاولة.', 'olama-media-library'), 'WordPress login session expired.', false, array('http_status' => 403));
        }

        $nonce = $_REQUEST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'olama_media_library_nonce') && !wp_verify_nonce($nonce, 'olama_admin_nonce')) {
            $this->send_upload_error('nonce_expired', 'nonce_check', __('انتهت صلاحية جلسة الرفع. يرجى تحديث الصفحة ثم المحاولة مرة أخرى.', 'olama-media-library'), 'Upload nonce expired or invalid.', false, array('http_status' => 403));
        }

        $allowed = $capability === 'manage' ? Olama_Media_Admin::can_manage() : Olama_Media_Admin::can_upload();
        if (!$allowed) {
            $this->send_upload_error('capability_denied', 'capability_check', __('لا تملك صلاحية رفع الفيديوهات.', 'olama-media-library'), 'Current user does not have the required media capability.', false, array('http_status' => 403));
        }
    }

    private function send_wp_upload_error($wp_error, $stage, $extra = array())
    {
        $data = is_wp_error($wp_error) ? (array) $wp_error->get_error_data() : array();
        $code = is_wp_error($wp_error) ? $wp_error->get_error_code() : 'upload_failed';
        $message = is_wp_error($wp_error) ? $wp_error->get_error_message() : __('Upload failed.', 'olama-media-library');
        $mapped = $this->map_upload_error($code, $data, $message);

        $this->send_upload_error($mapped['error_code'], $stage, $mapped['message_ar'], $message, $mapped['retryable'], array_merge($data, $extra));
    }

    private function send_upload_error($error_code, $stage, $message_ar, $message_en, $retryable, $extra = array())
    {
        $payload = array(
            'error_code' => sanitize_key($error_code),
            'message_ar' => sanitize_text_field($message_ar),
            'message_en' => sanitize_text_field($message_en),
            'retryable' => (bool) $retryable,
            'stage' => sanitize_key($stage),
            'http_status' => absint($extra['http_status'] ?? 200),
            'drive_http_status' => absint($extra['drive_http_status'] ?? $extra['http_status'] ?? 0),
            'failed_chunk_index' => isset($extra['failed_chunk_index']) ? absint($extra['failed_chunk_index']) : null,
            'job_uuid' => sanitize_key($extra['job_uuid'] ?? ''),
            'asset_id' => absint($extra['asset_id'] ?? 0),
        );

        $context = array(
            'error_code' => $payload['error_code'],
            'stage' => $payload['stage'],
            'retryable' => $payload['retryable'],
            'failed_chunk_index' => $payload['failed_chunk_index'],
            'job_uuid' => $payload['job_uuid'],
            'asset_id' => $payload['asset_id'],
            'http_status' => $payload['http_status'],
            'drive_http_status' => $payload['drive_http_status'],
            'transfer_method' => sanitize_key($extra['transfer_method'] ?? ''),
            'content_range' => sanitize_text_field($extra['content_range'] ?? ''),
            'chunk_size_bytes' => absint($extra['chunk_size_bytes'] ?? 0),
            'memory_peak_mb' => isset($extra['memory_peak_mb']) ? (float) $extra['memory_peak_mb'] : $this->memory_mb(memory_get_peak_usage(true)),
            'message_en' => $payload['message_en'],
        );

        foreach (array('curl_errno', 'safe_curl_error', 'response_summary', 'google_accepted_range', 'expected_range', 'range_match', 'start_byte', 'end_byte', 'total_size') as $key) {
            if (isset($extra[$key])) {
                $context[$key] = is_bool($extra[$key]) ? $extra[$key] : sanitize_text_field((string) $extra[$key]);
            }
        }

        $event_type = sanitize_key($extra['event_type'] ?? ($payload['stage'] === 'google_auth_check' || strpos($payload['error_code'], 'google_') === 0 ? 'google_auth_error' : 'upload_error'));
        if (!empty($extra['mark_failed'])) {
            if ($payload['asset_id']) {
                $this->db->update_asset($payload['asset_id'], array('upload_status' => 'failed'));
            }
            if (!empty($extra['job_id'])) {
                $this->db->update_job(absint($extra['job_id']), array('status' => 'failed', 'error_message' => $payload['message_en']));
            }
        }
        $this->logger->log($event_type, $payload['message_en'], $context, absint($extra['job_id'] ?? 0), $payload['asset_id']);

        wp_send_json_error($payload, $payload['http_status'] ?: 200);
    }

    private function map_upload_error($code, $data, $message)
    {
        $retryable = isset($data['retryable']) ? (bool) $data['retryable'] : $this->is_retryable_upload_error($message);
        $message_ar = __('تعذر رفع هذا الجزء مؤقتا. سيتم إعادة المحاولة تلقائيا.', 'olama-media-library');
        $error_code = sanitize_key($code ?: 'upload_failed');

        if (in_array($error_code, array('google_auth_failed'), true) || in_array(absint($data['drive_http_status'] ?? 0), array(401, 403), true)) {
            return array(
                'error_code' => 'google_auth_failed',
                'message_ar' => __('انتهت صلاحية اتصال Google Drive. يرجى إعادة المصادقة مع Google ثم المحاولة مرة أخرى.', 'olama-media-library'),
                'retryable' => false,
            );
        }

        if ($error_code === 'google_bad_upload_request') {
            return array(
                'error_code' => 'google_bad_upload_request',
                'message_ar' => __('رفض Google Drive طلب الرفع. يرجى إعادة المصادقة أو بدء رفع جديد.', 'olama-media-library'),
                'retryable' => $retryable,
            );
        }

        if ($error_code === 'google_range_mismatch') {
            return array(
                'error_code' => 'google_range_mismatch',
                'message_ar' => __('حدث اختلاف في موضع الرفع لدى Google Drive. سيتم إعادة محاولة هذا الجزء.', 'olama-media-library'),
                'retryable' => true,
            );
        }

        if (strpos($error_code, 'invalid') === 0 || in_array($error_code, array('chunk_index_out_of_range', 'chunk_start_mismatch'), true)) {
            return array(
                'error_code' => in_array($error_code, array('chunk_start_mismatch', 'chunk_index_out_of_range'), true) ? 'invalid_content_range' : 'invalid_file',
                'message_ar' => __('بيانات الرفع غير صحيحة. يرجى إعادة المحاولة.', 'olama-media-library'),
                'retryable' => false,
            );
        }

        return array(
            'error_code' => $error_code,
            'message_ar' => $message_ar,
            'retryable' => $retryable,
        );
    }

    private function register_v2_uploaded_file($asset, $drive_file_id, $metadata, $download_link)
    {
        if (!$asset || empty($asset->lesson_id)) { return; }
        $repo = new Olama_Media_V2_Repository();
        $normalizer = new Olama_Media_Normalizer();
        $filename = sanitize_text_field($metadata['name'] ?? $asset->original_filename ?? $asset->title);
        $row_id = $repo->upsert_drive_file(array(
            'drive_file_id'=>sanitize_text_field($drive_file_id),'drive_folder_id'=>sanitize_text_field($asset->drive_folder_id ?? ''),
            'drive_parent_ids'=>wp_json_encode(array_map('sanitize_text_field', (array) ($metadata['parents'] ?? array()))),
            'filename'=>$filename,'normalized_filename'=>$normalizer->normalize_filename($filename),
            'extension'=>$normalizer->extract_extension($filename),'mime_type'=>sanitize_text_field($metadata['mime_type'] ?? $asset->mime_type),
            'file_size'=>absint($metadata['size'] ?? $asset->file_size),'web_view_link'=>esc_url_raw($metadata['web_view_link'] ?? ''),
            'web_content_link'=>esc_url_raw($download_link),'thumbnail_link'=>esc_url_raw($metadata['thumbnail_link'] ?? ''),
            'video_metadata'=>wp_json_encode($metadata['video_media_metadata'] ?? array()),'scan_status'=>'active','last_seen_at'=>current_time('mysql'),
        ));
        $part = $normalizer->extract_part_number($filename);
        $repo->upsert_lesson_video_link(array(
            'drive_file_id'=>sanitize_text_field($drive_file_id),'drive_file_row_id'=>$row_id,
            'academic_year_id'=>absint($asset->academic_year_id),'semester_id'=>absint($asset->semester_id),
            'grade_id'=>absint($asset->grade_id),'subject_id'=>absint($asset->subject_id),'unit_id'=>absint($asset->unit_id),
            'lesson_id'=>absint($asset->lesson_id),'part_number'=>$part,'sequence_order'=>$part ?: $repo->next_sequence_order($asset->lesson_id),
            'match_method'=>'manual_upload','match_confidence'=>100,'approval_status'=>sanitize_key($asset->approval_status ?: 'pending'),
            'link_status'=>'active','linked_by'=>get_current_user_id(),
        ));
    }

    private function verify_nonce()
    {
        $nonce = $_REQUEST['nonce'] ?? '';
        if (!wp_verify_nonce($nonce, 'olama_media_library_nonce') && !wp_verify_nonce($nonce, 'olama_admin_nonce')) {
            wp_send_json_error(__('Security check failed.', 'olama-media-library'), 403);
        }
    }

    private function require_manage()
    {
        if (!Olama_Media_Admin::can_manage()) {
            wp_send_json_error(__('Unauthorized.', 'olama-media-library'), 403);
        }
    }

    private function require_upload()
    {
        if (!Olama_Media_Admin::can_upload()) {
            wp_send_json_error(__('Unauthorized.', 'olama-media-library'), 403);
        }
    }

    private function require_approve()
    {
        if (!Olama_Media_Admin::can_approve()) {
            wp_send_json_error(__('Unauthorized.', 'olama-media-library'), 403);
        }
    }

    private function recursive_rmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->recursive_rmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
