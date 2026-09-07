<?php
if (!defined('ABSPATH')) { exit; }

/** Resolves an existing reviewed unit folder for uploads without creating Drive folders. */
class Olama_Media_Safe_Upload_Folder_Resolver
{
    private $inventory;
    private $mapping;
    private $normalizer;
    private $db;

    public function __construct($inventory = null, $mapping = null, $normalizer = null, $db = null)
    {
        $this->inventory = $inventory ?: new Olama_Media_Drive_Inventory_Repository();
        $this->mapping = $mapping ?: new Olama_Media_Drive_Mapping();
        $this->normalizer = $normalizer ?: new Olama_Media_Normalizer();
        $this->db = $db ?: new Olama_Media_DB();
    }

    public function resolve($drive, array $meta)
    {
        $ids = array_map('absint', array(
            $meta['academic_year_id'] ?? 0, $meta['semester_id'] ?? 0,
            $meta['grade_id'] ?? 0, $meta['subject_id'] ?? 0,
        ));
        $unit_id = absint($meta['unit_id'] ?? 0);
        $lesson_id = absint($meta['lesson_id'] ?? 0);
        if (in_array(0, $ids, true) || !$unit_id || !$lesson_id) {
            return new WP_Error('safe_upload_scope_invalid', __('بيانات المادة أو الوحدة غير مكتملة. أعد تحميل المنهاج ثم حاول مرة أخرى.', 'olama-media-library'));
        }

        $units = $this->db->get_curriculum_with_assets($ids[0], $ids[1], $ids[2], $ids[3]);
        if (is_wp_error($units)) { return $units; }
        $unit_name = '';
        foreach ((array) $units as $unit) {
            if (absint($unit->id) !== $unit_id) { continue; }
            foreach ((array) ($unit->lessons ?? array()) as $lesson) {
                if (absint($lesson->id) === $lesson_id) {
                    $unit_name = sanitize_text_field($unit->unit_name);
                    break 2;
                }
            }
        }
        if ($this->normalizer->normalize_text($unit_name) === '') {
            return new WP_Error('safe_upload_curriculum_mismatch', __('الدرس المحدد لا ينتمي إلى الوحدة والمادة المختارتين. أعد تحميل المنهاج ثم حاول مرة أخرى.', 'olama-media-library'));
        }

        $scope_key = sprintf('subject:%d:%d:%d:%d', $ids[0], $ids[1], $ids[2], $ids[3]);
        $mapping = $this->mapping->get_confirmed_mapping_for_scope($scope_key);
        if (!$mapping) {
            return new WP_Error('safe_upload_mapping_required', __('يجب اعتماد مجلد المادة من تبويب فحص الربط قبل رفع الفيديوهات.', 'olama-media-library'));
        }
        $run = $this->inventory->get_latest_completed_run();
        if (!$run || !hash_equals((string) $mapping->root_config_hash, (string) $run->root_config_hash) ||
            !hash_equals((string) $run->root_config_hash, $this->current_root_config_hash())) {
            return new WP_Error('safe_upload_inventory_stale', __('ربط المادة أو جرد Drive غير حديث. شغّل جرداً جديداً من فحص الربط ثم حاول مرة أخرى.', 'olama-media-library'));
        }

        $expected = $this->normalizer->normalize_text($unit_name);
        $inventory_matches = array_values(array_filter($this->inventory->get_all_observations($run->id), function ($item) use ($mapping, $expected) {
            return (string) $item->item_type === 'folder'
                && hash_equals((string) $mapping->drive_folder_id, (string) $item->parent_drive_folder_id)
                && $this->normalizer->normalize_text($item->item_name) === $expected;
        }));
        if (!$inventory_matches) {
            return new WP_Error('safe_upload_unit_missing', __('مجلد الوحدة غير موجود تحت مجلد المادة المعتمد. أنشئه عبر خطة المجلدات ثم شغّل جرداً جديداً.', 'olama-media-library'));
        }
        if (count($inventory_matches) !== 1) {
            return new WP_Error('safe_upload_unit_ambiguous', __('يوجد أكثر من مجلد مطابق لهذه الوحدة. أوقف الرفع وراجع التعارض في فحص الربط.', 'olama-media-library'));
        }

        $live_matches = $this->find_live_unit_folders($drive, (string) $mapping->drive_folder_id, $expected);
        if (is_wp_error($live_matches)) { return $live_matches; }
        if (count($live_matches) !== 1 || !hash_equals((string) $inventory_matches[0]->drive_item_id, (string) $live_matches[0]['id'])) {
            return new WP_Error('safe_upload_unit_changed', __('تغيّر مجلد الوحدة في Drive بعد آخر جرد. لم يبدأ الرفع؛ شغّل جرداً جديداً وراجع الربط.', 'olama-media-library'));
        }
        return sanitize_text_field($live_matches[0]['id']);
    }

    private function find_live_unit_folders($drive, $subject_folder_id, $expected)
    {
        $matches = array();
        $token = '';
        $seen_tokens = array();
        $pages = 0;
        do {
            if ($pages++ >= 100 || isset($seen_tokens[$token])) {
                return new WP_Error('safe_upload_pagination_invalid', __('تعذر التحقق الآمن من مجلد الوحدة في Drive.', 'olama-media-library'));
            }
            $seen_tokens[$token] = true;
            $page = $drive->list_folder_children_page($subject_folder_id, $token, 200);
            if (is_wp_error($page)) { return $page; }
            foreach ((array) ($page['items'] ?? array()) as $item) {
                if (($item['mime_type'] ?? '') !== 'application/vnd.google-apps.folder' || !empty($item['trashed'])) { continue; }
                if ($this->normalizer->normalize_text($item['name'] ?? '') === $expected) { $matches[] = $item; }
            }
            $token = (string) ($page['next_page_token'] ?? '');
        } while ($token !== '');
        return $matches;
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
