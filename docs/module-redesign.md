# Media library redesign

Status: long-term design target. The first binding-first workspace release is implemented; see README.md for its behavior and verification. Historical data migration, guardian eligibility consolidation, unit-binding UI, replacement switching, and the other deferred capabilities below remain future work.

## Product objective

A teacher selects a curriculum lesson, uploads a video or chooses an existing Drive video, and sees a clear result. A supervisor previews and approves it. An administrator connects Drive and resolves exceptional folder problems. All three use the same video records and linking rules.

The curriculum remains owned by olama-school. This plugin owns folder bindings, video observations, lesson links, upload operations, review, and publication.

## Findings from the current implementation

- Direct resumable upload, streamed fallback, chunk recovery, and concurrent upload tracking already exist. Preserve these capabilities while separating them from the large AJAX controller.
- olama_curriculum_drive_map already stores folder IDs with curriculum scope. Extend and consolidate its use rather than adding Drive fields to olama-school tables.
- The upload resolver repeatedly lists and validates the named hierarchy, even with a confirmed subject binding. Explicit bindings should remove name ambiguity from the normal path.
- The main screen already has a sync action, but discovery, matching, folder provisioning, and reconciliation expose separate workflows.
- Lesson rendering combines legacy asset states with newer video links. Approval and publication have separate legacy and newer paths. Guardian publication currently applies different readiness checks to those paths; migration must define and verify the intended behavior explicitly.
- Migration feature gates exist. Preserve their effective configuration until an explicit, tested cutover replaces them.

## Everyday interface

### Curriculum workspace

Remember the selected year, semester, grade, and subject per user. Load lessons from the local database; never scan Drive as a side effect of viewing them.

Above the lessons, show:

- The selected subject and compact coverage counts: missing, awaiting review, published, needs attention.
- Drive connection/binding status with an administrator-only change-folder action.
- Upload videos and refresh from Drive actions. Refresh displays last successful refresh time and progress.

Group lessons by unit. Each lesson shows its title, videos, primary status, and primary action. Empty lessons offer Upload video and Choose from Drive. Linked videos offer Preview; authorized reviewers also get Approve. Reject, replace, unlink, reorder, and details live in a contextual menu. Rejection records a reason. Multiple parts remain supported and independently reviewable.

Use one primary status per video: Uploading, Processing, Awaiting review, Published, Rejected, or Needs attention. Empty is a lesson-level state. Show the reason and a recovery action when attention is needed. Mixed lessons show a compact count rather than implying all parts share one status.

The upload tray remains visible across filters inside the workspace and provides progress, cancel, retry, and specific recovery messages. Warn before leaving with active browser transfers. Reload recovery checks saved server operations; continuing bytes may require the user to reselect the same local file. Do not promise unattended uploads after closing the browser.

### Choose from Drive

Open a scoped dialog from a lesson. Default to its bound unit folder, or the bound subject folder when units are not configured. Provide breadcrumb navigation within the allowed subject subtree, search over indexed results, pagination, preview, part number, and existing-link indicators. Explain the scope of search; do not imply uncached folders were searched.

Show cached observations immediately with freshness information. Refresh from Drive explicitly. On selection, the server revalidates the file and curriculum scope before linking. Existing filenames need not change. Reusing a file already linked elsewhere requires an explicit decision and an authorized action; never silently move a link between lessons.

### Review and coverage

Provide an Awaiting review filter in the workspace with preview and approve/reject actions. Bulk approval operates on explicit selected video links and reports individual failures.

Coverage distinguishes video attached, awaiting review, and published. All counters use the same read model as the workspace and guardian library, with clearly stated coverage definitions.

### Administration

Settings contains connection health, curriculum folder bindings, sharing policy, and an advanced activity/recovery area. Teachers do not see reconciliation phases, rollout phrases, raw Drive IDs, or diagnostic logs in their normal flow. Connection failures offer an administrator recovery path and retain local curriculum access.

## Folder binding rules

