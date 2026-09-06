<?php
define('ABSPATH', __DIR__ . '/');
class WP_Error { private $message; public function __construct($code, $message) { $this->message = $message; } public function get_error_message() { return $this->message; } }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function assert_folder_apply_safety($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

$root = dirname(__DIR__);
$source = file_get_contents($root . '/includes/class-olama-media-folder-provisioning-apply.php');
$drive = file_get_contents($root . '/includes/class-olama-media-drive.php');
$mapping = file_get_contents($root . '/includes/class-olama-media-drive-mapping.php');
$ajax = file_get_contents($root . '/includes/class-olama-media-ajax.php');
$db = file_get_contents($root . '/includes/class-olama-media-db.php');
$view = file_get_contents($root . '/views/media-library-page.php');
$script = file_get_contents($root . '/assets/js/media-library-admin.js');
$plugin = file_get_contents($root . '/olama-media-library.php');

assert_folder_apply_safety(strpos($source, "const CONFIRMATION_PHRASE = 'CREATE REVIEWED FOLDERS';") !== false, 'Folder execution must require an exact explicit phrase.');
assert_folder_apply_safety(strpos($source, "plan_status='applying'") !== false, 'Folder execution must atomically claim a plan.');
assert_folder_apply_safety(strpos($source, "plan_status IN ('ready_for_review','partial_failed')") !== false, 'Only reviewed or safely retryable plans may be claimed.');
assert_folder_apply_safety(strpos($source, 'SELECT GET_LOCK(%s,0)') !== false, 'Concurrent plans for the same curriculum scope must be serialized.');
assert_folder_apply_safety(strpos($source, 'SELECT RELEASE_LOCK(%s)') !== false, 'The curriculum-scope execution lock must be released.');
assert_folder_apply_safety(strpos($source, 'list_folder_children_page') !== false, 'Every node must be revalidated against live direct children.');
assert_folder_apply_safety(strpos($source, 'create_reviewed_folder') !== false, 'Execution must use the dedicated reviewed single-folder mutation.');
assert_folder_apply_safety(strpos($source, 'folder_apply_live_conflict') !== false, 'Live duplicate or similar folders must stop execution.');
assert_folder_apply_safety(strpos($source, 'folder_apply_checkpoint_failed') !== false, 'Every resolved Drive ID must be checkpointed.');
assert_folder_apply_safety(strpos($source, 'folder_plan_integrity_failed') !== false, 'Execution must reject a plan whose staged nodes changed after review.');
assert_folder_apply_safety(strpos($source, 'hash_equals((string) $plan->plan_hash') !== false, 'Plan integrity must use a timing-safe hash comparison.');
assert_folder_apply_safety(strpos($source, 'partial_failed') !== false, 'Partial failures must remain safely retryable.');
foreach (array('files->delete', 'files->update', 'permissions->create', 'get_or_create_nested_folder') as $forbidden) {
    assert_folder_apply_safety(strpos($source, $forbidden) === false, "Folder executor must not contain forbidden mutation {$forbidden}.");
}
assert_folder_apply_safety(strpos($drive, 'OLAMA_MEDIA_REVIEWED_FOLDER_APPLY_ENABLED') !== false, 'The dedicated Drive mutation must have its own server-side gate.');
assert_folder_apply_safety(strpos($mapping, "'confirmation_method'=>'provisioned_plan'") !== false, 'A created subject must be mapped by its returned Drive ID.');
foreach (array('apply_run_uuid', 'applied_created_count', 'apply_status', 'resolved_drive_folder_id', 'resolution_type', 'applied_at') as $column) {
    assert_folder_apply_safety(strpos($db, $column) !== false, "Folder execution audit schema must include {$column}.");
}
foreach (array('wp_ajax_olama_media_folder_provisioning_readiness', 'wp_ajax_olama_media_folder_provisioning_apply') as $endpoint) {
    assert_folder_apply_safety(strpos($ajax, $endpoint) !== false, "Authenticated endpoint {$endpoint} must be registered.");
    assert_folder_apply_safety(strpos($ajax, str_replace('wp_ajax_', 'wp_ajax_nopriv_', $endpoint)) === false, "Endpoint {$endpoint} must never be public.");
}
assert_folder_apply_safety(strpos($view, 'CREATE REVIEWED FOLDERS') !== false, 'The UI must display the exact folder execution phrase.');
assert_folder_apply_safety(strpos($script, "action: 'olama_media_folder_provisioning_readiness'") !== false, 'UI must call folder readiness first.');
assert_folder_apply_safety(strpos($script, "action: 'olama_media_folder_provisioning_apply'") !== false, 'UI must expose the guarded apply endpoint.');
assert_folder_apply_safety(strpos($plugin, "OLAMA_MEDIA_DRIVE_UPLOAD_ENABLED', false") !== false, 'Video uploads must remain disabled.');
assert_folder_apply_safety(strpos($plugin, "OLAMA_MEDIA_DRIVE_SYNC_ENABLED', false") !== false, 'Legacy Drive synchronization must remain disabled.');
assert_folder_apply_safety(strpos($plugin, "OLAMA_MEDIA_LIBRARY_VERSION', '2.8.0'") !== false, 'Plugin version must be 2.8.0.');

require_once $root . '/includes/class-olama-media-normalizer.php';
require_once $root . '/includes/class-olama-media-folder-provisioning-apply.php';
class FolderApplyTestDrive {
    public function list_folder_children_page($parent, $token, $size) {
        return array('next_page_token'=>'', 'items'=>array(
            array('id'=>'grade-5', 'name'=>'الصف الخامس', 'mime_type'=>'application/vnd.google-apps.folder', 'trashed'=>false),
            array('id'=>'other', 'name'=>'الصف الرابع', 'mime_type'=>'application/vnd.google-apps.folder', 'trashed'=>false),
        ));
    }
}
$service = new Olama_Media_Folder_Provisioning_Apply(new stdClass(), new stdClass(), new stdClass(), new Olama_Media_Normalizer());
$inspect = new ReflectionMethod($service, 'inspect_live_child');
$inspect->setAccessible(true);
$live = $inspect->invoke($service, new FolderApplyTestDrive(), 'semester-id', (object) array('node_type'=>'grade', 'expected_name'=>'خامس أساسي'));
assert_folder_apply_safety(count($live['exact']) === 1 && $live['exact'][0]['id'] === 'grade-5', 'Live revalidation must recognize the canonical Arabic grade folder without matching another grade.');

echo "Folder provisioning apply safety tests passed.\n";
