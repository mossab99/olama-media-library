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

        $observations = $this->inventory->get_all_observations($run->id);
        $by_id = array();
        foreach ($observations as $item) { $by_id[(string) $item->drive_item_id] = $item; }
        if (!isset($by_id[(string) $mapping->drive_folder_id])) {
            return new WP_Error('mapped_folder_not_observed', __('The confirmed subject folder was not found in the completed inventory.', 'olama-media-library'));
        }

        $table = $wpdb->prefix . 'olama_drive_reconciliation_items';
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
        $report = array('mapping_id'=>absint($mapping->id),'run_uuid'=>$run->run_uuid,'files_in_subject'=>0,'matched'=>0,'needs_review'=>0,'ambiguous'=>0,'unmatched'=>0,'authoritative_links_changed'=>false,'drive_mutations'=>0,'results'=>array());

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
            if (!$top) { $status = 'unmatched'; }
            elseif (isset($candidates[1]) && $candidates[1]['confidence'] === $top['confidence']) { $status = 'ambiguous'; }
            elseif ($top['confidence'] >= 90) { $status = 'matched'; }
            else { $status = 'needs_review'; }
            $report[$status]++;
            $row = array(
                'drive_file_id'=>(string) $item->drive_item_id, 'filename'=>(string) $item->item_name,
                'path'=>(string) $item->path_snapshot, 'status'=>$status,
                'unit_id'=>$top ? absint($top['unit']->id) : 0, 'unit_name'=>$top ? (string) $top['unit']->unit_name : '',
                'lesson_id'=>$top ? absint($top['lesson']->id) : 0, 'lesson_number'=>$top ? (string) $top['lesson']->lesson_number : '',
                'lesson_title'=>$top ? (string) $top['lesson']->lesson_title : '', 'confidence'=>$top ? absint($top['confidence']) : 0,
                'part_number'=>$top ? $top['part_number'] : null,
            );
            $now = current_time('mysql');
            $saved = $wpdb->insert($table, array(
                'discovery_run_id'=>absint($run->id),'subject_mapping_id'=>absint($mapping->id),'drive_file_id'=>sanitize_text_field($item->drive_item_id),
                'proposed_unit_id'=>$row['unit_id'] ?: null,'proposed_lesson_id'=>$row['lesson_id'] ?: null,'confidence'=>$row['confidence'],
                'proposal_status'=>$status,'filename'=>sanitize_text_field($item->item_name),'path_snapshot'=>sanitize_text_field($item->path_snapshot),
                'reasons'=>wp_json_encode(array('part_number'=>$row['part_number'])),'created_at'=>$now,'updated_at'=>$now,
            ));
            if (!$saved) {
                $wpdb->query('ROLLBACK');
                return new WP_Error('reconciliation_stage_failed', __('Could not stage the reconciliation preview.', 'olama-media-library'));
            }
            if (count($report['results']) < 250) { $report['results'][] = $row; }
        }
        if (false === $wpdb->query('COMMIT')) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('reconciliation_commit_failed', __('Could not commit the reconciliation preview.', 'olama-media-library'));
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
