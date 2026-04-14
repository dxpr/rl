# RL Page Title

A/B test page titles for any page using Thompson Sampling. Source-agnostic:
works for nodes, Views displays, custom controllers, and any path Drupal serves.

## What it does

You give it alternative page titles for a path. It rotates them across visits
using Thompson Sampling, records impressions and engagement, and converges on
the best-performing title. The A/B testing math lives in the parent `rl`
module; this module is the integration layer that hooks into Drupal's title
rendering and stores the variant configuration.

## How it works

### Storage

A **content entity** per experiment, `rl_page_title_experiment`, stores:

- `path` - the internal path being tested (e.g., `/node/42`, `/blog`,
  `/user/login`). Aliases are resolved to internal paths on save.
- `langcode` - the language scope (specific language code, or
  `LANGCODE_NOT_SPECIFIED` for "all languages")
- `variants_data` - JSON-encoded list of alternative title strings
- `enabled` - whether the experiment is currently running

Indexed lookups on `(path, langcode)` keep selector latency constant
regardless of how many experiments exist on the site. Tested for sites with
tens of thousands of experiments.

The original title (whatever Drupal would normally render at that path) is
always tested as **arm v0** and is read live - it is **not** stored on the
experiment entity. Stored variants are arms v1, v2, ... vN.

The RL experiment ID is a deterministic hash:
`rl_page_title-{12-char-sha1-of-path-pipe-langcode}`. The hash includes the
langcode so each language gets its own Thompson Sampling state -- an English
experiment for `/blog` and a Spanish experiment for `/blog` accumulate
separate turns and rewards.

### Multilingual

Each (path, langcode) pair is its own experiment row, mirroring the Redirect
module's per-language redirects. The "all languages" fallback is langcode
`LANGCODE_NOT_SPECIFIED`. Lookup at runtime tries the current request
language first, then falls back to "all languages" if no language-specific
experiment exists.

We do **not** use Drupal's translation framework. Each language gets its
own row with its own variants list, its own enabled flag, and its own
analytics. This matches how Redirect handles multilingual.

### Runtime

1. Every page render is tagged with `rl_page_title:{path}` so creating an
   experiment can invalidate cached pages immediately, even pages cached
   before the experiment existed.
2. `hook_preprocess_page_title()` and `hook_preprocess_html()` look up an
   active experiment for the current internal path, ask the parent RL module
   for Thompson Sampling scores, and override the title with the winning
   variant. If the winning arm is `v0` (original), nothing is touched.
3. `hook_page_attachments()` attaches the tracking JavaScript when an
   experiment is active.
4. `js/title-tracking.js` records a turn (impression) on page load and a
   reward 10 seconds later (a bounce-rate proxy: if the user is still on the
   page after 10 seconds, the variant kept them).
5. Both events are POSTed via `navigator.sendBeacon()` to `rl.php`, the
   parent RL module's tracking endpoint.

### UX flows

- **Node titles**: vertical tab "Title variants" on node edit forms, in the
  advanced tabs group, with inline textarea editing.
- **Any path** (Views displays, `/user/login`, `/admin/content`, custom
  controllers): standalone admin form at
  `/admin/config/services/rl-page-title/add` accepting any path.
- **Editing**: same vertical tab on the entity form, or the admin list at
  `/admin/config/services/rl-page-title`.
- **Deletion**: dedicated delete confirmation form that purges the RL
  analytics tables (turns, rewards, totals, snapshots, registry) before
  removing the config entity. Purge happens first so a failure leaves the
  config entity intact as a recovery anchor.

The pattern is consistent with how the Redirect module handles in-context vs
standalone editing - inline UX where there is an obvious entity context,
standalone form for arbitrary paths.

## Configuration

There is no settings form. The reward strategy (10-second time-on-page) and
the page cache TTL override (60 seconds while an experiment is active) are
hardcoded as class constants. They can be promoted to module config in a
follow-up if site builders need tuning.

## Permissions

- `administer rl page title experiments` - create, edit, delete experiments.
  Restricted access.

## Trash module compatibility

On sites with the [Trash](https://www.drupal.org/project/trash) module
enabled, deleting a node sends it to the trash rather than removing it
from the database. The `hook_entity_predelete` cleanup that this module
relies on for orphan removal **only fires when the trashed node is
purged**, not on the initial soft-delete. This is correct semantically:
the trashed node still has the same internal path, so the experiment
remains valid until the node is permanently removed. Restored nodes
keep their experiment intact. Purged nodes trigger the standard
cleanup. Site builders running Trash should be aware that experiment
rows linger in the admin list for as long as their target node sits in
the trash bin.

## Known limitations

- **State divergence on the vertical tab path**: when the inline submit
  handler runs (after the parent entity has been saved), an experiment write
  failure is logged and surfaced to the user but does not roll back the
  parent save. This is acceptable because experiment writes are
  near-instantaneous and the failure is visible. A full fix would use the
  entity-builder pattern.
- **Vertical-tab retarget gap**: the inline form keys experiments by the
  entity's *current* canonical URL. If a node's canonical URL changes
  (Pathauto, manual alias edits) and you then re-edit the node via the
  vertical tab, you may see a fresh experiment for the new path while the
  old experiment for the old path becomes orphaned. The standalone
  admin form correctly purges on retarget via `loadUnchanged()`. Use the
  standalone form when retargeting an existing experiment.
- **First-time experiment cache invalidation**: pages that have been served
  before the rl_page_title module was installed will not carry the
  `rl_page_title:{path}` cache tag and will not invalidate when an
  experiment is created for them. They will refresh naturally when the page
  cache expires. After installation, all subsequent renders carry the tag.

## Tests

Coverage is provided by the parent rl module's e2e tests under
`scripts/e2e/`, which exercise install, experiment CRUD, and analytics
end-to-end against a real Drupal site. Run via
`docker compose --profile test run e2e-test` from the rl module root.
