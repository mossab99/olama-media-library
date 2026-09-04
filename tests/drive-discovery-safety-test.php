<?php
define('ABSPATH', __DIR__ . '/');
define('ARRAY_A', 'ARRAY_A');

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
function esc_url_raw($value) { return (string) $value; }
function wp_json_encode($value) { return json_encode($value); }
function current_time($type) { return $type === 'timestamp' ? time() : '2026-09-05 12:00:00'; }
function get_option($name, $default = array()) { return $default; }
function __($text) { return $text; }

require_once dirname(__DIR__) . '/includes/class-olama-media-drive-discovery.php';

class DiscoveryTestNormalizer {
    public function normalize_text($value) { return strtolower(trim((string) $value)); }
}

class DiscoveryTestDrive {
    public $calls = 0;
    public function test_connection() { return array('id' => 'root', 'name' => 'Root'); }
    public function get_root_folder_id() { return 'root'; }
    public function list_folder_children_page($folder_id, $page_token, $page_size) {
        $this->calls++;
        if ($folder_id === 'root') {
            return array('next_page_token' => '', 'items' => array(
                array('id'=>'folder-a','name'=>'Arabic','mime_type'=>'application/vnd.google-apps.folder','size'=>0,'parents'=>array('root'),'drive_id'=>'','modified_time'=>'','trashed'=>false,'web_view_link'=>'','shortcut_target_id'=>'','shortcut_target_mime_type'=>''),
                array('id'=>'shortcut-a','name'=>'Outside','mime_type'=>'application/vnd.google-apps.shortcut','size'=>0,'parents'=>array('root'),'drive_id'=>'','modified_time'=>'','trashed'=>false,'web_view_link'=>'','shortcut_target_id'=>'external-folder','shortcut_target_mime_type'=>'application/vnd.google-apps.folder'),
            ));
        }
        return array('next_page_token' => '', 'items' => array());
    }
}

class DiscoveryTestRepository {
    public $run;
    public $queue = array();
    public $observations = array();
    public $finished_status = '';
    public function __construct() {
        $rootHash = hash('sha256', json_encode(array('root_folder_id'=>'root','root_scope_level'=>'unknown','root_scope_id'=>0)));
        $this->run = (object) array('id'=>1,'run_uuid'=>'run-1','status'=>'scanning','root_config_hash'=>$rootHash);
        $this->queue[] = (object) array('id'=>1,'drive_folder_id'=>'root','path_snapshot'=>'Root','depth'=>0,'page_token'=>'','status'=>'pending');
    }
    public function get_run_by_uuid($uuid) { $this->run->status = $this->finished_status ?: 'scanning'; return $uuid === 'run-1' ? $this->run : null; }
    public function claim_next_queue_item($run_id) {
        foreach ($this->queue as $item) { if ($item->status === 'pending') { $item->status = 'processing'; return $item; } }
        return null;
    }
    public function upsert_observation($data) { $this->observations[$data['drive_item_id']] = $data; return true; }
    public function enqueue_folder($run_id, $folder_id, $parent_id, $path, $depth) {
        $this->queue[] = (object) array('id'=>count($this->queue)+1,'drive_folder_id'=>$folder_id,'path_snapshot'=>$path,'depth'=>$depth,'page_token'=>'','status'=>'pending'); return true;
    }
    public function refresh_run_counts($run_id) { return true; }
    public function finish_queue_page($id, $token) { foreach ($this->queue as $item) { if ($item->id === $id) { $item->status = $token ? 'pending' : 'completed'; } } return true; }
    public function fail_queue_item($id, $message) { return true; }
    public function pending_count($run_id) { return count(array_filter($this->queue, function ($item) { return in_array($item->status, array('pending','processing'), true); })); }
    public function finish_run($run_id, $status, $summary = array()) { $this->finished_status = $status; return true; }
    public function report($run_id) { return array('run'=>(array) $this->run,'queue'=>array(),'duplicate_sibling_folders'=>array()); }
}

function assert_true($condition, $message) { if (!$condition) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); } }

$drive = new DiscoveryTestDrive();
$repository = new DiscoveryTestRepository();
$discovery = new Olama_Media_Drive_Discovery($drive, $repository, new DiscoveryTestNormalizer());
$result = $discovery->batch('run-1', 5, 50);
assert_true(!is_wp_error($result), 'Discovery batch should complete.');
assert_true(isset($repository->observations['shortcut-a']), 'Shortcut must be recorded.');
assert_true(count(array_filter($repository->queue, function ($item) { return $item->drive_folder_id === 'external-folder'; })) === 0, 'Shortcut target must never be traversed.');
assert_true($drive->calls === 2, 'Only the root and normal child folder should be traversed.');
assert_true($repository->finished_status === 'completed', 'Run should complete after the persistent queue drains.');

$discovery_source = file_get_contents(dirname(__DIR__) . '/includes/class-olama-media-drive-discovery.php');
foreach (array('files->create', 'files->update', 'files->delete', 'permissions->create') as $mutation) {
    assert_true(strpos($discovery_source, $mutation) === false, "Discovery must not contain Drive mutation {$mutation}.");
}
echo "Drive discovery safety tests passed.\n";
