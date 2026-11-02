<?php
// Reuse the live-path fixtures and their full resolver regression suite.
require __DIR__ . '/safe-upload-routing-test.php';
require_once $root . '/includes/class-olama-media-v2-repository.php';
require_once $root . '/includes/class-olama-media-workspace.php';
function esc_url_raw($value) { return (string) $value; }
function wp_generate_uuid4() { static $sequence = 0; return 'test-refresh-' . ++$sequence; }
function get_transient($key) { return $GLOBALS['workspace_cache'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['workspace_cache'][$key] = $value; return true; }
function update_option($key, $value, $autoload = null) { if (!empty($GLOBALS['fail_report_save'])) return false; $GLOBALS['workspace_options'][$key] = $value; return true; }

class WorkspaceWpdb extends SafeUploadWpdb {
    public $files = array(); public $links = array();
    public $before_link_update = null;
    public function get_row($sql) {
        if (strpos($sql, 'olama_curriculum_drive_map') !== false) { return $this->mapping ? clone $this->mapping : null; }
        preg_match("/drive_file_id='([^']*)'/", $sql, $match);
        $rows = strpos($sql, 'olama_lesson_video_links') !== false ? $this->links : $this->files;
        foreach ($rows as $row) { if ($row->drive_file_id === ($match[1] ?? '')) return clone $row; }
        return null;
    }
    public function get_results($sql) {
        if (strpos($sql, 'WHERE l.academic_year_id=') !== false) {
            $result = array();
            foreach ($this->links as $link) {
                if ($link->link_status !== 'active') continue;
                $matches = true;
                foreach (array('academic_year_id','semester_id','grade_id','subject_id') as $field) {
                    preg_match('/l\.' . $field . '=(\d+)/', $sql, $value);
                    if ((int) $link->$field !== (int) ($value[1] ?? 0)) $matches = false;
                }
                if (!$matches) continue;
                $row = clone $link;
                foreach ($this->files as $file) if ($file->drive_file_id === $link->drive_file_id) {
                    $row->filename = $file->filename; $row->drive_path = $file->drive_path ?? ''; $row->web_view_link = $file->web_view_link ?? '';
                }
                $result[] = $row;
            }
            return $result;
        }
        preg_match('/l.lesson_id=(\d+)/', $sql, $match);
        return array_values(array_filter($this->links, function ($link) use ($match) { return $link->lesson_id == ($match[1] ?? 0) && $link->link_status === 'active'; }));
    }
    public function get_var($sql) {
        if (strpos($sql, 'MAX(sequence_order)') !== false) return 0;
        return parent::get_var($sql);
    }
    public function insert($table, $data) {
        if (strpos($table, 'olama_curriculum_drive_map') !== false) return parent::insert($table, $data);
        if ($this->save_fails) return false;
        $data['id'] = ++$this->insert_id;
        if (strpos($table, 'olama_lesson_video_links') !== false) $this->links[$data['id']] = (object) $data;
        else $this->files[$data['id']] = (object) $data;
        return 1;
    }
    public function update($table, $data, $where) {
        if (strpos($table, 'olama_curriculum_drive_map') !== false) return parent::update($table, $data, $where);
        if ($this->save_fails) return false;
        if (strpos($table, 'olama_lesson_video_links') !== false && $this->before_link_update) ($this->before_link_update)($this);
        $rows = strpos($table, 'olama_lesson_video_links') !== false ? $this->links : $this->files;
        foreach ($rows as $row) { if ($row->id == $where['id']) {
            foreach ($where as $key=>$value) if (($row->$key ?? null) != $value) return 0;
            foreach ($data as $key=>$value) $row->$key = $value; return 1;
        } }
        return 0;
    }
}
class WorkspaceCurriculumDB {
    public $units;
    public function __construct() {
        $this->units = array((object) array('id'=>5, 'unit_name'=>'الوحدة الأولى', 'lessons'=>array(
            (object) array('id'=>6, 'lesson_number'=>'1', 'lesson_title'=>'الاقترانات'),
            (object) array('id'=>7, 'lesson_number'=>'2', 'lesson_title'=>'المتباينات'),
            (object) array('id'=>8, 'lesson_number'=>'3', 'lesson_title'=>'الجمع'),
        )));
    }
    public function get_curriculum_with_assets(...$scope) { return $this->units; }
}
function workspace_fixture() {
    global $wpdb;
    fixture_safe_upload(); $wpdb = new WorkspaceWpdb();
    $curriculum = new SafeUploadCurriculum(); $db = new WorkspaceCurriculumDB();
    $mapping = new Olama_Media_Drive_Mapping(new stdClass(), $curriculum);
    $repo = new Olama_Media_V2_Repository(); $drive = new SafeUploadDrive();
    $drive->add('subject', 'عنوان مختلف تماماً', 'grade');
    $service = new Olama_Media_Workspace($mapping, $curriculum, $db, $repo);
    $GLOBALS['workspace_cache'] = array(); $GLOBALS['workspace_options'] = array(); $GLOBALS['fail_report_save'] = false; $_POST = array();
    return array($service, $drive, $mapping, $repo, $db);
}
$scope = array(1,2,3,4);
list($service, $drive, $mapping, $repo, $db) = workspace_fixture();
$bound = $service->bind($drive, $scope, 'subject', '');
assert_safe_upload(!is_wp_error($bound) && $wpdb->mapping->confirmation_method === 'workspace_selected', 'Bind an arbitrary subject label without an inventory or name matching.');
$drive->folders['grade']['name'] = 'تسمية جديدة'; $drive->add('duplicate-grade', 'الحادي عشر', 'semester');
$drive->add('duplicate-subject', 'عنوان مختلف تماماً', 'grade');
assert_safe_upload(!is_wp_error($mapping->validate_binding($drive, $wpdb->mapping)), 'Ancestor renames and duplicate siblings must not invalidate an exact ID chain.');
$resolver = new Olama_Media_Safe_Upload_Folder_Resolver($mapping, null, $db, new SafeUploadCurriculum());
assert_safe_upload(!is_wp_error($resolver->resolve($drive, $meta)), 'Upload must use the same selected binding despite renamed ancestors.');
$drive->folders['subject']['parents'] = array('semester');
expect_safe_error($mapping->validate_binding($drive, $wpdb->mapping), 'binding_moved');
$drive->folders['subject']['parents'] = array('grade');
$outside = $service->bind($drive, $scope, 'not-in-root', 'subject');
assert_safe_upload(is_wp_error($outside) && $wpdb->mapping->drive_folder_id === 'subject', 'Invalid folder selection must preserve the binding.');
expect_safe_error($service->bind($drive, $scope, 'subject', 'stale-folder'), 'binding_changed');

// Picker revalidates scope and ancestry and shares idempotent attachment with uploads.
$drive->add('video', 'الاقترانات.mp4', 'subject', array('mime_type'=>'video/mp4', 'web_view_link'=>'https://drive.google.com/file/d/video/view'));
$linked = $service->link($drive, $scope, clone $wpdb->mapping, 'video', 6, 0);
assert_safe_upload(!is_wp_error($linked) && count($wpdb->links) === 1, 'Picker must create one pending canonical link.');
$id = $linked['link_id']; $wpdb->links[$id]->approval_status = 'approved'; $wpdb->links[$id]->approved_by = 22;
$again = $service->link($drive, $scope, clone $wpdb->mapping, 'video', 6, 0);
assert_safe_upload(!is_wp_error($again) && count($wpdb->links) === 1 && $wpdb->links[$id]->approval_status === 'approved' && $wpdb->links[$id]->approved_by === 22, 'Repeated linking/finalization must preserve approval and provenance.');
$wpdb->save_fails = true;
assert_safe_upload($repo->upsert_drive_file(array('drive_file_id'=>'cannot-save')) === 0, 'Manifest write failure must not return a stale insert ID.');
$wpdb->save_fails = false;
expect_safe_error($service->link($drive, $scope, clone $wpdb->mapping, 'video', 7, 0), 'video_already_linked');
expect_safe_error($service->link($drive, $scope, clone $wpdb->mapping, 'video', 999, 0), 'invalid_lesson');
$drive->add('outside', 'الخارج', ''); $drive->add('outside-video', 'ملف.mp4', 'outside', array('mime_type'=>'video/mp4'));
assert_safe_upload(is_wp_error($service->link($drive, $scope, clone $wpdb->mapping, 'outside-video', 7, 0)), 'Picker must reject a forged file outside the bound subtree.');
assert_safe_upload(is_wp_error($service->browse($drive, 'outside', 'subject', '', false)), 'Browsing cannot escape the bound subtree.');
$repo->unlink_drive_file($id);
assert_safe_upload($wpdb->links[$id]->link_status === 'ignored', 'Explicit unlink must remain dismissed for subsequent matching.');

// A complete refresh links unique titles, never number-only or competing files.
list($service, $drive, $mapping, $repo, $db) = workspace_fixture();
$service->bind($drive, $scope, 'subject', '');
$drive->page_size = 1;
$drive->add('one', 'الاقترانات.mp4', 'subject', array('mime_type'=>'video/mp4'));
$drive->add('two', 'المتباينات.mp4', 'subject', array('mime_type'=>'video/mp4'));
$drive->add('two-copy', 'المتباينات.mp4', 'subject', array('mime_type'=>'video/mp4'));
$drive->add('number-only', 'الدرس 3.mp4', 'subject', array('mime_type'=>'video/mp4'));
$drive->add('mismatch', 'الدرس 99 الجمع.mp4', 'subject', array('mime_type'=>'video/mp4'));
$step = $service->sync_step($drive, $scope, clone $wpdb->mapping, '');
assert_safe_upload(!$step['done'] && !$wpdb->links && !$wpdb->files, 'A partial refresh must not mutate the manifest or links.');
$token = $step['token']; $_POST['token'] = $token;
for ($i=0; !$step['done'] && $i<20; $i++) $step = $service->sync_step($drive, $scope, clone $wpdb->mapping, $token);
assert_safe_upload($step['done'] && $step['linked'] === 1 && $step['needs_assignment'] === 4 && count($wpdb->links) === 1, 'Only a unique exact title without conflicting numbering may auto-link.');
$repeated = $service->sync_step($drive, $scope, clone $wpdb->mapping, $token);
assert_safe_upload($repeated === $step && count($wpdb->links) === 1, 'A repeated completed batch must return the saved result without duplicate mutations.');
$link = reset($wpdb->links); $repo->unlink_drive_file($link->id);
$_POST = array(); $step = $service->sync_step($drive, $scope, clone $wpdb->mapping, ''); $token = $step['token']; $_POST['token']=$token;
for ($i=0; !$step['done'] && $i<20; $i++) $step = $service->sync_step($drive, $scope, clone $wpdb->mapping, $token);
assert_safe_upload($step['dismissed'] === 1 && reset($wpdb->links)->link_status === 'ignored', 'Refresh must not resurrect an explicitly dismissed link.');

// A failed scan and expired or scope-mismatched requests fail without destructive cleanup.
list($service, $drive, $mapping, $repo, $db) = workspace_fixture();
$service->bind($drive, $scope, 'subject', '');
$drive->fail_listing = 'subject';
assert_safe_upload(is_wp_error($service->sync_step($drive, $scope, clone $wpdb->mapping, '')) && !$wpdb->links && !$wpdb->files && !$wpdb->locks, 'Failed refresh releases locks and does not infer missing videos.');
$_POST['token'] = 'expired';
expect_safe_error($service->sync_step($drive, $scope, clone $wpdb->mapping, 'expired'), 'refresh_expired');
assert_safe_upload(!$wpdb->locks, 'All refresh exits release their locks.');
$old_mapping = clone $wpdb->mapping;
$wpdb->mapping->drive_folder_id = 'changed-concurrently';
expect_safe_error($service->link($drive, $scope, $old_mapping, 'video', 6, 0), 'binding_changed');
assert_safe_upload(!$wpdb->locks, 'Binding change before linking releases the subject lock.');
$wpdb->mapping = $old_mapping;
$GLOBALS['safe_upload_settings']['root_folder_id'] = 'new-root';
$resolver = new Olama_Media_Safe_Upload_Folder_Resolver($mapping, null, $db, new SafeUploadCurriculum());
expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_mapping_changed');
assert_safe_upload(!$drive->created, 'An explicitly selected binding cannot be rediscovered after a root change.');

echo "Workspace binding, picker, refresh and lifecycle tests passed.\n";
