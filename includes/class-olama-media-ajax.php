<?php
if (!defined('ABSPATH')) {
    exit;
}

class Olama_Media_Ajax
{
    private $db;
    private $curriculum;
    private $logger;

    public function __construct($db, $curriculum, $logger)
    {
        $this->db = $db;
        $this->curriculum = $curriculum;
        $this->logger = $logger;

        add_action('wp_ajax_olama_get_subjects', array($this, 'get_subjects'), 5);
        add_action('wp_ajax_olama_media_get_subjects', array($this, 'get_subjects'));
        add_action('wp_ajax_academy_load_media_curriculum', array($this, 'load_curriculum'));
        add_action('wp_ajax_olama_media_start_upload_job', array($this, 'start_upload_job'));
        add_action('wp_ajax_academy_upload_media_video_chunk', array($this, 'upload_chunk'));
        add_action('wp_ajax_olama_media_finalize_upload', array($this, 'finalize_upload'));
        add_action('wp_ajax_olama_media_check_preview_status', array($this, 'check_preview_status'));
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
        $this->verify_nonce();
        $this->require_upload();

        $job_uuid = sanitize_key($_POST['file_uuid'] ?? wp_generate_uuid4());
        $filename = sanitize_file_name($_POST['filename'] ?? '');
        $total_size = absint($_POST['total_size'] ?? 0);
        $total_chunks = absint($_POST['total_chunks'] ?? 0);
        $validation = $this->validate_video($filename, $total_size);
        if (is_wp_error($validation)) {
            wp_send_json_error($validation->get_error_message());
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

    public function upload_chunk()
    {
        set_time_limit(0);
        ignore_user_abort(true);

        $this->verify_nonce();
        $this->require_upload();

        $file_uuid = sanitize_key($_POST['file_uuid'] ?? '');
        $chunk_index = absint($_POST['chunk_index'] ?? 0);
        $total_chunks = absint($_POST['total_chunks'] ?? 0);
        $filename = sanitize_file_name($_POST['filename'] ?? '');
        $total_size = absint($_POST['total_size'] ?? 0);
        $start_byte = absint($_POST['start_byte'] ?? 0);
        $expected_chunk_size = absint($_POST['chunk_size'] ?? 0);

        if (!$file_uuid || !$total_chunks || empty($_FILES['video_chunk'])) {
            $this->send_chunk_error(__('Invalid upload chunk.', 'olama-media-library'), $chunk_index, false, 0, 0, 'invalid_chunk_request', array('file_uuid' => $file_uuid));
        }

        $validation = $this->validate_video($filename, $total_size);
        if (is_wp_error($validation)) {
            $this->send_chunk_error($validation->get_error_message(), $chunk_index, false, 0, 0, 'invalid_video', array('filename' => $filename));
        }

        $chunk_validation = $this->validate_chunk_request($chunk_index, $total_chunks, $start_byte, $expected_chunk_size);
        if (is_wp_error($chunk_validation)) {
            $this->send_chunk_error($chunk_validation->get_error_message(), $chunk_index, false, 0, 0, 'invalid_chunk_position', array(
                'chunk_index' => $chunk_index,
                'total_chunks' => $total_chunks,
                'start_byte' => $start_byte,
                'expected_chunk_size' => $expected_chunk_size,
            ));
        }

        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit($upload_dir['basedir']) . 'olama-media-temp/' . $file_uuid;
        wp_mkdir_p($temp_dir);
        $meta_path = trailingslashit($temp_dir) . 'meta.json';

        $job_id = 0;
        $asset_id = 0;

        try {
            $drive = new Olama_Media_Drive();

            if (file_exists($meta_path)) {
                $meta = json_decode(file_get_contents($meta_path), true);
                if (!is_array($meta) || empty($meta['resume_uri'])) {
                    throw new Exception(__('Upload metadata is corrupted. Please restart the upload.', 'olama-media-library'));
                }
            } elseif (($existing_job = $this->db->get_job($file_uuid)) && !empty($existing_job->drive_upload_uri)) {
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
            } else {
                if ($chunk_index > 0) {
                    throw new Exception(__('Upload session was lost. Please restart the upload.', 'olama-media-library'));
                }

                $meta = $this->prepare_upload_meta($filename, $total_size, $total_chunks);
                if (is_wp_error($meta)) {
                    throw new Exception($meta->get_error_message());
                }

                $folder_id = $drive->get_or_create_nested_folder($meta['path']);
                if (is_wp_error($folder_id)) {
                    throw new Exception($folder_id->get_error_message());
                }

                $resume_uri = $drive->init_resumable_upload($meta['target_filename'], 'video/mp4', $folder_id, $total_size);
                if (is_wp_error($resume_uri)) {
                    throw new Exception($resume_uri->get_error_message());
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

            $asset_id = absint($meta['asset_id'] ?? 0);
            $job_id = absint($meta['job_id'] ?? 0);
            $chunk_file = $_FILES['video_chunk'];

            $file_validation = $this->validate_uploaded_chunk_file($chunk_file);
            if (is_wp_error($file_validation)) {
                throw new Exception($file_validation->get_error_message());
            }

            $chunk_data = file_get_contents($chunk_file['tmp_name']);
            if ($chunk_data === false || strlen($chunk_data) < 1) {
                throw new Exception(__('Uploaded chunk is empty.', 'olama-media-library'));
            }

            $result = $drive->put_upload_chunk($meta['resume_uri'], $chunk_data, $start_byte, $total_size);
            @unlink($chunk_file['tmp_name']);

            if (is_wp_error($result)) {
                throw new Exception($result->get_error_message());
            }

            $this->db->update_job($job_id, array('uploaded_chunks' => $chunk_index + 1));
            $this->logger->log('chunk_uploaded_to_drive', 'Chunk uploaded to Drive.', array('chunk' => $chunk_index + 1, 'total' => $total_chunks), $job_id, $asset_id);

            if ($result['status'] === 'completed') {
                $permission = $drive->ensure_file_permissions($result['file_id']);
                if (is_wp_error($permission)) {
                    $this->logger->log('permission_update_failed', $permission->get_error_message(), array(), $job_id, $asset_id);
                } elseif (is_array($permission) && !empty($permission['already_exists'])) {
                    $this->logger->log('permission_already_exists', 'Drive file permission already exists.', array(), $job_id, $asset_id);
                } else {
                    $this->logger->log('permission_updated', 'Drive file permission updated.', array(), $job_id, $asset_id);
                }

                $download_link = $result['web_content_link'] ?: 'https://drive.google.com/uc?export=download&id=' . rawurlencode($result['file_id']);
                $this->db->update_asset($asset_id, array(
                    'drive_file_id' => $result['file_id'],
                    'web_view_link' => $result['web_view_link'],
                    'web_content_link' => $download_link,
                    'thumbnail_link' => $result['thumbnail_link'],
                    'upload_status' => 'uploaded_to_drive',
                    'preview_status' => 'processing',
                    'uploaded_at' => current_time('mysql'),
                ));
                $this->db->update_job($job_id, array('status' => 'completed', 'completed_at' => current_time('mysql')));
                $this->logger->log('upload_finalized', 'Upload completed on Google Drive.', array('file_id' => $result['file_id']), $job_id, $asset_id);
                $this->recursive_rmdir($temp_dir);

                wp_send_json_success(array(
                    'completed' => true,
                    'asset_id' => $asset_id,
                    'url' => $result['web_view_link'],
                    'download_url' => $download_link,
                    'preview_status' => 'processing',
                ));
            }

            wp_send_json_success(array('completed' => false, 'asset_id' => $asset_id));
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
            ), $job_id, $asset_id);
            wp_send_json_error(array(
                'failed_chunk_index' => $chunk_index,
                'retryable' => $this->is_retryable_upload_error($e->getMessage()),
                'message' => $e->getMessage(),
            ));
        }
    }

    public function finalize_upload()
    {
        $this->verify_nonce();
        $this->require_upload();
        wp_send_json_success(array('message' => __('Chunk upload finalizes automatically when Google Drive accepts the last chunk.', 'olama-media-library')));
    }

    public function check_preview_status()
    {
        $this->verify_nonce();
        $this->require_manage();

        $asset_id = absint($_POST['asset_id'] ?? 0);
        $asset = $this->db->get_asset($asset_id);
        if (!$asset || !$asset->drive_file_id) {
            wp_send_json_error(__('Media asset not found.', 'olama-media-library'));
        }

        $drive = new Olama_Media_Drive();
        $metadata = $drive->get_file_metadata($asset->drive_file_id);
        if (is_wp_error($metadata)) {
            $this->logger->log('drive_metadata_failed', $metadata->get_error_message(), array(), null, $asset_id);
            wp_send_json_error($metadata->get_error_message());
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
        wp_send_json_success($this->db->get_events(absint($_REQUEST['paged'] ?? 1)));
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
        $settings = get_option('academy_media_library_settings', array());
        $max = max(1, absint($settings['max_file_size'] ?? 2048)) * 1024 * 1024;
        if ($size > $max) {
            return new WP_Error('file_too_large', sprintf(__('File is too large. Max size allowed: %s MB', 'olama-media-library'), absint($settings['max_file_size'] ?? 2048)));
        }
        return true;
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
