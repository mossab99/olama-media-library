<?php
if (!defined('ABSPATH')) { exit; }

/** Builds reviewable curriculum-folder candidates from a completed inventory. */
class Olama_Media_Drive_Mapping
{
    private $inventory;
    private $curriculum;
    private $normalizer;

    public function __construct($inventory = null, $curriculum = null, $normalizer = null)
    {
        $this->inventory = $inventory ?: new Olama_Media_Drive_Inventory_Repository();
        $this->curriculum = $curriculum ?: new Olama_Media_Curriculum_Adapter();
        $this->normalizer = $normalizer ?: new Olama_Media_Normalizer();
    }

    public function generate_subject_candidates($year_id, $semester_id, $grade_id, $subject_id)
    {
        global $wpdb;
        $ids = array_map('absint', array($year_id, $semester_id, $grade_id, $subject_id));
        if (in_array(0, $ids, true)) { return new WP_Error('invalid_scope', __('Select the academic year, semester, grade, and subject.', 'olama-media-library')); }
        $run = $this->inventory->get_latest_completed_run();
        if (!$run) { return new WP_Error('inventory_required', __('Complete a Drive inventory before generating mapping candidates.', 'olama-media-library')); }
        if (!hash_equals((string) $run->root_config_hash, $this->current_root_config_hash())) {
            return new WP_Error('inventory_root_stale', __('The completed inventory belongs to an older Drive root configuration. Run a new inventory first.', 'olama-media-library'));
        }

        $names = $this->curriculum->get_names($ids[0], $ids[1], $ids[2], $ids[3]);
        if (empty($names['subject'])) { return new WP_Error('subject_not_found', __('The selected curriculum subject was not found.', 'olama-media-library')); }
        $normalized = array_map(array($this->normalizer, 'normalize_text'), $names);
        $scope_key = sprintf('subject:%d:%d:%d:%d', $ids[0], $ids[1], $ids[2], $ids[3]);
        $candidates_table = $wpdb->prefix . 'olama_drive_mapping_candidates';
        $wpdb->delete($candidates_table, array('discovery_run_id' => absint($run->id), 'scope_key' => $scope_key));

        $matches = array();
        foreach ($this->inventory->get_folder_observations($run->id) as $folder) {
            if (!$this->names_match($normalized['subject'], $folder->normalized_name)) { continue; }
            $path = $this->normalizer->normalize_text($folder->path_snapshot);
            $score = 60;
            $reasons = array('subject_name_exact');
            foreach (array('grade'=>15, 'semester'=>10, 'academic_year'=>10) as $key => $points) {
                if ($normalized[$key] !== '' && $this->context_matches($key, $path, $normalized[$key])) {
                    $score += $points;
                    $reasons[] = $key . '_path_match';
                }
            }
            if (absint($folder->direct_file_count) > 0) { $score += 5; $reasons[] = 'contains_direct_files'; }
            $matches[] = array('folder'=>$folder, 'score'=>$score, 'reasons'=>$reasons);
        }

        $required_reasons = array('academic_year_path_match', 'semester_path_match', 'grade_path_match');
        $eligible = array_filter($matches, function ($match) use ($required_reasons) {
            return !array_diff($required_reasons, $match['reasons']) && absint($match['folder']->sibling_name_count) < 2;
        });
        $candidate_rows = array();
        foreach ($matches as $match) {
            $folder = $match['folder'];
            $score = $match['score'];
            $reasons = $match['reasons'];
            if (absint($folder->sibling_name_count) > 1) {
                $conflict = 'duplicate_sibling_folder_name';
            } elseif (array_diff($required_reasons, $reasons)) {
                $conflict = 'insufficient_scope_context';
            } elseif (count($eligible) > 1) {
                $conflict = 'multiple_scope_candidates';
            } else {
                $conflict = '';
            }
            $wpdb->insert($candidates_table, array(
                'discovery_run_id' => absint($run->id), 'scope_key' => $scope_key,
                'drive_folder_id' => sanitize_text_field($folder->drive_item_id),
                'confidence' => min(100, $score), 'reasons' => wp_json_encode($reasons),
                'conflict_reason' => $conflict, 'created_at' => current_time('mysql'),
            ));
            if ($wpdb->insert_id) { $candidate_rows[] = $this->candidate_payload($wpdb->insert_id, $folder, $score, $reasons, $conflict); }
        }

        usort($candidate_rows, function ($a, $b) { return $b['confidence'] <=> $a['confidence']; });
        $confirmed_mapping_revalidated = $this->revalidate_existing_mapping($scope_key, $eligible, $matches);
        $confirmed_mapping = $this->get_confirmed_mapping_for_scope($scope_key);
        return array(
            'run_uuid' => $run->run_uuid, 'scope_key' => $scope_key,
            'subject_name' => $names['subject'], 'candidate_count' => count($candidate_rows),
            'confirmation_ready' => count($eligible) === 1, 'confirmed_mapping_revalidated' => $confirmed_mapping_revalidated,
            'confirmed_mapping' => $confirmed_mapping ? $this->mapping_payload($confirmed_mapping) : null,
            'requires_manual_confirmation' => true, 'candidates' => $candidate_rows,
        );
    }

