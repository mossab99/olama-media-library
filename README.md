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
- Google Drive accepts the final chunk: `upload_status = uploaded_to_drive`, `preview_status = processing`
- Manual Drive status check confirms metadata: `preview_status = ready`
- Upload failure: `upload_status = failed`
- Review state is separate: `approval_status = pending|approved|rejected`

Uploaded to Drive does not mean ready for preview or approved for students.

## Google Drive Behavior

Normal curriculum loading reads the local database only. It does not call Google Drive.

Drive API calls happen during:

- upload session creation
- chunk upload
- permission update after upload completion
- manual Check Status
- settings authentication/test

Drive preview can take time after a successful upload. While processing, users can use the download link if Drive exposes one.

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
