# Installation

## Install the module

```bash
composer require drupal/rl
drush en rl
```

After enabling, visit `/admin/config/services/reinforcement-learning` to
review the default settings, then see [Configuration](configuration.md)
for next steps.

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

Direct access to `rl.php` is required for the tracking endpoint. If your
server configuration prevents it, you will need to add a custom route in
your own module that proxies requests to the RL services.

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
