<?php
if (!defined('ABSPATH')) { exit; }

/** Stages lesson-link proposals from inventory without changing authoritative links. */
class Olama_Media_Reconciliation_Preview
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

    public function generate($mapping_id)
    {
        global $wpdb;
        $mapping = $this->mapping->get_confirmed_mapping_by_id($mapping_id);
        if (!$mapping) { return new WP_Error('confirmed_mapping_required', __('A confirmed subject Drive mapping is required.', 'olama-media-library')); }
        $run = $this->inventory->get_latest_completed_run();
        if (!$run || !hash_equals((string) $mapping->root_config_hash, (string) $run->root_config_hash)) {
            return new WP_Error('mapping_inventory_mismatch', __('The confirmed mapping and latest completed inventory do not use the same Drive root generation.', 'olama-media-library'));
        }
        $units = (new Olama_Media_DB())->get_curriculum_with_assets(
            $mapping->academic_year_id, $mapping->semester_id, $mapping->grade_id, $mapping->subject_id
        );
        if (is_wp_error($units)) { return $units; }

        $curriculum_lessons = $this->curriculum_lessons($units);
        $report = array(
            'mapping_id'=>absint($mapping->id), 'run_uuid'=>$run->run_uuid,
            'files_in_subject'=>0, 'matched'=>0, 'needs_review'=>0, 'ambiguous'=>0, 'unmatched'=>0,
            'reviewed'=>0, 'authoritative_links_changed'=>false, 'drive_mutations'=>0,
            'curriculum_lessons'=>array_values($curriculum_lessons), 'results'=>array(),
        );

        $table = $wpdb->prefix . 'olama_drive_reconciliation_items';
        $existing = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE discovery_run_id=%d AND subject_mapping_id=%d ORDER BY id ASC",
            absint($run->id), absint($mapping->id)
        ));
        if ($existing) {
            return $this->existing_report($report, $existing, $curriculum_lessons);
        }

        $observations = $this->inventory->get_all_observations($run->id);
        $by_id = array();
        foreach ($observations as $item) { $by_id[(string) $item->drive_item_id] = $item; }
        if (!isset($by_id[(string) $mapping->drive_folder_id])) {
            return new WP_Error('mapped_folder_not_observed', __('The confirmed subject folder was not found in the completed inventory.', 'olama-media-library'));
        }

        if (false === $wpdb->query('START TRANSACTION')) {
            return new WP_Error('reconciliation_transaction_failed', __('Could not start the reconciliation staging transaction.', 'olama-media-library'));
        }
        if (false === $wpdb->delete($table, array('discovery_run_id'=>absint($run->id), 'subject_mapping_id'=>absint($mapping->id)))) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('reconciliation_reset_failed', __('Could not reset the previous reconciliation preview.', 'olama-media-library'));
        }
        $matcher = new Olama_Media_Matcher(null, $this->normalizer);
        $names = (new Olama_Media_Curriculum_Adapter())->get_names(
            $mapping->academic_year_id, $mapping->semester_id, $mapping->grade_id, $mapping->subject_id
        );
        foreach ($observations as $item) {
            if ($item->item_type !== 'file' || !$this->is_video_file($item) || !$this->is_descendant_of($item, $mapping->drive_folder_id, $by_id)) { continue; }
            $report['files_in_subject']++;
            $drive_file = (object) array(
                'drive_file_id'=>$item->drive_item_id, 'filename'=>$item->item_name,
                'normalized_filename'=>$this->normalizer->normalize_filename($item->item_name),
                'drive_path'=>$item->path_snapshot,
            );
            $candidates = array();
            foreach ((array) $units as $unit) {
                foreach ((array) $unit->lessons as $lesson) {
                    $score = $matcher->score_file_against_lesson($drive_file, $lesson, $unit, array('names'=>$names,'units'=>$units));
                    if ($score['confidence'] >= 70) { $candidates[] = array('unit'=>$unit,'lesson'=>$lesson) + $score; }
                }
            }
            usort($candidates, function ($a, $b) { return $b['confidence'] <=> $a['confidence']; });
            $top = $candidates[0] ?? null;
            $has_title_evidence = $top && in_array('lesson_title_match', (array) $top['evidence'], true);
            if (!$top) { $status = 'unmatched'; }
            // A lesson number is only a sequencing hint. It must never become a
            // proposed lesson identity when the filename title contradicts (or
            // does not identify) that curriculum lesson.
            elseif (!$has_title_evidence) { $status = 'unmatched'; }
            elseif (isset($candidates[1]) && $candidates[1]['confidence'] === $top['confidence']) { $status = 'ambiguous'; }
            elseif ($top['confidence'] >= 90 && !in_array('lesson_number_mismatch', (array) $top['evidence'], true)) { $status = 'matched'; }
            else { $status = 'needs_review'; }
            $report[$status]++;
            $proposed_lesson = $has_title_evidence ? $top : null;
            $candidate_confidence = $top ? absint($top['confidence']) : 0;
            $row = array(
                'drive_file_id'=>(string) $item->drive_item_id, 'filename'=>(string) $item->item_name,
                'path'=>(string) $item->path_snapshot, 'status'=>$status,
                'unit_id'=>$top ? absint($top['unit']->id) : 0, 'unit_name'=>$top ? (string) $top['unit']->unit_name : '',
                'lesson_id'=>$proposed_lesson ? absint($proposed_lesson['lesson']->id) : 0,
                'lesson_number'=>$proposed_lesson ? (string) $proposed_lesson['lesson']->lesson_number : '',
                'lesson_title'=>$proposed_lesson ? (string) $proposed_lesson['lesson']->lesson_title : '',
                'confidence'=>$proposed_lesson ? $candidate_confidence : 0,
                'part_number'=>$top ? $top['part_number'] : null, 'evidence'=>$top ? $top['evidence'] : array(),
                'decision_status'=>'pending', 'selected_unit_id'=>0, 'selected_lesson_id'=>0,
            );
            $now = current_time('mysql');
            $saved = $wpdb->insert($table, array(
                'discovery_run_id'=>absint($run->id),'subject_mapping_id'=>absint($mapping->id),'drive_file_id'=>sanitize_text_field($item->drive_item_id),
                'proposed_unit_id'=>$row['unit_id'] ?: null,'proposed_lesson_id'=>$row['lesson_id'] ?: null,'confidence'=>$row['confidence'],
                'proposal_status'=>$status,'filename'=>sanitize_text_field($item->item_name),'path_snapshot'=>sanitize_text_field($item->path_snapshot),
                'reasons'=>wp_json_encode(array(
                    'part_number'=>$row['part_number'], 'evidence'=>$row['evidence'],
                    'rejected_candidate_confidence'=>$proposed_lesson ? null : $candidate_confidence,
                )),'created_at'=>$now,'updated_at'=>$now,
            ));
            if (!$saved) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('reconciliation_stage_failed', __('Could not stage the reconciliation preview.', 'olama-media-library'));
            }
            $row['item_id'] = absint($wpdb->insert_id);
            if (count($report['results']) < 250) { $report['results'][] = $row; }
        }
        if (false === $wpdb->query('COMMIT')) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('reconciliation_commit_failed', __('Could not commit the reconciliation preview.', 'olama-media-library'));
        }
        return $report;
    }

    /** Records a human decision in staging only; it never writes an authoritative link. */
    public function review($item_id, $decision, $lesson_id = 0)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_drive_reconciliation_items';
        $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d", absint($item_id)));
        if (!$item) { return new WP_Error('reconciliation_item_not_found', __('The reconciliation item was not found.', 'olama-media-library')); }

        $mapping = $this->mapping->get_confirmed_mapping_by_id($item->subject_mapping_id);
        $run = $this->inventory->get_latest_completed_run();
        if (!$mapping || !$run || absint($item->discovery_run_id) !== absint($run->id) ||
            !hash_equals((string) $mapping->root_config_hash, (string) $run->root_config_hash)) {
            return new WP_Error('stale_reconciliation_item', __('This preview is stale. Create a new inventory and reconciliation preview.', 'olama-media-library'));
        }

        $units = (new Olama_Media_DB())->get_curriculum_with_assets(
            $mapping->academic_year_id, $mapping->semester_id, $mapping->grade_id, $mapping->subject_id
        );
        if (is_wp_error($units)) { return $units; }
        $lessons = $this->curriculum_lessons($units);
        $decision = sanitize_key($decision);
        $data = array('reviewed_by'=>get_current_user_id(), 'reviewed_at'=>current_time('mysql'), 'updated_at'=>current_time('mysql'));

        if ($decision === 'reject') {
            $data += array('decision_status'=>'rejected', 'selected_unit_id'=>null, 'selected_lesson_id'=>null);
        } elseif ($decision === 'assign') {
            $lesson_id = absint($lesson_id);
            if (!$lesson_id || !isset($lessons[$lesson_id])) {
                return new WP_Error('invalid_reconciliation_lesson', __('Select a lesson from the mapped curriculum subject.', 'olama-media-library'));
            }
            if (absint($item->proposed_unit_id) && absint($lessons[$lesson_id]['unit_id']) !== absint($item->proposed_unit_id)) {
                return new WP_Error('reconciliation_unit_boundary', __('The selected lesson must belong to the Drive unit identified by the file path.', 'olama-media-library'));
            }
            $data += array(
                'decision_status'=>($lesson_id === absint($item->proposed_lesson_id) ? 'approved' : 'manual'),
                'selected_unit_id'=>absint($lessons[$lesson_id]['unit_id']), 'selected_lesson_id'=>$lesson_id,
            );
        } else {
            return new WP_Error('invalid_reconciliation_decision', __('Invalid reconciliation decision.', 'olama-media-library'));
        }

        if (false === $wpdb->update($table, $data, array('id'=>absint($item->id)))) {
            return new WP_Error('reconciliation_review_failed', __('Could not save the staged review decision.', 'olama-media-library'));
        }
        return array(
            'item_id'=>absint($item->id), 'decision_status'=>$data['decision_status'],
            'selected_unit_id'=>absint($data['selected_unit_id'] ?? 0), 'selected_lesson_id'=>absint($data['selected_lesson_id'] ?? 0),
            'authoritative_links_changed'=>false, 'drive_mutations'=>0,
        );
    }

    private function curriculum_lessons($units)
    {
        $lessons = array();
        foreach ((array) $units as $unit) {
            foreach ((array) $unit->lessons as $lesson) {
                $lesson_id = absint($lesson->id);
                $lessons[$lesson_id] = array(
                    'lesson_id'=>$lesson_id, 'lesson_number'=>(string) $lesson->lesson_number,
                    'lesson_title'=>(string) $lesson->lesson_title, 'unit_id'=>absint($unit->id),
                    'unit_name'=>(string) $unit->unit_name,
                );
            }
        }
        return $lessons;
    }

    private function existing_report($report, $items, $lessons)
    {
        foreach ((array) $items as $item) {
            $status = sanitize_key($item->proposal_status);
            if (isset($report[$status])) { $report[$status]++; }
            $report['files_in_subject']++;
            if (sanitize_key($item->decision_status ?? 'pending') !== 'pending') { $report['reviewed']++; }
            $proposed = $lessons[absint($item->proposed_lesson_id)] ?? null;
            $unit = $proposed ?: ($lessons[absint($item->selected_lesson_id)] ?? null);
            $unit_name = $unit ? (string) $unit['unit_name'] : '';
            if ($unit_name === '' && absint($item->proposed_unit_id)) {
                foreach ($lessons as $lesson) {
                    if (absint($lesson['unit_id']) === absint($item->proposed_unit_id)) { $unit_name = (string) $lesson['unit_name']; break; }
                }
            }
            if (count($report['results']) < 250) {
                $report['results'][] = array(
                    'item_id'=>absint($item->id), 'drive_file_id'=>(string) $item->drive_file_id,
                    'filename'=>(string) $item->filename, 'path'=>(string) $item->path_snapshot, 'status'=>$status,
                    'unit_id'=>absint($item->proposed_unit_id), 'unit_name'=>$unit_name,
                    'lesson_id'=>absint($item->proposed_lesson_id), 'lesson_number'=>$proposed ? (string) $proposed['lesson_number'] : '',
                    'lesson_title'=>$proposed ? (string) $proposed['lesson_title'] : '', 'confidence'=>absint($item->confidence),
                    'decision_status'=>sanitize_key($item->decision_status ?? 'pending'),
                    'selected_unit_id'=>absint($item->selected_unit_id), 'selected_lesson_id'=>absint($item->selected_lesson_id),
                );
            }
        }
        return $report;
    }

    private function is_descendant_of($item, $ancestor_id, $by_id)
    {
        $parent = (string) $item->parent_drive_folder_id;
        $visited = array();
        while ($parent !== '') {
            if ($parent === (string) $ancestor_id) { return true; }
            if (isset($visited[$parent]) || !isset($by_id[$parent])) { return false; }
            $visited[$parent] = true;
            $parent = (string) $by_id[$parent]->parent_drive_folder_id;
        }
        return false;
    }

    private function is_video_file($item)
    {
        if (strpos(strtolower((string) $item->mime_type), 'video/') === 0) { return true; }
        $extension = strtolower(pathinfo((string) $item->item_name, PATHINFO_EXTENSION));
        return in_array($extension, array('mp4','mov','m4v','avi','mkv','webm','mpg','mpeg'), true);
    }
}
