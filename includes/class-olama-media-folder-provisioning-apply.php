<?php
if (!defined('ABSPATH')) { exit; }

/** Executes one immutable, reviewed folder plan. It never moves, renames, or deletes Drive items. */
class Olama_Media_Folder_Provisioning_Apply
{
    const CONFIRMATION_PHRASE = 'CREATE REVIEWED FOLDERS';

    private $drive;
    private $inventory;
    private $mapping;
    private $normalizer;
    private $scope_lock_name = '';

    public function __construct($drive = null, $inventory = null, $mapping = null, $normalizer = null)
    {
        $this->drive = $drive;
        $this->inventory = $inventory ?: new Olama_Media_Drive_Inventory_Repository();
        $this->mapping = $mapping ?: new Olama_Media_Drive_Mapping();
        $this->normalizer = $normalizer ?: new Olama_Media_Normalizer();
    }

    public function readiness($plan_id)
    {
        global $wpdb;
        $plans = $wpdb->prefix . 'olama_drive_folder_plans';
        $nodes_table = $wpdb->prefix . 'olama_drive_folder_plan_nodes';
        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$plans} WHERE id=%d LIMIT 1", absint($plan_id)));
        if (!$plan) { return new WP_Error('folder_plan_not_found', __('The reviewed folder plan was not found.', 'olama-media-library')); }
        if ($plan->plan_status === 'completed') { return $this->completed_report($plan, true); }
        if ($plan->plan_status === 'applying' && !empty($plan->apply_started_at) && strtotime($plan->apply_started_at) < current_time('timestamp') - 900) {
            $wpdb->update($plans, array('plan_status'=>'partial_failed', 'apply_error'=>'Previous execution stopped before completion.', 'updated_at'=>current_time('mysql')), array('id'=>absint($plan->id), 'plan_status'=>'applying'));
            $plan->plan_status = 'partial_failed';
        }
        if (!in_array($plan->plan_status, array('ready_for_review', 'partial_failed'), true)) {
            return new WP_Error('folder_plan_not_ready', __('This folder plan is not ready for execution.', 'olama-media-library'));
        }
        if (absint($plan->conflict_count) || absint($plan->blocked_count)) {
            return new WP_Error('folder_plan_has_conflicts', __('Resolve every folder conflict before execution.', 'olama-media-library'));
        }
        if (!defined('OLAMA_MEDIA_REVIEWED_FOLDER_APPLY_ENABLED') || OLAMA_MEDIA_REVIEWED_FOLDER_APPLY_ENABLED !== true) {
            return new WP_Error('reviewed_folder_apply_disabled', __('Reviewed Drive folder plan execution is disabled.', 'olama-media-library'));
        }
        $run = $this->inventory->get_latest_completed_run();
        if (!$run || absint($run->id) !== absint($plan->discovery_run_id)) {
            return new WP_Error('folder_plan_inventory_stale', __('A newer Drive inventory exists. Create and review a new folder plan.', 'olama-media-library'));
        }
        if (!hash_equals((string) $plan->root_config_hash, (string) $run->root_config_hash) ||
            !hash_equals((string) $plan->root_config_hash, $this->current_root_config_hash())) {
            return new WP_Error('folder_plan_root_changed', __('The configured Drive root changed after this plan was created.', 'olama-media-library'));
        }
        $nodes = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$nodes_table} WHERE plan_id=%d ORDER BY id", absint($plan->id)));
        if (count($nodes) !== absint($plan->items_total)) {
            return new WP_Error('folder_plan_nodes_incomplete', __('The folder plan audit nodes are incomplete.', 'olama-media-library'));
        }
        if (!$this->verify_plan_hash($plan, $nodes)) {
            return new WP_Error('folder_plan_integrity_failed', __('The reviewed folder plan changed after it was generated. Create a new plan.', 'olama-media-library'));
        }
        foreach ($nodes as $node) {
            if (!in_array($node->planned_action, array('reuse', 'create'), true)) {
                return new WP_Error('folder_plan_node_unsafe', __('The folder plan contains an unsafe or unresolved node.', 'olama-media-library'));
            }
        }
        return array(
            'ready'=>true, 'already_applied'=>false, 'plan_id'=>absint($plan->id),
            'total'=>count($nodes), 'planned_create'=>absint($plan->create_count),
            'planned_reuse'=>absint($plan->existing_count), 'confirmation_phrase'=>self::CONFIRMATION_PHRASE,
            'drive_mutations_planned'=>absint($plan->create_count),
            'deletes'=>0, 'moves'=>0, 'renames'=>0,
        );
    }

    public function apply($plan_id, $confirmation_text)
    {
        global $wpdb;
        if (!hash_equals(self::CONFIRMATION_PHRASE, (string) $confirmation_text)) {
            return new WP_Error('folder_apply_confirmation_invalid', __('The folder creation confirmation text does not match.', 'olama-media-library'));
        }
        $ready = $this->readiness($plan_id);
        if (is_wp_error($ready) || !empty($ready['already_applied'])) { return $ready; }

        $plans = $wpdb->prefix . 'olama_drive_folder_plans';
        $nodes_table = $wpdb->prefix . 'olama_drive_folder_plan_nodes';
        $run_uuid = wp_generate_uuid4();
        $claimed = $wpdb->query($wpdb->prepare(
            "UPDATE {$plans} SET plan_status='applying',apply_run_uuid=%s,apply_started_at=%s,apply_error=NULL,applied_by=%d,updated_at=%s
             WHERE id=%d AND plan_status IN ('ready_for_review','partial_failed')",
            $run_uuid, current_time('mysql'), get_current_user_id(), current_time('mysql'), absint($plan_id)
        ));
        if ($claimed !== 1) { return new WP_Error('folder_plan_apply_locked', __('This folder plan is already being executed or is no longer available.', 'olama-media-library')); }

        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$plans} WHERE id=%d LIMIT 1", absint($plan_id)));
        $nodes = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$nodes_table} WHERE plan_id=%d ORDER BY id", absint($plan_id)));
        $this->scope_lock_name = 'olama_folder_' . substr(hash('sha256', (string) $plan->scope_key), 0, 40);
        $scope_lock = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $this->scope_lock_name));
        if ((int) $scope_lock !== 1) {
            $wpdb->update($plans, array('plan_status'=>'ready_for_review', 'apply_error'=>'Another folder plan is executing for this curriculum scope.', 'updated_at'=>current_time('mysql')), array('id'=>absint($plan->id)));
            $this->scope_lock_name = '';
            return new WP_Error('folder_scope_apply_locked', __('Another folder plan is currently executing for this curriculum scope.', 'olama-media-library'));
        }
        $drive = $this->drive ?: new Olama_Media_Drive();
        $root = $drive->test_connection();
        if (is_wp_error($root)) { return $this->fail($plan, null, $root); }
        if (!hash_equals((string) $plan->anchor_drive_folder_id, (string) ($root['id'] ?? ''))) {
            return $this->fail($plan, null, new WP_Error('folder_apply_root_mismatch', __('The live Drive root does not match the reviewed plan.', 'olama-media-library')));
        }

        $resolved = array('root'=>(string) $plan->anchor_drive_folder_id);
        $created = 0;
        $reused = 0;
        foreach ($nodes as $node) {
            if ($node->apply_status === 'completed' && (string) $node->resolved_drive_folder_id !== '') {
                $resolved[$node->node_key] = (string) $node->resolved_drive_folder_id;
                $node->resolution_type === 'created' ? $created++ : $reused++;
                continue;
            }
            $parent_id = $resolved[$node->parent_node_key] ?? '';
            if ($parent_id === '') {
                return $this->fail($plan, $node, new WP_Error('folder_apply_parent_missing', __('A parent Drive ID is missing from the reviewed execution chain.', 'olama-media-library')));
            }

            // When the configured root is itself this node, it was verified above.
            if ((string) $node->existing_drive_folder_id === (string) $plan->anchor_drive_folder_id && empty($node->parent_drive_folder_id)) {
                $result = array('id'=>(string) $plan->anchor_drive_folder_id, 'created'=>false);
            } else {
                $live = $this->inspect_live_child($drive, $parent_id, $node);
                if (is_wp_error($live)) { return $this->fail($plan, $node, $live); }
                if ($node->planned_action === 'reuse') {
                    if (count($live['exact']) !== 1 || !hash_equals((string) $node->existing_drive_folder_id, (string) $live['exact'][0]['id'])) {
                        return $this->fail($plan, $node, new WP_Error('folder_apply_reuse_changed', __('An existing folder changed after review. Run a new inventory and plan.', 'olama-media-library')));
                    }
                    $result = array('id'=>(string) $live['exact'][0]['id'], 'created'=>false);
                } elseif (count($live['exact']) === 1) {
                    // Idempotent retry or another operator created the exact child.
                    $result = array('id'=>(string) $live['exact'][0]['id'], 'created'=>false);
                } elseif (count($live['exact']) > 1 || !empty($live['similar'])) {
                    return $this->fail($plan, $node, new WP_Error('folder_apply_live_conflict', __('A duplicate or similar folder appeared after review. No new folder was created for this node.', 'olama-media-library')));
                } else {
                    $result = $drive->create_reviewed_folder($node->expected_name, $parent_id);
                    if (is_wp_error($result)) { return $this->fail($plan, $node, $result); }
                }
            }

            $folder_id = sanitize_text_field($result['id'] ?? '');
            if ($folder_id === '') { return $this->fail($plan, $node, new WP_Error('folder_apply_created_id_missing', __('Google Drive did not return a folder ID.', 'olama-media-library'))); }
            $resolution = !empty($result['created']) ? 'created' : 'reused';
            $checkpointed = $wpdb->update($nodes_table, array(
                'apply_status'=>'completed', 'resolved_drive_folder_id'=>$folder_id,
                'resolution_type'=>$resolution, 'apply_error'=>null, 'applied_at'=>current_time('mysql'),
            ), array('id'=>absint($node->id)));
            if ($checkpointed === false) {
                return $this->fail($plan, $node, new WP_Error('folder_apply_checkpoint_failed', __('The created Drive folder ID could not be checkpointed safely.', 'olama-media-library')));
            }
            $resolved[$node->node_key] = $folder_id;
            $resolution === 'created' ? $created++ : $reused++;
        }

        $subject_id = $resolved['subject'] ?? '';
        $mapping_id = $this->mapping->confirm_provisioned_subject(
            $plan->scope_key, $subject_id, $plan->root_config_hash, $plan->id
        );
        if (is_wp_error($mapping_id)) { return $this->fail($plan, null, $mapping_id); }

        $completed = $wpdb->update($plans, array(
            'plan_status'=>'completed', 'subject_mapping_id'=>absint($mapping_id),
            'subject_drive_folder_id'=>sanitize_text_field($subject_id),
            'applied_created_count'=>$created, 'applied_reused_count'=>$reused,
            'apply_error'=>null, 'apply_finished_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql'),
            'summary'=>wp_json_encode(array('created'=>$created, 'reused'=>$reused, 'deletes'=>0, 'moves'=>0, 'renames'=>0)),
        ), array('id'=>absint($plan->id)));
        if ($completed === false) {
            return $this->fail($plan, null, new WP_Error('folder_apply_completion_failed', __('The completed folder plan could not be finalized in WordPress.', 'olama-media-library')));
        }
        $plan = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$plans} WHERE id=%d LIMIT 1", absint($plan->id)));
        $report = $this->completed_report($plan, false);
        $this->release_scope_lock();
        return $report;
    }

    private function inspect_live_child($drive, $parent_id, $node)
    {
        $folders = array();
        $token = '';
        $seen_tokens = array();
        $pages = 0;
        do {
            if ($pages++ >= 100 || isset($seen_tokens[$token])) {
                return new WP_Error('folder_apply_pagination_invalid', __('Drive folder verification returned an invalid pagination sequence.', 'olama-media-library'));
            }
            $seen_tokens[$token] = true;
            $page = $drive->list_folder_children_page($parent_id, $token, 200);
            if (is_wp_error($page)) { return $page; }
            foreach ((array) ($page['items'] ?? array()) as $item) {
                if (($item['mime_type'] ?? '') === 'application/vnd.google-apps.folder' && empty($item['trashed'])) { $folders[] = $item; }
            }
            $token = (string) ($page['next_page_token'] ?? '');
        } while ($token !== '');

        $exact = array();
        $similar = array();
        $expected = $this->normalizer->normalize_text($node->expected_name);
        foreach ($folders as $folder) {
            $actual = $this->normalizer->normalize_text($folder['name'] ?? '');
            if ($this->node_name_matches($node->node_type, $expected, $actual)) {
                $exact[] = $folder;
            } elseif ($this->names_are_similar($expected, $actual)) {
                $similar[] = $folder;
            }
        }
        return array('exact'=>$exact, 'similar'=>$similar);
    }

    private function verify_plan_hash($plan, $nodes)
    {
        $items = array();
        foreach ($nodes as $node) {
            $candidate_ids = json_decode((string) $node->candidate_drive_folder_ids, true);
            $candidate_names = json_decode((string) $node->candidate_names, true);
            $reasons = json_decode((string) $node->reasons, true);
            $items[] = array(
                'node_key'=>(string) $node->node_key, 'node_type'=>(string) $node->node_type,
                'entity_id'=>absint($node->curriculum_entity_id), 'unit_id'=>absint($node->unit_id),
                'unit_number'=>(string) $node->unit_number, 'expected_name'=>(string) $node->expected_name,
                'normalized_name'=>(string) $node->normalized_name, 'planned_action'=>(string) $node->planned_action,
                'parent_node_key'=>(string) $node->parent_node_key,
                'parent_drive_folder_id'=>(string) $node->parent_drive_folder_id,
                'existing_drive_folder_id'=>(string) $node->existing_drive_folder_id,
                'candidate_drive_folder_ids'=>is_array($candidate_ids) ? array_values($candidate_ids) : array(),
                'candidate_names'=>is_array($candidate_names) ? array_values($candidate_names) : array(),
                'path_snapshot'=>(string) $node->path_snapshot,
                'reason'=>(string) ($reasons['reason'] ?? ''),
            );
        }
        $hash = hash('sha256', wp_json_encode(array(
            'run_id'=>absint($plan->discovery_run_id), 'scope_key'=>(string) $plan->scope_key,
            'root_id'=>(string) $plan->anchor_drive_folder_id, 'items'=>$items,
        )));
        return hash_equals((string) $plan->plan_hash, $hash);
    }

    private function node_name_matches($node_type, $expected, $actual)
    {
        if ($expected !== '' && ($expected === $actual || str_replace(' ', '', $expected) === str_replace(' ', '', $actual))) { return true; }
        return $node_type === 'grade' && $this->grade_number($expected) !== null && $this->grade_number($expected) === $this->grade_number($actual);
    }

    private function grade_number($name)
    {
        if (preg_match('/(?:^| )([0-9]{1,2})(?: |$)/u', $name, $match)) { return (int) $match[1]; }
        $ordinals = array(1=>array('اول','الاول'),2=>array('ثاني','الثاني'),3=>array('ثالث','الثالث'),4=>array('رابع','الرابع'),5=>array('خامس','الخامس'),6=>array('سادس','السادس'),7=>array('سابع','السابع'),8=>array('ثامن','الثامن'),9=>array('تاسع','التاسع'),10=>array('عاشر','العاشر'),11=>array('حادي عشر','الحادي عشر'),12=>array('ثاني عشر','الثاني عشر'));
        foreach ($ordinals as $number => $aliases) {
            foreach ($aliases as $alias) { if (preg_match('/(?:^| )' . preg_quote($alias, '/') . '(?: |$)/u', $name)) { return $number; } }
        }
        return null;
    }

    private function names_are_similar($expected, $actual)
    {
        if ($expected === '' || $actual === '') { return false; }
        if (strpos($expected, $actual) !== false || strpos($actual, $expected) !== false) { return true; }
        $a = array_values(array_unique(array_filter(explode(' ', $expected), function ($token) { return strlen($token) > 2; })));
        $b = array_values(array_unique(array_filter(explode(' ', $actual), function ($token) { return strlen($token) > 2; })));
        if (!$a || !$b) { return false; }
        $shared = count(array_intersect($a, $b));
        return $shared >= 2 && ($shared / max(count($a), count($b))) >= 0.5;
    }

    private function fail($plan, $node, $error)
    {
        global $wpdb;
        $message = $error->get_error_message();
        if ($node) {
            $wpdb->update($wpdb->prefix . 'olama_drive_folder_plan_nodes', array('apply_status'=>'failed', 'apply_error'=>$message), array('id'=>absint($node->id)));
        }
        $wpdb->update($wpdb->prefix . 'olama_drive_folder_plans', array(
            'plan_status'=>'partial_failed', 'apply_error'=>$message,
            'apply_finished_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql'),
        ), array('id'=>absint($plan->id)));
        $this->release_scope_lock();
        return $error;
    }

    private function release_scope_lock()
    {
        global $wpdb;
        if ($this->scope_lock_name !== '') {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $this->scope_lock_name));
            $this->scope_lock_name = '';
        }
    }

    private function completed_report($plan, $already_applied)
    {
        return array(
            'status'=>'completed', 'already_applied'=>(bool) $already_applied,
            'plan_id'=>absint($plan->id), 'run_uuid'=>(string) $plan->apply_run_uuid,
            'subject_mapping_id'=>absint($plan->subject_mapping_id),
            'subject_drive_folder_id'=>(string) $plan->subject_drive_folder_id,
            'created'=>absint($plan->applied_created_count), 'reused'=>absint($plan->applied_reused_count),
            'deletes'=>0, 'moves'=>0, 'renames'=>0,
            'inventory_refresh_required'=>true, 'drive_mutations'=>absint($plan->applied_created_count),
        );
    }

    private function current_root_config_hash()
    {
        $settings = get_option('academy_media_library_settings', array());
        return hash('sha256', wp_json_encode(array(
            'root_folder_id'=>sanitize_text_field($settings['root_folder_id'] ?? ''),
            'root_scope_level'=>sanitize_key($settings['root_scope_level'] ?? 'unknown'),
            'root_scope_id'=>absint($settings['root_scope_id'] ?? 0),
        )));
    }
}
