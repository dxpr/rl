#!/usr/bin/env bash
# E2E tests for analytics/read commands.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/_helpers.sh"

DRUSH="${DRUSH:-drush}"

section "Setup test experiment"

$DRUSH rl:experiment:create analytics_test --module=e2e_test --name="Analytics Test" 2>&1 > /dev/null

section "rl:list"

output=$($DRUSH rl:list --format=json 2>&1)
assert_not_empty "list returns data" "$output"
assert_has "list contains test experiment" "analytics_test" "$output"

section "rl:status"

output=$($DRUSH rl:status analytics_test --format=yaml 2>&1)
assert_has "status returns experiment id" "analytics_test" "$output"
assert_has "status returns phase" "phase:" "$output"

# Nonexistent experiment.
output=$($DRUSH rl:status nonexistent_exp --format=yaml 2>&1 || true)
assert_has "status nonexistent fails" "not found" "$output"

section "rl:performance"

output=$($DRUSH rl:performance analytics_test --format=json 2>&1 || true)
assert_runs "performance command runs" $DRUSH rl:performance analytics_test --format=json

section "rl:trends"

assert_runs "trends command runs" $DRUSH rl:trends analytics_test --format=json

section "rl:export"

output=$($DRUSH rl:export analytics_test --format=json 2>&1)
assert_has "export contains experiment" "analytics_test" "$output"

section "rl:analyze"

output=$($DRUSH rl:analyze analytics_test --format=yaml 2>&1)
assert_has "analyze contains experiment" "analytics_test" "$output"
assert_has "analyze contains recommendation" "recommendation:" "$output"

section "Cleanup"

$DRUSH rl:experiment:delete analytics_test 2>&1 > /dev/null

print_summary
