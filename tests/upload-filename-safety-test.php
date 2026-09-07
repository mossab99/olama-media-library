<?php
define('ABSPATH', __DIR__ . '/');
function __($message, $domain = null) { return $message; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_file_name($value) { return trim((string) $value); }
function is_wp_error($value) { return $value instanceof WP_Error; }
function add_action() {}
class WP_Error {
    private $code; private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_code() { return $this->code; }
}
function assert_upload_name($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

class UploadFilenameDB {
    public function get_curriculum_with_assets($year, $semester, $grade, $subject) { return array(
        (object) array('id'=>50, 'unit_name'=>'الضرب والقسمة', 'lessons'=>array(
            (object) array('id'=>60, 'lesson_number'=>'3', 'lesson_title'=>'ضرب الأعداد'),
        )),
    ); }
}

$root = dirname(__DIR__);
$source = file_get_contents($root . '/includes/class-olama-media-ajax.php');
assert_upload_name(strpos($source, 'get_curriculum_with_assets($academic_year_id, $semester_id, $grade_id, $subject_id)') !== false, 'Filename generation must load the canonical curriculum lesson.');
assert_upload_name(strpos($source, "sanitize_text_field(\$_POST['lesson_name']") === false, 'The browser lesson name must never be used to name a Drive file.');
assert_upload_name(strpos($source, "sanitize_text_field(\$_POST['lesson_number']") === false, 'The browser lesson number must never be used to name a Drive file.');

require_once $root . '/includes/class-olama-media-ajax.php';
$ajax = new Olama_Media_Ajax(new UploadFilenameDB(), new stdClass(), new stdClass());
$method = new ReflectionMethod($ajax, 'prepare_upload_meta');
$method->setAccessible(true);
$_POST = array(
    'academic_year_id'=>1, 'semester_id'=>2, 'grade_id'=>3, 'subject_id'=>4,
    'unit_id'=>50, 'lesson_id'=>60, 'unit_name'=>'وحدة مزورة',
    'lesson_name'=>'عنوان مزور', 'lesson_number'=>'99', 'part_number'=>2,
);
$meta = $method->invoke($ajax, 'original.mp4', 100, 1);
assert_upload_name(!is_wp_error($meta), 'A valid curriculum lesson must produce upload metadata.');
assert_upload_name($meta['target_filename'] === 'Lesson 3 Part 2 ضرب الأعداد.mp4', 'Drive filename must use the canonical lesson number, part, and title.');
assert_upload_name($meta['unit_name'] === 'الضرب والقسمة', 'Upload routing must use the canonical curriculum unit name.');
assert_upload_name(strpos($meta['target_filename'], 'مزور') === false, 'Spoofed browser labels must not reach the Drive filename.');

$_POST['lesson_id'] = 999;
$invalid = $method->invoke($ajax, 'original.mp4', 100, 1);
assert_upload_name(is_wp_error($invalid) && $invalid->get_error_code() === 'upload_curriculum_lesson_mismatch', 'A lesson outside the selected unit must be rejected before upload.');
echo "Upload filename safety tests passed.\n";
