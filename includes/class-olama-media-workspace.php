<?php
if (!defined('ABSPATH')) { exit; }

/** The ordinary, scoped Drive workflow. Existing repair/migration tools remain separate. */
class Olama_Media_Workspace
{
    private $mapping;
    private $curriculum;
    private $db;
    private $repo;

    public function __construct($mapping = null, $curriculum = null, $db = null, $repo = null)
    {
        $this->mapping = $mapping ?: new Olama_Media_Drive_Mapping();
        $this->curriculum = $curriculum ?: new Olama_Media_Curriculum_Adapter();
        $this->db = $db ?: new Olama_Media_DB();
        $this->repo = $repo ?: new Olama_Media_V2_Repository();
    }

    public function handle()
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'olama_media_library_nonce')) { wp_send_json_error(__('Security check failed.', 'olama-media-library'), 403); }
        if (!Olama_Media_Admin::can_access()) { wp_send_json_error(__('Unauthorized.', 'olama-media-library'), 403); }
        $op = sanitize_key($_POST['op'] ?? 'status');
        $admin = current_user_can('manage_options') || current_user_can('olama_media_drive_settings');
        if (in_array($op, array('bind', 'browse_folders', 'create_subject_folder', 'sync', 'dismiss', 'reassign'), true) && !$admin) { wp_send_json_error(__('Unauthorized.', 'olama-media-library'), 403); }
        if (in_array($op, array('browse_videos', 'link'), true) && !Olama_Media_Admin::can_upload()) { wp_send_json_error(__('Unauthorized.', 'olama-media-library'), 403); }
        $scope = array_map('absint', array($_POST['academic_year_id'] ?? 0, $_POST['semester_id'] ?? 0, $_POST['grade_id'] ?? 0, $_POST['subject_id'] ?? 0));
        if (in_array(0, $scope, true) || !$this->curriculum->is_valid_scope(...$scope)) { wp_send_json_error(__('اختر نطاق المنهج الصحيح أولاً.', 'olama-media-library')); }
        $key = 'subject:' . implode(':', $scope);
        $mapping = $this->mapping->get_mapping_for_scope($key);
        $settings = get_option('academy_media_library_settings', array());
        if ($op === 'report') {
            $result = $this->subject_report($scope, $mapping);
            is_wp_error($result) ? wp_send_json_error($result->get_error_message()) : wp_send_json_success($result);
        }
        if ($op === 'status') {
            $evidence = $mapping ? json_decode((string) $mapping->confirmation_evidence, true) : array();
            wp_send_json_success(array('folder_id'=>$mapping->drive_folder_id ?? '', 'mapping_id'=>absint($mapping->id ?? 0),
                'binding_ready'=>$mapping && $mapping->mapping_status === 'confirmed' && hash_equals($this->mapping->root_config_hash(), (string) $mapping->root_config_hash) && (bool) $this->mapping->binding_chain_ids($mapping),
                'folder_label'=>$evidence['folder_label'] ?? $evidence['subject']['name'] ?? '',
                'root_folder_id'=>sanitize_text_field($settings['root_folder_id'] ?? '')));
        }
        if (in_array($op, array('sync', 'browse_videos', 'link', 'dismiss', 'reassign'), true) && !Olama_Media_Feature_Flags::enabled(Olama_Media_Feature_Flags::DRIVE_SYNC)) {
            wp_send_json_error(Olama_Media_Feature_Flags::error(Olama_Media_Feature_Flags::DRIVE_SYNC)->get_error_message());
        }
        $drive = new Olama_Media_Drive();
        if ($op === 'bind') {
            $result = $this->bind($drive, $scope, sanitize_text_field(wp_unslash($_POST['folder_id'] ?? '')), sanitize_text_field($_POST['previous_folder_id'] ?? ''));
        } elseif ($op === 'create_subject_folder') {
            $result = $this->create_subject_folder($drive, $scope, sanitize_text_field($_POST['parent_id'] ?? ''), sanitize_text_field($_POST['root_hash'] ?? ''));
        } elseif ($op === 'browse_folders') {
            $result = $this->browse($drive, sanitize_text_field($_POST['folder_id'] ?? '') ?: (string) ($settings['root_folder_id'] ?? ''), (string) ($settings['root_folder_id'] ?? ''), sanitize_text_field($_POST['page_token'] ?? ''), true);
            if (!is_wp_error($result)) {
                $availability = $this->subject_folder_availability($drive, $scope, $result['folder_id']);
                $result['subject_folder'] = is_wp_error($availability) ? array('can_create'=>false, 'reason'=>$availability->get_error_message()) : $availability;
            }
        } else {
            if ($op === 'browse_videos') {
                $chain = $this->mapping->validate_binding($drive, $mapping);
                if (is_wp_error($chain)) { wp_send_json_error($chain->get_error_message()); }
                $result = $this->browse($drive, sanitize_text_field($_POST['folder_id'] ?? '') ?: $mapping->drive_folder_id, $mapping->drive_folder_id, sanitize_text_field($_POST['page_token'] ?? ''), false);
            } elseif ($op === 'link' || $op === 'reassign') {
                $revision = $op === 'reassign' ? sanitize_text_field($_POST['link_revision'] ?? '') : '';
                if ($op === 'reassign' && $revision === '') { wp_send_json_error(__('حدّث النتائج قبل تغيير ربط الفيديو.', 'olama-media-library')); }
                $result = $this->link($drive, $scope, $mapping, sanitize_text_field($_POST['file_id'] ?? ''), absint($_POST['lesson_id'] ?? 0), absint($_POST['part_number'] ?? 0), $revision);
            } elseif ($op === 'dismiss') {
                $result = $this->dismiss($drive, $scope, $mapping, sanitize_text_field($_POST['file_id'] ?? ''));
            } elseif ($op === 'sync') {
                $result = $this->sync_step($drive, $scope, $mapping, sanitize_key($_POST['token'] ?? ''));
            } else { $result = new WP_Error('unknown_operation', __('طلب غير معروف.', 'olama-media-library')); }
        }
        is_wp_error($result) ? wp_send_json_error($result->get_error_message()) : wp_send_json_success($result);
    }

    public function bind($drive, $scope, $folder_id, $previous_folder_id)
    {
        global $wpdb;
        $key = 'subject:' . implode(':', $scope);
        $lock = 'olama_folder_' . substr(hash('sha256', $key), 0, 40);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) { return new WP_Error('binding_busy', __('يجري تعديل الربط. أعد المحاولة.', 'olama-media-library')); }
        try {
            $hash = $this->mapping->root_config_hash();
            $settings = get_option('academy_media_library_settings', array());
            // Explicit root scopes must agree with the selected curriculum IDs.
            $levels = array('academic_year'=>0, 'semester'=>1, 'grade'=>2, 'subject'=>3);
            $level = $settings['root_scope_level'] ?? 'unknown';
            if (isset($levels[$level]) && absint($settings['root_scope_id'] ?? 0) !== $scope[$levels[$level]]) { return new WP_Error('binding_scope', __('نطاق المجلد الجذر مختلف عن المنهج المحدد.', 'olama-media-library')); }
            $chain = $this->mapping->folder_chain($drive, $folder_id, (string) ($settings['root_folder_id'] ?? ''));
            if (is_wp_error($chain)) { return $chain; }
            $old = $this->mapping->get_mapping_for_scope($key);
            if ((string) ($old->drive_folder_id ?? '') !== $previous_folder_id || !hash_equals($hash, $this->mapping->root_config_hash())) { return new WP_Error('binding_changed', __('تغيّر الربط أو إعدادات Drive. أعد تحميل حالة الربط.', 'olama-media-library')); }
            $now = current_time('mysql');
            $label = implode(' / ', array_reverse(array_column($chain, 'name')));
            $data = array('scope_key'=>$key, 'scope_type'=>'subject', 'academic_year_id'=>$scope[0], 'semester_id'=>$scope[1], 'grade_id'=>$scope[2], 'subject_id'=>$scope[3],
                'drive_folder_id'=>$folder_id, 'mapping_status'=>'confirmed', 'root_config_hash'=>$hash, 'confirmation_method'=>'workspace_selected',
                'confirmation_evidence'=>wp_json_encode(array('bound_ancestor_ids'=>array_column($chain, 'id'), 'folder_label'=>$label,
                    'previous_folder_id'=>$previous_folder_id)), 'confirmed_by'=>get_current_user_id(), 'confirmed_at'=>$now, 'updated_at'=>$now);
            if ($old) {
                $ok = $wpdb->update($wpdb->prefix . 'olama_curriculum_drive_map', $data, array('id'=>$old->id, 'drive_folder_id'=>$old->drive_folder_id, 'updated_at'=>$old->updated_at));
            } else { $data['created_at'] = $now; $ok = $wpdb->insert($wpdb->prefix . 'olama_curriculum_drive_map', $data); }
            return $ok !== false && ($ok > 0 || ($old && $old->drive_folder_id === $folder_id && $old->confirmation_evidence === $data['confirmation_evidence']))
                ? array('folder_id'=>$folder_id, 'folder_label'=>$label) : new WP_Error('binding_save_failed', __('تعذر حفظ الربط. أعد تحميل الحالة ثم حاول مجدداً.', 'olama-media-library'));
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }

    public function browse($drive, $folder_id, $boundary, $page_token, $folders_only)
    {
        $chain = $this->mapping->folder_chain($drive, $folder_id, $boundary);
        if (is_wp_error($chain)) { return $chain; }
        $page = $drive->list_folder_children_page($folder_id, $page_token, 100);
        if (is_wp_error($page)) { return $page; }
        $items = array();
        foreach ($page['items'] as $item) {
            if (!empty($item['trashed'])) { continue; }
            $folder = ($item['mime_type'] ?? '') === 'application/vnd.google-apps.folder';
            if (!$folder && ($folders_only || strpos((string) ($item['mime_type'] ?? ''), 'video/') !== 0)) { continue; }
            $link = $folder ? null : $this->repo->get_link_by_drive_file_id($item['id']);
            $items[] = array('id'=>$item['id'], 'name'=>$item['name'], 'folder'=>$folder, 'size'=>absint($item['size'] ?? 0),
                'url'=>esc_url_raw($item['web_view_link'] ?? ''), 'linked_lesson'=>($link && $link->link_status === 'active') ? absint($link->lesson_id) : 0);
        }
        return array('folder_id'=>$folder_id, 'breadcrumbs'=>array_reverse(array_map(function ($f) { return array('id'=>$f['id'], 'name'=>$f['name']); }, $chain)),
            'items'=>$items, 'next_page_token'=>$page['next_page_token']);
    }

    /** Inspect every sibling page, including Arabic-normalized equivalents. */
    public function subject_folder_availability($drive, $scope, $parent_id)
    {
        if (count($scope) !== 4 || in_array(0, $scope, true) || !$this->curriculum->is_valid_scope(...$scope)) {
            return new WP_Error('invalid_scope', __('اختر نطاق المنهج الصحيح أولاً.', 'olama-media-library'));
        }
        $settings = get_option('academy_media_library_settings', array());
        $levels = array('academic_year'=>0, 'semester'=>1, 'grade'=>2, 'subject'=>3);
        $level = $settings['root_scope_level'] ?? 'unknown';
        if (isset($levels[$level]) && absint($settings['root_scope_id'] ?? 0) !== $scope[$levels[$level]]) {
            return new WP_Error('binding_scope', __('نطاق المجلد الجذر مختلف عن المنهج المحدد.', 'olama-media-library'));
        }
        $chain = $this->mapping->folder_chain($drive, $parent_id, (string) ($settings['root_folder_id'] ?? ''));
        if (is_wp_error($chain)) { return $chain; }
        $names = $this->curriculum->get_names(...$scope);
        $name = trim(sanitize_text_field($names['subject'] ?? ''));
        $normalizer = new Olama_Media_Normalizer();
        $normalized = $normalizer->normalize_text($name);
        if ($normalized === '') { return new WP_Error('subject_name_missing', __('اسم المادة غير متوفر في بيانات المنهج.', 'olama-media-library')); }
        $already_inside = $normalizer->normalize_text($chain[0]['name']) === $normalized;
        $matches = $already_inside ? array($parent_id=>$parent_id) : array(); $seen = array(); $token = '';
        do {
            if (isset($seen[$token]) || count($seen) >= 100) { return new WP_Error('folder_pages_incomplete', __('لم يكتمل فحص المجلدات. حدّث المجلد قبل محاولة الإنشاء.', 'olama-media-library')); }
            $seen[$token] = true;
            $page = $drive->list_folder_children_page($parent_id, $token, 200);
            if (is_wp_error($page)) { return $page; }
            foreach ((array) ($page['items'] ?? array()) as $item) {
                if (empty($item['trashed']) && ($item['mime_type'] ?? '') === 'application/vnd.google-apps.folder'
                    && $normalizer->normalize_text($item['name'] ?? '') === $normalized) {
                    $id = sanitize_text_field($item['id'] ?? '');
                    if ($id === '') { return new WP_Error('folder_id_missing', __('تعذر التحقق من معرف المجلد الموجود.', 'olama-media-library')); }
                    $matches[$id] = $id;
                }
            }
            $token = (string) ($page['next_page_token'] ?? '');
        } while ($token !== '');
        $enabled = defined('OLAMA_MEDIA_REVIEWED_FOLDER_APPLY_ENABLED') && OLAMA_MEDIA_REVIEWED_FOLDER_APPLY_ENABLED === true;
        if ($already_inside) { $reason = __('المجلد المعروض يحمل اسم المادة بالفعل؛ يمكنك اعتماده مباشرة دون إنشاء مجلد داخله.', 'olama-media-library'); }
        elseif ($matches) { $reason = __('مجلد المادة موجود هنا بالفعل؛ افتحه ثم اعتمده بدلاً من إنشاء نسخة أخرى.', 'olama-media-library'); }
        elseif (!$enabled) { $reason = __('إنشاء المجلدات موقوف من مسؤول الموقع.', 'olama-media-library'); }
        else { $reason = __('سيُنشأ مجلد باسم المادة داخل المجلد المعروض حالياً، ثم يمكنك اعتماده للمادة.', 'olama-media-library'); }
        return array('name'=>$name, 'parent_name'=>$chain[0]['name'], 'existing_ids'=>array_values($matches), 'exists'=>(bool) $matches,
            'can_create'=>$enabled && !$matches, 'root_hash'=>$this->mapping->root_config_hash(),
            'reason'=>$reason);
    }

    public function create_subject_folder($drive, $scope, $parent_id, $expected_root_hash)
    {
        global $wpdb;
        if (!defined('OLAMA_MEDIA_REVIEWED_FOLDER_APPLY_ENABLED') || OLAMA_MEDIA_REVIEWED_FOLDER_APPLY_ENABLED !== true) {
            return new WP_Error('subject_folder_creation_disabled', __('إنشاء المجلدات موقوف من مسؤول الموقع.', 'olama-media-library'));
        }
        $settings = get_option('academy_media_library_settings', array());
        $root = sanitize_text_field($settings['root_folder_id'] ?? '');
        $key = 'subject:' . implode(':', $scope);
        // Same lock order as upload provisioning; serialize even across two subjects
        // with the same display name inside the selected parent.
        $locks = array('olama_upload_' . substr(hash('sha256', $root), 0, 40), 'olama_folder_' . substr(hash('sha256', $key), 0, 40));
        $acquired = array();
        try {
            foreach ($locks as $lock) {
                if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) { return new WP_Error('folder_creation_busy', __('تجهيز المجلدات قيد التنفيذ. أعد المحاولة بعد لحظات.', 'olama-media-library')); }
                $acquired[] = $lock;
            }
            if ($expected_root_hash === '' || !hash_equals($this->mapping->root_config_hash(), $expected_root_hash)) { return new WP_Error('binding_changed', __('تغيّرت إعدادات Drive. حدّث نافذة اختيار المجلد.', 'olama-media-library')); }
            $availability = $this->subject_folder_availability($drive, $scope, $parent_id);
            if (is_wp_error($availability)) { return $availability; }
            if ($availability['exists']) { return new WP_Error('subject_folder_exists', $availability['reason']); }
            $chain = $this->mapping->folder_chain($drive, $parent_id, $root);
            if (is_wp_error($chain)) { return $chain; }
            if (!hash_equals($this->mapping->root_config_hash(), $expected_root_hash)) { return new WP_Error('binding_changed', __('تغيّرت إعدادات Drive. حدّث نافذة اختيار المجلد.', 'olama-media-library')); }
            $created = $drive->create_reviewed_folder($availability['name'], $parent_id);
            if (is_wp_error($created)) { return $created; }
            // The Drive helper rechecks the exact name immediately before creation.
            if (empty($created['created'])) { return new WP_Error('subject_folder_exists', __('ظهر مجلد بالاسم نفسه أثناء التجهيز. حدّث القائمة واختر المجلد الموجود.', 'olama-media-library')); }
            $id = sanitize_text_field($created['id'] ?? '');
            $folder = $id !== '' ? $drive->get_file_metadata($id) : null;
            if (is_wp_error($folder)) { return $folder; }
            if (!$folder || ($folder['id'] ?? '') !== $id || !empty($folder['trashed']) || ($folder['mime_type'] ?? '') !== 'application/vnd.google-apps.folder'
                || !in_array($parent_id, (array) ($folder['parents'] ?? array()), true) || ($folder['name'] ?? '') !== $availability['name']) {
                return new WP_Error('created_folder_invalid', __('تعذر تأكيد المجلد الجديد. حدّث القائمة قبل المحاولة مجدداً.', 'olama-media-library'));
            }
            if (!hash_equals($this->mapping->root_config_hash(), $expected_root_hash)) { return new WP_Error('binding_changed', __('أُنشئ المجلد لكن إعدادات Drive تغيّرت. أعد فتح نافذة الاختيار للتحقق منه.', 'olama-media-library')); }
            return array('folder_id'=>$id, 'folder_name'=>$availability['name'], 'created'=>true);
        } finally { foreach (array_reverse($acquired) as $lock) { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); } }
    }

    public function link($drive, $scope, $mapping, $file_id, $lesson_id, $part, $expected_revision = '')
    {
        return $this->with_binding_lock($drive, $scope, $mapping, function () use ($drive, $scope, $mapping, $file_id, $lesson_id, $part, $expected_revision) {
            return $this->perform_link($drive, $scope, $mapping, $file_id, $lesson_id, $part, $expected_revision);
        });
    }

    private function with_binding_lock($drive, $scope, $mapping, $callback)
    {
        global $wpdb;
        $key = 'subject:' . implode(':', $scope);
        $lock = 'olama_folder_' . substr(hash('sha256', $key), 0, 40);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) { return new WP_Error('binding_busy', __('الربط قيد التحديث. أعد المحاولة.', 'olama-media-library')); }
        try {
            $current = $this->mapping->get_mapping_for_scope($key);
            if (!$current || !$mapping || $this->revision($current) !== $this->revision($mapping)) { return new WP_Error('binding_changed', __('تغيّر مجلد المادة. أعد تحميل حالة الربط.', 'olama-media-library')); }
            $chain = $this->mapping->validate_binding($drive, $current);
            return is_wp_error($chain) ? $chain : $callback();
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }

    private function perform_link($drive, $scope, $mapping, $file_id, $lesson_id, $part, $expected_revision = '')
    {
        $units = $this->db->get_curriculum_with_assets(...$scope);
        if (is_wp_error($units)) { return $units; }
        $target = null;
        foreach ($units as $unit) { foreach ($unit->lessons as $lesson) { if (absint($lesson->id) === $lesson_id) { $target = $unit; } } }
        if (!$target) { return new WP_Error('invalid_lesson', __('الدرس خارج المنهج المحدد.', 'olama-media-library')); }
        $file = $drive->get_file_metadata($file_id);
        if (is_wp_error($file)) { return $file; }
        if (!empty($file['trashed']) || strpos((string) ($file['mime_type'] ?? ''), 'video/') !== 0 || count((array) ($file['parents'] ?? array())) !== 1) { return new WP_Error('invalid_video', __('الفيديو غير متاح أو ليس ملف فيديو صالحاً.', 'olama-media-library')); }
        $chain = $this->mapping->folder_chain($drive, $file['parents'][0], $mapping->drive_folder_id);
        if (is_wp_error($chain)) { return $chain; }
        $current = $this->mapping->get_mapping_for_scope('subject:' . implode(':', $scope));
        if (!$current || $this->revision($current) !== $this->revision($mapping)) { return new WP_Error('binding_changed', __('تغيّر مجلد المادة. أعد فتح نافذة الاختيار.', 'olama-media-library')); }
        $row_id = $this->observe($file, $file['parents'][0], implode('/', array_reverse(array_column($chain, 'name'))));
        if (!$row_id) { return new WP_Error('save_failed', __('تعذر حفظ الفيديو.', 'olama-media-library')); }
        return $this->repo->attach_video(array('drive_file_id'=>$file_id, 'drive_file_row_id'=>$row_id, 'academic_year_id'=>$scope[0], 'semester_id'=>$scope[1], 'grade_id'=>$scope[2], 'subject_id'=>$scope[3],
            'unit_id'=>absint($target->id), 'lesson_id'=>$lesson_id, 'part_number'=>$part ?: null, 'sequence_order'=>$part ?: $this->repo->next_sequence_order($lesson_id),
            'match_method'=>'manual', 'match_confidence'=>100, 'approval_status'=>'pending', 'link_status'=>'active', 'linked_by'=>get_current_user_id()), true, $expected_revision);
    }

    public function dismiss($drive, $scope, $mapping, $file_id)
    {
        return $this->with_binding_lock($drive, $scope, $mapping, function () use ($drive, $scope, $mapping, $file_id) {
            $file = $drive->get_file_metadata($file_id);
            if (is_wp_error($file)) { return $file; }
            if (!empty($file['trashed']) || strpos((string) ($file['mime_type'] ?? ''), 'video/') !== 0 || count((array) ($file['parents'] ?? array())) !== 1) { return new WP_Error('invalid_video', __('تعذر التحقق من الفيديو. أعد الفحص.', 'olama-media-library')); }
            $chain = $this->mapping->folder_chain($drive, $file['parents'][0], $mapping->drive_folder_id);
            if (is_wp_error($chain)) { return $chain; }
            $row_id = $this->observe($file, $file['parents'][0], implode('/', array_reverse(array_column($chain, 'name'))));
            if (!$row_id) { return new WP_Error('save_failed', __('تعذر حفظ بيانات الفيديو.', 'olama-media-library')); }
            return $this->repo->dismiss_video(array('drive_file_id'=>$file_id, 'drive_file_row_id'=>$row_id, 'academic_year_id'=>$scope[0], 'semester_id'=>$scope[1], 'grade_id'=>$scope[2], 'subject_id'=>$scope[3], 'linked_by'=>get_current_user_id()));
        });
    }

    private function report_key($scope) { return 'olama_media_subject_report_' . md5(implode(':', $scope)); }

    /** Local read only: viewing the subject never triggers Drive requests. */
    public function subject_report($scope, $mapping)
    {
        $units = $this->db->get_curriculum_with_assets(...$scope);
        if (is_wp_error($units)) { return $units; }
        $lessons = array(); $seen = array();
        foreach ($units as $unit) { foreach ($unit->lessons as $lesson) {
            if (isset($seen[$lesson->id])) { continue; } $seen[$lesson->id] = true;
            $lessons[] = array('id'=>absint($lesson->id), 'unit_id'=>absint($unit->id), 'label'=>$unit->unit_name . ' / ' . $lesson->lesson_number . ' — ' . $lesson->lesson_title);
        } }
        $result = array('state'=>'needs_folder', 'items'=>array(), 'lessons'=>$lessons);
        if (!$mapping || $mapping->mapping_status !== 'confirmed' || !hash_equals($this->mapping->root_config_hash(), (string) $mapping->root_config_hash) || !$this->mapping->binding_chain_ids($mapping)) { return $result; }
        $saved = get_option($this->report_key($scope), array());
        $result['state'] = 'needs_scan';
        if (!is_array($saved) || ($saved['revision'] ?? '') !== $this->revision($mapping)) { return $result; }
        $result = array_merge($result, $saved['report']);
        $result['items'] = array();
        foreach ((array) ($saved['report']['items'] ?? array()) as $item) {
            $link = $this->repo->get_link_by_drive_file_id($item['file_id']);
            if ($link && ($link->link_status === 'ignored' || $link->approval_status === 'rejected')) { continue; }
            if ($link && $link->link_status === 'active') {
                $same = true;
                foreach (array('academic_year_id','semester_id','grade_id','subject_id') as $i=>$field) { if (absint($link->$field) !== $scope[$i]) { $same = false; } }
                if ($same && !in_array($item['reason'] ?? '', array('link_conflict','not_observed'), true)) { continue; }
                if ($same && absint($link->lesson_id) !== absint($item['current_lesson_id'] ?? 0)) { continue; }
                if (($item['reason'] ?? '') !== 'not_observed') { $item['reason'] = 'link_conflict'; }
                $item['current_lesson_id'] = absint($link->lesson_id);
                $item['link_revision'] = Olama_Media_V2_Repository::link_revision($link);
            } else { $item['link_revision'] = ''; }
            $result['items'][] = $item;
        }
        $result['needs_assignment'] = count($result['items']);
        $result['lessons'] = $lessons;
        return $result;
    }

    private function revision($mapping) { return hash('sha256', wp_json_encode(array($mapping->drive_folder_id, $mapping->mapping_status, $mapping->root_config_hash, $mapping->confirmation_evidence))); }

    private function observe($file, $parent, $path)
    {
        $normalizer = new Olama_Media_Normalizer();
        $path = $path . '/' . $file['name'];
        $data = array('drive_file_id'=>$file['id'], 'drive_folder_id'=>$parent, 'drive_parent_ids'=>wp_json_encode($file['parents'] ?? array($parent)),
            'filename'=>$file['name'], 'normalized_filename'=>$normalizer->normalize_filename($file['name']), 'extension'=>$normalizer->extract_extension($file['name']),
            'mime_type'=>$file['mime_type'], 'file_size'=>absint($file['size'] ?? 0), 'drive_path'=>$path, 'drive_path_hash'=>hash('sha256', $path),
            'web_view_link'=>esc_url_raw($file['web_view_link'] ?? ''), 'scan_status'=>'active');
        if (isset($file['video_media_metadata'])) { $data['video_metadata'] = wp_json_encode($file['video_media_metadata']); }
        return $this->repo->upsert_drive_file($data);
    }

    /** One Drive page per request; aborted/partial refreshes never infer removal. */
    public function sync_step($drive, $scope, $mapping, $token)
    {
        return $this->with_binding_lock($drive, $scope, $mapping, function () use ($drive, $scope, $mapping, $token) {
            return $this->sync_page($drive, $scope, $mapping, $token);
        });
    }

    private function sync_page($drive, $scope, $mapping, $token)
    {
        global $wpdb;
        $fresh = $token === '';
        $token = $token ?: str_replace('-', '', wp_generate_uuid4());
        $cache_key = 'olama_refresh_' . get_current_user_id() . '_' . $token;
        $lock = 'olama_refresh_' . substr(hash('sha256', $cache_key), 0, 40);
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== 1) { return new WP_Error('refresh_busy', __('طلب المزامنة قيد التنفيذ. أعد المحاولة.', 'olama-media-library')); }
        try {
            $state = get_transient($cache_key);
            if (!$state) {
                // Only a fresh request may initialize; an expired token must never restart silently.
                if (!$fresh) { return new WP_Error('refresh_expired', __('انتهت جلسة المزامنة. ابدأ فحصاً جديداً؛ الروابط الحالية محفوظة.', 'olama-media-library')); }
                $state = array('revision'=>$this->revision($mapping), 'scope'=>$scope, 'queue'=>array(array('id'=>$mapping->drive_folder_id, 'path'=>'', 'depth'=>0, 'page'=>'')), 'seen'=>array(), 'files'=>array(), 'folders'=>0, 'other_files'=>0);
            }
            if ($state['scope'] !== $scope || $state['revision'] !== $this->revision($mapping)) { return new WP_Error('refresh_changed', __('تغيّر نطاق المزامنة. ابدأ فحصاً جديداً.', 'olama-media-library')); }
            if (isset($state['result'])) { return $state['result']; }
            $node = $state['queue'][0];
            $chain = $this->mapping->folder_chain($drive, $node['id'], $mapping->drive_folder_id);
            if (is_wp_error($chain)) { return $chain; }
            $page = $drive->list_folder_children_page($node['id'], $node['page'], 100);
            if (is_wp_error($page)) { return $page; }
            $page_key = $node['id'] . ':' . $node['page'];
            if (isset($state['seen'][$page_key])) { return new WP_Error('refresh_pagination', __('تعذر إكمال صفحات Drive. الروابط الحالية محفوظة.', 'olama-media-library')); }
            $state['seen'][$page_key] = true;
            foreach ($page['items'] as $file) {
                if (!empty($file['trashed'])) { continue; }
                if (($file['mime_type'] ?? '') === 'application/vnd.google-apps.folder') {
                    if ($node['depth'] >= 15) { return new WP_Error('refresh_depth', __('شجرة المجلدات عميقة جداً. لم يكتمل الفحص.', 'olama-media-library')); }
                    $state['queue'][] = array('id'=>$file['id'], 'path'=>$node['path'] . '/' . $file['name'], 'depth'=>$node['depth'] + 1, 'page'=>'');
                } elseif (strpos((string) ($file['mime_type'] ?? ''), 'video/') === 0) {
                    $state['files'][$file['id']] = array('file'=>$file, 'parent'=>$node['id'], 'path'=>$node['path']);
                } else { $state['other_files'] = ($state['other_files'] ?? 0) + 1; }
            }
            if (count($state['files']) > 5000 || count($state['queue']) > 1000 || count($state['seen']) > 2000) { return new WP_Error('refresh_limit', __('حجم المجلد كبير جداً. اختر مجلد مادة أصغر.', 'olama-media-library')); }
            if ($page['next_page_token'] !== '') { $state['queue'][0]['page'] = $page['next_page_token']; }
            else { array_shift($state['queue']); $state['folders']++; }
            $result = array('token'=>$token, 'done'=>false, 'files'=>count($state['files']), 'folders'=>$state['folders']);
            if (!$state['queue']) {
                $current = $this->mapping->get_mapping_for_scope('subject:' . implode(':', $scope));
                if (!$current || $this->revision($current) !== $state['revision']) { return new WP_Error('refresh_changed', __('تغيّر الربط أثناء الفحص. ابدأ من جديد.', 'olama-media-library')); }
                $report = $this->match_refresh($scope, $state['files']);
                if (is_wp_error($report)) { return $report; }
                $result = array_merge($result, $report, array('done'=>true, 'other_files'=>$state['other_files'] ?? 0,
                    'state'=>count($state['files']) ? 'complete' : 'no_videos', 'completed_at'=>current_time('mysql')));
                $saved_report = $result; unset($saved_report['token']);
                $snapshot = array('revision'=>$state['revision'], 'report'=>$saved_report);
                if (!update_option($this->report_key($scope), $snapshot, false) && get_option($this->report_key($scope)) !== $snapshot) {
                    return new WP_Error('report_save_failed', __('اكتمل الفحص لكن تعذر حفظ قائمته. الروابط المضافة محفوظة؛ أعد الفحص لتحميل النتائج.', 'olama-media-library'));
                }
                $state['result'] = $result;
            }
            set_transient($cache_key, $state, 1800);
            return $result;
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }

    public function match_refresh($scope, $observations)
    {
        $units = $this->db->get_curriculum_with_assets(...$scope);
        if (is_wp_error($units)) { return $units; }
        $normalizer = new Olama_Media_Normalizer();
        $candidates = array(); $slots = array(); $lesson_files = array();
        $report = array('linked'=>0, 'already_linked'=>0, 'needs_assignment'=>0, 'dismissed'=>0, 'errors'=>0, 'items'=>array());
        $issue = function ($observation, $reason, $lesson_id = 0, $part = 0, $existing = null) use (&$report) {
            $file = $observation['file'];
            $report['needs_assignment']++;
            $report['items'][] = array('file_id'=>$file['id'], 'filename'=>$file['name'], 'path'=>$observation['path'],
                'url'=>$file['web_view_link'] ?? '', 'reason'=>$reason, 'suggested_lesson_id'=>absint($lesson_id), 'part_number'=>$part ?: 0,
                'current_lesson_id'=>absint($existing->lesson_id ?? 0), 'link_revision'=>$existing ? Olama_Media_V2_Repository::link_revision($existing) : '');
        };
        foreach ($observations as $id=>$observation) {
            $file = $observation['file'];
            $row_id = $this->observe($file, $observation['parent'], $observation['path']);
            if (!$row_id) { $report['errors']++; $issue($observation, 'save_failed'); continue; }
            $existing = $this->repo->get_link_by_drive_file_id($id);
            if ($existing && ($existing->link_status === 'ignored' || $existing->approval_status === 'rejected')) { $report['dismissed']++; continue; }
            $title = $normalizer->normalize_filename($file['name']);
            $raw_title = $title;
            $title = trim(preg_replace('/^(?:(?:الدرس|درس|lesson|l)\s*)?\d+(?:\s+|$)/iu', '', $title));
            $title = trim(preg_replace('/(?:الجزء|جزء|part)\s*\d+$/iu', '', $title));
            $part = $normalizer->extract_part_number($file['name']);
            $number = $normalizer->extract_lesson_number($file['name']);
            if ($number === null && preg_match('/^(\d+)(?:\s|$)/', $raw_title, $prefix)) { $number = (int) $prefix[1]; }
            $matches = array();
            foreach ($units as $unit) {
                foreach ($unit->lessons as $lesson) {
                    if ($title !== '' && $title === $normalizer->normalize_text($lesson->lesson_title)
                        && ($number === null || $number === (int) $normalizer->normalize_text($lesson->lesson_number))) { $matches[$lesson->id] = array($unit, $lesson); }
                }
            }
            $matches = array_values($matches);
            if ($existing && $existing->link_status === 'active') {
                $same_scope = array_map('absint', array($existing->academic_year_id, $existing->semester_id, $existing->grade_id, $existing->subject_id)) === $scope;
                if (!$same_scope || (count($matches) === 1 && absint($matches[0][1]->id) !== absint($existing->lesson_id))) {
                    $issue($observation, 'link_conflict', count($matches) === 1 ? $matches[0][1]->id : 0, $part, $existing);
                } else { $report['already_linked']++; }
                continue;
            }
            if (count($matches) !== 1) { $issue($observation, count($matches) > 1 ? 'ambiguous' : 'unmatched', 0, $part); continue; }
            list($unit, $lesson) = $matches[0];
            $slot = $lesson->id . ':' . ($part ?: 0);
            $slots[$slot][] = $id;
            $lesson_files[$lesson->id][] = $id;
            $candidates[$id] = array($observation, $unit, $lesson, $part, $slot, $row_id);
        }
        foreach ($candidates as $id=>$candidate) {
            list($observation, $unit, $lesson, $part, $slot, $row_id) = $candidate;
            $occupied = false;
            foreach ($this->repo->get_links_for_lesson($lesson->id) as $link) { if (!$part || empty($link->part_number) || (int) $link->part_number === $part) { $occupied = true; } }
            $mixed_parts = isset($slots[$lesson->id . ':0']) && count($lesson_files[$lesson->id]) > 1;
            if (count($slots[$slot]) !== 1 || $mixed_parts || $occupied || (!empty($lesson->media_record_id) && ($lesson->upload_status ?? '') === 'uploaded_to_drive')) {
                $issue($observation, 'occupied', $lesson->id, $part); continue;
            }
            $attached = $this->repo->attach_video(array('drive_file_id'=>$id, 'drive_file_row_id'=>$row_id, 'academic_year_id'=>$scope[0], 'semester_id'=>$scope[1], 'grade_id'=>$scope[2], 'subject_id'=>$scope[3],
                'unit_id'=>absint($unit->id), 'lesson_id'=>absint($lesson->id), 'part_number'=>$part, 'sequence_order'=>$part ?: $this->repo->next_sequence_order($lesson->id),
                'match_method'=>'bound_exact_title', 'match_confidence'=>100, 'approval_status'=>'pending', 'link_status'=>'active', 'linked_by'=>get_current_user_id()));
            if (is_wp_error($attached)) { $report['errors']++; $issue($observation, 'save_failed', $lesson->id, $part); }
            else { $report['linked']++; }
        }
        // Absence from a complete scan is a review item, never proof of deletion.
        foreach ($this->repo->get_links_for_scope($scope) as $link) {
            if (isset($observations[$link->drive_file_id])) { continue; }
            $issue(array('file'=>array('id'=>$link->drive_file_id,'name'=>$link->filename ?: $link->drive_file_id,'web_view_link'=>$link->web_view_link ?? ''), 'path'=>$link->drive_path ?? ''), 'not_observed', $link->lesson_id, $link->part_number ?? 0, $link);
        }
        return $report;
    }
}
