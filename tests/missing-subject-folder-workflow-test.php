<?php
define('ABSPATH', __DIR__ . '/');
define('OLAMA_MEDIA_REVIEWED_FOLDER_APPLY_ENABLED', true);
function __($text, $domain = null) { return $text; }
function absint($value) { return abs((int) $value); }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_key($value) { return strtolower(preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)); }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function wp_json_encode($value) { return json_encode($value); }
function get_option($key, $default = array()) { return $GLOBALS['folder_workflow_settings']; }
function current_time($type) { return $type === 'timestamp' ? 1793260800 : '2026-10-29 10:00:00'; }
function get_current_user_id() { return 1; }
function wp_generate_uuid4() { return 'folder-workflow-test'; }
function is_wp_error($value) { return $value instanceof WP_Error; }
class WP_Error {
    private $message;
    public function __construct($code, $message) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function check_folder_workflow($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

require_once dirname(__DIR__) . '/includes/class-olama-media-normalizer.php';
require_once dirname(__DIR__) . '/includes/class-olama-media-folder-provisioning.php';
require_once dirname(__DIR__) . '/includes/class-olama-media-folder-provisioning-apply.php';

$GLOBALS['folder_workflow_settings'] = array('root_folder_id'=>'root', 'root_scope_level'=>'unknown', 'root_scope_id'=>0);
class Olama_Media_DB {
    public function get_curriculum_with_assets($year, $semester, $grade, $subject) {
        return array(
            (object) array('id'=>5, 'unit_number'=>'1', 'unit_name'=>'الاقترانات والمقادير الجبرية'),
            (object) array('id'=>6, 'unit_number'=>'2', 'unit_name'=>'النهايات والمشتقات'),
        );
    }
}
class MissingSubjectCurriculum {
    public function get_names($year, $semester, $grade, $subject) {
        return array('academic_year'=>'2026-2027', 'semester'=>'First Semester', 'grade'=>'الحادي عشر', 'subject'=>'الرياضيات');
    }
}
class MissingSubjectInventory {
    public function get_latest_completed_run() {
        return (object) array('id'=>7, 'root_folder_id'=>'root', 'root_name'=>'Olama Videos',
            'root_config_hash'=>hash('sha256', wp_json_encode($GLOBALS['folder_workflow_settings'])));
    }
    public function get_all_observations($run_id) {
        return array(
            (object) array('item_type'=>'folder', 'drive_item_id'=>'year', 'parent_drive_folder_id'=>'root', 'item_name'=>'2026-2027'),
            (object) array('item_type'=>'folder', 'drive_item_id'=>'semester', 'parent_drive_folder_id'=>'year', 'item_name'=>'First Semester'),
            (object) array('item_type'=>'folder', 'drive_item_id'=>'grade', 'parent_drive_folder_id'=>'semester', 'item_name'=>'الحادي عشر'),
            (object) array('item_type'=>'folder', 'drive_item_id'=>'islamic', 'parent_drive_folder_id'=>'grade', 'item_name'=>'التربية الإسلامية'),
        );
    }
}
class MissingSubjectMapping {
    public $confirmed_folder = '';
    public function get_confirmed_mapping_for_scope($scope) { return null; }
    public function confirm_provisioned_subject($scope, $folder, $hash, $plan_id) {
        $this->confirmed_folder = $folder;
        return 9;
    }
}
class MissingSubjectWpdb {
    public $prefix = 'wp_';
    public $insert_id = 0;
    public $plan;
    public $nodes = array();
    public function prepare($sql, ...$args) { return $sql; }
    public function get_row($sql) { return strpos($sql, 'plan_hash=') !== false ? null : $this->plan; }
    public function get_results($sql) { return $this->nodes; }
    public function get_var($sql) { return 1; }
    public function query($sql) {
        if (strpos($sql, "SET plan_status='applying'") !== false) {
            $this->plan->plan_status = 'applying';
            $this->plan->apply_run_uuid = 'folder-workflow-test';
            return 1;
        }
        return true;
    }
    public function insert($table, $data) {
        $this->insert_id++;
        $data['id'] = $this->insert_id;
        if (strpos($table, 'plan_nodes') !== false) {
            $this->nodes[] = (object) array_merge(array('apply_status'=>'pending', 'resolved_drive_folder_id'=>''), $data);
        } else {
            $this->plan = (object) $data;
        }
        return 1;
    }
    public function update($table, $data, $where) {
        $rows = strpos($table, 'plan_nodes') !== false ? $this->nodes : array($this->plan);
        foreach ($rows as $row) {
            if ($row->id === $where['id']) { foreach ($data as $key=>$value) { $row->$key = $value; } }
        }
        return 1;
    }
}
class MissingSubjectDrive {
    public $created = array();
    private $folders = array(
        'root'=>array(array('id'=>'year', 'name'=>'2026-2027')),
        'year'=>array(array('id'=>'semester', 'name'=>'First Semester')),
        'semester'=>array(array('id'=>'grade', 'name'=>'الحادي عشر')),
        'grade'=>array(array('id'=>'islamic', 'name'=>'التربية الإسلامية')),
    );
    public function test_connection() { return array('id'=>'root'); }
    public function list_folder_children_page($parent, $token, $size) {
        return array('next_page_token'=>'', 'items'=>array_map(function ($folder) {
            return $folder + array('mime_type'=>'application/vnd.google-apps.folder', 'trashed'=>false);
        }, $this->folders[$parent] ?? array()));
    }
    public function create_reviewed_folder($name, $parent) {
        $folder = array('id'=>'created-' . (count($this->created) + 1), 'name'=>$name);
        $this->folders[$parent][] = $folder;
        $this->created[] = $folder + array('parent'=>$parent);
        return $folder + array('created'=>true);
    }
}

$wpdb = new MissingSubjectWpdb();
$inventory = new MissingSubjectInventory();
$mapping = new MissingSubjectMapping();
$planner = new Olama_Media_Folder_Provisioning($inventory, $mapping, new Olama_Media_Normalizer(), new MissingSubjectCurriculum());
$preview = $planner->preview_scope(1, 2, 3, 4);
check_folder_workflow(!is_wp_error($preview), 'A missing subject must be plannable without a confirmed mapping.');
check_folder_workflow($preview['create'] === 3 && $preview['existing'] === 3, 'Plan must reuse the year/semester/grade and create the subject plus both units.');
check_folder_workflow($preview['requires_apply'] && !$preview['ready_for_reconciliation'], 'A preview with missing folders must require execution before lesson matching.');
check_folder_workflow($preview['items'][3]['node_type'] === 'subject' && $preview['items'][3]['planned_action'] === 'create', 'Missing subject must be created before its units.');

$drive = new MissingSubjectDrive();
$executor = new Olama_Media_Folder_Provisioning_Apply($drive, $inventory, $mapping);
$invalid = $executor->apply($preview['plan_id'], '');
check_folder_workflow(is_wp_error($invalid) && !$drive->created, 'Drive must stay unchanged until the reviewed execution is confirmed.');
$ready = $executor->readiness($preview['plan_id']);
check_folder_workflow(!is_wp_error($ready) && $ready['ready'] && !$drive->created, 'Readiness must succeed for the missing branch without creating folders.');
$applied = $executor->apply($preview['plan_id'], 'APPLY REVIEWED FOLDER PLAN');
check_folder_workflow(!is_wp_error($applied), 'Reviewed missing-subject creation must execute successfully.');
check_folder_workflow($applied['created'] === 3 && $applied['reused'] === 3, 'Execution must create the missing branch and reuse its ancestors.');
check_folder_workflow($drive->created[0]['parent'] === 'grade', 'Subject must be created directly under the selected grade.');
check_folder_workflow($drive->created[1]['parent'] === 'created-1' && $drive->created[2]['parent'] === 'created-1', 'Both units must use the new subject Drive ID as parent.');
check_folder_workflow($mapping->confirmed_folder === 'created-1', 'Subject mapping must use the actual newly created Drive ID.');
check_folder_workflow($applied['inventory_refresh_required'], 'Execution must require a new inventory before uploads.');
$again = $executor->apply($preview['plan_id'], 'APPLY REVIEWED FOLDER PLAN');
check_folder_workflow($again['already_applied'] && count($drive->created) === 3, 'Retrying a completed plan must not create duplicate folders.');

// Even a confirmed subject cannot make pending unit creates or renames complete.
$report_method = new ReflectionMethod($planner, 'report');
$counts = array('existing'=>4, 'create'=>3, 'rename'=>0, 'conflict'=>0, 'blocked'=>0);
$pending = $report_method->invoke($planner, (object) array('id'=>18, 'plan_uuid'=>'18', 'plan_status'=>'ready_for_review'), array(), $counts, 9, 'subject:1:2:3:4');
check_folder_workflow($pending['requires_apply'] && !$pending['ready_for_reconciliation'], 'A confirmed subject with three missing units must stay in the execution stage.');
$completed = $report_method->invoke($planner, $wpdb->plan, array(), $counts, 9, 'subject:1:2:3:4');
check_folder_workflow(!$completed['requires_apply'] && $completed['inventory_refresh_required'] && !$completed['ready_for_reconciliation'], 'A completed plan from the old inventory must direct the administrator to rescan.');
$counts['create'] = 0;
$existing = $report_method->invoke($planner, (object) array('id'=>19, 'plan_uuid'=>'19', 'plan_status'=>'ready_for_review'), array(), $counts, 9, 'subject:1:2:3:4');
check_folder_workflow($existing['ready_for_reconciliation'] && !$existing['requires_apply'], 'A confirmed branch with all folders present must allow lesson matching.');

echo "Missing subject folder workflow tests passed.\n";
