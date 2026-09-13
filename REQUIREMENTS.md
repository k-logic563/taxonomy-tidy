# Taxonomy Tidy — MVP Requirements

## 1. Project overview

Taxonomy Tidy is a WordPress admin plugin for safely organizing categories and tags in bulk.

The problem it solves is that WordPress's standard taxonomy screens become difficult to use when a site has dozens or hundreds of inconsistent categories and tags. An administrator should be able to review the current taxonomy structure, rename terms, merge duplicate terms, delete unused terms, and reassign affected posts without leaving the WordPress admin UI.

The MVP must prioritize predictable behavior, previewability, and recovery over automation.

## 2. Product identity

- Plugin name: `Taxonomy Tidy`
- Plugin slug: `taxonomy-tidy`
- PHP namespace: `TaxonomyTidy`
- Text domain: `taxonomy-tidy`
- Admin menu location: `Tools > Taxonomy Tidy`

## 3. MVP goal

Allow a WordPress administrator to safely perform the following workflow:

1. Inspect all standard categories or tags in a table.
2. Define rename, merge, or delete operations.
3. Preview exactly what will change.
4. Apply the changes to published posts only.
5. Review the execution result.
6. Undo a completed operation when possible.

## 4. Scope

### Included

- Standard WordPress posts (`post` post type).
- Standard categories (`category` taxonomy).
- Standard tags (`post_tag` taxonomy).
- Published posts (`publish` status) as the only posts whose term relationships may be changed.
- Category and tag listing.
- Term name changes.
- Optional term slug changes.
- Same-taxonomy term merging.
- Deletion of unused terms.
- Dry-run preview before execution.
- Batched execution.
- Operation history.
- Undo of supported completed operations.

### Excluded

- Pages and custom post types.
- Custom taxonomies.
- Draft, private, pending, scheduled, trashed, and auto-draft posts.
- AI-based classification or content analysis.
- Automatic taxonomy cleanup without administrator approval.
- CSV import or export.
- Per-post taxonomy editing.
- Scheduled cleanup.
- Category-to-tag or tag-to-category conversion.
- Multisite network-wide operations.
- Automatic redirects for old taxonomy archive URLs.
- WooCommerce-specific behavior.

Do not implement excluded functionality unless the requirements are explicitly changed.

## 5. Functional requirements

### 5.1 Taxonomy selection

The screen must provide separate views for:

- Categories
- Tags
- Operation history

Category and tag operations must never be mixed in one merge operation.

### 5.2 Term list

For each term, show:

- Selection control
- Term name
- Slug
- Taxonomy type
- Parent category, when applicable
- Number of published posts using the term
- Proposed action
- Proposed destination or new value

The published-post usage count must not rely solely on the stored `term_taxonomy.count` value when that value could include post statuses outside the MVP scope. Counts used for previews and safety decisions must reflect published standard posts.

The list should support:

- Pagination
- Search by name or slug
- Sorting by name and published-post usage count
- Filtering unused terms

### 5.3 Rename

An administrator may change a term's display name.

- Changing the slug must be optional and explicit.
- A rename must be rejected if it would result in an invalid or conflicting term state.
- Renaming a term must not alter its post relationships.

### 5.4 Merge

An administrator may merge one or more source terms into one existing destination term within the same taxonomy.

For every published standard post assigned to a source term:

- Assign the destination term if it is not already assigned.
- Remove the source term assignment.
- Preserve all unrelated term assignments.
- Do not create duplicate relationships.

After all eligible published-post relationships are moved, the source term may be deleted only when doing so cannot affect excluded posts. If a source term is still assigned to a draft, private, scheduled, pending, trashed, auto-draft, page, or custom post type, the preview must report this and the source term must not be deleted automatically.

A category cannot be merged into one of its own descendants. The plugin must prevent invalid or circular category hierarchies.

### 5.5 Delete unused terms

For the MVP, a term is safe to delete only when it has no relationships to any WordPress object, including objects outside the published-post scope.

- Show whether a term is unused by published posts but still used elsewhere.
- Do not describe such a term as globally unused.
- Require explicit confirmation before deletion.
- Never delete the site's default post category.

### 5.6 Preview

No rename, merge, relationship change, or deletion may occur before a preview is generated.

The preview must show:

- Each proposed operation.
- Source and destination terms.
- Number of published posts that will change.
- Number of excluded objects that will not change.
- Whether a source term will be deleted or retained.
- Validation errors and warnings.
- A sample or expandable list of affected post IDs and titles.

If the stored plan changes after preview generation, the previous preview must become invalid and execution must require a new preview.

### 5.7 Execution

