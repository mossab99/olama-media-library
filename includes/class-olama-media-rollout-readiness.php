<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Read-only operational readiness report for every subject in a grade.
 *
 * This class reads curriculum, inventory, mapping, and authoritative link state.
 * It never creates a plan and never writes to WordPress or Google Drive.
 */
class Olama_Media_Rollout_Readiness
{
    private $inventory;
    private $mapping;
    private $curriculum;
    private $normalizer;

    public function __construct($inventory = null, $mapping = null, $curriculum = null, $normalizer = null)
    {
        $this->inventory = $inventory ?: new Olama_Media_Drive_Inventory_Repository();
        $this->mapping = $mapping ?: new Olama_Media_Drive_Mapping();
        $this->curriculum = $curriculum ?: new Olama_Media_Curriculum_Adapter();
        $this->normalizer = $normalizer ?: new Olama_Media_Normalizer();
    }

    public function report($academic_year_id, $semester_id, $grade_id)
    {
        $ids = array_map('absint', array($academic_year_id, $semester_id, $grade_id));
        if (in_array(0, $ids, true)) {
            return new WP_Error('rollout_scope_required', __('اختر السنة الدراسية والفصل والصف أولاً.', 'olama-media-library'));
        }

        $subjects = $this->curriculum->get_subjects($ids[2]);
        if (!$subjects) {
            return new WP_Error('rollout_subjects_missing', __('لا توجد مواد نشطة للصف المحدد.', 'olama-media-library'));
        }

        $run = $this->inventory->get_latest_completed_run();
        $current_hash = $this->current_root_config_hash();
        $inventory = $this->inventory_state($run, $current_hash);
        $observations = $run ? $this->inventory->get_all_observations($run->id) : array();
        $by_id = array();
        $children = array();
        foreach ((array) $observations as $item) {
            $by_id[(string) $item->drive_item_id] = $item;
            if ($item->item_type === 'folder') {
                $children[(string) $item->parent_drive_folder_id][] = $item;
            }
        }

        $rows = array();
        $totals = array(
            'subjects'=>0, 'ready'=>0, 'attention'=>0, 'blocked'=>0, 'not_applicable'=>0,
            'curriculum_lessons'=>0, 'linked_videos'=>0, 'pending_approvals'=>0,
            'drive_videos_to_review'=>0, 'missing_unit_folders'=>0, 'folder_conflicts'=>0,
        );

        foreach ($subjects as $subject) {
            $row = $this->subject_state($ids, $subject, $run, $inventory, $by_id, $children);
            $rows[] = $row;
            $totals['subjects']++;
            $totals[$row['status']]++;
            foreach (array('curriculum_lessons','linked_videos','pending_approvals','drive_videos_to_review','missing_unit_folders','folder_conflicts') as $key) {
                $totals[$key] += absint($row[$key]);
            }
        }

        return array(
            'academic_year_id'=>$ids[0], 'semester_id'=>$ids[1], 'grade_id'=>$ids[2],
            'inventory'=>$inventory, 'totals'=>$totals, 'subjects'=>$rows,
            'deployment_ready'=>$totals['blocked'] === 0 && $totals['attention'] === 0,
            'read_only'=>true, 'authoritative_state_changed'=>false, 'drive_mutations'=>0,
        );
    }

