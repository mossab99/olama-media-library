<?php
if (!defined('ABSPATH')) { exit; }

class Olama_Media_Matcher
{
    private $repository;
    private $normalizer;
    private $curriculum;

    public function __construct($repository = null, $normalizer = null, $curriculum = null)
    {
        $this->repository = $repository ?: new Olama_Media_V2_Repository();
        $this->normalizer = $normalizer ?: new Olama_Media_Normalizer();
        $this->curriculum = $curriculum ?: new Olama_Media_Curriculum_Adapter();
    }

    public function match_subject($academic_year_id, $semester_id, $grade_id, $subject_id, $options = array())
    {
        $dry_run = !empty($options['dry_run']);
        $auto_apply = !empty($options['auto_apply']) && !$dry_run;
        $force = !empty($options['force_relink']);
        $run_id = $this->repository->create_sync_run(array(
            'run_type'=>'lesson_match','dry_run'=>$dry_run,'academic_year_id'=>absint($academic_year_id),
            'semester_id'=>absint($semester_id),'grade_id'=>absint($grade_id),'subject_id'=>absint($subject_id),
        ));
        $units = (new Olama_Media_DB())->get_curriculum_with_assets($academic_year_id, $semester_id, $grade_id, $subject_id);
        if (is_wp_error($units)) { $this->repository->finish_sync_run($run_id, 'failed', array('errors'=>1)); return $units; }
        $names = $this->curriculum->get_names($academic_year_id, $semester_id, $grade_id, $subject_id);

        // get_active_drive_files() already deduplicates by drive_path_hash (keeps newest per path).
        // We also count raw duplicates for reporting transparency.
        $all_raw = $this->repository->get_all_active_drive_files_raw();
        $all_raw_in_scope = array_values(array_filter($all_raw, function ($file) use ($names) { return $this->file_is_in_curriculum_scope($file, $names); }));
        $files = $this->repository->get_active_drive_files();
        $files = array_values(array_filter($files, function ($file) use ($names) { return $this->file_is_in_curriculum_scope($file, $names); }));
        $duplicate_count = count($all_raw_in_scope) - count($files);

        $cleared = 0;
        if ($auto_apply) {
            $cleared = $this->repository->clear_pending_generated_links_for_scope($academic_year_id, $semester_id, $grade_id, $subject_id);
        }
        $report = array('run_id'=>$run_id,'files_in_scope'=>count($files),'raw_files_in_scope'=>count($all_raw_in_scope),'duplicate_drive_files'=>$duplicate_count,'cleared_pending_links'=>$cleared,'auto_linked'=>0,'needs_review'=>0,'unmatched'=>0,'ambiguous'=>0,'already_linked'=>0,'errors'=>0,'results'=>array());

        foreach ($files as $file) {
            $candidates = array();
            foreach ($units as $unit) {
                if (!$this->file_is_in_unit($file, $unit)) { continue; }
                foreach ($unit->lessons as $lesson) {
                    $score = $this->score_file_against_lesson($file, $lesson, $unit, array('names'=>$names,'units'=>$units));
                    if ($score['confidence'] >= 70) { $candidates[] = array('file'=>$file,'lesson'=>$lesson,'unit'=>$unit) + $score; }
                }
            }
            usort($candidates, function ($a, $b) { return $b['confidence'] <=> $a['confidence']; });
            if (!$candidates) { $report['unmatched']++; continue; }
            $top = $candidates[0];
            $high = array_values(array_filter($candidates, function ($candidate) { return $candidate['confidence'] >= 90; }));
            if (count($high) > 1 && $high[0]['confidence'] === $high[1]['confidence']) {
                $report['ambiguous']++;
                $report['results'][] = $this->result_row($top, 'ambiguous');
                if (!$dry_run && !empty($options['save_review'])) {
                    $this->save_candidate_link($top, $academic_year_id, $semester_id, $grade_id, $subject_id, min(89, $top['confidence']), 'unlinked');
                }
                continue;
            }
            $existing = $this->repository->get_link_by_drive_file_id($file->drive_file_id);
            if ($existing && absint($existing->lesson_id) === absint($top['lesson']->id) && $existing->link_status === 'active') {
                $report['already_linked']++;
                continue;
            }
            if ($existing && $existing->link_status === 'active' && !$force) {
                $report['needs_review']++;
                $report['results'][] = $this->result_row($top, 'existing_link_conflict');
                continue;
            }
            $status = $top['confidence'] >= 90 ? 'auto_link' : 'needs_review';
            $report[$status === 'auto_link' ? 'auto_linked' : 'needs_review']++;
            $report['results'][] = $this->result_row($top, $status);
            if (($status === 'auto_link' && $auto_apply) || ($status === 'needs_review' && !$dry_run && !empty($options['save_review']))) {
                $this->save_candidate_link($top, $academic_year_id, $semester_id, $grade_id, $subject_id, $top['confidence'], $status === 'auto_link' ? 'active' : 'unlinked');
            }
        }
        $report['results'] = array_slice($report['results'], 0, 100);
        $this->repository->log_sync_event($run_id, 'subject_match_completed', 'info', 'Subject match completed.', $report);
        $this->repository->finish_sync_run($run_id, 'completed', $report);
        return $report;
    }

