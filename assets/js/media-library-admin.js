jQuery(function ($) {
    'use strict';

    const cfg = window.olamaMediaLibrary;
    const state = { currentUploadRequest: null, logPage: 1, debugUploads: !!cfg.canManage };
    const activeUploads = new Map();

    const esc = (value) => $('<div>').text(value == null ? '' : value).html();
    const statusLabel = (status) => cfg.i18n['status_' + status] || status || cfg.i18n.status_none;
    const badge = (status) => `<span class="olama-status status-${esc(status || 'none')}">${esc(statusLabel(status || 'none'))}</span>`;
    const notify = (message, type = 'info') => {
        const klass = type === 'error' ? 'notice-error' : type === 'success' ? 'notice-success' : 'notice-info';
        $('.olama-media-library-wrap').prepend(`<div class="notice ${klass} is-dismissible"><p>${esc(message)}</p></div>`);
    };

    if (window.wp && wp.heartbeat && typeof wp.heartbeat.interval === 'function') {
        wp.heartbeat.interval(120);
    }

    $('#olama-upload-debug-toggle').prop('checked', state.debugUploads).on('change', function () {
        state.debugUploads = $(this).is(':checked');
        renderAllUploads();
    });

    $('.nav-tab').on('click', function (event) {
        event.preventDefault();
        const tab = $(this).data('tab');
        activateTab(tab);
    });

    $(document).on('click', '.nav-tab-jump', function (event) {
        event.preventDefault();
        activateTab($(this).data('tab'));
    });

    function activateTab(tab) {
        $('.nav-tab').removeClass('nav-tab-active');
        $(`.nav-tab[data-tab="${tab}"]`).addClass('nav-tab-active');
        $('.olama-media-tab').removeClass('active');
        $('#tab-' + tab).addClass('active');
        if (tab === 'logs') {
            loadLogs(1);
        } else if (tab === 'coverage' && !$('#coverage-report').data('loaded')) {
            loadCoverage();
        }
    }

    $('#filter-grade').on('change', function () {
        const gradeId = $(this).val();
        const $subject = $('#filter-subject');
        if (!gradeId) {
            $subject.prop('disabled', true).html(`<option value="">${esc(cfg.i18n.select)}</option>`);
            return;
        }

        $subject.prop('disabled', true).html(`<option value="">${esc(cfg.i18n.loading)}</option>`);
        $.get(cfg.ajaxurl, {
            action: 'olama_get_subjects',
            nonce: cfg.nonce,
            grade_id: gradeId
        }).done(function (response) {
            if (!response.success) {
                notify(response.data || cfg.i18n.error, 'error');
                return;
            }
            let html = `<option value="">${esc(cfg.i18n.select)}</option>`;
            response.data.forEach((subject) => {
                html += `<option value="${esc(subject.id)}">${esc(subject.subject_name)}</option>`;
            });
            $subject.html(html).prop('disabled', false);
        });
    });

    $('#btn-load-curriculum').on('click', loadCurriculum);

    $('#coverage-grade').on('change', function () {
        const gradeId = $(this).val(), $subject = $('#coverage-subject');
        $subject.html(`<option value="">${esc(cfg.i18n.coverage_all_subjects)}</option>`);
        if (!gradeId) { $subject.prop('disabled', true); return; }
        $subject.prop('disabled', true);
        $.get(cfg.ajaxurl, { action: 'olama_get_subjects', nonce: cfg.nonce, grade_id: gradeId }).done(function (response) {
            let html = `<option value="">${esc(cfg.i18n.coverage_all_subjects)}</option>`;
            if (response.success) response.data.forEach((s) => { html += `<option value="${esc(s.id)}">${esc(s.subject_name)}</option>`; });
            $subject.html(html).prop('disabled', false);
        });
    });

    $('#coverage-year').on('change', function () {
        const $semester = $('#coverage-semester').prop('disabled', true).html(`<option>${esc(cfg.i18n.loading)}</option>`);
        $.get(cfg.ajaxurl, { action: 'olama_media_get_semesters', nonce: cfg.nonce, academic_year_id: $(this).val() })
            .done(function (response) {
                let html = '';
                if (response.success) response.data.forEach((s) => { html += `<option value="${esc(s.id)}">${esc(s.semester_name)}</option>`; });
                $semester.html(html).prop('disabled', false);
            });
    });

    $('#btn-load-coverage').on('click', loadCoverage);
    function coveragePercent(done, total) { return total ? Math.round(done / total * 1000) / 10 : 0; }
    function coverageStat(label, done, total) {
        const percent = coveragePercent(done, total);
        return `<div class="olama-coverage-stat"><span>${esc(label)}</span><strong>${percent}%</strong><small>${done} / ${total} ${esc(cfg.i18n.coverage_lessons)}</small><div class="olama-coverage-track"><i style="width:${percent}%"></i></div></div>`;
    }
    function loadCoverage() {
        const semester = $('#coverage-semester').val(), $report = $('#coverage-report');
        if (!semester) { notify(cfg.i18n.coverage_select_semester, 'error'); return; }
        $report.html(`<p>${esc(cfg.i18n.loading)}</p>`); $('#btn-load-coverage').prop('disabled', true);
        $.get(cfg.ajaxurl, { action: 'olama_media_video_coverage', nonce: cfg.nonce, semester_id: semester, grade_id: $('#coverage-grade').val() || '', subject_id: $('#coverage-subject').val() || '' })
            .done(function (response) {
                if (!response.success) { $report.html(`<div class="notice notice-error inline"><p>${esc(response.data || cfg.i18n.error)}</p></div>`); return; }
                renderCoverage(response.data.rows || []); $report.data('loaded', true);
            }).always(() => $('#btn-load-coverage').prop('disabled', false));
    }
    function renderCoverage(rows) {
        const $report = $('#coverage-report');
        if (!rows.length) { $report.html(`<div class="notice notice-warning inline"><p>${esc(cfg.i18n.no_curriculum)}</p></div>`); return; }
        const curricula = {}, grades = {}, subjects = {}; let covered = 0;
        rows.forEach((row) => {
            const yes = Number(row.has_video) === 1, ck = `${row.grade_id}:${row.subject_id}`, sk = row.subject_name;
            covered += yes ? 1 : 0;
            curricula[ck] ||= { label: `${row.grade_name} — ${row.subject_name}`, rows: [], covered: 0 };
            curricula[ck].rows.push(row); curricula[ck].covered += yes ? 1 : 0;
            grades[row.grade_id] ||= { label: row.grade_name, total: 0, covered: 0 };
            subjects[sk] ||= { label: row.subject_name, total: 0, covered: 0 };
            grades[row.grade_id].total++; subjects[sk].total++;
            grades[row.grade_id].covered += yes ? 1 : 0; subjects[sk].covered += yes ? 1 : 0;
        });
        let html = `<div class="olama-coverage-overall">${coverageStat(cfg.i18n.coverage_full_set, covered, rows.length)}</div><h3>${esc(cfg.i18n.coverage_by_grade)}</h3><div class="olama-coverage-stats">`;
        Object.values(grades).forEach((x) => { html += coverageStat(x.label, x.covered, x.total); });
        html += `</div><h3>${esc(cfg.i18n.coverage_by_subject)}</h3><div class="olama-coverage-stats">`;
        Object.values(subjects).forEach((x) => { html += coverageStat(x.label, x.covered, x.total); }); html += '</div>';
        Object.values(curricula).forEach((c) => {
            html += `<div class="olama-coverage-curriculum"><div class="olama-coverage-heading"><h3>${esc(c.label)}</h3>${coverageStat(cfg.i18n.coverage_curriculum, c.covered, c.rows.length)}</div><table class="wp-list-table widefat striped"><thead><tr><th>${esc(cfg.i18n.coverage_unit)}</th><th>#</th><th>${esc(cfg.i18n.coverage_lesson)}</th><th>${esc(cfg.i18n.coverage_video)}</th></tr></thead><tbody>`;
            c.rows.forEach((row) => { const yes = Number(row.has_video) === 1; html += `<tr class="${yes ? 'has-video' : 'missing-video'}"><td>${esc(row.unit_number)}. ${esc(row.unit_name)}</td><td>${esc(row.lesson_number)}</td><td>${esc(row.lesson_title)}</td><td><span class="olama-coverage-status">${yes ? '✓ ' + esc(cfg.i18n.coverage_uploaded) : '✕ ' + esc(cfg.i18n.coverage_missing)}</span></td></tr>`; });
            html += '</tbody></table></div>';
        }); $report.html(html);
    }

    function filters() {
        return {
            academic_year_id: $('#filter-year-id').val(),
            semester_id: $('#filter-semester').val(),
            grade_id: $('#filter-grade').val(),
            subject_id: $('#filter-subject').val()
        };
    }

    function loadCurriculum() {
        const data = filters();
        if (!data.grade_id || !data.subject_id || !data.semester_id) {
            notify(cfg.i18n.select_all, 'error');
            return;
        }

        const $btn = $('#btn-load-curriculum');
        $btn.prop('disabled', true).text(cfg.i18n.loading);
        $.get(cfg.ajaxurl, {
            action: 'academy_load_media_curriculum',
            nonce: cfg.nonce,
            ...data
        }).done(function (response) {
            response.success ? renderCurriculum(response.data) : notify(response.data || cfg.i18n.error, 'error');
        }).always(function () {
            $btn.prop('disabled', false).text(cfg.i18n.load_curriculum);
        });
    }

    function renderCurriculum(units) {
        const $container = $('#curriculum-container');
        if (!units || !units.length) {
            $container.html(`<div class="notice notice-warning inline"><p>${esc(cfg.i18n.no_curriculum)}</p></div>`);
            return;
        }

        let html = '';
        units.forEach((unit) => {
            html += `<div class="olama-media-unit">
                <h2>${esc(unit.unit_number)}. ${esc(unit.unit_name)}</h2>
                <table class="wp-list-table widefat striped">
                    <thead><tr>
                        <th style="width:60px">#</th>
                        <th>${esc('الدرس')}</th>
                        <th>${esc('الحالة')}</th>
                        <th>${esc('الملاحظات')}</th>
                        <th>${esc('تاريخ الرفع')}</th>
                        <th style="width:260px">${esc('الإجراءات')}</th>
                    </tr></thead><tbody>`;

            (unit.lessons || []).forEach((lesson) => {
                const uploadStatus = lesson.upload_status || 'none';
                const previewStatus = lesson.preview_status || 'not_checked';
                const approvalStatus = lesson.approval_status || 'pending';
                const hasVideo = uploadStatus === 'uploaded_to_drive';
                const needsFinalize = lesson.job_status === 'finalize_failed'
                    || (uploadStatus === 'uploading' && lesson.drive_file_id)
                    || (lesson.drive_file_id && !lesson.drive_file_url);
                const downloadUrl = lesson.web_content_link || (lesson.drive_file_id ? `https://drive.google.com/uc?export=download&id=${encodeURIComponent(lesson.drive_file_id)}` : '');
                const processingNote = hasVideo && previewStatus === 'processing' ? `<div class="olama-processing-note">${esc(cfg.i18n.processing_note)}</div>` : '';

                html += `<tr data-asset-id="${esc(lesson.media_record_id || '')}" data-lesson-id="${esc(lesson.id)}">
                    <td>${esc(lesson.lesson_number)}</td>
                    <td>${esc(lesson.lesson_title)}</td>
                    <td>${badge(uploadStatus)} ${badge(previewStatus)} ${badge(approvalStatus)}${processingNote}</td>
                    <td><textarea class="olama-note" rows="2" ${lesson.media_record_id ? '' : 'disabled'}>${esc(lesson.comments || '')}</textarea></td>
                    <td>${lesson.uploaded_at ? esc(lesson.uploaded_at) : '-'}</td>
                    <td>
                        <div class="olama-actions">
                            ${hasVideo && previewStatus === 'ready' && lesson.drive_file_url ? `<button type="button" class="button btn-preview" data-url="${esc(lesson.drive_file_url)}" data-title="${esc(lesson.lesson_title)}">${esc(cfg.i18n.preview)}</button>` : ''}
                            ${downloadUrl ? `<a class="button" target="_blank" href="${esc(downloadUrl)}">${esc(cfg.i18n.download)}</a>` : ''}
                            ${needsFinalize ? `<button type="button" class="button btn-finalize-upload" data-job-uuid="${esc(lesson.job_uuid || '')}">${esc(cfg.i18n.retry_finalize)}</button>` : ''}
                            ${lesson.media_record_id ? `<button type="button" class="button btn-check-status">${esc(cfg.i18n.check_status)}</button>` : ''}
                            ${cfg.canApprove && lesson.media_record_id ? `<button type="button" class="button btn-approval" data-status="approved">${esc(cfg.i18n.approve)}</button><button type="button" class="button btn-approval" data-status="rejected">${esc(cfg.i18n.reject)}</button>` : ''}
                            <button type="button" class="button btn-upload" data-lesson-id="${esc(lesson.id)}" data-unit-id="${esc(unit.id)}" data-lesson-number="${esc(lesson.lesson_number)}" data-lesson-name="${esc(lesson.lesson_title)}" data-unit-name="${esc(unit.unit_name)}" data-record-id="${esc(lesson.media_record_id || '')}">${esc(hasVideo ? cfg.i18n.replace : cfg.i18n.upload)}</button>
                        </div>
                        <div class="olama-progress olama-upload-panel" id="progress-${esc(lesson.id)}" data-upload-panel>
                            <div class="olama-upload-summary" data-upload-summary></div>
                            <div class="olama-progress-track olama-upload-progress-wrap" data-upload-progress-wrap>
                                <div class="olama-progress-bar olama-upload-progress-bar" data-upload-progress-bar></div>
                            </div>
                            <div class="olama-progress-text olama-upload-details" data-upload-details></div>
                            <div class="olama-upload-debug" data-upload-debug></div>
                        </div>
                    </td>
                </tr>`;
            });

            html += '</tbody></table></div>';
        });

        $container.html(html);
        rebindUploadRows();
        renderAllUploads();
    }

    $(document).on('click', '.btn-upload', function () {
        state.currentUploadRequest = {
            lesson: $(this).data(),
            button: this,
            row: $(this).closest('[data-lesson-id]')[0]
        };
        $('#media-video-input').trigger('click');
    });

    $('#media-video-input').on('change', function () {
        const file = this.files[0];
        this.value = '';
        const request = state.currentUploadRequest;
        state.currentUploadRequest = null;
        if (!file || !request || !request.lesson) {
            return;
        }
        const ext = file.name.split('.').pop().toLowerCase();
        if (ext !== 'mp4' || file.type && file.type !== 'video/mp4') {
            notify(cfg.i18n.invalid_file, 'error');
            return;
        }
        if (file.size > cfg.maxFileSize) {
            notify(cfg.i18n.file_too_large.replace('%s', cfg.maxFileSizeHuman), 'error');
            return;
        }
        refreshUploadNonce().done(function (response) {
            if (!response.success || !response.data || !response.data.drive_authenticated) {
                notify((response.data && response.data.message_ar) || (response.data && response.data.auth_warning) || cfg.i18n.session_or_permission_expired, 'error');
                return;
            }
            const uploadState = createUploadState(file, request.lesson, request.row, request.button);
            if (shouldUseDirectUpload(file)) {
                uploadFileDirect(uploadState);
            } else {
                uploadFile(uploadState);
            }
        }).fail(function () {
            notify(cfg.i18n.session_or_permission_expired, 'error');
        });
    });

    function createUploadState(file, lesson, row, button) {
        const uploadId = `${lesson.lessonId}-${Date.now()}-${Math.random().toString(36).slice(2)}`;
        const $row = row ? $(row) : $(`[data-lesson-id="${lesson.lessonId}"]`);
        const $progress = $row.find('.olama-progress');
        const uploadState = {
            upload_id: uploadId,
            lesson_id: lesson.lessonId,
            row: $row,
            progress: $progress,
            statusElement: $progress.find('.olama-progress-text'),
            bar: $progress.find('.olama-progress-bar'),
            uploadButton: button ? $(button) : $row.find('.btn-upload'),
            fallbackButton: null,
            transport_mode: shouldUseDirectUpload(file) ? 'direct_google' : 'wordpress_streamed',
            asset_id: 0,
            job_uuid: '',
            drive_file_id: '',
            session: null,
            file,
            lesson,
            total_size: file.size,
            uploaded_bytes: 0,
            current_chunk_index: 0,
            current_stage: 'queued',
            retry_count: 0,
            xhr: null,
            status: 'queued',
            payload: null,
            percent: 0,
            started_at: Date.now(),
            completed_at: null,
            last_message: cfg.i18n.uploading,
            error_code: '',
            xhr_status: '',
            drive_http_status: '',
            last_probe_status: '',
            next_start: '',
            events: []
        };
        activeUploads.set(uploadId, uploadState);
        uploadState.uploadButton.prop('disabled', true);
        appendUploadEvent(uploadState, 'queued', 'تمت إضافة الرفع إلى قائمة الانتظار');
        renderUploadState(uploadId);
        return uploadState;
    }

    function setUploadState(uploadId, patch = {}, eventMessage = '') {
        const uploadState = activeUploads.get(uploadId);
        if (!uploadState) {
            return null;
        }
        Object.assign(uploadState, patch);
        if (eventMessage) {
            appendUploadEvent(uploadState, patch.current_stage || patch.status || 'update', eventMessage);
        }
        renderUploadState(uploadId);
        return uploadState;
    }

    function appendUploadEvent(uploadState, type, message) {
        uploadState.events = uploadState.events || [];
        uploadState.events.push({
            type,
            message,
            at: new Date().toLocaleTimeString()
        });
        if (uploadState.events.length > 30) {
            uploadState.events = uploadState.events.slice(-30);
        }
    }

    function rebindUploadRows() {
        activeUploads.forEach((uploadState) => {
            const $row = $(`[data-lesson-id="${uploadState.lesson_id}"]`);
            if ($row.length) {
                uploadState.row = $row;
                uploadState.progress = $row.find('[data-upload-panel]');
                uploadState.statusElement = $row.find('[data-upload-details]');
                uploadState.bar = $row.find('[data-upload-progress-bar]');
                uploadState.uploadButton = $row.find('.btn-upload');
            } else {
                uploadState.row = $();
                appendUploadEvent(uploadState, 'row_missing', 'تم فقدان صف الدرس أثناء الرفع');
            }
        });
    }

    function renderAllUploads() {
        activeUploads.forEach((_, uploadId) => renderUploadState(uploadId));
        renderUploadMonitor();
    }

    function renderUploadState(uploadId) {
        const uploadState = activeUploads.get(uploadId);
        if (!uploadState) {
            renderUploadMonitor();
            return;
        }

        if (!uploadState.progress || !uploadState.progress.length) {
            rebindUploadRows();
        }

        const panel = uploadState.progress;
        if (panel && panel.length) {
            const percent = Math.max(0, Math.min(100, Math.round(uploadState.percent || 0)));
            panel
                .removeClass('is-active is-completed is-failed is-probing is-finalizing')
                .addClass(uploadPanelClass(uploadState.status));
            panel.find('[data-upload-summary]').text(uploadSummary(uploadState));
            panel.find('[data-upload-progress-bar]').css('width', percent + '%');
            panel.find('[data-upload-details]').html(uploadDetails(uploadState));
            panel.find('[data-upload-debug]').html(state.debugUploads ? uploadDebug(uploadState) : '');
        }

        renderUploadMonitor();
    }

    function uploadPanelClass(status) {
        if (status === 'completed') {
            return 'is-completed';
        }
        if (status === 'failed') {
            return 'is-failed';
        }
        if (status === 'probing') {
            return 'is-probing is-active';
        }
        if (status === 'finalizing') {
            return 'is-finalizing is-active';
        }
        return status && status !== 'queued' ? 'is-active' : '';
    }

    function uploadSummary(uploadState) {
        if (uploadState.status === 'completed') {
            return 'تم الرفع بنجاح. المعاينة قيد المعالجة.';
        }
        if (uploadState.status === 'failed') {
            return 'فشل الرفع لهذا الدرس.';
        }
        if (uploadState.status === 'probing') {
            return cfg.i18n.direct_probe_checking || 'يتم التحقق من حالة الرفع...';
        }
        if (uploadState.status === 'finalizing') {
            return cfg.i18n.direct_completed_finalizing || cfg.i18n.finalizing_upload;
        }
        return uploadState.last_message || cfg.i18n.uploading;
    }

    function uploadDetails(uploadState) {
        const uploaded = formatBytes(uploadState.uploaded_bytes || 0);
        const total = formatBytes(uploadState.total_size || 0);
        const duration = formatDuration(((uploadState.completed_at || Date.now()) - uploadState.started_at) / 1000);
        const parts = [
            `<strong>${esc(uploadState.lesson.lessonName || '')}</strong>`,
            `${esc(uploadState.transport_mode || '')} | ${esc(uploadState.status || '')} | ${Math.round(uploadState.percent || 0)}%`,
            `${uploaded} / ${total} | ${esc(uploadState.current_stage || '')}`,
            uploadState.job_uuid ? `Job: ${esc(uploadState.job_uuid)}` : '',
            uploadState.status === 'completed' ? `المدة: ${esc(duration)}` : ''
        ].filter(Boolean);
        return parts.map((line) => `<div>${line}</div>`).join('');
    }

    function uploadDebug(uploadState) {
        const debugLines = [
            `upload_id=${uploadState.upload_id}`,
            `job_uuid=${uploadState.job_uuid || ''}`,
            `asset_id=${uploadState.asset_id || ''}`,
            `drive_file_id=${uploadState.drive_file_id || ''}`,
            `transport=${uploadState.transport_mode || ''}`,
            `chunk_index=${uploadState.current_chunk_index ?? ''}`,
            `xhr_status=${uploadState.xhr_status || ''}`,
            `drive_http_status=${uploadState.drive_http_status || ''}`,
            `stage=${uploadState.current_stage || ''}`,
            `retry_count=${uploadState.retry_count || 0}`,
            `last_probe_status=${uploadState.last_probe_status || ''}`,
            `next_start=${uploadState.next_start || ''}`,
            `uploaded_bytes=${uploadState.uploaded_bytes || 0}`,
            `total_bytes=${uploadState.total_size || 0}`,
            uploadState.error_code ? `error_code=${uploadState.error_code}` : ''
        ].filter(Boolean);
        const eventLines = (uploadState.events || []).slice(-8).map((event) => `${event.at} - ${event.message}`);
        const actions = uploadState.status === 'failed'
            ? `<div class="olama-upload-debug-actions"><button type="button" class="button button-small btn-copy-upload-diagnostics" data-upload-id="${esc(uploadState.upload_id)}">نسخ تفاصيل الخطأ</button>${uploadState.job_uuid ? `<button type="button" class="button button-small btn-show-upload-logs" data-job-uuid="${esc(uploadState.job_uuid)}">عرض السجلات</button>` : ''}</div>`
            : (uploadState.job_uuid ? `<div class="olama-upload-debug-actions"><button type="button" class="button button-small btn-show-upload-logs" data-job-uuid="${esc(uploadState.job_uuid)}">عرض السجلات</button></div>` : '');
        return `<pre>${esc(debugLines.concat(eventLines).join('\n'))}</pre>${actions}`;
    }

    function renderUploadMonitor() {
        const $list = $('#olama-upload-monitor-list');
        if (!$list.length) {
            return;
        }
        const uploads = Array.from(activeUploads.values());
        if (!uploads.length) {
            $list.html('<p class="description">لا توجد عمليات رفع نشطة حالياً.</p>');
            return;
        }
        $list.html(uploads.map((uploadState) => {
            const debug = state.debugUploads && uploadState.job_uuid ? `<div class="olama-upload-monitor-debug">job_uuid=${esc(uploadState.job_uuid)} | upload_id=${esc(uploadState.upload_id)}</div>` : '';
            return `<div class="olama-upload-monitor-item status-${esc(uploadState.status || 'queued')}">
                <strong>${esc(uploadState.lesson.lessonName || '')}</strong>
                <span>${esc(uploadState.transport_mode || '')}</span>
                <span>${esc(uploadState.status || '')}</span>
                <span>${Math.round(uploadState.percent || 0)}%</span>
                <span>${formatBytes(uploadState.uploaded_bytes || 0)} / ${formatBytes(uploadState.total_size || 0)}</span>
                <span>${esc(uploadState.current_stage || '')}</span>
                <span>${esc(uploadState.last_message || '')}</span>
                ${debug}
            </div>`;
        }).join(''));
    }

    function formatBytes(bytes) {
        const value = Number(bytes || 0);
        if (value >= 1024 * 1024) {
            return (value / 1024 / 1024).toFixed(1) + 'MB';
        }
        if (value >= 1024) {
            return (value / 1024).toFixed(1) + 'KB';
        }
        return value + 'B';
    }

    function formatDuration(seconds) {
        const total = Math.max(0, Math.round(seconds || 0));
        const minutes = Math.floor(total / 60);
        const remaining = total % 60;
        return `${String(minutes).padStart(2, '0')}:${String(remaining).padStart(2, '0')}`;
    }

    function diagnosticText(uploadState) {
        const events = (uploadState.events || []).slice(-5).map((event) => `${event.at} ${event.message}`).join('\n');
        return [
            `lesson=${uploadState.lesson.lessonName || ''}`,
            `upload_id=${uploadState.upload_id}`,
            `job_uuid=${uploadState.job_uuid || ''}`,
            `asset_id=${uploadState.asset_id || ''}`,
            `transport=${uploadState.transport_mode || ''}`,
            `error_code=${uploadState.error_code || ''}`,
            `stage=${uploadState.current_stage || ''}`,
            `xhr_status=${uploadState.xhr_status || ''}`,
            `drive_http_status=${uploadState.drive_http_status || ''}`,
            `chunk_index=${uploadState.current_chunk_index ?? ''}`,
            `uploaded=${formatBytes(uploadState.uploaded_bytes || 0)}/${formatBytes(uploadState.total_size || 0)}`,
            'events:',
            events
        ].join('\n');
    }

    $(document).on('click', '.btn-copy-upload-diagnostics', function () {
        const uploadState = activeUploads.get($(this).data('upload-id'));
        if (!uploadState) {
            return;
        }
        const text = diagnosticText(uploadState);
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(() => notify('تم نسخ تفاصيل الخطأ', 'success'));
        } else {
            uploadState.progress.find('[data-upload-debug]').append(`<textarea class="large-text" rows="8">${esc(text)}</textarea>`);
        }
    });

    $(document).on('click', '.btn-show-upload-logs', function () {
        const jobUuid = $(this).data('job-uuid') || '';
        $('#log-filter-job-uuid').val(jobUuid);
        activateTab('logs');
        loadLogs(1);
    });

    function finishUpload(uploadState, status, refresh = true) {
        if (!uploadState) {
            return;
        }
        uploadState.status = status;
        uploadState.current_stage = status;
        if (status === 'completed' || status === 'failed' || status === 'cancelled') {
            uploadState.completed_at = Date.now();
        }
        uploadState.uploadButton.prop('disabled', false);
        if (status === 'cancelled') {
            activeUploads.delete(uploadState.upload_id);
        }
        if (refresh && !hasActiveUploadWork()) {
            loadCurriculum();
        } else {
            renderUploadState(uploadState.upload_id);
        }
    }

    function refreshCurriculumWhenIdle() {
        if (!hasActiveUploadWork()) {
            loadCurriculum();
        }
    }

    function hasActiveUploadWork() {
        return Array.from(activeUploads.values()).some((upload) => ['queued', 'uploading', 'probing', 'retrying', 'finalizing'].includes(upload.status));
    }

    function shouldUseDirectUpload(file) {
        if (cfg.uploadTransportMode === 'direct_google') {
            return true;
        }
        if (cfg.uploadTransportMode === 'auto') {
            return file.size >= (cfg.directUploadThresholdBytes || (20 * 1024 * 1024));
        }
        return false;
    }

    function lessonUploadPayload(file, lesson) {
        const f = filters();
        return {
            file_uuid: Date.now() + '-' + Math.random().toString(36).slice(2),
            file_name: file.name,
            filename: file.name,
            file_size: file.size,
            total_size: file.size,
            mime_type: file.type || 'video/mp4',
            id: lesson.recordId || '',
            lesson_id: lesson.lessonId,
            unit_id: lesson.unitId,
            lesson_name: lesson.lessonName,
            lesson_number: lesson.lessonNumber,
            unit_name: lesson.unitName,
            academic_year_id: f.academic_year_id,
            semester_id: f.semester_id,
            grade_id: f.grade_id,
            subject_id: f.subject_id
        };
    }

    function refreshUploadNonce() {
        return $.post(cfg.ajaxurl, {
            action: 'olama_media_refresh_upload_nonce'
        }).done(function (response) {
            if (response.success && response.data && response.data.nonce) {
                cfg.nonce = response.data.nonce;
                cfg.driveAuth = {
                    drive_authenticated: !!response.data.drive_authenticated,
                    has_refresh_token: !!response.data.has_refresh_token,
                    auth_warning: response.data.auth_warning || ''
                };
            }
        });
    }

    function uploadErrorMessage(data) {
        if (typeof data === 'object' && data !== null) {
            let message = data.message_ar || data.message || cfg.i18n.error;
            if (cfg.canManage) {
                const details = [
                    data.error_code ? `error_code=${data.error_code}` : '',
                    data.stage ? `stage=${data.stage}` : '',
                    data.drive_http_status ? `drive_http_status=${data.drive_http_status}` : '',
                    data.job_uuid ? `job_uuid=${data.job_uuid}` : ''
                ].filter(Boolean).join(' | ');
                if (details) {
                    message += ` (${details})`;
                }
            }
            return message;
        }
        return data || cfg.i18n.error;
    }

    function uploadFileDirect(uploadState) {
        const file = uploadState.file;
        const lesson = uploadState.lesson;
        const payload = lessonUploadPayload(file, lesson);
        const $progress = uploadState.progress;
        const $bar = uploadState.bar;
        const $text = uploadState.statusElement;

        uploadState.payload = payload;
        uploadState.transport_mode = 'direct_google';
        setUploadState(uploadState.upload_id, {
            status: 'queued',
            current_stage: 'direct_session_create',
            last_message: cfg.i18n.direct_session_creating,
            percent: 0,
            uploaded_bytes: 0
        }, 'جاري إنشاء جلسة الرفع المباشر');
        $progress.show().find('.olama-direct-actions').remove();
        $bar.css('width', '0%').css('background', '#2271b1');
        $text.text(`${cfg.i18n.transport_direct} - ${cfg.i18n.direct_session_creating}`);
        logDirectEvent('direct_upload_selected', payload, 0, 0, '', 'Direct upload selected.');

        refreshUploadNonce().done(function (nonceResponse) {
            if (!nonceResponse.success || !nonceResponse.data || !nonceResponse.data.drive_authenticated) {
                showDirectFallback(uploadState, uploadErrorMessage(nonceResponse.data) || cfg.i18n.session_or_permission_expired);
                return;
            }

            $.post(cfg.ajaxurl, {
                action: 'olama_media_start_direct_upload',
                nonce: cfg.nonce,
                ...payload
            }).done(function (response) {
                if (!response.success || !response.data || !response.data.upload_url) {
                    showDirectFallback(uploadState, uploadErrorMessage(response.data) || cfg.i18n.direct_browser_failed);
                    return;
                }

                const session = response.data;
                payload.job_uuid = session.job_uuid;
                payload.asset_id = session.asset_id;
                uploadState.payload = payload;
                uploadState.job_uuid = session.job_uuid || '';
                uploadState.asset_id = session.asset_id || 0;
                uploadState.drive_file_id = session.drive_file_id || '';
                uploadState.session = session;
                setUploadState(uploadState.upload_id, {
                    status: 'uploading',
                    current_stage: 'direct_google_put',
                    last_message: cfg.i18n.direct_uploading,
                    job_uuid: uploadState.job_uuid,
                    asset_id: uploadState.asset_id,
                    drive_file_id: uploadState.drive_file_id
                }, 'بدأ الرفع المباشر');
                $text.text(`${cfg.i18n.transport_direct} - ${cfg.i18n.direct_uploading}`);
                logDirectEvent('direct_upload_started', payload, file.size, 0, '', 'Direct browser upload started.');
                sendDirectToGoogle(uploadState);
            }).fail(function () {
                showDirectFallback(uploadState, cfg.i18n.direct_browser_failed);
            });
        }).fail(function () {
            showDirectFallback(uploadState, cfg.i18n.session_or_permission_expired);
        });
    }

    function sendDirectToGoogle(uploadState) {
        const file = uploadState.file;
        const session = uploadState.session;
        const payload = uploadState.payload;
        const $progress = uploadState.progress;
        const $bar = uploadState.bar;
        const $text = uploadState.statusElement;
        const chunkSize = normalizeDirectChunkSize(cfg.directUploadChunkSizeBytes || (16 * 1024 * 1024));
        const checkpoints = { 25: false, 50: false, 75: false, 100: false };
        let nextStart = 0;

        const uploadChunk = (retryAttempt = 0, probeRetryAttempt = 0) => {
            const start = nextStart;
            const end = Math.min(file.size - 1, start + chunkSize - 1);
            const chunkIndex = Math.floor(start / chunkSize);
            const blob = file.slice(start, end + 1);
            const xhr = new XMLHttpRequest();
            uploadState.xhr = xhr;
            setUploadState(uploadState.upload_id, {
                current_chunk_index: chunkIndex,
                current_stage: 'direct_google_put',
                status: 'uploading',
                last_message: `جاري رفع الجزء ${chunkIndex + 1}`
            }, `بدأ رفع الجزء ${chunkIndex + 1}`);

            xhr.upload.onprogress = function (event) {
                if (!event.lengthComputable) {
                    return;
                }
                const uploaded = start + event.loaded;
                const percent = Math.min(99, Math.round((uploaded / file.size) * 100));
                setUploadState(uploadState.upload_id, {
                    uploaded_bytes: uploaded,
                    percent,
                    current_chunk_index: chunkIndex,
                    current_stage: 'direct_google_put',
                    last_message: `${cfg.i18n.direct_uploading} ${percent}%`
                });
                $bar.css('width', percent + '%');
                $text.text(`${cfg.i18n.transport_direct} - ${cfg.i18n.direct_uploading} ${percent}%`);
                [25, 50, 75].forEach(function (point) {
                    if (percent >= point && !checkpoints[point]) {
                        checkpoints[point] = true;
                        logDirectEvent('direct_upload_progress_checkpoint', payload, file.size, uploaded, '', `Direct upload reached ${point}%.`, point, {
                            loaded_bytes: uploaded,
                            total_bytes: file.size,
                            chunk_start: start,
                            chunk_end: end,
                            chunk_index: chunkIndex,
                            direct_chunk_size: chunkSize,
                            stage: 'direct_google_put'
                        });
                    }
                });
            };

            xhr.onload = function () {
                const diagnostics = directXhrDiagnostics(xhr, file, start, end, chunkIndex, chunkSize);
                if (xhr.status === 308) {
                    const acceptedEnd = parseAcceptedRangeEnd(xhr);
                    if (acceptedEnd >= start) {
                        nextStart = Math.min(file.size, acceptedEnd + 1);
                    } else {
                        logDirectEvent('direct_missing_range_header', payload, file.size, start, 'direct_missing_range_header', 'Google returned 308 without a readable Range header.', 0, diagnostics);
                        probeDirectUpload(uploadState, start, end, chunkIndex, chunkSize, retryAttempt, probeRetryAttempt);
                        return;
                    }
                    if (nextStart < file.size) {
                        setUploadState(uploadState.upload_id, {
                            uploaded_bytes: nextStart,
                            percent: Math.min(99, Math.round((nextStart / file.size) * 100)),
                            last_message: `تم رفع الجزء ${chunkIndex + 1}`
                        }, `تم رفع الجزء ${chunkIndex + 1}`);
                        uploadChunk();
                        return;
                    }
                    probeDirectUpload(uploadState, start, end, chunkIndex, chunkSize, retryAttempt, probeRetryAttempt, diagnostics);
                    return;
                }

                if (xhr.status === 200 || xhr.status === 201) {
                    $bar.css('width', '100%');
                    checkpoints[100] = true;
                    logDirectEvent('direct_upload_completed_browser', payload, file.size, file.size, '', 'Direct browser upload completed.', 100, diagnostics);
                    $text.text(cfg.i18n.direct_completed_finalizing);
                    setUploadState(uploadState.upload_id, {
                        status: 'finalizing',
                        current_stage: 'direct_finalize',
                        uploaded_bytes: file.size,
                        percent: 100,
                        last_message: cfg.i18n.direct_completed_finalizing
                    }, 'جاري إنهاء الرفع');
                    refreshUploadNonce().always(function () {
                        finalizeDirectUpload(uploadState, '', 0);
                    });
                    return;
                }

                if (xhr.status >= 500 && retryAttempt < 3) {
                    setUploadState(uploadState.upload_id, {
                        status: 'retrying',
                        retry_count: retryAttempt + 1,
                        last_message: 'إعادة محاولة رفع الجزء'
                    }, `إعادة محاولة الجزء ${chunkIndex + 1}`);
                    window.setTimeout(() => uploadChunk(retryAttempt + 1), [2000, 5000, 10000][retryAttempt]);
                    return;
                }

                if (xhr.status === 0) {
                    probeDirectUpload(uploadState, start, end, chunkIndex, chunkSize, retryAttempt, probeRetryAttempt, diagnostics);
                    return;
                }

                const errorCode = xhr.status === 0 ? 'direct_cors_or_network_failure' : (xhr.status >= 400 && xhr.status < 500 ? 'direct_session_restart_required' : 'direct_google_http_error');
                const message = xhr.status >= 400 && xhr.status < 500 ? cfg.i18n.direct_session_restart_required : `${cfg.i18n.direct_browser_failed} (${xhr.status})`;
                showDirectFallback(uploadState, message, errorCode, diagnostics, xhr.status >= 400 && xhr.status < 500 ? 'direct_upload_failed' : 'direct_upload_browser_error');
            };

            xhr.onerror = function () {
                probeDirectUpload(uploadState, start, end, chunkIndex, chunkSize, retryAttempt, probeRetryAttempt, {
                    loaded_bytes: start,
                    total_bytes: file.size,
                    chunk_start: start,
                    chunk_end: end,
                    chunk_index: chunkIndex,
                    direct_chunk_size: chunkSize,
                    xhr_status: 0,
                    xhr_status_text: '',
                    stage: 'direct_google_put',
                    message_en: 'Browser lost the direct Google upload response or the network request failed.'
                });
            };
            xhr.ontimeout = function () {
                const diagnostics = directXhrDiagnostics(xhr, file, start, end, chunkIndex, chunkSize);
                diagnostics.message_en = 'Direct upload chunk timed out; probing resumable session state.';
                probeDirectUpload(uploadState, start, end, chunkIndex, chunkSize, retryAttempt, probeRetryAttempt, diagnostics, 'direct_chunk_timeout');
            };
            xhr.onabort = function () {
                showDirectFallback(uploadState, cfg.i18n.direct_browser_failed, 'direct_browser_aborted', directXhrDiagnostics(xhr, file, start, end, chunkIndex, chunkSize));
            };

            xhr.open('PUT', session.upload_url, true);
            xhr.timeout = cfg.directUploadChunkTimeoutMs || 180000;
            xhr.setRequestHeader('Content-Type', session.mime_type || 'video/mp4');
            xhr.setRequestHeader('Content-Range', `bytes ${start}-${end}/${file.size}`);
            xhr.send(blob);
        };

        const probeDirectUpload = (currentUpload, start, end, chunkIndex, directChunkSize, retryAttempt, probeRetryAttempt, diagnostics = {}, errorCode = 'direct_browser_network_or_response_failure') => {
            setUploadState(currentUpload.upload_id, {
                status: 'probing',
                current_stage: 'direct_upload_probe',
                error_code: errorCode,
                xhr_status: diagnostics.xhr_status || 0,
                uploaded_bytes: diagnostics.loaded_bytes || start,
                last_message: cfg.i18n.direct_probe_checking || cfg.i18n.direct_browser_failed
            }, 'حدث انقطاع مؤقت، يتم فحص حالة Google');
            currentUpload.statusElement.text(cfg.i18n.direct_probe_checking || cfg.i18n.direct_browser_failed);
            logDirectEvent('direct_upload_browser_error', currentUpload.payload, currentUpload.file.size, diagnostics.loaded_bytes || start, errorCode, diagnostics.message_en || 'Browser upload response failed; probing Google resumable session.', 0, diagnostics);
            $.post(cfg.ajaxurl, {
                action: 'olama_media_probe_direct_upload',
                nonce: cfg.nonce,
                job_uuid: currentUpload.job_uuid || '',
                asset_id: currentUpload.asset_id || 0,
                total_size: currentUpload.file.size
            }).done(function (response) {
                const probeData = response.data || {};
                const probeDiagnostics = {
                    ...diagnostics,
                    last_probe_status: probeData.google_http_status || probeData.drive_http_status || 0,
                    next_start: probeData.next_start || 0,
                    stage: 'direct_google_put'
                };
                setUploadState(currentUpload.upload_id, {
                    last_probe_status: probeDiagnostics.last_probe_status,
                    next_start: probeDiagnostics.next_start
                }, `Google استلم حتى ${formatBytes(probeDiagnostics.next_start || 0)}`);

                if (response.success && probeData.complete) {
                    currentUpload.bar.css('width', '100%');
                    logDirectEvent('direct_upload_completed_browser', currentUpload.payload, currentUpload.file.size, currentUpload.file.size, '', 'Direct upload completed according to Google probe.', 100, probeDiagnostics);
                    currentUpload.statusElement.text(cfg.i18n.direct_completed_finalizing);
                    setUploadState(currentUpload.upload_id, {
                        status: 'finalizing',
                        current_stage: 'direct_finalize',
                        uploaded_bytes: currentUpload.file.size,
                        percent: 100,
                        last_message: cfg.i18n.direct_completed_finalizing
                    }, 'جاري إنهاء الرفع');
                    refreshUploadNonce().always(function () {
                        finalizeDirectUpload(currentUpload, '', 0);
                    });
                    return;
                }

                if (response.success && probeData.next_start > start) {
                    nextStart = Math.min(currentUpload.file.size, probeData.next_start);
                    setUploadState(currentUpload.upload_id, {
                        status: 'uploading',
                        current_stage: 'direct_google_put',
                        uploaded_bytes: nextStart,
                        percent: Math.min(99, Math.round((nextStart / currentUpload.file.size) * 100)),
                        last_message: `استئناف الرفع من ${formatBytes(nextStart)}`
                    }, `Google استلم حتى ${formatBytes(nextStart)}`);
                    uploadChunk();
                    return;
                }

                if (response.success && probeData.next_start === start && retryAttempt < 3) {
                    setUploadState(currentUpload.upload_id, {
                        status: 'retrying',
                        retry_count: retryAttempt + 1,
                        last_message: 'إعادة محاولة نفس الجزء بعد الفحص'
                    }, 'إعادة محاولة نفس الجزء');
                    window.setTimeout(() => uploadChunk(retryAttempt + 1, probeRetryAttempt + 1), [2000, 5000, 10000][retryAttempt]);
                    return;
                }

                const probeError = probeData.error_code || errorCode;
                const message = probeData.message_ar || cfg.i18n.direct_browser_failed;
                showDirectFallback(currentUpload, message, probeError, probeDiagnostics, probeData.retryable ? 'direct_upload_browser_error' : 'direct_upload_failed');
            }).fail(function () {
                if (probeRetryAttempt < 2) {
                    window.setTimeout(() => probeDirectUpload(currentUpload, start, end, chunkIndex, directChunkSize, retryAttempt, probeRetryAttempt + 1, diagnostics, errorCode), [2000, 5000, 10000][probeRetryAttempt]);
                    return;
                }
                showDirectFallback(currentUpload, cfg.i18n.direct_browser_failed, errorCode, diagnostics);
            });
        };

        uploadChunk();
    }

    function normalizeDirectChunkSize(bytes) {
        const unit = 256 * 1024;
        const value = Math.max(unit, parseInt(bytes, 10) || (16 * 1024 * 1024));
        return Math.max(unit, Math.floor(value / unit) * unit);
    }

    function directXhrDiagnostics(xhr, file, start, end, chunkIndex, chunkSize) {
        let responseHeaders = '';
        let responseText = '';
        try {
            responseHeaders = xhr.getAllResponseHeaders() || '';
        } catch (error) {
            responseHeaders = '';
        }
        try {
            responseText = xhr.responseText || '';
        } catch (error) {
            responseText = '';
        }
        return {
            xhr_status: xhr.status || 0,
            xhr_status_text: xhr.statusText || '',
            response_text_preview: responseText.slice(0, 500),
            response_headers_preview: responseHeaders.slice(0, 500),
            loaded_bytes: Math.min(file.size, end + 1),
            total_bytes: file.size,
            chunk_start: start,
            chunk_end: end,
            chunk_index: chunkIndex,
            direct_chunk_size: chunkSize,
            stage: 'direct_google_put'
        };
    }

    function parseAcceptedRangeEnd(xhr) {
        let range = '';
        try {
            range = xhr.getResponseHeader('Range') || xhr.getResponseHeader('range') || '';
        } catch (error) {
            range = '';
        }
        const match = range.match(/bytes=0-(\d+)/i);
        return match ? parseInt(match[1], 10) : -1;
    }

    function finalizeDirectUpload(uploadState, driveFileId, retryAttempt = 0) {
        $.post(cfg.ajaxurl, {
            action: 'olama_media_finalize_direct_upload',
            nonce: cfg.nonce,
            asset_id: uploadState.asset_id,
            job_uuid: uploadState.job_uuid || '',
            drive_file_id: driveFileId || ''
        }).done(function (response) {
            if (response.success) {
                uploadState.bar.css('width', '100%').css('background', '#2271b1');
                setUploadState(uploadState.upload_id, {
                    status: 'completed',
                    current_stage: 'completed',
                    percent: 100,
                    uploaded_bytes: uploadState.total_size,
                    last_message: cfg.i18n.direct_success,
                    completed_at: Date.now()
                }, 'تم الرفع بنجاح');
                uploadState.statusElement.text(cfg.i18n.direct_success);
                notify(cfg.i18n.direct_success, 'success');
                finishUpload(uploadState, 'completed');
                return;
            }
            showDirectFallback(uploadState, uploadErrorMessage(response.data) || cfg.i18n.finalize_failed, 'direct_finalize_failed');
            finishUpload(uploadState, 'failed', false);
        }).fail(function () {
            showDirectFallback(uploadState, cfg.i18n.finalize_failed, 'direct_finalize_transport_failed');
            finishUpload(uploadState, 'failed', false);
        });
    }

    function showDirectFallback(uploadState, message, errorCode = 'direct_browser_error', diagnostics = {}, eventType = 'direct_upload_browser_error') {
        const $progress = uploadState.progress;
        setUploadState(uploadState.upload_id, {
            status: 'failed',
            current_stage: diagnostics.stage || 'failed',
            error_code: errorCode,
            xhr_status: diagnostics.xhr_status || '',
            drive_http_status: diagnostics.drive_http_status || diagnostics.drive_http_status === 0 ? diagnostics.drive_http_status : '',
            current_chunk_index: Number.isInteger(diagnostics.chunk_index) ? diagnostics.chunk_index : uploadState.current_chunk_index,
            uploaded_bytes: diagnostics.loaded_bytes || uploadState.uploaded_bytes,
            last_probe_status: diagnostics.last_probe_status || uploadState.last_probe_status,
            next_start: diagnostics.next_start || uploadState.next_start,
            last_message: message,
            completed_at: Date.now()
        }, message);
        uploadState.uploadButton.prop('disabled', false);
        uploadState.bar.css('width', '100%').css('background', '#dba617');
        let adminDetails = '';
        if (cfg.canManage) {
            const uploadedMb = diagnostics.loaded_bytes && diagnostics.total_bytes
                ? `uploaded=${(diagnostics.loaded_bytes / 1024 / 1024).toFixed(1)}MB/${(diagnostics.total_bytes / 1024 / 1024).toFixed(1)}MB`
                : '';
            adminDetails = [
                errorCode ? `error_code=${errorCode}` : '',
                diagnostics.xhr_status || diagnostics.xhr_status === 0 ? `xhr_status=${diagnostics.xhr_status}` : '',
                diagnostics.stage ? `stage=${diagnostics.stage}` : '',
                Number.isInteger(diagnostics.chunk_index) ? `chunk_index=${diagnostics.chunk_index}` : '',
                diagnostics.last_probe_status || diagnostics.last_probe_status === 0 ? `last_probe_status=${diagnostics.last_probe_status}` : '',
                diagnostics.next_start || diagnostics.next_start === 0 ? `next_start=${diagnostics.next_start}` : '',
                uploadedMb
            ].filter(Boolean).join(' | ');
        }
        const fullMessage = adminDetails ? `${message} (${adminDetails})` : message;
        uploadState.statusElement.text(`${fullMessage} ${cfg.i18n.direct_fallback_available}`);
        notify(fullMessage, 'error');

        logDirectEvent(eventType, uploadState.payload, uploadState.file.size, diagnostics.loaded_bytes || 0, errorCode, diagnostics.message_en || message, 0, diagnostics);

        $progress.find('.olama-direct-actions').remove();
        $progress.append(`<div class="olama-direct-actions">
            <button type="button" class="button button-small btn-retry-direct-upload" data-upload-id="${esc(uploadState.upload_id)}">${esc(cfg.i18n.retry_direct_upload)}</button>
            <button type="button" class="button button-small btn-use-wordpress-fallback" data-upload-id="${esc(uploadState.upload_id)}">${esc(cfg.i18n.use_wordpress_fallback)}</button>
        </div>`);
    }

    $(document).on('click', '.btn-retry-direct-upload', function () {
        const uploadState = activeUploads.get($(this).data('upload-id'));
        if (!uploadState) {
            return;
        }
        uploadFileDirect(uploadState);
    });

    $(document).on('click', '.btn-use-wordpress-fallback', function () {
        const uploadState = activeUploads.get($(this).data('upload-id'));
        if (!uploadState) {
            return;
        }
        logDirectEvent('direct_upload_fallback_selected', uploadState.payload, uploadState.file.size, 0, '', 'User selected WordPress streamed fallback.');
        uploadState.progress.find('.olama-direct-actions').remove();
        uploadFile(uploadState, true);
    });

    function logDirectEvent(eventType, payload, fileSize, uploadedBytes, errorCode, messageEn, percent = 0, diagnostics = {}) {
        if (!payload) {
            return;
        }
        $.post(cfg.ajaxurl, {
            action: 'olama_media_log_direct_upload_event',
            nonce: cfg.nonce,
            event_type: eventType,
            job_uuid: payload.job_uuid || '',
            asset_id: payload.asset_id || 0,
            file_size: fileSize || payload.file_size || 0,
            uploaded_bytes: uploadedBytes || 0,
            percent: percent || 0,
            error_code: errorCode || '',
            message_en: messageEn || eventType,
            ...diagnostics
        });
    }

    function uploadFile(uploadState, isFallback = false) {
        const file = uploadState.file;
        const lesson = uploadState.lesson;
        const chunkSize = cfg.chunkSize || (5 * 1024 * 1024);
        const totalChunks = Math.ceil(file.size / chunkSize);
        const uuid = Date.now() + '-' + Math.random().toString(36).slice(2);
        const $progress = uploadState.progress;
        const $bar = uploadState.bar;
        const $text = uploadState.statusElement;
        let index = 0;

        uploadState.transport_mode = 'wordpress_streamed';
        uploadState.job_uuid = uuid;
        setUploadState(uploadState.upload_id, {
            transport_mode: 'wordpress_streamed',
            status: 'uploading',
            current_stage: isFallback ? 'wordpress_fallback_upload' : 'wordpress_upload',
            job_uuid: uuid,
            percent: 0,
            uploaded_bytes: 0,
            last_message: cfg.i18n.transport_wordpress
        }, isFallback ? 'تم اختيار الرفع عبر WordPress كبديل' : 'بدأ الرفع عبر WordPress');
        $progress.show();
        $progress.find('.olama-direct-actions').remove();
        $bar.css('width', '0%');

        const retryDelays = [2000, 5000, 10000];
        const next = (retryAttempt = 0) => {
            const start = index * chunkSize;
            const end = Math.min(start + chunkSize, file.size);
            const formData = new FormData();
            const f = filters();
            formData.append('action', 'academy_upload_media_video_chunk');
            formData.append('nonce', cfg.nonce);
            formData.append('file_uuid', uuid);
            formData.append('chunk_index', index);
            formData.append('total_chunks', totalChunks);
            formData.append('filename', file.name);
            formData.append('total_size', file.size);
            formData.append('start_byte', start);
            formData.append('chunk_size', chunkSize);
            formData.append('video_chunk', file.slice(start, end), file.name);
            formData.append('id', lesson.recordId || '');
            formData.append('lesson_id', lesson.lessonId);
            formData.append('unit_id', lesson.unitId);
            formData.append('lesson_name', lesson.lessonName);
            formData.append('lesson_number', lesson.lessonNumber);
            formData.append('unit_name', lesson.unitName);
            formData.append('academic_year_id', f.academic_year_id);
            formData.append('semester_id', f.semester_id);
            formData.append('grade_id', f.grade_id);
            formData.append('subject_id', f.subject_id);
            setUploadState(uploadState.upload_id, {
                current_chunk_index: index,
                uploaded_bytes: start,
                current_stage: 'wordpress_chunk_upload',
                last_message: `جاري رفع الجزء ${index + 1} من ${totalChunks}`
            }, `بدأ رفع الجزء ${index + 1} من ${totalChunks}`);

            if (retryAttempt > 0) {
                $text.text(cfg.i18n.retrying_chunk
                    .replace('%1$s', index + 1)
                    .replace('%2$s', totalChunks)
                    .replace('%3$s', retryAttempt));
            } else {
                $text.text(`${cfg.i18n.transport_wordpress} - ${cfg.i18n.uploading} ${index + 1}/${totalChunks}`);
            }
            $bar.css('background', '#2271b1');

            const failOrRetry = (responseData, xhrFailed = false) => {
                const responseMessage = uploadErrorMessage(responseData);
                const retryable = typeof responseData === 'object' && responseData !== null && responseData.retryable === true;

                if (retryable && retryAttempt < retryDelays.length) {
                    const nextAttempt = retryAttempt + 1;
                    setUploadState(uploadState.upload_id, {
                        status: 'retrying',
                        retry_count: nextAttempt,
                        last_message: cfg.i18n.retryable_network_error
                    }, `إعادة محاولة الجزء ${index + 1}`);
                    $text.text(cfg.i18n.retryable_network_error);
                    refreshUploadNonce().done(function (nonceResponse) {
                        if (!nonceResponse.success || !nonceResponse.data || !nonceResponse.data.drive_authenticated) {
                            $bar.css('width', '100%').css('background', '#d63638');
                            const nonceMessage = (nonceResponse.data && (nonceResponse.data.message_ar || nonceResponse.data.auth_warning)) || cfg.i18n.session_or_permission_expired;
                            $text.text(nonceMessage);
                            notify(nonceMessage, 'error');
                            finishUpload(uploadState, 'failed', false);
                            return;
                        }
                        $text.text(cfg.i18n.retrying_chunk
                            .replace('%1$s', index + 1)
                            .replace('%2$s', totalChunks)
                            .replace('%3$s', nextAttempt));
                        window.setTimeout(() => next(nextAttempt), retryDelays[retryAttempt]);
                    }).fail(function () {
                        $bar.css('width', '100%').css('background', '#d63638');
                        $text.text(cfg.i18n.session_or_permission_expired);
                        notify(cfg.i18n.session_or_permission_expired, 'error');
                        finishUpload(uploadState, 'failed', false);
                    });
                    return;
                }

                $bar.css('width', '100%').css('background', '#d63638');
                $text.text(responseMessage);
                notify(responseMessage, 'error');
                setUploadState(uploadState.upload_id, {
                    status: 'failed',
                    error_code: responseData && responseData.error_code ? responseData.error_code : '',
                    current_stage: responseData && responseData.stage ? responseData.stage : 'wordpress_upload_failed',
                    drive_http_status: responseData && responseData.drive_http_status ? responseData.drive_http_status : '',
                    last_message: responseMessage
                }, responseMessage);
                finishUpload(uploadState, 'failed', false);
            };

            $.ajax({
                url: cfg.ajaxurl,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: function () {
                    const xhr = new window.XMLHttpRequest();
                    xhr.upload.addEventListener('progress', function (event) {
                        if (event.lengthComputable) {
                            const uploaded = start + event.loaded;
                            const percent = Math.min(95, Math.round((uploaded / file.size) * 100));
                            setUploadState(uploadState.upload_id, {
                                uploaded_bytes: uploaded,
                                percent,
                                status: 'uploading',
                                last_message: `${cfg.i18n.transport_wordpress} ${percent}%`
                            });
                            $bar.css('width', percent + '%');
                            uploadState.uploaded_bytes = uploaded;
                        }
                    });
                    return xhr;
                }
            }).done(function (response) {
                if (!response.success) {
                    failOrRetry(response.data);
                    return;
                }
                if (response.data.completed) {
                    if (response.data.needs_finalize) {
                        uploadState.asset_id = response.data.asset_id || uploadState.asset_id;
                        uploadState.job_uuid = response.data.job_uuid || uploadState.job_uuid;
                        uploadState.status = 'finalizing';
                        finalizeUpload(uploadState, 0);
                    } else {
                        $bar.css('width', '100%');
                        $text.text(cfg.i18n.status_uploaded_to_drive);
                        notify(cfg.i18n.processing_note, 'success');
                        finishUpload(uploadState, 'completed');
                    }
                    return;
                }
                index++;
                setUploadState(uploadState.upload_id, {
                    uploaded_bytes: Math.min(index * chunkSize, file.size),
                    percent: Math.min(95, Math.round((Math.min(index * chunkSize, file.size) / file.size) * 100)),
                    status: 'uploading',
                    last_message: `تم رفع الجزء ${index} من ${totalChunks}`
                }, `تم رفع الجزء ${index} من ${totalChunks}`);
                if (index < totalChunks) {
                    next();
                }
            }).fail(function () {
                failOrRetry({
                    retryable: true,
                    message_ar: cfg.i18n.retryable_network_error,
                    error_code: 'ajax_transport_failed',
                    stage: 'ajax_transport'
                }, true);
            });
        };

        next();
    }

    function finalizeUpload(uploadState, retryAttempt = 0) {
        const retryDelays = [2000, 5000, 10000];
        const $text = uploadState.statusElement;
        const $bar = uploadState.bar;
        setUploadState(uploadState.upload_id, {
            status: 'finalizing',
            current_stage: 'finalize_upload',
            last_message: cfg.i18n.finalizing_upload,
            percent: Math.max(uploadState.percent || 0, 95)
        }, 'جاري تثبيت بيانات الفيديو');
        if ($text && $text.length) {
            $text.text(cfg.i18n.finalizing_upload);
        }

        $.post(cfg.ajaxurl, {
            action: 'olama_media_finalize_upload',
            nonce: cfg.nonce,
            asset_id: uploadState.asset_id,
            job_uuid: uploadState.job_uuid || ''
        }).done(function (response) {
            if (response.success) {
                if ($bar && $bar.length) {
                    $bar.css('width', '100%').css('background', '#2271b1');
                }
                if ($text && $text.length) {
                    $text.text(cfg.i18n.status_uploaded_to_drive);
                }
                notify(cfg.i18n.processing_note, 'success');
                setUploadState(uploadState.upload_id, {
                    status: 'completed',
                    current_stage: 'completed',
                    percent: 100,
                    uploaded_bytes: uploadState.total_size,
                    last_message: 'تم الرفع بنجاح. المعاينة قيد المعالجة.',
                    completed_at: Date.now()
                }, 'تم الرفع بنجاح');
                finishUpload(uploadState, 'completed');
                return;
            }

            const retryable = !(response.data && response.data.retryable === false);
            if (retryable && retryAttempt < retryDelays.length) {
                refreshUploadNonce().done(function (nonceResponse) {
                    if (!nonceResponse.success || !nonceResponse.data || !nonceResponse.data.drive_authenticated) {
                        const nonceMessage = (nonceResponse.data && (nonceResponse.data.message_ar || nonceResponse.data.auth_warning)) || cfg.i18n.session_or_permission_expired;
                        if ($text && $text.length) {
                            $text.text(nonceMessage);
                        }
                        notify(nonceMessage, 'error');
                        setUploadState(uploadState.upload_id, {
                            status: 'failed',
                            current_stage: 'finalize_nonce_refresh',
                            last_message: nonceMessage
                        }, nonceMessage);
                        return;
                    }
                    window.setTimeout(() => finalizeUpload(uploadState, retryAttempt + 1), retryDelays[retryAttempt]);
                }).fail(function () {
                    if ($text && $text.length) {
                        $text.text(cfg.i18n.session_or_permission_expired);
                    }
                    notify(cfg.i18n.session_or_permission_expired, 'error');
                });
                return;
            }

            if ($bar && $bar.length) {
                $bar.css('width', '100%').css('background', '#dba617');
            }
            if ($text && $text.length) {
                $text.text(uploadErrorMessage(response.data) || cfg.i18n.finalize_failed);
            }
            notify(uploadErrorMessage(response.data) || cfg.i18n.finalize_failed, 'error');
            setUploadState(uploadState.upload_id, {
                status: 'failed',
                current_stage: response.data && response.data.stage ? response.data.stage : 'finalize_failed',
                error_code: response.data && response.data.error_code ? response.data.error_code : '',
                last_message: uploadErrorMessage(response.data) || cfg.i18n.finalize_failed
            }, uploadErrorMessage(response.data) || cfg.i18n.finalize_failed);
            finishUpload(uploadState, 'failed');
        }).fail(function () {
            if (retryAttempt < retryDelays.length) {
                window.setTimeout(() => finalizeUpload(uploadState, retryAttempt + 1), retryDelays[retryAttempt]);
                return;
            }
            if ($text && $text.length) {
                $text.text(cfg.i18n.finalize_failed);
            }
            notify(cfg.i18n.finalize_failed, 'error');
            setUploadState(uploadState.upload_id, {
                status: 'failed',
                current_stage: 'finalize_transport_failed',
                last_message: cfg.i18n.finalize_failed
            }, cfg.i18n.finalize_failed);
            finishUpload(uploadState, 'failed');
        });
    }

    $(document).on('click', '.btn-finalize-upload', function () {
        const $row = $(this).closest('tr');
        const assetId = $row.data('asset-id');
        const jobUuid = $(this).data('job-uuid') || '';
        const $btn = $(this).prop('disabled', true).text(cfg.i18n.loading);
        $row.find('.olama-progress').show();
        const uploadState = {
            upload_id: `finalize-${assetId}-${Date.now()}`,
            lesson_id: $row.data('lesson-id') || '',
            row: $row,
            progress: $row.find('.olama-progress'),
            statusElement: $row.find('.olama-progress-text'),
            bar: $row.find('.olama-progress-bar'),
            uploadButton: $btn,
            asset_id: assetId,
            job_uuid: jobUuid,
            transport_mode: 'wordpress_streamed',
            lesson: { lessonName: $row.find('td').eq(1).text() },
            total_size: 0,
            uploaded_bytes: 0,
            percent: 95,
            started_at: Date.now(),
            current_stage: 'manual_finalize',
            last_message: cfg.i18n.finalizing_upload,
            events: [],
            status: 'finalizing'
        };
        activeUploads.set(uploadState.upload_id, uploadState);
        appendUploadEvent(uploadState, 'finalizing', 'جاري تثبيت بيانات الفيديو');
        renderUploadState(uploadState.upload_id);
        finalizeUpload(uploadState);
        window.setTimeout(() => $btn.prop('disabled', false).text(cfg.i18n.retry_finalize), 1500);
    });

    $(document).on('change', '.olama-note', function () {
        const assetId = $(this).closest('tr').data('asset-id');
        if (!assetId) {
            return;
        }
        $.post(cfg.ajaxurl, {
            action: 'olama_media_save_notes',
            nonce: cfg.nonce,
            asset_id: assetId,
            notes: $(this).val()
        }).done((response) => {
            if (!response.success) {
                notify(response.data || cfg.i18n.error, 'error');
            }
        });
    });

    $(document).on('click', '.btn-approval', function () {
        const assetId = $(this).closest('tr').data('asset-id');
        const status = $(this).data('status');
        const comment = status === 'rejected' ? window.prompt(cfg.i18n.notes + ':', '') : '';
        if (comment === null) {
            return;
        }
        $.post(cfg.ajaxurl, {
            action: 'academy_update_media_status',
            nonce: cfg.nonce,
            media_id: assetId,
            status,
            comment
        }).done((response) => {
            response.success ? refreshCurriculumWhenIdle() : notify(response.data || cfg.i18n.error, 'error');
        });
    });

    $(document).on('click', '.btn-check-status', function () {
        const $row = $(this).closest('tr');
        const assetId = $row.data('asset-id');
        const $btn = $(this).prop('disabled', true).text(cfg.i18n.loading);
        $.post(cfg.ajaxurl, {
            action: 'olama_media_check_preview_status',
            nonce: cfg.nonce,
            asset_id: assetId
        }).done((response) => {
            response.success ? refreshCurriculumWhenIdle() : notify(response.data || cfg.i18n.error, 'error');
        }).always(() => $btn.prop('disabled', false).text(cfg.i18n.check_status));
    });

    $(document).on('click', '.btn-preview', function () {
        let url = $(this).data('url') || '';
        if (url.includes('/view')) {
            url = url.replace('/view', '/preview');
        }
        $('#modal-video-title').text($(this).data('title') || '');
        $('#video-preview-iframe').attr('src', url);
        $('#video-preview-modal').removeAttr('hidden');
    });

    $('.olama-media-modal-close, #video-preview-modal').on('click', function (event) {
        if (event.target !== this) {
            return;
        }
        $('#video-preview-modal').attr('hidden', 'hidden');
        $('#video-preview-iframe').attr('src', '');
    });

    $('#drive-settings-form').on('submit', function (event) {
        event.preventDefault();
        $('#settings-status').text(cfg.i18n.loading);
        $.post(cfg.ajaxurl, $(this).serialize() + '&action=academy_save_drive_settings&nonce=' + encodeURIComponent(cfg.nonce))
            .done((response) => $('#settings-status').text(response.success ? response.data : (response.data || cfg.i18n.error)));
    });

    $('#btn-test-connection').on('click', function () {
        $('#settings-status').text(cfg.i18n.loading);
        $.post(cfg.ajaxurl, { action: 'academy_test_drive_connection', nonce: cfg.nonce })
            .done((response) => $('#settings-status').text(response.success ? (response.data.name || 'OK') : (response.data || cfg.i18n.error)));
    });

    $('#btn-refresh-log').on('click', () => loadLogs(1));

    function loadLogs(page) {
        state.logPage = page;
        $('#log-table-body').html(`<tr><td colspan="4">${esc(cfg.i18n.loading)}</td></tr>`);
        $.get(cfg.ajaxurl, {
            action: 'olama_media_get_upload_log',
            nonce: cfg.nonce,
            paged: page,
            job_uuid: $('#log-filter-job-uuid').val() || '',
            event_type: $('#log-filter-event-type').val() || '',
            error_code: $('#log-filter-error-code').val() || ''
        })
            .done(function (response) {
                if (!response.success || !response.data.items.length) {
                    $('#log-table-body').html(`<tr><td colspan="4">${esc(cfg.i18n.no_logs)}</td></tr>`);
                    return;
                }
                let html = '';
                response.data.items.forEach((item) => {
                    html += `<tr><td>${esc(item.created_at)}</td><td>${esc(item.event_type)}</td><td>${esc(item.message)}</td><td>${esc(item.title || '-')}</td></tr>`;
                });
                $('#log-table-body').html(html);
            });
    }

    $('#btn-migration-dry-run, #btn-migrate-legacy').on('click', function () {
        const dryRun = this.id === 'btn-migration-dry-run' ? 1 : 0;
        $('#migration-result').text(cfg.i18n.loading);
        $.post(cfg.ajaxurl, { action: 'olama_media_migrate_legacy', nonce: cfg.nonce, dry_run: dryRun })
            .done((response) => $('#migration-result').text(JSON.stringify(response.data || response, null, 2)));
    });

    $('#btn-drive-sync-dry-run, #btn-drive-sync').on('click', function () {
        const dryRun = this.id === 'btn-drive-sync-dry-run' ? 1 : 0;
        syncDrive({ dryRun, reloadCurriculum: !dryRun });
    });

    if (cfg.autoSyncDriveOnLoad) {
        syncDrive({ dryRun: 0, reloadCurriculum: true, silent: true });
    }

    function syncDrive(options) {
        const dryRun = options && options.dryRun ? 1 : 0;
        const reloadCurriculum = !options || options.reloadCurriculum !== false;
        const silent = !!(options && options.silent);
        const payload = {
            action: 'olama_media_sync_drive',
            nonce: cfg.nonce,
            dry_run: dryRun,
            academic_year_id: $('#filter-year-id').val() || '',
            semester_id: $('#filter-semester').val() || '',
            grade_id: $('#filter-grade').val() || '',
            subject_id: $('#filter-subject').val() || ''
        };

        if (!silent) {
            $('#drive-sync-result').text(cfg.i18n.loading);
        }

        return $.post(cfg.ajaxurl, payload)
            .done(function (response) {
                if (!silent) {
                    $('#drive-sync-result').text(JSON.stringify(response.data || response, null, 2));
                }
                if (response.success && reloadCurriculum) {
                    $('#btn-load-curriculum').trigger('click');
                }
            })
            .fail(function () {
                if (!silent) {
                    $('#drive-sync-result').text(cfg.i18n.error);
                }
            });
    }
});
