# Phase 2 persistence model

Phase 2 adds only the storage needed by the MVP rename, merge, delete, recovery, history, and undo flows. It does not query or mutate terms, relationships, or posts.

## Tables

All names use the current site's WordPress table prefix.

### `wp_term_steward_operations`

One row represents an administrator-approved operation lifecycle.

- Identity: `id`, optional `parent_operation_id`, `user_id`, `taxonomy`
- State: `status`, `started_at`, `completed_at`, `created_at`, `updated_at`
- Preview identity: `plan_hash`, `state_fingerprint`
- Audit data: `requested_data`, `result_data`, `errors`, `warnings`
- Execution lease: nullable `lock_name`, `lock_token`, `lock_expires_at`

`taxonomy` is limited by the repository API to `category` or `post_tag`. A unique nullable `lock_name` allows only one active lease per taxonomy while allowing any number of unlocked operations.

### `wp_term_steward_operation_items`

One row represents a fixed unit of rename, merge, or delete work.

- Parent and identity: `id`, `operation_id`, `item_key`
- Work definition: `action`, `payload`
- Recovery state: `status`, `attempts`, `last_error`, timestamps

`(operation_id, item_key)` is unique. Items remain `pending` while an attempt runs and become terminal only after success or a recorded failure. A request interrupted before that terminal update therefore leaves the item discoverable for retry.

### `wp_term_steward_changes`

One row records an actual change and the snapshots required for history or undo.

- Parent and identity: `id`, `operation_id`, `item_id`, `change_key`
- Actual change: `change_type`, optional `object_id`, `before_data`, `after_data`
- Undo tracking: `created_at`, `undone_at`

`(operation_id, change_key)` is unique. Re-recording the same key returns the existing journal ID instead of creating a duplicate.

No post content is stored. JSON columns contain only structured operation metadata and before/after values required by later MVP phases.

## Operation state transitions

Normal operations and their separate Undo operations use these transitions:

```text
draft -> previewed -> running -> completed
                              -> partial_failed
                              -> failed

draft -> undo_previewed -> undoing -> undone
                                 -> undo_partial_failed
                                 -> failed
```

An Undo never advances the original operation through the Undo states. It creates a child operation whose `parent_operation_id` points to the immutable original operation. `started_at` is set when either normal execution enters `running` or Undo enters `undoing`; owner-scoped history therefore excludes draft and preview-only rows without guessing from status names.

Unlisted transitions throw an `InvalidStatusTransition` before any state update. Repository updates include both the operation ID and expected current status, so a concurrent state change cannot be silently overwritten.

## Lock behavior

- A lock applies to one taxonomy, not merely one operation row.
- Category and tag operations may hold independent locks.
- A second operation for the same taxonomy cannot acquire an active lock.
- A lease has a token and expiry time so an interrupted request does not block recovery indefinitely.
- Renewal and release require the current token.
- Deactivation does not delete operation history or schema.

## Schema lifecycle

The schema version is stored in the non-autoloaded `term_steward_schema_version` option. Activation runs `dbDelta()` for all three tables, and normal plugin loading applies the same idempotent migration when the stored version is outdated.
