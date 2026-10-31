<?php
define('ABSPATH', __DIR__ . '/');
define('OLAMA_MEDIA_DRIVE_UPLOAD_ENABLED', true);
function __($message, $domain = null) { return $message; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $value)); }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function wp_json_encode($value) { return json_encode($value); }
function current_time($type) { return '2026-10-29 12:00:00'; }
function get_current_user_id() { return 1; }
function get_option($key, $default = array()) { return $GLOBALS['safe_upload_settings'] ?? $default; }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error {
    private $code; private $message; private $data;
    public function __construct($code, $message, $data = array()) { $this->code = $code; $this->message = $message; $this->data = $data; }
    public function get_error_code() { return $this->code; }
    public function get_error_message() { return $this->message; }
    public function get_error_data() { return $this->data; }
}
function assert_safe_upload($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }
function expect_safe_error($value, $code) { assert_safe_upload(is_wp_error($value) && $value->get_error_code() === $code, 'Expected ' . $code . ', got ' . (is_wp_error($value) ? $value->get_error_code() : (string) $value)); }
$root = dirname(__DIR__);
require_once $root . '/includes/class-olama-media-feature-flags.php';
require_once $root . '/includes/class-olama-media-normalizer.php';
require_once $root . '/includes/class-olama-media-drive-mapping.php';
require_once $root . '/includes/class-olama-media-safe-upload-folder-resolver.php';
require_once $root . '/includes/class-olama-media-ajax.php';

