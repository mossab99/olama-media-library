# Olama Media Library

Standalone media library plugin for Olama School curriculum videos.

## Purpose

`olama-media-library` owns media upload, Google Drive integration, upload jobs, media statuses, approval, notes, diagnostics, and migration from the old bundled media module.

`olama-school` remains the source of curriculum structure only: academic years, semesters, grades, subjects, units, and lessons.

## Dependency

This plugin expects the Olama School curriculum tables to exist. If `olama-school` is inactive, the media plugin loads safely but curriculum filters and lesson rows may be unavailable.

## Tables

- `wp_olama_media_assets`
- `wp_olama_media_upload_jobs`
- `wp_olama_media_job_events`

The old `wp_academy_media_uploads` table is not deleted.

## Status Lifecycle

- Upload starts: `upload_status = uploading`, `preview_status = not_checked`
- Chunk upload sends file parts to Google Drive while the job remains `uploading`.
- Google Drive accepts the final chunk and the browser calls `olama_media_finalize_upload`.
- Finalize fetches Drive metadata, stores preview/download links, sets permissions, then sets `upload_status = uploaded_to_drive` and `preview_status = processing`.
- Manual Drive status check confirms metadata: `preview_status = ready`
- Upload failure: `upload_status = failed`
- Finalize failure: job status becomes `finalize_failed`; the uploaded Drive file id is preserved so admins can retry finalization without re-uploading.
- Review state is separate: `approval_status = pending|approved|rejected`

Uploaded to Drive does not mean ready for preview or approved for students.

## Google Drive Behavior

Normal curriculum loading reads the local database only. It does not call Google Drive.

Drive API calls happen during:

- upload session creation
- chunk upload
- finalize upload metadata registration
- permission update during finalize
- manual Check Status
- settings authentication/test

Drive preview can take time after a successful upload. While processing, users can use the download link if Drive exposes one.

## Upload Lifecycle

1. Browser sends chunks through WordPress AJAX to Google Drive resumable upload.
2. The final chunk returns `needs_finalize = true`.
3. Browser calls `olama_media_finalize_upload`.
4. Finalize stores Drive metadata and marks the asset as uploaded to Drive.
5. Preview remains `processing` until a manual Check Status confirms Drive video metadata.
6. If finalization fails, use the admin action `إعادة تثبيت بيانات الفيديو` to retry without uploading the file again.

## Phase 2.0 Upload Transport Modes

The plugin supports three upload transport modes from Drive settings:

- `wordpress_streamed`: the stable Phase 1.3 path. Browser uploads chunks to WordPress, then PHP streams them to Google Drive.
- `direct_google`: WordPress creates a secure Google Drive resumable upload session, then the browser sends the video bytes directly to Google Drive.
- `auto`: recommended production mode. Files at or above the configured threshold use direct Google upload; smaller files keep the WordPress streamed uploader.

Recommended production setting: `auto` with `olama_media_direct_upload_threshold_mb = 20`.

Direct upload flow:

1. WordPress verifies nonce, logged-in user, media upload capability, file type, Google auth health, and lesson binding.
2. WordPress creates the target Drive folder and media/job rows.
3. WordPress creates a Google Drive resumable upload URL and returns only that temporary session URL to the browser.
4. The browser uploads the MP4 directly to Google Drive with `XMLHttpRequest`.
5. The browser calls `olama_media_finalize_direct_upload`.
6. WordPress verifies Drive metadata server-side, ensures public read permission, stores links, and sets `upload_status = uploaded_to_drive` and `preview_status = processing`.

Security model:

- OAuth access tokens, refresh tokens, client secrets, and Google credentials are never exposed to the browser.
- The resumable upload URL is treated as sensitive and is not written to logs. Logs store only a hash/masked diagnostic value.
- The browser-provided Drive file ID is not trusted blindly. WordPress fetches Drive metadata and validates MIME type, file size, trash status, and expected folder when available before storing the file.

Fallback behavior:

- If direct upload initialization fails before bytes are sent, the UI offers the WordPress streamed uploader.
- If direct upload fails mid-transfer, the UI offers direct retry or explicit fallback to WordPress streamed upload. It does not silently re-upload through WordPress.
- The existing `academy_upload_media_video_chunk` endpoint remains available and unchanged for fallback.

Known Phase 2.0 MVP limitations:

- Browser/CORS behavior must be tested in Chrome against the live Google Drive resumable URL.
- Google Drive preview processing may still take time after upload completion.

## Phase 2.1 Direct Upload Stabilization

Direct upload no longer depends on the browser receiving the final Google Drive file ID. WordPress now creates a metadata-only Drive file first, stores its `drive_file_id` on the asset and upload job, then creates a resumable update session for that known file. Finalization uses the stored file ID and validates Drive metadata server-side.

Browser direct upload now sends the MP4 in resumable chunks:

