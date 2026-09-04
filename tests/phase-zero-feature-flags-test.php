<?php
define('ABSPATH', __DIR__ . '/');
define('OLAMA_MEDIA_DRIVE_UPLOAD_ENABLED', false);
define('OLAMA_MEDIA_DRIVE_SYNC_ENABLED', false);
define('OLAMA_MEDIA_DRIVE_FOLDER_CREATION_ENABLED', false);
define('OLAMA_MEDIA_LEGACY_SYNC_ENABLED', false);

function __($message, $domain = null) { return $message; }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }

class WP_Error
{
    private $code;
    private $message;
    private $data;

    public function __construct($code, $message, $data = array())
    {
        $this->code = $code;
        $this->message = $message;
        $this->data = $data;
    }

    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}

function assert_true($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Assertion failed: {$message}\n");
        exit(1);
    }
}

require_once dirname(__DIR__) . '/includes/class-olama-media-feature-flags.php';

$features = array(
    Olama_Media_Feature_Flags::DRIVE_UPLOAD,
    Olama_Media_Feature_Flags::DRIVE_SYNC,
    Olama_Media_Feature_Flags::DRIVE_FOLDER_CREATION,
    Olama_Media_Feature_Flags::LEGACY_SYNC,
);

foreach ($features as $feature) {
    assert_true(!Olama_Media_Feature_Flags::enabled($feature), "{$feature} must default to disabled during Phase 0.");
    $error = Olama_Media_Feature_Flags::error($feature);
    assert_true($error instanceof WP_Error, "{$feature} must return a blocking WP_Error.");
    assert_true(false === ($error->get_error_data()['retryable'] ?? true), "{$feature} freeze errors must not be retryable.");
}

assert_true(Olama_Media_Feature_Flags::phase_zero_active(), 'Phase 0 must be active when all mutation features are disabled.');

$ajax_source = file_get_contents(dirname(__DIR__) . '/includes/class-olama-media-ajax.php');
assert_true(strpos($ajax_source, 'wp_ajax_nopriv_') === false, 'Unauthenticated AJAX routes must not be registered.');

echo "Phase 0 feature flag tests passed.\n";
