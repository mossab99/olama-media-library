jQuery(function ($) {
    'use strict';
    const cfg = window.olamaMediaLibrary;
    const dialog = document.getElementById('olama-drive-picker');
    let scope = null, binding = null, picker = null, generation = 0, browsing = 0, syncing = false;
    let pendingSyncScope = null, workflowReport = null, workflowPage = 0, applying = false;
    const decisions = new Map(), rowErrors = new Map(), pageSize = 25;
    let pendingCorrection = null;
    const key = (s) => s ? JSON.stringify(s) : '';
    const request = (op, data, selectedScope = scope) => $.post(cfg.ajaxurl, { action: 'olama_media_workspace', nonce: cfg.nonce, ...selectedScope, ...data, op });
    const error = (r) => typeof r?.data === 'string' ? r.data : r?.responseJSON?.data || cfg.i18n.error;
    const message = (text) => $('#olama-picker-message').text(text);
    $('#btn-v2-sync-now').prop('disabled', true);

    function status() {
        const selected = scope, version = generation;
        $('#olama-binding-label').text('جاري قراءة الربط المحفوظ...');
        $('#btn-select-drive-folder').prop('disabled', true);
        return request('status', {}, selected).done(function (r) {
            if (version !== generation) return;
            if (!r.success) { $('#olama-binding-label').text(error(r)); return; }
            binding = r.data;
            $('#olama-binding-label').text(binding.binding_ready ? `مرتبط: ${binding.folder_label || 'مجلد المادة المعتمد'}` : 'يلزم اختيار مجلد المادة مرة واحدة أو إعادة تأكيد الربط.');
            $('#btn-select-drive-folder').prop('disabled', !binding.root_folder_id || syncing);
            $('#btn-v2-sync-now').prop('disabled', syncing || !binding.root_folder_id || !cfg.driveFeatures.syncEnabled);
            loadReport(selected);
        }).fail(function (r) { if (version === generation) $('#olama-binding-label').text(error(r)); });
    }

    $(document).on('olama:curriculum-loaded', function (_, selected) {
        scope = { ...selected }; generation++; binding = null; status();
    }).on('olama:scope-changed', function () {
        scope = null; binding = null; generation++; browsing++;
        workflowReport = null; workflowPage = 0; pendingSyncScope = null; decisions.clear(); rowErrors.clear();
        pendingCorrection = null; $('#workflow-confirm').prop('hidden', true);
        $('#olama-subject-workflow, #olama-v2-auto-sync-status').prop('hidden', true);
        $('#btn-select-drive-folder, #btn-v2-sync-now').prop('disabled', true);
        $('#olama-binding-label').text('حمّل المنهج المحدد لعرض الربط.');
        if (dialog.open) dialog.close();
    });

    function loadReport(selected = scope) {
        if (!selected) return;
        const version = generation;
        return request('report', {}, selected).done(function (r) {
            if (version !== generation || key(scope) !== key(selected)) return;
            if (r.success) renderWorkflow(r.data);
            else $('#olama-v2-auto-sync-status').prop('hidden', false).find('p').text(error(r));
        }).fail(function (r) {
            if (version === generation) $('#olama-v2-auto-sync-status').prop('hidden', false).find('p').text(error(r));
        });
    }

    function renderWorkflow(data) {
        workflowReport = data;
        if ($('#olama-v2-auto-sync-status').hasClass('notice-success')) $('#olama-v2-auto-sync-status').prop('hidden', true);
        const state = data.state || 'needs_scan', items = data.items || [];
        const titles = { needs_folder:'حدد مكان فيديوهات المادة', needs_scan:'المادة جاهزة للفحص والمزامنة', no_videos:items.length ? 'لم تظهر فيديوهات في المجلد المفحوص' : 'لا توجد فيديوهات لهذه المادة بعد', complete:items.length ? 'بقيت ملفات تحتاج قرارك' : 'اكتملت المزامنة' };
        const descriptions = { needs_folder:'اختر مجلدًا موجودًا أو أنشئ مجلد المادة. يمكنك أيضًا اختيار الدرس أدناه ورفع فيديو جديد.', needs_scan:'اضغط «فحص ومزامنة» لربط المطابقات المؤكدة ومراجعة البقية هنا.', no_videos:'اكتمل فحص مجلد المادة ومجلداته الفرعية. ارفع فيديو من صف الدرس، أو اختر مجلدًا آخر إذا كانت الفيديوهات في مكان مختلف.', complete:`${Number(data.linked || 0)} ربط تلقائي بانتظار الاعتماد · ${Number(data.already_linked || 0)} مرتبط سابقًا · ${items.length} يحتاج قرارًا · ${Number(data.dismissed || 0)} مستبعد.` };
        $('#olama-subject-workflow').prop('hidden', false).attr('data-state', state);
        $('#olama-workflow-title').text(titles[state] || titles.needs_scan);
        $('#olama-workflow-description').text(descriptions[state] || descriptions.needs_scan);
        $('#olama-workflow-date').text(data.completed_at ? `آخر فحص مكتمل: ${data.completed_at}` : '');
        $('#btn-workflow-folder').prop('disabled', !binding?.root_folder_id).prop('hidden', state === 'complete' && !items.length);
        $('#btn-workflow-folder').text(state === 'no_videos' ? 'اختيار مجلد آخر من Drive' : 'اختيار / إنشاء مجلد المادة');
        $('#btn-workflow-upload').prop('disabled', !cfg.canUpload || !cfg.driveFeatures.uploadEnabled);
        $('#btn-workflow-retry').prop('hidden', state === 'needs_folder').prop('disabled', syncing || !cfg.canManage || !cfg.driveFeatures.syncEnabled);
        renderInbox();
    }

    function showLesson(lessonId) {
        const row = $('#curriculum-container tr[data-lesson-id]').filter(function () { return Number($(this).data('lesson-id')) === Number(lessonId); }).first();
        const target = row.length ? row : $('#curriculum-container');
        target[0]?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        row.find('.btn-upload:not(:disabled)').first().trigger('focus');
    }
    $('#btn-workflow-folder').on('click', () => $('#btn-select-drive-folder').trigger('click'));
    $('#btn-workflow-upload').on('click', () => showLesson($('#curriculum-container tr[data-lesson-id]').first().data('lesson-id')));
    $('#btn-workflow-retry').on('click', () => $(document).trigger('olama:sync', [scope]));

    function draftFor(item) {
        if (!decisions.has(item.file_id)) decisions.set(item.file_id, { lesson_id:Number(item.suggested_lesson_id || 0), part_number:Number(item.part_number || 0), selected:false });
        return decisions.get(item.file_id);
    }
    function renderInbox() {
        const items = workflowReport?.items || [], lessons = workflowReport?.lessons || [];
        $('#olama-workflow-inbox').prop('hidden', !cfg.canManage || !items.length);
        if (!cfg.canManage || !items.length) return;
        const totalPages = Math.ceil(items.length / pageSize);
        workflowPage = Math.min(workflowPage, totalPages - 1);
        const pageItems = items.slice(workflowPage * pageSize, (workflowPage + 1) * pageSize);
        const list = $('#olama-workflow-items').empty();
        const reasons = { unmatched:'اختر الدرس المناسب', ambiguous:'أكثر من درس محتمل', occupied:'توجد فيديوهات أخرى لهذا الدرس أو الجزء', link_conflict:'مرتبط بدرس مختلف', not_observed:'لم يظهر في المجلد؛ قد يكون منقولًا أو غير متاح', save_failed:'تعذر حفظه في المحاولة السابقة' };
        pageItems.forEach(function (item) {
            const draft = draftFor(item), missing = item.reason === 'not_observed';
            const row = $('<tr>').attr('data-file-id', item.file_id);
            const check = $('<input type="checkbox" class="workflow-select">').attr('aria-label', `تحديد ${item.filename}`).prop('checked', draft.selected).prop('disabled', missing || applying);
            check.on('change', function () { draft.selected = this.checked; updateBulkButton(); });
            row.append($('<td>').append(check));
            const fileCell = $('<td>').append($('<strong>').text(item.filename));
            if (item.path) fileCell.append($('<small>').text(item.path));
            if (item.url) fileCell.append($('<button type="button" class="button btn-preview">').text('معاينة').data('url', item.url).data('title', item.filename));
            row.append(fileCell);
            const reason = $('<td>').append($('<span>').text(reasons[item.reason] || 'تحتاج مراجعة'));
            if (item.current_lesson_id) reason.append($('<small>').text(`الربط الحالي: ${lessons.find((l) => l.id === Number(item.current_lesson_id))?.label || 'درس رقم ' + item.current_lesson_id + ' خارج نطاق المادة'}`));
            if (rowErrors.has(item.file_id)) reason.append($('<p class="olama-inbox-error" role="alert">').text(rowErrors.get(item.file_id)));
            row.append(reason);
            const select = $('<select class="workflow-lesson">').attr('aria-label', `درس الفيديو ${item.filename}`).prop('disabled', missing || applying).append($('<option>').val('0').text('— اختر الدرس —'));
            lessons.forEach((lesson) => select.append($('<option>').val(lesson.id).text(lesson.label)));
            select.val(String(draft.lesson_id)).on('change', function () { draft.lesson_id = Number(this.value); row.find('.workflow-link').prop('disabled', !draft.lesson_id || applying); });
            const part = $('<input type="number" min="1" max="999" class="workflow-part">').attr('aria-label', `رقم جزء ${item.filename}`).attr('placeholder', 'الجزء (اختياري)').prop('disabled', missing || applying).val(draft.part_number || '').on('input', function () { draft.part_number = Number(this.value || 0); });
            row.append($('<td>').append(select, part));
            const actions = $('<td>');
            if (missing) actions.append($('<button type="button" class="button">').text('مراجعة الدرس').on('click', () => showLesson(item.current_lesson_id)));
            else {
                actions.append($('<button type="button" class="button workflow-link">').text(item.link_revision ? 'تصحيح الربط' : 'ربط').prop('disabled', !draft.lesson_id || applying).on('click', () => applyRows([item.file_id])));
                if (!item.link_revision) actions.append($('<button type="button" class="button">').text('تجاهل').prop('disabled', applying).on('click', () => applyRows([item.file_id], true)));
            }
            row.append(actions); list.append(row);
        });
        $('#workflow-page-label').text(`${workflowPage + 1} / ${totalPages} · ${items.length} ملف`);
        $('#workflow-prev').prop('disabled', workflowPage === 0 || applying);
        $('#workflow-next').prop('disabled', workflowPage + 1 >= totalPages || applying);
        $('#workflow-select-page').prop('checked', pageItems.filter((i) => i.reason !== 'not_observed').every((i) => draftFor(i).selected)).prop('disabled', applying);
        updateBulkButton();
    }
    function updateBulkButton() {
        $('#btn-workflow-apply').prop('disabled', applying || !(workflowReport?.items || []).some((i) => i.reason !== 'not_observed' && draftFor(i).selected));
    }
    $('#workflow-select-page').on('change', function () {
        (workflowReport?.items || []).slice(workflowPage * pageSize, (workflowPage + 1) * pageSize).filter((i) => i.reason !== 'not_observed').forEach((i) => { draftFor(i).selected = this.checked; }); renderInbox();
    });
    $('#workflow-prev').on('click', () => { workflowPage--; renderInbox(); });
    $('#workflow-next').on('click', () => { workflowPage++; renderInbox(); });
    $('#btn-workflow-apply').on('click', () => applyRows((workflowReport?.items || []).filter((i) => draftFor(i).selected).map((i) => i.file_id)));

    $('#workflow-confirm-cancel').on('click', () => { pendingCorrection = null; $('#workflow-confirm').prop('hidden', true); });
    $('#workflow-confirm-apply').on('click', function () {
        const pending = pendingCorrection;
        pendingCorrection = null; $('#workflow-confirm').prop('hidden', true);
        if (pending && key(scope) === pending.scope) applyRows(pending.ids, false, pending.signature === correctionSignature(pending.ids));
    });
    function correctionSignature(ids) {
        return JSON.stringify((workflowReport?.items || []).filter((i) => ids.includes(i.file_id)).map((i) => [i.file_id, draftFor(i).lesson_id, draftFor(i).part_number, i.link_revision]));
    }

    async function applyRows(ids, dismiss = false, confirmed = false) {
        if (applying || !scope) return;
        const selected = { ...scope }, items = (workflowReport?.items || []).filter((i) => ids.includes(i.file_id) && i.reason !== 'not_observed');
        const changes = items.filter((i) => i.link_revision);
        if (changes.length && !confirmed) {
            pendingCorrection = { ids, scope:key(scope), signature:correctionSignature(ids) };
            const list = $('#workflow-confirm-items').empty();
            changes.forEach((item) => list.append($('<li>').text(`${item.filename} ← ${workflowReport.lessons.find((l) => l.id === draftFor(item).lesson_id)?.label || 'اختر الدرس أولًا'}`)));
            $('#workflow-confirm').prop('hidden', false)[0]?.scrollIntoView({behavior:'smooth',block:'center'});
            return;
        }
        applying = true; renderInbox();
        for (const item of items) {
            if (key(scope) !== key(selected)) break;
            const draft = draftFor(item);
            if (!dismiss && (!draft.lesson_id || !Number.isInteger(draft.part_number) || draft.part_number < 0 || draft.part_number > 999)) { rowErrors.set(item.file_id, 'اختر الدرس ورقم جزء صحيحًا إن لزم.'); continue; }
            try {
                const r = await request(dismiss ? 'dismiss' : item.link_revision ? 'reassign' : 'link', { file_id:item.file_id, lesson_id:draft.lesson_id, part_number:draft.part_number, link_revision:item.link_revision || '' }, selected);
                if (r.success) { decisions.delete(item.file_id); rowErrors.delete(item.file_id); }
                else rowErrors.set(item.file_id, error(r));
            } catch (r) { rowErrors.set(item.file_id, error(r)); }
        }
        applying = false;
        if (scope) loadReport();
        if (key(scope) === key(selected)) $(document).trigger('olama:reload');
    }

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

    $('#btn-select-drive-folder').on('click', () => { pendingSyncScope = null; openPicker('folders'); });
    $(document).on('click', '.btn-choose-drive', function () { openPicker('videos', Number($(this).data('lesson-id'))); });
    $('.olama-picker-close').on('click', () => dialog.close());
    dialog.addEventListener('close', () => { browsing++; picker = null; pendingSyncScope = null; });
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
            const resume = pendingSyncScope;
            dialog.close();
            status()?.done(() => { if (resume && key(scope) === key(resume) && binding?.binding_ready) $(document).trigger('olama:sync', [resume]); });
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
        if (syncing || applying || !scope || key(selected) !== key(scope) || !cfg.driveFeatures.syncEnabled) return;
        if (!binding?.binding_ready) { pendingSyncScope = { ...scope }; openPicker('folders'); return; }
        const selectedScope = { ...scope };
        syncing = true;
        $('#btn-v2-sync-now, #btn-select-drive-folder').prop('disabled', true);
        const result = $('#olama-v2-auto-sync-status').removeAttr('hidden').removeClass('notice-error notice-success').addClass('notice-info');
        result.find('p').text('جاري فحص مجلد المادة...');
        function finish() {
            syncing = false;
            $('#btn-v2-sync-now').text('فحص ومزامنة');
            if (scope) status();
        }
        function step(token = '') {
            if (key(scope) !== key(selectedScope)) { finish(); return; }
            request('sync', { token }, selectedScope).done(function (r) {
                if (key(scope) !== key(selectedScope)) { finish(); return; }
                if (!r.success) { result.removeClass('notice-info').addClass('notice-error').find('p').text(`تعذر إكمال الفحص؛ لا يعني ذلك أن المادة فارغة. ${error(r)}`); finish(); return; }
                const data = r.data;
                if (!data.done) {
                    result.find('p').text(`جاري الفحص: ${data.files} فيديو، ${data.folders} مجلد. يمكنك متابعة العمل.`);
                    window.setTimeout(() => step(data.token), 50);
                    return;
                }
                result.removeClass('notice-info').addClass(data.errors ? 'notice-error' : 'notice-success').find('p').text(data.files === 0 ? 'اكتمل فحص مجلد المادة ومجلداته الفرعية؛ لم يُعثر على فيديوهات. يمكنك الرفع أو اختيار مجلد آخر.' : `اكتمل الفحص: ${data.linked} ربط جديد بانتظار الاعتماد، ${data.already_linked} مرتبط سابقًا، ${data.needs_assignment} يحتاج قرارًا في القائمة أدناه.`);
                finish();
                if (key(scope) === key(selectedScope)) $(document).trigger('olama:reload');
            }).fail(function (r) { if (key(scope) === key(selectedScope)) result.removeClass('notice-info').addClass('notice-error').find('p').text(`تعذر إكمال الفحص؛ الروابط الحالية محفوظة. ${error(r)}`); finish(); });
        }
        step();
    });
});
