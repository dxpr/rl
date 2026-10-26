#!/usr/bin/env bash
# Run report browser tests in the isolated E2E Drupal installation.
set -euo pipefail

SITE_DIR=$(cd "$(dirname "$DRUSH")/../.." && pwd)
MODULE_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
cd "$SITE_DIR"

# Keep the test dependencies aligned with the installed Drupal core version.
if [ ! -x vendor/bin/phpunit ]; then
  CORE_VERSION=$(php -r 'require "vendor/autoload.php"; echo \Drupal::VERSION;')
  composer require --dev "drupal/core-dev:$CORE_VERSION" --no-interaction --quiet
fi

mkdir -p "$SITE_DIR/browser_output"
php -S 127.0.0.1:8080 -t "$SITE_DIR/web" "$SITE_DIR/web/.ht.router.php" > "$SITE_DIR/browser-server.log" 2>&1 &
SERVER_PID=$!
trap 'kill "$SERVER_PID" 2>/dev/null || true' EXIT

# Wait for the server before PHPUnit starts making HTTP requests.
for attempt in {1..30}; do
  if php -r '$socket = @fsockopen("127.0.0.1", 8080); exit($socket ? 0 : 1);'; then
    break
  fi
  sleep 1
done

SIMPLETEST_BASE_URL=http://127.0.0.1:8080 \
SIMPLETEST_DB="sqlite://localhost/$SITE_DIR/browser-tests.sqlite" \
BROWSERTEST_OUTPUT_DIRECTORY="$SITE_DIR/browser_output" \
BROWSERTEST_OUTPUT_VERBOSE=false \
vendor/bin/phpunit -c web/core/phpunit.xml.dist "$MODULE_DIR/tests/src/Functional"
