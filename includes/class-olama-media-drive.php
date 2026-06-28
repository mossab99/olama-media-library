<?php
if (!defined('ABSPATH')) {
    exit;
}

class Olama_Media_Drive
{
    private $service;
    private $root_folder_id;
    private $last_error_code = '';
    private $last_error_message = '';

    public function __construct()
    {
        $settings = get_option('academy_media_library_settings', array());
        $this->root_folder_id = trim($settings['root_folder_id'] ?? '', " \t\n\r\0\x0B.");
        $client = $this->get_client();

        if (!$client) {
            return;
        }

        $access_token = $settings['access_token'] ?? null;
        if ($access_token) {
            $client->setAccessToken($access_token);
        }

        if ($client->isAccessTokenExpired() && !empty($settings['refresh_token'])) {
            $new_token = $client->fetchAccessTokenWithRefreshToken($settings['refresh_token']);
            if ($new_token && empty($new_token['error'])) {
                if (empty($new_token['refresh_token'])) {
                    $new_token['refresh_token'] = $settings['refresh_token'];
                }
                $settings['access_token'] = $new_token;
                update_option('academy_media_library_settings', $settings);
            } else {
                $this->last_error_code = 'google_token_refresh_failed';
                $this->last_error_message = sanitize_text_field($new_token['error_description'] ?? $new_token['error'] ?? __('Google token refresh failed.', 'olama-media-library'));
            }
        }

        if (class_exists('Google_Service_Drive')) {
            $this->service = new Google_Service_Drive($client);
        }
    }

    public function get_client()
    {
        $settings = get_option('academy_media_library_settings', array());
        if (empty($settings['client_id']) || empty($settings['client_secret']) || !class_exists('Google_Client') || !class_exists('Google_Service_Drive')) {
            return null;
        }

        $client = new Google_Client();
        $client->setClientId($settings['client_id']);
        $client->setClientSecret($settings['client_secret']);
        $client->addScope(Google_Service_Drive::DRIVE);
        $client->addScope('https://www.googleapis.com/auth/userinfo.email');
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setRedirectUri(admin_url('admin.php?page=academy-media-library'));
        return $client;
    }

    public function get_auth_health()
    {
        $settings = get_option('academy_media_library_settings', array());
        $health = array(
            'is_configured' => !empty($settings['client_id']) && !empty($settings['client_secret']) && !empty($settings['root_folder_id']) && class_exists('Google_Client') && class_exists('Google_Service_Drive'),
            'has_access_token' => !empty($settings['access_token']),
            'has_refresh_token' => !empty($settings['refresh_token']),
            'token_expired' => true,
            'can_refresh' => false,
            'last_error_code' => $this->last_error_code,
            'last_error_message' => $this->last_error_message,
        );

        $client = $this->get_client();
        if (!$client) {
            $health['last_error_code'] = $health['is_configured'] ? $health['last_error_code'] : 'google_auth_missing';
            $health['last_error_message'] = $health['last_error_message'] ?: __('Google Drive is not fully configured.', 'olama-media-library');
            return $health;
        }

        if (!empty($settings['access_token'])) {
            $client->setAccessToken($settings['access_token']);
            $health['token_expired'] = (bool) $client->isAccessTokenExpired();
        }

        $health['can_refresh'] = $health['has_refresh_token'] && (!$health['token_expired'] || empty($this->last_error_code));
        if (!$health['has_refresh_token']) {
            $health['last_error_code'] = 'google_refresh_token_missing';
            $health['last_error_message'] = __('Google Drive refresh token is missing.', 'olama-media-library');
        } elseif ($this->last_error_code === 'google_token_refresh_failed') {
            $health['can_refresh'] = false;
        }

        return $health;
    }

