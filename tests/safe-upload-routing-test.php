<?php
define('ABSPATH', __DIR__ . '/');

function __($message, $domain = null) { return $message; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function wp_json_encode($value) { return json_encode($value); }
function get_option($key, $default = array()) { return $GLOBALS['safe_upload_settings'] ?? $default; }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error {
    private $code; private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
}
function assert_safe_upload($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

$root = dirname(__DIR__);
$ajax_source = file_get_contents($root . '/includes/class-olama-media-ajax.php');
$plugin_source = file_get_contents($root . '/olama-media-library.php');
$resolver_source = file_get_contents($root . '/includes/class-olama-media-safe-upload-folder-resolver.php');
$view_source = file_get_contents($root . '/views/media-library-page.php');
assert_safe_upload(strpos($ajax_source, 'get_or_create_nested_folder') === false, 'Upload routing must never create a folder.');
assert_safe_upload(strpos($ajax_source, 'Olama_Media_Safe_Upload_Folder_Resolver') !== false, 'Every upload must use the reviewed folder resolver.');
assert_safe_upload(strpos($resolver_source, 'list_folder_children_page') !== false, 'The target unit must be revalidated live before upload.');
assert_safe_upload(strpos($resolver_source, 'get_confirmed_mapping_for_scope') !== false, 'Upload routing must require a confirmed subject mapping.');
assert_safe_upload(strpos($plugin_source, "OLAMA_MEDIA_DRIVE_FOLDER_CREATION_ENABLED', false") !== false, 'General folder creation must remain disabled.');
assert_safe_upload(strpos($plugin_source, "OLAMA_MEDIA_DRIVE_SYNC_ENABLED', false") !== false, 'Legacy synchronization must remain disabled.');
assert_safe_upload(strpos($view_source, 'الرفع الآمن مفعّل') !== false, 'The upload screen must explain the reviewed-folder restriction.');

require_once $root . '/includes/class-olama-media-normalizer.php';
require_once $root . '/includes/class-olama-media-safe-upload-folder-resolver.php';

$GLOBALS['safe_upload_settings'] = array('root_folder_id'=>'root', 'root_scope_level'=>'unknown', 'root_scope_id'=>0);
$root_hash = hash('sha256', wp_json_encode($GLOBALS['safe_upload_settings']));
class SafeUploadInventory {
    public $duplicate = false;
    public function get_latest_completed_run() { global $root_hash; return (object) array('id'=>3, 'root_config_hash'=>$root_hash); }
    public function get_all_observations($run_id) {
        $items = array((object) array('item_type'=>'folder','parent_drive_folder_id'=>'subject-id','drive_item_id'=>'unit-id','item_name'=>'الأعداد جمعها وطرحها'));
        if ($this->duplicate) { $items[] = (object) array('item_type'=>'folder','parent_drive_folder_id'=>'subject-id','drive_item_id'=>'duplicate-id','item_name'=>'الأعداد جمعها وطرحها'); }
        return $items;
    }
}
class SafeUploadMapping {
    public function get_confirmed_mapping_for_scope($scope) { global $root_hash; return (object) array('drive_folder_id'=>'subject-id','root_config_hash'=>$root_hash); }
}
class SafeUploadDrive {
    public function list_folder_children_page($parent, $token, $size) { return array('next_page_token'=>'','items'=>array(
        array('id'=>'unit-id','name'=>'الأعداد جمعها وطرحها','mime_type'=>'application/vnd.google-apps.folder','trashed'=>false),
    )); }
}
class SafeUploadCurriculumDB {
    public function get_curriculum_with_assets($year, $semester, $grade, $subject) { return array(
        (object) array('id'=>5, 'unit_name'=>'الأعداد جمعها وطرحها', 'lessons'=>array((object) array('id'=>6))),
    ); }
}
$inventory = new SafeUploadInventory();
$resolver = new Olama_Media_Safe_Upload_Folder_Resolver($inventory, new SafeUploadMapping(), new Olama_Media_Normalizer(), new SafeUploadCurriculumDB());
$meta = array('academic_year_id'=>1,'semester_id'=>2,'grade_id'=>3,'subject_id'=>4,'unit_id'=>5,'lesson_id'=>6,'unit_name'=>'قيمة لا يثق بها الخادم');
assert_safe_upload($resolver->resolve(new SafeUploadDrive(), $meta) === 'unit-id', 'The reviewed exact unit Drive ID must be returned.');
$wrong_lesson = $meta; $wrong_lesson['lesson_id'] = 999;
$mismatch = $resolver->resolve(new SafeUploadDrive(), $wrong_lesson);
assert_safe_upload(is_wp_error($mismatch) && $mismatch->get_error_code() === 'safe_upload_curriculum_mismatch', 'A lesson outside the selected curriculum unit must block upload.');
$inventory->duplicate = true;
$duplicate = $resolver->resolve(new SafeUploadDrive(), $meta);
assert_safe_upload(is_wp_error($duplicate) && $duplicate->get_error_code() === 'safe_upload_unit_ambiguous', 'Duplicate unit folders must block upload.');

echo "Safe upload routing tests passed.\n";
