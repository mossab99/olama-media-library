<?php
if (!defined('ABSPATH')) {
    exit;
}

class Olama_Media_Drive
{
    private $service;
    private $root_folder_id;

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
        if ($chunk_size < 1) {
            return new WP_Error('invalid_chunk_size', __('Chunk size is invalid.', 'olama-media-library'));
        }

        if (!function_exists('curl_init')) {
            $chunk_data = file_get_contents($chunk_path);
            if ($chunk_data === false) {
                return new WP_Error('chunk_read_failed', __('Unable to read uploaded chunk for fallback transfer.', 'olama-media-library'));
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
            return new WP_Error('chunk_open_failed', __('Unable to open uploaded chunk for streaming.', 'olama-media-library'));
        }

        $curl = curl_init(esc_url_raw($resume_uri));
        if (!$curl) {
            fclose($handle);
            return new WP_Error('curl_init_failed', __('Unable to initialize cURL for Drive upload.', 'olama-media-library'));
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
                'Content-Range: bytes ' . absint($start_byte) . '-' . absint($end_byte) . '/' . absint($total_size),
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
            return new WP_Error('drive_streaming_curl_error', sprintf('cURL error %d: %s', $curl_errno, sanitize_text_field($curl_error)));
        }

        $body = substr($raw_response, $header_size);

        if ($http_status === 308) {
            return array(
                'status' => 'incomplete',
                'transfer_method' => 'streamed_curl',
                'http_status' => $http_status,
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
            );
        }

        return new WP_Error('drive_streaming_chunk_error', sprintf('Google Drive API error: %d %s', $http_status, sanitize_textarea_field($body)));
    }

    public function get_file_metadata($file_id)
    {
        if (!$this->service || !$file_id) {
            return new WP_Error('drive_not_ready', __('Google Drive service is not initialized.', 'olama-media-library'));
        }

        try {
            $file = $this->service->files->get($file_id, array(
                'fields' => 'id,name,mimeType,size,trashed,webViewLink,webContentLink,thumbnailLink,videoMediaMetadata',
                'supportsAllDrives' => true,
            ));
            return array(
                'id' => $file->id,
                'name' => $file->name,
                'mime_type' => $file->mimeType,
                'size' => $file->size,
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
}
