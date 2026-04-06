#!/usr/bin/env bash
# E2E tests for experiment CRUD commands.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/_helpers.sh"

DRUSH="${DRUSH:-drush}"

section "rl:experiment:create"

# Create experiment.
output=$($DRUSH rl:experiment:create e2e_test_exp --module=e2e_test --name="E2E Test Experiment" 2>&1)
assert_has "create returns success" "success: true" "$output"
assert_has "create returns experiment id" "e2e_test_exp" "$output"

# Duplicate creation should fail.
output=$($DRUSH rl:experiment:create e2e_test_exp 2>&1)
assert_has "duplicate create returns error" "already exists" "$output"

# Dry run.
output=$($DRUSH rl:experiment:create dry_run_exp --dry-run 2>&1)
assert_dry_run "create dry-run" "$output"

section "rl:experiment:update"

# Update name.
output=$($DRUSH rl:experiment:update e2e_test_exp --name="Updated Name" 2>&1)
assert_has "update returns success" "success: true" "$output"
assert_has "update shows changes" "Updated Name" "$output"

# Update module.
output=$($DRUSH rl:experiment:update e2e_test_exp --module=other_module 2>&1)
assert_has "update module returns success" "success: true" "$output"

# Update nonexistent.
output=$($DRUSH rl:experiment:update nonexistent_exp --name=test 2>&1)
assert_has "update nonexistent returns error" "not found" "$output"

# Update with nothing.
output=$($DRUSH rl:experiment:update e2e_test_exp 2>&1)
assert_has "update with no options returns error" "Nothing to update" "$output"

section "rl:experiment:delete"

# Dry run delete.
output=$($DRUSH rl:experiment:delete e2e_test_exp --dry-run 2>&1)
assert_dry_run "delete dry-run" "$output"

# Actual delete.
output=$($DRUSH rl:experiment:delete e2e_test_exp 2>&1)
assert_has "delete returns success" "success: true" "$output"

# Delete nonexistent.
output=$($DRUSH rl:experiment:delete e2e_test_exp 2>&1)
assert_has "delete nonexistent returns error" "not found" "$output"

# Verify it's gone from list.
output=$($DRUSH rl:list --format=json 2>&1)
assert_has "deleted experiment not in list" "" "$(echo "$output" | grep -o 'e2e_test_exp' || true)"

print_summary
