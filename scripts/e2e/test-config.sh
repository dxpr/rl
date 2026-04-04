#!/usr/bin/env bash
# E2E tests for config management commands.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/_helpers.sh"

DRUSH="${DRUSH:-drush}"

section "rl:config:list"

output=$($DRUSH rl:config:list 2>&1)
assert_has "list returns success" "success: true" "$output"
assert_has "list contains debug_mode" "debug_mode" "$output"
assert_has "list contains enable_event_log" "enable_event_log" "$output"
assert_has "list contains event_log_max_rows" "event_log_max_rows" "$output"
assert_has "list contains chart_line_threshold" "chart_line_threshold" "$output"

section "rl:config:get"

output=$($DRUSH rl:config:get debug_mode 2>&1)
assert_has "get returns key" "key: debug_mode" "$output"
assert_has "get returns type" "type: boolean" "$output"

# Invalid key.
output=$($DRUSH rl:config:get invalid_key 2>&1)
assert_has "get invalid key returns error" "Unknown setting" "$output"

section "rl:config:set"

# Set debug mode.
output=$($DRUSH rl:config:set debug_mode true 2>&1)
assert_has "set debug_mode returns success" "success: true" "$output"

# Verify it was set.
output=$($DRUSH rl:config:get debug_mode 2>&1)
assert_has "debug_mode is now true" "value: true" "$output"

# Set integer.
output=$($DRUSH rl:config:set chart_line_threshold 15 2>&1)
assert_has "set integer returns success" "success: true" "$output"

# Invalid value.
output=$($DRUSH rl:config:set debug_mode invalid_bool 2>&1)
assert_has "set invalid boolean returns error" "Invalid value" "$output"

# Out of range.
output=$($DRUSH rl:config:set event_log_max_rows 999 2>&1)
assert_has "set below min returns error" "below minimum" "$output"

# Dry run.
output=$($DRUSH rl:config:set debug_mode false --dry-run 2>&1)
assert_dry_run "set dry-run" "$output"

section "rl:config:reset"

# Dry run reset.
output=$($DRUSH rl:config:reset --dry-run 2>&1)
assert_dry_run "reset dry-run" "$output"

# Actual reset.
output=$($DRUSH rl:config:reset 2>&1)
assert_has "reset returns success" "success: true" "$output"

# Verify defaults.
output=$($DRUSH rl:config:get debug_mode 2>&1)
assert_has "debug_mode reset to false" "value: false" "$output"

output=$($DRUSH rl:config:get chart_line_threshold 2>&1)
assert_has "chart_line_threshold reset to 9" "value: 9" "$output"

section "rl:event-log:clear"

# Clear (may be empty).
output=$($DRUSH rl:event-log:clear 2>&1)
assert_has "clear returns success" "success: true" "$output"

print_summary
