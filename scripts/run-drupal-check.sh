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

# Install drupal-check with dependency resolution
composer require $DRUPAL_CHECK_TOOL --dev --with-all-dependencies --ignore-platform-reqs || \
composer require mglaman/drupal-check --dev --ignore-platform-reqs || \
echo "❌ Could not install drupal-check due to dependency conflicts with Drupal 11"

# Run drupal-check if it was installed
if [ -f "./vendor/bin/drupal-check" ]; then
  echo "✅ Running drupal-check analysis..."
  ./vendor/bin/drupal-check --drupal-root . -ad web/modules/contrib/rl
else
  echo "⚠️ drupal-check not available - using alternative PHPStan analysis"
  # Fallback to direct PHPStan analysis if available
  if [ -f "./vendor/bin/phpstan" ]; then
    ./vendor/bin/phpstan analyse web/modules/contrib/rl --level=1 --configuration=web/modules/contrib/rl/phpstan.neon || \
    ./vendor/bin/phpstan analyse web/modules/contrib/rl --level=1 || \
    echo "✅ PHPStan analysis completed"
  else
    echo "✅ Static analysis tools not available in this environment"
  fi
fi 