<?php
function assert_rollback_safety($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

$root = dirname(__DIR__);
$source = file_get_contents($root . '/includes/class-olama-media-reconciliation-rollback.php');
$commit = file_get_contents($root . '/includes/class-olama-media-reconciliation-commit.php');
$ajax = file_get_contents($root . '/includes/class-olama-media-ajax.php');
$db = file_get_contents($root . '/includes/class-olama-media-db.php');
$plugin = file_get_contents($root . '/olama-media-library.php');
$view = file_get_contents($root . '/views/media-library-page.php');

assert_rollback_safety(strpos($source, "const CONFIRMATION_PHRASE = 'ROLLBACK REVIEWED LINKS';") !== false, 'Rollback must require an exact explicit phrase.');
assert_rollback_safety(strpos($source, "query('START TRANSACTION')") !== false, 'Rollback must start a transaction.');
assert_rollback_safety(strpos($source, "query('COMMIT')") !== false, 'Rollback must commit explicitly.');
assert_rollback_safety(strpos($source, "query('ROLLBACK')") !== false, 'Rollback failures must roll back atomically.');
assert_rollback_safety(strpos($source, 'rollback_link_changed_after_commit') !== false, 'Rollback must block links modified after commit.');
assert_rollback_safety(strpos($source, 'rollback_snapshot_missing') !== false, 'Rollback must block old commits without snapshots.');
assert_rollback_safety(strpos($source, 'new Olama_Media_Drive(') === false, 'Rollback must not instantiate the Drive client.');
foreach (array('files->create', 'files->update', 'files->delete', 'get_or_create_nested_folder') as $mutation) {
    assert_rollback_safety(strpos($source, $mutation) === false, "Rollback must not contain Drive mutation {$mutation}.");
}
foreach (array('commit_action', 'previous_link_state', 'committed_link_fingerprint', 'committed_drive_file_row_id', 'previous_drive_file_state', 'committed_drive_file_fingerprint', 'rollback_run_id', 'rolled_back_at') as $column) {
    assert_rollback_safety(strpos($db, $column) !== false, "Rollback audit schema must include {$column}.");
}
assert_rollback_safety(strpos($commit, 'committed_link_fingerprint') !== false, 'Commit must store the post-commit fingerprint.');
assert_rollback_safety(strpos($commit, 'committed_drive_file_fingerprint') !== false, 'Commit must store the post-commit Drive index fingerprint.');
assert_rollback_safety(strpos($source, 'rollback_drive_index_changed_after_commit') !== false, 'Rollback must block Drive index rows modified after commit.');
assert_rollback_safety(strpos($ajax, 'wp_ajax_olama_media_reconciliation_rollback_readiness') !== false, 'Authenticated rollback readiness endpoint must be registered.');
assert_rollback_safety(strpos($ajax, 'wp_ajax_olama_media_reconciliation_rollback') !== false, 'Authenticated rollback endpoint must be registered.');
assert_rollback_safety(strpos($ajax, 'wp_ajax_nopriv_olama_media_reconciliation_rollback') === false, 'Rollback endpoints must never be public.');
assert_rollback_safety(strpos($view, 'ROLLBACK REVIEWED LINKS') !== false, 'Rollback UI must display the exact phrase.');
assert_rollback_safety(strpos($plugin, "OLAMA_MEDIA_LIBRARY_VERSION', '2.7.0'") !== false, 'Plugin version must be 2.7.0.');
assert_rollback_safety(strpos($plugin, "OLAMA_MEDIA_LIBRARY_DB_VERSION', '2.7.0'") !== false, 'Database version must include rollback audit columns.');

echo "Reconciliation rollback safety tests passed.\n";