    private function subject_state($ids, $subject, $run, $inventory, $by_id, $children)
    {
        $subject_id = absint($subject->id ?? 0);
        $subject_name = sanitize_text_field($subject->subject_name ?? '');
        $scope_key = sprintf('subject:%d:%d:%d:%d', $ids[0], $ids[1], $ids[2], $subject_id);
        $units = $this->curriculum->get_curriculum_lessons($ids[0], $ids[1], $ids[2], $subject_id);
        $curriculum_error = is_wp_error($units);
        if ($curriculum_error) { $units = array(); }

        $lesson_count = 0;
        foreach ((array) $units as $unit) { $lesson_count += count((array) ($unit->lessons ?? array())); }

        $mapping = $this->mapping->get_confirmed_mapping_for_scope($scope_key);
        $mapping_current = $mapping && $run && $inventory['usable']
            && hash_equals((string) $mapping->root_config_hash, (string) $run->root_config_hash);
        $subject_folder_id = $mapping_current ? (string) $mapping->drive_folder_id : '';
        $subject_observed = $subject_folder_id !== '' && isset($by_id[$subject_folder_id])
            && $by_id[$subject_folder_id]->item_type === 'folder';

        $missing_units = 0;
        $folder_conflicts = 0;
        if ($subject_observed) {
            foreach ((array) $units as $unit) {
                $expected = $this->normalizer->normalize_text((string) ($unit->unit_name ?? ''));
                $matches = array_values(array_filter($children[$subject_folder_id] ?? array(), function ($folder) use ($expected) {
                    $actual = $this->normalizer->normalize_text((string) $folder->item_name);
                    return $expected !== '' && ($expected === $actual || str_replace(' ', '', $expected) === str_replace(' ', '', $actual));
                }));
                if (count($matches) === 0) { $missing_units++; }
                elseif (count($matches) > 1) { $folder_conflicts++; }
            }
        } elseif ($units) {
            $missing_units = count($units);
        }

        $link_counts = $this->link_counts($ids, $subject_id);
        $drive_video_ids = $subject_observed ? $this->descendant_video_ids($subject_folder_id, $by_id) : array();
        $linked_ids = $this->linked_drive_ids($ids, $subject_id);
        $to_review = count(array_diff($drive_video_ids, $linked_ids));
        $staging = ($mapping && $run) ? $this->staging_counts(absint($mapping->id), absint($run->id)) : array('pending'=>0, 'conflicts'=>0);

        $issues = array();
        $status = 'ready';
        $next_action = __('لا يوجد إجراء مطلوب.', 'olama-media-library');
        if ($curriculum_error || !$units || !$lesson_count) {
            $status = 'not_applicable';
            $issues[] = __('لا توجد وحدات ودروس منهجية ضمن هذا النطاق.', 'olama-media-library');
            $next_action = __('غير مشمولة في تشغيل مكتبة الفيديو لهذا الفصل.', 'olama-media-library');
        } elseif (!$inventory['usable']) {
            $status = 'blocked';
            $next_action = __('راجع حالة الجرد العامة أعلى الجدول.', 'olama-media-library');
        } elseif (!$mapping_current || !$subject_observed) {
            $status = 'attention';
            $issues[] = __('مجلد المادة غير معتمد مقابل أحدث جرد.', 'olama-media-library');
            $next_action = __('راجع ربط المادة أو أنشئ شجرة مجلداتها.', 'olama-media-library');
        } elseif ($folder_conflicts > 0) {
            $status = 'blocked';
            $issues[] = sprintf(__('يوجد %d تعارض بسبب تكرار مجلدات الوحدات.', 'olama-media-library'), $folder_conflicts);
            $next_action = __('عالج المجلدات المكررة يدوياً ثم شغّل جرداً جديداً.', 'olama-media-library');
        } elseif ($missing_units > 0) {
            $status = 'attention';
            $issues[] = sprintf(__('يوجد %d مجلد وحدة مفقود من شجرة المنهج.', 'olama-media-library'), $missing_units);
            $next_action = __('أنشئ وراجع خطة المجلدات الناقصة.', 'olama-media-library');
        } elseif ($to_review > 0 || $staging['pending'] > 0) {
            $status = 'attention';
            $issues[] = sprintf(__('يوجد %d ملف فيديو يحتاج مراجعة المطابقة.', 'olama-media-library'), max($to_review, $staging['pending']));
            $next_action = __('افتح مطابقة الدروس وأكمل المراجعة.', 'olama-media-library');
        } elseif ($link_counts['pending'] > 0) {
            $status = 'attention';
            $issues[] = sprintf(__('يوجد %d فيديو مربوط بانتظار الاعتماد.', 'olama-media-library'), $link_counts['pending']);
            $next_action = __('اعتمد الفيديوهات المعلّقة أو ارفضها.', 'olama-media-library');
        }

        return array(
            'subject_id'=>$subject_id, 'subject_name'=>$subject_name, 'scope_key'=>$scope_key,
            'status'=>$status, 'mapping_id'=>$mapping_current ? absint($mapping->id) : 0,
            'mapping_confirmed'=>(bool) ($mapping_current && $subject_observed),
            'unit_folders_total'=>count((array) $units),
            'missing_unit_folders'=>$missing_units, 'folder_conflicts'=>$folder_conflicts,
            'curriculum_lessons'=>$lesson_count, 'linked_videos'=>$link_counts['total'],
            'approved_videos'=>$link_counts['approved'], 'pending_approvals'=>$link_counts['pending'],
            'drive_videos'=>count($drive_video_ids), 'drive_videos_to_review'=>$to_review,
            'staged_pending'=>$staging['pending'], 'staged_conflicts'=>$staging['conflicts'],
            'issues'=>$issues, 'next_action'=>$next_action,
        );
    }

