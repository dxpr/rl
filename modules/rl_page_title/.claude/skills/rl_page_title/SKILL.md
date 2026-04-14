---
name: rl_page_title
version: 1.0.0
description: >
  A/B test page titles for any Drupal page (nodes, Views displays, custom
  controllers, any path) using Thompson Sampling. Per-language scoping.
  Manage experiments via Drush CLI.
triggers:
  - /rl-page-title
  - page title test
  - title experiment
  - title variant
  - a/b test page title
  - test page title
  - rl_page_title
---

# RL Page Title — Drush CLI

You are managing A/B testing experiments for Drupal page titles. The
rl_page_title module is a content-entity-backed integration on top of
the parent rl module's Thompson Sampling engine.

## Preamble — Auto-discover Current State

```bash
# List all page title experiments with stats.
drush rl:page-title:list --format=yaml

# Filter to active experiments only.
drush rl:page-title:list --enabled=yes --format=yaml
```

## Commands Reference

| Command | Alias | Purpose |
|---|---|---|
| `rl:page-title:list` | `rl-ptl` | List experiments (`--enabled=yes\|no\|all`) |
| `rl:page-title:get <id>` | `rl-ptg` | Show full details + live RL stats per arm |
| `rl:page-title:create <path>` | `rl-ptc` | Create experiment (`--variants`, `--label`, `--langcode`, `--disabled`, `--dry-run`) |
| `rl:page-title:update <id>` | `rl-ptu` | Update label / variants / enable / disable (`--dry-run`) |
| `rl:page-title:delete <id>` | `rl-ptd` | Delete experiment AND purge RL analytics (`--dry-run`) |

All state-changing commands support `--dry-run`. All commands output YAML.

## Concepts

- **Path**: the internal Drupal path to test (e.g., `/node/42`, `/blog`,
  `/user/login`). Aliases are accepted and resolved on save.
- **Variant**: an alternative title text. The original title (whatever
  Drupal renders normally) is always tested as variant `v0`. Stored
  variants are `v1`, `v2`, etc.
- **Langcode**: experiments are scoped per language. Use `und` (the
  default, `LANGCODE_NOT_SPECIFIED`) for "all languages". Lookup tries
  language-specific match first, falls back to "all languages".
- **Reward**: a successful outcome (the user stayed on the page for 10
  seconds, default reward strategy). Tracked client-side via the
  bundled JS.

## Workflow Examples

### Test alternatives for a node title

```bash
# Create with two alternatives. The original /node/42 title is v0.
drush rl:page-title:create /node/42 \
  --variants="Learn About Our Team,Meet ACME"

# Wait for traffic, then check progress.
drush rl:page-title:get <returned-id>

# Pause without deleting if results are inconclusive.
drush rl:page-title:update <id> --disable

# Delete and purge analytics when done.
drush rl:page-title:delete <id>
```

### Test a Views page title in Spanish only

```bash
drush rl:page-title:create /blog \
  --variants="Nuestro Blog,Últimos Artículos" \
  --langcode=es \
  --label="Blog index (Spanish)"
```

### Use multiple --variants flags instead of comma-separated

```bash
drush rl:page-title:create /user/login \
  --variants="Sign In" \
  --variants="Log In to Continue" \
  --variants="Welcome Back"
```

### Test a custom module page (the form page itself)

```bash
drush rl:page-title:create /node/add/article \
  --variants="Create New Article,Write a New Story"
```

### Preview before applying

```bash
drush rl:page-title:create /blog \
  --variants="One,Two" \
  --dry-run
```

## How variants reach end users

1. The module's preprocess_page_title hook intercepts the rendered title
   for the matching path on every request.
2. The Thompson Sampling selector picks a winning variant based on
   accumulated turns and rewards.
3. The bundled tracking JS records a turn on page load and a reward if
   the user stays past the threshold (10s default).
4. Tens of thousands of experiments scale via indexed `(path, langcode)`
   lookups (content entities, not config).

## Notes

- The same page can have separate experiments per language, with
  independent Thompson Sampling state.
- Vertical tabs on entity edit forms also create page title experiments;
  the Drush commands operate on the same content entities.
- The admin UI is at `/admin/config/services/rl-page-title` (Views).
- Analytics are managed by the parent `rl` module; use `drush rl:list`
  and the `rl:experiment:*` commands to inspect raw turn/reward data.
- Deleting an experiment via this command purges the RL analytics
  tables (turns, rewards, snapshots, registry) atomically.