- Default direct chunk size is `olama_media_direct_chunk_size_mb = 16`.
- Chunks are rounded to a multiple of 256 KB for Google Drive resumable upload compatibility.
- `308 Resume Incomplete` is treated as normal progress, not a failure.
- Final `200` or `201` completes the browser upload and triggers WordPress finalization.
- Google `4xx` responses stop the current direct session; the UI offers a new direct retry or WordPress streamed fallback.
- Google `5xx` responses retry the same chunk with short backoff.

Direct browser diagnostics are logged without storing the upload URL. Useful log context includes `xhr_status`, `stage`, `chunk_index`, byte range, response previews when readable, and `direct_cors_or_network_failure` when the browser reports status `0`.

Incomplete reserved Drive files are logged with `direct_reserved_file_incomplete`. They are not automatically deleted in this phase; review the logs before manual cleanup.

## Phase 2.2 Direct Upload Recovery

Direct upload can now recover when the browser loses a late Google response, including `xhr_status = 0` near the final chunk.

- WordPress stores the active resumable session URL in the upload job for server-side probing only. It is never returned after session creation and is not logged.
- On browser response/network failure, the plugin calls `olama_media_probe_direct_upload`.
- The probe sends `Content-Range: bytes */TOTAL_SIZE` to Google Drive.
- `200` or `201` means Google already completed the upload, so WordPress finalizes immediately.
- `308` means the upload is incomplete. The plugin reads Google's `Range` header and resumes from the next byte.
- `404` means the resumable session expired and a new direct session or WordPress fallback is required.
- Other `4xx` responses are treated as invalid sessions and are not retried blindly.
- `5xx` probe responses are retryable.

For every `308`, the browser uses Google's `Range` header strictly instead of assuming a full chunk was accepted. If the Range header is not readable, the plugin logs `direct_missing_range_header` and probes the session before deciding where to resume.

Browser diagnostics now distinguish `direct_browser_network_or_response_failure` from a full CORS failure. Admin details include the chunk index, uploaded MB, `last_probe_status`, and `next_start` when available.

## Phase 2.3 Concurrent Upload UI

The admin upload UI now tracks each upload with an isolated per-upload state object. Progress bars, retry/fallback buttons, finalize calls, and status text are scoped to the lesson row that started the upload. Curriculum refreshes are delayed while upload contexts are active so one completed upload does not redraw the table and hide another lesson's progress.

## Phase 1.3 Streaming Uploads

Chunk upload still passes through WordPress AJAX, but the Drive transfer now streams the temporary chunk file to Google Drive with native cURL. This avoids reading the chunk into a large PHP string before sending it to Drive and should reduce PHP memory pressure compared with the previous `wp_remote_request()` string-body transfer.

If the cURL extension is unavailable, the plugin falls back to the older WP HTTP transfer and logs `drive_streaming_unavailable_fallback_used`.

## Phase 1.4 Upload Diagnostics

Upload and finalize failures now return structured diagnostics for admins:

- `error_code`: stable failure reason, such as `nonce_expired`, `auth_session_expired`, `capability_denied`, `google_refresh_token_missing`, `google_token_refresh_failed`, `google_auth_failed`, `google_bad_upload_request`, or `google_range_mismatch`.
- `stage`: where the failure happened, such as `nonce_check`, `google_auth_check`, `drive_session_create`, `drive_chunk_upload`, or `finalize_upload`.
- `retryable`: whether the browser should retry the same chunk/finalize step.
- `drive_http_status`: Google Drive response status when available.
- `job_uuid`, `asset_id`, and `failed_chunk_index`: safe identifiers for support and log filtering.

Google OAuth/session problems are treated as non-retryable until the admin re-authenticates from Drive settings. Temporary transfer failures and Drive range mismatches may retry the same chunk without restarting the upload.

The Logs tab includes filters for job UUID, event type, and error code. Use these with `upload_error`, `google_auth_error`, `upload_chunk_timing`, and `upload_chunk_drive_timing` events when investigating a failed upload.

## Migration

Open the Media Library admin page, then the Migration tab.

1. Run dry-run.
2. Review the created/updated/skipped counts.
3. Run migration.
4. Run migration again to confirm it is idempotent.

Migration maps existing Drive file IDs and links into `olama_media_assets` and does not delete old records.

## Phase 1.1 Admin Test Checklist

1. Activate `olama-media-library`.
2. Save Google Drive settings.
3. Authenticate with Google.
4. Test the Drive connection.
5. Load curriculum from the Media Library page.
6. Upload a small MP4 file under 10 MB.
7. Confirm the row becomes `upload_status = uploaded_to_drive` and `preview_status = processing`.
8. Click Download and confirm the file is reachable.
9. Wait for Google Drive processing, then click Check Status.
10. Confirm `preview_status` eventually becomes `ready`.

## Rollback

Deactivate `olama-media-library`. No media tables or old records are deleted automatically.

`olama-school` keeps its curriculum data. The old media files remain in the `olama-school` plugin folder for this phase.
