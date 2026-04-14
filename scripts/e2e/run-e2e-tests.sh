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

# Install PHP extensions required by Drupal (GD for image handling).
if command -v apk &> /dev/null; then
  echo "Installing system dependencies..."
  apk add --no-cache bash yq libpng libpng-dev libjpeg-turbo-dev libwebp-dev zlib-dev libxpm-dev > /dev/null 2>&1
  docker-php-ext-install gd > /dev/null 2>&1
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
composer create-project drupal/recommended-project:11.x-dev . --no-interaction --quiet

# Symlink our module.
mkdir -p web/modules/contrib
ln -s "$MODULE_DIR" web/modules/contrib/rl

# Install Drush.
composer require drush/drush --quiet

# Install Drupal with SQLite.
./vendor/bin/drush site:install standard \
  --db-url=sqlite://sites/default/files/.ht.sqlite \
  --site-name="RL E2E Tests" \
  --site-mail="test@example.com" \
  --yes \
  --quiet

# Enable the RL ecosystem modules (parent + submodules).
DRUSH="$SITE_DIR/vendor/bin/drush"
$DRUSH en rl rl_page_title rl_menu_link --yes --quiet

# Rebuild cache after enabling module.
$DRUSH cr --quiet

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
