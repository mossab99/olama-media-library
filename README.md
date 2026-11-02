# Olama Media Library

## Binding-first curriculum workspace

### Unified videos workflow

The **الفيديوهات** tab combines uploads, folder setup, synchronization, and per-file repair. Choose the curriculum once, then **فحص ومزامنة**. If its folder is not confirmed, the existing picker opens for selection or safe creation; confirming the binding continues the requested scan automatically. The former five-stage repair workflow and its old review queue are retained only inside the collapsed maintenance section of Drive settings. Existing deep links to repair open that section for compatibility.

A complete scoped scan attaches only unique exact matches as pending review and returns an actionable inbox for unmatched, ambiguous, occupied, or incorrectly linked videos. Users can choose a lesson/part per row, apply selected rows independently, or dismiss unlinked files. A failed or undecided row does not block the others. Correcting an existing target requires administrator permission, explicit confirmation, and an unchanged link revision; the new association returns to pending approval. Legacy guardian fallback cannot revive an explicitly moved, dismissed, or rejected association.

The latest complete per-subject report is saved in a non-autoloaded WordPress option tied to the binding revision. Reopening the page uses this local report and derives resolved/dismissed decisions from canonical links; it never scans Drive during lesson loading. Draft choices not yet applied are kept only while the page is open. Rebinding or changing the root invalidates the old report. Failed or partial scans preserve the last complete report and do not claim the material is empty. An empty state appears only after the entire subtree has been read successfully, offering lesson uploads or another Drive folder. Non-video files are counted separately. Previously linked files absent from a complete scan become review items; existing links and approvals are not automatically removed.

Load a curriculum, then use **اختيار / تغيير المجلد** to select its existing subject folder inside the configured Drive root. This saves an explicit folder ID and ancestor-ID chain for that year, semester, grade, and subject. Labels may differ from curriculum labels. Renaming folders or adding duplicate siblings does not invalidate the binding; moving the bound branch or changing the root requires review. Binding a folder does not rename, create, move, delete, or share anything in Drive.

If the subject folder is missing, a Drive administrator can browse to the intended parent (usually the grade folder) and use **إنشاء مجلد المادة هنا**. The folder name comes from the selected curriculum, not browser input. The server verifies the parent is inside the configured root, checks every child-list page for an existing or Arabic-normalized equivalent folder name, and serializes creation with upload provisioning. Existing names disable creation and expose **فتح المجلد الموجود**; being inside the subject folder also prevents creating a nested duplicate. The same subject name in a different grade is allowed. After creation, the picker opens the new folder for explicit binding; existing video links are preserved. This action uses the existing `OLAMA_MEDIA_REVIEWED_FOLDER_APPLY_ENABLED` gate and never enables legacy or unrestricted folder creation.

Both upload transports use a verified binding directly, while retaining the existing safe automatic preparation path for older/unbound scopes. Unit destinations still require an unambiguous existing unit name or safe creation beneath the subject. Dedicated unit selection and historical data cutover are deferred.

Each lesson has **اختر من Drive**. The picker browses live, paginated results within the bound subject subtree, previews videos, accepts an optional part number, and revalidates the selected file server-side. A video already active on another lesson cannot be silently reassigned. Upload finalization and picker linking share an idempotent attachment method that preserves existing approvals on retry. Explicit unlinking marks the association dismissed so refresh does not recreate it.

The main **فحص ومزامنة** action uses the same binding. It runs one Drive page per AJAX request and applies only unique exact normalized title matches after a complete traversal. Number disagreements, duplicate target candidates, occupied lesson/part slots, and repeated titles require manual selection. New links remain pending approval. Refresh is additive: it does not infer deletion or mark unseen files missing, including after an interrupted scan. This deliberately favors keeping existing links over automatic removal. Server-side refresh sessions expire after 30 minutes; a new refresh can safely be started. Subject/file limits produce explicit errors rather than partial-removal conclusions.

Loading lessons reads local data only and never triggers a scan. Secondary video actions use a compact menu; the original multi-stage tools remain collapsed under the maintenance section in **إعدادات Drive** for administrators.

Drive settings now provides visible controls for **تفعيل المزامنة واختيار الفيديوهات من Drive** and **تفعيل رفع الفيديوهات إلى Drive**. Saving reloads the page to apply the feature state to the interface. Existing sites default to uploads enabled and scoped sync disabled until selected by an administrator. These settings reuse the existing options record and preserve OAuth credentials when unchanged. Explicit `OLAMA_MEDIA_DRIVE_SYNC_ENABLED` / `OLAMA_MEDIA_DRIVE_UPLOAD_ENABLED` definitions in wp-config.php remain authoritative deployment overrides; affected controls show their effective state and explain why they are locked. The plugin no longer defines those two constants itself. The obsolete legacy sync engine and unrestricted nested-folder creation API have been removed. Folder selection remains available to Drive administrators without enabling either feature. This workflow adds no tables and performs no bulk migration. The broader migration specification is in [docs/module-redesign.md](docs/module-redesign.md).

Validation: run each `tests/*-test.php` script with PHP, plus `node --check assets/js/media-library-admin.js` and `node --check assets/js/media-workspace.js`. `workspace-workflow-test.php` covers explicit bindings, ancestor renames, duplicate siblings, picker boundaries, attachment retries, conflicting candidates, and interrupted/repeated refreshes. Live OAuth, sharing, playback processing, and browser upload recovery still require a running WordPress site and a connected Drive account.