    public function authenticate($code)
    {
        $client = $this->get_client();
        if (!$client) {
            return new WP_Error('missing_credentials', __('Client ID or Client Secret is missing.', 'olama-media-library'));
        }

        $token = $client->fetchAccessTokenWithAuthCode(sanitize_text_field(wp_unslash($code)));
        if (!empty($token['error'])) {
            return new WP_Error('drive_auth_error', $token['error_description'] ?? $token['error']);
        }

        $settings = get_option('academy_media_library_settings', array());
        $settings['access_token'] = $token;
        if (!empty($token['refresh_token'])) {
            $settings['refresh_token'] = $token['refresh_token'];
        }

        if (empty($settings['refresh_token'])) {
            return new WP_Error('missing_refresh_token', __('Google did not return a refresh token. Revoke app access and authenticate again.', 'olama-media-library'));
        }

        update_option('academy_media_library_settings', $settings);
        return true;
    }

    public function get_auth_url()
    {
        $client = $this->get_client();
        return $client ? $client->createAuthUrl() : '#';
    }

    public function get_authenticated_email()
    {
        if (!$this->service || !class_exists('Google_Service_Oauth2')) {
            return '';
        }

        try {
            $oauth2 = new Google_Service_Oauth2($this->service->getClient());
            $userinfo = $oauth2->userinfo->get();
            return $userinfo->email;
        } catch (Exception $e) {
            return '';
        }
    }

    public function test_connection()
    {
        if (!$this->service) {
            return new WP_Error('drive_not_ready', __('Google Drive is not configured or authenticated.', 'olama-media-library'));
        }
        if (!$this->root_folder_id) {
            return new WP_Error('missing_root', __('Root Folder ID is missing.', 'olama-media-library'));
        }

        try {
            $folder = $this->service->files->get($this->root_folder_id, array('fields' => 'id,name', 'supportsAllDrives' => true));
            return array('id' => $folder->id, 'name' => $folder->name);
        } catch (Exception $e) {
            return new WP_Error('drive_error', $this->extract_error($e));
        }
    }

    public function get_or_create_nested_folder($path_parts)
    {
        if (!$this->service) {
            return new WP_Error('drive_not_ready', __('Google Drive service is not initialized.', 'olama-media-library'));
        }
        if (!$this->root_folder_id) {
            return new WP_Error('missing_root', __('Root Folder ID is missing.', 'olama-media-library'));
        }

        $parent_id = $this->root_folder_id;
        foreach ($path_parts as $folder_name) {
            $folder_name = trim(wp_strip_all_tags((string) $folder_name));
            if ($folder_name === '') {
                continue;
            }
            $parent_id = $this->get_or_create_single_folder($folder_name, $parent_id);
            if (is_wp_error($parent_id)) {
                return $parent_id;
            }
        }
        return $parent_id;
    }

    public function init_resumable_upload($filename, $mime_type, $folder_id, $total_size)
    {
        if (!$this->service) {
            return new WP_Error('drive_not_ready', __('Google Drive service is not initialized.', 'olama-media-library'));
        }

        try {
            $file_metadata = new Google_Service_Drive_DriveFile(array(
                'name' => sanitize_file_name($filename),
                'parents' => array($folder_id),
            ));
            $client = $this->service->getClient();
            $client->setDefer(true);
            $request = $this->service->files->create($file_metadata, array(
                'fields' => 'id,webViewLink,webContentLink,thumbnailLink,videoMediaMetadata',
                'supportsAllDrives' => true,
            ));
            $media = new Google_Http_MediaFileUpload($client, $request, $mime_type, null, true, 10485760);
            $media->setFileSize(absint($total_size));
            $resume_uri = $media->getResumeUri();
            $client->setDefer(false);
            return $resume_uri;
        } catch (Exception $e) {
            return new WP_Error('drive_upload_init_error', $this->extract_error($e));
        }
    }

