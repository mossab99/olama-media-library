<?php
if (!defined('ABSPATH')) { exit; }

/** Scores candidates for the administrator's reconciliation preview; it never writes links. */
class Olama_Media_Matcher
{
    private $normalizer;

    public function __construct($normalizer = null)
    {
        $this->normalizer = $normalizer ?: new Olama_Media_Normalizer();
    }

    public function score_file_against_lesson($drive_file, $lesson, $unit, $context)
    {
        $score = 0;
        $filename = $drive_file->normalized_filename ?: $this->normalizer->normalize_filename($drive_file->filename);
        $subject = $this->normalizer->normalize_text($context['names']['subject'] ?? '');
        $unit_name = $this->normalizer->normalize_text($unit->unit_name ?? '');
        $title = $this->normalizer->normalize_text($lesson->lesson_title ?? '');
        $parsed_lesson = $this->normalizer->extract_lesson_number($drive_file->filename);
        $part = $this->normalizer->extract_part_number($drive_file->filename);
        $lesson_number = (int) $this->normalizer->normalize_text($lesson->lesson_number ?? '0');
        $segments = $this->normalized_path_segments($drive_file->drive_path);
        $evidence = array();
        if ($subject && in_array($subject, $segments, true)) { $score += 20; $evidence[] = 'subject_path_match'; }
        if ($unit_name && $this->segments_match_unit($segments, $unit_name)) { $score += 25; $evidence[] = 'unit_path_match'; }
        if ($parsed_lesson !== null && $parsed_lesson === $lesson_number) { $score += 20; $evidence[] = 'lesson_number_match'; }
        elseif ($parsed_lesson !== null) { $evidence[] = 'lesson_number_mismatch'; }
        $title_match = $this->title_matches_filename($filename, $title);
        if ($title_match) { $score += 40; $evidence[] = 'lesson_title_match'; }
        if (preg_match('/(?:^|\s)(?:درس|الدرس|lesson|l)\s*/iu', $filename)) { $score += 5; $evidence[] = 'lesson_marker'; }
        if ($part !== null) { $score += 5; $evidence[] = 'part_number'; }
        foreach ($context['units'] as $other_unit) {
            if ((int) $other_unit->id === (int) $unit->id) { continue; }
            $other_name = $this->normalizer->normalize_text($other_unit->unit_name);
            if ($other_name && $this->segments_match_unit($segments, $other_name)) { $score -= 40; $evidence[] = 'different_unit_path'; break; }
        }
        $score = max(0, min(100, $score));
        $method = $title_match ? 'filename_title' : ($parsed_lesson !== null ? 'filename_lesson_number' : 'folder_only');
        if ($part !== null) { $method .= '_part'; }
        return array('confidence'=>$score,'part_number'=>$part,'method'=>$method,'evidence'=>$evidence,'title_match'=>$title_match);
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

    private function title_matches_filename($filename, $title)
    {
        if ($title === '' || (function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title)) < 3) { return false; }
        if (strpos($filename, $title) !== false) { return true; }
        $without_markers = trim((string) preg_replace('/^(?:درس|الدرس|lesson|l)\s*\d+\s*/iu', '', $filename));
        return $without_markers !== ''
            && (function_exists('mb_strlen') ? mb_strlen($without_markers, 'UTF-8') : strlen($without_markers)) >= 4
            && strpos($title, $without_markers) !== false;
    }

}
