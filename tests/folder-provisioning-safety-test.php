<?php
define('ABSPATH', __DIR__ . '/');
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function assert_folder_plan_safety($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

$root = dirname(__DIR__);
$source = file_get_contents($root . '/includes/class-olama-media-folder-provisioning.php');
$ajax = file_get_contents($root . '/includes/class-olama-media-ajax.php');
$db = file_get_contents($root . '/includes/class-olama-media-db.php');
$plugin = file_get_contents($root . '/olama-media-library.php');
$script = file_get_contents($root . '/assets/js/media-library-admin.js');

assert_folder_plan_safety(strpos($source, 'new Olama_Media_Drive(') === false, 'Folder preview must not instantiate the Drive client.');
foreach (array('files->create', 'files->update', 'files->delete', 'get_or_create_nested_folder') as $mutation) {
    assert_folder_plan_safety(strpos($source, $mutation) === false, "Folder preview must not contain Drive mutation {$mutation}.");
}
assert_folder_plan_safety(strpos($source, "'authoritative_state_changed'=>false") !== false, 'Folder preview must report that authoritative state is unchanged.');
assert_folder_plan_safety(strpos($source, "'drive_mutations'=>0") !== false, 'Folder preview must explicitly report zero Drive mutations.');
assert_folder_plan_safety(strpos($source, "\$children[(string) \$parent_drive_id]") !== false, 'Every tree level must inspect only direct children of its resolved parent Drive ID.');
assert_folder_plan_safety(strpos($source, 'duplicate_exact_sibling_folders') !== false, 'Duplicate exact sibling folders must block the plan.');
assert_folder_plan_safety(strpos($source, 'possible_existing_folder_requires_review') !== false, 'Similar sibling folders must require review instead of creating duplicates.');
assert_folder_plan_safety(strpos($source, 'invalid_curriculum_folder_name') !== false, 'Invalid curriculum folder names must block creation.');
assert_folder_plan_safety(strpos($source, 'blocked_by_parent_conflict') !== false, 'Descendants must remain blocked when their parent is ambiguous.');
assert_folder_plan_safety(strpos($source, 'confirmed_subject_mapping_mismatch') !== false, 'A confirmed mapping that disagrees with the exact tree must become a conflict.');
assert_folder_plan_safety(strpos($ajax, 'wp_ajax_olama_media_folder_provisioning_preview') !== false, 'Authenticated folder preview endpoint must be registered.');
assert_folder_plan_safety(strpos($ajax, 'wp_ajax_nopriv_olama_media_folder_provisioning_preview') === false, 'Folder preview endpoint must never be public.');
assert_folder_plan_safety(strpos($ajax, 'drive_folder_provisioning_preview') !== false, 'Folder preview handler must exist.');
foreach (array('olama_drive_folder_plans', 'olama_drive_folder_plan_nodes', 'scope_key', 'node_key', 'node_type', 'parent_node_key', 'subject_mapping_id', 'subject_drive_folder_id', 'plan_hash', 'planned_action', 'parent_drive_folder_id', 'candidate_drive_folder_ids') as $schema) {
    assert_folder_plan_safety(strpos($db, $schema) !== false, "Folder plan schema must include {$schema}.");
}
assert_folder_plan_safety(strpos($plugin, "OLAMA_MEDIA_LIBRARY_VERSION', '2.8.1'") !== false, 'Plugin version must be 2.8.1.');
assert_folder_plan_safety(strpos($plugin, "OLAMA_MEDIA_LIBRARY_DB_VERSION', '2.8.0'") !== false, 'Database version must trigger full-tree plan table creation.');
assert_folder_plan_safety(strpos($ajax, "preg_match('/^subject:(\\d+):(\\d+):(\\d+):(\\d+)$/'") !== false, 'Folder preview must validate the server-issued curriculum scope key.');
assert_folder_plan_safety(strpos($script, 'scope_key: mappingScopeKey') !== false, 'Folder preview must reuse the scope key returned by subject discovery.');

require_once $root . '/includes/class-olama-media-normalizer.php';
require_once $root . '/includes/class-olama-media-folder-provisioning.php';
$service = new Olama_Media_Folder_Provisioning(new stdClass(), new stdClass(), new Olama_Media_Normalizer(), new stdClass());
$similar = new ReflectionMethod($service, 'names_are_similar');
$similar->setAccessible(true);
assert_folder_plan_safety($similar->invoke($service, 'الوحدة الرابعة رسومي المتحركة', 'الوحدة الرابعة رسومي المتحركة') === true, 'Identical normalized names must match.');
assert_folder_plan_safety($similar->invoke($service, 'الوحدة الرابعة رسومي المتحركة', 'الرابعة رسومي المتحركة') === true, 'A contained meaningful name must be treated as a possible existing folder.');
assert_folder_plan_safety($similar->invoke($service, 'الوحدة الرابعة رسومي المتحركة', 'الوحدة الأولى أسرتي') === false, 'Unrelated unit names must not be treated as similar.');
$grade = new ReflectionMethod($service, 'node_name_matches');
$grade->setAccessible(true);
$grade_spec = array('node_type'=>'grade', 'expected_name'=>'خامس أساسي');
assert_folder_plan_safety($grade->invoke($service, $grade_spec, 'الصف الخامس') === true, 'Canonical Arabic grade aliases must reuse the existing grade folder.');

echo "Folder provisioning safety tests passed.\n";
