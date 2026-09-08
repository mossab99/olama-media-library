<?php
define('ABSPATH', __DIR__ . '/');
function assert_uploaded_visibility($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
$root = dirname(__DIR__);
$repo = file_get_contents($root . '/includes/class-olama-media-v2-repository.php');
$preview = file_get_contents($root . '/includes/class-olama-media-reconciliation-preview.php');
$script = file_get_contents($root . '/assets/js/media-library-admin.js');
$style = file_get_contents($root . '/assets/css/media-library-admin.css');
assert_uploaded_visibility(strpos($repo, 'get_active_manual_upload_links_for_scope') !== false, 'Reconciliation must query files explicitly linked by plugin uploads.');
assert_uploaded_visibility(strpos($repo, "l.match_method='manual_upload'") !== false, 'Only explicit plugin-upload links may bypass matching.');
assert_uploaded_visibility(strpos($preview, 'already_linked_uploads') !== false, 'The preview must report already-linked uploads separately.');
assert_uploaded_visibility(strpos($preview, 'isset($linked_upload_ids[(string) $item->drive_item_id])') !== false, 'An uploaded file later seen by inventory must not be staged twice.');
assert_uploaded_visibility(strpos($script, 'مربوط مسبقاً عبر الرفع') !== false, 'The UI must identify uploaded files as already linked.');
assert_uploaded_visibility(strpos($script, "actionableResults.length === 0") !== false, 'A report containing only linked uploads must not enable a duplicate commit.');
assert_uploaded_visibility(strpos($style, '.olama-linked-upload') !== false, 'Already-linked uploads must be visually distinct.');
echo "Uploaded file reconciliation visibility tests passed.\n";