    public function create_direct_resumable_upload_session($metadata, $mime_type, $file_size)
    {
        if (!$this->service) {
            return new WP_Error('drive_not_ready', __('Google Drive service is not initialized.', 'olama-media-library'));
        }

        try {
            $file_metadata = new Google_Service_Drive_DriveFile(array(
                'name' => sanitize_file_name($metadata['name'] ?? ''),
                'parents' => array(sanitize_text_field($metadata['parent_id'] ?? '')),
                'mimeType' => sanitize_text_field($mime_type),
            ));
            $client = $this->service->getClient();
            $client->setDefer(true);
            $request = $this->service->files->create($file_metadata, array(
                'fields' => 'id,name,mimeType,size,parents,webViewLink,webContentLink,thumbnailLink,videoMediaMetadata',
                'supportsAllDrives' => true,
            ));
            $media = new Google_Http_MediaFileUpload($client, $request, $mime_type, null, true, 10485760);
            $media->setFileSize(absint($file_size));
            $resume_uri = $media->getResumeUri();
            $client->setDefer(false);

            if (!$resume_uri) {
                return new WP_Error('drive_upload_init_error', __('Google Drive did not return a resumable upload URL.', 'olama-media-library'));
            }

            return array(
                'upload_url' => esc_url_raw($resume_uri),
                'upload_url_hash' => hash('sha256', $resume_uri),
                'expires_hint_seconds' => 3600,
            );
        } catch (Exception $e) {
            return new WP_Error('drive_upload_init_error', $this->extract_error($e));
        } finally {
            if (isset($client)) {
                $client->setDefer(false);
            }
        }
    }

    public function create_metadata_file($name, $parent_id, $mime_type)
    {
        if (!$this->service) {
            return new WP_Error('drive_not_ready', __('Google Drive service is not initialized.', 'olama-media-library'));
        }

        try {
            $file = $this->service->files->create(new Google_Service_Drive_DriveFile(array(
                'name' => sanitize_file_name($name),
                'parents' => array(sanitize_text_field($parent_id)),
                'mimeType' => sanitize_text_field($mime_type),
            )), array(
                'fields' => 'id,name,mimeType,parents,webViewLink,webContentLink,thumbnailLink',
                'supportsAllDrives' => true,
            ));

            return array(
                'id' => sanitize_text_field($file->id),
                'name' => sanitize_text_field($file->name),
                'mime_type' => sanitize_text_field($file->mimeType),
                'parents' => (array) $file->parents,
                'web_view_link' => esc_url_raw($file->webViewLink),
                'web_content_link' => esc_url_raw($file->webContentLink),
                'thumbnail_link' => esc_url_raw($file->thumbnailLink),
            );
        } catch (Exception $e) {
            return new WP_Error('drive_metadata_file_error', $this->extract_error($e));
        }
    }

    public function create_direct_resumable_update_session($file_id, $mime_type, $file_size)
    {
        if (!$this->service) {
            return new WP_Error('drive_not_ready', __('Google Drive service is not initialized.', 'olama-media-library'));
        }

        $file_id = sanitize_text_field($file_id);
        if (!$file_id) {
            return new WP_Error('missing_drive_file_id', __('Drive file ID is missing.', 'olama-media-library'));
        }

        try {
            $client = $this->service->getClient();
            $client->setDefer(true);
            $request = $this->service->files->update($file_id, new Google_Service_Drive_DriveFile(), array(
                'fields' => 'id,name,mimeType,size,parents,webViewLink,webContentLink,thumbnailLink,videoMediaMetadata',
                'supportsAllDrives' => true,
            ));
            $media = new Google_Http_MediaFileUpload($client, $request, $mime_type, null, true, 10485760);
            $media->setFileSize(absint($file_size));
            $resume_uri = $media->getResumeUri();
            $client->setDefer(false);

            if (!$resume_uri) {
                return new WP_Error('drive_upload_init_error', __('Google Drive did not return a resumable upload URL.', 'olama-media-library'));
            }

            return array(
                'upload_url' => esc_url_raw($resume_uri),
                'upload_url_hash' => hash('sha256', $resume_uri),
                'file_id' => $file_id,
                'expires_hint_seconds' => 3600,
            );
        } catch (Exception $e) {
            return new WP_Error('drive_upload_init_error', $this->extract_error($e));
        } finally {
            if (isset($client)) {
                $client->setDefer(false);
            }
        }
    }

