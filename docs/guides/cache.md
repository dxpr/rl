# Cache management

RL provides optional cache management for web components that run A/B tests.
When an experiment needs to refresh more often than the site's page cache
allows, the cache manager shortens the cache lifetime for that page only.

## Usage

```php
// Override page cache if experiment cache is shorter than site cache.
\Drupal::service('rl.cache_manager')->overridePageCacheIfShorter(30);
```

Call this in your module's render logic, before the response is sent.

## How it works

The cache manager compares your requested lifetime against the site's
configured page cache maximum age:

- If site cache is 300s and experiment needs 30s: overrides to 30s
- If site cache is 60s and experiment needs 300s: leaves at 60s
- If site cache is disabled (0): no override

The cache manager only shortens the page cache lifetime; it never extends
it beyond what the site already has configured.

## When to use it

- Views plugins using RL for content sorting (e.g. `rl_sorting`)
- Blocks displaying A/B tested content
- Components needing frequent RL score updates

## Client-side alternative

If you need full page caching (Varnish, Fastly, CDN) and still want
per-visitor variant selection, use the client-side approach instead:
render all variants into the HTML, cache the full page, and let
`Drupal.rl.decide()` swap variants in JavaScript after load. See the
[JavaScript API](../api/javascript.md) for details.

<!-- TODO: diagram showing server-side cache override vs client-side swap decision flow -->
