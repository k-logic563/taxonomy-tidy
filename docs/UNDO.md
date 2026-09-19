# Phase 6 operation history and Undo

Phase 6 exposes only operations that actually entered execution. The history query is owner-scoped, ordered by `started_at` and ID descending, and paginated in the database. Drafts and both normal and Undo preview-only records have no `started_at`, so they do not appear in normal history.

## Separate operation lifecycle

Undo never changes or deletes the original audit record. Preview creates a child operation with `parent_operation_id` pointing to the original. Its lifecycle is:

```text
draft -> undo_previewed -> undoing -> undone
                                 -> undo_partial_failed
                                 -> failed
```

The child becomes visible in history only after `undoing` sets `started_at`. A completed, running, or partially failed child prevents a second Undo of the same original. A preview-only child remains hidden and cannot authorize execution after its state fingerprint becomes stale.

## Eligibility and conflicts

Only actual, not-yet-undone journal entries from `completed` or changed `partial_failed` originals are candidates. Preview recalculates one of `full`, `partial`, or `none` from current WordPress data. It checks operation ownership, taxonomy, existing Undo attempts, journal completeness, term identity and values, slug/name collisions, category parent existence, published standard post state, and the exact source/destination assignments produced by the original operation.

Execution recalculates the same assessment and compares its plan hash and fingerprint before seeding any item. A changed state rejects the stale preview without starting Undo. Each later batch item rechecks its own current state, so changes made between batches become item conflicts rather than being overwritten. Preview-time and runtime conflict records preserve the target type, intended restored state, observed current state, reason, and retryability without exposing those raw snapshots in the normal UI.

## Inverse items

- Rename: name and slug are separate inverse items. Each field is restored only while its current value equals the value applied by the original operation; the other field is untouched.
- Deleted term: the saved name, slug, description, taxonomy, and category parent are validated before `wp_insert_term()`. WordPress must recreate the exact requested attributes. The new term ID and old-to-new mapping are written to the Undo journal.
- Merge relationship: only a journaled `source_removed` is restored. A destination is removed only when the same original item journaled `destination_added`; `destination_existing` is never removed.
- Delete: a complete saved term snapshot is required. A name/slug collision or missing category parent makes restoration unavailable rather than generating a substitute value.

Term recreation items are ordered before relationship items. Every inverse mutation and every conflict is written to the child journal. Successfully reversed original journal rows receive `undone_at`; the recorded before/after snapshots remain immutable.

## Batching and outcomes

Undo reuses the taxonomy-scoped 60-second lease and handles at most 10 fixed items per authenticated request. Pending items remain discoverable after interruption, while terminal items are never selected again. Stable item and journal keys prevent duplicate application on request replay.

All items succeeding produces `undone`. Any preview conflict or runtime item failure alongside successes produces `undo_partial_failed`. An attempt with no completed inverse item produces `failed`. No partial result is reported as complete. A partial or failed Undo detail shows the remaining count and that automatic retry is not available in Phase 6; Redo and an advanced retry UI remain outside the MVP.

## Verification

The integration suite covers owner-scoped started history, exclusion of drafts and preview-only operations, localized history output, detail access isolation, nonce enforcement, required Undo modal actions, field-specific rename restoration, later administrator rename conflicts, deleted-source recreation, new term ID mapping, restoration of original source assignments, preservation of pre-existing destinations, deletion slug conflicts, partial Undo, stale Undo previews, bounded batches, and continuation from pending items.

The final automated run passed PHPCS for 59 files, JavaScript lint, and 87 PHPUnit tests with 1,178 assertions. An authenticated Docker administration smoke test completed a dedicated rename through the normal plan preview and execution modal, then completed its Undo through history detail and the Undo preview modal. The unchanged slug was preserved. All dedicated term and audit fixtures were removed after the check.
