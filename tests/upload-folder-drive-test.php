<?php
define('ABSPATH', __DIR__ . '/');
function __($text, $domain = null) { return $text; }
function sanitize_text_field($value) { return trim((string) $value); }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
class WP_Error {
    private $code;
    public function __construct($code, $message) { $this->code = $code; }
    public function get_error_code() { return $this->code; }
}
class Olama_Media_Feature_Flags {
    const DRIVE_UPLOAD = 'upload'; const DRIVE_FOLDER_CREATION = 'legacy';
    public static $upload = true;
    public static function enabled($feature) { return $feature === self::DRIVE_UPLOAD && self::$upload; }
    public static function error($feature) { return new WP_Error('disabled', 'Disabled'); }
}
class Google_Service_Drive_DriveFile {
    public $data;
    public function __construct($data) { $this->data = $data; }
}
class UploadFolderFiles {
    public $existing = array(); public $created = array(); public $queries = array(); public $failure = false;
    public function listFiles($args) { $this->queries[] = $args; return (object) array('files'=>$this->existing); }
    public function create($file, $args) {
        if ($this->failure) { throw new Exception('Drive permission denied'); }
        $this->created[] = array($file->data, $args);
        return (object) array('id'=>'new-folder');
    }
}
function check_upload_folder_drive($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}
require_once dirname(__DIR__) . '/includes/class-olama-media-drive.php';
$files = new UploadFolderFiles();
$drive = (new ReflectionClass('Olama_Media_Drive'))->newInstanceWithoutConstructor();
$service = new ReflectionProperty($drive, 'service'); $service->setValue($drive, (object) array('files'=>$files));
$result = $drive->create_upload_folder('الرياضيات', 'grade-11');
check_upload_folder_drive($result === array('id'=>'new-folder','created'=>true), 'Dedicated upload creation must work while legacy folder creation is disabled.');
check_upload_folder_drive($files->created[0][0] === array('name'=>'الرياضيات','mimeType'=>'application/vnd.google-apps.folder','parents'=>array('grade-11')), 'The Drive SDK must receive exactly the verified parent and folder name.');
check_upload_folder_drive($files->created[0][1]['supportsAllDrives'] === true, 'Support shared-drive folder creation.');
$files->existing = array((object) array('id'=>'existing'));
$result = $drive->create_upload_folder('الرياضيات', 'grade-11');
check_upload_folder_drive($result === array('id'=>'existing','created'=>false) && count($files->created) === 1, 'A folder appearing before creation must be reused.');
$files->existing[] = (object) array('id'=>'duplicate');
$result = $drive->create_upload_folder('الرياضيات', 'grade-11');
check_upload_folder_drive($result instanceof WP_Error && $result->get_error_code() === 'drive_folder_ambiguous' && count($files->created) === 1, 'Exact duplicate folders must block the low-level creation too.');
$queries = count($files->queries); Olama_Media_Feature_Flags::$upload = false;
$result = $drive->create_upload_folder('الرياضيات', 'grade-11');
check_upload_folder_drive($result instanceof WP_Error && $result->get_error_code() === 'disabled' && count($files->queries) === $queries, 'Disabling uploads must also disable automatic folder preparation.');
Olama_Media_Feature_Flags::$upload = true;
$result = $drive->create_upload_folder('', 'grade-11');
check_upload_folder_drive($result instanceof WP_Error && count($files->queries) === $queries, 'Reject empty folder names before the SDK call.');
$files->existing = array(); $files->failure = true;
$result = $drive->create_upload_folder('الرياضيات', 'grade-11');
check_upload_folder_drive($result instanceof WP_Error && $result->get_error_code() === 'drive_folder_error', 'SDK permission failures must be returned without starting a video upload.');
echo "Upload folder Drive SDK tests passed.\n";
