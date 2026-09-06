<?php
if (!defined('ABSPATH')) {
    exit;
}

$drive = new Olama_Media_Drive();
$refresh_token = $settings['refresh_token'] ?? '';
$can_administer = current_user_can('manage_options') || current_user_can('olama_media_drive_settings');
$drive_upload_enabled = Olama_Media_Feature_Flags::enabled(Olama_Media_Feature_Flags::DRIVE_UPLOAD);
$drive_sync_enabled = Olama_Media_Feature_Flags::enabled(Olama_Media_Feature_Flags::DRIVE_SYNC);
?>

<div class="wrap academy-media-library-wrap olama-media-library-wrap<?php echo $can_administer ? '' : ' olama-upload-only'; ?>" dir="rtl">
    <h1><?php esc_html_e('مكتبة الوسائط', 'olama-media-library'); ?></h1>

    <h2 class="nav-tab-wrapper">
        <?php if ($can_administer) : ?><a href="#coverage" class="nav-tab" data-tab="coverage"><?php esc_html_e('Curriculum Video Coverage Report', 'olama-media-library'); ?></a><?php endif; ?>
        <a href="#library" class="nav-tab nav-tab-active" data-tab="library"><?php esc_html_e('رفع الفيديوهات', 'olama-media-library'); ?></a>
        <?php if ($can_administer) : ?><a href="#link-check" class="nav-tab" data-tab="link-check"><?php esc_html_e('فحص الربط', 'olama-media-library'); ?></a><?php endif; ?>
        <?php if ($can_administer) : ?><a href="#settings" class="nav-tab" data-tab="settings"><?php esc_html_e('إعدادات Drive', 'olama-media-library'); ?></a><?php endif; ?>
    </h2>

    <section id="tab-library" class="olama-media-tab active">
        <?php if (Olama_Media_Feature_Flags::phase_zero_active()) : ?>
            <div class="notice notice-warning inline">
                <p><strong><?php esc_html_e('وضع الحماية:', 'olama-media-library'); ?></strong> <?php esc_html_e('تم إيقاف رفع الملفات ومزامنة Google Drive وإنشاء المجلدات مؤقتاً إلى حين اكتمال ربط مجلدات المنهج بشكل آمن.', 'olama-media-library'); ?></p>
            </div>
        <?php endif; ?>
        <?php if (empty($drive_auth_health['is_configured']) || empty($drive_auth_health['has_refresh_token']) || empty($drive_auth_health['can_refresh'])) : ?>
            <div class="notice notice-error inline olama-drive-auth-warning">
                <p>
                    <strong><?php esc_html_e('تنبيه:', 'olama-media-library'); ?></strong>
                    <?php esc_html_e('اتصال Google Drive غير مكتمل. لن تنجح عملية رفع الفيديوهات حتى تتم إعادة المصادقة.', 'olama-media-library'); ?>
                    <?php if ($can_administer) : ?><a href="#settings" class="button button-small nav-tab-jump" data-tab="settings"><?php esc_html_e('إعدادات Drive', 'olama-media-library'); ?></a><?php endif; ?>
                </p>
            </div>
        <?php endif; ?>

        <div class="olama-media-toolbar">
            <label>
                <span><?php esc_html_e('السنة الدراسية', 'olama-media-library'); ?></span>
                <select id="filter-year-id">
                    <?php foreach ($years as $year) : ?>
                        <option value="<?php echo esc_attr($year->id); ?>" <?php selected($active_year->id ?? 0, $year->id); ?>>
                            <?php echo esc_html($year->year_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span><?php esc_html_e('الفصل', 'olama-media-library'); ?></span>
                <select id="filter-semester">
                    <?php foreach ($semesters as $semester) : ?>
                        <option value="<?php echo esc_attr($semester->id); ?>" <?php selected($active_semester->id ?? 0, $semester->id); ?>>
                            <?php echo esc_html($semester->semester_name); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span><?php esc_html_e('الصف', 'olama-media-library'); ?></span>
                <select id="filter-grade">
                    <option value=""><?php esc_html_e('-- اختر الصف --', 'olama-media-library'); ?></option>
                    <?php foreach ($grades as $grade) : ?>
                        <option value="<?php echo esc_attr($grade->id); ?>"><?php echo esc_html($grade->grade_name); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label>
                <span><?php esc_html_e('المادة', 'olama-media-library'); ?></span>
                <select id="filter-subject" disabled>
                    <option value=""><?php esc_html_e('-- اختر المادة --', 'olama-media-library'); ?></option>
                </select>
            </label>

            <button type="button" id="btn-load-curriculum" class="button button-primary"><?php esc_html_e('تحميل المنهاج', 'olama-media-library'); ?></button>
        </div>

        <?php if ($can_administer) : ?>
            <div class="olama-drive-sync-bar">
                <div>
                    <strong><?php esc_html_e('مزامنة Google Drive', 'olama-media-library'); ?></strong>
                    <span><?php esc_html_e('يفحص مجلد المادة المحددة ويربط الفيديوهات بالدروس تلقائياً.', 'olama-media-library'); ?></span>
                </div>
                <button type="button" id="btn-v2-sync-now" class="button button-primary" <?php disabled(!$drive_sync_enabled); ?>><?php esc_html_e('فحص ومزامنة Google Drive', 'olama-media-library'); ?></button>
            </div>
        <?php endif; ?>

        <div class="olama-upload-monitor" id="olama-upload-monitor">
            <div class="olama-upload-monitor-header">
                <h2><?php esc_html_e('عمليات الرفع الحالية', 'olama-media-library'); ?></h2>
                <label>
                    <input type="checkbox" id="olama-upload-debug-toggle">
                    <?php esc_html_e('إظهار تفاصيل التشخيص', 'olama-media-library'); ?>
                </label>
            </div>
            <div id="olama-upload-monitor-list" class="olama-upload-monitor-list">
                <p class="description"><?php esc_html_e('لا توجد عمليات رفع نشطة حالياً.', 'olama-media-library'); ?></p>
            </div>
        </div>

        <div id="olama-v2-auto-sync-status" class="notice notice-info inline" hidden><p></p></div>
        <div id="curriculum-container" class="olama-media-lessons">
            <div class="notice notice-info inline"><p><?php esc_html_e('اختر الصف والمادة ثم حمّل الدروس. لا يتم فحص Google Drive أثناء تحميل القائمة.', 'olama-media-library'); ?></p></div>
        </div>

        <?php if ($can_administer) : ?>
            <div id="olama-v2-review-panel" class="olama-media-panel olama-review-panel" hidden>
                <div class="olama-v2-heading">
                    <div>
                        <h2><?php esc_html_e('فيديوهات بانتظار المراجعة', 'olama-media-library'); ?></h2>
                        <p class="description"><?php esc_html_e('راجع الفيديوهات المرتبطة بالدروس ثم اعتمدها أو ارفضها.', 'olama-media-library'); ?></p>
                    </div>
                    <button type="button" class="button" id="btn-v2-review-refresh"><?php esc_html_e('تحديث', 'olama-media-library'); ?></button>
                </div>
                <table class="wp-list-table widefat striped"><thead><tr>
                    <th><?php esc_html_e('ملف Drive', 'olama-media-library'); ?></th><th><?php esc_html_e('المسار', 'olama-media-library'); ?></th>
                    <th><?php esc_html_e('الدرس المقترح', 'olama-media-library'); ?></th><th><?php esc_html_e('الوحدة', 'olama-media-library'); ?></th>
                    <th><?php esc_html_e('الجزء', 'olama-media-library'); ?></th><th><?php esc_html_e('الثقة', 'olama-media-library'); ?></th><th><?php esc_html_e('الإجراءات', 'olama-media-library'); ?></th>
                </tr></thead><tbody id="v2-review-body"><tr><td colspan="7">-</td></tr></tbody></table>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($can_administer) : ?>
    <section id="tab-coverage" class="olama-media-tab">
        <div class="olama-media-panel">
            <h2><?php esc_html_e('Curriculum Video Coverage Report', 'olama-media-library'); ?></h2>
            <p><?php esc_html_e('Track uploaded and missing lesson videos across the complete curriculum.', 'olama-media-library'); ?></p>
            <div class="olama-media-toolbar olama-coverage-toolbar">
                <label><span><?php esc_html_e('Academic year', 'olama-media-library'); ?></span>
                    <select id="coverage-year">
                        <?php foreach ($years as $year) : ?>
                            <option value="<?php echo esc_attr($year->id); ?>" <?php selected($active_year->id ?? 0, $year->id); ?>><?php echo esc_html($year->year_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><span><?php esc_html_e('Semester', 'olama-media-library'); ?></span>
                    <select id="coverage-semester">
                        <?php foreach ($semesters as $semester) : ?>
                            <option value="<?php echo esc_attr($semester->id); ?>" <?php selected($active_semester->id ?? 0, $semester->id); ?>><?php echo esc_html($semester->semester_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><span><?php esc_html_e('Grade', 'olama-media-library'); ?></span>
                    <select id="coverage-grade"><option value=""><?php esc_html_e('All grades', 'olama-media-library'); ?></option>
                        <?php foreach ($grades as $grade) : ?><option value="<?php echo esc_attr($grade->id); ?>"><?php echo esc_html($grade->grade_name); ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label><span><?php esc_html_e('Subject', 'olama-media-library'); ?></span>
                    <select id="coverage-subject" disabled><option value=""><?php esc_html_e('All subjects', 'olama-media-library'); ?></option></select>
                </label>
                <button type="button" id="btn-load-coverage" class="button button-primary"><?php esc_html_e('Generate report', 'olama-media-library'); ?></button>
            </div>
            <div id="coverage-report"><div class="notice notice-info inline"><p><?php esc_html_e('Generate the report to view curriculum coverage.', 'olama-media-library'); ?></p></div></div>
        </div>
    </section>

    <section id="tab-settings" class="olama-media-tab">
        <div class="olama-media-panel">
            <h2><?php esc_html_e('Google Drive Settings', 'olama-media-library'); ?></h2>
            <form id="drive-settings-form">
                <table class="form-table">
                    <tr>
                        <th><label for="client_id"><?php esc_html_e('Client ID', 'olama-media-library'); ?></label></th>
                        <td><input type="text" id="client_id" name="client_id" value="<?php echo esc_attr($settings['client_id'] ?? ''); ?>" class="large-text"></td>
                    </tr>
                    <tr>
                        <th><label for="client_secret"><?php esc_html_e('Client Secret', 'olama-media-library'); ?></label></th>
                        <td><input type="password" id="client_secret" name="client_secret" value="<?php echo esc_attr($settings['client_secret'] ?? ''); ?>" class="large-text"></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Redirect URI', 'olama-media-library'); ?></th>
                        <td><input type="text" readonly onclick="this.select()" class="large-text" value="<?php echo esc_attr(admin_url('admin.php?page=academy-media-library')); ?>"></td>
                    </tr>
                    <tr>
                        <th><label for="root_folder_id"><?php esc_html_e('Root Folder ID', 'olama-media-library'); ?></label></th>
                        <td><input type="text" id="root_folder_id" name="root_folder_id" value="<?php echo esc_attr($settings['root_folder_id'] ?? ''); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th><label for="max_file_size"><?php esc_html_e('Max Upload Size (MB)', 'olama-media-library'); ?></label></th>
                        <td><input type="number" id="max_file_size" name="max_file_size" value="<?php echo esc_attr($settings['max_file_size'] ?? 2048); ?>" class="small-text"></td>
                    </tr>
                    <tr>
                        <th><label for="upload_transport_mode"><?php esc_html_e('طريقة رفع الفيديوهات', 'olama-media-library'); ?></label></th>
                        <td>
                            <?php $transport_mode = $settings['olama_media_upload_transport_mode'] ?? $settings['upload_transport_mode'] ?? 'auto'; ?>
                            <select id="olama_media_upload_transport_mode" name="olama_media_upload_transport_mode">
                                <option value="wordpress_streamed" <?php selected($transport_mode, 'wordpress_streamed'); ?>><?php esc_html_e('الرفع عبر WordPress — الوضع المستقر الحالي', 'olama-media-library'); ?></option>
                                <option value="direct_google" <?php selected($transport_mode, 'direct_google'); ?>><?php esc_html_e('الرفع المباشر إلى Google Drive — أسرع للملفات الكبيرة', 'olama-media-library'); ?></option>
                                <option value="auto" <?php selected($transport_mode, 'auto'); ?>><?php esc_html_e('تلقائي — استخدم الرفع المباشر للملفات الكبيرة والرفع العادي للملفات الصغيرة', 'olama-media-library'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('عند تفعيل الرفع المباشر، يتم رفع الفيديو من جهاز المستخدم إلى Google Drive مباشرة بدون مرور ملف الفيديو عبر خادم الموقع. يبقى WordPress مسؤولاً عن الصلاحيات، ربط الفيديو بالدرس، وحفظ بيانات الملف.', 'olama-media-library'); ?></p>
                            <p class="description"><strong><?php esc_html_e('تنبيه:', 'olama-media-library'); ?></strong> <?php esc_html_e('يتطلب الرفع المباشر اتصال Google Drive صالحاً. في حال فشل الرفع المباشر يمكن الرجوع إلى الرفع عبر WordPress.', 'olama-media-library'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="olama_media_direct_upload_threshold_mb"><?php esc_html_e('Direct Upload Threshold (MB)', 'olama-media-library'); ?></label></th>
                        <td><input type="number" id="olama_media_direct_upload_threshold_mb" name="olama_media_direct_upload_threshold_mb" value="<?php echo esc_attr($settings['olama_media_direct_upload_threshold_mb'] ?? $settings['direct_upload_threshold_mb'] ?? 20); ?>" class="small-text" min="1"></td>
                    </tr>
                    <tr>
                        <th><label for="olama_media_direct_chunk_size_mb"><?php esc_html_e('Direct Chunk Size (MB)', 'olama-media-library'); ?></label></th>
                        <td>
                            <input type="number" id="olama_media_direct_chunk_size_mb" name="olama_media_direct_chunk_size_mb" value="<?php echo esc_attr($settings['olama_media_direct_chunk_size_mb'] ?? 16); ?>" class="small-text" min="1">
                            <p class="description"><?php esc_html_e('يتم تقريب حجم أجزاء الرفع المباشر داخلياً إلى مضاعفات 256 KB حسب متطلبات Google Drive.', 'olama-media-library'); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <button type="submit" class="button button-primary"><?php esc_html_e('حفظ', 'olama-media-library'); ?></button>
                    <button type="button" id="btn-test-connection" class="button"><?php esc_html_e('فحص الاتصال', 'olama-media-library'); ?></button>
                    <span id="settings-status"></span>
                </p>
            </form>

            <?php if (!empty($settings['client_id']) && !empty($settings['client_secret'])) : ?>
                <p>
                    <a class="button" href="<?php echo esc_url($drive->get_auth_url()); ?>">
                        <?php echo $refresh_token ? esc_html__('إعادة المصادقة مع Google', 'olama-media-library') : esc_html__('المصادقة مع Google', 'olama-media-library'); ?>
                    </a>
                    <?php if ($refresh_token) : ?>
                        <span class="olama-media-ok"><?php esc_html_e('Refresh token محفوظ.', 'olama-media-library'); ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        </div>

    </section>

    <section id="tab-link-check" class="olama-media-tab olama-link-check-tab">
        <div class="olama-link-check-hero">
            <div>
                <span class="olama-eyebrow"><?php esc_html_e('مراجعة آمنة خطوة بخطوة', 'olama-media-library'); ?></span>
                <h2><?php esc_html_e('فحص وربط فيديوهات Google Drive', 'olama-media-library'); ?></h2>
                <p><?php esc_html_e('اختر نطاق المنهج، تحقق من مجلد المادة، راجع المطابقات، ثم نفّذ الربط داخل WordPress بعد اجتياز فحص الجاهزية.', 'olama-media-library'); ?></p>
            </div>
            <div class="olama-safety-badge"><span class="dashicons dashicons-shield"></span><strong><?php esc_html_e('Drive محمي', 'olama-media-library'); ?></strong><small><?php esc_html_e('الجرد والربط لا ينقلان ولا يحذفان الملفات أو المجلدات.', 'olama-media-library'); ?></small></div>
        </div>

        <div class="olama-media-panel olama-link-scope-panel">
            <div class="olama-section-heading">
                <div><span class="olama-step-kicker"><?php esc_html_e('نطاق العمل', 'olama-media-library'); ?></span><h2><?php esc_html_e('حدد المادة التي تريد فحصها', 'olama-media-library'); ?></h2></div>
                <span id="audit-scope-state" class="olama-scope-state"><?php esc_html_e('لم يتم اختيار مادة بعد', 'olama-media-library'); ?></span>
            </div>
            <div class="olama-media-toolbar olama-audit-toolbar">
                <label><span><?php esc_html_e('السنة الدراسية', 'olama-media-library'); ?></span><select id="audit-year-id"><?php foreach ($years as $year) : ?><option value="<?php echo esc_attr($year->id); ?>" <?php selected($active_year->id ?? 0, $year->id); ?>><?php echo esc_html($year->year_name); ?></option><?php endforeach; ?></select></label>
                <label><span><?php esc_html_e('الفصل', 'olama-media-library'); ?></span><select id="audit-semester"><?php foreach ($semesters as $semester) : ?><option value="<?php echo esc_attr($semester->id); ?>" <?php selected($active_semester->id ?? 0, $semester->id); ?>><?php echo esc_html($semester->semester_name); ?></option><?php endforeach; ?></select></label>
                <label><span><?php esc_html_e('الصف', 'olama-media-library'); ?></span><select id="audit-grade"><option value=""><?php esc_html_e('-- اختر الصف --', 'olama-media-library'); ?></option><?php foreach ($grades as $grade) : ?><option value="<?php echo esc_attr($grade->id); ?>"><?php echo esc_html($grade->grade_name); ?></option><?php endforeach; ?></select></label>
                <label><span><?php esc_html_e('المادة', 'olama-media-library'); ?></span><select id="audit-subject" disabled><option value=""><?php esc_html_e('-- اختر المادة --', 'olama-media-library'); ?></option></select></label>
                <button type="button" id="btn-audit-scope" class="button button-primary" disabled><?php esc_html_e('بدء فحص المادة', 'olama-media-library'); ?></button>
            </div>
        </div>

        <div class="olama-link-check-flow">
                <article class="olama-workflow-card" data-workflow-step="1">
                    <div class="olama-workflow-heading"><span class="olama-step-number">1</span><div><h2><?php esc_html_e('فحص Google Drive', 'olama-media-library'); ?></h2><p><?php esc_html_e('تأكد أن آخر جرد مكتمل وحديث. يمكنك تشغيل جرد جديد للقراءة فقط عند الحاجة.', 'olama-media-library'); ?></p></div></div>
                    <p><?php esc_html_e('يجمع معرفات المجلدات والملفات والمسارات ويكشف المجلدات المتكررة. لا ينقل أو ينشئ أو يحذف أي عنصر، ولا يغير روابط الدروس الحالية.', 'olama-media-library'); ?></p>
                    <button type="button" class="button button-primary" id="btn-drive-inventory"><?php esc_html_e('بدء الجرد الآمن', 'olama-media-library'); ?></button>
                    <div id="drive-inventory-progress" class="notice notice-info inline" hidden><p></p></div>
                    <pre id="drive-inventory-result" class="olama-media-result" hidden></pre>
                </article>
                <article class="olama-workflow-card" data-workflow-step="2">
                    <div class="olama-workflow-heading"><span class="olama-step-number">2</span><div><h2><?php esc_html_e('تحديد مجلد المادة', 'olama-media-library'); ?></h2><p><?php esc_html_e('راجع المرشح والمسار ونسبة الثقة، أو انتقل لخطة شجرة جديدة عندما لا تكون المادة موجودة داخل الصف.', 'olama-media-library'); ?></p></div></div>
                    <p><?php esc_html_e('لا يعتمد النظام مادة من صف أو فصل آخر. إذا لم يوجد تطابق في المسار المحدد، تبقى عملية الربط مقفلة لكن تتاح معاينة إنشاء الشجرة الناقصة بأمان.', 'olama-media-library'); ?></p>
                    <button type="button" class="button" id="btn-drive-mapping-candidates" disabled><?php esc_html_e('عرض مرشّحات المجلد', 'olama-media-library'); ?></button>
                    <div id="drive-mapping-status" class="notice notice-info inline" hidden><p></p></div>
                    <table class="wp-list-table widefat striped" id="drive-mapping-table" hidden>
                        <thead><tr><th><?php esc_html_e('المجلد', 'olama-media-library'); ?></th><th><?php esc_html_e('المسار', 'olama-media-library'); ?></th><th><?php esc_html_e('الثقة', 'olama-media-library'); ?></th><th><?php esc_html_e('التعارض', 'olama-media-library'); ?></th><th><?php esc_html_e('الإجراء', 'olama-media-library'); ?></th></tr></thead>
                        <tbody id="drive-mapping-body"></tbody>
                    </table>
                </article>
                <article class="olama-workflow-card olama-workflow-card-wide" data-workflow-step="3">
                    <div class="olama-workflow-heading"><span class="olama-step-number">3</span><div><h2><?php esc_html_e('معاينة شجرة مجلدات المنهج', 'olama-media-library'); ?></h2><p><?php esc_html_e('يفحص السنة والفصل والصف والمادة والوحدات بالترتيب، ويعرض ما سيُعاد استخدامه وما يحتاج إلى إنشاء أو مراجعة.', 'olama-media-library'); ?></p></div></div>
                    <p><?php esc_html_e('هذه معاينة للقراءة فقط. لا تنشئ أو تنقل أو تعيد تسمية أي مجلد في Google Drive.', 'olama-media-library'); ?></p>
                    <button type="button" class="button" id="btn-folder-provisioning-preview" disabled><?php esc_html_e('إنشاء خطة المجلدات', 'olama-media-library'); ?></button>
                    <div id="folder-provisioning-summary" class="notice notice-info inline" hidden><p></p></div>
                    <table class="wp-list-table widefat striped" id="folder-provisioning-table" hidden>
                        <thead><tr><th><?php esc_html_e('مستوى الشجرة', 'olama-media-library'); ?></th><th><?php esc_html_e('الإجراء المقترح', 'olama-media-library'); ?></th><th><?php esc_html_e('المسار', 'olama-media-library'); ?></th><th><?php esc_html_e('Drive ID / المرشحات', 'olama-media-library'); ?></th><th><?php esc_html_e('السبب', 'olama-media-library'); ?></th></tr></thead>
                        <tbody id="folder-provisioning-body"></tbody>
                    </table>
                    <div id="folder-provisioning-apply-gate" class="olama-folder-apply-gate notice notice-warning inline" hidden>
                        <p><strong><?php esc_html_e('تنفيذ خطة المجلدات المراجعة', 'olama-media-library'); ?></strong></p>
                        <p><?php esc_html_e('يعيد فحص Drive مباشرة قبل كل خطوة، ثم ينشئ المجلدات الناقصة فقط ويحفظ معرفاتها. لا يحذف أو ينقل أو يعيد تسمية أي مجلد أو ملف.', 'olama-media-library'); ?></p>
                        <p class="description"><?php esc_html_e('إذا توقف التنفيذ بعد إنشاء بعض المجلدات، لا يحذفها النظام؛ تحفظ معرفاتها وتعيد المحاولة استخدامها بأمان.', 'olama-media-library'); ?></p>
                        <button type="button" class="button" id="btn-folder-provisioning-readiness"><?php esc_html_e('فحص جاهزية إنشاء المجلدات', 'olama-media-library'); ?></button>
                        <div id="folder-provisioning-readiness-result" class="olama-media-result" hidden></div>
                        <p><label for="folder-provisioning-confirmation"><?php esc_html_e('بعد نجاح الفحص اكتب:', 'olama-media-library'); ?> <code>CREATE REVIEWED FOLDERS</code></label></p>
                        <input type="text" id="folder-provisioning-confirmation" class="regular-text" autocomplete="off" disabled>
                        <button type="button" class="button button-primary" id="btn-folder-provisioning-apply" disabled><?php esc_html_e('إنشاء المجلدات الناقصة', 'olama-media-library'); ?></button>
                        <pre id="folder-provisioning-apply-result" class="olama-media-result" hidden></pre>
                    </div>
                </article>
                <article class="olama-workflow-card olama-workflow-card-wide" data-workflow-step="4">
                    <div class="olama-workflow-heading"><span class="olama-step-number">4</span><div><h2><?php esc_html_e('مراجعة مطابقة الدروس', 'olama-media-library'); ?></h2><p><?php esc_html_e('راجع المقترحات وسجّل قرارًا لكل ملف. لا تنتقل للربط النهائي قبل وصول القرارات المعلقة إلى صفر.', 'olama-media-library'); ?></p></div></div>
                    <p><?php esc_html_e('يستخدم Drive ID للمادة المعتمدة ويقترح الوحدة والدرس لكل ملف من الجرد. يمكنك تسجيل قرار مرحلي لكل ملف، لكن هذه القرارات لا تغيّر مكتبة الفيديوهات أو Drive.', 'olama-media-library'); ?></p>
                    <button type="button" class="button" id="btn-reconciliation-preview" disabled><?php esc_html_e('إنشاء المعاينة', 'olama-media-library'); ?></button>
                    <div id="reconciliation-summary" class="notice notice-info inline" hidden><p></p></div>
                    <table class="wp-list-table widefat striped" id="reconciliation-table" hidden>
                        <thead><tr><th><?php esc_html_e('ملف Drive', 'olama-media-library'); ?></th><th><?php esc_html_e('الوحدة المقترحة', 'olama-media-library'); ?></th><th><?php esc_html_e('الدرس المقترح', 'olama-media-library'); ?></th><th><?php esc_html_e('الثقة', 'olama-media-library'); ?></th><th><?php esc_html_e('النتيجة', 'olama-media-library'); ?></th><th><?php esc_html_e('المراجعة المرحلية', 'olama-media-library'); ?></th></tr></thead>
                        <tbody id="reconciliation-body"></tbody>
                    </table>
                </article>
                <article class="olama-workflow-card olama-workflow-card-wide" data-workflow-step="5">
                    <div class="olama-workflow-heading"><span class="olama-step-number">5</span><div><h2><?php esc_html_e('الربط النهائي والتراجع', 'olama-media-library'); ?></h2><p><?php esc_html_e('افحص الجاهزية أولًا. لن يتاح التنفيذ إذا بقي قرار معلّق أو تعارض.', 'olama-media-library'); ?></p></div></div>
                    <div id="reconciliation-commit-gate" class="notice notice-warning inline" hidden>
                        <p><strong><?php esc_html_e('بوابة الربط النهائي في WordPress', 'olama-media-library'); ?></strong></p>
                        <p><?php esc_html_e('تفحص التعارضات أولاً، ثم تنشئ روابط الدروس المعتمدة داخل قاعدة بيانات WordPress بمعاملة واحدة. لا تعدّل أو تنقل أو تحذف أي عنصر في Google Drive.', 'olama-media-library'); ?></p>
                        <button type="button" class="button" id="btn-reconciliation-readiness"><?php esc_html_e('فحص جاهزية الربط النهائي', 'olama-media-library'); ?></button>
                        <div id="reconciliation-readiness-result" class="olama-media-result" hidden></div>
                        <p><label for="reconciliation-commit-confirmation"><?php esc_html_e('بعد نجاح الفحص اكتب:', 'olama-media-library'); ?> <code>COMMIT REVIEWED LINKS</code></label></p>
                        <input type="text" id="reconciliation-commit-confirmation" class="regular-text" autocomplete="off" disabled>
                        <button type="button" class="button button-primary" id="btn-reconciliation-commit" disabled><?php esc_html_e('تنفيذ الربط النهائي', 'olama-media-library'); ?></button>
                        <pre id="reconciliation-commit-result" class="olama-media-result" hidden></pre>
                        <hr>
                        <p><strong><?php esc_html_e('التراجع عن أحدث عملية ربط', 'olama-media-library'); ?></strong></p>
                        <p><?php esc_html_e('يفحص لقطات الروابط أولاً، ويرفض التراجع إذا تغيّر أي رابط بعد العملية. يؤثر في WordPress فقط ولا يغيّر Drive.', 'olama-media-library'); ?></p>
                        <button type="button" class="button" id="btn-reconciliation-rollback-readiness"><?php esc_html_e('فحص إمكانية التراجع', 'olama-media-library'); ?></button>
                        <pre id="reconciliation-rollback-readiness-result" class="olama-media-result" hidden></pre>
                        <p><label for="reconciliation-rollback-confirmation"><?php esc_html_e('بعد نجاح فحص التراجع اكتب:', 'olama-media-library'); ?> <code>ROLLBACK REVIEWED LINKS</code></label></p>
                        <input type="text" id="reconciliation-rollback-confirmation" class="regular-text" autocomplete="off" disabled>
                        <button type="button" class="button" id="btn-reconciliation-rollback" disabled><?php esc_html_e('تنفيذ التراجع', 'olama-media-library'); ?></button>
                        <pre id="reconciliation-rollback-result" class="olama-media-result" hidden></pre>
                    </div>
                </article>
        </div>

        <details id="olama-advanced-tools" class="olama-advanced-tools olama-technical-tools">
            <summary><span class="dashicons dashicons-admin-tools"></span> <?php esc_html_e('تشخيص تقني وأدوات صيانة', 'olama-media-library'); ?></summary>
            <p class="description"><?php esc_html_e('هذا القسم مخصص للدعم الفني. لا تحتاج إلى فتحه أثناء مسار الربط المعتاد.', 'olama-media-library'); ?></p>

            <div class="olama-v2-grid">
                <div class="olama-media-panel">
                    <h2><?php esc_html_e('إعادة بناء فهرس Google Drive', 'olama-media-library'); ?></h2>
                    <p><?php esc_html_e('يفحص جميع المجلدات. استخدمه فقط عند طلب الدعم الفني.', 'olama-media-library'); ?></p>
                    <button type="button" class="button" id="btn-v2-rebuild" <?php disabled(!$drive_sync_enabled); ?>><?php esc_html_e('إعادة بناء الفهرس', 'olama-media-library'); ?></button>
                    <pre id="v2-scan-result" class="olama-media-result" hidden></pre>
                </div>
                <div class="olama-media-panel">
                    <h2><?php esc_html_e('استيراد البيانات القديمة', 'olama-media-library'); ?></h2>
                    <p><?php esc_html_e('يُستخدم مرة واحدة فقط عند الانتقال من الإصدارات القديمة.', 'olama-media-library'); ?></p>
                    <button type="button" class="button" id="btn-import-legacy-data"><?php esc_html_e('استيراد البيانات القديمة', 'olama-media-library'); ?></button>
                    <pre id="maintenance-import-result" class="olama-media-result" hidden></pre>
                </div>
            </div>

            <div class="olama-media-panel" id="olama-diagnostic-logs">
                <div class="olama-v2-heading"><h2><?php esc_html_e('السجلات والتشخيص', 'olama-media-library'); ?></h2><button type="button" id="btn-refresh-log" class="button"><?php esc_html_e('تحديث السجلات', 'olama-media-library'); ?></button></div>
                <p class="olama-log-filters">
                    <input type="text" id="log-filter-job-uuid" class="regular-text" placeholder="<?php esc_attr_e('Job UUID', 'olama-media-library'); ?>">
                    <input type="text" id="log-filter-event-type" class="regular-text" placeholder="<?php esc_attr_e('Event type', 'olama-media-library'); ?>">
                    <input type="text" id="log-filter-error-code" class="regular-text" placeholder="<?php esc_attr_e('Error code', 'olama-media-library'); ?>">
                </p>
                <table class="wp-list-table widefat striped"><thead><tr>
                    <th><?php esc_html_e('الوقت', 'olama-media-library'); ?></th><th><?php esc_html_e('الحدث', 'olama-media-library'); ?></th>
                    <th><?php esc_html_e('الرسالة', 'olama-media-library'); ?></th><th><?php esc_html_e('الفيديو', 'olama-media-library'); ?></th>
                </tr></thead><tbody id="log-table-body"><tr><td colspan="4"><?php esc_html_e('اضغط تحديث السجلات.', 'olama-media-library'); ?></td></tr></tbody></table>
            </div>

            <div class="olama-media-panel"><h2><?php esc_html_e('آخر عمليات المزامنة', 'olama-media-library'); ?></h2>
                <table class="wp-list-table widefat striped"><thead><tr><th>Type</th><th>Status</th><th>Counts</th><th>Started</th><th>Finished</th></tr></thead><tbody id="v2-runs-body"></tbody></table>
            </div>

            <details class="olama-media-panel olama-v2-danger">
                <summary><?php esc_html_e('إعادة ضبط بيانات المزامنة', 'olama-media-library'); ?></summary>
                <p><?php esc_html_e('تحذير: قد يؤدي هذا الإجراء إلى إزالة روابط الفيديوهات. استخدمه فقط بتوجيه من الدعم الفني.', 'olama-media-library'); ?></p>
                <select id="v2-reset-scope"><option value="links_only">links only</option><option value="manifest_only">manifest only</option><option value="all_v2">all v2</option></select>
                <input type="text" id="v2-reset-confirmation" class="regular-text" placeholder="RESET V2 MEDIA INDEX">
                <button type="button" class="button" id="btn-v2-reset"><?php esc_html_e('إعادة الضبط', 'olama-media-library'); ?></button>
                <pre id="v2-reset-result" class="olama-media-result" hidden></pre>
            </details>
        </details>
    </section>
    <?php endif; ?>
</div>

<div id="video-preview-modal" class="olama-media-modal" hidden>
    <div class="olama-media-modal-box">
        <header>
            <h2 id="modal-video-title"></h2>
            <button type="button" class="olama-media-modal-close button-link">&times;</button>
        </header>
        <iframe id="video-preview-iframe" src="" allow="autoplay; encrypted-media" allowfullscreen></iframe>
    </div>
</div>

<input type="file" id="media-video-input" accept="video/mp4,.mp4" hidden>
