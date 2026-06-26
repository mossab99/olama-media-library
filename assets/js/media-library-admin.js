jQuery(function ($) {
    'use strict';

    const cfg = window.olamaMediaLibrary;
    const state = { currentLesson: null, logPage: 1, pendingDirectUpload: null, pendingDirectPayload: null };

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

                html += `<tr data-asset-id="${esc(lesson.media_record_id || '')}">
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
                        <div class="olama-progress" id="progress-${esc(lesson.id)}">
                            <div class="olama-progress-track"><div class="olama-progress-bar"></div></div>
                            <small class="olama-progress-text"></small>
                        </div>
                    </td>
                </tr>`;
            });

            html += '</tbody></table></div>';
        });

        $container.html(html);
    }

    $(document).on('click', '.btn-upload', function () {
        state.currentLesson = $(this).data();
        $('#media-video-input').trigger('click');
    });

    $('#media-video-input').on('change', function () {
        const file = this.files[0];
        this.value = '';
        if (!file || !state.currentLesson) {
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
            if (shouldUseDirectUpload(file)) {
                uploadFileDirect(file, state.currentLesson);
            } else {
                uploadFile(file, state.currentLesson);
            }
        }).fail(function () {
            notify(cfg.i18n.session_or_permission_expired, 'error');
        });
    });

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

    function uploadFileDirect(file, lesson) {
        const payload = lessonUploadPayload(file, lesson);
        const $progress = $('#progress-' + lesson.lessonId);
        const $bar = $progress.find('.olama-progress-bar');
        const $text = $progress.find('.olama-progress-text');

        state.pendingDirectUpload = { file, lesson };
        state.pendingDirectPayload = payload;
        $progress.show().find('.olama-direct-actions').remove();
        $bar.css('width', '0%').css('background', '#2271b1');
        $text.text(`${cfg.i18n.transport_direct} - ${cfg.i18n.direct_session_creating}`);
        logDirectEvent('direct_upload_selected', payload, 0, 0, '', 'Direct upload selected.');

        refreshUploadNonce().done(function (nonceResponse) {
            if (!nonceResponse.success || !nonceResponse.data || !nonceResponse.data.drive_authenticated) {
                showDirectFallback($progress, $bar, $text, uploadErrorMessage(nonceResponse.data) || cfg.i18n.session_or_permission_expired);
                return;
            }

            $.post(cfg.ajaxurl, {
                action: 'olama_media_start_direct_upload',
                nonce: cfg.nonce,
                ...payload
            }).done(function (response) {
                if (!response.success || !response.data || !response.data.upload_url) {
                    showDirectFallback($progress, $bar, $text, uploadErrorMessage(response.data) || cfg.i18n.direct_browser_failed);
                    return;
                }

                const session = response.data;
                payload.job_uuid = session.job_uuid;
                payload.asset_id = session.asset_id;
                state.pendingDirectPayload = payload;
                $text.text(`${cfg.i18n.transport_direct} - ${cfg.i18n.direct_uploading}`);
                logDirectEvent('direct_upload_started', payload, file.size, 0, '', 'Direct browser upload started.');
                sendDirectToGoogle(file, session, payload, $progress, $bar, $text);
            }).fail(function () {
                showDirectFallback($progress, $bar, $text, cfg.i18n.direct_browser_failed);
            });
        }).fail(function () {
            showDirectFallback($progress, $bar, $text, cfg.i18n.session_or_permission_expired);
        });
    }

    function sendDirectToGoogle(file, session, payload, $progress, $bar, $text) {
        const chunkSize = normalizeDirectChunkSize(cfg.directUploadChunkSizeBytes || (16 * 1024 * 1024));
        const checkpoints = { 25: false, 50: false, 75: false, 100: false };
        let nextStart = 0;

        const uploadChunk = (retryAttempt = 0, probeRetryAttempt = 0) => {
            const start = nextStart;
            const end = Math.min(file.size - 1, start + chunkSize - 1);
            const chunkIndex = Math.floor(start / chunkSize);
            const blob = file.slice(start, end + 1);
            const xhr = new XMLHttpRequest();

            xhr.upload.onprogress = function (event) {
                if (!event.lengthComputable) {
                    return;
                }
                const uploaded = start + event.loaded;
                const percent = Math.min(99, Math.round((uploaded / file.size) * 100));
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
                        probeDirectUpload(session, payload, file, start, end, chunkIndex, chunkSize, $progress, $bar, $text, retryAttempt, probeRetryAttempt);
                        return;
                    }
                    if (nextStart < file.size) {
                        uploadChunk();
                        return;
                    }
                    probeDirectUpload(session, payload, file, start, end, chunkIndex, chunkSize, $progress, $bar, $text, retryAttempt, probeRetryAttempt, diagnostics);
                    return;
                }

                if (xhr.status === 200 || xhr.status === 201) {
                    $bar.css('width', '100%');
                    checkpoints[100] = true;
                    logDirectEvent('direct_upload_completed_browser', payload, file.size, file.size, '', 'Direct browser upload completed.', 100, diagnostics);
                    $text.text(cfg.i18n.direct_completed_finalizing);
                    refreshUploadNonce().always(function () {
                        finalizeDirectUpload(session.asset_id, session.job_uuid, '', $text, $bar, $progress);
                    });
                    return;
                }

                if (xhr.status >= 500 && retryAttempt < 3) {
                    window.setTimeout(() => uploadChunk(retryAttempt + 1), [2000, 5000, 10000][retryAttempt]);
                    return;
                }

                if (xhr.status === 0) {
                    probeDirectUpload(session, payload, file, start, end, chunkIndex, chunkSize, $progress, $bar, $text, retryAttempt, probeRetryAttempt, diagnostics);
                    return;
                }

                const errorCode = xhr.status === 0 ? 'direct_cors_or_network_failure' : (xhr.status >= 400 && xhr.status < 500 ? 'direct_session_restart_required' : 'direct_google_http_error');
                const message = xhr.status >= 400 && xhr.status < 500 ? cfg.i18n.direct_session_restart_required : `${cfg.i18n.direct_browser_failed} (${xhr.status})`;
                showDirectFallback($progress, $bar, $text, message, errorCode, diagnostics, xhr.status >= 400 && xhr.status < 500 ? 'direct_upload_failed' : 'direct_upload_browser_error');
            };

            xhr.onerror = function () {
                probeDirectUpload(session, payload, file, start, end, chunkIndex, chunkSize, $progress, $bar, $text, retryAttempt, probeRetryAttempt, {
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
                probeDirectUpload(session, payload, file, start, end, chunkIndex, chunkSize, $progress, $bar, $text, retryAttempt, probeRetryAttempt, diagnostics, 'direct_chunk_timeout');
            };
            xhr.onabort = function () {
                showDirectFallback($progress, $bar, $text, cfg.i18n.direct_browser_failed, 'direct_browser_aborted', directXhrDiagnostics(xhr, file, start, end, chunkIndex, chunkSize));
            };

            xhr.open('PUT', session.upload_url, true);
            xhr.timeout = cfg.directUploadChunkTimeoutMs || 180000;
            xhr.setRequestHeader('Content-Type', session.mime_type || 'video/mp4');
            xhr.setRequestHeader('Content-Range', `bytes ${start}-${end}/${file.size}`);
            xhr.send(blob);
        };

        const probeDirectUpload = (sessionData, eventPayload, uploadFileRef, start, end, chunkIndex, directChunkSize, $progressRef, $barRef, $textRef, retryAttempt, probeRetryAttempt, diagnostics = {}, errorCode = 'direct_browser_network_or_response_failure') => {
            $textRef.text(cfg.i18n.direct_probe_checking || cfg.i18n.direct_browser_failed);
            logDirectEvent('direct_upload_browser_error', eventPayload, uploadFileRef.size, diagnostics.loaded_bytes || start, errorCode, diagnostics.message_en || 'Browser upload response failed; probing Google resumable session.', 0, diagnostics);
            $.post(cfg.ajaxurl, {
                action: 'olama_media_probe_direct_upload',
                nonce: cfg.nonce,
                job_uuid: sessionData.job_uuid || '',
                asset_id: sessionData.asset_id || 0,
                total_size: uploadFileRef.size
            }).done(function (response) {
                const probeData = response.data || {};
                const probeDiagnostics = {
                    ...diagnostics,
                    last_probe_status: probeData.google_http_status || probeData.drive_http_status || 0,
                    next_start: probeData.next_start || 0,
                    stage: 'direct_google_put'
                };

                if (response.success && probeData.complete) {
                    $barRef.css('width', '100%');
                    logDirectEvent('direct_upload_completed_browser', eventPayload, uploadFileRef.size, uploadFileRef.size, '', 'Direct upload completed according to Google probe.', 100, probeDiagnostics);
                    $textRef.text(cfg.i18n.direct_completed_finalizing);
                    refreshUploadNonce().always(function () {
                        finalizeDirectUpload(sessionData.asset_id, sessionData.job_uuid, '', $textRef, $barRef, $progressRef);
                    });
                    return;
                }

                if (response.success && probeData.next_start > start) {
                    nextStart = Math.min(uploadFileRef.size, probeData.next_start);
                    uploadChunk();
                    return;
                }

                if (response.success && probeData.next_start === start && retryAttempt < 3) {
                    window.setTimeout(() => uploadChunk(retryAttempt + 1, probeRetryAttempt + 1), [2000, 5000, 10000][retryAttempt]);
                    return;
                }

                const probeError = probeData.error_code || errorCode;
                const message = probeData.message_ar || cfg.i18n.direct_browser_failed;
                showDirectFallback($progressRef, $barRef, $textRef, message, probeError, probeDiagnostics, probeData.retryable ? 'direct_upload_browser_error' : 'direct_upload_failed');
            }).fail(function () {
                if (probeRetryAttempt < 2) {
                    window.setTimeout(() => probeDirectUpload(sessionData, eventPayload, uploadFileRef, start, end, chunkIndex, directChunkSize, $progressRef, $barRef, $textRef, retryAttempt, probeRetryAttempt + 1, diagnostics, errorCode), [2000, 5000, 10000][probeRetryAttempt]);
                    return;
                }
                showDirectFallback($progressRef, $barRef, $textRef, cfg.i18n.direct_browser_failed, errorCode, diagnostics);
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

    function finalizeDirectUpload(assetId, jobUuid, driveFileId, $text, $bar, $progress) {
        $.post(cfg.ajaxurl, {
            action: 'olama_media_finalize_direct_upload',
            nonce: cfg.nonce,
            asset_id: assetId,
            job_uuid: jobUuid || '',
            drive_file_id: driveFileId || ''
        }).done(function (response) {
            if (response.success) {
                $bar.css('width', '100%').css('background', '#2271b1');
                $text.text(cfg.i18n.direct_success);
                notify(cfg.i18n.direct_success, 'success');
                state.pendingDirectUpload = null;
                state.pendingDirectPayload = null;
                loadCurriculum();
                return;
            }
            showDirectFallback($progress, $bar, $text, uploadErrorMessage(response.data) || cfg.i18n.finalize_failed, 'direct_finalize_failed');
            loadCurriculum();
        }).fail(function () {
            showDirectFallback($progress, $bar, $text, cfg.i18n.finalize_failed, 'direct_finalize_transport_failed');
            loadCurriculum();
        });
    }

    function showDirectFallback($progress, $bar, $text, message, errorCode = 'direct_browser_error', diagnostics = {}, eventType = 'direct_upload_browser_error') {
        $bar.css('width', '100%').css('background', '#dba617');
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
        $text.text(`${fullMessage} ${cfg.i18n.direct_fallback_available}`);
        notify(fullMessage, 'error');

        if (state.pendingDirectUpload) {
            logDirectEvent(eventType, state.pendingDirectPayload, state.pendingDirectUpload.file.size, diagnostics.loaded_bytes || 0, errorCode, diagnostics.message_en || message, 0, diagnostics);
        }

        $progress.find('.olama-direct-actions').remove();
        $progress.append(`<div class="olama-direct-actions">
            <button type="button" class="button button-small btn-retry-direct-upload">${esc(cfg.i18n.retry_direct_upload)}</button>
            <button type="button" class="button button-small btn-use-wordpress-fallback">${esc(cfg.i18n.use_wordpress_fallback)}</button>
        </div>`);
    }

    $(document).on('click', '.btn-retry-direct-upload', function () {
        if (!state.pendingDirectUpload) {
            return;
        }
        uploadFileDirect(state.pendingDirectUpload.file, state.pendingDirectUpload.lesson);
    });

    $(document).on('click', '.btn-use-wordpress-fallback', function () {
        if (!state.pendingDirectUpload) {
            return;
        }
        const pending = state.pendingDirectUpload;
        logDirectEvent('direct_upload_fallback_selected', state.pendingDirectPayload, pending.file.size, 0, '', 'User selected WordPress streamed fallback.');
        state.pendingDirectUpload = null;
        state.pendingDirectPayload = null;
        uploadFile(pending.file, pending.lesson);
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

    function uploadFile(file, lesson) {
        const chunkSize = cfg.chunkSize || (5 * 1024 * 1024);
        const totalChunks = Math.ceil(file.size / chunkSize);
        const uuid = Date.now() + '-' + Math.random().toString(36).slice(2);
        const $progress = $('#progress-' + lesson.lessonId);
        const $bar = $progress.find('.olama-progress-bar');
        const $text = $progress.find('.olama-progress-text');
        let index = 0;

        $progress.show();
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
                    $text.text(cfg.i18n.retryable_network_error);
                    refreshUploadNonce().done(function (nonceResponse) {
                        if (!nonceResponse.success || !nonceResponse.data || !nonceResponse.data.drive_authenticated) {
                            $bar.css('width', '100%').css('background', '#d63638');
                            const nonceMessage = (nonceResponse.data && (nonceResponse.data.message_ar || nonceResponse.data.auth_warning)) || cfg.i18n.session_or_permission_expired;
                            $text.text(nonceMessage);
                            notify(nonceMessage, 'error');
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
                    });
                    return;
                }

                $bar.css('width', '100%').css('background', '#d63638');
                $text.text(responseMessage);
                notify(responseMessage, 'error');
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
                            $bar.css('width', percent + '%');
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
                        finalizeUpload(response.data.asset_id, response.data.job_uuid, $text, $bar);
                    } else {
                        $bar.css('width', '100%');
                        $text.text(cfg.i18n.status_uploaded_to_drive);
                        notify(cfg.i18n.processing_note, 'success');
                        loadCurriculum();
                    }
                    return;
                }
                index++;
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

    function finalizeUpload(assetId, jobUuid, $text, $bar, retryAttempt = 0) {
        const retryDelays = [2000, 5000, 10000];
        if ($text && $text.length) {
            $text.text(cfg.i18n.finalizing_upload);
        }

        $.post(cfg.ajaxurl, {
            action: 'olama_media_finalize_upload',
            nonce: cfg.nonce,
            asset_id: assetId,
            job_uuid: jobUuid || ''
        }).done(function (response) {
            if (response.success) {
                if ($bar && $bar.length) {
                    $bar.css('width', '100%').css('background', '#2271b1');
                }
                if ($text && $text.length) {
                    $text.text(cfg.i18n.status_uploaded_to_drive);
                }
                notify(cfg.i18n.processing_note, 'success');
                loadCurriculum();
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
                        return;
                    }
                    window.setTimeout(() => finalizeUpload(assetId, jobUuid, $text, $bar, retryAttempt + 1), retryDelays[retryAttempt]);
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
            loadCurriculum();
        }).fail(function () {
            if (retryAttempt < retryDelays.length) {
                window.setTimeout(() => finalizeUpload(assetId, jobUuid, $text, $bar, retryAttempt + 1), retryDelays[retryAttempt]);
                return;
            }
            if ($text && $text.length) {
                $text.text(cfg.i18n.finalize_failed);
            }
            notify(cfg.i18n.finalize_failed, 'error');
            loadCurriculum();
        });
    }

    $(document).on('click', '.btn-finalize-upload', function () {
        const $row = $(this).closest('tr');
        const assetId = $row.data('asset-id');
        const jobUuid = $(this).data('job-uuid') || '';
        const $btn = $(this).prop('disabled', true).text(cfg.i18n.loading);
        $row.find('.olama-progress').show();

        finalizeUpload(assetId, jobUuid, $row.find('.olama-progress-text'), $row.find('.olama-progress-bar'));
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
            response.success ? loadCurriculum() : notify(response.data || cfg.i18n.error, 'error');
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
            response.success ? loadCurriculum() : notify(response.data || cfg.i18n.error, 'error');
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
});
