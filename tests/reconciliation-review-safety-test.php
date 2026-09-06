<?php
function assert_review_safety($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

$root = dirname(__DIR__);
$db = file_get_contents($root . '/includes/class-olama-media-db.php');
$service = file_get_contents($root . '/includes/class-olama-media-reconciliation-preview.php');
$ajax = file_get_contents($root . '/includes/class-olama-media-ajax.php');
$plugin = file_get_contents($root . '/olama-media-library.php');

foreach (array('decision_status', 'selected_unit_id', 'selected_lesson_id', 'reviewed_by', 'reviewed_at') as $column) {
    assert_review_safety(strpos($db, $column) !== false, "Reconciliation review schema must include {$column}.");
}
assert_review_safety(strpos($ajax, "wp_ajax_olama_media_reconciliation_review") !== false, 'Authenticated reconciliation review endpoint must be registered.');
assert_review_safety(strpos($ajax, "wp_ajax_nopriv_olama_media_reconciliation_review") === false, 'Reconciliation review must never expose an unauthenticated endpoint.');
assert_review_safety(strpos($ajax, 'require_drive_administration();') !== false, 'Reconciliation review endpoint must require Drive administration capability.');
assert_review_safety(strpos($service, "'authoritative_links_changed'=>false") !== false, 'Review response must explicitly report that authoritative links are unchanged.');
assert_review_safety(strpos($service, "'drive_mutations'=>0") !== false, 'Review response must explicitly report zero Drive mutations.');
assert_review_safety(strpos($service, 'upsert_lesson_video_link') === false, 'Reconciliation staging must not write authoritative lesson links.');
assert_review_safety(strpos($service, 'reconciliation_unit_boundary') !== false, 'Manual selection must enforce the Drive unit boundary server-side.');
assert_review_safety(strpos($service, 'stale_reconciliation_item') !== false, 'Review must reject stale inventory generations.');
assert_review_safety(strpos($plugin, "OLAMA_MEDIA_LIBRARY_DB_VERSION', '2.8.0'") !== false, 'Schema version must include the additive reconciliation migration.');

echo "Reconciliation review safety tests passed.\n";
