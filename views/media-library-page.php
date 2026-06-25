<?php
if (!defined('ABSPATH')) {
    exit;
}

$drive = new Olama_Media_Drive();
$refresh_token = $settings['refresh_token'] ?? '';
?>

<div class="wrap academy-media-library-wrap olama-media-library-wrap" dir="rtl">
    <h1><?php esc_html_e('مكتبة الوسائط', 'olama-media-library'); ?></h1>

    <h2 class="nav-tab-wrapper">
        <a href="#library" class="nav-tab nav-tab-active" data-tab="library"><?php esc_html_e('رفع الفيديوهات', 'olama-media-library'); ?></a>
        <a href="#logs" class="nav-tab" data-tab="logs"><?php esc_html_e('السجلات والتشخيص', 'olama-media-library'); ?></a>
        <a href="#migration" class="nav-tab" data-tab="migration"><?php esc_html_e('الترحيل', 'olama-media-library'); ?></a>
        <a href="#settings" class="nav-tab" data-tab="settings"><?php esc_html_e('إعدادات Drive', 'olama-media-library'); ?></a>
    </h2>

    <section id="tab-library" class="olama-media-tab active">
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

        <div id="curriculum-container" class="olama-media-lessons">
            <div class="notice notice-info inline"><p><?php esc_html_e('اختر الصف والمادة ثم حمّل الدروس. لا يتم فحص Google Drive أثناء تحميل القائمة.', 'olama-media-library'); ?></p></div>
        </div>
    </section>

    <section id="tab-logs" class="olama-media-tab">
        <p><button type="button" id="btn-refresh-log" class="button"><?php esc_html_e('تحديث السجلات', 'olama-media-library'); ?></button></p>
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
            <h2><?php esc_html_e('ترحيل السجلات القديمة', 'olama-media-library'); ?></h2>
            <p><?php esc_html_e('يمكن تشغيل الفحص الجاف أولا لمعرفة عدد السجلات التي سيتم إنشاؤها أو تحديثها. العملية آمنة ويمكن تكرارها بدون تكرار السجلات.', 'olama-media-library'); ?></p>
            <button type="button" class="button" id="btn-migration-dry-run"><?php esc_html_e('فحص جاف', 'olama-media-library'); ?></button>
            <button type="button" class="button button-primary" id="btn-migrate-legacy"><?php esc_html_e('Migrate existing media records', 'olama-media-library'); ?></button>
            <pre id="migration-result" class="olama-media-result"></pre>
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