class SafeUploadWpdb {
    public $prefix = 'wp_'; public $mapping = null; public $insert_id = 0;
    public $locks = array(); public $busy_at = 0; public $lock_attempts = 0; public $save_fails = false;
    public function prepare($sql, ...$args) {
        $i = 0;
        return preg_replace_callback('/%[sd]/', function ($m) use (&$i, $args) { $v = $args[$i++]; return $m[0] === '%d' ? (int) $v : "'" . $v . "'"; }, $sql);
    }
    public function get_row($sql) { return $this->mapping; }
    public function get_var($sql) {
        preg_match("/'([^']+)'/", $sql, $m); $lock = $m[1] ?? '';
        if (strpos($sql, 'GET_LOCK') !== false) {
            if (++$this->lock_attempts === $this->busy_at) { return 0; }
            $this->locks[$lock] = true;
        } else { unset($this->locks[$lock]); }
        return 1;
    }
    public function insert($table, $data) {
        if ($this->save_fails) { return false; }
        $data['id'] = ++$this->insert_id; $this->mapping = (object) $data; return 1;
    }
    public $before_update = null;
    public function update($table, $data, $where) {
        if ($this->before_update) { ($this->before_update)($this); }
        if ($this->save_fails) { return false; }
        foreach ($where as $key=>$value) { if ((string) ($this->mapping->$key ?? '') !== (string) $value) { return 0; } }
        foreach ($data as $key=>$value) { $this->mapping->$key = $value; }
        return 1;
    }
}
class SafeUploadCurriculum {
    public $valid = true;
    public $names = array('academic_year'=>'2026-2027', 'semester'=>'First Semester', 'grade'=>'أول ثانوي', 'subject'=>'الرياضيات');
    public function is_valid_scope($year, $semester, $grade, $subject) { return $this->valid; }
    public function get_names($year, $semester, $grade, $subject) { return $this->names; }
}
class SafeUploadCurriculumDB {
    public function get_curriculum_with_assets($year, $semester, $grade, $subject) { return array(
        (object) array('id'=>5, 'unit_name'=>'الاقترانات والمقادير الجبرية', 'lessons'=>array((object) array('id'=>6))),
    ); }
}
class SafeUploadDrive {
    public $folders = array(); public $created = array(); public $fail_create = ''; public $fail_listing = '';
    public $metadata_hook = null; public $after_create = null; public $page_size = 200; public $repeat_token = false;
    public function __construct() {
        $this->add('root', 'Olama Videos', ''); $this->add('year', '2026-2027', 'root');
        $this->add('semester', 'First Semester', 'year'); $this->add('grade', 'الحادي عشر', 'semester');
        $this->add('islamic', 'التربية الإسلامية', 'grade');
    }
    public function add($id, $name, $parent, $extra = array()) {
        $this->folders[$id] = array_merge(array('id'=>$id,'name'=>$name,'parents'=>$parent === '' ? array() : array($parent),
            'mime_type'=>'application/vnd.google-apps.folder','trashed'=>false), $extra);
    }
    public function get_file_metadata($id) {
        if ($this->metadata_hook) { ($this->metadata_hook)($this, $id); }
        return $this->folders[$id] ?? new WP_Error('missing', 'Folder is inaccessible.');
    }
    public function list_folder_children_page($parent, $token, $size) {
        if ($parent === $this->fail_listing) { return new WP_Error('list_failed', 'Drive permission denied.'); }
        $children = array_values(array_filter($this->folders, function ($f) use ($parent) { return in_array($parent, $f['parents'], true); }));
        $offset = (int) $token; $items = array_slice($children, $offset, $this->page_size);
        $next = $offset + count($items) < count($children) ? (string) ($offset + count($items)) : '';
        return array('items'=>$items, 'next_page_token'=>$this->repeat_token ? 'repeat' : $next);
    }
    public function create_upload_folder($name, $parent) {
        if ($name === $this->fail_create) { return new WP_Error('create_failed', 'Drive creation failed.'); }
        $id = 'created-' . (count($this->created) + 1); $this->add($id, $name, $parent);
        $this->created[] = $this->folders[$id];
        if ($this->after_create) { ($this->after_create)($this, $id); }
        return array('id'=>$id, 'created'=>true);
    }
}
function fixture_safe_upload() {
    global $wpdb;
    $GLOBALS['safe_upload_settings'] = array('root_folder_id'=>'root', 'root_scope_level'=>'unknown', 'root_scope_id'=>0);
    $wpdb = new SafeUploadWpdb(); $curriculum = new SafeUploadCurriculum(); $drive = new SafeUploadDrive();
    $normalizer = new Olama_Media_Normalizer();
    $mapping = new Olama_Media_Drive_Mapping(new stdClass(), $curriculum, $normalizer);
    $resolver = new Olama_Media_Safe_Upload_Folder_Resolver(null, $mapping, $normalizer, new SafeUploadCurriculumDB(), $curriculum);
    return array($resolver, $drive, $curriculum);
}
function bind_safe_subject($id = 'subject', $status = 'confirmed') {
    global $wpdb;
    $wpdb->mapping = (object) array('id'=>9, 'drive_folder_id'=>$id, 'mapping_status'=>$status, 'confirmation_method'=>'manual_verified',
        'root_config_hash'=>hash('sha256', wp_json_encode($GLOBALS['safe_upload_settings'])));
}
$meta = array('academic_year_id'=>1,'semester_id'=>2,'grade_id'=>3,'subject_id'=>4,'unit_id'=>5,'lesson_id'=>6,'unit_name'=>'لا يثق به الخادم');

// User's case: only Islamic Education exists under grade eleven. No scan/mapping required.
list($resolver, $drive) = fixture_safe_upload();
$result = $resolver->resolve($drive, $meta);
assert_safe_upload($result === 'created-2', 'Missing Mathematics and its selected unit must be created before upload.');
assert_safe_upload(count($drive->created) === 2 && $drive->created[0]['parents'] === array('grade'), 'First secondary must reuse grade eleven and create Mathematics directly beneath it.');
assert_safe_upload($drive->created[1]['parents'] === array('created-1'), 'The unit must use the returned subject folder ID.');
assert_safe_upload($drive->created[1]['name'] === 'الاقترانات والمقادير الجبرية', 'Use the database unit name, never a browser label.');
assert_safe_upload($wpdb->mapping->drive_folder_id === 'created-1' && $wpdb->mapping->confirmation_method === 'upload_verified_path', 'Persist the live-verified subject ID and evidence.');
assert_safe_upload(!$wpdb->locks, 'Success must release all locks.');
assert_safe_upload($resolver->resolve($drive, $meta) === $result && count($drive->created) === 2, 'Retry must reuse folders and preserve the mapping.');

