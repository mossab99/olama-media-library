<?php
if (!defined('ABSPATH')) { exit; }

/** Builds a WordPress-only folder provisioning plan from a completed Drive inventory. */
class Olama_Media_Folder_Provisioning
{
    private $inventory;
    private $mapping;
    private $normalizer;

    public function __construct($inventory = null, $mapping = null, $normalizer = null)
    {
        $this->inventory = $inventory ?: new Olama_Media_Drive_Inventory_Repository();
        $this->mapping = $mapping ?: new Olama_Media_Drive_Mapping();
        $this->normalizer = $normalizer ?: new Olama_Media_Normalizer();
    }

    public function preview($mapping_id)
    {
        global $wpdb;
        $mapping = $this->mapping->get_confirmed_mapping_by_id($mapping_id);
        if (!$mapping) { return new WP_Error('folder_plan_mapping_required', __('A confirmed subject Drive mapping is required.', 'olama-media-library')); }
        $run = $this->inventory->get_latest_completed_run();
        if (!$run || !hash_equals((string) $mapping->root_config_hash, (string) $run->root_config_hash)) {
            return new WP_Error('folder_plan_inventory_mismatch', __('The confirmed mapping and latest inventory do not belong to the same Drive root generation.', 'olama-media-library'));
        }
        $units = (new Olama_Media_DB())->get_curriculum_with_assets(
            $mapping->academic_year_id, $mapping->semester_id, $mapping->grade_id, $mapping->subject_id
        );
        if (is_wp_error($units)) { return $units; }
        if (!$units) { return new WP_Error('folder_plan_curriculum_empty', __('No curriculum units exist for the selected subject.', 'olama-media-library')); }

        $observations = $this->inventory->get_all_observations($run->id);
        $subject = null;
        $children = array();
        foreach ($observations as $observation) {
            if ((string) $observation->drive_item_id === (string) $mapping->drive_folder_id && $observation->item_type === 'folder') {
                $subject = $observation;
            }
            if ($observation->item_type === 'folder' && (string) $observation->parent_drive_folder_id === (string) $mapping->drive_folder_id) {
                $children[] = $observation;
            }
        }
        if (!$subject) { return new WP_Error('folder_plan_subject_not_observed', __('The confirmed subject folder is missing from the latest inventory.', 'olama-media-library')); }

        $items = array();
        $counts = array('existing'=>0, 'create'=>0, 'conflict'=>0);
        foreach ($units as $unit) {
            $expected = sanitize_text_field($unit->unit_name);
            $normalized = $this->normalizer->normalize_text($expected);
            if ($expected === '' || $normalized === '') {
                $counts['conflict']++;
                $items[] = array(
                    'unit_id'=>absint($unit->id), 'unit_number'=>sanitize_text_field($unit->unit_number),
                    'expected_name'=>$expected, 'normalized_name'=>$normalized, 'planned_action'=>'conflict',
                    'parent_drive_folder_id'=>(string) $mapping->drive_folder_id, 'existing_drive_folder_id'=>'',
                    'candidate_drive_folder_ids'=>array(), 'candidate_names'=>array(),
                    'path_snapshot'=>rtrim((string) $subject->path_snapshot, '/') . '/' . $expected,
                    'reason'=>'invalid_curriculum_unit_name',
                );
                continue;
            }
            $exact = array_values(array_filter($children, function ($folder) use ($normalized) {
                return $this->normalizer->normalize_text($folder->item_name) === $normalized;
            }));
            $similar = array();
            if (!$exact) {
                $similar = array_values(array_filter($children, function ($folder) use ($normalized) {
                    return $this->names_are_similar($normalized, $this->normalizer->normalize_text($folder->item_name));
                }));
            }

            if (count($exact) === 1) {
                $action = 'reuse';
                $candidates = $exact;
                $counts['existing']++;
                $reason = 'exact_normalized_name';
            } elseif (count($exact) > 1) {
                $action = 'conflict';
                $candidates = $exact;
                $counts['conflict']++;
                $reason = 'duplicate_exact_sibling_folders';
            } elseif ($similar) {
                $action = 'conflict';
                $candidates = $similar;
                $counts['conflict']++;
                $reason = 'possible_existing_folder_requires_review';
            } else {
                $action = 'create';
                $candidates = array();
                $counts['create']++;
                $reason = 'no_existing_sibling_candidate';
            }

            $items[] = array(
                'unit_id'=>absint($unit->id), 'unit_number'=>sanitize_text_field($unit->unit_number),
                'expected_name'=>$expected, 'normalized_name'=>$normalized, 'planned_action'=>$action,
                'parent_drive_folder_id'=>(string) $mapping->drive_folder_id,
                'existing_drive_folder_id'=>count($candidates) === 1 ? (string) $candidates[0]->drive_item_id : '',
                'candidate_drive_folder_ids'=>array_map(function ($folder) { return (string) $folder->drive_item_id; }, $candidates),
                'candidate_names'=>array_map(function ($folder) { return (string) $folder->item_name; }, $candidates),
                'path_snapshot'=>rtrim((string) $subject->path_snapshot, '/') . '/' . $expected,
                'reason'=>$reason,
            );
        }

        $plan_hash = hash('sha256', wp_json_encode(array(
            'run_id'=>absint($run->id), 'mapping_id'=>absint($mapping->id),
            'parent_id'=>(string) $mapping->drive_folder_id, 'items'=>$items,
        )));
        $plans = $wpdb->prefix . 'olama_drive_folder_plans';
        $plan_items = $wpdb->prefix . 'olama_drive_folder_plan_items';
        $existing_plan = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$plans} WHERE subject_mapping_id=%d AND discovery_run_id=%d AND plan_hash=%s LIMIT 1",
            absint($mapping->id), absint($run->id), $plan_hash
        ));
        if ($existing_plan) { return $this->report($existing_plan, $items, $counts); }

        if (false === $wpdb->query('START TRANSACTION')) {
            return new WP_Error('folder_plan_transaction_failed', __('Could not start the folder planning transaction.', 'olama-media-library'));
        }
        try {
            $now = current_time('mysql');
            $uuid = wp_generate_uuid4();
            $status = $counts['conflict'] ? 'blocked' : 'ready_for_review';
            if (!$wpdb->insert($plans, array(
                'plan_uuid'=>$uuid, 'discovery_run_id'=>absint($run->id), 'subject_mapping_id'=>absint($mapping->id),
                'subject_drive_folder_id'=>sanitize_text_field($mapping->drive_folder_id),
                'root_config_hash'=>sanitize_text_field($run->root_config_hash), 'plan_hash'=>$plan_hash,
                'plan_status'=>$status, 'items_total'=>count($items), 'existing_count'=>$counts['existing'],
                'create_count'=>$counts['create'], 'conflict_count'=>$counts['conflict'],
                'summary'=>wp_json_encode(array('drive_mutations'=>0)), 'created_by'=>get_current_user_id(),
                'created_at'=>$now, 'updated_at'=>$now,
            ))) { throw new RuntimeException('Could not save the folder provisioning plan.'); }
            $plan_id = absint($wpdb->insert_id);
            foreach ($items as $item) {
                if (!$wpdb->insert($plan_items, array(
                    'plan_id'=>$plan_id, 'unit_id'=>$item['unit_id'], 'unit_number'=>$item['unit_number'],
                    'expected_name'=>$item['expected_name'], 'normalized_name'=>$item['normalized_name'],
                    'planned_action'=>$item['planned_action'], 'parent_drive_folder_id'=>$item['parent_drive_folder_id'],
                    'existing_drive_folder_id'=>$item['existing_drive_folder_id'] ?: null,
                    'candidate_drive_folder_ids'=>wp_json_encode($item['candidate_drive_folder_ids']),
                    'candidate_names'=>wp_json_encode($item['candidate_names']), 'path_snapshot'=>$item['path_snapshot'],
                    'reasons'=>wp_json_encode(array('reason'=>$item['reason'])), 'created_at'=>$now,
                ))) { throw new RuntimeException('Could not save a folder provisioning plan item.'); }
            }
            if (false === $wpdb->query('COMMIT')) { throw new RuntimeException('Could not commit the folder provisioning plan.'); }
            $plan = (object) array('id'=>$plan_id, 'plan_uuid'=>$uuid, 'plan_status'=>$status);
            return $this->report($plan, $items, $counts);
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('folder_plan_failed', $error->getMessage());
        }
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

    private function report($plan, $items, $counts)
    {
        return array(
            'plan_id'=>absint($plan->id), 'plan_uuid'=>(string) $plan->plan_uuid,
            'status'=>(string) $plan->plan_status, 'total'=>count($items),
            'existing'=>$counts['existing'], 'create'=>$counts['create'], 'conflicts'=>$counts['conflict'],
            'items'=>$items, 'ready_for_review'=>$counts['conflict'] === 0,
            'authoritative_state_changed'=>false, 'drive_mutations'=>0,
        );
    }
}
