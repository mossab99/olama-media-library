<?php

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Published curriculum videos safe for student and family portals.
 *
 * This service intentionally excludes pending/rejected links, upload metadata,
 * Drive identifiers, uploader details, notes, jobs, and diagnostic fields.
 */
class Olama_Media_Guardian_Library {
    public static function for_curriculum($academic_year_id, $semester_id, $grade_id, array $subject_ids) {
        $academic_year_id = absint($academic_year_id);
        $semester_id = absint($semester_id);
        $grade_id = absint($grade_id);
        $subject_ids = array_values(array_unique(array_filter(array_map('absint', $subject_ids))));

        if (!$academic_year_id || !$semester_id || !$grade_id || !$subject_ids) {
            return array('subjects' => array(), 'video_count' => 0);
        }
        if (!class_exists('Olama_School_Subject')) {
            return new WP_Error('olama_media_school_missing', __('OLAMA School is required to resolve curriculum subjects.', 'olama-media-library'));
        }

        $db = new Olama_Media_DB();
        $repository = new Olama_Media_V2_Repository();
        $subjects = array();
        $video_count = 0;

        foreach ($subject_ids as $subject_id) {
            $subject = Olama_School_Subject::get_subject($subject_id);
            if (!$subject) {
                continue;
            }

            $units = $db->get_curriculum_with_assets($academic_year_id, $semester_id, $grade_id, $subject_id);
            if (is_wp_error($units)) {
                return $units;
            }

            $published_units = array();
            foreach ((array) $units as $unit) {
                $published_lessons = array();
                foreach ((array) (isset($unit->lessons) ? $unit->lessons : array()) as $lesson) {
                    $videos = self::approved_v2_links($repository->get_links_for_lesson($lesson->id));
                    if (!$videos) {
                        $videos = self::approved_legacy_asset($lesson);
                    }
                    if (!$videos) {
                        continue;
                    }

                    $video_count += count($videos);
                    $published_lessons[] = array(
                        'id' => absint($lesson->id),
                        'number' => sanitize_text_field(isset($lesson->lesson_number) ? $lesson->lesson_number : ''),
                        'title' => sanitize_text_field(isset($lesson->lesson_title) ? $lesson->lesson_title : ''),
                        'videos' => $videos,
                    );
                }

                if ($published_lessons) {
                    $published_units[] = array(
                        'id' => absint($unit->id),
                        'number' => sanitize_text_field(isset($unit->unit_number) ? $unit->unit_number : ''),
                        'name' => sanitize_text_field(isset($unit->unit_name) ? $unit->unit_name : ''),
                        'lessons' => $published_lessons,
                    );
                }
            }

            if ($published_units) {
                $color = sanitize_hex_color(isset($subject->color_code) ? $subject->color_code : '');
                $subjects[] = array(
                    'id' => $subject_id,
                    'name' => sanitize_text_field(isset($subject->subject_name) ? $subject->subject_name : ''),
                    'color' => $color ?: '#1f7ac0',
                    'units' => $published_units,
                );
            }
        }

        return array('subjects' => $subjects, 'video_count' => $video_count);
    }

    private static function approved_v2_links($links) {
        $videos = array();
        foreach ((array) $links as $link) {
            if (
                'approved' !== sanitize_key(isset($link->approval_status) ? $link->approval_status : '')
                || empty($link->web_view_link)
            ) {
                continue;
            }
            $part = !empty($link->part_number) ? absint($link->part_number) : 0;
            $videos[] = array(
                'id' => 'v2-' . absint($link->link_id),
                'part' => $part,
                'label' => $part ? sprintf(__('Part %d', 'olama-media-library'), $part) : __('Watch video', 'olama-media-library'),
                'url' => esc_url_raw($link->web_view_link),
            );
        }
        return $videos;
    }

    private static function approved_legacy_asset($lesson) {
        if (
            'approved' !== sanitize_key(isset($lesson->approval_status) ? $lesson->approval_status : '')
            || 'uploaded_to_drive' !== sanitize_key(isset($lesson->upload_status) ? $lesson->upload_status : '')
            || 'ready' !== sanitize_key(isset($lesson->preview_status) ? $lesson->preview_status : '')
            || empty($lesson->drive_file_url)
        ) {
            return array();
        }
        return array(array(
            'id' => 'legacy-' . absint(isset($lesson->media_record_id) ? $lesson->media_record_id : 0),
            'part' => 0,
            'label' => __('Watch video', 'olama-media-library'),
            'url' => esc_url_raw($lesson->drive_file_url),
        ));
    }
}