// Empty root: create only the requested path, in parent order.
list($resolver, $drive) = fixture_safe_upload(); $drive->folders = array('root'=>$drive->folders['root']);
assert_safe_upload($resolver->resolve($drive, $meta) === 'created-5' && count($drive->created) === 5, 'An empty root must create year, semester, grade, subject, unit.');
// Existing folders, normalization, and stale inventory need no creation or rescan.
list($resolver, $drive) = fixture_safe_upload();
$drive->add('subject', 'الرياضيات', 'grade'); $drive->add('unit', 'الإقترانات والمقادير الجبرية', 'subject'); bind_safe_subject();
assert_safe_upload($resolver->resolve($drive, $meta) === 'unit' && !$drive->created, 'Unique existing branch must be reused live.');
assert_safe_upload($wpdb->mapping->confirmation_method === 'manual_verified', 'Do not overwrite reviewed mapping evidence.');
// Confirmed renamed subject remains authoritative within the verified grade.
$drive->folders['subject']['name'] = 'رياضيات معتمدة';
assert_safe_upload($resolver->resolve($drive, $meta) === 'unit', 'Reuse the reviewed subject by ID under the correct parent.');

// Reject invalid curriculum before any Drive mutation.
list($resolver, $drive, $curriculum) = fixture_safe_upload(); $curriculum->valid = false;
expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_scope_invalid');
$curriculum->valid = true; $bad = $meta; $bad['lesson_id'] = 999;
expect_safe_error($resolver->resolve($drive, $bad), 'safe_upload_curriculum_mismatch');
assert_safe_upload(!$drive->created && !$wpdb->locks, 'Invalid curriculum must never mutate Drive.');

// Duplicate candidate on a later page must still block; no partial create.
list($resolver, $drive) = fixture_safe_upload(); $drive->page_size = 1;
$drive->add('subject', 'الرياضيات', 'grade'); $drive->add('duplicate', 'الرياضيات', 'grade');
expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_folder_ambiguous');
assert_safe_upload(!$drive->created && !$wpdb->locks, 'Detect paginated duplicates before making changes.');
list($resolver, $drive) = fixture_safe_upload(); $drive->add('subject', 'الرياضيات', 'grade');
$drive->add('similar', 'الوحدة الأولى: الاقترانات والمقادير الجبرية', 'subject');
expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_folder_review_required');
assert_safe_upload(!$drive->created, 'Do not create a duplicate alongside a similar unit.');
// Trashed folders and shortcuts do not become upload destinations.
list($resolver, $drive) = fixture_safe_upload(); $drive->add('trash', 'الرياضيات', 'grade', array('trashed'=>true));
$drive->add('shortcut', 'الرياضيات', 'grade', array('mime_type'=>'application/vnd.google-apps.shortcut'));
assert_safe_upload($resolver->resolve($drive, $meta) === 'created-2', 'Ignore trashed folders and shortcuts.');

// A stale/proposed/different mapping must not be silently replaced.
foreach (array('confirmed','proposed_for_revalidation') as $status) {
    list($resolver, $drive) = fixture_safe_upload(); bind_safe_subject('elsewhere', $status);
    expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_mapping_changed');
    assert_safe_upload(!$drive->created && !$wpdb->locks, 'A changed mapping must stop before creation.');
}
list($resolver, $drive) = fixture_safe_upload(); bind_safe_subject(); $wpdb->mapping->root_config_hash = 'old';
expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_mapping_changed');

// A stale hash or inventory revalidation flag must not block a valid live path.
foreach (array('confirmed','proposed_for_revalidation') as $status) {
    list($resolver, $drive) = fixture_safe_upload();
    $drive->add('subject', 'الرياضيات', 'grade'); bind_safe_subject('subject', $status);
    $wpdb->mapping->root_config_hash = 'old'; $wpdb->mapping->confirmation_evidence = '{"review":"prior"}';
    assert_safe_upload($resolver->resolve($drive, $meta) === 'created-1', 'Revalidate the same subject ID live and create its missing unit.');
    assert_safe_upload($wpdb->mapping->mapping_status === 'confirmed' && $wpdb->mapping->root_config_hash === hash('sha256', wp_json_encode($GLOBALS['safe_upload_settings'])), 'Save the current root and confirmed state only after live verification.');
    $evidence = json_decode($wpdb->mapping->confirmation_evidence, true);
    assert_safe_upload($evidence['previous_mapping']['root_config_hash'] === 'old' && $evidence['previous_mapping']['confirmation_evidence'] === '{"review":"prior"}', 'Preserve prior review evidence when renewing a stale mapping.');
    assert_safe_upload(!$wpdb->locks, 'Renewal must release locks.');
}