1. Bind an exact folder ID to an explicit curriculum scope. A subject binding includes year, semester, grade, and subject; a unit binding includes the unit. Labels are display information, not identity.
2. Bindings belong to this plugin's mapping repository. Name aliases can suggest an initial choice but cannot override a confirmed ID.
3. A confirmed binding is authoritative. Validate its existence, folder type, access, and permitted scope using live metadata as needed. Duplicate or renamed siblings do not invalidate it.
4. Initial selection must prove the configured root boundary and scope. Explicitly selected names may differ from curriculum names. Changing the root invalidates the affected trust context and requires administrator review.
5. Create missing folders only within a validated bound parent. Serialize creation per scope and recheck after acquiring the lock. A timeout with an uncertain remote result must be reconciled before another create.
6. When no binding exists, offer Select existing folder or Create folder in context. Only an authorized administrator establishes arbitrary bindings. Teachers may trigger configured automatic creation beneath validated parents.
7. Do not recreate deleted or inaccessible bound folders automatically. Explain the problem and let an administrator repair the binding. Existing lesson links remain recorded.
8. Check the unit belongs to the selected subject scope on every mutation. A client-provided folder ID never authorizes an upload or link.
9. Existing units may be bound to the subject folder when a flat layout is intentional. Hierarchical folders are an organization choice, not a prerequisite for linking videos.

## One video lifecycle

Use these conceptual records, reusing suitable existing tables after schema review:

| Record | Responsibility |
| --- | --- |
| Folder binding | Curriculum scope, exact folder ID, parent boundary, verification and binding revision |
| Drive video | Unique Drive file ID, observed metadata, accessibility, processing state, last verified time |
| Lesson video link | Lesson and scope, video reference, part/order, active state, review state, review provenance |
| Operation | Upload/refresh/migration state, owner, idempotency key, progress, retry information |
| Activity event | Actor, operation, affected record, outcome and reversible local change evidence |

Upload and Choose from Drive both produce the same Drive-video and lesson-link records. Transport completion, playback availability, and review remain separate facts. The primary display status is derived from those facts.

Publication requires an active link, approval for that link, and the agreed playable/access policy. Approval alone cannot prove playback readiness. Preserve the guardian API shape while consolidating its eligibility rules. Define transient verification failure separately from confirmed removal so a network outage does not immediately unpublish the library.

Replacement creates a pending replacement link. Keep the currently published video available until the replacement qualifies for publication and an atomic local switch succeeds. Unlinking changes the local association; it does not delete the Drive file. No routine operation renames, moves, deletes, or changes sharing on existing Drive content implicitly.

## Upload and refresh behavior

### Upload

Validate actor, curriculum scope, file metadata, binding revision, and destination. Create a durable operation and reserve a known Drive file. Repeated initialization for the same operation returns the existing safe result rather than creating another file. Reconcile uncertain remote outcomes before retrying creation.

Use the existing direct resumable transport and explicit streamed fallback. The server owns destination selection and final metadata verification. Finalization is repeatable and creates exactly one canonical link. Completion of bytes followed by a database failure is recoverable without uploading again. Sharing failures are reported separately from transfer failures. Session URLs and credentials stay out of logs.

Cancellation stops the local operation; incomplete remote files are tracked for explicit cleanup rather than silently deleted. Session expiry offers a precise restart action. The queue uses bounded concurrency and preserves independent progress per file. Bulk upload first assigns each selected file to a lesson/part; uncertain assignments require a user decision.

### Refresh from Drive

Refresh only the selected bound subject subtree, in bounded resumable batches. Include nested units, paginate fully, and guard against cycles or unsupported shortcuts. Store the scan scope and binding revision. Apply disappearance conclusions only after a complete successful scan of that exact scope; failed or partial scans cannot mark unseen files missing.

Matching order: trusted plugin identity metadata with validated scope, unique exact scoped title matches, then suggestions. Lesson display numbers alone never authorize linking. Competing files for one lesson/part remain unresolved. Automatic links enter review; they never replace manual decisions or publish themselves. Deliberately rejected/unlinked suggestions are not recreated on each refresh without an explicit reset.

