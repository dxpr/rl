# Cache management

RL provides optional cache management for web components that run A/B tests.

## Usage

```php
// Override page cache if experiment cache is shorter than site cache
\Drupal::service('rl.cache_manager')->overridePageCacheIfShorter(30);
```

## How it works

- If site cache is 300s and experiment needs 30s: overrides to 30s
- If site cache is 60s and experiment needs 300s: leaves at 60s
- If site cache is disabled: no override

## When to use it

- Views plugins using RL for content sorting
- Blocks displaying A/B tested content
- Components needing frequent RL score updates

The cache manager only shortens the page cache lifetime; it never extends
it beyond what the site already has configured. This ensures that
experiments can refresh their data without interfering with the site's
overall caching strategy.
