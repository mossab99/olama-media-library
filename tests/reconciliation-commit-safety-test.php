<?php
define('ABSPATH', __DIR__ . '/');
function absint($value) { return abs((int) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }

require_once dirname(__DIR__) . '/includes/class-olama-media-reconciliation-commit.php';

function assert_commit_safety($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

$root = dirname(__DIR__);
$source = file_get_contents($root . '/includes/class-olama-media-reconciliation-commit.php');
$ajax = file_get_contents($root . '/includes/class-olama-media-ajax.php');
$db = file_get_contents($root . '/includes/class-olama-media-db.php');
$plugin = file_get_contents($root . '/olama-media-library.php');

assert_commit_safety(strpos($source, "const CONFIRMATION_PHRASE = 'COMMIT REVIEWED LINKS';") !== false, 'Commit must require an exact explicit phrase.');
assert_commit_safety(strpos($source, "query('START TRANSACTION')") !== false, 'Commit must start a database transaction.');
assert_commit_safety(strpos($source, "query('COMMIT')") !== false, 'Commit must explicitly commit the database transaction.');
assert_commit_safety(strpos($source, "query('ROLLBACK')") !== false, 'Commit failures must roll back.');
assert_commit_safety(strpos($source, 'new Olama_Media_Drive(') === false, 'Commit service must not instantiate the Drive client.');
foreach (array('files->create', 'files->update', 'files->delete', 'get_or_create_nested_folder') as $mutation) {
    assert_commit_safety(strpos($source, $mutation) === false, "Commit service must not contain Drive mutation {$mutation}.");
}
assert_commit_safety(strpos($ajax, 'wp_ajax_olama_media_reconciliation_commit') !== false, 'Authenticated commit endpoint must be registered.');
assert_commit_safety(strpos($ajax, 'wp_ajax_nopriv_olama_media_reconciliation_commit') === false, 'Commit endpoint must never be public.');
foreach (array('commit_status', 'committed_link_id', 'commit_run_id', 'committed_at') as $column) {
    assert_commit_safety(strpos($db, $column) !== false, "Commit audit schema must include {$column}.");
}
assert_commit_safety(strpos($plugin, "OLAMA_MEDIA_LIBRARY_VERSION', '2.5.2'") !== false, 'Plugin version must be 2.5.2.');
assert_commit_safety(strpos($plugin, "OLAMA_MEDIA_LIBRARY_DB_VERSION', '2.5.0'") !== false, 'Database version must trigger the 2.5.0 additive migration.');
assert_commit_safety(strpos($source, 'existing_link_same_target_not_approved') !== false, 'Diagnostics must distinguish a matching legacy target from a true target conflict.');
assert_commit_safety(strpos($source, 'existing_link_target_conflict') !== false, 'Diagnostics must expose true link target conflicts separately.');

$service = new Olama_Media_Reconciliation_Commit(new stdClass(), new stdClass());
$mapping = (object) array('academic_year_id'=>2026, 'semester_id'=>1, 'grade_id'=>1, 'subject_id'=>7);
$item = (object) array('selected_unit_id'=>40, 'selected_lesson_id'=>99);
$same = (object) array('academic_year_id'=>2026, 'semester_id'=>1, 'grade_id'=>1, 'subject_id'=>7, 'unit_id'=>40, 'lesson_id'=>99, 'link_status'=>'active', 'approval_status'=>'approved');
$wrong_grade = clone $same; $wrong_grade->grade_id = 5;
$wrong_lesson = clone $same; $wrong_lesson->lesson_id = 100;
$pending = clone $same; $pending->approval_status = 'pending';
$method = new ReflectionMethod($service, 'same_committed_link');
$method->setAccessible(true);
assert_commit_safety($method->invoke($service, $same, $item, $mapping) === true, 'Exact approved active link must be idempotently reusable.');
assert_commit_safety($method->invoke($service, $wrong_grade, $item, $mapping) === false, 'Cross-grade existing link must be a conflict.');
assert_commit_safety($method->invoke($service, $wrong_lesson, $item, $mapping) === false, 'Different-lesson existing link must be a conflict.');
assert_commit_safety($method->invoke($service, $pending, $item, $mapping) === false, 'Pending existing link must not be silently approved.');

$replaceable_method = new ReflectionMethod($service, 'is_replaceable_pending_generated_link');
$replaceable_method->setAccessible(true);
$pending_generated = clone $pending; $pending_generated->match_method = 'filename_lesson_number';
$pending_manual = clone $pending; $pending_manual->match_method = 'manual';
$pending_legacy = clone $pending; $pending_legacy->match_method = 'legacy_import';
$inactive_generated = clone $pending_generated; $inactive_generated->link_status = 'inactive';
$approved_generated = clone $pending_generated; $approved_generated->approval_status = 'approved';
assert_commit_safety($replaceable_method->invoke($service, $pending_generated) === true, 'A pending active generated link may be superseded only by reviewed staging.');
assert_commit_safety($replaceable_method->invoke($service, $pending_manual) === false, 'A manual link must never be automatically replaced.');
assert_commit_safety($replaceable_method->invoke($service, $pending_legacy) === false, 'A legacy import must never be automatically replaced.');
assert_commit_safety($replaceable_method->invoke($service, $inactive_generated) === false, 'An inactive generated link must not be revived automatically.');
assert_commit_safety($replaceable_method->invoke($service, $approved_generated) === false, 'An approved generated link must never be automatically replaced.');

$scope_method = new ReflectionMethod($service, 'scope_is_empty_or_same');
$scope_method->setAccessible(true);
$empty_file = (object) array('academic_year_id'=>null, 'semester_id'=>null, 'grade_id'=>null, 'subject_id'=>null, 'unit_id'=>null);
$wrong_scope_file = clone $empty_file; $wrong_scope_file->subject_id = 77;
assert_commit_safety($scope_method->invoke($service, $empty_file, $item, $mapping) === true, 'Unscoped inventoried file may receive the confirmed scope.');
assert_commit_safety($scope_method->invoke($service, $wrong_scope_file, $item, $mapping) === false, 'Existing conflicting file scope must block commit.');

echo "Reconciliation commit safety tests passed.\n";