Show a result such as 8 linked for review, 2 need assignment, 1 unavailable. Open unresolved assignments in the current workspace. Record each automatic local mutation so an authorized user can undo that refresh's changes without reverting later edits. Discovery is read-only in Drive.

## Implementation boundaries

- Curriculum adapter: validates relationships and reads olama-school; no Drive operations.
- Folder binding service: resolves explicit destinations and handles initial selection/creation.
- Drive client: authentication, metadata, paginated listing, upload sessions, probing, and explicit permission operations.
- Upload service: operation lifecycle and shared finalization for both transports.
- Library service/repository: one authoritative link lifecycle, review, replacement, unlinking, and publication reads.
- Refresh service: scoped inventory and match proposals/unique automatic links.
- Thin request handlers: nonce, capabilities, input validation, service call, structured response.
- Workspace UI: curriculum, picker, review actions, upload tray, and actionable errors; advanced diagnostics loaded separately.

These are responsibilities, not a requirement for a framework or additional infrastructure. Use WordPress and the existing PHP/JavaScript stack. Start with bounded AJAX jobs; scheduled verification must tolerate delayed WP-Cron execution and have a manual refresh path.

Every mutation checks server-side capability and curriculum scope. Separate upload/link permission, review permission, and Drive administration. Reuse current capabilities where sufficient. Review permission must not grant arbitrary folder binding or destructive Drive access.

## Migration and delivery

1. Establish a baseline: current tests, installed schema/options, existing links and approvals, guardian response samples, and a restorable database backup before migration. Inspect live Drive read-only where connected access is available. Do not assume documentation proves production configuration.
2. Implement the canonical library service over current records and an explicit compatibility adapter. Add tests for eligibility, multi-part lessons, rejection, replacement, and duplicate finalization before routing writes through it.
3. Add binding-first resolution and contextual folder repair. Keep existing resolver available for unmigrated scopes while verifying explicit binding behavior.
4. Build the new workspace and scoped Drive picker against the shared service. Preserve upload recovery and existing permission boundaries.
5. Consolidate refresh and matching into the scoped workflow. Remove normal navigation to legacy reconciliation after its replacement handles equivalent exceptions.
6. Migrate existing data in resumable, repeatable batches with provenance. Deduplicate by Drive ID and lesson association, retain part order and review history, and report conflicting approvals for review. Do not derive approval from confidence scores. Never silently promote pending records.
7. Compare old/new read results and coverage for migrated scopes. Cut over reads and writes together per scope; avoid indefinite independent dual writes. Retain migration provenance and old rows during the verification period.
8. Rollback restores routing and reconciles post-cutover changes using provenance; a feature toggle alone is insufficient once new writes exist. Retire legacy endpoints/tables only after compatibility consumers and rollback obligations are resolved.

## Acceptance criteria

- A teacher can upload or select an existing video from a lesson without visiting settings or reconciliation after initial setup.
- A confirmed binding works with differently named and duplicate sibling folders; inaccessible or invalid bindings show an actionable repair state.
- A large transfer interrupted near completion can recover or finalize without duplicating the video/link.
- Two concurrent first uploads to a unit cannot create competing destination folders.
- A partial refresh never removes existing links or marks unvisited files missing.
- Repeated refreshes and repeated finalize requests are idempotent.
- Rejected/unlinked suggestions remain dismissed, and approved/manual links are not overwritten by matching.
- Pending replacement does not remove the existing published video.
- Guardian visibility, approvals, multi-part ordering, and scope isolation are verified across migration.
- Ordinary lesson loading performs no Drive scan. Drive failures preserve local curriculum browsing and offer a specific next action.
- Arabic RTL layout, keyboard operation, narrow screens, upload tray behavior, expired sessions, and live direct-upload CORS/recovery are checked before rollout.
