# RL Menu Link

A/B test menu link labels using Thompson Sampling. Works for both
`menu_link_content` entities (user-created menu links) and YAML-defined
menu links from contrib/custom modules.

## What it does

You give it alternative labels for a menu link. It rotates them across menu
renders using Thompson Sampling, records impressions when the link is
visible and rewards when the link is clicked, and converges on the
best-performing label.

## How it works

### Storage

A config entity per experiment, `rl_menu_link_experiment`, stores:

- `menu_link_plugin_id` - the menu link plugin ID being tested. For
  `menu_link_content` entities this looks like
  `menu_link_content:abc-uuid`. For YAML-defined links it is the link's
  machine name (e.g., `system.admin_content`).
- `variants` - the alternative label strings.
- `enabled` - whether the experiment is currently running.

The original label is always tested as **arm v0** and is read live from the
menu link manager - it is **not** stored on the experiment entity. Stored
variants are arms v1, v2, ... vN.

The RL experiment ID is a deterministic hash:
`rl_menu_link-{12-char-sha1-of-plugin-id}`.

### Runtime

1. `hook_preprocess_menu()` walks the menu tree, looks up an active
   experiment for each item's plugin ID, and swaps `$item['title']` with
   the winning variant. It also injects `data-rl-ml-experiment-id` and
   `data-rl-ml-arm-id` attributes onto the rendered anchor so the tracking
   JavaScript can match anchors precisely.
2. The preprocess hook attaches `rl_menu_link:all` plus per-plugin-id cache
   tags so saving an experiment can invalidate the cached menu output
   immediately.
3. `js/menu-tracking.js` uses `IntersectionObserver` to record a turn
   (impression) when a tracked anchor enters the viewport, and a click
   listener to record a reward when the user clicks. Each visit and each
   click is its own event - there is no per-session cap, so repeat
   visitors do not depress the conversion signal.
4. Both events are POSTed via `navigator.sendBeacon()` to `rl.php`.

### UX flows

- **menu_link_content entities**: vertical tab "Label variants" on the
  menu link edit form (advanced tabs group), inline textarea editing.
- **YAML-defined menu links** (e.g., `system.admin_content`): standalone
  admin form at `/admin/config/services/rl-menu-link/add` with a
  textfield for the plugin ID. The form validates that the plugin ID is
  registered with the menu link manager.
- **Editing**: same vertical tab on the menu link edit form, or the
  admin list at `/admin/config/services/rl-menu-link`.
- **Deletion**: dedicated delete confirmation form that purges the RL
  analytics tables before removing the config entity.

## Configuration

There is no settings form. The page cache TTL override (60 seconds while
an experiment is active) is hardcoded as a class constant. Reward signal
is "user clicked the link", which is the natural conversion signal for
menu links and does not need configuration.

## Permissions

- `administer rl menu link experiments` - create, edit, delete
  experiments. Restricted access.

## Cache invalidation

Saving or deleting an experiment invalidates two cache tags:

- `rl_menu_link:all` - attached to every rendered menu, so any first-time
  experiment invalidates all cached menu output.
- `rl_menu_link:{plugin_id}` - attached to menus that contain the
  affected link, for more targeted invalidation when the experiment
  already existed.

## Known limitations

- **Click reward double-counting prevention**: a single click event is
  guarded by a per-anchor data attribute that clears immediately after
  the event finishes propagating. Subsequent clicks in the same page load
  do count, by design - the goal is to track engagement, not session-level
  uniqueness.
- **State divergence on the vertical tab path**: identical to the
  rl_page_title note - inline experiment writes happen after the parent
  menu link save commits, so a write failure is logged but does not roll
  back the parent save.
- **YAML link plugin IDs are not autocompleted**: the standalone form
  takes the plugin ID as a textfield with examples in the description.
  Adding an autocomplete is a Phase 2 enhancement.

## Tests

Kernel tests live under `tests/Kernel/`:

- `MenuLinkVariantSelectorTest.php` - selector behavior with
  enabled and disabled experiments, plugin ID normalization.
- `MenuLinkDecoratorTest.php` - decorator output for v0/v1/vN.
- `MenuLinkEntityPredeleteTest.php` - entity-delete cleanup of
  both the config entity and the RL analytics tables.

Run via `docker compose --profile test run phpunit-tests` from the rl
module root.