// Regression from the original screenshots: an automatic first-secondary
// mapping was recorded beneath grade one before grade aliases were fixed.
function add_legacy_secondary_mapping($drive, $method = 'scoped_candidate') {
    global $wpdb;
    $drive->add('old-grade', 'الصف الأول', 'semester');
    $drive->add('old-subject', 'الرياضيات', 'old-grade');
    $drive->add('old-video', 'previous.mp4', 'old-subject', array('mime_type'=>'video/mp4'));
    bind_safe_subject('old-subject'); $wpdb->mapping->confirmation_method = $method;
}
foreach (array('scoped_candidate','provisioned_plan') as $method) {
    list($resolver, $drive) = fixture_safe_upload(); add_legacy_secondary_mapping($drive, $method);
    $before = $drive->folders;
    assert_safe_upload($resolver->resolve($drive, $meta) === 'created-2', 'Correct a proven automatic grade-one / first-secondary alias mapping.');
    assert_safe_upload($drive->created[0]['parents'] === array('grade') && $wpdb->mapping->drive_folder_id === 'created-1', 'Create/rebind Mathematics inside grade eleven.');
    $evidence = json_decode($wpdb->mapping->confirmation_evidence, true);
    assert_safe_upload($evidence['mapping_repair_reason'] === 'legacy_secondary_grade_alias' && $evidence['previous_mapping']['drive_folder_id'] === 'old-subject', 'Audit the old folder and exact correction reason.');
    foreach ($before as $id=>$folder) { assert_safe_upload($drive->folders[$id] === $folder, 'Legacy correction must never move, rename, or delete existing items.'); }
    assert_safe_upload($resolver->resolve($drive, $meta) === 'created-2' && count($drive->created) === 2, 'Retry after repair must reuse the new path.');
}
// Existing correct Mathematics must be reused when correcting the old mapping.
list($resolver, $drive) = fixture_safe_upload(); add_legacy_secondary_mapping($drive);
$drive->add('subject', 'الرياضيات', 'grade');
assert_safe_upload($resolver->resolve($drive, $meta) === 'created-1' && $drive->created[0]['parents'] === array('subject'), 'Repair must reuse an existing unique subject under grade eleven.');
foreach (array('year','semester') as $root_id) {
    list($resolver, $drive) = fixture_safe_upload();
    $GLOBALS['safe_upload_settings']['root_folder_id'] = $root_id;
    if ($root_id === 'semester') { $GLOBALS['safe_upload_settings']['root_scope_level'] = 'semester'; $GLOBALS['safe_upload_settings']['root_scope_id'] = 2; }
    add_legacy_secondary_mapping($drive);
    assert_safe_upload($resolver->resolve($drive, $meta) === 'created-2', 'Verify legacy alias correction up to a scoped ' . $root_id . ' root.');
}
list($resolver, $drive, $curriculum) = fixture_safe_upload(); add_legacy_secondary_mapping($drive);
$curriculum->names['grade'] = 'ثاني ثانوي'; $drive->folders['grade']['name'] = 'الثاني عشر'; $drive->folders['old-grade']['name'] = 'الصف الثاني';
assert_safe_upload($resolver->resolve($drive, $meta) === 'created-2' && $drive->created[0]['parents'] === array('grade'), 'Repair the corresponding grade-two / second-secondary bug into grade twelve.');
// Do not reinterpret explicit manual choices or folders in another scope.
foreach (array('manual','semester','year','root','grade','name','trashed') as $case) {
    list($resolver, $drive) = fixture_safe_upload(); add_legacy_secondary_mapping($drive);
    if ($case === 'manual') { $wpdb->mapping->confirmation_method = 'manual_verified'; }
    if ($case === 'semester') { $drive->folders['semester']['name'] = 'Second Semester'; }
    if ($case === 'year') { $drive->folders['year']['name'] = '2025-2026'; }
    if ($case === 'root') { $drive->folders['year']['parents'] = array('other-root'); }
    if ($case === 'grade') { $drive->folders['old-grade']['name'] = 'الصف الثاني'; }
    if ($case === 'name') { $drive->folders['old-subject']['name'] = 'اللغة العربية'; }
    if ($case === 'trashed') { $drive->folders['old-subject']['trashed'] = true; }
    expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_mapping_changed');
    assert_safe_upload(!$drive->created && $wpdb->mapping->drive_folder_id === 'old-subject' && !$wpdb->locks, 'Unproven or manually reviewed mappings must remain unchanged.');
}
// Real duplicates must still stop a proven legacy correction.
list($resolver, $drive) = fixture_safe_upload(); add_legacy_secondary_mapping($drive);
$drive->add('subject', 'الرياضيات', 'grade'); $drive->add('duplicate', 'الرياضيات', 'grade');
expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_folder_ambiguous');
assert_safe_upload(!$drive->created && $wpdb->mapping->drive_folder_id === 'old-subject', 'Do not repair into an ambiguous destination.');
// A concurrent mapping edit must win over the upload's previously inspected state.
list($resolver, $drive) = fixture_safe_upload(); $drive->add('subject', 'الرياضيات', 'grade'); bind_safe_subject('subject', 'proposed_for_revalidation');
$wpdb->before_update = function ($db) { $db->mapping->drive_folder_id = 'changed-by-another-request'; };
expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_mapping_save_failed');
assert_safe_upload($wpdb->mapping->drive_folder_id === 'changed-by-another-request' && !$wpdb->locks, 'Conditional update must not overwrite a concurrent mapping change.');

