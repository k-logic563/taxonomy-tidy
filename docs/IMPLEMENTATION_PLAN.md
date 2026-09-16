# Taxonomy Tidy — MVP Implementation Plan

## 1. Purpose

This document defines the implementation order for the Taxonomy Tidy MVP.

`REQUIREMENTS.md` is the authoritative source for product behavior, scope, safety, and acceptance criteria. This document does not replace or weaken those requirements. It divides the work into reviewable phases and defines what Codex may implement in each phase.

If this document conflicts with `REQUIREMENTS.md`, stop and ask for clarification before changing code or data.

## 2. Confirmed technical decisions

- Minimum WordPress version: `6.6`
- Minimum PHP version: `8.2`
- Supported database baseline: MySQL `8.0+` or MariaDB `10.11+`
- Primary local environment: Docker Compose
- Primary test matrix: PHP `8.2`, `8.3`, and `8.4`
- PHP `8.5` may be checked when practical, but is not required to complete the MVP
- Plugin name: `Taxonomy Tidy`
- Plugin slug: `taxonomy-tidy`
- PHP namespace: `TaxonomyTidy`
- Text domain: `taxonomy-tidy`

### Required capabilities

Every plugin screen and every server-side request that reads a stored operation or changes data must require:

- `manage_categories`
- `edit_others_posts`
- `edit_published_posts`

Mutating requests must also require a valid WordPress nonce.

### Categories with children

For the MVP, merging a source category that has child categories must not automatically reparent those children.

- Published standard-post relationships may be moved to the destination category.
- The source category must be retained while it has child categories.
- Existing parent-child relationships must remain unchanged.
- The preview and result must clearly explain why the source category was retained.

Automatic child-category reparenting is outside the MVP.

## 3. Rules for every phase

Before starting a phase, Codex must:

1. Read all of `REQUIREMENTS.md`.
2. Read all of this file.
3. Inspect the current repository and the results of completed phases.
4. Confirm that the requested phase is the next incomplete phase.
5. State the files and behavior expected to change.

During a phase, Codex must:

- Implement only the requested phase.
- Avoid speculative features and abstractions for excluded functionality.
- Keep data-safety checks on the server side.
- Add or update automated tests with each behavior change.
- Preserve unrelated work.
- Use WordPress APIs for mutations unless a documented reason requires isolated prepared SQL.
- Keep the persistence model limited to the MVP operations: rename, merge, delete, execution recovery, and undo.

At the end of a phase, Codex must:

1. Run all checks relevant to the phase.
2. Report changed files.
3. Report commands executed and their results.
4. Report behavior that was manually verified and behavior that remains unverified.
5. Report assumptions, risks, and unresolved questions.
6. Update the phase status in this document only after its completion criteria pass.
7. Stop and wait for approval before beginning the next phase.

Do not report a phase as complete when required checks were skipped or failed.

## 4. Phase status

| Phase | Name | Status |
|---|---|---|
| 1 | Development foundation and plugin skeleton | Completed |
| 2 | Persistence and operation state | Completed |
| 3 | Accurate taxonomy inventory | Completed |
| 4 | Plan validation and preview | Completed |
| 5 | Batched execution and recovery | In progress |
| 6 | History and undo | Not started |
| 7 | Admin workflow and usability | Not started |
| 8 | Compatibility, acceptance, and release candidate | Not started |

Allowed status values are `Not started`, `In progress`, `Blocked`, and `Completed`.

## 5. Phase 1 — Development foundation and plugin skeleton

### Goal

Provide a reproducible development environment and the smallest installable plugin skeleton.

### In scope

