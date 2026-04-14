#!/usr/bin/env bash
# E2E tests for rl:menu-link:* commands.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/_helpers.sh"

DRUSH="${DRUSH:-drush}"

section "rl:menu-link:create"

# Create against a known core menu link.
output=$($DRUSH rl:menu-link:create system.admin_content --variants="Content,Manage Content" --label="E2E test" 2>&1)
assert_has "create returns success" "success: true" "$output"
assert_has "create returns rl experiment id" "rl_menu_link-" "$output"

ENTITY_ID=$(echo "$output" | yq -e '.experiment.id' 2>/dev/null || true)
assert_not_empty "create returned entity id" "$ENTITY_ID"

# Duplicate (same plugin id + langcode) should fail.
output=$($DRUSH rl:menu-link:create system.admin_content --variants="Other" 2>&1)
assert_has "duplicate create returns error" "already exists" "$output"

# Different language for same plugin id should succeed.
output=$($DRUSH rl:menu-link:create system.admin_content --variants="Contenido" --langcode=es 2>&1)
assert_has "different language create succeeds" "success: true" "$output"

# Multiple --variants flags work.
output=$($DRUSH rl:menu-link:create system.admin_structure --variants="One" --variants="Two" 2>&1)
assert_has "multiple --variants flags accepted" "success: true" "$output"

# Unknown plugin id should fail.
output=$($DRUSH rl:menu-link:create not.a.real.plugin --variants="A" 2>&1)
assert_has "unknown plugin id returns error" "No menu link plugin" "$output"

# Empty variants list should fail.
output=$($DRUSH rl:menu-link:create system.admin_config --variants="" 2>&1)
assert_has "empty variants returns error" "At least one variant" "$output"

# Dry run.
output=$($DRUSH rl:menu-link:create user.page --variants="Preview" --dry-run 2>&1)
assert_dry_run "create dry-run" "$output"

section "rl:menu-link:list"

output=$($DRUSH rl:menu-link:list 2>&1)
assert_has "list returns success" "success: true" "$output"
assert_has "list contains created experiment" "system.admin_content" "$output"

# Filter by enabled status.
output=$($DRUSH rl:menu-link:list --enabled=yes 2>&1)
assert_has "filter by enabled=yes" "success: true" "$output"

section "rl:menu-link:get"

output=$($DRUSH rl:menu-link:get "$ENTITY_ID" 2>&1)
assert_has "get returns label" "E2E test" "$output"
assert_has "get returns variants" "Content" "$output"
assert_has "get includes original_label from menu manager" "original_label" "$output"
assert_has "get returns analytics block" "analytics:" "$output"

# Get nonexistent.
output=$($DRUSH rl:menu-link:get 999999 2>&1)
assert_has "get nonexistent returns error" "not found" "$output"

section "rl:menu-link:update"

output=$($DRUSH rl:menu-link:update "$ENTITY_ID" --label="Renamed" 2>&1)
assert_has "update label returns success" "success: true" "$output"

# Update variants.
output=$($DRUSH rl:menu-link:update "$ENTITY_ID" --variants="New A,New B,New C" 2>&1)
assert_has "update variants returns success" "success: true" "$output"

# Disable.
output=$($DRUSH rl:menu-link:update "$ENTITY_ID" --disable 2>&1)
assert_has "disable returns success" "success: true" "$output"

# Re-enable.
output=$($DRUSH rl:menu-link:update "$ENTITY_ID" --enable 2>&1)
assert_has "enable returns success" "success: true" "$output"

# Update with no options.
output=$($DRUSH rl:menu-link:update "$ENTITY_ID" 2>&1)
assert_has "update with no options returns error" "Nothing to update" "$output"

# Dry run.
output=$($DRUSH rl:menu-link:update "$ENTITY_ID" --label="Preview" --dry-run 2>&1)
assert_dry_run "update dry-run" "$output"

section "rl:menu-link:delete"

# Dry run.
output=$($DRUSH rl:menu-link:delete "$ENTITY_ID" --dry-run 2>&1)
assert_dry_run "delete dry-run" "$output"

# Actual delete.
output=$($DRUSH rl:menu-link:delete "$ENTITY_ID" 2>&1)
assert_has "delete returns success" "success: true" "$output"
assert_has "delete reports purge count" "purged" "$output"

# Verify it's gone.
output=$($DRUSH rl:menu-link:get "$ENTITY_ID" 2>&1)
assert_has "deleted experiment not found by get" "not found" "$output"

# Delete nonexistent.
output=$($DRUSH rl:menu-link:delete 999999 2>&1)
assert_has "delete nonexistent returns error" "not found" "$output"

print_summary
