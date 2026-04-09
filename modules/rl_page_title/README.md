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

A config entity per experiment, `rl_page_title_experiment`, stores:

- `path` - the internal path being tested (e.g., `/node/42`, `/blog`,
  `/user/login`). Aliases are resolved to internal paths on save.
- `variants` - the alternative title strings.
- `enabled` - whether the experiment is currently running.

The original title (whatever Drupal would normally render at that path) is
always tested as **arm v0** and is read live - it is **not** stored on the
experiment entity. Stored variants are arms v1, v2, ... vN.

The RL experiment ID is a deterministic hash:
`rl_page_title-{12-char-sha1-of-path}`. The hash avoids collisions between
paths like `/foo/bar` and `/foo_bar` that naive sanitization would conflate.

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

Kernel tests live under `tests/Kernel/`:

- `PageTitleVariantSelectorTest.php` - selector behavior with enabled
  and disabled experiments, path normalization, per-request caching.
- `PageTitleDecoratorTest.php` - decorator output for v0/v1/vN and
  unknown experiments.
- `PageTitleEntityPredeleteTest.php` - entity-delete cleanup of both
  the config entity and the RL analytics tables.

Run via `docker compose --profile test run phpunit-tests` from the rl module
root.