    private function inventory_state($run, $current_hash)
    {
        if (!$run) {
            return array('usable'=>false, 'fresh'=>false, 'age_hours'=>null, 'duplicate_sibling_folders'=>0, 'message'=>__('لا يوجد جرد مكتمل لـ Google Drive.', 'olama-media-library'));
        }
        $root_matches = hash_equals((string) $run->root_config_hash, (string) $current_hash);
        $finished = strtotime((string) $run->finished_at);
        $age_hours = $finished ? max(0, round((current_time('timestamp') - $finished) / HOUR_IN_SECONDS, 1)) : null;
        $max_age = max(1, absint(apply_filters('olama_media_rollout_inventory_max_age_hours', 24)));
        $fresh = $age_hours !== null && $age_hours <= $max_age;
        $usable = $root_matches && absint($run->errors) === 0 && $fresh;
        $summary = json_decode((string) $run->summary, true);
        $duplicates = is_array($summary) ? absint($summary['duplicate_sibling_folders'] ?? 0) : 0;
        if (!$root_matches) { $message = __('الجرد يعود إلى إعداد مختلف لمجلد Drive الرئيسي.', 'olama-media-library'); }
        elseif (absint($run->errors) > 0) { $message = __('اكتمل أحدث جرد مع وجود أخطاء.', 'olama-media-library'); }
        elseif (!$fresh) { $message = sprintf(__('أحدث جرد أقدم من %d ساعة.', 'olama-media-library'), $max_age); }
        else { $message = __('أحدث جرد لـ Google Drive حديث ومكتمل دون أخطاء.', 'olama-media-library'); }
        return array(
            'usable'=>$usable, 'fresh'=>$fresh, 'root_matches'=>$root_matches, 'age_hours'=>$age_hours,
            'max_age_hours'=>$max_age, 'run_id'=>absint($run->id), 'run_uuid'=>(string) $run->run_uuid,
            'finished_at'=>(string) $run->finished_at, 'errors'=>absint($run->errors),
            'duplicate_sibling_folders'=>$duplicates, 'message'=>$message,
        );
    }

    private function descendant_video_ids($folder_id, $by_id)
    {
        $ids = array();
        foreach ($by_id as $item) {
            if ($item->item_type !== 'file' || !$this->is_video($item) || !$this->is_descendant($item, $folder_id, $by_id)) { continue; }
            $ids[] = (string) $item->drive_item_id;
        }
        return array_values(array_unique($ids));
    }

    private function is_descendant($item, $folder_id, $by_id)
    {
        $parent = (string) $item->parent_drive_folder_id;
        $seen = array();
        while ($parent !== '') {
            if ($parent === (string) $folder_id) { return true; }
            if (isset($seen[$parent]) || !isset($by_id[$parent])) { return false; }
            $seen[$parent] = true;
            $parent = (string) $by_id[$parent]->parent_drive_folder_id;
        }
        return false;
    }

    private function is_video($item)
    {
        return strpos((string) $item->mime_type, 'video/') === 0 || preg_match('/\.mp4$/i', (string) $item->item_name);
    }

    private function linked_drive_ids($ids, $subject_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_lesson_video_links';
        return array_map('strval', (array) $wpdb->get_col($wpdb->prepare(
            "SELECT drive_file_id FROM {$table} WHERE academic_year_id=%d AND semester_id=%d AND grade_id=%d AND subject_id=%d AND link_status='active'",
            $ids[0], $ids[1], $ids[2], $subject_id
        )));
    }

    private function link_counts($ids, $subject_id)
    {
        global $wpdb;
        $links = $wpdb->prefix . 'olama_lesson_video_links';
        $files = $wpdb->prefix . 'olama_drive_files';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT COUNT(DISTINCT l.drive_file_id) total,
                    COUNT(DISTINCT CASE WHEN l.approval_status='approved' THEN l.drive_file_id END) approved,
                    COUNT(DISTINCT CASE WHEN l.approval_status='pending' THEN l.drive_file_id END) pending
             FROM {$links} l INNER JOIN {$files} f ON f.drive_file_id=l.drive_file_id
             WHERE l.academic_year_id=%d AND l.semester_id=%d AND l.grade_id=%d AND l.subject_id=%d
               AND l.link_status='active' AND f.scan_status='active'",
            $ids[0], $ids[1], $ids[2], $subject_id
        ));
        return array('total'=>absint($row->total ?? 0), 'approved'=>absint($row->approved ?? 0), 'pending'=>absint($row->pending ?? 0));
    }

    private function staging_counts($mapping_id, $run_id)
    {
        global $wpdb;
        $table = $wpdb->prefix . 'olama_drive_reconciliation_items';
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT SUM(decision_status='pending' AND commit_status='pending') pending,
                    SUM(proposal_status IN ('ambiguous','unmatched') AND decision_status='pending' AND commit_status='pending') conflicts
             FROM {$table} WHERE subject_mapping_id=%d AND discovery_run_id=%d",
            $mapping_id, $run_id
        ));
        return array('pending'=>absint($row->pending ?? 0), 'conflicts'=>absint($row->conflicts ?? 0));
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
