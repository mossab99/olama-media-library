jQuery(function ($) {
    'use strict';
    const cfg = window.olamaMediaLibrary;
    const dialog = document.getElementById('olama-drive-picker');
    let scope = null, binding = null, picker = null, generation = 0, browsing = 0, syncing = false;
    const key = (s) => s ? JSON.stringify(s) : '';
    const request = (op, data, selectedScope = scope) => $.post(cfg.ajaxurl, { action: 'olama_media_workspace', nonce: cfg.nonce, ...selectedScope, ...data, op });
    const error = (r) => typeof r?.data === 'string' ? r.data : r?.responseJSON?.data || cfg.i18n.error;
    const message = (text) => $('#olama-picker-message').text(text);
    $('#btn-v2-sync-now').prop('disabled', true);

    function status() {
        const selected = scope, version = generation;
        $('#olama-binding-label').text('جاري قراءة الربط المحفوظ...');
        $('#btn-select-drive-folder').prop('disabled', true);
        request('status', {}, selected).done(function (r) {
            if (version !== generation) return;
            if (!r.success) { $('#olama-binding-label').text(error(r)); return; }
            binding = r.data;
            $('#olama-binding-label').text(binding.binding_ready ? `مرتبط: ${binding.folder_label || 'مجلد المادة المعتمد'}` : 'يلزم اختيار مجلد المادة مرة واحدة أو إعادة تأكيد الربط.');
            $('#btn-select-drive-folder').prop('disabled', !binding.root_folder_id || syncing);
            $('#btn-v2-sync-now').prop('disabled', syncing || !binding.binding_ready || !cfg.driveFeatures.syncEnabled);
        }).fail(function (r) { if (version === generation) $('#olama-binding-label').text(error(r)); });
    }

    $(document).on('olama:curriculum-loaded', function (_, selected) {
        scope = { ...selected }; generation++; binding = null; status();
    }).on('olama:scope-changed', function () {
        scope = null; binding = null; generation++; browsing++;
        $('#btn-select-drive-folder, #btn-v2-sync-now').prop('disabled', true);
        $('#olama-binding-label').text('حمّل المنهج المحدد لعرض الربط.');
        if (dialog.open) dialog.close();
    });

    function openPicker(mode, lesson) {
        if (!scope || !binding || (mode === 'videos' && !binding.binding_ready)) {
            $('#olama-v2-auto-sync-status').removeAttr('hidden').find('p').text('حمّل المنهج واختر مجلد المادة أولاً.');
            return;
        }
        picker = { mode, lesson, scope: { ...scope }, previous: binding.folder_id, folder: '', next: '' };
        $('#olama-picker-title').text(mode === 'folders' ? 'اختيار مجلد المادة' : 'اختيار فيديو من Drive');
        $('#olama-picker-context').text(mode === 'folders' ? 'اختر مجلد هذه المادة للمنهج المحدد. تبقى روابط الفيديوهات الحالية محفوظة عند تغيير المجلد.' : 'تصفح مجلد المادة ووحداتها. ربط الفيديو يضعه بانتظار الاعتماد ولا يغيّر الملف في Drive.');
        $('#olama-picker-part-label').prop('hidden', mode === 'folders');
        $('#olama-picker-part').val('');
        $('#olama-picker-bind').prop('hidden', mode !== 'folders');
        $('#olama-picker-create-section').prop('hidden', true);
        dialog.showModal();
        browse(mode === 'folders' ? binding.root_folder_id : binding.folder_id);
    }

    $('#btn-select-drive-folder').on('click', () => openPicker('folders'));
    $(document).on('click', '.btn-choose-drive', function () { openPicker('videos', Number($(this).data('lesson-id'))); });
    $('.olama-picker-close').on('click', () => dialog.close());
    dialog.addEventListener('close', () => { browsing++; picker = null; });
    $('#olama-picker-search').on('input', function () {
        const text = this.value.trim().toLocaleLowerCase();
        $('#olama-picker-items > div').each(function () { $(this).prop('hidden', !$(this).text().toLocaleLowerCase().includes(text)); });
    });
    $('#olama-picker-refresh').on('click', () => { if (picker) browse(picker.folder); });
    $('#olama-picker-more').on('click', () => { if (picker && picker.next) browse(picker.folder, picker.next); });

    function busy(value) {
        $('#olama-picker-bind, #olama-picker-refresh, #olama-picker-more, #olama-picker-create-folder, #olama-picker-open-existing, #olama-picker-items button, #olama-picker-breadcrumbs button').prop('disabled', value);
        if (!value) $('#olama-picker-create-folder').prop('disabled', !picker?.availability?.can_create || !picker?.folder);
    }

    function browse(folder, page = '') {
        const version = ++browsing, current = picker;
        current.folder = '';
        current.availability = null;
        $('#olama-picker-create-section').prop('hidden', true);
        busy(true); message('جاري قراءة المجلد من Drive...');
        $('#olama-picker-items').empty(); $('#olama-picker-more').prop('hidden', true);
        return request(current.mode === 'folders' ? 'browse_folders' : 'browse_videos', { folder_id: folder, page_token: page }, current.scope).done(function (r) {
            if (version !== browsing || picker !== current) return;
            if (!r.success) { message(error(r)); return; }
            current.folder = r.data.folder_id; current.next = r.data.next_page_token;
            if (current.mode === 'folders') {
                current.availability = r.data.subject_folder || { can_create: false, reason: 'حدّث إضافة مكتبة الوسائط على الخادم لإتاحة إنشاء المجلدات.' };
                const availability = current.availability;
                $('#olama-picker-create-section').prop('hidden', false);
                $('#olama-picker-create-title').text(availability.name ? `مجلد المادة: ${availability.name}` : 'إنشاء مجلد المادة');
                $('#olama-picker-create-help').text(availability.can_create ? `سيُنشأ المجلد داخل «${availability.parent_name}»، ثم يمكنك اعتماده للمادة.` : availability.reason);
                $('#olama-picker-create-folder').text(availability.exists ? 'المجلد موجود بالفعل' : 'إنشاء مجلد المادة هنا');
                const ids = availability.existing_ids || [];
                current.existingFolder = ids.length === 1 && ids[0] !== current.folder ? ids[0] : '';
                $('#olama-picker-open-existing').prop('hidden', !current.existingFolder);
            }
            $('#olama-picker-search').val('');
            const crumbs = $('#olama-picker-breadcrumbs').empty();
            r.data.breadcrumbs.forEach((f) => crumbs.append($('<button type="button" class="button-link">').text(f.name).on('click', () => browse(f.id))));
            const list = $('#olama-picker-items').empty();
            r.data.items.forEach(function (item) {
                const row = $('<div class="olama-picker-item">');
                row.append($('<strong>').text(item.folder ? `📁 ${item.name}` : item.name));
                if (item.folder) row.append($('<button type="button" class="button">').text('فتح').on('click', () => browse(item.id)));
                else {
                    if (item.url) row.append($('<a class="button" target="_blank" rel="noopener noreferrer">').attr('href', item.url).text('معاينة'));
                    const other = item.linked_lesson && item.linked_lesson !== current.lesson;
                    row.append($('<button type="button" class="button button-primary">').prop('disabled', !!other).attr('data-linked-elsewhere', other ? '1' : '0').text(other ? 'مرتبط بدرس آخر' : item.linked_lesson ? 'مرتبط بهذا الدرس' : 'ربط بالدرس').on('click', () => link(item.id)));
                }
                list.append(row);
            });
            message(r.data.items.length ? 'اختر من النتائج؛ التصفية تخص الصفحة الحالية فقط.' : 'لا توجد نتائج في هذه الصفحة.');
            $('#olama-picker-more').prop('hidden', !current.next);
        }).fail((r) => { if (version === browsing) message(error(r)); }).always(function () {
            if (version !== browsing) return;
            busy(false);
            $('#olama-picker-items [data-linked-elsewhere="1"]').prop('disabled', true);
            if (!current.folder) $('#olama-picker-bind').prop('disabled', true);
        });
    }

    $('#olama-picker-open-existing').on('click', () => { if (picker?.existingFolder) browse(picker.existingFolder); });
    $('#olama-picker-create-folder').on('click', function () {
        const current = picker, version = browsing;
        if (!current?.folder || current.mode !== 'folders' || !current.availability?.can_create) return;
        const parent = current.folder;
        busy(true); message('جاري فحص الاسم وإنشاء مجلد المادة...');
        request('create_subject_folder', { parent_id: parent, root_hash: current.availability.root_hash }, current.scope).done(function (r) {
            if (version !== browsing || picker !== current) return;
            const text = r.success ? 'تم إنشاء مجلد المادة. اضغط «اعتماد هذا المجلد للمادة» لحفظ الربط.' : error(r);
            browse(r.success ? r.data.folder_id : parent).done(function (response) {
                if (picker === current && response.success) message(text);
            });
        }).fail(function (r) {
            if (version !== browsing || picker !== current) return;
            // A lost response may still have created the folder. Refresh before
            // allowing another attempt; the server always rechecks all siblings.
            const text = error(r);
            browse(parent).done(function (response) { if (picker === current && response.success) message(text); });
        }).always(() => { if (version === browsing) busy(false); });
    });

    $('#olama-picker-bind').on('click', function () {
        const current = picker, version = browsing;
        if (!current?.folder) return;
        busy(true); message('جاري التحقق من المجلد وحفظ الربط...');
        request('bind', { folder_id: current.folder, previous_folder_id: current.previous }, current.scope).done(function (r) {
            if (version !== browsing || current !== picker) return;
            if (!r.success) { message(error(r)); return; }
            dialog.close(); status();
        }).fail((r) => { if (version === browsing) message(error(r)); }).always(() => { if (version === browsing) busy(false); });
    });

    function link(id) {
        const current = picker, version = browsing;
        const part = $('#olama-picker-part').val();
        if (part && (!Number.isInteger(Number(part)) || Number(part) < 1 || Number(part) > 999)) { message('أدخل رقم جزء صحيحاً بين 1 و999.'); return; }
        busy(true); message('جاري التحقق من الفيديو وربطه...');
        request('link', { file_id: id, lesson_id: current.lesson, part_number: part }, current.scope).done(function (r) {
            if (version !== browsing || current !== picker) return;
            if (!r.success) { message(error(r)); return; }
            dialog.close(); $(document).trigger('olama:reload');
        }).fail((r) => { if (version === browsing) message(error(r)); }).always(() => {
            if (version === browsing) { busy(false); $('#olama-picker-items [data-linked-elsewhere="1"]').prop('disabled', true); }
        });
    }

    $(document).on('olama:sync', function (_, selected) {
        if (syncing || !scope || key(selected) !== key(scope) || !binding?.binding_ready || !cfg.driveFeatures.syncEnabled) return;
        const selectedScope = { ...scope };
        syncing = true;
        $('#btn-v2-sync-now, #btn-select-drive-folder').prop('disabled', true);
        const result = $('#olama-v2-auto-sync-status').removeAttr('hidden').removeClass('notice-error notice-success').addClass('notice-info');
        result.find('p').text('جاري فحص مجلد المادة...');
        function finish() {
            syncing = false;
            $('#btn-v2-sync-now').text('فحص ومزامنة Google Drive');
            if (scope) status();
        }
        function step(token = '') {
            request('sync', { token }, selectedScope).done(function (r) {
                if (!r.success) { result.removeClass('notice-info').addClass('notice-error').find('p').text(error(r)); finish(); return; }
                const data = r.data;
                if (!data.done) {
                    result.find('p').text(`جاري الفحص: ${data.files} فيديو، ${data.folders} مجلد. يمكنك متابعة العمل.`);
                    window.setTimeout(() => step(data.token), 50);
                    return;
                }
                result.removeClass('notice-info').addClass(data.errors ? 'notice-error' : 'notice-success').find('p').text(`اكتمل الفحص: ${data.linked} ربط جديد بانتظار الاعتماد، ${data.already_linked} مرتبط سابقاً، ${data.needs_assignment} يحتاج اختياراً يدوياً، ${data.dismissed} مستبعد، ${data.errors} تعذر حفظه. استخدم «اختر من Drive» لإكمال الربط.`);
                finish();
                if (key(scope) === key(selectedScope)) $(document).trigger('olama:reload');
            }).fail(function (r) { result.removeClass('notice-info').addClass('notice-error').find('p').text(error(r)); finish(); });
        }
        step();
    });
});