    public function put_upload_chunk($resume_uri, $chunk_data, $start_byte, $total_size)
    {
        $end_byte = absint($start_byte) + strlen($chunk_data) - 1;
        $response = wp_remote_request(esc_url_raw($resume_uri), array(
            'method' => 'PUT',
            'headers' => array(
                'Content-Range' => 'bytes ' . absint($start_byte) . '-' . $end_byte . '/' . absint($total_size),
                'Content-Length' => (string) strlen($chunk_data),
                'Content-Type' => 'application/octet-stream',
            ),
            'body' => $chunk_data,
            'timeout' => 120,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 308) {
            return array(
                'status' => 'incomplete',
                'transfer_method' => 'fallback_wp_http',
                'http_status' => $code,
            );
        }
        if ($code === 200 || $code === 201) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
            return array(
                'status' => 'completed',
                'file_id' => sanitize_text_field($data['id'] ?? ''),
                'web_view_link' => esc_url_raw($data['webViewLink'] ?? ''),
                'web_content_link' => esc_url_raw($data['webContentLink'] ?? ''),
                'thumbnail_link' => esc_url_raw($data['thumbnailLink'] ?? ''),
                'video_media_metadata' => $data['videoMediaMetadata'] ?? array(),
                'transfer_method' => 'fallback_wp_http',
                'http_status' => $code,
            );
        }

        return new WP_Error('drive_chunk_error', sprintf('Google Drive API error: %d %s', $code, wp_remote_retrieve_body($response)));
    }

    public function put_upload_chunk_streamed($resume_uri, $chunk_path, $start_byte, $end_byte, $total_size, $mime_type = 'application/octet-stream')
    {
        $chunk_size = ($end_byte - absint($start_byte)) + 1;
        $content_range = 'bytes ' . absint($start_byte) . '-' . absint($end_byte) . '/' . absint($total_size);
        if ($chunk_size < 1) {
            return $this->drive_upload_error('invalid_chunk_size', __('Chunk size is invalid.', 'olama-media-library'), array(
                'chunk_size_bytes' => $chunk_size,
                'start_byte' => absint($start_byte),
                'end_byte' => absint($end_byte),
                'total_size' => absint($total_size),
                'content_range' => $content_range,
            ));
        }

        if (!function_exists('curl_init')) {
            $chunk_data = file_get_contents($chunk_path);
            if ($chunk_data === false) {
                return $this->drive_upload_error('chunk_read_failed', __('Unable to read uploaded chunk for fallback transfer.', 'olama-media-library'), array(
                    'transfer_method' => 'fallback_wp_http',
                    'chunk_size_bytes' => $chunk_size,
                    'start_byte' => absint($start_byte),
                    'end_byte' => absint($end_byte),
                    'total_size' => absint($total_size),
                    'content_range' => $content_range,
                ));
            }

            $result = $this->put_upload_chunk($resume_uri, $chunk_data, $start_byte, $total_size);
            unset($chunk_data);

            if (is_wp_error($result)) {
                return $result;
            }

            $result['transfer_method'] = 'fallback_wp_http';
            $result['http_status'] = isset($result['http_status']) ? $result['http_status'] : 0;
            return $result;
        }

        $handle = fopen($chunk_path, 'rb');
        if (!$handle) {
            return $this->drive_upload_error('chunk_open_failed', __('Unable to open uploaded chunk for streaming.', 'olama-media-library'), array(
                'transfer_method' => 'streamed_curl',
                'chunk_size_bytes' => $chunk_size,
                'start_byte' => absint($start_byte),
                'end_byte' => absint($end_byte),
                'total_size' => absint($total_size),
                'content_range' => $content_range,
            ));
        }

        $curl = curl_init(esc_url_raw($resume_uri));
        if (!$curl) {
            fclose($handle);
            return $this->drive_upload_error('curl_init_failed', __('Unable to initialize cURL for Drive upload.', 'olama-media-library'), array(
                'transfer_method' => 'streamed_curl',
                'chunk_size_bytes' => $chunk_size,
                'start_byte' => absint($start_byte),
                'end_byte' => absint($end_byte),
                'total_size' => absint($total_size),
                'content_range' => $content_range,
            ));
        }

        curl_setopt_array($curl, array(
            CURLOPT_UPLOAD => true,
            CURLOPT_INFILE => $handle,
            CURLOPT_INFILESIZE => $chunk_size,
            CURLOPT_CUSTOMREQUEST => 'PUT',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: ' . sanitize_text_field($mime_type),
                'Content-Length: ' . $chunk_size,
                'Content-Range: ' . $content_range,
            ),
        ));

