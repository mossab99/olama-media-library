<?php
define('ABSPATH', __DIR__ . '/');
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function absint($value) { return abs((int) $value); }

require_once dirname(__DIR__) . '/includes/class-olama-media-normalizer.php';
require_once dirname(__DIR__) . '/includes/class-olama-media-matcher.php';

function assert_matcher($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

$normalizer = new Olama_Media_Normalizer();
$matcher = new Olama_Media_Matcher(new stdClass(), $normalizer, new stdClass());
$unit = (object) array('id'=>4, 'unit_name'=>'الوحدة الرابعة رسومي المتحركة');
$context = array('names'=>array('subject'=>'اللغة العربية'), 'units'=>array($unit));
$file = (object) array(
    'drive_file_id'=>'file-1', 'filename'=>'درس 5 تنوين الفتح.mp4',
    'normalized_filename'=>$normalizer->normalize_filename('درس 5 تنوين الفتح.mp4'),
    'drive_path'=>'Olama Videos/2026-2027/First Semester/الصف الأول/اللغة العربية/الوحدة الرابعة رسومي المتحركة/درس 5 تنوين الفتح.mp4',
);
$wrongNumberedLesson = (object) array('id'=>5, 'lesson_number'=>'5', 'lesson_title'=>'حرف الشين');
$correctTitleLesson = (object) array('id'=>7, 'lesson_number'=>'7', 'lesson_title'=>'تنوين الفتح');
$wrong = $matcher->score_file_against_lesson($file, $wrongNumberedLesson, $unit, $context);
$correct = $matcher->score_file_against_lesson($file, $correctTitleLesson, $unit, $context);

assert_matcher($wrong['confidence'] < 90, 'A matching number with a contradictory title must never auto-match.');
assert_matcher($wrong['title_match'] === false, 'Contradictory lesson title must be recorded.');
assert_matcher($correct['confidence'] >= 90, 'A strong unique title match may outrank a stale filename lesson number.');
assert_matcher($correct['confidence'] > $wrong['confidence'], 'Title identity must outrank filename numbering.');
assert_matcher(in_array('lesson_number_mismatch', $correct['evidence'], true), 'Number disagreement must remain visible as evidence.');
assert_matcher(count($matcher->auto_link_high_confidence(array($correct), 0, true)) === 0, 'A title match with a number mismatch must require human review.');

echo "Matcher title-priority tests passed.\n";
