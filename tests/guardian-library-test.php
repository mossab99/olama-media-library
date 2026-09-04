<?php

define('ABSPATH', __DIR__);

class WP_Error {
}

function __($text) { return $text; }
function absint($value) { return abs((int) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_hex_color($value) { return preg_match('/^#[0-9a-f]{6}$/i', (string) $value) ? $value : null; }
function esc_url_raw($value) { return filter_var($value, FILTER_SANITIZE_URL); }
function is_wp_error($value) { return $value instanceof WP_Error; }

class Olama_School_Subject {
    public static function get_subject($id) {
        return (object) array('id' => $id, 'subject_name' => 'العلوم', 'color_code' => '#16835b');
    }
}

class Olama_Media_DB {
    public function get_curriculum_with_assets($year_id, $semester_id, $grade_id, $subject_id) {
        return array((object) array(
            'id' => 3,
            'unit_number' => '1',
            'unit_name' => 'الإنسان',
            'lessons' => array(
                (object) array('id' => 10, 'lesson_number' => '1', 'lesson_title' => 'الجهاز التنفسي', 'approval_status' => 'approved', 'upload_status' => 'uploaded_to_drive', 'preview_status' => 'ready', 'drive_file_url' => 'https://legacy.example/10', 'media_record_id' => 40),
                (object) array('id' => 11, 'lesson_number' => '2', 'lesson_title' => 'الجهاز الهضمي', 'approval_status' => 'approved', 'upload_status' => 'uploaded_to_drive', 'preview_status' => 'ready', 'drive_file_url' => 'https://legacy.example/11', 'media_record_id' => 41),
                (object) array('id' => 12, 'lesson_number' => '3', 'lesson_title' => 'مسودة', 'approval_status' => 'pending', 'upload_status' => 'uploaded_to_drive', 'preview_status' => 'ready', 'drive_file_url' => 'https://legacy.example/12', 'media_record_id' => 42),
            ),
        ));
    }
}

class Olama_Media_V2_Repository {
    public function get_links_for_lesson($lesson_id) {
        if (10 === (int) $lesson_id) {
            return array(
                (object) array('link_id' => 70, 'approval_status' => 'approved', 'part_number' => 1, 'web_view_link' => 'https://video.example/approved'),
                (object) array('link_id' => 71, 'approval_status' => 'pending', 'part_number' => 2, 'web_view_link' => 'https://video.example/pending'),
            );
        }
        return array();
    }
}

require dirname(__DIR__) . '/includes/class-olama-media-guardian-library.php';

function assert_true($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$library = Olama_Media_Guardian_Library::for_curriculum(1, 2, 3, array(4));
assert_true(2 === $library['video_count'], 'Only one approved v2 link and one approved legacy video should be returned.');
assert_true(2 === count($library['subjects'][0]['units'][0]['lessons']), 'Lessons without approved videos must be omitted.');
assert_true('https://video.example/approved' === $library['subjects'][0]['units'][0]['lessons'][0]['videos'][0]['url'], 'Approved v2 video should be preferred.');
assert_true('https://legacy.example/11' === $library['subjects'][0]['units'][0]['lessons'][1]['videos'][0]['url'], 'Approved ready legacy video should remain available.');
assert_true(!isset($library['subjects'][0]['units'][0]['lessons'][0]['videos'][0]['drive_file_id']), 'Drive identifiers must not be exposed.');

echo "Guardian video library tests passed.\n";
