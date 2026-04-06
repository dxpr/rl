#!/usr/bin/env bash
# E2E tests for rl:setup-ai command.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
source "$SCRIPT_DIR/_helpers.sh"

DRUSH="${DRUSH:-drush}"

section "rl:setup-ai"

# Check mode (files not installed yet).
output=$($DRUSH rl:setup-ai --check 2>&1)
assert_has "check detects not installed" "NOT INSTALLED" "$output"

# Install for Claude only.
output=$($DRUSH rl:setup-ai --host=claude 2>&1)
assert_has "install claude returns success" "success: true" "$output"
assert_has "install claude mentions skill file" "SKILL.md" "$output"

# Install for all.
output=$($DRUSH rl:setup-ai 2>&1)
assert_has "install all returns success" "success: true" "$output"

# Check mode after install.
output=$($DRUSH rl:setup-ai --check 2>&1)
assert_has "check shows up to date" "up to date" "$output"

# Invalid host.
output=$($DRUSH rl:setup-ai --host=invalid 2>&1)
assert_has "invalid host returns error" "Invalid --host" "$output"

# Host filtering.
output=$($DRUSH rl:setup-ai --host=agents 2>&1)
assert_has "agents install returns success" "success: true" "$output"

print_summary
