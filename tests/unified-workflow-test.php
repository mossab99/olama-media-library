<?php
require __DIR__ . '/workspace-workflow-test.php';

function finish_subject_scan($service, $drive, $scope, $mapping) {
    $step = $service->sync_step($drive, $scope, clone $mapping, '');
    for ($i=0; !is_wp_error($step) && !$step['done'] && $i<30; $i++) $step = $service->sync_step($drive, $scope, clone $mapping, $step['token']);
    return $step;
}
list($service, $drive, $mapping, $repo, $db) = workspace_fixture();
assert_safe_upload($service->subject_report($scope, null)['state'] === 'needs_folder', 'A missing binding is a setup action, never an empty-material claim.');
$service->bind($drive, $scope, 'subject', '');
assert_safe_upload($service->subject_report($scope, $wpdb->mapping)['state'] === 'needs_scan', 'A bound but unscanned subject must not be called empty.');
$drive->add('empty-unit', 'وحدة فارغة', 'subject');
$result = finish_subject_scan($service, $drive, $scope, $wpdb->mapping);
assert_safe_upload(!is_wp_error($result) && $result['state'] === 'no_videos' && $result['folders'] === 2, 'An empty subject is confirmed only after traversal of its nested folders.');
$saved = $service->subject_report($scope, clone $wpdb->mapping);
assert_safe_upload($saved['state'] === 'no_videos' && isset($saved['completed_at']), 'The completed empty state persists across page reloads.');
$drive->add('notes', 'ملخص.pdf', 'empty-unit', array('mime_type'=>'application/pdf'));
$result = finish_subject_scan($service, $drive, $scope, $wpdb->mapping);
assert_safe_upload($result['state'] === 'no_videos' && $result['other_files'] === 1, 'Non-video files do not masquerade as available videos.');
$drive->fail_listing = 'empty-unit';
$before = $GLOBALS['workspace_options'];
assert_safe_upload(is_wp_error(finish_subject_scan($service, $drive, $scope, $wpdb->mapping)), 'An inaccessible nested folder makes the scan incomplete.');
assert_safe_upload($GLOBALS['workspace_options'] === $before, 'A failed scan cannot overwrite the last completed report with a false empty result.');
list($service, $drive, $mapping, $repo, $db) = workspace_fixture(); $service->bind($drive, $scope, 'subject', '');
$GLOBALS['fail_report_save'] = true;
expect_safe_error(finish_subject_scan($service, $drive, $scope, $wpdb->mapping), 'report_save_failed');
assert_safe_upload($service->subject_report($scope, $wpdb->mapping)['state'] === 'needs_scan', 'An unsaved result must not be presented as a durable completed scan.');

list($service, $drive, $mapping, $repo, $db) = workspace_fixture(); $service->bind($drive, $scope, 'subject', '');
$drive->add('auto', 'الاقترانات.mp4', 'subject', array('mime_type'=>'video/mp4'));
$drive->add('manual-one', 'محاضرة إضافية.mp4', 'subject', array('mime_type'=>'video/mp4'));
$drive->add('manual-two', 'درس غير معروف.mp4', 'subject', array('mime_type'=>'video/mp4'));
$result = finish_subject_scan($service, $drive, $scope, $wpdb->mapping);
assert_safe_upload($result['linked'] === 1 && $result['needs_assignment'] === 2 && count($result['items']) === 2, 'Automatically link the certain video and return actionable rows for just the exceptions.');
$report = $service->subject_report($scope, clone $wpdb->mapping);
assert_safe_upload(count($report['lessons']) === 3 && count($report['items']) === 2, 'The saved inbox includes the local lesson choices after reloading.');
expect_safe_error($service->link($drive, $scope, clone $wpdb->mapping, 'manual-one', 999, 0), 'invalid_lesson');
assert_safe_upload(!is_wp_error($service->link($drive, $scope, clone $wpdb->mapping, 'manual-one', 7, 0)), 'A valid row can be applied independently of an invalid or undecided row.');
assert_safe_upload(count($service->subject_report($scope, clone $wpdb->mapping)['items']) === 1, 'Applied rows disappear while undecided rows persist.');
assert_safe_upload(!is_wp_error($service->dismiss($drive, $scope, clone $wpdb->mapping, 'manual-two')), 'A file can be durably dismissed without inventing a lesson association.');
$ignored = $repo->get_link_by_drive_file_id('manual-two');
assert_safe_upload($ignored->link_status === 'ignored' && $ignored->lesson_id === 0, 'Dismissal is stored as a non-published association.');
assert_safe_upload(!$service->subject_report($scope, clone $wpdb->mapping)['items'], 'Resolved decisions persist independently of the browser and refresh session.');
$result = finish_subject_scan($service, $drive, $scope, $wpdb->mapping);
assert_safe_upload($result['dismissed'] === 1, 'Later scans respect an explicit dismissal.');
assert_safe_upload(is_wp_error($service->dismiss($drive, $scope, clone $wpdb->mapping, 'auto')), 'Ignoring an inbox item cannot silently unlink an active video.');

