<?php
if (!defined('ABSPATH')) {
    exit;
}

$drive = new Olama_Media_Drive();
$refresh_token = $settings['refresh_token'] ?? '';
$can_administer = Olama_Media_Admin::can_manage() && !Olama_Media_Admin::is_teacher();
?>

<div class="wrap academy-media-library-wrap olama-media-library-wrap<?php echo $can_administer ? '' : ' olama-upload-only'; ?>" dir="rtl">
    <h1><?php esc_html_e('مكتبة الوسائط', 'olama-media-library'); ?></h1>

    <h2 class="nav-tab-wrapper">
        <?php if ($can_administer) : ?><a href="#coverage" class="nav-tab" data-tab="coverage"><?php esc_html_e('Curriculum Video Coverage Report', 'olama-media-library'); ?></a><?php endif; ?>
        <a href="#library" class="nav-tab nav-tab-active" data-tab="library"><?php esc_html_e('رفع الفيديوهات', 'olama-media-library'); ?></a>
        <a href="#logs" class="nav-tab" data-tab="logs"><?php esc_html_e('السجلات والتشخيص', 'olama-media-library'); ?></a>
        <a href="#migration" class="nav-tab" data-tab="migration"><?php esc_html_e('الترحيل', 'olama-media-library'); ?></a>
        <?php if ($can_administer) : ?><a href="#drive-v2" class="nav-tab" data-tab="drive-v2"><?php esc_html_e('Drive v2', 'olama-media-library'); ?></a><?php endif; ?>
        <a href="#settings" class="nav-tab" data-tab="settings"><?php esc_html_e('إعدادات Drive', 'olama-media-library'); ?></a>
    </h2>

    <section id="tab-library" class="olama-media-tab active">
        <?php if (empty($drive_auth_health['is_configured']) || empty($drive_auth_health['has_refresh_token']) || empty($drive_auth_health['can_refresh'])) : ?>
            <div class="notice notice-error inline olama-drive-auth-warning">
                <p>
                    <strong><?php esc_html_e('تنبيه:', 'olama-media-library'); ?></strong>
                    <?php esc_html_e('اتصال Google Drive غير مكتمل. لن تنجح عملية رفع الفيديوهات حتى تتم إعادة المصادقة.', 'olama-media-library'); ?>
                    <a href="#settings" class="button button-small nav-tab-jump" data-tab="settings"><?php esc_html_e('إعدادات Drive', 'olama-media-library'); ?></a>
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

    <section id="tab-logs" class="olama-media-tab">
        <p><button type="button" id="btn-refresh-log" class="button"><?php esc_html_e('تحديث السجلات', 'olama-media-library'); ?></button></p>
        <p class="olama-log-filters">
            <input type="text" id="log-filter-job-uuid" class="regular-text" placeholder="<?php esc_attr_e('Job UUID', 'olama-media-library'); ?>">
            <input type="text" id="log-filter-event-type" class="regular-text" placeholder="<?php esc_attr_e('Event type', 'olama-media-library'); ?>">
            <input type="text" id="log-filter-error-code" class="regular-text" placeholder="<?php esc_attr_e('Error code', 'olama-media-library'); ?>">
        </p>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e('الوقت', 'olama-media-library'); ?></th>
                    <th><?php esc_html_e('الحدث', 'olama-media-library'); ?></th>
                    <th><?php esc_html_e('الرسالة', 'olama-media-library'); ?></th>
                    <th><?php esc_html_e('الفيديو', 'olama-media-library'); ?></th>
                </tr>
            </thead>
            <tbody id="log-table-body">
                <tr><td colspan="4"><?php esc_html_e('اضغط تحديث السجلات.', 'olama-media-library'); ?></td></tr>
            </tbody>
        </table>
    </section>

    <section id="tab-migration" class="olama-media-tab">
        <div class="olama-media-panel">
            <h2><?php esc_html_e('Sync existing Google Drive videos', 'olama-media-library'); ?></h2>
            <p><?php esc_html_e('Select the curriculum filters in the Media tab first. The dry run previews exact filename matches; Sync then restores missing local media links without uploading or duplicating files.', 'olama-media-library'); ?></p>
            <button type="button" class="button" id="btn-drive-sync-dry-run"><?php esc_html_e('Preview Drive sync', 'olama-media-library'); ?></button>
            <button type="button" class="button button-primary" id="btn-drive-sync"><?php esc_html_e('Sync matched videos', 'olama-media-library'); ?></button>
            <pre id="drive-sync-result" class="olama-media-result"></pre>
        </div>
        <div class="olama-media-panel">
            <h2><?php esc_html_e('ترحيل السجلات القديمة', 'olama-media-library'); ?></h2>
            <p><?php esc_html_e('يمكن تشغيل الفحص الجاف أولا لمعرفة عدد السجلات التي سيتم إنشاؤها أو تحديثها. العملية آمنة ويمكن تكرارها بدون تكرار السجلات.', 'olama-media-library'); ?></p>
            <button type="button" class="button" id="btn-migration-dry-run"><?php esc_html_e('فحص جاف', 'olama-media-library'); ?></button>
            <button type="button" class="button button-primary" id="btn-migrate-legacy"><?php esc_html_e('Migrate existing media records', 'olama-media-library'); ?></button>
            <pre id="migration-result" class="olama-media-result"></pre>
        </div>
    </section>

    <section id="tab-drive-v2" class="olama-media-tab">
        <div class="olama-v2-grid">
            <div class="olama-media-panel">
                <h2><?php esc_html_e('فهرس Google Drive', 'olama-media-library'); ?></h2>
                <p><?php esc_html_e('يفحص زر Drive المادة المحددة فقط لسرعة التنفيذ. إعادة بناء الفهرس هي العملية الكاملة لجميع المجلدات.', 'olama-media-library'); ?></p>
                <button type="button" class="button button-primary" id="btn-v2-scan"><?php esc_html_e('فحص Drive', 'olama-media-library'); ?></button>
                <button type="button" class="button" id="btn-v2-scan-dry"><?php esc_html_e('فحص تجريبي', 'olama-media-library'); ?></button>
                <button type="button" class="button" id="btn-v2-rebuild"><?php esc_html_e('إعادة بناء فهرس Drive', 'olama-media-library'); ?></button>
                <pre id="v2-scan-result" class="olama-media-result"></pre>
            </div>
            <div class="olama-media-panel">
                <h2><?php esc_html_e('مطابقة المادة', 'olama-media-library'); ?></h2>
                <p><?php esc_html_e('تستخدم اختيارات السنة والفصل والصف والمادة الموجودة في تبويب رفع الفيديوهات. الربط التلقائي يعيد بناء الروابط المعلقة التي أنشأها النظام فقط، ولا يحذف الروابط المعتمدة أو اليدوية.', 'olama-media-library'); ?></p>
                <button type="button" class="button" id="btn-v2-match-preview"><?php esc_html_e('معاينة الربط', 'olama-media-library'); ?></button>
                <button type="button" class="button button-primary" id="btn-v2-match-apply"><?php esc_html_e('ربط تلقائي', 'olama-media-library'); ?></button>
                <button type="button" class="button" id="btn-v2-match-force"><?php esc_html_e('إعادة ربط آمن', 'olama-media-library'); ?></button>
                <pre id="v2-match-result" class="olama-media-result"></pre>
            </div>
        </div>

        <div class="olama-media-panel">
            <div class="olama-v2-heading"><h2><?php esc_html_e('قائمة المراجعة', 'olama-media-library'); ?></h2><button type="button" class="button" id="btn-v2-review-refresh"><?php esc_html_e('تحديث', 'olama-media-library'); ?></button></div>
            <table class="wp-list-table widefat striped"><thead><tr>
                <th><?php esc_html_e('ملف Drive', 'olama-media-library'); ?></th><th><?php esc_html_e('المسار', 'olama-media-library'); ?></th>
                <th><?php esc_html_e('الدرس المقترح', 'olama-media-library'); ?></th><th><?php esc_html_e('الوحدة', 'olama-media-library'); ?></th>
                <th><?php esc_html_e('الجزء', 'olama-media-library'); ?></th><th><?php esc_html_e('الثقة', 'olama-media-library'); ?></th><th><?php esc_html_e('الإجراءات', 'olama-media-library'); ?></th>
            </tr></thead><tbody id="v2-review-body"><tr><td colspan="7">-</td></tr></tbody></table>
        </div>

        <div class="olama-v2-grid">
            <div class="olama-media-panel">
                <h2><?php esc_html_e('استيراد السجلات القديمة', 'olama-media-library'); ?></h2>
                <label><input type="checkbox" id="v2-include-stale"> <?php esc_html_e('تضمين الروابط القديمة غير المرتبطة بدروس حالية', 'olama-media-library'); ?></label>
                <p><button type="button" class="button" id="btn-v2-import-legacy"><?php esc_html_e('Import existing media assets into v2', 'olama-media-library'); ?></button></p>
                <pre id="v2-import-result" class="olama-media-result"></pre>
            </div>
            <div class="olama-media-panel olama-v2-danger">
                <h2><?php esc_html_e('إعادة ضبط فهرس v2', 'olama-media-library'); ?></h2>
                <select id="v2-reset-scope"><option value="links_only">links only</option><option value="manifest_only">manifest only</option><option value="all_v2">all v2</option></select>
                <input type="text" id="v2-reset-confirmation" class="regular-text" placeholder="RESET V2 MEDIA INDEX">
                <button type="button" class="button" id="btn-v2-reset"><?php esc_html_e('إعادة الضبط', 'olama-media-library'); ?></button>
                <pre id="v2-reset-result" class="olama-media-result"></pre>
            </div>
        </div>

        <div class="olama-media-panel"><h2><?php esc_html_e('آخر عمليات v2', 'olama-media-library'); ?></h2>
            <table class="wp-list-table widefat striped"><thead><tr><th>Type</th><th>Status</th><th>Counts</th><th>Started</th><th>Finished</th></tr></thead><tbody id="v2-runs-body"></tbody></table>
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
