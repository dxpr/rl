# PHP API

## Experiment manager

The `rl.experiment_manager` service is the primary entry point for recording
interactions and retrieving scores.

```php
$experiment_manager = \Drupal::service('rl.experiment_manager');

// Record a trial (content shown to visitor).
$experiment_manager->recordTurn('my-experiment', 'variant-a');

// Record a success (visitor clicked/converted).
$experiment_manager->recordReward('my-experiment', 'variant-a');

// Get Thompson Sampling scores for specific arms.
$scores = $experiment_manager->getThompsonScores(
  'my-experiment',
  NULL,
  ['variant-a', 'variant-b', 'variant-c'],
);

// Select the best option.
$ts_calculator = \Drupal::service('rl.ts_calculator');
$best_option = $ts_calculator->selectBestArm($scores);
```

## Experiment registry

Experiments must be registered before they can receive data. Unknown
experiment IDs sent to `rl.php` are silently dropped.

```php
$registry = \Drupal::service('rl.experiment_registry');

// Register a new experiment.
$registry->register('my-experiment', 'my_module', 'Homepage Hero Test');

// Check if an experiment exists.
$exists = $registry->exists('my-experiment');

// Remove an experiment and all its data.
$registry->delete('my-experiment');
```

## Variant selection helpers

For modules that follow the "v0 = original, v1..vN = stored variants"
convention, the module provides reusable traits and base classes:

- `VariantArmsTrait`: arm-id helpers (`getArmIds`, `getArmText`,
  `buildVariantExperimentId`)
- `VariantSelectorBase`: reusable base class for variant selectors
- `VariantParser`: static helper for parsing textarea variant input into
  normalised lists

## Cache management

See the [cache management guide](../guides/cache.md) for controlling page
cache lifetimes on pages running experiments.
