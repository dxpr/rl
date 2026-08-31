# Configuration

## Deciding which variant to show

### Server-side (preferred)

Pick the winning variant in PHP at render time whenever you can. Your
consumer already has the full arm set in hand (entity fields, view
filters, plugin config), so it can call the RL service directly:

```php
$scores = $experiment_manager->getThompsonScores(
  'my_experiment',
  NULL,
  ['v0', 'v1', 'v2']  // arm ids owned by your domain
);
arsort($scores);
$best_arm = key($scores);
```

Deciding server-side keeps the arm list where it belongs (with the
experiment owner) and avoids a network round trip on every page load.
See `rl_sorting`'s Views sort plugin for the canonical pattern and
`VariantSelectorBase` in this module for a reusable base class.

### Client-side (cache-friendly path)

Some consumers have to decide in JS: full-page-cached builders that
render all variants into the HTML and swap them on the client so they
can keep Varnish/Fastly caching. For that case there is
`Drupal.rl.decide()`, documented in the [JavaScript API](../api/javascript.md).

## Cache management

RL provides optional cache management for web components:

```php
// Override page cache if experiment cache is shorter than site cache
\Drupal::service('rl.cache_manager')->overridePageCacheIfShorter(30);
```

**How it works:**

- If site cache is 300s and experiment needs 30s: overrides to 30s
- If site cache is 60s and experiment needs 300s: leaves at 60s
- If site cache is disabled: no override

**Use cases:**

- Views plugins using RL for content sorting
- Blocks displaying A/B tested content
- Components needing frequent RL score updates
