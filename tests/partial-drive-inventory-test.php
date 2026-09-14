<?php
define('ABSPATH', __DIR__ . '/');

class WP_Error {
    private $code;
    private $message;
    public function __construct($code, $message) { $this->code = $code; $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function is_wp_error($value) { return $value instanceof WP_Error; }
function sanitize_text_field($value) { return is_scalar($value) ? trim((string) $value) : ''; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)); }
function absint($value) { return abs((int) $value); }
function wp_json_encode($value) { return json_encode($value); }
function get_option($name, $default = array()) {
    return array('root_folder_id'=>'root', 'root_scope_level'=>'unknown', 'root_scope_id'=>0);
}
function __($text) { return $text; }

require_once dirname(__DIR__) . '/includes/class-olama-media-drive-discovery.php';

class PartialInventoryDrive {
    public function test_connection() { return array('id'=>'root', 'name'=>'Root'); }
    public function get_file_metadata($id) {
        return array(
            'id'=>$id, 'name'=>'Arabic Renamed', 'mime_type'=>'application/vnd.google-apps.folder',
            'size'=>0, 'parents'=>array('grade-5'), 'trashed'=>false, 'web_view_link'=>'',
        );
    }
}
class PartialInventoryNormalizer {
    public function normalize_text($value) { return strtolower(trim((string) $value)); }
}
class PartialInventoryCurriculum {}
class PartialInventoryMapping {
    public function get_confirmed_mapping_for_scope($scope) {
        return (object) array('drive_folder_id'=>'arabic');
    }
}
class PartialInventoryRepository {
    public $partial_target;
    public $partial_scope;
    public $saved;
    private $source;
    public function __construct() {
        $this->source = (object) array(
            'id'=>7, 'run_uuid'=>'full-7', 'root_folder_id'=>'root', 'root_name'=>'Root',
            'root_config_hash'=>hash('sha256', json_encode(array('root_folder_id'=>'root','root_scope_level'=>'unknown','root_scope_id'=>0))),
        );
    }
    public function get_latest_completed_run() { return $this->source; }
    public function get_observation_by_drive_id($run_id, $id) {
        if ($id === 'arabic') return (object) array('drive_item_id'=>'arabic','item_type'=>'folder','item_name'=>'Arabic','path_snapshot'=>'Root/2026-2027/First Semester/Grade 5/Arabic');
        if ($id === 'grade-5') return (object) array('drive_item_id'=>'grade-5','item_type'=>'folder','item_name'=>'Grade 5','path_snapshot'=>'Root/2026-2027/First Semester/Grade 5');
        return null;
    }
    public function create_partial_run($source, $target, $hash, $scope) {
        $this->partial_target = $target;
        $this->partial_scope = $scope;
        return (object) array('id'=>8, 'run_uuid'=>'partial-8', 'status'=>'scanning');
    }
    public function upsert_observation($data) { $this->saved = $data; return true; }
    public function finish_run($id, $status, $summary) { return true; }
}
function assert_partial_inventory($condition, $message) {
    if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$repository = new PartialInventoryRepository();
$discovery = new Olama_Media_Drive_Discovery(
    new PartialInventoryDrive(), $repository, new PartialInventoryNormalizer(),
    new PartialInventoryCurriculum(), new PartialInventoryMapping()
);
$result = $discovery->start_scope(1, 2, 5, 9);
assert_partial_inventory(!is_wp_error($result), 'A confirmed selected subject should start a partial inventory.');
assert_partial_inventory($result->run_uuid === 'partial-8', 'The partial run should be returned to the batch UI.');
assert_partial_inventory($repository->partial_target['old_path'] === 'Root/2026-2027/First Semester/Grade 5/Arabic', 'The old subtree path must delimit observations being replaced.');
assert_partial_inventory($repository->partial_target['path'] === 'Root/2026-2027/First Semester/Grade 5/Arabic Renamed', 'A live folder rename must update the new subtree path.');
assert_partial_inventory($repository->saved['drive_item_id'] === 'arabic', 'Drive ID remains the stable identity after a rename.');
assert_partial_inventory($repository->partial_scope['subject_id'] === 9, 'The selected curriculum scope must be recorded in the run summary.');

$repository_source = file_get_contents(dirname(__DIR__) . '/includes/class-olama-media-drive-inventory-repository.php');
assert_partial_inventory(strpos($repository_source, "'run_type' => 'inventory_partial'") !== false, 'Partial runs must be distinguishable from full runs.');
assert_partial_inventory(strpos($repository_source, 'path_snapshot NOT LIKE') !== false, 'The previous selected subtree must not be copied into the merged snapshot.');
assert_partial_inventory(strpos($repository_source, 'authoritative_state_changed') !== false, 'Partial inventory must remain explicitly read-only.');

echo "Partial Drive inventory tests passed.\n";