// Partial failure is recoverable by reuse, without a scan or duplicate subject.
list($resolver, $drive) = fixture_safe_upload(); $drive->fail_create = 'الاقترانات والمقادير الجبرية';
expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_drive_error');
assert_safe_upload(count($drive->created) === 1 && !$wpdb->locks, 'Keep a successfully created subject and release locks after failure.');
$drive->fail_create = '';
assert_safe_upload($resolver->resolve($drive, $meta) === 'created-2' && count($drive->created) === 2, 'Retry must reuse the subject from partial preparation.');
list($resolver, $drive) = fixture_safe_upload(); $wpdb->save_fails = true;
expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_mapping_save_failed');
$wpdb->save_fails = false;
assert_safe_upload($resolver->resolve($drive, $meta) === 'created-2' && count($drive->created) === 2, 'Mapping save retry must not duplicate Drive folders.');

// Lock contention, permission failures, invalid pagination and root changes fail closed.
foreach (array(1,2) as $busy_at) {
    list($resolver, $drive) = fixture_safe_upload(); $wpdb->busy_at = $busy_at;
    $busy = $resolver->resolve($drive, $meta); expect_safe_error($busy, 'safe_upload_folder_busy');
    assert_safe_upload($busy->get_error_data()['retryable'] && !$drive->created && !$wpdb->locks, 'Contention must be retryable and release any acquired lock.');
}
list($resolver, $drive) = fixture_safe_upload(); $drive->fail_listing = 'grade';
expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_drive_error');
assert_safe_upload(!$drive->created && !$wpdb->locks, 'Permission failures must not lead to creation.');
list($resolver, $drive) = fixture_safe_upload(); $drive->repeat_token = true;
expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_pagination_invalid');
list($resolver, $drive) = fixture_safe_upload(); $drive->after_create = function () { $GLOBALS['safe_upload_settings']['root_folder_id'] = 'changed'; };
expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_root_changed');
assert_safe_upload(count($drive->created) === 1 && !$wpdb->locks, 'Stop immediately if root configuration changes mid-preparation.');
list($resolver, $drive) = fixture_safe_upload(); $drive->after_create = function ($d) { $d->folders['grade']['parents'] = array('other'); };
expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_mapping_changed');
list($resolver, $drive) = fixture_safe_upload(); $drive->after_create = function ($d, $id) { $d->add('racing-duplicate', $d->folders[$id]['name'], $d->folders[$id]['parents'][0]); };
expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_folder_ambiguous');
assert_safe_upload(count($drive->created) === 1 && !$wpdb->locks, 'An external duplicate appearing during creation must stop before upload.');
list($resolver, $drive) = fixture_safe_upload(); $drive->folders['root']['trashed'] = true;
expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_root_invalid');
assert_safe_upload(!$drive->created, 'A trashed root must never receive new folders.');

