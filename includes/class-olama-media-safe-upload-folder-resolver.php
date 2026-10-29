<?php
if (!defined('ABSPATH')) { exit; }

/** Prepare the selected lesson's canonical Drive branch before either upload transport. */
class Olama_Media_Safe_Upload_Folder_Resolver
{
    private $mapping;
    private $normalizer;
    private $db;
    private $curriculum;

    // The first argument is retained for compatibility. Uploads now verify Drive live.
    public function __construct($inventory = null, $mapping = null, $normalizer = null, $db = null, $curriculum = null)
    {
        $this->mapping = $mapping ?: new Olama_Media_Drive_Mapping();
        $this->normalizer = $normalizer ?: new Olama_Media_Normalizer();
        $this->db = $db ?: new Olama_Media_DB();
        $this->curriculum = $curriculum ?: new Olama_Media_Curriculum_Adapter();
    }

    public function resolve($drive, array $meta)
    {
        global $wpdb;
        if (!Olama_Media_Feature_Flags::enabled(Olama_Media_Feature_Flags::DRIVE_UPLOAD)) {
            return Olama_Media_Feature_Flags::error(Olama_Media_Feature_Flags::DRIVE_UPLOAD);
        }
        $ids = array_map('absint', array($meta['academic_year_id'] ?? 0, $meta['semester_id'] ?? 0, $meta['grade_id'] ?? 0, $meta['subject_id'] ?? 0));
        $unit_id = absint($meta['unit_id'] ?? 0);
        $lesson_id = absint($meta['lesson_id'] ?? 0);
        if (in_array(0, $ids, true) || !$unit_id || !$lesson_id) {
            return new WP_Error('safe_upload_scope_invalid', __('بيانات المنهج غير مكتملة. أعد تحميل المنهاج ثم حاول مرة أخرى.', 'olama-media-library'));
        }
        if (!$this->curriculum->is_valid_scope($ids[0], $ids[1], $ids[2], $ids[3])) {
            return new WP_Error('safe_upload_scope_invalid', __('السنة أو الفصل أو الصف أو المادة لا تنتمي إلى النطاق نفسه. أعد تحميل المنهاج.', 'olama-media-library'));
        }
        $units = $this->db->get_curriculum_with_assets($ids[0], $ids[1], $ids[2], $ids[3]);
        if (is_wp_error($units)) { return $units; }
        $unit_name = '';
        foreach ((array) $units as $unit) {
            if (absint($unit->id) !== $unit_id) { continue; }
            foreach ((array) ($unit->lessons ?? array()) as $lesson) {
                if (absint($lesson->id) === $lesson_id) { $unit_name = sanitize_text_field($unit->unit_name); break 2; }
            }
        }
        if ($this->normalizer->normalize_text($unit_name) === '') {
            return new WP_Error('safe_upload_curriculum_mismatch', __('الدرس المحدد لا ينتمي إلى الوحدة والمادة المختارتين. أعد تحميل المنهاج ثم حاول مرة أخرى.', 'olama-media-library'));
        }
        $names = $this->curriculum->get_names($ids[0], $ids[1], $ids[2], $ids[3]);
        $specs = array();
        foreach (array('academic_year', 'semester', 'grade', 'subject') as $index => $type) {
            $name = sanitize_text_field($names[$type] ?? '');
            if ($this->normalizer->normalize_text($name) === '') {
                return new WP_Error('safe_upload_scope_invalid', __('اسم أحد مستويات المنهج مفقود. راجع بيانات السنة والفصل والصف والمادة.', 'olama-media-library'));
            }
            $specs[] = array('type'=>$type, 'entity_id'=>$ids[$index], 'name'=>$name);
        }
        $specs[] = array('type'=>'unit', 'entity_id'=>$unit_id, 'name'=>$unit_name);
        $settings = get_option('academy_media_library_settings', array());
        $root_id = sanitize_text_field($settings['root_folder_id'] ?? '');
        if ($root_id === '') { return new WP_Error('safe_upload_root_required', __('حدد المجلد الجذر في إعدادات Drive قبل الرفع.', 'olama-media-library')); }
        $scope_key = sprintf('subject:%d:%d:%d:%d', $ids[0], $ids[1], $ids[2], $ids[3]);
        // Serialize shared ancestors across subjects, and coordinate with reviewed plans.
        $locks = array('olama_upload_' . substr(hash('sha256', $root_id), 0, 40), 'olama_folder_' . substr(hash('sha256', $scope_key), 0, 40));
        $acquired = array();
        try {
            foreach ($locks as $lock) {
                if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) {
                    return new WP_Error('safe_upload_folder_busy', __('يجري تجهيز مجلدات Drive لعملية أخرى. أعد محاولة الرفع بعد لحظات.', 'olama-media-library'), array('retryable'=>true));
                }
                $acquired[] = $lock;
            }
            return $this->prepare_branch($drive, $specs, $scope_key, $settings);
        } finally {
            foreach (array_reverse($acquired) as $lock) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
        }
    }

    private function prepare_branch($drive, $specs, $scope_key, $settings)
    {
        $root_id = sanitize_text_field($settings['root_folder_id']);
        $root_hash = $this->root_hash($settings);
        $mapping = $this->mapping->get_mapping_for_scope($scope_key);
        if ($mapping && ($mapping->mapping_status !== 'confirmed' || !hash_equals((string) $mapping->root_config_hash, $root_hash))) {
            return $this->mapping_changed();
        }
        $root = $drive->get_file_metadata($root_id);
        if (is_wp_error($root)) { return $this->drive_error($root); }
        if (!$this->valid_folder($root, $root_id)) {
            return new WP_Error('safe_upload_root_invalid', __('المجلد الجذر المحدد غير صالح أو موجود في المهملات. راجع إعدادات Drive.', 'olama-media-library'));
        }
        $start = $this->root_start_index($root, $specs, $settings);
        if (is_wp_error($start)) { return $start; }
        $subject_id = $start === 4 ? $root_id : '';
        if ($subject_id !== '' && $mapping && !hash_equals((string) $mapping->drive_folder_id, $subject_id)) { return $this->mapping_changed(); }
        $branch = array_slice($specs, $start);
        $parent_id = $root_id;
        $evidence = array('root_folder_id'=>$root_id);
        // Inspect the entire existing prefix before creating anything. Never
        // recreate missing ancestors of an already confirmed subject folder.
        foreach ($branch as &$spec) {
            $spec['folder_id'] = '';
            if ($parent_id !== '') {
                $bound_id = $spec['type'] === 'subject' && $mapping ? (string) $mapping->drive_folder_id : '';
                $found = $this->find_child($drive, $parent_id, $spec, $bound_id, $spec['type'] === 'subject' && (bool) $mapping);
                if (is_wp_error($found)) { return $found; }
                $spec['folder_id'] = $found;
            }
            if ($spec['type'] === 'subject' && $mapping && $spec['folder_id'] === '') { return $this->mapping_changed(); }
            $parent_id = $spec['folder_id'];
        }
        unset($spec);
        $parent_id = $root_id;
        foreach ($branch as $spec) {
            if (!$this->root_unchanged($root_hash)) { return $this->root_changed(); }
            // Re-list immediately before every reuse/create, including newly created parents.
            $allow_renamed = $spec['type'] === 'subject' && (bool) $mapping;
            $folder_id = $this->find_child($drive, $parent_id, $spec, $spec['folder_id'], $allow_renamed);
            if (is_wp_error($folder_id)) { return $folder_id; }
            if ($folder_id === '') {
                $result = $drive->create_upload_folder($spec['name'], $parent_id);
                if (is_wp_error($result)) { return $this->drive_error($result); }
                $folder_id = sanitize_text_field($result['id'] ?? '');
            }
            if ($folder_id === '') {
                return new WP_Error('safe_upload_folder_create_failed', __('لم يُرجع Drive معرف المجلد. لم يبدأ رفع الفيديو؛ أعد المحاولة.', 'olama-media-library'), array('retryable'=>true));
            }
            $live = $drive->get_file_metadata($folder_id);
            if (is_wp_error($live)) { return $this->drive_error($live); }
            if (!$this->valid_folder($live, $folder_id) || !in_array($parent_id, (array) ($live['parents'] ?? array()), true)
                || (!$allow_renamed && !$this->name_matches($spec, $live['name'] ?? ''))) {
                return new WP_Error('safe_upload_folder_changed', __('تغيّر مسار مجلد أثناء تجهيز الرفع. راجع المجلدات من فحص الربط.', 'olama-media-library'));
            }
            $checked = $this->find_child($drive, $parent_id, $spec, $folder_id, $allow_renamed);
            if (is_wp_error($checked)) { return $checked; }
            $evidence[$spec['type']] = array('id'=>$folder_id, 'parent_id'=>$parent_id, 'name'=>$live['name'], 'expected_name'=>$spec['name'], 'allow_renamed'=>$allow_renamed);
            if ($spec['type'] === 'subject') { $subject_id = $folder_id; }
            $parent_id = $folder_id;
        }
        // Recheck every ancestor after creation, before saving the mapping or starting a session.
        foreach ($evidence as $type => $item) {
            if ($type === 'root_folder_id') { continue; }
            $live = $drive->get_file_metadata($item['id']);
            if (is_wp_error($live)) { return $this->drive_error($live); }
            if (!$this->valid_folder($live, $item['id']) || !in_array($item['parent_id'], (array) ($live['parents'] ?? array()), true)
                || (!$item['allow_renamed'] && !$this->name_matches(array('type'=>$type,'name'=>$item['expected_name']), $live['name'] ?? ''))) {
                return $this->mapping_changed();
            }
        }
        $root_live = $drive->get_file_metadata($root_id);
        if (is_wp_error($root_live)) { return $this->drive_error($root_live); }
        if (!$this->valid_folder($root_live, $root_id) || $root_live['name'] !== $root['name']) { return $this->root_changed(); }
        if (!$this->root_unchanged($root_hash)) { return $this->root_changed(); }
        $saved = $this->mapping->confirm_upload_subject($scope_key, $subject_id, $root_hash, $evidence);
        return is_wp_error($saved) ? $saved : $parent_id;
    }

    private function root_start_index($root, $specs, $settings)
    {
        $level = sanitize_key($settings['root_scope_level'] ?? 'unknown');
        $scope_id = absint($settings['root_scope_id'] ?? 0);
        if ($level !== 'unknown') {
            foreach (array_slice($specs, 0, 4) as $index => $spec) {
                if ($level !== $spec['type']) { continue; }
                if (!$scope_id || $scope_id !== $spec['entity_id']) {
                    return new WP_Error('safe_upload_root_scope_mismatch', __('نطاق المجلد الجذر لا يطابق المنهج المختار. راجع إعدادات Drive.', 'olama-media-library'));
                }
                return $index + 1;
            }
            return new WP_Error('safe_upload_root_scope_mismatch', __('نطاق المجلد الجذر غير مدعوم. راجع إعدادات Drive.', 'olama-media-library'));
        }
        if ($this->name_matches($specs[0], $root['name'])) { return 1; }
        // A deeper root needs an explicit scope ID to distinguish academic years.
        foreach (array_slice($specs, 1, 3) as $spec) {
            if ($this->name_matches($spec, $root['name'])) {
                return new WP_Error('safe_upload_root_scope_required', __('المجلد الجذر يبدأ من الفصل أو الصف أو المادة دون نطاق محدد. اختر في إعدادات Drive المجلد العام أو مجلد السنة الدراسية لتجنب إنشاء شجرة في مسار خاطئ.', 'olama-media-library'));
            }
        }
        $root_name = $this->normalizer->normalize_text($root['name']);
        if (preg_match('/(?:^| )[0-9]{4} [0-9]{4}(?: |$)/', $root_name) || $this->grade_number($root_name) !== null
            || preg_match('/(?:^| )(?:semester|الفصل)(?: |$)/u', $root_name)) {
            return new WP_Error('safe_upload_root_scope_mismatch', __('يبدو أن المجلد الجذر يخص سنة أو فصلاً أو صفاً مختلفاً. راجع إعدادات Drive قبل الرفع.', 'olama-media-library'));
        }
        return 0;
    }

    private function find_child($drive, $parent_id, $spec, $bound_id = '', $allow_renamed = false)
    {
        $exact = array(); $similar = array(); $bound = null; $token = ''; $seen = array();
        do {
            if (count($seen) >= 100 || isset($seen[$token])) {
                return new WP_Error('safe_upload_pagination_invalid', __('تعذر التحقق الكامل من مجلدات Drive. لم يبدأ رفع الفيديو.', 'olama-media-library'));
            }
            $seen[$token] = true;
            $page = $drive->list_folder_children_page($parent_id, $token, 200);
            if (is_wp_error($page)) { return $this->drive_error($page); }
            foreach ((array) ($page['items'] ?? array()) as $item) {
                if (($item['mime_type'] ?? '') !== 'application/vnd.google-apps.folder' || !empty($item['trashed'])) { continue; }
                $id = sanitize_text_field($item['id'] ?? '');
                if ($id === '') { return new WP_Error('safe_upload_folder_invalid', __('أعاد Drive مجلداً دون معرف صالح. لم يبدأ رفع الفيديو.', 'olama-media-library')); }
                if ($bound_id !== '' && hash_equals($bound_id, $id)) { $bound = $item; }
                if ($this->name_matches($spec, $item['name'] ?? '')) { $exact[$id] = $item; }
                elseif ($this->names_are_similar($spec['name'], $item['name'] ?? '')) { $similar[$id] = $item; }
            }
            $token = (string) ($page['next_page_token'] ?? '');
        } while ($token !== '');
        if (count($exact) > 1 || ($bound_id !== '' && $exact && !isset($exact[$bound_id]))) {
            return new WP_Error('safe_upload_folder_ambiguous', sprintf(__('يوجد تعارض أو أكثر من مجلد مطابق لـ «%s» تحت الأصل نفسه. راجع فحص الربط قبل الرفع.', 'olama-media-library'), $spec['name']));
        }
        if ($bound_id !== '') {
            if (!$bound || (!$allow_renamed && !isset($exact[$bound_id]))) { return $this->mapping_changed(); }
            return $bound_id;
        }
        if ($exact) { return (string) key($exact); }
        if ($similar) {
            return new WP_Error('safe_upload_folder_review_required', sprintf(__('يوجد مجلد مشابه لـ «%s». راجع اختياره في فحص الربط لتجنب إنشاء مجلد مكرر.', 'olama-media-library'), $spec['name']));
        }
        return '';
    }

    private function name_matches($spec, $actual)
    {
        $expected = $this->normalizer->normalize_text($spec['name']);
        $actual = $this->normalizer->normalize_text($actual);
        if ($expected !== '' && str_replace(' ', '', $expected) === str_replace(' ', '', $actual)) { return true; }
        return $spec['type'] === 'grade' && $this->grade_number($expected) !== null && $this->grade_number($expected) === $this->grade_number($actual);
    }

    private function grade_number($name)
    {
        return $this->normalizer->grade_number($name);
    }

    private function names_are_similar($expected, $actual)
    {
        $expected = $this->normalizer->normalize_text($expected); $actual = $this->normalizer->normalize_text($actual);
        if ($expected === '' || $actual === '') { return false; }
        if (strpos($expected, $actual) !== false || strpos($actual, $expected) !== false) { return true; }
        $a = array_values(array_unique(explode(' ', $expected))); $b = array_values(array_unique(explode(' ', $actual)));
        $shared = count(array_intersect($a, $b));
        return $shared >= 2 && $shared / max(count($a), count($b)) >= 0.5;
    }

    private function valid_folder($folder, $id)
    {
        return (string) ($folder['id'] ?? '') === $id && ($folder['mime_type'] ?? '') === 'application/vnd.google-apps.folder' && empty($folder['trashed']);
    }
    private function root_hash($settings)
    {
        return hash('sha256', wp_json_encode(array('root_folder_id'=>sanitize_text_field($settings['root_folder_id'] ?? ''),
            'root_scope_level'=>sanitize_key($settings['root_scope_level'] ?? 'unknown'), 'root_scope_id'=>absint($settings['root_scope_id'] ?? 0))));
    }
    private function root_unchanged($hash) { return hash_equals($hash, $this->root_hash(get_option('academy_media_library_settings', array()))); }
    private function root_changed() { return new WP_Error('safe_upload_root_changed', __('تغيّرت إعدادات المجلد الجذر أثناء التجهيز. أعد محاولة الرفع بعد مراجعة الإعدادات.', 'olama-media-library')); }
    private function mapping_changed() { return new WP_Error('safe_upload_mapping_changed', __('المسار الحالي لا يطابق ربط المادة أو المجلد الذي تم التحقق منه. راجع فحص الربط قبل الرفع.', 'olama-media-library')); }
    private function drive_error($error) { return new WP_Error('safe_upload_drive_error', sprintf(__('تعذر تجهيز مجلدات Drive ولم يبدأ رفع الفيديو: %s', 'olama-media-library'), $error->get_error_message())); }
}
