#!/bin/bash

set -vo pipefail

# Install required libs for Drupal.
GD_ENABLED=$(php -i | grep 'GD Support' | awk '{ print $4 }')

if [ "$GD_ENABLED" != 'enabled' ]; then
  apk update && \
  apk add libpng libpng-dev libjpeg-turbo-dev libwebp-dev zlib-dev libxpm-dev gd tree rsync sqlite && docker-php-ext-install gd pdo_sqlite
fi

# Create project in a temporary directory inside the container.
INSTALL_DIR="/drupal_install_tmp"
composer create-project drupal/recommended-project:11.x-dev "$INSTALL_DIR" --no-interaction --stability=dev

cd "$INSTALL_DIR"

# Allow specific plugins needed by dependencies before requiring them.
composer config --no-plugins allow-plugins.tbachert/spi true --no-interaction
composer config --no-plugins allow-plugins.phpstan/extension-installer true --no-interaction

mkdir -p web/modules/contrib/

if [ ! -L "web/modules/contrib/rl" ]; then
  ln -s /src web/modules/contrib/rl
fi

# Install PHPUnit + dev dependencies needed for kernel/unit tests.
composer require --dev phpunit/phpunit drush/drush --with-all-dependencies --no-interaction

# Drupal kernel tests need a writable simpletest dir.
mkdir -p web/sites/simpletest
chmod -R 777 web/sites/simpletest

# Run unit and kernel tests for the rl ecosystem.
export SIMPLETEST_DB="sqlite://localhost//tmp/test.sqlite"
export SIMPLETEST_BASE_URL="http://localhost"

./vendor/bin/phpunit \
  -c web/core/phpunit.xml.dist \
  --colors=never \
  --group rl \
  --group rl_page_title \
  --group rl_menu_link \
  --testdox \
  web/modules/contrib/rl
