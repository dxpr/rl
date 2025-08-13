# Reinforcement Learning (RL)

Multi-armed bandit experiments in Drupal using Thompson Sampling algorithm for
efficient A/B testing that minimizes lost conversions.

## Features

- **Thompson Sampling Algorithm**: Pure PHP implementation
- **Fast HTTP REST API**: Optimized JSON endpoints for tracking and decisions
- **Administrative Reports**: Experiment analysis interface at 
  `/admin/reports/rl`
- **Service-based Architecture**: Extensible design for custom implementations
- **Data Sovereignty**: No cloud dependencies, pure Drupal solution

## How Thompson Sampling Works

Thompson Sampling is a learning-while-doing method. Each visitor triggers the
algorithm to "roll the dice" based on learned performance. High-performing
variants get larger numbers and show more often, while weak variants still get
chances to prove themselves.

Traditional A/B tests waste conversions by showing losing variants for fixed
durations. Thompson Sampling shifts traffic to better variants as soon as
evidence emerges, saving conversions and reducing testing time.

## Use Cases

- **A/B Testing**: Test content variations efficiently
- **Content Optimization**: Track content engagement automatically
- **Feature Selection**: Choose features to show users
- **Recommendations**: Optimize content recommendations
- **Resource Allocation**: Distribute resources across options

## Installation

```bash
composer require drupal/rl
drush en rl
```

## API Usage

### PHP API
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

// Override page cache for web components (optional)
$cache_manager = \Drupal::service('rl.cache_manager');
$cache_manager->overridePageCacheIfShorter(60); // 60 seconds
```

## HTTP Endpoints

### rl.php - High-Performance Endpoint (Recommended)
**For high-volume, low-latency applications, use the direct rl.php
endpoint:**

```javascript
// Record turns (trials) - when content is viewed
const formData = new FormData();
formData.append('action', 'turns');
formData.append('experiment_id', 'abc123');
formData.append('arm_ids', '1,2,3');
navigator.sendBeacon('/modules/contrib/rl/rl.php', formData);

// Record reward (success) - when user clicks/converts  
const rewardData = new FormData();
rewardData.append('action', 'rewards');
rewardData.append('experiment_id', 'abc123');
rewardData.append('arm_id', '1');
navigator.sendBeacon('/modules/contrib/rl/rl.php', rewardData);
```

**Benefits:**
- Minimal server overhead (no full Drupal bootstrap)
- Faster response times for JavaScript tracking
- Optimized for `navigator.sendBeacon()` requests
- Used by AI Sorting module for real-time content optimization

### Drupal Routes - Full API
**For applications requiring full Drupal integration:**
- `POST /rl/experiment/{experiment_id}/turns` - Record trials
- `POST /rl/experiment/{experiment_id}/rewards` - Record successes  
- `GET /rl/experiment/{experiment_id}/scores` - Get scores

## Cache Management

RL provides optional cache management for web components:

```php
// Override page cache if experiment cache is shorter than site cache
\Drupal::service('rl.cache_manager')->overridePageCacheIfShorter(30);
```

**How it works:**
- If site cache is 300s and experiment needs 30s → overrides to 30s
- If site cache is 60s and experiment needs 300s → leaves at 60s  
- If site cache is disabled → no override

**Use cases:**
- Views plugins using RL for content sorting
- Blocks displaying A/B tested content
- Components needing frequent RL score updates

## Related Modules

- [AI Sorting](https://www.drupal.org/project/ai_sorting) - Intelligent content
  ordering for Drupal Views

## Technical Implementation

Full algorithm details available in source code:
[ThompsonCalculator.php](https://git.drupalcode.org/project/rl/-/blob/1.x/src/Service/ThompsonCalculator.php)

## Development

### Linting and Code Standards

Run coding standards checks:
```bash
docker compose --profile lint run --rm drupal-lint
```

Auto-fix coding standard violations:
```bash
docker compose --profile lint run --rm drupal-lint-auto-fix
```

Run Drupal compatibility checks:
```bash
docker compose --profile lint run --rm drupal-check
```

## Resources

- [Multi-Armed Bandit Problem](https://en.wikipedia.org/wiki/Multi-armed_bandit) -
  Wikipedia overview
- [Thompson Sampling Paper](https://www.jstor.org/stable/2332286) - Original research
- [Finite-time Analysis](https://homes.di.unimi.it/~cesa-bianchi/Pubblicazioni/ml-02.pdf) -
  Mathematical foundations
