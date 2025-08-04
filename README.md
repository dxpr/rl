# Reinforcement Learning

Multi-armed bandit experiments for Drupal using Thompson Sampling algorithm.

## Features

- Thompson Sampling algorithm (pure PHP)
- HTTP REST API for tracking
- Administrative reports
- Service-based architecture

## API Usage

```php
// Get the experiment manager
$experiment_manager = \Drupal::service('rl.experiment_manager');

// Record a trial (content shown)
$experiment_manager->recordTurn('my-experiment', 'variant-a');

// Record a success (user clicked)
$experiment_manager->recordReward('my-experiment', 'variant-a');

// Get Thompson Sampling scores
$scores = $experiment_manager->getThompsonScores('my-experiment');

// Select the best option
$ts_calculator = \Drupal::service('rl.ts_calculator');
$best_option = $ts_calculator->selectBestArm($scores);
```

## HTTP Endpoints

- `POST /rl/experiment/{uuid}/turns` - Record trials
- `POST /rl/experiment/{uuid}/rewards` - Record successes  
- `GET /rl/experiment/{uuid}/scores` - Get scores

## Reports

View experiment data at `/admin/reports/rl`

## Related Modules

- [AI Sorting](https://www.drupal.org/project/ai_sorting) - Ready-to-use content optimization for Views