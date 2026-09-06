<?php
function assert_link_check_ui($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

$root = dirname(__DIR__);
$view = file_get_contents($root . '/views/media-library-page.php');
$script = file_get_contents($root . '/assets/js/media-library-admin.js');
$style = file_get_contents($root . '/assets/css/media-library-admin.css');

assert_link_check_ui(strpos($view, 'data-tab="link-check"') !== false, 'Administrators must have a separate link-check tab.');
assert_link_check_ui(strpos($view, 'id="tab-link-check"') !== false, 'Link-check content must live in its own tab section.');
assert_link_check_ui(strpos($view, "esc_html_e('فحص الربط'") !== false, 'The tab must use the requested Arabic label.');
foreach (array('audit-year-id', 'audit-semester', 'audit-grade', 'audit-subject', 'btn-audit-scope') as $control) {
    assert_link_check_ui(strpos($view, 'id="' . $control . '"') !== false, "Link-check scope must include {$control}.");
}
foreach (range(1, 5) as $step) {
    assert_link_check_ui(strpos($view, 'data-workflow-step="' . $step . '"') !== false, "Workflow step {$step} must be visible.");
}
foreach (array('btn-folder-provisioning-preview', 'folder-provisioning-summary', 'folder-provisioning-table', 'folder-provisioning-body') as $control) {
    assert_link_check_ui(strpos($view, 'id="' . $control . '"') !== false, "Folder provisioning stage must include {$control}.");
}
foreach (array('folder-provisioning-apply-gate', 'btn-folder-provisioning-readiness', 'folder-provisioning-confirmation', 'btn-folder-provisioning-apply', 'folder-provisioning-apply-result') as $control) {
    assert_link_check_ui(strpos($view, 'id="' . $control . '"') !== false, "Guarded folder execution must include {$control}.");
}
assert_link_check_ui(strpos($view, 'هذه معاينة للقراءة فقط') !== false, 'Folder provisioning must clearly identify the preview as read-only.');
assert_link_check_ui(strpos($view, 'id="olama-advanced-tools"') > strpos($view, 'data-workflow-step="5"'), 'Technical diagnostics must follow the guided workflow.');
assert_link_check_ui(strpos($script, "activateTab('link-check')") !== false, 'Diagnostic deep links must open the link-check tab.');
assert_link_check_ui(strpos($script, 'function auditFilters()') !== false, 'The link-check tab must own its curriculum filters.');
assert_link_check_ui(strpos($script, "action: 'olama_media_folder_provisioning_preview'") !== false, 'Folder planning UI must call the authenticated preview endpoint.');
assert_link_check_ui(strpos($script, 'غير قابل للاعتماد — مسار مختلف') !== false, 'Cross-grade candidates must not expose a manual confirmation action.');
assert_link_check_ui(strpos($script, "setWorkflowStep(5") !== false, 'Final commit must remain the fifth guarded workflow stage.');
assert_link_check_ui(strpos($style, '.olama-link-check-flow') !== false, 'The guided workflow must have dedicated responsive styling.');
assert_link_check_ui(strpos($style, '.folder-plan-conflict') !== false, 'Folder plan conflicts must be visually prominent.');

echo "Link-check UI tests passed.\n";
