<?php
// Run with -d upload_max_filesize=512M -d post_max_size=512M to reproduce the reported host limit.
define('ABSPATH', __DIR__ . '/');
define('OLAMA_MEDIA_LIBRARY_PATH', dirname(__DIR__) . '/');
define('OLAMA_MEDIA_LIBRARY_URL', 'https://example.test/plugins/olama-media-library/');
define('OLAMA_MEDIA_LIBRARY_VERSION', 'test');

function __($message, $domain = null) { return $message; }
function absint($value) { return abs((int) $value); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
function current_user_can($capability) { return $capability === 'olama_media_upload_video'; }
function get_option($key, $default = array()) { return $GLOBALS['upload_size_settings'] ?? $default; }
function wp_enqueue_style() {}
function wp_enqueue_script() {}
function admin_url($path, $scheme = null) { return '/wp-admin/' . $path; }
function wp_create_nonce($action) { return 'test-nonce'; }
function size_format($bytes) { return ($bytes / 1024 / 1024) . ' MB'; }
function wp_localize_script($handle, $name, $data) { $GLOBALS['upload_size_config'] = $data; }
class WP_Error {
    private $code;
    public function __construct($code, $message) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class Olama_Media_Drive {
    public function get_auth_health() {
        return array('is_configured' => true, 'has_refresh_token' => true, 'can_refresh' => true);
    }
}
function assert_upload_size($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-feature-flags.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-admin.php';
require_once OLAMA_MEDIA_LIBRARY_PATH . 'includes/class-olama-media-ajax.php';
$admin = (new ReflectionClass('Olama_Media_Admin'))->newInstanceWithoutConstructor();
$validate = new ReflectionMethod('Olama_Media_Ajax', 'validate_video');
$validate->setAccessible(true);
$server_limit_method = new ReflectionMethod($admin, 'server_upload_limit');
$server_limit_method->setAccessible(true);
$server_limit = $server_limit_method->invoke($admin);
$mb = 1024 * 1024;

foreach (array('direct_google', 'wordpress_streamed', 'auto') as $mode) {
    foreach (array(2000, 100) as $limit_mb) {
        $GLOBALS['upload_size_settings'] = array('max_file_size' => $limit_mb, 'olama_media_upload_transport_mode' => $mode);
        $admin->enqueue_assets('toplevel_page_academy-media-library');
        $config = $GLOBALS['upload_size_config'];
        assert_upload_size($config['maxFileSize'] === $limit_mb * $mb, "{$mode}: browser must use the configured whole-video limit.");
        assert_upload_size($config['maxFileSizeHuman'] === size_format($limit_mb * $mb), "{$mode}: error text must show the configured limit.");
        assert_upload_size($config['chunkSize'] <= 5 * $mb && $config['chunkSize'] < $server_limit, "{$mode}: WordPress chunks must remain below PHP request limits.");

        $ajax = (new ReflectionClass('Olama_Media_Ajax'))->newInstanceWithoutConstructor();
        $limit = $config['maxFileSize'];
        assert_upload_size($validate->invoke($ajax, 'video.mp4', $limit - 1) === true, "{$mode}: video below the configured limit must pass server validation.");
        assert_upload_size($validate->invoke($ajax, 'video.mp4', $limit) === true, "{$mode}: video exactly at the configured limit must pass.");
        $oversize = $validate->invoke($ajax, 'video.mp4', $limit + 1);
        assert_upload_size($oversize instanceof WP_Error && $oversize->get_error_code() === 'file_too_large', "{$mode}: video above the configured limit must fail.");
        if ($limit_mb === 2000) {
            assert_upload_size(600 * $mb <= $config['maxFileSize'] && $validate->invoke($ajax, 'video.mp4', 600 * $mb) === true, "{$mode}: a 600 MB video must pass with a 2000 MB setting.");
        }
    }
}
echo "Upload size limit tests passed.\n";
