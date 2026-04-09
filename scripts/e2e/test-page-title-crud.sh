#!/usr/bin/env bash
# E2E tests for rl:page-title:* commands.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/_helpers.sh"

DRUSH="${DRUSH:-drush}"

section "rl:page-title:create"

# Create with comma-separated variants.
output=$($DRUSH rl:page-title:create /admin --variants="Alt One,Alt Two" --label="E2E test" 2>&1)
assert_has "create returns success" "success: true" "$output"
assert_has "create returns rl experiment id" "rl_page_title-" "$output"
assert_has "create stores normalized path" "path: /admin" "$output"

# Capture the entity ID for later commands.
ENTITY_ID=$(echo "$output" | yq -e '.experiment.id' 2>/dev/null || true)
assert_not_empty "create returned entity id" "$ENTITY_ID"

# Duplicate (same path + langcode) should fail.
output=$($DRUSH rl:page-title:create /admin --variants="Other" 2>&1)
assert_has "duplicate create returns error" "already exists" "$output"

# Different language for same path should succeed.
output=$($DRUSH rl:page-title:create /admin --variants="Spanish Alt" --langcode=es 2>&1)
assert_has "different language create succeeds" "success: true" "$output"

# Multiple --variants flags work.
output=$($DRUSH rl:page-title:create /admin/structure --variants="One" --variants="Two" --variants="Three" 2>&1)
assert_has "multiple --variants flags accepted" "success: true" "$output"

# Empty variants list should fail.
output=$($DRUSH rl:page-title:create /admin/people --variants="" 2>&1)
assert_has "empty variants returns error" "At least one variant" "$output"

# Dry run.
output=$($DRUSH rl:page-title:create /admin/config --variants="Preview" --dry-run 2>&1)
assert_dry_run "create dry-run" "$output"

# Path normalization: trailing slash should resolve to canonical form.
$DRUSH rl:page-title:create /admin/reports/ --variants="Trailing" 2>&1 > /dev/null
output=$($DRUSH rl:page-title:create /admin/reports --variants="Same path" 2>&1)
assert_has "trailing slash duplicate detected" "already exists" "$output"

section "rl:page-title:list"

output=$($DRUSH rl:page-title:list 2>&1)
assert_has "list returns success" "success: true" "$output"
assert_has "list contains created experiment" "/admin" "$output"

# Filter by enabled status.
output=$($DRUSH rl:page-title:list --enabled=yes 2>&1)
assert_has "filter by enabled=yes" "success: true" "$output"

section "rl:page-title:get"

output=$($DRUSH rl:page-title:get "$ENTITY_ID" 2>&1)
assert_has "get returns label" "E2E test" "$output"
assert_has "get returns variants" "Alt One" "$output"
assert_has "get returns analytics block" "analytics:" "$output"

# Get nonexistent.
output=$($DRUSH rl:page-title:get 999999 2>&1)
assert_has "get nonexistent returns error" "not found" "$output"

section "rl:page-title:update"

output=$($DRUSH rl:page-title:update "$ENTITY_ID" --label="Renamed" 2>&1)
assert_has "update label returns success" "success: true" "$output"
assert_has "update reflects new label in changes" "Renamed" "$output"

# Update variants.
output=$($DRUSH rl:page-title:update "$ENTITY_ID" --variants="Brand New A,Brand New B,Brand New C" 2>&1)
assert_has "update variants returns success" "success: true" "$output"
assert_has "update variants shows new list" "Brand New" "$output"

# Disable.
output=$($DRUSH rl:page-title:update "$ENTITY_ID" --disable 2>&1)
assert_has "disable returns success" "success: true" "$output"

# Re-enable.
output=$($DRUSH rl:page-title:update "$ENTITY_ID" --enable 2>&1)
assert_has "enable returns success" "success: true" "$output"

# Update with no options.
output=$($DRUSH rl:page-title:update "$ENTITY_ID" 2>&1)
assert_has "update with no options returns error" "Nothing to update" "$output"

# Dry run.
output=$($DRUSH rl:page-title:update "$ENTITY_ID" --label="Preview" --dry-run 2>&1)
assert_dry_run "update dry-run" "$output"

section "rl:page-title:delete"

# Dry run delete.
output=$($DRUSH rl:page-title:delete "$ENTITY_ID" --dry-run 2>&1)
assert_dry_run "delete dry-run" "$output"

# Actual delete.
output=$($DRUSH rl:page-title:delete "$ENTITY_ID" 2>&1)
assert_has "delete returns success" "success: true" "$output"
assert_has "delete reports purge count" "purged" "$output"

# Verify it's gone.
output=$($DRUSH rl:page-title:get "$ENTITY_ID" 2>&1)
assert_has "deleted experiment not found by get" "not found" "$output"

# Delete nonexistent.
output=$($DRUSH rl:page-title:delete 999999 2>&1)
assert_has "delete nonexistent returns error" "not found" "$output"

print_summary