// A wrong existing target requires an explicit current revision and resets review.
list($service, $drive, $mapping, $repo, $db) = workspace_fixture(); $service->bind($drive, $scope, 'subject', '');
$drive->add('wrong', 'المتباينات.mp4', 'subject', array('mime_type'=>'video/mp4'));
$first = $service->link($drive, $scope, clone $wpdb->mapping, 'wrong', 6, 0);
$wpdb->links[$first['link_id']]->approval_status = 'approved'; $wpdb->links[$first['link_id']]->approved_by = 22;
$result = finish_subject_scan($service, $drive, $scope, $wpdb->mapping);
assert_safe_upload($result['items'][0]['reason'] === 'link_conflict' && $result['items'][0]['suggested_lesson_id'] === 7, 'Wrong existing targets are shown with a suggestion, never silently reassigned.');
$item = $service->subject_report($scope, clone $wpdb->mapping)['items'][0];
expect_safe_error($service->link($drive, $scope, clone $wpdb->mapping, 'wrong', 7, 0), 'video_already_linked');
expect_safe_error($service->link($drive, $scope, clone $wpdb->mapping, 'wrong', 7, 0, 'stale-revision'), 'video_already_linked');
$wpdb->before_link_update = function ($db) use ($first) { $db->links[$first['link_id']]->lesson_id = 99; };
expect_safe_error($service->link($drive, $scope, clone $wpdb->mapping, 'wrong', 7, 0, $item['link_revision']), 'link_changed');
assert_safe_upload($repo->get_link_by_drive_file_id('wrong')->lesson_id === 99, 'Conditional correction must not overwrite a concurrent change from another writer.');
$wpdb->before_link_update = null; $wpdb->links[$first['link_id']]->lesson_id = 6;
$corrected = $service->link($drive, $scope, clone $wpdb->mapping, 'wrong', 7, 0, $item['link_revision']);
$current = $repo->get_link_by_drive_file_id('wrong');
assert_safe_upload(!is_wp_error($corrected) && $current->lesson_id === 7 && $current->approval_status === 'pending' && $current->approved_by === null, 'Explicit correction resets approval for the new target.');
assert_safe_upload(!$service->subject_report($scope, clone $wpdb->mapping)['items'], 'A corrected target resolves its saved conflict.');
assert_safe_upload(!is_wp_error($service->link($drive, $scope, clone $wpdb->mapping, 'wrong', 7, 0, $item['link_revision'])), 'Repeating a successful correction is idempotent.');

// A video absent from the completed subtree remains linked until a human decides.
unset($drive->folders['wrong']);
$wpdb->links[$current->id]->approval_status = 'approved';
$result = finish_subject_scan($service, $drive, $scope, $wpdb->mapping);
assert_safe_upload($result['items'][0]['reason'] === 'not_observed', 'A missing or moved linked file is a review item rather than a deletion conclusion.');
assert_safe_upload($repo->get_link_by_drive_file_id('wrong')->approval_status === 'approved' && $repo->get_link_by_drive_file_id('wrong')->link_status === 'active', 'Scanning preserves the existing approval and association.');
assert_safe_upload(count($service->subject_report($scope, clone $wpdb->mapping)['items']) === 1, 'Unavailable-file review persists after reloading.');
$old = clone $wpdb->mapping;
$GLOBALS['safe_upload_settings']['root_folder_id'] = 'different-root';
assert_safe_upload($service->subject_report($scope, $old)['state'] === 'needs_folder', 'A root change hides obsolete reports and requires folder verification.');
assert_safe_upload(!$wpdb->locks, 'All unified-workflow operations release their locks.');
echo "Unified workflow, persistent decisions and empty-material tests passed.\n";
