<?php
define('OLAMA_MEDIA_REVIEWED_FOLDER_APPLY_ENABLED', true);
require __DIR__ . '/workspace-workflow-test.php';
function wp_verify_nonce($value, $action) { return $value === 'valid'; }
function current_user_can($capability) { return false; }
function wp_send_json_error($message, $status = 200) { throw new RuntimeException($message, $status); }
class Olama_Media_Admin {
    public static function can_access() { return true; }
    public static function can_upload() { return true; }
}

class SubjectCreationDrive extends SafeUploadDrive {
    public $reuse_race = false;
    public function create_reviewed_folder($name, $parent) {
        if ($this->reuse_race) { $this->add('race-folder', $name, $parent); return array('id'=>'race-folder', 'created'=>false); }
        return $this->create_upload_folder($name, $parent);
    }
}
function subject_create_fixture() {
    list($unused, $unused_drive, $mapping, $repo, $db) = workspace_fixture();
    $curriculum = new SafeUploadCurriculum();
    $curriculum->names['subject'] = 'اللغة العربية';
    $drive = new SubjectCreationDrive();
    $service = new Olama_Media_Workspace($mapping, $curriculum, $db, $repo);
    return array($service, $drive, $mapping, $curriculum);
}

list($service, $drive, $mapping) = subject_create_fixture();
$availability = $service->subject_folder_availability($drive, $scope, 'grade');
assert_safe_upload($availability['name'] === 'اللغة العربية' && $availability['can_create'] && !$availability['exists'], 'Offer the real curriculum subject name when missing.');
$created = $service->create_subject_folder($drive, $scope, 'grade', $availability['root_hash']);
assert_safe_upload(!is_wp_error($created) && $created['created'] && count($drive->created) === 1, 'Create exactly one missing subject folder.');
assert_safe_upload($drive->created[0]['name'] === 'اللغة العربية' && $drive->created[0]['parents'] === array('grade'), 'Create the server-owned subject name under the explicitly selected parent.');
assert_safe_upload(!$wpdb->mapping && !$wpdb->locks, 'Creation must not silently change bindings and must release locks.');
$available_after = $service->subject_folder_availability($drive, $scope, 'grade');
assert_safe_upload(!$available_after['can_create'] && $available_after['existing_ids'] === array($created['folder_id']), 'Expose the existing folder for direct selection after creation.');
expect_safe_error($service->create_subject_folder($drive, $scope, 'grade', $availability['root_hash']), 'subject_folder_exists');
assert_safe_upload(count($drive->created) === 1 && !$wpdb->locks, 'A double click or retry cannot create a second folder.');
$inside = $service->subject_folder_availability($drive, $scope, $created['folder_id']);
assert_safe_upload(!$inside['can_create'] && $inside['exists'], 'Do not create another subject folder while already inside that subject.');

// Existing names on later pages, including normalized Arabic spelling, block creation.
foreach (array('اللغة العربية', 'اللغه العربيه') as $name) {
    list($service, $drive, $mapping) = subject_create_fixture();
    $drive->page_size = 1;
    $drive->add('unrelated', 'الرياضيات', 'grade');
    $drive->add('existing-arabic', $name, 'grade');
    $availability = $service->subject_folder_availability($drive, $scope, 'grade');
    assert_safe_upload($availability['exists'] && !$availability['can_create'], 'Detect equivalent existing Arabic names beyond the first page.');
    expect_safe_error($service->create_subject_folder($drive, $scope, 'grade', $mapping->root_config_hash()), 'subject_folder_exists');
    assert_safe_upload(!$drive->created && !$wpdb->locks, 'Never mutate Drive when a same-name folder already exists.');
}

// Subject names are allowed in other grades; duplicate prevention is parent-scoped.
list($service, $drive, $mapping) = subject_create_fixture();
$drive->add('another-grade', 'الصف العاشر', 'semester');
$drive->add('other-arabic', 'اللغة العربية', 'another-grade');
assert_safe_upload($service->subject_folder_availability($drive, $scope, 'grade')['can_create'], 'A subject in a different grade must not block this grade.');
$drive->add('trash-arabic', 'اللغة العربية', 'grade', array('trashed'=>true));
assert_safe_upload(!is_wp_error($service->create_subject_folder($drive, $scope, 'grade', $mapping->root_config_hash())), 'Trashed folders must not block a new active subject folder.');

list($service, $drive, $mapping) = subject_create_fixture();
$drive->fail_listing = 'grade';
assert_safe_upload(is_wp_error($service->create_subject_folder($drive, $scope, 'grade', $mapping->root_config_hash())) && !$drive->created && !$wpdb->locks, 'A listing failure cannot be interpreted as a missing folder.');
list($service, $drive, $mapping) = subject_create_fixture();
$drive->repeat_token = true;
expect_safe_error($service->create_subject_folder($drive, $scope, 'grade', $mapping->root_config_hash()), 'folder_pages_incomplete');
assert_safe_upload(!$drive->created && !$wpdb->locks, 'Incomplete pagination fails closed and releases locks.');

list($service, $drive, $mapping, $curriculum) = subject_create_fixture();
$curriculum->valid = false;
expect_safe_error($service->create_subject_folder($drive, $scope, 'grade', $mapping->root_config_hash()), 'invalid_scope');
assert_safe_upload(!$drive->created && !$wpdb->locks, 'Invalid curriculum cannot create Drive folders.');
list($service, $drive, $mapping) = subject_create_fixture();
$drive->add('outside', 'خارج الجذر', '');
assert_safe_upload(is_wp_error($service->create_subject_folder($drive, $scope, 'outside', $mapping->root_config_hash())) && !$drive->created, 'A forged parent outside the configured root must be rejected.');
expect_safe_error($service->create_subject_folder($drive, $scope, 'grade', 'stale-hash'), 'binding_changed');

list($service, $drive, $mapping) = subject_create_fixture();
$wpdb->busy_at = 2;
expect_safe_error($service->create_subject_folder($drive, $scope, 'grade', $mapping->root_config_hash()), 'folder_creation_busy');
assert_safe_upload(!$drive->created && !$wpdb->locks, 'Concurrent preparation cannot create a duplicate and releases the first lock.');
list($service, $drive, $mapping) = subject_create_fixture();
$drive->reuse_race = true;
expect_safe_error($service->create_subject_folder($drive, $scope, 'grade', $mapping->root_config_hash()), 'subject_folder_exists');
assert_safe_upload(!$drive->created && !$wpdb->locks, 'An exact-name folder appearing at the final Drive recheck is not recreated.');

list($service, $drive, $mapping) = subject_create_fixture();
$hash = $mapping->root_config_hash();
$drive->metadata_hook = function () { $GLOBALS['safe_upload_settings']['root_folder_id'] = 'changed-root'; };
assert_safe_upload(is_wp_error($service->create_subject_folder($drive, $scope, 'grade', $hash)) && !$drive->created && !$wpdb->locks, 'A root change during validation must prevent creation.');

foreach (array('create_subject_folder','dismiss','reassign') as $op) { foreach (array('invalid', 'valid') as $nonce) {
    $_POST = array('nonce'=>$nonce, 'op'=>$op);
    try { $service->handle(); assert_safe_upload(false, 'Unauthorized creation must be rejected.'); }
    catch (RuntimeException $error) { assert_safe_upload($error->getCode() === 403, 'Creation requires a valid nonce and Drive administration capability, even for upload users.'); }
} }

echo "Subject folder creation and duplicate-prevention tests passed.\n";
