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

### Plotly.js Library (Required for Charts)

The RL module uses Plotly.js for experiment charts. Install it via Composer using
[Asset Packagist](https://asset-packagist.org):

```bash
# Add Asset Packagist repository (if not already configured)
composer config repositories.asset-packagist composer https://asset-packagist.org

# Install the Composer plugin for npm assets (if not already installed)
composer require oomphinc/composer-installers-extender
composer config extra.installer-types --json '["npm-asset"]'
composer config extra.installer-paths.web/libraries/\{\$name\} --json '["type:npm-asset"]'

# Install Plotly.js
composer require npm-asset/plotly.js-dist-min:^2.35
```

This installs the library to `web/libraries/plotly.js-dist-min/`.

### Post-Installation: Verify rl.php Access

The RL module includes a `.htaccess` file that allows direct access to
`rl.php` (following the same pattern as Drupal 11's contrib statistics
module). Test that it's working:

```bash
# Test if rl.php is accessible
curl -X POST -d "action=turns&experiment_id=test&arm_ids=1" \
  http://example.com/modules/contrib/rl/rl.php
```

**If the test fails:**

- **Apache**: Ensure `.htaccess` files are processed (`AllowOverride All`)
- **Nginx**: Add the configuration rules below to your server block
- **Security modules**: Whitelist `/modules/contrib/rl/rl.php`

#### Nginx Configuration

Add these rules to your Nginx server block, **before** the main Drupal location block:

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

If server policies prevent direct access to `rl.php`, use the Drupal
Routes API instead.

## Drush Command Reference

### Discovery & Analysis

| Command | Alias | Description |
|---------|-------|-------------|
| `rl:list` | `rll` | Lists all experiments with summary stats |
| `rl:status <id>` | `rlst` | Detailed status: phase, confidence, traffic distribution |
| `rl:performance <id>` | `rlp` | Arm-level performance with human-readable labels |
| `rl:trends <id>` | `rlt` | Historical trends with period aggregation |
| `rl:export <id>` | `rle` | Exports complete experiment data |
| `rl:analyze <id>` | `rla` | Full analysis with actionable recommendations |

### Experiment Lifecycle

| Command | Alias | Description |
|---------|-------|-------------|
| `rl:experiment:create <id>` | `rl-ec` | Creates experiment (`--module`, `--name`, `--dry-run`) |
| `rl:experiment:update <id>` | `rl-eu` | Updates name/module (`--module`, `--name`, `--dry-run`) |
| `rl:experiment:delete <id>` | `rl-ed` | Deletes experiment + all data (`--dry-run`) |

### Configuration

| Command | Alias | Description |
|---------|-------|-------------|
| `rl:config:get [key]` | `rl-cg` | Gets one or all settings |
| `rl:config:set <key> <value>` | `rl-cs` | Sets a setting with validation (`--dry-run`) |
| `rl:config:list` | `rl-cl` | Lists all settings with current values |
| `rl:config:reset` | `rl-cr` | Resets all settings to defaults (`--dry-run`) |
| `rl:event-log:clear` | `rl-elc` | Clears all snapshots (`--dry-run`) |

### Setup

| Command | Alias | Description |
|---------|-------|-------------|
| `rl:setup-ai` | `rl-sa` | Installs AI skill files for Claude Code (`--host`, `--check`) |

```bash
# Create a new A/B test
drush rl:experiment:create hero_cta_test \
  --module=my_module \
  --name="Hero CTA Button Color Test"

# Check status after data collection
drush rl:status hero_cta_test

# Get detailed performance breakdown
drush rl:performance hero_cta_test --format=yaml

# Full analysis with recommendations
drush rl:analyze hero_cta_test
```

## AI Coding Assistant Integration

The RL module ships with a built-in skill file
(`.claude/skills/rl/SKILL.md`) that teaches AI coding assistants how
to manage experiments through natural language. After installation, use
the `/rl` slash command or ask naturally:

```
/rl list all experiments
/rl analyze hero_cta_test
/rl create experiment homepage_banner --module=my_module
```

### Quick Setup

```bash
# Install skill files for AI tool discovery
drush rl:setup-ai

# Claude Code only
drush rl:setup-ai --host=claude

# Codex/Gemini/Copilot/Cursor only
drush rl:setup-ai --host=agents
```

Compatible with Claude Code, Codex CLI, Gemini CLI, GitHub Copilot,
Cursor, and other tools supporting the
[Agent Skills standard](https://agentskills.io/specification).

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

### rl.php - Direct Endpoint (Recommended)
**Use the direct rl.php endpoint for optimal performance:**

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

## Experiment Decorators

Decorators customize how experiments and arms are displayed in the RL reports
interface. By default, experiments and arms show their raw IDs, but decorators
can provide human-readable labels.

### Creating a Decorator

Implement the `ExperimentDecoratorInterface`:

```php
<?php

namespace Drupal\my_module\Decorator;

use Drupal\rl\Decorator\ExperimentDecoratorInterface;

class MyExperimentDecorator implements ExperimentDecoratorInterface {

  /**
   * {@inheritdoc}
   */
  public function decorateExperiment(string $experiment_id): ?array {
    // Return NULL to skip, or a render array for custom display.
    if (!str_starts_with($experiment_id, 'my_module-')) {
      return NULL;
    }
    return ['#markup' => 'My Custom Experiment Name'];
  }

  /**
   * {@inheritdoc}
   */
  public function decorateArm(string $experiment_id, string $arm_id): ?array {
    // Return NULL to skip, or a render array for custom display.
    if (!str_starts_with($experiment_id, 'my_module-')) {
      return NULL;
    }
    // Example: Load entity and return its label.
    $entity = \Drupal::entityTypeManager()->getStorage('node')->load($arm_id);
    if ($entity) {
      return [
        '#markup' => htmlspecialchars($entity->label()) .
          ' <small>(' . htmlspecialchars($arm_id) . ')</small>',
      ];
    }
    return NULL;
  }

}
```

### Registering the Decorator

Add the decorator service to your module's `*.services.yml` with the
`rl_experiment_decorator` tag:

```yaml
services:
  my_module.experiment_decorator:
    class: Drupal\my_module\Decorator\MyExperimentDecorator
    arguments: ['@entity_type.manager']
    tags:
      - { name: rl_experiment_decorator }
```

The decorator manager automatically discovers all tagged services and calls
them in order until one returns a non-NULL value.

### Best Practices

- **Check experiment prefix**: Return `NULL` early for experiments your
  decorator doesn't handle.
- **Handle missing entities**: Entities may be deleted; return `NULL` if the
  entity can't be loaded.
- **Use render arrays**: Return proper Drupal render arrays for consistent
  theming and security.
- **Escape output**: Use `htmlspecialchars()` for any user-provided content.

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
