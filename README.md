# Reinforcement Learning (RL)

Multi-armed bandit experiments in Drupal using Thompson Sampling algorithm for efficient A/B testing that minimizes lost conversions.

## Features

- **Thompson Sampling Algorithm**: Pure PHP implementation
- **Fast HTTP REST API**: Optimized JSON endpoints for tracking and decisions
- **Administrative Reports**: Experiment analysis interface at `/admin/reports/rl`
- **Service-based Architecture**: Extensible design for custom implementations
- **Data Sovereignty**: No cloud dependencies, pure Drupal solution

## How Thompson Sampling Works

Thompson Sampling is a learning-while-doing method. Each visitor triggers the algorithm to "roll the dice" based on learned performance. High-performing variants get larger numbers and show more often, while weak variants still get chances to prove themselves.

Traditional A/B tests waste conversions by showing losing variants for fixed durations. Thompson Sampling shifts traffic to better variants as soon as evidence emerges, saving conversions and reducing testing time.

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
```

## HTTP Endpoints

### rl.php - High-Performance Endpoint (Recommended)
**For high-volume, low-latency applications, use the direct rl.php endpoint:**

```javascript
// Record turns (trials) - when content is viewed
const formData = new FormData();
formData.append('action', 'turns');
formData.append('experiment_uuid', 'abc123');
formData.append('arm_ids', '1,2,3');
navigator.sendBeacon('/modules/contrib/rl/rl.php', formData);

// Record reward (success) - when user clicks/converts  
const rewardData = new FormData();
rewardData.append('action', 'rewards');
rewardData.append('experiment_uuid', 'abc123');
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
- `POST /rl/experiment/{uuid}/turns` - Record trials
- `POST /rl/experiment/{uuid}/rewards` - Record successes  
- `GET /rl/experiment/{uuid}/scores` - Get scores

## Related Modules

- [AI Sorting](https://www.drupal.org/project/ai_sorting) - Intelligent content ordering for Drupal Views

## Technical Implementation

Full algorithm details available in source code:
[ThompsonCalculator.php](https://git.drupalcode.org/project/rl/-/blob/1.x/src/Service/ThompsonCalculator.php)

## Analytics API

The RL module provides an analytics service (`rl.analyzer`) for accessing experiment performance data, insights, and recommendations. This service can be used by Drush commands, other modules, or custom code.

### Drush Commands

```bash
# List all experiments
drush rl:list
drush rl:list --format=json

# Get detailed experiment status
drush rl:status ab_test_button_color --format=yaml

# Get arm performance with human-readable labels
drush rl:performance ai_sorting-help_center_categories-block_1 --limit=10

# Get historical trends
drush rl:trends ab_test_headline_variants --period=weekly --periods=8

# Full analysis with recommendations (AI-optimized)
drush rl:analyze mock_10_arm_test --format=json

# Export complete experiment data
drush rl:export my_experiment --snapshots --format=json
```

### Service API for Other Modules

```php
// Get the analyzer service
$analyzer = \Drupal::service('rl.analyzer');

// List all experiments with summary stats
$experiments = $analyzer->listExperiments();

// Get detailed status for an experiment
$status = $analyzer->getStatus('my_experiment');
// Returns: phase, confidence, value generated vs equal distribution

// Get arm performance with resolved labels
$performance = $analyzer->getPerformance('my_experiment', limit: 20);
// Returns: arms with labels, rates, vs_average, confidence

// Get historical trends
$trends = $analyzer->getTrends('my_experiment', 'weekly', 8);
// Returns: period data with trend analysis

// Full export for deep analysis
$export = $analyzer->export('my_experiment', includeSnapshots: true);
```

### AI Integration

The Drush commands are designed for AI tool consumption:
- Human-readable labels (entity IDs resolved to titles)
- Pre-computed insights (vs_average, confidence levels)
- Actionable recommendations
- JSON/YAML output formats

## Resources

- [Multi-Armed Bandit Problem](https://en.wikipedia.org/wiki/Multi-armed_bandit) - Wikipedia overview
- [Thompson Sampling Paper](https://www.jstor.org/stable/2332286) - Original research
- [Finite-time Analysis](https://homes.di.unimi.it/~cesa-bianchi/Pubblicazioni/ml-02.pdf) - Mathematical foundations
