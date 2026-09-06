<?php
if (!defined('ABSPATH')) { exit; }

/** Builds a WordPress-only curriculum folder tree plan from a completed Drive inventory. */
class Olama_Media_Folder_Provisioning
{
    private $inventory;
    private $mapping;
    private $normalizer;
    private $curriculum;

    public function __construct($inventory = null, $mapping = null, $normalizer = null, $curriculum = null)
    {
        $this->inventory = $inventory ?: new Olama_Media_Drive_Inventory_Repository();
        $this->mapping = $mapping ?: new Olama_Media_Drive_Mapping();
        $this->normalizer = $normalizer ?: new Olama_Media_Normalizer();
        $this->curriculum = $curriculum ?: new Olama_Media_Curriculum_Adapter();
    }

    /** Backward-compatible entry point for an already confirmed subject. */
    public function preview($mapping_id)
    {
        $mapping = $this->mapping->get_confirmed_mapping_by_id($mapping_id);
        if (!$mapping) { return new WP_Error('folder_plan_mapping_required', __('A confirmed subject Drive mapping is required.', 'olama-media-library')); }
        return $this->preview_scope($mapping->academic_year_id, $mapping->semester_id, $mapping->grade_id, $mapping->subject_id);
    }

    /** Plan the complete year/semester/grade/subject/unit branch without changing Drive. */
    public function preview_scope($academic_year_id, $semester_id, $grade_id, $subject_id)
    {
        global $wpdb;
        $ids = array_map('absint', array($academic_year_id, $semester_id, $grade_id, $subject_id));
        if (in_array(0, $ids, true)) { return new WP_Error('invalid_scope', __('Select the academic year, semester, grade, and subject.', 'olama-media-library')); }

        $run = $this->inventory->get_latest_completed_run();
        if (!$run) { return new WP_Error('inventory_required', __('Complete a Drive inventory before creating a folder plan.', 'olama-media-library')); }
        if (!hash_equals((string) $run->root_config_hash, $this->current_root_config_hash())) {
            return new WP_Error('folder_plan_inventory_mismatch', __('The latest inventory belongs to an older Drive root configuration. Run a new inventory first.', 'olama-media-library'));
        }

        $names = $this->curriculum->get_names($ids[0], $ids[1], $ids[2], $ids[3]);
        foreach (array('academic_year', 'semester', 'grade', 'subject') as $required_name) {
            if (trim((string) ($names[$required_name] ?? '')) === '') {
                return new WP_Error('folder_plan_curriculum_name_missing', __('A required curriculum folder name is missing.', 'olama-media-library'));
            }
        }
        $units = (new Olama_Media_DB())->get_curriculum_with_assets($ids[0], $ids[1], $ids[2], $ids[3]);
        if (is_wp_error($units)) { return $units; }
        if (!$units) { return new WP_Error('folder_plan_curriculum_empty', __('No curriculum units exist for the selected subject.', 'olama-media-library')); }

        $scope_key = sprintf('subject:%d:%d:%d:%d', $ids[0], $ids[1], $ids[2], $ids[3]);
        $mapping = $this->mapping->get_confirmed_mapping_for_scope($scope_key);
        $observations = $this->inventory->get_all_observations($run->id);
        $children = array();
        foreach ($observations as $observation) {
            if ($observation->item_type !== 'folder') { continue; }
            $children[(string) $observation->parent_drive_folder_id][] = $observation;
        }

        $specs = array(
            array('node_key'=>'academic_year', 'node_type'=>'academic_year', 'entity_id'=>$ids[0], 'expected_name'=>sanitize_text_field($names['academic_year'])),
            array('node_key'=>'semester', 'node_type'=>'semester', 'entity_id'=>$ids[1], 'expected_name'=>sanitize_text_field($names['semester'])),
            array('node_key'=>'grade', 'node_type'=>'grade', 'entity_id'=>$ids[2], 'expected_name'=>sanitize_text_field($names['grade'])),
            array('node_key'=>'subject', 'node_type'=>'subject', 'entity_id'=>$ids[3], 'expected_name'=>sanitize_text_field($names['subject'])),
        );

        $items = array();
        $counts = array('existing'=>0, 'create'=>0, 'conflict'=>0, 'blocked'=>0);
        $parent_drive_id = (string) $run->root_folder_id;
        $parent_node_key = 'root';
        $path = sanitize_text_field($run->root_name);
        $upstream_conflict = false;
        $start_index = 0;

        // A configured root may itself be a curriculum level. Never recreate
        // its ancestors underneath it.
        foreach ($specs as $index => $spec) {
            if ($this->node_name_matches($spec, (string) $run->root_name)) {
                $items[] = $this->make_item($spec, 'reuse', '', 'root', (string) $run->root_folder_id, array(), $path, 'configured_root_matches_node');
                $counts['existing']++;
                $parent_node_key = $spec['node_key'];
                $start_index = $index + 1;
                break;
            }
        }

        for ($index = $start_index; $index < count($specs); $index++) {
            $spec = $specs[$index];
            $planned = $this->plan_child($spec, $parent_drive_id, $parent_node_key, $path, $children, $upstream_conflict);
            $items[] = $planned;
            $counts[$planned['planned_action']]++;
            $path = $planned['path_snapshot'];
            $parent_node_key = $spec['node_key'];
            if ($planned['planned_action'] === 'reuse') {
                $parent_drive_id = $planned['existing_drive_folder_id'];
            } elseif ($planned['planned_action'] === 'create') {
                $parent_drive_id = '';
            } else {
                $parent_drive_id = '';
                $upstream_conflict = true;
            }
        }

        // A previously confirmed subject must agree with the exact hierarchy.
        $subject_item_index = $this->find_item_index($items, 'subject');
        if ($mapping && $subject_item_index !== null) {
            $subject_item = $items[$subject_item_index];
            if ($subject_item['planned_action'] !== 'reuse' || !hash_equals((string) $mapping->drive_folder_id, (string) $subject_item['existing_drive_folder_id'])) {
                $counts[$subject_item['planned_action']]--;
                $counts['conflict']++;
                $subject_item['planned_action'] = 'conflict';
                $subject_item['reason'] = 'confirmed_subject_mapping_mismatch';
                $subject_item['candidate_drive_folder_ids'][] = (string) $mapping->drive_folder_id;
                $items[$subject_item_index] = $subject_item;
                $upstream_conflict = true;
            }
        }

        $subject_path = $subject_item_index !== null ? $items[$subject_item_index]['path_snapshot'] : $path;
        $subject_drive_id = ($subject_item_index !== null && $items[$subject_item_index]['planned_action'] === 'reuse')
            ? $items[$subject_item_index]['existing_drive_folder_id'] : '';
        foreach ($units as $unit) {
            $spec = array(
                'node_key'=>'unit:' . absint($unit->id), 'node_type'=>'unit', 'entity_id'=>absint($unit->id),
                'unit_id'=>absint($unit->id), 'unit_number'=>sanitize_text_field($unit->unit_number),
                'expected_name'=>sanitize_text_field($unit->unit_name),
            );
            $planned = $this->plan_child($spec, $subject_drive_id, 'subject', $subject_path, $children, $upstream_conflict);
            $items[] = $planned;
            $counts[$planned['planned_action']]++;
        }

        $plan_hash = hash('sha256', wp_json_encode(array(
            'run_id'=>absint($run->id), 'scope_key'=>$scope_key, 'root_id'=>(string) $run->root_folder_id, 'items'=>$items,
        )));
        $plans = $wpdb->prefix . 'olama_drive_folder_plans';
        $nodes = $wpdb->prefix . 'olama_drive_folder_plan_nodes';
        $mapping_id = $mapping ? absint($mapping->id) : 0;
        $existing_plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$plans} WHERE scope_key=%s AND discovery_run_id=%d AND plan_hash=%s LIMIT 1",
            $scope_key, absint($run->id), $plan_hash
        ));
        if ($existing_plan) { return $this->report($existing_plan, $items, $counts, $mapping_id, $scope_key); }

        if (false === $wpdb->query('START TRANSACTION')) {
            return new WP_Error('folder_plan_transaction_failed', __('Could not start the folder planning transaction.', 'olama-media-library'));
        }
        try {
            $now = current_time('mysql');
            $uuid = wp_generate_uuid4();
            $status = $counts['conflict'] ? 'blocked' : 'ready_for_review';
            if (!$wpdb->insert($plans, array(
                'plan_uuid'=>$uuid, 'discovery_run_id'=>absint($run->id), 'subject_mapping_id'=>$mapping_id,
                'scope_key'=>$scope_key, 'academic_year_id'=>$ids[0], 'semester_id'=>$ids[1], 'grade_id'=>$ids[2], 'subject_id'=>$ids[3],
                'anchor_drive_folder_id'=>sanitize_text_field($run->root_folder_id), 'subject_drive_folder_id'=>sanitize_text_field($subject_drive_id),
                'root_config_hash'=>sanitize_text_field($run->root_config_hash), 'plan_hash'=>$plan_hash,
                'plan_status'=>$status, 'items_total'=>count($items), 'existing_count'=>$counts['existing'],
                'create_count'=>$counts['create'], 'conflict_count'=>$counts['conflict'], 'blocked_count'=>$counts['blocked'],
                'summary'=>wp_json_encode(array('drive_mutations'=>0)), 'created_by'=>get_current_user_id(),
                'created_at'=>$now, 'updated_at'=>$now,
            ))) { throw new RuntimeException('Could not save the folder provisioning plan.'); }
            $plan_id = absint($wpdb->insert_id);
            foreach ($items as $item) {
                if (!$wpdb->insert($nodes, array(
                    'plan_id'=>$plan_id, 'node_key'=>$item['node_key'], 'node_type'=>$item['node_type'],
                    'parent_node_key'=>$item['parent_node_key'], 'curriculum_entity_id'=>$item['entity_id'],
                    'unit_id'=>$item['unit_id'] ?: null, 'unit_number'=>$item['unit_number'],
                    'expected_name'=>$item['expected_name'], 'normalized_name'=>$item['normalized_name'],
                    'planned_action'=>$item['planned_action'], 'parent_drive_folder_id'=>$item['parent_drive_folder_id'] ?: null,
                    'existing_drive_folder_id'=>$item['existing_drive_folder_id'] ?: null,
                    'candidate_drive_folder_ids'=>wp_json_encode($item['candidate_drive_folder_ids']),
                    'candidate_names'=>wp_json_encode($item['candidate_names']), 'path_snapshot'=>$item['path_snapshot'],
                    'reasons'=>wp_json_encode(array('reason'=>$item['reason'])), 'created_at'=>$now,
                ))) { throw new RuntimeException('Could not save a folder provisioning plan node.'); }
            }
            if (false === $wpdb->query('COMMIT')) { throw new RuntimeException('Could not commit the folder provisioning plan.'); }
            $plan = (object) array('id'=>$plan_id, 'plan_uuid'=>$uuid, 'plan_status'=>$status);
            return $this->report($plan, $items, $counts, $mapping_id, $scope_key);
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('folder_plan_failed', $error->getMessage());
        }
    }

    private function plan_child($spec, $parent_drive_id, $parent_node_key, $parent_path, $children, $upstream_conflict)
    {
        $expected = sanitize_text_field($spec['expected_name']);
        $path = rtrim((string) $parent_path, '/') . '/' . $expected;
        if ($expected === '' || $this->normalizer->normalize_text($expected) === '') {
            return $this->make_item($spec, 'conflict', $parent_drive_id, $parent_node_key, '', array(), $path, 'invalid_curriculum_folder_name');
        }
        if ($upstream_conflict) {
            return $this->make_item($spec, 'blocked', '', $parent_node_key, '', array(), $path, 'blocked_by_parent_conflict');
        }
        if ($parent_drive_id === '') {
            return $this->make_item($spec, 'create', '', $parent_node_key, '', array(), $path, 'parent_will_be_created');
        }

        $siblings = $children[(string) $parent_drive_id] ?? array();
        $exact = array_values(array_filter($siblings, function ($folder) use ($spec) {
            return $this->node_name_matches($spec, (string) $folder->item_name);
        }));
        $similar = array();
        if (!$exact) {
            $normalized = $this->normalizer->normalize_text($expected);
            $similar = array_values(array_filter($siblings, function ($folder) use ($normalized) {
                return $this->names_are_similar($normalized, $this->normalizer->normalize_text($folder->item_name));
            }));
        }
        if (count($exact) === 1) {
            $existing_path = rtrim((string) $parent_path, '/') . '/' . sanitize_text_field($exact[0]->item_name);
            return $this->make_item($spec, 'reuse', $parent_drive_id, $parent_node_key, (string) $exact[0]->drive_item_id, $exact, $existing_path, 'exact_or_canonical_name');
        }
        if (count($exact) > 1) {
            return $this->make_item($spec, 'conflict', $parent_drive_id, $parent_node_key, '', $exact, $path, 'duplicate_exact_sibling_folders');
        }
        if ($similar) {
            return $this->make_item($spec, 'conflict', $parent_drive_id, $parent_node_key, '', $similar, $path, 'possible_existing_folder_requires_review');
        }
        return $this->make_item($spec, 'create', $parent_drive_id, $parent_node_key, '', array(), $path, 'no_existing_sibling_candidate');
    }

    private function make_item($spec, $action, $parent_drive_id, $parent_node_key, $existing_id, $candidates, $path, $reason)
    {
        return array(
            'node_key'=>(string) $spec['node_key'], 'node_type'=>(string) $spec['node_type'],
            'entity_id'=>absint($spec['entity_id']), 'unit_id'=>absint($spec['unit_id'] ?? 0),
            'unit_number'=>sanitize_text_field($spec['unit_number'] ?? ''),
            'expected_name'=>sanitize_text_field($spec['expected_name']),
            'normalized_name'=>$this->normalizer->normalize_text($spec['expected_name']),
            'planned_action'=>$action, 'parent_node_key'=>$parent_node_key,
            'parent_drive_folder_id'=>(string) $parent_drive_id, 'existing_drive_folder_id'=>(string) $existing_id,
            'candidate_drive_folder_ids'=>array_map(function ($folder) { return (string) $folder->drive_item_id; }, $candidates),
            'candidate_names'=>array_map(function ($folder) { return (string) $folder->item_name; }, $candidates),
            'path_snapshot'=>$path, 'reason'=>$reason,
        );
    }

    private function node_name_matches($spec, $actual)
    {
        $expected = $this->normalizer->normalize_text($spec['expected_name']);
        $actual = $this->normalizer->normalize_text($actual);
        if ($expected !== '' && ($expected === $actual || str_replace(' ', '', $expected) === str_replace(' ', '', $actual))) { return true; }
        return $spec['node_type'] === 'grade' && $this->grade_number($expected) !== null
            && $this->grade_number($expected) === $this->grade_number($actual);
    }

    private function grade_number($name)
    {
        if (preg_match('/(?:^| )([0-9]{1,2})(?: |$)/u', $name, $match)) { return (int) $match[1]; }
        $ordinals = array(
            1=>array('اول','الاول'), 2=>array('ثاني','الثاني'), 3=>array('ثالث','الثالث'),
            4=>array('رابع','الرابع'), 5=>array('خامس','الخامس'), 6=>array('سادس','السادس'),
            7=>array('سابع','السابع'), 8=>array('ثامن','الثامن'), 9=>array('تاسع','التاسع'),
            10=>array('عاشر','العاشر'), 11=>array('حادي عشر','الحادي عشر'), 12=>array('ثاني عشر','الثاني عشر'),
        );
        foreach ($ordinals as $number => $aliases) {
            foreach ($aliases as $alias) {
                if (preg_match('/(?:^| )' . preg_quote($alias, '/') . '(?: |$)/u', $name)) { return $number; }
            }
        }
        return null;
    }

    private function names_are_similar($expected, $actual)
    {
        if ($expected === '' || $actual === '') { return false; }
        if (strpos($expected, $actual) !== false || strpos($actual, $expected) !== false) { return true; }
        $expected_tokens = array_values(array_unique(array_filter(explode(' ', $expected), function ($token) { return strlen($token) > 2; })));
        $actual_tokens = array_values(array_unique(array_filter(explode(' ', $actual), function ($token) { return strlen($token) > 2; })));
        if (!$expected_tokens || !$actual_tokens) { return false; }
        $shared = count(array_intersect($expected_tokens, $actual_tokens));
        return $shared >= 2 && ($shared / max(count($expected_tokens), count($actual_tokens))) >= 0.5;
    }

    private function find_item_index($items, $node_type)
    {
        foreach ($items as $index => $item) { if ($item['node_type'] === $node_type) { return $index; } }
        return null;
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

    private function report($plan, $items, $counts, $mapping_id, $scope_key)
    {
        return array(
            'plan_id'=>absint($plan->id), 'plan_uuid'=>(string) $plan->plan_uuid,
            'status'=>(string) $plan->plan_status, 'scope_key'=>$scope_key,
            'subject_mapping_id'=>absint($mapping_id), 'subject_mapping_required'=>!$mapping_id,
            'total'=>count($items), 'existing'=>$counts['existing'], 'create'=>$counts['create'],
            'conflicts'=>$counts['conflict'], 'blocked'=>$counts['blocked'], 'items'=>$items,
            'ready_for_review'=>$counts['conflict'] === 0 && $counts['blocked'] === 0,
            'ready_for_reconciliation'=>boolval($mapping_id) && $counts['conflict'] === 0 && $counts['blocked'] === 0,
            'authoritative_state_changed'=>false, 'drive_mutations'=>0,
        );
    }
}
