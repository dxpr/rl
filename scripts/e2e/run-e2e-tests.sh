#!/usr/bin/env bash
# E2E test runner for RL module.
# Sets up a fresh Drupal install and runs all test scripts.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
MODULE_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
FILTER="${1:-}"

echo "RL Module E2E Tests"
echo "==================="
echo ""

# Install dependencies if in Docker.
if command -v apk &> /dev/null; then
  echo "Installing system dependencies..."
  apk add --no-cache bash yq > /dev/null 2>&1 || true
fi

# Check for yq.
if ! command -v yq &> /dev/null; then
  echo "ERROR: yq is required. Install: https://github.com/mikefarah/yq"
  exit 1
fi

# Create a fresh Drupal install.
SITE_DIR=$(mktemp -d)
echo "Setting up Drupal in $SITE_DIR..."

cd "$SITE_DIR"
composer create-project drupal/recommended-project:^11 . --no-interaction --quiet 2>&1 || true

# Symlink our module.
mkdir -p web/modules/contrib
ln -s "$MODULE_DIR" web/modules/contrib/rl

# Install Drupal with SQLite.
cd web
php core/scripts/drupal install standard \
  --site-name="RL E2E Tests" \
  --db-url="sqlite://sites/default/files/.ht.sqlite" \
  2>&1 || \
php -r "
  require 'autoload.php';
  \$site_path = 'sites/default';
  \$settings = [];
  require_once 'core/includes/install.core.inc';
" 2>&1 || true

# Use Drush from vendor.
DRUSH="$SITE_DIR/vendor/bin/drush"

# Enable the RL module.
$DRUSH en rl -y 2>&1

echo "Drupal installed. Running tests..."
echo ""

# Run test files.
TESTS_RUN=0
for test_file in "$SCRIPT_DIR"/test-*.sh; do
  test_name=$(basename "$test_file" .sh)

  # Apply filter if given.
  if [ -n "$FILTER" ] && [[ "$test_name" != *"$FILTER"* ]]; then
    continue
  fi

  echo "--- Running: $test_name ---"
  DRUSH="$DRUSH" bash "$test_file"
  TESTS_RUN=$((TESTS_RUN + 1))
done

echo ""
echo "Completed $TESTS_RUN test files."

# Cleanup.
rm -rf "$SITE_DIR"