- Initialize the plugin source structure.
- Add the main plugin file and valid plugin headers.
- Add namespace-based autoloading through Composer.
- Add activation and deactivation hooks without creating speculative data structures.
- Add `Tools > Taxonomy Tidy` with a minimal placeholder screen.
- Apply the confirmed capability checks to the menu and page.
- Configure Docker Compose with WordPress, MySQL 8.0, and WP-CLI access.
- Mount the plugin source into the WordPress container.
- Configure PHPUnit and the WordPress integration-test foundation.
- Configure WordPress Coding Standards.
- Add JavaScript linting only if JavaScript is introduced in this phase.
- Add basic installation, bootstrap, and access-control tests.

### Out of scope

- Taxonomy tables and queries.
- Operation database tables.
- Rename, merge, delete, preview, execution, history, or undo behavior.
- Final admin UI styling.

### Required verification

- Docker environment starts successfully.
- WordPress installation can be completed reproducibly.
- The plugin activates without warnings or fatal errors.
- An authorized user can open `Tools > Taxonomy Tidy`.
- An unauthorized user cannot access the screen directly.
- PHPUnit and PHPCS commands execute successfully.

### Completion criteria

- A new developer can start the environment using documented commands.
- The plugin skeleton is active and testable.
- All Phase 1 checks pass.

## 6. Phase 2 — Persistence and operation state

### Goal

Persist the minimum data required to preview, execute, resume, audit, and undo MVP operations.

### In scope

- Define the minimum operation, operation-item, and change-journal schema.
- Create tables with `dbDelta()` and version the schema.
- Implement repositories for the required stored data.
- Implement explicit operation-state transitions.
- Implement an operation lock that prevents conflicting concurrent execution.
- Store enough item-level state to resume pending work safely.
- Add cleanup and retention behavior only when explicitly required for correct operation.

### Required operation states

```text
draft -> previewed -> running -> completed
                              -> partial_failed
                              -> failed

completed -> undo_previewed -> undoing -> undone
                                     -> undo_partial_failed
```

Equivalent internal names are acceptable only when the mapping is documented and behavior remains explicit.

### Constraints

- Do not build a general-purpose workflow engine.
- Do not store entire post contents.
- Do not add tables or fields solely for possible future features.
- Invalid state transitions must be rejected server-side.

### Required verification

- Fresh activation creates the expected schema.
- Repeated activation or migration is idempotent.
- Valid transitions succeed and invalid transitions fail.
- A second execution cannot acquire an active conflicting lock.
- Interrupted pending items remain discoverable for retry.

### Completion criteria

- The persistence model supports the remaining phases without implementing their business behavior.
- Schema and state tests pass.

## 7. Phase 3 — Accurate taxonomy inventory

### Goal

Display accurate category and tag information without changing any taxonomy data.

### In scope

- Separate category and tag views.
- Server-side pagination.
- Search by name or slug.
- Sort by name and published standard-post usage count.
- Filter globally unused terms.
- Show term name, slug, taxonomy, parent category, and counts.
- Calculate published `post` usage independently from `term_taxonomy.count`.
- Calculate total object relationships to distinguish:
  - used by published standard posts;
  - used only by excluded objects;
  - unused by every WordPress object.
- Isolate any prepared reporting SQL in the infrastructure layer.

### Constraints

- This phase is read-only.
- Category and tag operations must not be mixed.
- Counts used for safety decisions must come from tested queries.

### Required verification

- Published counts exclude drafts, private posts, scheduled posts, pages, and custom post types.
- Total relationship counts include excluded objects.
- Search, pagination, sorting, and filtering work independently for both taxonomies.
- The screen remains usable with approximately 100 categories and 1,000 tags.

### Completion criteria

- Administrators can accurately inspect the taxonomy state needed to plan cleanup.
- No mutation endpoints exist yet.
- Query and screen tests pass.

## 8. Phase 4 — Plan validation and preview

### Goal

Allow an administrator to configure rename, merge, and globally unused delete operations and preview the exact effects without changing data.

### In scope

