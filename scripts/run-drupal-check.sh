#!/bin/bash
set -vo pipefail

DRUPAL_RECOMMENDED_PROJECT=${DRUPAL_RECOMMENDED_PROJECT:-11.x-dev}
PHP_EXTENSIONS="gd"
DRUPAL_CHECK_TOOL="mglaman/drupal-check:^1.5"

# Install required PHP extensions
for ext in $PHP_EXTENSIONS; do
  if ! php -m | grep -q $ext; then
    apk update && apk add --no-cache ${ext}-dev
    docker-php-ext-install $ext
  fi
done

# Create Drupal project if it doesn't exist
if [ ! -d "/drupal" ]; then
  composer create-project drupal/recommended-project=$DRUPAL_RECOMMENDED_PROJECT drupal --no-interaction --stability=dev
fi

cd drupal
mkdir -p web/modules/contrib/

# Symlink rl if not already linked
if [ ! -L "web/modules/contrib/rl" ]; then
  ln -s /src web/modules/contrib/rl
fi

# Install the statistic modules if D11 (removed from core).
if [[ $DRUPAL_RECOMMENDED_PROJECT == 11.* ]]; then
  composer require drupal/statistics
fi

# Try to install drupal-check latest version
echo "Installing drupal-check..."
if ! composer require mglaman/drupal-check:^1.5 --dev --no-interaction; then
  echo "❌ Failed to install drupal-check due to dependency conflicts"
  echo "Attempting to resolve conflicts..."
  
  # Try alternative installation methods
  if ! composer require mglaman/drupal-check --dev --no-interaction --ignore-platform-reqs; then
    echo "❌ Could not resolve drupal-check dependency conflicts"
    echo "This indicates a real compatibility issue that needs to be addressed"
    exit 1
  fi
fi

# Verify installation and run drupal-check
if [ -f "./vendor/bin/drupal-check" ]; then
  echo "✅ Running drupal-check analysis..."
  ./vendor/bin/drupal-check --drupal-root . -ad web/modules/contrib/rl
else
  echo "❌ drupal-check installation failed - binary not found"
  exit 1
fi 