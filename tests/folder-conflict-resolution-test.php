<?php
define('ABSPATH', __DIR__ . '/');
class WP_Error { private $message; public function __construct($code, $message) { $this->message = $message; } public function get_error_message() { return $this->message; } }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_strip_all_tags($value) { return strip_tags((string) $value); }
function sanitize_text_field($value) { return trim((string) $value); }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function absint($value) { return abs((int) $value); }
function wp_json_encode($value) { return json_encode($value); }
function current_time($type) { return '2026-09-15 10:00:00'; }
function get_current_user_id() { return 44; }
function __($text) { return $text; }

require_once dirname(__DIR__) . '/includes/class-olama-media-normalizer.php';
require_once dirname(__DIR__) . '/includes/class-olama-media-folder-provisioning.php';

class FolderResolutionWpdb {
    public $prefix = 'wp_';
    public $plan;
    public $nodes;
    public function __construct() {
        $this->plan = (object) array(
            'id'=>12, 'plan_uuid'=>'plan-12', 'plan_status'=>'blocked', 'discovery_run_id'=>7,
            'scope_key'=>'subject:1:2:3:4', 'anchor_drive_folder_id'=>'root', 'subject_mapping_id'=>9,
        );
        $this->nodes = array((object) array(
            'id'=>20, 'plan_id'=>12, 'node_key'=>'unit:12', 'node_type'=>'unit', 'parent_node_key'=>'subject',
            'curriculum_entity_id'=>12, 'unit_id'=>12, 'unit_number'=>'12',
            'expected_name'=>'الوحدة الثانية عشرة: حرف الفاء', 'normalized_name'=>'الوحده الثانيه عشره حرف الفاء',
            'planned_action'=>'conflict', 'parent_drive_folder_id'=>'subject-id', 'existing_drive_folder_id'=>'',
            'candidate_drive_folder_ids'=>json_encode(array('fa-folder','sad-folder')),
            'candidate_names'=>json_encode(array('الوحدة الحادية عشر : حرف الفاء','الوحدة الثانية عشر : حرف الصاد')),
            'path_snapshot'=>'Root/Arabic/الوحدة الثانية عشرة: حرف الفاء',
            'reasons'=>json_encode(array('reason'=>'possible_existing_folder_requires_review')),
        ));
    }
    public function query($sql) { return true; }
    public function prepare($query, ...$args) { return $query; }
    public function get_row($query) { return $this->plan; }
    public function get_results($query) { return $this->nodes; }
    public function update($table, $data, $where) {
        if (strpos($table, 'folder_plan_nodes') !== false) {
            foreach ($data as $key=>$value) { $this->nodes[0]->{$key} = $value; }
        } else {
            foreach ($data as $key=>$value) { $this->plan->{$key} = $value; }
        }
        return 1;
    }
}
class FolderResolutionInventory {
    public function get_latest_completed_run() { return (object) array('id'=>7); }
}
function assert_folder_resolution($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

global $wpdb;
$wpdb = new FolderResolutionWpdb();
$service = new Olama_Media_Folder_Provisioning(
    new FolderResolutionInventory(), new stdClass(), new Olama_Media_Normalizer(), new stdClass()
);
$result = $service->resolve_unit_conflict(12, 'unit:12', 'rename', 'fa-folder');
assert_folder_resolution(!is_wp_error($result), 'An administrator should be able to stage a reviewed rename.');
assert_folder_resolution($result['rename'] === 1 && $result['conflicts'] === 0, 'The resolved plan must count one rename and no remaining conflict.');
assert_folder_resolution($result['ready_for_review'] === true && $result['ready_for_reconciliation'] === true, 'Resolving the final conflict must unlock the reviewed plan and Stage 4.');
assert_folder_resolution($result['items'][0]['existing_drive_folder_id'] === 'fa-folder', 'The exact reviewed Drive ID must be retained for execution.');
assert_folder_resolution($result['items'][0]['reason'] === 'administrator_approved_folder_rename', 'The administrator rename decision must be auditable.');

$changed = $service->resolve_unit_conflict(12, 'unit:12', 'create', '');
assert_folder_resolution(!is_wp_error($changed), 'A reviewed decision should remain editable before plan execution.');
assert_folder_resolution($changed['create'] === 1 && $changed['rename'] === 0, 'Rejecting candidates must replace the rename with a create action.');

echo "Folder conflict resolution tests passed.\n";
