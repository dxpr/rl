# Reinforcement Learning

A Drupal module providing a core API for tracking multi-armed bandit experiments using Thompson Sampling algorithm.

## Table of Contents

- [Description](#description)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
  - [Basic API Usage](#basic-api-usage)
  - [Recording Turns and Rewards](#recording-turns-and-rewards)
  - [Getting Thompson Sampling Scores](#getting-thompson-sampling-scores)
  - [HTTP API Endpoints](#http-api-endpoints)
- [Architecture](#architecture)
- [Thompson Sampling Algorithm](#thompson-sampling-algorithm)
- [Performance](#performance)
- [Reporting and Analytics](#reporting-and-analytics)
- [Troubleshooting](#troubleshooting)
- [Contributing](#contributing)
- [Maintainers](#maintainers)

## Description

The Reinforcement Learning module enables developers to implement multi-armed bandit experiments in Drupal applications. It uses Thompson Sampling, a Bayesian approach that balances exploration and exploitation more naturally than traditional methods like UCB1.

Common use cases include:
- A/B testing for content variations
- Dynamic feature selection
- Personalized content optimization
- Resource allocation optimization
- Adaptive user interface testing

## Features

- **Thompson Sampling Algorithm**: Pure PHP implementation with zero external dependencies
- **Abstract Experiment Tracking**: UUID-based experiments with flexible arm identification
- **High-Performance API**: Optimized for high-frequency tracking operations
- **HTTP REST Endpoints**: JSON API for remote integration
- **Administrative Reports**: Built-in reporting interface for experiment analysis
- **Extensible Architecture**: Service-based design for easy customization
- **Database Optimization**: Efficient schema for large-scale experiments

## Requirements

- Drupal 10.3+ or Drupal 11+
- PHP 8.0+ (utilizes cryptographically secure random number generation)
- MySQL 5.7+ or PostgreSQL 10+ (for optimal performance)

## Installation

### Via Composer (Recommended)

```bash
composer require drupal/rl
drush en rl
```

### Manual Installation

1. Download and extract the module to `modules/contrib/rl`
2. Enable the module: `drush en rl`
3. Clear caches: `drush cr`

The module will automatically create the required database tables during installation.

## Configuration

No configuration is required. The module works out of the box with sensible defaults.

### Optional Configuration

- **Experiment Registry**: Modules can register experiments for better organization
- **Custom Decorators**: Implement `ExperimentDecoratorInterface` to customize arm display names

## Usage

### Basic API Usage

```php
// Get the experiment manager service
$experiment_manager = \Drupal::service('rl.experiment_manager');

// Record a turn (trial) for an arm
$experiment_manager->recordTurn('my-experiment-uuid', 'arm-variant-a');

// Record a reward (success) for an arm
$experiment_manager->recordReward('my-experiment-uuid', 'arm-variant-a');

// Get Thompson Sampling scores for all arms
$scores = $experiment_manager->getUCB1Scores('my-experiment-uuid');

// Select the best arm based on current scores
$ts_calculator = \Drupal::service('rl.ts_calculator');
$best_arm = $ts_calculator->selectBestArm($scores);
```

### Recording Turns and Rewards

```php
use Drupal\rl\Service\ExperimentManagerInterface;

class MyContentController {
  
  public function __construct(
    private ExperimentManagerInterface $experimentManager
  ) {}
  
  public function showContent() {
    $experiment_uuid = 'homepage-hero-test';
    
    // Define your content variants
    $variants = ['original', 'variant-a', 'variant-b'];
    
    // Get Thompson Sampling scores
    $scores = $this->experimentManager->getUCB1Scores($experiment_uuid);
    
    // Select variant with highest score
    $ts_calculator = \Drupal::service('rl.ts_calculator');
    $selected_variant = $ts_calculator->selectBestArm($scores) ?? $variants[0];
    
    // Record the turn
    $this->experimentManager->recordTurn($experiment_uuid, $selected_variant);
    
    // Show the selected variant
    return $this->renderVariant($selected_variant);
  }
  
  public function recordConversion($variant) {
    // User performed desired action - record reward
    $this->experimentManager->recordReward('homepage-hero-test', $variant);
  }
}
```

### Getting Thompson Sampling Scores

```php
// Get scores for decision making
$scores = $experiment_manager->getUCB1Scores('my-experiment');

// Example scores output:
// [
//   'variant-a' => 0.7234,  // Thompson sample from Beta distribution
//   'variant-b' => 0.6891,
//   'variant-c' => 0.8123,
// ]

// Variant 'c' would be selected in this example
```

### HTTP API Endpoints

#### Record a Turn
```http
POST /rl/experiment/{experiment_uuid}/turns
Content-Type: application/json

{
  "arm_ids": ["variant-a"]
}
```

#### Record a Reward
```http
POST /rl/experiment/{experiment_uuid}/rewards
Content-Type: application/json

{
  "arm_id": "variant-a"
}
```

#### Get Thompson Sampling Scores
```http
GET /rl/experiment/{experiment_uuid}/scores

Response:
{
  "scores": {
    "variant-a": 0.7234,
    "variant-b": 0.6891
  }
}
```

## Architecture

### Core Services

- **`rl.experiment_manager`**: Main service for experiment operations
- **`rl.ts_calculator`**: Thompson Sampling algorithm implementation
- **`rl.experiment_data_storage`**: Database abstraction layer
- **`rl.experiment_registry`**: Optional experiment registration system

### Database Schema

#### `rl_arm_data`
Stores turn and reward data for each experiment arm:
- `experiment_uuid`: Experiment identifier
- `arm_id`: Variant/arm identifier  
- `turns`: Number of trials
- `rewards`: Number of successes
- `created`, `updated`: Timestamps

#### `rl_experiment_totals`
Tracks total turns per experiment for optimization.

#### `rl_experiment_registry` 
Optional registry linking experiments to owning modules.

## Thompson Sampling Algorithm

This module implements Thompson Sampling using a **Beta-Bernoulli** model:

- **Prior**: Beta(1, 1) - uniform distribution
- **Likelihood**: Bernoulli trials (success/failure)
- **Posterior**: Beta(α, β) where α = rewards + 1, β = failures + 1

### Algorithm Details

1. For each arm, sample from Beta(α, β) distribution
2. Select arm with highest sampled value
3. Update α and β based on observed outcome

### Implementation Features

- **Pure PHP**: No external libraries required
- **Marsaglia-Tsang Algorithm**: Efficient Gamma distribution sampling
- **Box-Muller Transform**: Standard normal generation
- **Cryptographic Security**: Uses `random_int()` for security
- **Performance**: ~1-2 microseconds per arm

## Performance

### Benchmarks

- **Single arm scoring**: ~0.7 μs
- **Two-arm comparison**: ~1.4 μs  
- **Database operations**: ~0.5-1 ms (typical)

### Optimization Tips

- Use batch operations for multiple turns: `recordTurns()`
- Cache experiment UUIDs to avoid string operations
- Consider experiment archival for very long-running tests

### Best Practices for Data Collection

**⚠️ Important: Use Viewport-Based Tracking for Turns**

When implementing turn tracking (recording when content is shown to users), it's critical to use **viewport-based detection** rather than server-side page rendering:

```javascript
// ✅ RECOMMENDED: Track turns when content enters viewport
const observer = new IntersectionObserver((entries) => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      // Record turn only when user actually sees the content
      recordTurn(experimentUuid, entry.target.dataset.armId);
    }
  });
});

// ✅ RECOMMENDED: Track rewards on actual user interaction
document.addEventListener('click', (event) => {
  if (event.target.dataset.armId) {
    recordReward(experimentUuid, event.target.dataset.armId);
  }
});
```

**Why viewport-based tracking matters:**

- **Bot Protection**: Server-side rendering records turns for bot traffic, skewing data
- **Genuine User Engagement**: Only counts content actually seen by real users
- **Accurate Success Rates**: Prevents false turns from improving apparent performance
- **Data Quality**: Ensures Thompson Sampling learns from authentic user behavior

**Avoid server-side turn recording:**
```php
// ❌ AVOID: Recording turns on page render
// This counts bots, crawlers, and content users never see
$experiment_manager->recordTurn($uuid, $node_id); // Don't do this
```

This approach ensures your multi-armed bandit experiments learn from genuine user engagement rather than artificial traffic.

## Reporting and Analytics

### Administrative Interface

Visit `/admin/reports/rl` to view:

- All active experiments
- Per-experiment arm performance
- Success rates and confidence metrics
- Thompson Sampling expected values

### Experiment Details

For each experiment, view:
- **Turns**: Total trials per arm
- **Rewards**: Total successes per arm  
- **Success Rate**: Conversion percentage
- **TS Score**: Expected success rate (Beta mean)
- **Timeline**: First seen and last updated

## Troubleshooting

### Common Issues

**Q: Scores seem random/inconsistent**
A: This is expected! Thompson Sampling is probabilistic. Arms with similar performance will have similar selection probabilities.

**Q: New arms never get selected**
A: Thompson Sampling naturally explores new arms. Ensure you're not pre-filtering based on historical data.

**Q: Performance issues with many arms**
A: Consider experiment segmentation or archival. The algorithm scales O(n) with arm count.

### Debugging

Enable Drupal logging to see experiment operations:

```php
// Add to settings.php for debugging
$config['system.logging']['error_level'] = 'verbose';
```

Check recent experiments:
```sql
SELECT * FROM rl_arm_data ORDER BY updated DESC LIMIT 10;
```

### Cache Considerations

The module doesn't cache scores intentionally - Thompson Sampling requires fresh randomness. If you need deterministic behavior for testing, consider implementing a seeded random number generator.

## Contributing

### Development Setup

```bash
# Clone and install dependencies
git clone [repository-url]
cd rl
composer install

# Run tests
phpunit

# Code style
phpcs --standard=Drupal src/
```

### Submitting Patches

1. Follow [Drupal coding standards](https://www.drupal.org/docs/develop/standards)
2. Include tests for new functionality
3. Update documentation as needed
4. Submit patches to the issue queue

## Maintainers

- [Your Name] - [email@domain.com]

---

For support, feature requests, or bug reports, please use the [Drupal.org issue queue](https://www.drupal.org/project/issues/rl).

## License

This project is licensed under the GPL-2.0+ license - see the [LICENSE.txt](LICENSE.txt) file for details.