## Retired code and compatibility

The unused `olama_media_sync_drive` and `olama_media_v2_match_subject` AJAX actions, their matching/write engines, and the unrestricted `get_or_create_nested_folder*` APIs are removed. `OLAMA_MEDIA_LEGACY_SYNC_ENABLED` and `OLAMA_MEDIA_DRIVE_FOLDER_CREATION_ENABLED` no longer control any operation; old definitions can be removed from deployment configuration. Safe upload folder preparation and explicit reviewed folder creation retain their own gates. Lesson loading has no automatic-sync option.

Unused upload-session creation, destructive pending-link cleanup, identity lookup, URL parsing, translations, frontend state, and obsolete style selectors were removed. OAuth now requests Drive access only; the unused email scope is no longer requested. Existing tokens do not need to be revoked for this cleanup.

Legacy assets, resumable upload recovery, maintenance and migration tools, and `Olama_Media_Guardian_Library::for_curriculum()` remain supported. The student gateway still consumes that public read API. No stored data or Drive files are deleted by this cleanup.

## Guardian video library service (2.1.0)

`Olama_Media_Guardian_Library::for_curriculum()` exposes a minimal read model for family/student portals. It returns only active, approved curriculum video links (plus approved, ready legacy assets) for an explicit academic year, semester, grade, and subject list. It excludes pending matches, Drive identifiers, uploader data, notes, jobs, and diagnostics.

Standalone media library plugin for Olama School curriculum videos.

## Drive v2

Version 2 separates Drive discovery from curriculum linking. Recursive scans populate `olama_drive_files`; the binding-first workspace attaches confirmed matches to `olama_lesson_video_links`. A lesson may have multiple ordered video links, including parsed Arabic or English part numbers. Sync runs and detailed events are stored independently, and legacy media tables remain untouched for compatibility.

Administrators use **����������** for scoped synchronization and per-file decisions. Full inventory, staged reconciliation, legacy imports, and v2 index reset remain in the collapsed maintenance tools under **������� Drive**. The lesson upload page uses active v2 links as its primary media source, while successful new uploads continue writing legacy records and also register their v2 manifest/link records.

The link-check workflow supports both a complete safe Drive inventory and a folder-specific safe inventory for the selected academic year, semester, grade, and subject. A folder-specific run rescans the selected subject subtree, preserves unaffected observations from the latest completed inventory, and publishes the merged result as the next complete inventory snapshot. Both modes are read-only in Google Drive.

Folder-tree preview compares both the complete normalized unit name and its descriptive topic. When a candidate such as `حرف الفاء` has different sequence wording, Stage 3 requires an explicit administrator decision: approve it as-is, stage a rename to the catalog name, or reject the candidates and stage creation of the catalog folder. No Drive change occurs during review. The final guarded apply revalidates every direct child, then performs only the reviewed creates and renames; it never moves or deletes. Stage 3 also provides an explicit guarded transition into the Stage 4 lesson-matching preview.

## Curriculum Video Coverage Report

Administrators can open the coverage report to review every lesson in an academic year and semester. The report can be narrowed by grade and subject, marks uploaded and missing videos, and calculates coverage for each curriculum, subject, grade, and the full filtered curriculum set. A lesson is covered when it has a linked asset whose Drive upload completed successfully.

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

Both direct and WordPress-streamed uploads prepare the selected lesson's folder branch before creating the video/upload session. The server verifies curriculum IDs and names, walks the configured root → academic year → semester → grade → subject → unit in Drive, reuses unique existing folders, and creates only the missing branch. It saves the subject mapping by the returned Drive ID and revalidates folder parents. Uploads do not require an inventory scan or manual folder-plan execution first.

Preparation stops for duplicate/similar candidates, changed or unconfirmed existing mappings, invalid curriculum scope, inaccessible folders, or a root configuration change. It never renames, moves, or deletes existing Drive items. Root and subject locks serialize uploads and reviewed folder plans; retrying a partially completed preparation reuses the folders already created. Folder preparation uses the upload feature gate, and has no unrestricted folder-creation fallback. Inventory and reviewed folder plans remain available for reconciliation and resolving ambiguous paths.

An inventory revalidation flag or old root hash does not by itself block an upload: the same subject Drive ID can be revalidated against the full live path and its mapping renewed with the previous evidence retained. Older automatic mappings that incorrectly interpreted first/second secondary as grades one/two can also be corrected, but only when live metadata proves the old subject, grade, semester, year, and current root. Manual folder choices, inaccessible paths, other scope mismatches, and duplicate destinations still require review. Corrections preserve all existing Drive content and use a conditional database update so concurrent mapping changes are not overwritten.

Normal curriculum loading reads the local database only. It does not call Google Drive.

Administrators can use the Drive sync tool in the Migration tab to reconcile videos that already exist in the configured curriculum folders. It performs exact normalized filename matching against lesson titles, supports a dry run, refuses ambiguous matches, and is safe to repeat.

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

## Phase 2.4 Upload Visibility

Each lesson row now includes a persistent upload panel with summary, progress, details, and optional debug output. A global upload monitor lists active and recent uploads, including transport, status, percent, uploaded size, current stage, job UUID, and the latest message.

Admins can enable diagnostic details in the UI, copy safe failure diagnostics, and jump to filtered logs by job UUID. Completion and failure messages remain visible after the upload attempt so teacher support can see what happened without relying on browser console or backend logs alone.

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