- Create and edit a draft operation plan.
- Validate rename operations, including optional explicit slug changes.
- Validate same-taxonomy merge operations.
- Validate globally unused deletion for preview without a redundant checkbox; explicit confirmation remains required immediately before Phase 5 execution.
- Reject conflicting actions against the same term.
- Reject default-category deletion.
- Reject cross-taxonomy and self merges.
- Reject category merges into descendants or any circular hierarchy.
- Retain source categories that have children and explain that result.
- Generate and persist a normalized plan hash.
- Generate a relevant taxonomy-state fingerprint.
- Record the fixed published-post targets approved by the preview.
- Show affected published posts, excluded-object counts, deletion or retention behavior, errors, and warnings.
- Invalidate the preview when the plan changes.
- Reject stale previews when relevant taxonomy state changes.

### Constraints

- Preview generation must not rename terms, change relationships, or delete terms.
- State fingerprints should cover relevant terms and relationships without snapshotting unrelated site data.
- Post-title lists may be paginated or expanded progressively, but the approved IDs must be fixed server-side.

### Required verification

- Every validation rule in `REQUIREMENTS.md` has an automated test.
- Plan edits invalidate an earlier preview.
- Relevant changes made in another admin screen invalidate an earlier preview.
- Drafts and other excluded objects appear only as excluded counts and are never added to execution targets.
- Permission, nonce, sanitization, and escaping checks pass.

### Completion criteria

- A valid plan can move from draft to previewed.
- The preview truthfully describes the proposed changes.
- No taxonomy mutation is performed.

## 9. Phase 5 — Batched execution and recovery

### Goal

Execute an approved, current preview in bounded, retry-safe batches.

### In scope

- Require explicit execution confirmation.
- Revalidate the plan hash and relevant state immediately before starting.
- Process fixed targets in bounded authenticated requests.
- Rename terms through WordPress APIs.
- Merge published standard-post relationships while preserving unrelated assignments.
- Avoid duplicate destination relationships.
- Delete source terms only after a current, global safety check.
- Retain source terms used by excluded objects or acting as category parents.
- Delete only globally unused, explicitly confirmed terms.
- Journal actual changes required for history and undo.
- Show progress and truthful final states.
- Support safe retry or resume after interruption.

### Constraints

- A failed or incomplete batch must never be reported as completed.
- Retrying a processed item must not duplicate relationships or journal entries.
- Execution must not expand beyond the post IDs fixed by the approved preview.
- A last-moment conflict must retain data and produce a warning or failure state.

### Required verification

- Rename does not alter relationships.
- Merge preserves unrelated terms.
- Existing destination assignments are not duplicated.
- Source terms remain when excluded relationships or child categories exist.
- Default category and unsafe deletion checks cannot be bypassed.
- Interrupted batches can be resumed safely.
- Partial failures produce `partial_failed`, not `completed`.

### Completion criteria

- Approved operations can be applied safely to published standard posts.
- Results and failures are accurately recorded.
- All execution and retry tests pass.

## 10. Phase 6 — History and undo

### Goal

Expose completed operations and reverse supported changes without overwriting newer administrator work.

### In scope

- Operation-history list and detail views.
- Undo eligibility calculation.
- Undo preview as a separate recorded operation.
- Rename undo when current values still match the values applied by the original operation.
- Merge undo using the actual change journal.
- Safe recreation of deleted source terms when recorded attributes remain valid and conflict-free.
- Restore source assignments only to posts that originally had them.
- Remove destination assignments only when the original operation added them.
- Detect missing or changed posts, terms, slugs, names, and parents.
- Report full, partial, unavailable, and conflicted undo states.
- Execute undo in bounded, retry-safe batches.

### Constraints

- Undo must never overwrite newer administrator changes.
- The UI must not promise perfect reversibility.
- Conflicts must be reported rather than silently resolved.

### Required verification

- Rename undo succeeds in an unchanged state and stops on conflict.
- Merge undo handles posts that already had the destination before the merge.
- Deleted terms are recreated only when safe.
- Missing objects produce truthful partial or unavailable results.
- Interrupted undo can be retried safely.

