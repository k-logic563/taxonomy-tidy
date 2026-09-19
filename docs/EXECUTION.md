# Phase 5 batched execution and recovery

Phase 5 applies only a current, administrator-approved Phase 4 preview. It implements rename, merge, and globally-unused deletion. Undo and the history UI remain outside this phase.

The operation-plan tab groups the current administrator's category and tag drafts for review. They remain separate operations, each with one taxonomy, plan hash, state fingerprint, lock, batches, and result. A single preview dialog displays both and has one execution button for the whole preview. Before either starts, every preview is validated and every taxonomy lock is reserved; a stale preview or unavailable lock stops the whole start request without starting either operation. The existing batch workflow runs once per operation in sequence, and the browser continues authenticated bounded requests after the one explicit click. After execution begins, each result remains independent. Terminal results close the preview and appear as an inline Japanese summary and in history.

The dialog is rendered and opened only in response to the explicit preview action or while that same execution request chain is actively progressing. A stored `previewed`, completed, or failed operation never opens it on GET, tab navigation, browser reload, or history navigation. An interrupted `running` operation is shown with an explicit resume action on the plan page instead of an automatically opened dialog.

## Request flow

1. An authenticated POST verifies the three required capabilities, nonce, taxonomy, operation owner, and operation status.
2. A `previewed` operation is checked against its normalized plan hash and current taxonomy-state fingerprint.
3. The fixed preview targets are expanded idempotently into operation items.
4. The operation transitions to `running` and processes at most 10 pending items in one request.
5. A subsequent authenticated request continues from pending items. Completed, failed, and skipped items are not selected again.
6. The operation becomes `completed` only when no item failed. Mixed results become `partial_failed`; an attempt with no successful or skipped item becomes `failed`.

`partial_failed` and `failed` are terminal in Phase 5. They are not automatically retried because doing so could overwrite a newer administrator change. Only an interrupted `running` operation is resumable.

## Locks and idempotency

Execution uses the existing taxonomy-scoped operation lease with a 60-second TTL. The token is renewed between items and only its owner can release it. Expired leases may be acquired safely after interruption.

Operation item keys and change-journal keys are unique per operation. Seeding, request replay, and journaling therefore reuse existing rows. WordPress state is checked before mutation, and an already-achieved merge or rename state can be journaled and completed without duplicating relationships.

## Fixed items and batch size

The batch size is `ExecutionWorkflow::BATCH_SIZE` (10). Merge work is split into one item per preview-fixed published standard post and a later source-finalization item. Drafts, private or scheduled posts, pages, custom post types, and other objects are never included as merge-post items.

## Source deletion safety

Immediately before deleting a merge source or explicitly deleted term, execution rechecks every WordPress object relationship, the default category, and category children. An unsafe merge source is retained and journaled as a warning. An unsafe explicit deletion fails without deletion.

## Change journal

The journal records actual outcomes with stable keys:

- `name_changed` and `slug_changed`;
- `destination_added` or `destination_existing`;
- `source_removed`;
- `source_deleted` or `source_retained`;
- `term_deleted` with the term snapshot required by a future Undo;
- `item_failed` with a stable safe error code.

Operation items separately retain attempts, terminal status, and the latest safe error code. A journal failure is never reported as successful.

## Phase 6 integration

Phase 6 reads the immutable actual-change journal plus its fixed operation-item context to determine Undo eligibility. It does not infer a mutation from the original plan. The Undo preview and execution start each recheck current terms, posts, relationships, names, slugs, and parents. A separate child Operation stores fixed inverse items, bounded progress, conflicts, and the inverse change journal; the original Operation remains unchanged as audit evidence. See `docs/UNDO.md`.

## Verification

The Phase 5 integration suite covers name-only rename, journal snapshots, bounded merge batches, continuation from pending items, a destination already assigned to a post, multiple sources sharing one post, preservation of unrelated assignments, excluded-object source retention, globally-unused deletion, stale fingerprints, changed plan hashes, terminal re-execution rejection, and `partial_failed` results after a between-batch conflict. Existing persistence tests cover lock contention, TTL expiry, token ownership, pending-item discovery, and idempotent journal keys. Controller tests cover the shared capability and nonce gate used by execution commands.

At the latest Phase 5 and Phase 6 verification, PHPCS checked 59 files with no errors or warnings, JavaScript lint passed, and PHPUnit passed 88 tests with 1,214 assertions. In the authenticated Docker administration screen, a dedicated tag was added to a rename plan, previewed, executed successfully through the modal, shown in history, previewed for Undo, and restored successfully through a separate Undo operation. Its name returned to the original value while its unchanged slug remained intact. The dedicated term and audit fixtures were removed after verification. Phase 5 is complete; Phase 6 verification details are recorded in `docs/UNDO.md`.
