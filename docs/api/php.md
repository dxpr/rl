# PHP API

## Experiment manager

The `rl.experiment_manager` service is the primary entry point for recording
interactions and retrieving scores.

```php
// Get experiment manager
$experiment_manager = \Drupal::service('rl.experiment_manager');

// Record a trial (content shown)
$experiment_manager->recordTurn('my-experiment', 'variant-a');

// Record a success (user clicked)
$experiment_manager->recordReward('my-experiment', 'variant-a');

// Get Thompson Sampling scores
$scores = $experiment_manager->getThompsonScores('my-experiment');

// Select best option
$ts_calculator = \Drupal::service('rl.ts_calculator');
$best_option = $ts_calculator->selectBestArm($scores);
```

## Variant selection

For modules that follow the "v0 = original, v1..vN = stored variants"
convention, the module provides reusable traits and base classes:

- `VariantArmsTrait`: arm-id helpers (`getArmIds`, `getArmText`,
  `buildVariantExperimentId`)
- `VariantSelectorBase`: reusable base class for variant selectors
- `VariantParser`: static helper for parsing textarea variant input into
  normalised lists

## Cache override

```php
// Override page cache if experiment cache is shorter than site cache
$cache_manager = \Drupal::service('rl.cache_manager');
$cache_manager->overridePageCacheIfShorter(60); // 60 seconds
```
