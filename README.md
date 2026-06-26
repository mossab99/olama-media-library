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
