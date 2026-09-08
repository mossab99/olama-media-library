<?php
function assert_rollout_readiness($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

$root = dirname(__DIR__);
$service = file_get_contents($root . '/includes/class-olama-media-rollout-readiness.php');
$bootstrap = file_get_contents($root . '/olama-media-library.php');
$ajax = file_get_contents($root . '/includes/class-olama-media-ajax.php');
$view = file_get_contents($root . '/views/media-library-page.php');
$script = file_get_contents($root . '/assets/js/media-library-admin.js');

assert_rollout_readiness(strpos($bootstrap, "class-olama-media-rollout-readiness.php") !== false, 'The readiness service must load from the plugin bootstrap.');
assert_rollout_readiness(strpos($ajax, "wp_ajax_olama_media_rollout_readiness") !== false, 'The authenticated readiness endpoint must be registered.');
assert_rollout_readiness(strpos($ajax, 'new Olama_Media_Rollout_Readiness()') !== false, 'The endpoint must delegate to the read-only readiness service.');
assert_rollout_readiness(strpos($view, 'id="btn-rollout-readiness"') !== false, 'The grade readiness action must be visible in link-check.');
assert_rollout_readiness(strpos($view, 'id="rollout-readiness-body"') !== false, 'The readiness table must expose per-subject rows.');
assert_rollout_readiness(strpos($script, "action: 'olama_media_rollout_readiness'") !== false, 'The UI must request the readiness report.');
assert_rollout_readiness(strpos($script, 'drive_videos_to_review') !== false, 'The UI must disclose unlinked Drive videos.');
assert_rollout_readiness(strpos($service, "approval_status='pending'") !== false, 'Pending approvals must prevent a fully ready result.');
assert_rollout_readiness(strpos($service, 'array_diff($drive_video_ids, $linked_ids)') !== false, 'Inventory files without active links must be counted.');
assert_rollout_readiness(strpos($service, "'drive_mutations'=>0") !== false, 'The report must explicitly declare zero Drive mutations.');

foreach (array('create_folder(', 'delete_file(', 'delete_folder(', 'move_file(', 'move_folder(', 'rename_file(', 'rename_folder(', 'files->create', 'files->delete', 'files->update') as $mutation) {
    assert_rollout_readiness(strpos($service, $mutation) === false, "Readiness must not contain Drive mutation {$mutation}.");
}
foreach (array('INSERT ', 'UPDATE ', 'DELETE ') as $write_sql) {
    assert_rollout_readiness(strpos($service, $write_sql) === false, "Readiness must not execute {$write_sql}SQL.");
}

echo "Rollout readiness tests passed.\n";
