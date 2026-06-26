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
        add_action('wp_ajax_academy_load_media_curriculum', array($this, 'load_curriculum'));
        add_action('wp_ajax_olama_media_start_upload_job', array($this, 'start_upload_job'));
        add_action('wp_ajax_olama_media_refresh_upload_nonce', array($this, 'refresh_upload_nonce'));
        add_action('wp_ajax_academy_upload_media_video_chunk', array($this, 'upload_chunk'));
        add_action('wp_ajax_olama_media_finalize_upload', array($this, 'finalize_upload'));
        add_action('wp_ajax_olama_media_check_preview_status', array($this, 'check_preview_status'));
        add_action('wp_ajax_nopriv_olama_media_refresh_upload_nonce', array($this, 'refresh_upload_nonce'));
        add_action('wp_ajax_nopriv_academy_upload_media_video_chunk', array($this, 'upload_chunk'));
        add_action('wp_ajax_nopriv_olama_media_finalize_upload', array($this, 'finalize_upload'));
        add_action('wp_ajax_nopriv_olama_media_check_preview_status', array($this, 'check_preview_status'));
        add_action('wp_ajax_olama_media_save_notes', array($this, 'save_notes'));
        add_action('wp_ajax_academy_update_media_status', array($this, 'update_media_status'));
        add_action('wp_ajax_olama_media_delete_asset', array($this, 'delete_asset'));
        add_action('wp_ajax_academy_save_drive_settings', array($this, 'save_settings'));
        add_action('wp_ajax_academy_test_drive_connection', array($this, 'test_connection'));
        add_action('wp_ajax_olama_media_get_upload_log', array($this, 'get_upload_log'));
        add_action('wp_ajax_academy_get_upload_log', array($this, 'get_upload_log'));
        add_action('wp_ajax_olama_media_migrate_legacy', array($this, 'migrate_legacy'));
    }

    public function get_subjects()
    {
        $this->verify_nonce();
        $this->require_manage();
        $grade_id = absint($_REQUEST['grade_id'] ?? 0);
        wp_send_json_success($this->curriculum->get_subjects($grade_id));
    }

    public function load_curriculum()
    {
        $this->verify_nonce();
        $this->require_manage();

        $year_id = absint($_REQUEST['academic_year_id'] ?? 0);
        $semester_id = absint($_REQUEST['semester_id'] ?? 0);
        $grade_id = absint($_REQUEST['grade_id'] ?? 0);
        $subject_id = absint($_REQUEST['subject_id'] ?? 0);

        if (!$semester_id || !$grade_id || !$subject_id) {
            wp_send_json_error(__('Missing curriculum filters.', 'olama-media-library'));
        }

        $data = $this->db->get_curriculum_with_assets($year_id, $semester_id, $grade_id, $subject_id);
        if (is_wp_error($data)) {
            wp_send_json_error($data->get_error_message());
        }

        wp_send_json_success($data);
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
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized.', 'olama-media-library'));
        }

        $current = get_option('academy_media_library_settings', array());
        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        $client_secret = sanitize_text_field($_POST['client_secret'] ?? '');
        $settings = array(
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'root_folder_id' => trim(sanitize_text_field($_POST['root_folder_id'] ?? ''), " \t\n\r\0\x0B."),
            'max_file_size' => max(1, absint($_POST['max_file_size'] ?? 2048)),
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
        if (!current_user_can('manage_options')) {
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
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('Unauthorized.', 'olama-media-library'));
        }
        $dry_run = !empty($_POST['dry_run']);
        wp_send_json_success($this->db->migrate_legacy($dry_run));
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
