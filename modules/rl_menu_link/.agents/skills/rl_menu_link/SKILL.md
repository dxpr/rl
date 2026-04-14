# RL Menu Link — A/B test menu link labels via Drush CLI

A/B test Drupal menu link labels for menu_link_content entities and
YAML-defined links using Thompson Sampling. Per-language scoping.

## Commands

### Discovery
- `drush rl:menu-link:list` — List experiments (`--enabled=yes|no|all`)
- `drush rl:menu-link:get <id>` — Full details + live arm stats + original label

### Lifecycle
- `drush rl:menu-link:create <plugin_id> --variants="A,B,C"` — Create
- `drush rl:menu-link:update <id> --label="X"` — Update
- `drush rl:menu-link:delete <id>` — Delete + purge analytics

### Common options
- `--variants="A,B,C"` or `--variants=A --variants=B` — Alternative labels
- `--label="..."` — Human-readable label
- `--langcode=es` — Per-language scope (default `und` = all languages)
- `--disabled` — Create as disabled
- `--dry-run` — Preview without applying

All commands output YAML. All state-changing commands support `--dry-run`.

## Plugin ID format

- User-created menu links: `menu_link_content:abc-uuid`
- YAML-defined links: machine name like `system.admin_content`,
  `user.page`, `system.admin_structure`

## Concepts

- The original label is always tested as variant `v0` (read live from
  the menu link manager).
- Stored variants are `v1`, `v2`, etc.
- Each `(plugin_id, langcode)` pair is its own experiment with
  independent Thompson Sampling state.
- Reward signal: user clicked the tracked menu link.
- Lookups are indexed; tested at 10K+ experiments per site.

## Example

```bash
# A/B test the core "Content" admin link with alternatives.
drush rl:menu-link:create system.admin_content \
  --variants="Content,Manage Content,Site Content"

# Check progress.
drush rl:menu-link:get <id>

# Delete + purge.
drush rl:menu-link:delete <id>
```

The same admin UI is available at /admin/config/services/rl-menu-link
via Views.
