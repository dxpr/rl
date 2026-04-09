# RL Page Title — A/B test page titles via Drush CLI

A/B test Drupal page titles for any page (nodes, Views displays, custom
controllers, any path) using Thompson Sampling. Per-language scoping.

## Commands

### Discovery
- `drush rl:page-title:list` — List experiments (`--enabled=yes|no|all`)
- `drush rl:page-title:get <id>` — Full details + live arm stats

### Lifecycle
- `drush rl:page-title:create <path> --variants="A,B,C"` — Create
- `drush rl:page-title:update <id> --label="X"` — Update label/variants/enable/disable
- `drush rl:page-title:delete <id>` — Delete + purge analytics

### Common options
- `--variants="A,B,C"` or `--variants=A --variants=B` — Alternative titles
- `--label="..."` — Human-readable label
- `--langcode=es` — Per-language scope (default `und` = all languages)
- `--disabled` — Create as disabled
- `--dry-run` — Preview without applying

All commands output YAML. All state-changing commands support `--dry-run`.

## Concepts

- The original title is always tested as variant `v0` (read live).
- Stored variants are `v1`, `v2`, etc.
- Each `(path, langcode)` pair is its own experiment with independent
  Thompson Sampling state.
- Reward signal: user stayed on page 10 seconds (bounce-rate proxy).
- Lookups are indexed; tested at 10K+ experiments per site.

## Example

```bash
# A/B test the /blog page title with two alternatives.
drush rl:page-title:create /blog \
  --variants="News & Insights,Latest Articles" \
  --label="Blog index test"

# Check progress.
drush rl:page-title:get <id>

# Disable temporarily.
drush rl:page-title:update <id> --disable

# Delete + purge.
drush rl:page-title:delete <id>
```

The same admin UI is available at /admin/config/services/rl-page-title
via Views.
