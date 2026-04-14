---
name: rl_menu_link
version: 1.0.0
description: >
  A/B test Drupal menu link labels using Thompson Sampling. Works for
  both menu_link_content entities and YAML-defined links. Per-language
  scoping. Manage experiments via Drush CLI.
triggers:
  - /rl-menu-link
  - menu link test
  - menu label test
  - menu variant
  - a/b test menu
  - test menu link
  - rl_menu_link
---

# RL Menu Link — Drush CLI

You are managing A/B testing experiments for Drupal menu link labels.
The rl_menu_link module is a content-entity-backed integration on top
of the parent rl module's Thompson Sampling engine.

## Preamble — Auto-discover Current State

```bash
# List all menu link experiments with stats.
drush rl:menu-link:list --format=yaml

# Filter to active experiments only.
drush rl:menu-link:list --enabled=yes --format=yaml
```

## Commands Reference

| Command | Alias | Purpose |
|---|---|---|
| `rl:menu-link:list` | `rl-mll` | List experiments (`--enabled=yes\|no\|all`) |
| `rl:menu-link:get <id>` | `rl-mlg` | Show full details + live arm stats + original label |
| `rl:menu-link:create <plugin>` | `rl-mlc` | Create experiment (`--variants`, `--label`, `--langcode`, `--disabled`, `--dry-run`) |
| `rl:menu-link:update <id>` | `rl-mlu` | Update label / variants / enable / disable (`--dry-run`) |
| `rl:menu-link:delete <id>` | `rl-mld` | Delete experiment AND purge RL analytics (`--dry-run`) |

All state-changing commands support `--dry-run`. All commands output YAML.

## Concepts

- **Plugin ID**: every menu link in Drupal has a plugin ID. For
  user-created `menu_link_content` entities it looks like
  `menu_link_content:abc-uuid` (the entity UUID). For YAML-defined links
  from contrib/custom modules it is the link's machine name (e.g.,
  `system.admin_content`, `user.page`).
- **Variant**: an alternative label text. The original label (whatever
  the menu link normally renders) is always tested as variant `v0`.
  Stored variants are `v1`, `v2`, etc.
- **Langcode**: experiments are scoped per language. Use `und` (the
  default, `LANGCODE_NOT_SPECIFIED`) for "all languages". Lookup tries
  language-specific match first, falls back to "all languages".
- **Reward**: a click on the tracked menu link.

## Workflow Examples

### Test alternatives for a content menu link

```bash
# Find the plugin ID first via the menu UI or:
drush ev "echo \\Drupal::entityTypeManager()->getStorage('menu_link_content')->load(1)->getPluginId();"

# Then create the experiment.
drush rl:menu-link:create menu_link_content:abc-uuid \
  --variants="Services,What We Do,Solutions" \
  --label="Services menu link test"
```

### Test a core admin menu link

```bash
drush rl:menu-link:create system.admin_content \
  --variants="Content,Manage Content,Edit Site"
```

### Test a Spanish-only variant

```bash
drush rl:menu-link:create menu_link_content:abc-uuid \
  --variants="Servicios,Lo Que Hacemos" \
  --langcode=es
```

### Use multiple --variants flags

```bash
drush rl:menu-link:create system.admin_structure \
  --variants="Structure" \
  --variants="Site Structure" \
  --variants="Layout"
```

### Preview before applying

```bash
drush rl:menu-link:create system.admin_content \
  --variants="One,Two" \
  --dry-run
```

### Check current state of an experiment

```bash
drush rl:menu-link:get <id>
# Returns: id, label, plugin id, original_label (read live from menu
# link manager), langcode, enabled, variants list, rl_experiment_id,
# total_turns, per-arm turns/rewards/rate.
```

## How variants reach end users

1. The module's preprocess_menu hook walks the menu tree on every
   render and looks up an experiment by each item's plugin ID.
2. Matching items get their `title` swapped with the Thompson Sampling
   winner, and `data-rl-ml-experiment-id` / `data-rl-ml-arm-id` data
   attributes are injected onto the rendered anchor.
3. The bundled tracking JS uses IntersectionObserver to record an
   impression when the link enters the viewport, and a click handler
   to record a reward.
4. Tens of thousands of experiments scale via indexed `(plugin_id,
   langcode)` lookups (content entities, not config).

## Notes

- The same menu link can have separate experiments per language.
- Vertical tabs on `menu_link_content` edit forms also create menu link
  experiments; the Drush commands operate on the same content entities.
- The admin UI is at `/admin/config/services/rl-menu-link` (Views).
- Analytics are managed by the parent `rl` module; use `drush rl:list`
  and the `rl:experiment:*` commands to inspect raw turn/reward data.
- Deleting an experiment via this command purges the RL analytics
  tables (turns, rewards, snapshots, registry) atomically.