    public function confirm_candidate($candidate_id, $scope_key, $options = array())
    {
        global $wpdb;
        $candidates = $wpdb->prefix . 'olama_drive_mapping_candidates';
        $runs = $wpdb->prefix . 'olama_drive_scan_runs';
        $maps = $wpdb->prefix . 'olama_curriculum_drive_map';
        $candidate = $wpdb->get_row($wpdb->prepare(
            "SELECT c.*,r.root_config_hash,r.status run_status FROM {$candidates} c JOIN {$runs} r ON r.id=c.discovery_run_id WHERE c.id=%d LIMIT 1",
            absint($candidate_id)
        ));
        if (!$candidate || !hash_equals((string) $candidate->scope_key, (string) $scope_key)) {
            return new WP_Error('candidate_not_found', __('The selected mapping candidate is invalid.', 'olama-media-library'));
        }
        if ($candidate->run_status !== 'completed') { return new WP_Error('inventory_incomplete', __('Only candidates from a completed inventory can be confirmed.', 'olama-media-library')); }
        if (!hash_equals((string) $candidate->root_config_hash, $this->current_root_config_hash())) {
            return new WP_Error('candidate_root_stale', __('This candidate belongs to an older Drive root configuration and cannot be confirmed.', 'olama-media-library'));
        }
        $reasons = json_decode((string) $candidate->reasons, true);
        $required_reasons = array('academic_year_path_match', 'semester_path_match', 'grade_path_match');
        $scope_context_complete = is_array($reasons) && !array_diff($required_reasons, $reasons);
        $automatic_scope_complete = $scope_context_complete && empty($candidate->conflict_reason);
        $manual_override = !empty($options['manual_override']);
        if (!$scope_context_complete) {
            return new WP_Error('candidate_scope_mismatch', __('A folder from another academic year, semester, or grade cannot be confirmed for this subject.', 'olama-media-library'));
        }
        if (!$automatic_scope_complete && !$manual_override) {
            return new WP_Error('candidate_scope_incomplete', __('This candidate does not contain enough curriculum scope evidence to be confirmed.', 'olama-media-library'));
        }
        if ($manual_override) {
            $confirmed_folder_id = sanitize_text_field($options['confirmation_folder_id'] ?? '');
            $confirmation_text = sanitize_text_field($options['confirmation_text'] ?? '');
            if ($confirmed_folder_id === '' || !hash_equals((string) $candidate->drive_folder_id, $confirmed_folder_id)) {
                return new WP_Error('manual_folder_id_mismatch', __('The manually entered Drive folder ID does not match this candidate.', 'olama-media-library'));
            }
            if (!hash_equals('CONFIRM DRIVE MAPPING', $confirmation_text)) {
                return new WP_Error('manual_confirmation_invalid', __('Manual confirmation text does not match.', 'olama-media-library'));
            }
        }
        if (!preg_match('/^subject:(\d+):(\d+):(\d+):(\d+)$/', $candidate->scope_key, $matches)) {
            return new WP_Error('invalid_scope_key', __('The candidate curriculum scope is invalid.', 'olama-media-library'));
        }
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$maps} WHERE scope_key=%s LIMIT 1", $candidate->scope_key));
        if ($existing && $existing->mapping_status === 'confirmed' && $existing->drive_folder_id !== $candidate->drive_folder_id) {
            return new WP_Error('mapping_already_confirmed', __('This subject already has a different confirmed Drive mapping. Revalidation is required before replacing it.', 'olama-media-library'));
        }
        $now = current_time('mysql');
        $data = array(
            'scope_key'=>$candidate->scope_key, 'scope_type'=>'subject',
            'academic_year_id'=>absint($matches[1]), 'semester_id'=>absint($matches[2]),
            'grade_id'=>absint($matches[3]), 'subject_id'=>absint($matches[4]),
            'drive_folder_id'=>$candidate->drive_folder_id, 'mapping_status'=>'confirmed',
            'confirmation_method'=>$manual_override ? 'manual_verified' : 'scoped_candidate',
            'confirmation_evidence'=>wp_json_encode(array(
                'candidate_id'=>absint($candidate->id), 'discovery_run_id'=>absint($candidate->discovery_run_id),
                'reasons'=>is_array($reasons) ? $reasons : array(), 'conflict_reason'=>(string) $candidate->conflict_reason,
                'confirmed_folder_id'=>(string) $candidate->drive_folder_id,
            )),
            'root_config_hash'=>$candidate->root_config_hash, 'confirmed_by'=>get_current_user_id(),
            'confirmed_at'=>$now, 'updated_at'=>$now,
        );
        if ($existing) {
            $ok = false !== $wpdb->update($maps, $data, array('id'=>absint($existing->id)));
            $mapping_id = absint($existing->id);
        } else {
            $data['created_at'] = $now;
            $ok = $wpdb->insert($maps, $data);
            $mapping_id = absint($wpdb->insert_id);
        }
        return $ok ? array('mapping_id'=>$mapping_id,'scope_key'=>$candidate->scope_key,'drive_folder_id'=>$candidate->drive_folder_id,'mapping_status'=>'confirmed')
            : new WP_Error('mapping_save_failed', __('Could not save the confirmed Drive mapping.', 'olama-media-library'));
    }

    private function names_match($expected, $actual)
    {
        return $expected !== '' && ($expected === $actual || str_replace(' ', '', $expected) === str_replace(' ', '', $actual));
    }

    private function path_contains_segment($path, $segment)
    {
        return preg_match('/(?:^| )' . preg_quote($segment, '/') . '(?: |$)/u', $path) === 1;
    }

    private function context_matches($key, $path, $expected)
    {
        if ($this->path_contains_segment($path, $expected)) { return true; }
        if ($key !== 'grade') { return false; }
        $ordinals = array(
            1=>array('اول','الاول','one'), 2=>array('ثاني','الثاني','two'), 3=>array('ثالث','الثالث','three'),
            4=>array('رابع','الرابع','four'), 5=>array('خامس','الخامس','five'), 6=>array('سادس','السادس','six'),
            7=>array('سابع','السابع','seven'), 8=>array('ثامن','الثامن','eight'), 9=>array('تاسع','التاسع','nine'),
            10=>array('عاشر','العاشر','ten'), 11=>array('حادي عشر','الحادي عشر','eleven'), 12=>array('ثاني عشر','الثاني عشر','twelve'),
        );
        foreach ($ordinals as $number => $words) {
            $expected_matches = in_array((string) $number, explode(' ', $expected), true);
            foreach ($words as $word) { $expected_matches = $expected_matches || $this->path_contains_segment($expected, $word); }
            if (!$expected_matches) { continue; }
            $aliases = array('الصف ' . $words[1], 'grade ' . $number, 'grade ' . $words[count($words) - 1]);
            foreach (array_merge($words, $aliases) as $alias) {
                if ($this->path_contains_segment($path, $alias)) { return true; }
            }
        }
        return false;
    }

    private function revalidate_existing_mapping($scope_key, $eligible, $matches)
    {
        global $wpdb;
        $maps = $wpdb->prefix . 'olama_curriculum_drive_map';
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$maps} WHERE scope_key=%s LIMIT 1", $scope_key));
        if (!$existing || $existing->mapping_status !== 'confirmed') { return false; }
        $eligible_ids = array_map(function ($match) { return (string) $match['folder']->drive_item_id; }, $eligible);
        if (count($eligible_ids) === 1 && $eligible_ids[0] === (string) $existing->drive_folder_id) { return true; }
        if (($existing->confirmation_method ?? '') === 'manual_verified') {
            $observed_ids = array_map(function ($match) { return (string) $match['folder']->drive_item_id; }, $matches);
            if (in_array((string) $existing->drive_folder_id, $observed_ids, true)) { return true; }
        }
        $wpdb->update($maps, array('mapping_status'=>'proposed_for_revalidation','updated_at'=>current_time('mysql')), array('id'=>absint($existing->id)));
        return false;
    }

    private function candidate_payload($id, $folder, $score, $reasons, $conflict)
    {
        return array(
            'candidate_id'=>absint($id), 'drive_folder_id'=>sanitize_text_field($folder->drive_item_id),
            'folder_name'=>sanitize_text_field($folder->item_name), 'path'=>sanitize_text_field($folder->path_snapshot),
            'confidence'=>min(100, absint($score)), 'reasons'=>$reasons, 'conflict_reason'=>$conflict,
            'direct_file_count'=>absint($folder->direct_file_count),
        );
    }

    public function get_confirmed_mapping_for_scope($scope_key)
    {
        global $wpdb;
        $maps = $wpdb->prefix . 'olama_curriculum_drive_map';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$maps} WHERE scope_key=%s AND mapping_status='confirmed' LIMIT 1",
            sanitize_text_field($scope_key)
        ));
    }

    public function get_confirmed_mapping_by_id($mapping_id)
    {
        global $wpdb;
        $maps = $wpdb->prefix . 'olama_curriculum_drive_map';
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$maps} WHERE id=%d AND mapping_status='confirmed' LIMIT 1",
            absint($mapping_id)
        ));
    }

    /** Persist a subject folder created by a completed reviewed folder plan. */
    public function confirm_provisioned_subject($scope_key, $drive_folder_id, $root_config_hash, $plan_id)
    {
        global $wpdb;
        if (!preg_match('/^subject:(\d+):(\d+):(\d+):(\d+)$/', (string) $scope_key, $matches)) {
            return new WP_Error('invalid_scope_key', __('The folder plan curriculum scope is invalid.', 'olama-media-library'));
        }
        $drive_folder_id = sanitize_text_field($drive_folder_id);
        if ($drive_folder_id === '') { return new WP_Error('provisioned_subject_id_missing', __('The created subject Drive ID is missing.', 'olama-media-library')); }
        $maps = $wpdb->prefix . 'olama_curriculum_drive_map';
        $existing = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$maps} WHERE scope_key=%s LIMIT 1", $scope_key));
        if ($existing && $existing->mapping_status === 'confirmed' && !hash_equals((string) $existing->drive_folder_id, $drive_folder_id)) {
            return new WP_Error('mapping_already_confirmed', __('This subject already has a different confirmed Drive mapping.', 'olama-media-library'));
        }
        $now = current_time('mysql');
        $data = array(
            'scope_key'=>sanitize_text_field($scope_key), 'scope_type'=>'subject',
            'academic_year_id'=>absint($matches[1]), 'semester_id'=>absint($matches[2]),
            'grade_id'=>absint($matches[3]), 'subject_id'=>absint($matches[4]),
            'drive_folder_id'=>$drive_folder_id, 'mapping_status'=>'confirmed',
            'confirmation_method'=>'provisioned_plan',
            'confirmation_evidence'=>wp_json_encode(array('folder_plan_id'=>absint($plan_id), 'drive_folder_id'=>$drive_folder_id)),
            'root_config_hash'=>sanitize_text_field($root_config_hash), 'confirmed_by'=>get_current_user_id(),
            'confirmed_at'=>$now, 'updated_at'=>$now,
        );
        if ($existing) {
            $ok = false !== $wpdb->update($maps, $data, array('id'=>absint($existing->id)));
            $mapping_id = absint($existing->id);
        } else {
            $data['created_at'] = $now;
            $ok = $wpdb->insert($maps, $data);
            $mapping_id = absint($wpdb->insert_id);
        }
        return $ok ? $mapping_id : new WP_Error('mapping_save_failed', __('Could not save the provisioned subject Drive mapping.', 'olama-media-library'));
    }

    private function mapping_payload($mapping)
    {
        return array(
            'mapping_id'=>absint($mapping->id), 'scope_key'=>sanitize_text_field($mapping->scope_key),
            'drive_folder_id'=>sanitize_text_field($mapping->drive_folder_id),
            'mapping_status'=>sanitize_key($mapping->mapping_status),
            'confirmation_method'=>sanitize_key($mapping->confirmation_method ?? ''),
        );
    }

    private function current_root_config_hash()
    {
        $settings = get_option('academy_media_library_settings', array());
        return hash('sha256', wp_json_encode(array(
            'root_folder_id' => sanitize_text_field($settings['root_folder_id'] ?? ''),
            'root_scope_level' => sanitize_key($settings['root_scope_level'] ?? 'unknown'),
            'root_scope_id' => absint($settings['root_scope_id'] ?? 0),
        )));
    }
}