    public function score_file_against_lesson($drive_file, $lesson, $unit, $context)
    {
        $score = 0;
        $path = $this->normalizer->normalize_text($drive_file->drive_path);
        $filename = $drive_file->normalized_filename ?: $this->normalizer->normalize_filename($drive_file->filename);
        $subject = $this->normalizer->normalize_text($context['names']['subject'] ?? '');
        $unit_name = $this->normalizer->normalize_text($unit->unit_name ?? '');
        $title = $this->normalizer->normalize_text($lesson->lesson_title ?? '');
        $parsed_lesson = $this->normalizer->extract_lesson_number($drive_file->filename);
        $part = $this->normalizer->extract_part_number($drive_file->filename);
        $lesson_number = (int) $this->normalizer->normalize_text($lesson->lesson_number ?? '0');
        $segments = $this->normalized_path_segments($drive_file->drive_path);
        if ($subject && in_array($subject, $segments, true)) { $score += 35; }
        if ($unit_name && $this->segments_match_unit($segments, $unit_name)) { $score += 25; }
        if ($parsed_lesson !== null && $parsed_lesson === $lesson_number) { $score += 30; }
        elseif ($parsed_lesson !== null) { $score -= 30; }
        if ($title && strpos($filename, $title) !== false) { $score += 20; }
        if (preg_match('/(?:^|\s)(?:درس|الدرس|lesson|l)\s*/iu', $filename)) { $score += 10; }
        if ($part !== null) { $score += 10; }
        foreach ($context['units'] as $other_unit) {
            if ((int) $other_unit->id === (int) $unit->id) { continue; }
            $other_name = $this->normalizer->normalize_text($other_unit->unit_name);
            if ($other_name && $this->segments_match_unit($segments, $other_name)) { $score -= 20; break; }
        }
        $score = max(0, min(100, $score));
        return array('confidence'=>$score,'part_number'=>$part,'method'=>$part ? 'filename_lesson_part' : ($parsed_lesson ? 'filename_lesson_number' : 'folder_and_title'));
    }

    public function auto_link_high_confidence($matches, $run_id, $dry_run)
    {
        return array_filter($matches, function ($match) { return ($match['confidence'] ?? 0) >= 90; });
    }

    private function result_row($candidate, $status)
    {
        return array('drive_file_id'=>$candidate['file']->drive_file_id,'filename'=>$candidate['file']->filename,
            'drive_path'=>$candidate['file']->drive_path,'lesson_id'=>absint($candidate['lesson']->id),
            'lesson_title'=>$candidate['lesson']->lesson_title,'lesson_number'=>$candidate['lesson']->lesson_number,
            'unit_id'=>absint($candidate['unit']->id),'unit_name'=>$candidate['unit']->unit_name,
            'part_number'=>$candidate['part_number'],'confidence'=>$candidate['confidence'],'status'=>$status);
    }

    private function save_candidate_link($candidate, $academic_year_id, $semester_id, $grade_id, $subject_id, $confidence, $link_status)
    {
        return $this->repository->upsert_lesson_video_link(array(
            'drive_file_id'=>$candidate['file']->drive_file_id,'drive_file_row_id'=>absint($candidate['file']->id),
            'academic_year_id'=>absint($academic_year_id),'semester_id'=>absint($semester_id),
            'grade_id'=>absint($grade_id),'subject_id'=>absint($subject_id),'unit_id'=>absint($candidate['unit']->id),
            'lesson_id'=>absint($candidate['lesson']->id),'part_number'=>$candidate['part_number'],
            'sequence_order'=>$candidate['part_number'] ?: $this->repository->next_sequence_order($candidate['lesson']->id),
            'match_method'=>$candidate['method'],'match_confidence'=>absint($confidence),'approval_status'=>'pending',
            'link_status'=>sanitize_key($link_status),'linked_by'=>get_current_user_id(),
        ));
    }

    private function file_is_in_curriculum_scope($file, $names)
    {
        $segments = $this->normalized_path_segments($file->drive_path);
        foreach (array('academic_year','semester','grade','subject') as $key) {
            $expected = $this->normalizer->normalize_text($names[$key] ?? '');
            if ($expected !== '' && !in_array($expected, $segments, true)) { return false; }
        }
        return true;
    }

    private function file_is_in_unit($file, $unit)
    {
        $unit_name = $this->normalizer->normalize_text($unit->unit_name ?? '');
        return $unit_name !== '' && $this->segments_match_unit($this->normalized_path_segments($file->drive_path), $unit_name);
    }

    private function normalized_path_segments($path)
    {
        $segments = array_map(array($this->normalizer, 'normalize_text'), explode('/', (string) $path));
        array_pop($segments);
        return array_values(array_filter($segments, 'strlen'));
    }

    private function segments_match_unit($segments, $unit_name)
    {
        foreach ($segments as $segment) {
            if ($segment === $unit_name || preg_match('/(?:^|\s)' . preg_quote($unit_name, '/') . '$/u', $segment)) { return true; }
        }
        return false;
    }
}