### Completion criteria

- Supported completed operations can be previewed and undone safely.
- Conflict behavior is visible and tested.

## 11. Phase 7 — Admin workflow and usability

### Goal

Make the complete workflow understandable and usable within established WordPress admin patterns.

### In scope

- Finish the Categories, Tags, and History views.
- Provide the explicit configure -> preview -> confirm -> execute -> review flow.
- Make merge and delete actions visually distinct.
- Add inline plugin-screen notices for errors, warnings, and results.
- Improve row editing and progress updates with minimal JavaScript.
- Ensure server-side validation remains authoritative without JavaScript.
- Internationalize all user-facing strings with the `taxonomy-tidy` text domain.
- Escape every output according to context.
- Add accessible labels, keyboard operation, focus behavior, and meaningful status announcements.

### Required verification

- Complete each supported workflow manually from the WordPress admin UI.
- Verify behavior with JavaScript disabled where safety is concerned.
- Verify keyboard use and basic screen-reader semantics.
- Verify responsive usability at narrow admin viewport sizes.
- Verify error and partial-failure states, not only successful flows.

### Completion criteria

- The full MVP can be completed without database or command-line intervention.
- Safety warnings and operation states are understandable.
- UI-related automated and manual checks pass.

## 12. Phase 8 — Compatibility, acceptance, and release candidate

### Goal

Prove the MVP acceptance criteria and produce an installable release candidate.

### In scope

- Run the complete PHPUnit, integration-test, PHPCS, and JavaScript-lint suites.
- Test PHP 8.2, 8.3, and 8.4.
- Test WordPress 6.6 and the current stable WordPress release.
- Test MySQL 8.0 and, when practical, MariaDB 10.11 or later.
- Seed and test approximately 100 categories, 1,000 tags, and at least 300 published posts, together with excluded statuses and post types.
- Perform a clean-install test.
- Perform an upgrade/schema-idempotency test.
- Verify activation, deactivation, and uninstall behavior.
- Run all acceptance criteria from `REQUIREMENTS.md`.
- Create production plugin files, `readme.txt`, changelog, license information, and installation instructions.
- Build an installable ZIP without development-only files.
- Install the ZIP into a fresh WordPress environment and repeat the smoke test.

### Release boundary

This phase creates a release candidate. Publishing to WordPress.org, GitHub Releases, or another public service requires separate explicit approval.

### Required verification

- All automated checks pass without unexpected skips.
- The large-data scenario completes without request timeout or data corruption.
- A database comparison confirms excluded objects and unrelated relationships are unchanged.
- The packaged ZIP installs and activates cleanly.
- Known limitations and unverified combinations are documented.

### Completion criteria

- Every MVP acceptance criterion passes.
- The release candidate ZIP is reproducible and manually smoke-tested.
- Remaining risks are documented clearly enough for a release decision.

## 13. Phase start prompt template

Use the following prompt when starting each phase:

```text
Read REQUIREMENTS.md and docs/IMPLEMENTATION_PLAN.md completely before making changes.

Implement Phase <NUMBER> only.
Do not begin a later phase, even if it appears convenient.

Before editing, inspect the current repository and confirm that the preceding phases are complete. Then summarize the Phase <NUMBER> scope, expected files, and verification plan.

Implement the phase in small, reviewable changes and add the required tests. Run all checks relevant to the phase.

At completion, report:
- files created or changed;
- implementation decisions;
- commands and test results;
- manual verification performed;
- anything not verified;
- remaining risks or questions;
- whether every Phase <NUMBER> completion criterion passed.

Update the Phase <NUMBER> status in docs/IMPLEMENTATION_PLAN.md only if all completion criteria pass. Stop after reporting and wait for approval before starting the next phase.
```

## 14. Final safety rule

When an ambiguity could change or delete stored WordPress data, preserve the data, stop the affected operation, report the ambiguity, and ask for a decision. Convenience must not override the safety requirements.