// Year/scoped roots skip their ancestors and reject different scopes.
list($resolver, $drive) = fixture_safe_upload(); $GLOBALS['safe_upload_settings']['root_folder_id'] = 'year';
assert_safe_upload($resolver->resolve($drive, $meta) === 'created-2', 'A year root must not nest another copy of itself.');
list($resolver, $drive) = fixture_safe_upload(); $GLOBALS['safe_upload_settings'] = array('root_folder_id'=>'grade','root_scope_level'=>'grade','root_scope_id'=>3);
assert_safe_upload($resolver->resolve($drive, $meta) === 'created-2', 'An explicitly scoped grade root must only create subject/unit.');
list($resolver, $drive) = fixture_safe_upload(); $GLOBALS['safe_upload_settings']['root_scope_level'] = 'grade'; $GLOBALS['safe_upload_settings']['root_scope_id'] = 999;
expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_root_scope_mismatch');
list($resolver, $drive) = fixture_safe_upload(); $drive->folders['root']['name'] = '2025-2026';
expect_safe_error($resolver->resolve($drive, $meta), 'safe_upload_root_scope_mismatch');
list($resolver, $drive, $curriculum) = fixture_safe_upload(); $curriculum->names['grade'] = 'ثاني ثانوي'; $drive->folders['grade']['name'] = 'الثاني عشر';
assert_safe_upload($resolver->resolve($drive, $meta) === 'created-2' && $drive->created[0]['parents'] === array('grade'), 'Second secondary must reuse grade twelve, never grade two.');

// Both real AJAX transports must prepare folders before creating video sessions.
$ajax_source = file_get_contents($root . '/includes/class-olama-media-ajax.php');
assert_safe_upload(substr_count($ajax_source, '$this->resolve_upload_folder($drive, $meta)') === 2, 'Both direct and streamed uploads must prepare folders.');
assert_safe_upload(strpos($ajax_source, 'get_or_create_nested_folder') === false, 'Uploads must not use the unrestricted legacy resolver.');
$ajax = (new ReflectionClass('Olama_Media_Ajax'))->newInstanceWithoutConstructor();
$map_error = new ReflectionMethod($ajax, 'map_upload_error');
$mapped = $map_error->invoke($ajax, 'safe_upload_folder_review_required', array(), 'راجع المجلد المشابه');
assert_safe_upload(!$mapped['retryable'] && $mapped['message_ar'] === 'راجع المجلد المشابه', 'Conflict errors must retain actionable non-retryable messages.');
$mapped = $map_error->invoke($ajax, 'safe_upload_folder_busy', array('retryable'=>true), 'أعد المحاولة');
assert_safe_upload($mapped['retryable'], 'Lock contention must remain retryable in the transport response.');

// Mapping discovery must recognize the same aliases after the next inventory.
$context = new ReflectionMethod(Olama_Media_Drive_Mapping::class, 'context_matches');
$normalizer = new Olama_Media_Normalizer();
$mapping = new Olama_Media_Drive_Mapping(new stdClass(), new stdClass(), $normalizer);
assert_safe_upload($context->invoke($mapping, 'grade', '', $normalizer->normalize_text('أول ثانوي'), 'Olama/2026-2027/First Semester/الحادي عشر/الرياضيات'), 'Inventory mapping must preserve first-secondary / grade-eleven routing.');
assert_safe_upload(!$context->invoke($mapping, 'grade', '', $normalizer->normalize_text('ثاني أساسي'), 'Olama/2026-2027/First Semester/الثاني عشر/الرياضيات'), 'Grade twelve must never be accepted for grade two.');

echo "Safe upload routing and automatic folder preparation tests passed.\n";