        $raw_response = curl_exec($curl);
        $curl_error = curl_error($curl);
        $curl_errno = curl_errno($curl);
        $http_status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $header_size = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);

        curl_close($curl);
        fclose($handle);

        if ($raw_response === false) {
            return $this->drive_upload_error('drive_streaming_curl_error', sprintf('cURL error %d: %s', $curl_errno, sanitize_text_field($curl_error)), array(
                'drive_http_status' => $http_status,
                'curl_errno' => $curl_errno,
                'safe_curl_error' => sanitize_text_field($curl_error),
                'transfer_method' => 'streamed_curl',
                'chunk_size_bytes' => $chunk_size,
                'start_byte' => absint($start_byte),
                'end_byte' => absint($end_byte),
                'total_size' => absint($total_size),
                'content_range' => $content_range,
                'retryable' => true,
            ));
        }

        $headers_raw = substr($raw_response, 0, $header_size);
        $body = substr($raw_response, $header_size);
        $headers = $this->parse_response_headers($headers_raw);
        $accepted_range = $headers['range'] ?? '';
        $expected_range = 'bytes=0-' . absint($end_byte);
        $range_match = $accepted_range === '' || $accepted_range === $expected_range;

        if ($http_status === 308) {
            if (!$range_match) {
                return $this->drive_upload_error('google_range_mismatch', __('Google Drive accepted a different byte range than expected.', 'olama-media-library'), array(
                    'drive_http_status' => $http_status,
                    'curl_errno' => 0,
                    'safe_curl_error' => '',
                    'transfer_method' => 'streamed_curl',
                    'chunk_size_bytes' => $chunk_size,
                    'start_byte' => absint($start_byte),
                    'end_byte' => absint($end_byte),
                    'total_size' => absint($total_size),
                    'content_range' => $content_range,
                    'google_accepted_range' => $accepted_range,
                    'expected_range' => $expected_range,
                    'range_match' => false,
                    'response_summary' => $this->safe_body_summary($body),
                    'retryable' => true,
                ));
            }

            return array(
                'status' => 'incomplete',
                'transfer_method' => 'streamed_curl',
                'http_status' => $http_status,
                'google_accepted_range' => $accepted_range,
                'expected_range' => $expected_range,
                'range_match' => true,
            );
        }

        if ($http_status === 200 || $http_status === 201) {
            $data = json_decode($body, true);
            return array(
                'status' => 'completed',
                'file_id' => sanitize_text_field($data['id'] ?? ''),
                'web_view_link' => esc_url_raw($data['webViewLink'] ?? ''),
                'web_content_link' => esc_url_raw($data['webContentLink'] ?? ''),
                'thumbnail_link' => esc_url_raw($data['thumbnailLink'] ?? ''),
                'video_media_metadata' => $data['videoMediaMetadata'] ?? array(),
                'transfer_method' => 'streamed_curl',
                'http_status' => $http_status,
                'google_accepted_range' => $accepted_range,
                'expected_range' => $expected_range,
                'range_match' => true,
            );
        }

        $error_code = 'drive_api_error';
        $retryable = false;
        if ($http_status === 401 || $http_status === 403) {
            $error_code = 'google_auth_failed';
        } elseif ($http_status === 400) {
            $summary = strtolower($this->safe_body_summary($body));
            $error_code = 'google_bad_upload_request';
            $retryable = strpos($summary, 'range') !== false || strpos($summary, 'resume') !== false || strpos($summary, 'offset') !== false;
        }

        return $this->drive_upload_error($error_code, sprintf('Google Drive API error: %d', $http_status), array(
            'drive_http_status' => $http_status,
            'curl_errno' => $curl_errno,
            'safe_curl_error' => sanitize_text_field($curl_error),
            'transfer_method' => 'streamed_curl',
            'chunk_size_bytes' => $chunk_size,
            'start_byte' => absint($start_byte),
            'end_byte' => absint($end_byte),
            'total_size' => absint($total_size),
            'content_range' => $content_range,
            'google_accepted_range' => $accepted_range,
            'expected_range' => $expected_range,
            'range_match' => $range_match,
            'response_summary' => $this->safe_body_summary($body),
            'retryable' => $retryable,
        ));
    }

    public function get_file_metadata($file_id)
    {
        if (!$this->service || !$file_id) {
            return new WP_Error('drive_not_ready', __('Google Drive service is not initialized.', 'olama-media-library'));
        }

        try {
            $file = $this->service->files->get($file_id, array(
                'fields' => 'id,name,mimeType,size,parents,trashed,webViewLink,webContentLink,thumbnailLink,videoMediaMetadata',
                'supportsAllDrives' => true,
            ));
            return array(
                'id' => $file->id,
                'name' => $file->name,
                'mime_type' => $file->mimeType,
                'size' => $file->size,
                'parents' => $file->parents,
                'trashed' => (bool) $file->trashed,
                'web_view_link' => $file->webViewLink,
                'web_content_link' => $file->webContentLink,
                'thumbnail_link' => $file->thumbnailLink,
                'video_media_metadata' => $file->videoMediaMetadata,
            );
        } catch (Exception $e) {
            return new WP_Error('drive_metadata_error', $this->extract_error($e));
        }
    }

    /**
     * Find an existing folder path without creating any folders.
     *
     * @param string[] $path_parts Folder names relative to the configured root.
     * @return string|WP_Error Empty string means that the path does not exist.
     */
    public function find_nested_folder($path_parts)
    {
        if (!$this->service) {
            return new WP_Error('drive_not_ready', __('Google Drive service is not initialized.', 'olama-media-library'));
        }
        if (!$this->root_folder_id) {
            return new WP_Error('missing_root', __('Root Folder ID is missing.', 'olama-media-library'));
        }

        $parent_id = $this->root_folder_id;
        foreach ((array) $path_parts as $folder_name) {
            $folder_name = trim(wp_strip_all_tags((string) $folder_name));
            if ($folder_name === '') {
                continue;
            }

            try {
                $query = "name = '" . str_replace("'", "\\'", $folder_name) . "' and '" . str_replace("'", "\\'", $parent_id) . "' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
                $response = $this->service->files->listFiles(array(
                    'q' => $query,
                    'fields' => 'files(id,name)',
                    'pageSize' => 2,
                    'supportsAllDrives' => true,
                    'includeItemsFromAllDrives' => true,
                ));
                $folders = method_exists($response, 'getFiles') ? $response->getFiles() : ($response->files ?? array());
                if (count((array) $folders) !== 1) {
                    return '';
                }
                $parent_id = $folders[0]->id;
            } catch (Exception $e) {
                return new WP_Error('drive_folder_lookup_error', $this->extract_error($e));
            }
        }

        return $parent_id;
    }

    /** Return all non-trashed video files directly inside a folder. */
    public function list_video_files($folder_id)
    {
        if (!$this->service || !$folder_id) {
            return new WP_Error('drive_not_ready', __('Google Drive service is not initialized.', 'olama-media-library'));
        }

        $files = array();
        $page_token = null;
        try {
            do {
                $args = array(
                    'q' => "'" . str_replace("'", "\\'", $folder_id) . "' in parents and trashed = false and mimeType contains 'video/'",
                    'fields' => 'nextPageToken,files(id,name,mimeType,size,parents,webViewLink,webContentLink,thumbnailLink,videoMediaMetadata)',
                    'pageSize' => 1000,
                    'supportsAllDrives' => true,
                    'includeItemsFromAllDrives' => true,
                );
                if ($page_token) {
                    $args['pageToken'] = $page_token;
                }
                $response = $this->service->files->listFiles($args);
                $page_files = method_exists($response, 'getFiles') ? $response->getFiles() : ($response->files ?? array());
                foreach ((array) $page_files as $file) {
                    $files[] = array(
                        'id' => sanitize_text_field($file->id),
                        'name' => sanitize_text_field($file->name),
                        'mime_type' => sanitize_text_field($file->mimeType),
                        'size' => absint($file->size),
                        'parents' => array_map('sanitize_text_field', (array) $file->parents),
                        'web_view_link' => esc_url_raw($file->webViewLink),
                        'web_content_link' => esc_url_raw($file->webContentLink),
                        'thumbnail_link' => esc_url_raw($file->thumbnailLink),
                        'video_media_metadata' => $file->videoMediaMetadata,
                    );
                }
                $page_token = method_exists($response, 'getNextPageToken') ? $response->getNextPageToken() : ($response->nextPageToken ?? null);
            } while ($page_token);
        } catch (Exception $e) {
            return new WP_Error('drive_file_list_error', $this->extract_error($e));
        }

        return $files;
    }

    public function ensure_file_permissions($file_id)
    {
        if (!$this->service || !$file_id) {
            return new WP_Error('drive_not_ready', __('Google Drive service is not initialized.', 'olama-media-library'));
        }

        try {
            $permissions = $this->service->permissions->listPermissions($file_id, array(
                'fields' => 'permissions(id,type,role)',
                'supportsAllDrives' => true,
            ));
            $items = method_exists($permissions, 'getPermissions') ? $permissions->getPermissions() : ($permissions->permissions ?? array());
            foreach ((array) $items as $permission) {
                if ($permission->type === 'anyone' && $permission->role === 'reader') {
                    return array('success' => true, 'already_exists' => true);
                }
            }

            $permission = new Google_Service_Drive_Permission(array('type' => 'anyone', 'role' => 'reader'));
            $this->service->permissions->create($file_id, $permission, array('supportsAllDrives' => true));
            return array('success' => true, 'already_exists' => false);
        } catch (Exception $e) {
            $message = $this->extract_error($e);
            if (stripos($message, 'already') !== false || stripos($message, 'duplicate') !== false) {
                return array('success' => true, 'already_exists' => true);
            }
            return new WP_Error('drive_permission_error', $message);
        }
    }

    public function extract_id_from_url($url)
    {
        if ($url && preg_match('/[-\w]{25,}/', $url, $matches)) {
            return $matches[0];
        }
        return '';
    }

    private function get_or_create_single_folder($folder_name, $parent_id)
    {
        try {
            $query = "name = '" . str_replace("'", "\\'", $folder_name) . "' and '" . str_replace("'", "\\'", $parent_id) . "' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
            $response = $this->service->files->listFiles(array(
                'q' => $query,
                'fields' => 'files(id,name)',
                'pageSize' => 1,
                'supportsAllDrives' => true,
                'includeItemsFromAllDrives' => true,
            ));
            if (!empty($response->files)) {
                return $response->files[0]->id;
            }

            $folder = $this->service->files->create(new Google_Service_Drive_DriveFile(array(
                'name' => $folder_name,
                'mimeType' => 'application/vnd.google-apps.folder',
                'parents' => array($parent_id),
            )), array('fields' => 'id', 'supportsAllDrives' => true));
            return $folder->id;
        } catch (Exception $e) {
            return new WP_Error('drive_folder_error', $this->extract_error($e));
        }
    }

    private function extract_error($exception)
    {
        $data = json_decode($exception->getMessage(), true);
        return sanitize_text_field($data['error']['message'] ?? $exception->getMessage());
    }

    private function drive_upload_error($code, $message, $data = array())
    {
        $safe_data = array();
        foreach ($data as $key => $value) {
            $key = sanitize_key($key);
            if (is_bool($value)) {
                $safe_data[$key] = $value;
            } elseif (is_numeric($value)) {
                $safe_data[$key] = $value + 0;
            } else {
                $safe_data[$key] = sanitize_text_field((string) $value);
            }
        }

        return new WP_Error($code, sanitize_text_field($message), $safe_data);
    }

    private function parse_response_headers($headers_raw)
    {
        $headers = array();
        foreach (preg_split('/\r\n|\n|\r/', (string) $headers_raw) as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            list($key, $value) = explode(':', $line, 2);
            $headers[strtolower(trim($key))] = trim($value);
        }
        return $headers;
    }

    private function safe_body_summary($body)
    {
        return sanitize_textarea_field(substr((string) $body, 0, 300));
    }
}
