<?php
if (!defined('ABSPATH')) {
    exit;
}

class Olama_Media_Admin
{
    private $db;
    private $curriculum;

    public function __construct($db, $curriculum)
    {
        $this->db = $db;
        $this->curriculum = $curriculum;
        add_action('admin_menu', array($this, 'register_menu'), 30);
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));
        add_action('admin_init', array($this, 'handle_oauth_callback'));
        add_action('admin_notices', array($this, 'dependency_notice'));
        add_filter('heartbeat_settings', array($this, 'heartbeat_settings'));
    }

    public static function can_manage()
    {
        if (self::is_teacher()) {
            return false;
        }
        if (class_exists('Olama_School_Permissions') && method_exists('Olama_School_Permissions', 'can')) {
            return Olama_School_Permissions::can('olama_access_media_library') || current_user_can('manage_options');
        }
        return current_user_can('manage_options');
    }

    public static function is_teacher()
    {
        $user = wp_get_current_user();
        return $user->exists() && in_array('teacher', (array) $user->roles, true);
    }

    public static function can_access()
    {
        return self::can_manage() || self::can_upload();
    }

    public static function can_upload()
    {
        if (self::is_teacher()) {
            return true;
        }
        if (class_exists('Olama_School_Permissions') && method_exists('Olama_School_Permissions', 'can')) {
            return Olama_School_Permissions::can('olama_media_upload_video') || current_user_can('manage_options');
        }
        return current_user_can('manage_options');
    }

    public static function can_approve()
    {
        if (self::is_teacher()) {
            return false;
        }
        if (class_exists('Olama_School_Permissions') && method_exists('Olama_School_Permissions', 'can')) {
            return Olama_School_Permissions::can('olama_media_approve_video') || current_user_can('manage_options');
        }
        return current_user_can('manage_options');
    }

    public function register_menu()
    {
        if (!self::can_access()) {
            return;
        }

        $title = __('مكتبة الوسائط', 'olama-media-library');
        add_menu_page(
            __('Olama Media Library', 'olama-media-library'),
            $title,
            'read',
            'academy-media-library',
            array($this, 'render_page'),
            'dashicons-format-video',
            58
        );
    }

    public function enqueue_assets($hook)
    {
        if (strpos($hook, 'academy-media-library') === false || !self::can_access()) {
            return;
        }

        $style_path = OLAMA_MEDIA_LIBRARY_PATH . 'assets/css/media-library-admin.css';
        $script_path = OLAMA_MEDIA_LIBRARY_PATH . 'assets/js/media-library-admin.js';
        $style_version = file_exists($style_path) ? (string) filemtime($style_path) : OLAMA_MEDIA_LIBRARY_VERSION;
        $script_version = file_exists($script_path) ? (string) filemtime($script_path) : OLAMA_MEDIA_LIBRARY_VERSION;
        wp_enqueue_style('olama-media-library-admin', OLAMA_MEDIA_LIBRARY_URL . 'assets/css/media-library-admin.css', array(), $style_version);
        wp_enqueue_script('olama-media-library-admin', OLAMA_MEDIA_LIBRARY_URL . 'assets/js/media-library-admin.js', array('jquery'), $script_version, true);
        wp_enqueue_script('heartbeat');

        $settings = get_option('academy_media_library_settings', array());
        $max_size_mb = max(1, absint($settings['max_file_size'] ?? 2048));
        $server_limit = $this->server_upload_limit();
        $max_size = min($max_size_mb * 1024 * 1024, $server_limit);
        $drive_auth_health = (new Olama_Media_Drive())->get_auth_health();
        $transport_mode = sanitize_key($settings['olama_media_upload_transport_mode'] ?? $settings['upload_transport_mode'] ?? 'auto');
        if (!in_array($transport_mode, array('wordpress_streamed', 'direct_google', 'auto'), true)) {
            $transport_mode = 'auto';
        }
        $direct_threshold_mb = max(1, absint($settings['olama_media_direct_upload_threshold_mb'] ?? $settings['direct_upload_threshold_mb'] ?? 20));
        $direct_chunk_size_mb = max(1, absint($settings['olama_media_direct_chunk_size_mb'] ?? 16));

        wp_localize_script('olama-media-library-admin', 'olamaMediaLibrary', array(
            'ajaxurl' => admin_url('admin-ajax.php', 'relative'),
            'nonce' => wp_create_nonce('olama_media_library_nonce'),
            'legacyNonce' => wp_create_nonce('olama_admin_nonce'),
            'canManage' => self::can_manage() && !self::is_teacher(),
            'canApprove' => self::can_approve(),
            'maxFileSize' => $max_size,
            'maxFileSizeHuman' => size_format($max_size),
            'uploadTransportMode' => $transport_mode,
            'directUploadThresholdBytes' => $direct_threshold_mb * 1024 * 1024,
            'directUploadChunkSizeBytes' => $this->normalize_direct_chunk_size($direct_chunk_size_mb * 1024 * 1024),
            'directUploadChunkTimeoutMs' => 180000,
            'driveAuth' => array(
                'drive_authenticated' => $drive_auth_health['is_configured'] && $drive_auth_health['has_refresh_token'] && $drive_auth_health['can_refresh'],
                'has_refresh_token' => (bool) $drive_auth_health['has_refresh_token'],
                'auth_warning' => (!$drive_auth_health['is_configured'] || !$drive_auth_health['has_refresh_token'] || !$drive_auth_health['can_refresh']) ? __('تنبيه: اتصال Google Drive غير مكتمل. لن تنجح عملية رفع الفيديوهات حتى تتم إعادة المصادقة.', 'olama-media-library') : '',
            ),
            'autoSyncDriveOnLoad' => false,
            // Phase 1 still proxies chunks through PHP before sending them to Drive.
            // Keep chunks smaller for reliability until uploads move to a background/direct flow.
            'chunkSize' => min(5 * 1024 * 1024, max(1024 * 1024, (int) floor($server_limit * 0.7))),
            'i18n' => $this->i18n(),
        ));
    }

    public function handle_oauth_callback()
    {
        if (empty($_GET['page']) || $_GET['page'] !== 'academy-media-library' || empty($_GET['code'])) {
            return;
        }
        if (!current_user_can('manage_options') || self::is_teacher()) {
            return;
        }

        $drive = new Olama_Media_Drive();
        $result = $drive->authenticate(wp_unslash($_GET['code']));
        set_transient('olama_media_auth_message', array(
            'type' => is_wp_error($result) ? 'error' : 'success',
            'message' => is_wp_error($result) ? $result->get_error_message() : __('Google Drive authenticated successfully.', 'olama-media-library'),
        ), 45);

        wp_safe_redirect(remove_query_arg('code'));
        exit;
    }

    public function dependency_notice()
    {
        if (!$this->curriculum->is_available() && !empty($_GET['page']) && $_GET['page'] === 'academy-media-library') {
            echo '<div class="notice notice-warning"><p>' . esc_html__('Olama School curriculum tables are not available. Media Library can load, but curriculum filters will be empty until Olama School is active.', 'olama-media-library') . '</p></div>';
        }
    }

    public function render_page()
    {
        if (!self::can_access()) {
            wp_die(esc_html__('You are not allowed to access this page.', 'olama-media-library'));
        }

        $message = get_transient('olama_media_auth_message');
        if ($message) {
            add_settings_error('olama_media_library', 'auth', $message['message'], $message['type']);
            delete_transient('olama_media_auth_message');
        }

        $active_year = $this->curriculum->get_active_year();
        $active_semester = $this->curriculum->get_active_semester($active_year->id ?? null);
        $years = $this->curriculum->get_academic_years();
        $semesters = $active_year ? $this->curriculum->get_semesters($active_year->id) : array();
        $grades = $this->curriculum->get_grades();
        $settings = get_option('academy_media_library_settings', array());
        $drive_auth_health = (new Olama_Media_Drive())->get_auth_health();

        settings_errors('olama_media_library');
        include OLAMA_MEDIA_LIBRARY_PATH . 'views/media-library-page.php';
    }

    private function has_parent_menu($slug)
    {
        global $menu;
        foreach ((array) $menu as $item) {
            if (!empty($item[2]) && $item[2] === $slug) {
                return true;
            }
        }
        return false;
    }

    private function server_upload_limit()
    {
        $limits = array($this->to_bytes(ini_get('upload_max_filesize')), $this->to_bytes(ini_get('post_max_size')));
        $limits = array_filter($limits);
        return $limits ? min($limits) : 1024 * 1024 * 1024;
    }

    private function to_bytes($value)
    {
        $value = trim((string) $value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;
        if ($unit === 'g') {
            return $number * 1024 * 1024 * 1024;
        }
        if ($unit === 'm') {
            return $number * 1024 * 1024;
        }
        if ($unit === 'k') {
            return $number * 1024;
        }
        return $number;
    }

    private function normalize_direct_chunk_size($bytes)
    {
        $unit = 256 * 1024;
        $bytes = max($unit, absint($bytes));
        return max($unit, (int) floor($bytes / $unit) * $unit);
    }

    private function i18n()
    {
        return array(
            'select' => __('-- اختر --', 'olama-media-library'),
            'select_all' => __('يرجى اختيار الصف والمادة أولا.', 'olama-media-library'),
            'load_curriculum' => __('تحميل المنهاج', 'olama-media-library'),
            'loading' => __('جاري التحميل...', 'olama-media-library'),
            'uploading' => __('جاري الرفع...', 'olama-media-library'),
            'upload' => __('رفع', 'olama-media-library'),
            'replace' => __('استبدال', 'olama-media-library'),
            'preview' => __('معاينة', 'olama-media-library'),
            'download' => __('تحميل', 'olama-media-library'),
            'check_status' => __('فحص الحالة', 'olama-media-library'),
            'approve' => __('اعتماد', 'olama-media-library'),
            'reject' => __('رفض', 'olama-media-library'),
            'save' => __('حفظ', 'olama-media-library'),
            'notes' => __('ملاحظات', 'olama-media-library'),
            'error' => __('حدث خطأ.', 'olama-media-library'),
            'no_curriculum' => __('لا توجد دروس لهذه الاختيارات.', 'olama-media-library'),
            'no_logs' => __('لا توجد سجلات بعد.', 'olama-media-library'),
            'file_too_large' => __('حجم الملف أكبر من المسموح: %s', 'olama-media-library'),
            'invalid_file' => __('يسمح حاليا برفع ملفات MP4 فقط.', 'olama-media-library'),
            'retrying_chunk' => __('إعادة محاولة رفع الجزء %1$s من %2$s - المحاولة %3$s من 3', 'olama-media-library'),
            'chunk_failed_final' => __('فشل رفع هذا الجزء بعد 3 محاولات. يرجى التحقق من الاتصال والمحاولة مرة أخرى.', 'olama-media-library'),
            'finalizing_upload' => __('تم رفع الملف، جاري تثبيت بيانات الفيديو...', 'olama-media-library'),
            'finalize_failed' => __('فشل تثبيت بيانات الفيديو، يمكنك إعادة المحاولة بدون رفع الملف من جديد.', 'olama-media-library'),
            'retry_finalize' => __('إعادة تثبيت بيانات الفيديو', 'olama-media-library'),
            'session_or_permission_expired' => __('انتهت جلسة الدخول أو صلاحية الرفع. يرجى تحديث الصفحة وتسجيل الدخول ثم المحاولة مرة أخرى.', 'olama-media-library'),
            'google_auth_upload_failed' => __('فشل رفع الفيديو لأن اتصال Google Drive غير صالح. يرجى إعادة المصادقة مع Google من إعدادات Drive ثم إعادة المحاولة.', 'olama-media-library'),
            'retryable_network_error' => __('تعذر رفع هذا الجزء مؤقتا. سيتم إعادة المحاولة تلقائيا.', 'olama-media-library'),
            'transport_wordpress' => __('طريقة الرفع: عبر WordPress', 'olama-media-library'),
            'transport_direct' => __('طريقة الرفع: مباشر إلى Google Drive', 'olama-media-library'),
            'transport_auto' => __('طريقة الرفع: تلقائي', 'olama-media-library'),
            'direct_session_creating' => __('جاري إنشاء جلسة رفع مباشرة إلى Google Drive...', 'olama-media-library'),
            'direct_uploading' => __('جاري رفع الفيديو مباشرة إلى Google Drive...', 'olama-media-library'),
            'direct_probe_checking' => __('حدث انقطاع مؤقت أثناء الرفع المباشر. يتم التحقق من آخر جزء تم رفعه...', 'olama-media-library'),
            'direct_completed_finalizing' => __('اكتمل رفع الفيديو إلى Google Drive، جاري تثبيت بيانات الفيديو...', 'olama-media-library'),
            'direct_success' => __('تم رفع الفيديو بنجاح. المعاينة قيد المعالجة من Google Drive.', 'olama-media-library'),
            'direct_browser_failed' => __('تعذر الرفع المباشر إلى Google Drive من المتصفح. يمكنك استخدام الرفع عبر WordPress كبديل.', 'olama-media-library'),
            'direct_session_restart_required' => __('تعذر إكمال الرفع المباشر. سيتم استخدام الرفع عبر WordPress كبديل.', 'olama-media-library'),
            'direct_fallback_available' => __('يمكنك إعادة المحاولة أو استخدام طريقة الرفع عبر WordPress.', 'olama-media-library'),
            'use_wordpress_fallback' => __('استخدام الرفع عبر WordPress بدلاً من ذلك', 'olama-media-library'),
            'retry_direct_upload' => __('إعادة محاولة الرفع المباشر', 'olama-media-library'),
            'processing_note' => __('تم رفع الفيديو بنجاح، لكن Google Drive ما زال يعالج المعاينة. يمكن تحميل الملف الآن وستتوفر المشاهدة لاحقا.', 'olama-media-library'),
            'status_none' => __('لا يوجد فيديو', 'olama-media-library'),
            'status_uploading' => __('جاري الرفع', 'olama-media-library'),
            'status_uploaded_to_drive' => __('تم الرفع', 'olama-media-library'),
            'status_failed' => __('فشل الرفع', 'olama-media-library'),
            'status_not_checked' => __('لم يتم الفحص', 'olama-media-library'),
            'status_processing' => __('المعاينة قيد المعالجة', 'olama-media-library'),
            'status_ready' => __('جاهز للمشاهدة', 'olama-media-library'),
            'status_pending' => __('بانتظار الاعتماد', 'olama-media-library'),
            'status_approved' => __('معتمد', 'olama-media-library'),
            'status_rejected' => __('مرفوض', 'olama-media-library'),
            'coverage_all_subjects' => __('All subjects', 'olama-media-library'),
            'coverage_select_semester' => __('Select a semester first.', 'olama-media-library'),
            'coverage_lessons' => __('lessons', 'olama-media-library'),
            'coverage_full_set' => __('Full curriculum set', 'olama-media-library'),
            'coverage_by_grade' => __('Coverage by grade', 'olama-media-library'),
            'coverage_by_subject' => __('Coverage by subject', 'olama-media-library'),
            'coverage_curriculum' => __('Curriculum coverage', 'olama-media-library'),
            'coverage_unit' => __('Unit', 'olama-media-library'),
            'coverage_lesson' => __('Lesson', 'olama-media-library'),
            'coverage_video' => __('Video coverage', 'olama-media-library'),
            'coverage_uploaded' => __('Video uploaded', 'olama-media-library'),
            'coverage_missing' => __('Video missing', 'olama-media-library'),
        );
    }

    public function heartbeat_settings($settings)
    {
        if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'academy-media-library') {
            $settings['interval'] = 120;
        }

        return $settings;
    }
}