- Execution must require an explicit confirmation action.
- Process large changes in bounded batches to avoid request timeouts.
- Make every batch safe to retry without duplicating relationships or corrupting state.
- Display progress and a final result summary.
- A failed batch must not be reported as a successful completed operation.
- Preserve enough state to resume or safely retry an interrupted operation.

### 5.8 History and undo

For each execution, record:

- Operation ID
- Administrator user ID
- Start and completion timestamps
- Requested changes
- Actual changes
- Result status
- Errors and warnings
- Data required for undo

Undo must:

- Show a preview before applying.
- Restore recorded term relationships where the referenced objects still exist.
- Restore renamed term values when doing so does not conflict with current data.
- Recreate deleted source terms only when their necessary attributes were recorded and recreation is safe.
- Stop and report conflicts instead of overwriting newer administrator changes.

The UI must not promise that every operation is always fully reversible. It must clearly report partial or unavailable undo states.

## 6. Admin UI requirements

- Follow established WordPress admin visual patterns.
- Remain usable with approximately 100 categories and 1,000 tags.
- Use plain, action-oriented Japanese-ready labels through WordPress internationalization functions.
- Make destructive actions visually distinct.
- Do not use ambiguous actions such as a single immediate `Clean up` button.
- Keep the workflow explicit: configure, preview, confirm, execute, review.
- Show notices within the plugin screen instead of relying only on transient global notices.

Suggested primary actions:

- `変更内容を確認`
- `整理を実行`
- `変更を取り消す`

## 7. Permissions and security

- Restrict access to users with `manage_categories` and the required post-editing capability.
- Check capabilities again for every mutating request.
- Protect mutating requests with WordPress nonces.
- Sanitize all incoming values.
- Escape all rendered values according to output context.
- Use WordPress APIs for term and relationship mutations whenever practical.
- Use prepared queries for any direct database access.
- Do not expose post or taxonomy data through unauthenticated endpoints.

## 8. Data integrity rules

- Never modify excluded post statuses or post types.
- Never delete the default category.
- Never merge terms across taxonomies.
- Never silently change a slug.
- Never silently delete a source term that remains used by an excluded object.
- Never remove unrelated category or tag assignments from a post.
- Never execute a stale preview.
- Treat partial failure as an explicit operation state.
- Avoid relying on user-visible names as stable identifiers; use term and taxonomy IDs internally.

## 9. Technical direction

- Use object-oriented PHP under the `TaxonomyTidy` namespace.
- Keep WordPress hooks and bootstrapping separate from domain logic.
- Separate planning/validation, preview generation, execution, and undo responsibilities.
- Prefer WordPress core APIs over direct SQL mutations.
- Any direct SQL used for accurate counting or reporting must be isolated and tested.
- JavaScript may enhance the admin UI, but core safety checks must remain server-side.
- All user-facing strings must be translatable with the `taxonomy-tidy` text domain.
- Do not add a frontend-facing feature or modify the public theme output.

A suggested internal structure is:

```text
taxonomy-tidy.php
src/
  Admin/
  Application/
  Domain/
  Infrastructure/
assets/
  css/
  js/
tests/
```

This structure is guidance, not a requirement when a simpler design remains maintainable.

## 10. Testing requirements

At minimum, cover:

- Published-post usage counts excluding drafts and other statuses.
- Rename without relationship changes.
- Merge into an existing destination.
- Merge when a post already has the destination term.
- Merge source used by both published and excluded posts.
- Default category deletion prevention.
- Cross-taxonomy merge prevention.
- Circular category hierarchy prevention.
- Retry of an interrupted batch.
- Stale-preview rejection.
- Permission and nonce failures.
- Undo after rename.
- Undo after merge.
- Undo conflict caused by a later administrator change.

## 11. MVP acceptance criteria

The MVP is complete when an administrator can:

1. Open `Tools > Taxonomy Tidy`.
2. View accurate published-post counts for standard categories and tags.
3. Configure a valid rename, merge, or globally-unused-term deletion.
4. Preview all affected published posts and excluded-object warnings.
5. Execute the approved plan without changing drafts or other excluded objects.
6. See a truthful success, partial-failure, or failure result.
7. Review the operation in history.
8. Preview and perform a supported undo without overwriting conflicting newer changes.

All automated tests and WordPress coding-standard checks configured for the project must pass.

## 12. Implementation rules for Codex

- Read this file before planning or editing the plugin.
- Treat the MVP scope and exclusions as binding.
- Do not add speculative features.
- Do not weaken safety behavior to simplify implementation.
- Before editing, inspect the existing repository structure and conventions.
- Preserve unrelated existing changes.
- Implement in small, reviewable increments.
- Add or update tests with each behavior change.
- Run the relevant tests and checks before reporting completion.
- Report assumptions, remaining risks, and anything not verified.
- If a requirement is ambiguous and the choice affects stored data or destructive behavior, stop and ask instead of guessing.

