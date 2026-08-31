# Installation

## Install the module

```bash
composer require drupal/rl
drush en rl
```

After enabling, visit `/admin/config/system/rl` to review the default settings,
then see [Configuration](configuration.md) for next steps.

## Plotly.js library (required for charts)

The RL module uses Plotly.js for experiment charts. Install it via Composer using
[Asset Packagist](https://asset-packagist.org):

```bash
# Add Asset Packagist repository (if not already configured)
composer config repositories.asset-packagist composer https://asset-packagist.org

# Install the Composer plugin for npm assets (if not already installed)
composer require oomphinc/composer-installers-extender
composer config extra.installer-types --json '["npm-asset"]'
composer config extra.installer-paths.web/libraries/\{\$name\} --json '["type:npm-asset"]'

# Install Plotly.js
composer require npm-asset/plotly.js-dist-min:^2.35
```

This installs the library to `web/libraries/plotly.js-dist-min/`.

## Post-installation: verify rl.php access

The RL module includes a `.htaccess` file that allows direct access to
`rl.php` (following the same pattern as Drupal 11's contrib statistics
module). Test that it's working:

```bash
curl -X POST -d "action=ping" http://example.com/modules/contrib/rl/rl.php
```

**If the test fails:**

- **Apache**: ensure `.htaccess` files are processed (`AllowOverride All`)
- **Nginx**: add the configuration rules below to your server block
- **Security modules**: whitelist `/modules/contrib/rl/rl.php`

If server policies prevent direct access to `rl.php`, use the Drupal
Routes API instead.

### Nginx configuration

Add these rules to your Nginx server block, **before** the main Drupal
location block:

```nginx
# Allow direct access to rl.php for performance
location ~ ^/modules/contrib/rl/rl\.php$ {
    fastcgi_split_path_info ^(.+?\.php)(|/.*)$;
    try_files $uri =404;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_param PATH_INFO $fastcgi_path_info;
    fastcgi_param QUERY_STRING $query_string;
    fastcgi_pass unix:/var/run/php/php-fpm.sock;  # Adjust to your PHP-FPM socket
}

# Block access to other PHP files in modules (except rl.php)
location ~ ^/modules/.*\.php$ {
    deny all;
}
```

**Note:** Adjust `fastcgi_pass` to match your PHP-FPM configuration:

- Socket: `unix:/var/run/php/php8.1-fpm.sock` (or your PHP version)
- TCP: `127.0.0.1:9000`
