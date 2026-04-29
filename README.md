> Part of [DXPR CMS](https://dxpr.com/c/marketing-cms): The AI-Powered Drupal CMS
>
> [Documentation](https://dxpr.com/docs) | [Try Free](https://dxpr.com/try) | [dxpr.com](https://dxpr.com)

# Reinforcement Learning (RL): Adaptive A/B Testing for Drupal with Thompson Sampling

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
See `ai_sorting`'s Views sort plugin for the canonical pattern and
`VariantSelectorBase` in this module for a reusable base class.

### Client-side (cache-friendly path)

Some consumers have to decide in JS: full-page-cached builders that
render all variants into the HTML and swap them on the client so they
can keep Varnish/Fastly caching. For that case there is
`Drupal.rl.decide()`, documented below.

## JavaScript API (`Drupal.rl`)

Attach the `rl/api` library to make `Drupal.rl` available. It is a thin
transport proxy that coalesces every decide, impression, and conversion
fired on the page into a single batched POST to `rl.php`, so N
experiments on the same page produce one or two requests instead of one
set per experiment.

```javascript
// Record an impression when the variant becomes visible.
Drupal.rl.turn('hero_cta', 'v0');

// Record a conversion when the user clicks / submits / converts.
Drupal.rl.reward('hero_cta', 'v0');

// Ask for a decision when the variant needs to be chosen client-side.
// The arm list MUST be read from the DOM - see discipline below.
var container = document.querySelector('[data-rl-experiment="hero_cta"]');
var armIds = container.dataset.rlArms.split(',');
Drupal.rl.decide('hero_cta', armIds).then(function (armId) {
  showVariant(armId);
});

// Optional: force an immediate flush.
Drupal.rl.flush();
```

Events accumulate for 500 ms and then flush in one POST. Decide,
turn, and reward events share the same queue and the same request, so
a page with a DXPR Builder variant block plus tracking on other
elements ends up making a single round trip. Buffered tracking events
are also flushed via `navigator.sendBeacon` on `visibilitychange` and
`pagehide` so they survive navigation.

### Discipline for `decide()`

> **Never hardcode arm ids in JS. Always read them from a DOM
> attribute that the server-side renderer emitted.**

Rationale: the DOM is downstream of the same server-render pipeline
that produced the decide's context. When the experiment manager adds
or removes a variant, the consumer's page cache is invalidated, the
next render emits the new attribute, and JS picks it up. JS never
asserts what the arm set is - it just echoes whatever the current
cached HTML says, mirroring ai_sorting's PHP pattern of recomputing
`$arm_ids` from a fresh view query on every render. This keeps
`Drupal.rl.decide()` drift-free without requiring the rl core to
store arm lists.

The convention your builder uses internally (numeric `v0..vN`, UUIDs,
node ids, anything matching `^[a-zA-Z0-9_-]+$`) is whatever you emit
into the attribute. The rl core is arm-agnostic.

If the server returns no decision for an experiment (not registered,
no data, network error), the returned promise resolves to `armIds[0]`
so callers never need a `.catch()` for the common path.

`Drupal.rl` is one transport among several. Modules that already ship
their own tracking JS (like `ai_sorting`, which batches turns on its
own 100 ms window and posts them as form data) keep working untouched.

## HTTP API (`rl.php`)

`rl.php` is the low-level endpoint. It is reachable directly from any
client that can make an HTTP POST - browser pages, native mobile apps,
server-side workers, other CMSes, edge functions. Deciding is *not*
exposed here: it happens in PHP at render time as shown above.

Four actions are supported. All are additive - adding `batch` did not
deprecate the legacy form actions, and `ai_sorting` and other production
consumers keep using them unchanged.

| Action | Encoding | Purpose |
| --- | --- | --- |
| `ping` | form POST | Liveness check. Returns `pong`. |
| `turn` | form POST | Record one impression. |
| `turns` | form POST | Record impressions for many arms in one experiment. |
| `reward` | form POST | Record one conversion. |
| `batch` | JSON POST | Record turns and rewards across many experiments in one request. Used by `Drupal.rl`. |

Experiment IDs and arm IDs must match `^[a-zA-Z0-9_-]+$`. Experiments
must already be registered via `ExperimentRegistryInterface::register()`;
unknown IDs are silently dropped so garbage writes cannot create
registry entries.

### Legacy form actions

```bash
# Turn
curl -X POST https://example.com/modules/contrib/rl/rl.php \
  -d 'action=turn&experiment_id=hero_cta&arm_id=v0'

# Multiple arms in one experiment
curl -X POST https://example.com/modules/contrib/rl/rl.php \
  -d 'action=turns&experiment_id=hero_cta&arm_ids=v0,v1'

# Reward
curl -X POST https://example.com/modules/contrib/rl/rl.php \
  -d 'action=reward&experiment_id=hero_cta&arm_id=v0'
```

### `action=batch`

```
POST /modules/contrib/rl/rl.php?action=batch
Content-Type: application/json

{
  "decides": [
    {"id": "hero_cta", "arms": ["v0", "v1", "v2"]}
  ],
  "turns": [
    {"id": "hero_cta", "arm": "v0"},
    {"id": "menu_main_5", "arm": "v1"}
  ],
  "rewards": [
    {"id": "hero_cta", "arm": "v0"}
  ]
}
```

All three sections are optional. Invalid or unregistered entries are
dropped silently so one bad event does not poison the rest of the
batch. The response is

```json
{"ok":true,"decisions":{"hero_cta":{"armId":"v1"}}}
```

`decisions` contains only entries that had a successful Thompson
Sampling lookup. Missing keys mean "use the default variant". Turns
and rewards are fire-and-forget writes with no per-event response.

### Curl examples

```bash
# Batch tracking from a server-side job.
curl -X POST 'https://example.com/modules/contrib/rl/rl.php?action=batch' \
  -H 'Content-Type: application/json' \
  -d '{"turns":[{"id":"hero_cta","arm":"v1"}],"rewards":[{"id":"hero_cta","arm":"v1"}]}'

# Liveness probe.
curl -X POST 'https://example.com/modules/contrib/rl/rl.php' -d 'action=ping'
# => pong
```

### Error responses

| Status | When |
| --- | --- |
| `400` | Missing/invalid `action`, malformed JSON, or missing `experiment_id` on a legacy action. |
| `500` | Drupal kernel failed to boot. Error logged to the PHP error log. |

### Performance notes

`rl.php` bootstraps a minimal Drupal kernel per request (same pattern as
core's `statistics.php`), not the full stack that would run behind a
normal route. One kernel boot processes the whole batch, so the cheapest
way to use this endpoint is to send as many events as possible in one
request. `Drupal.rl` already does this on the browser side; non-browser
callers should coalesce events similarly when they can.

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

---

## Related DXPR Modules

- [RL Sorting](https://www.drupal.org/project/rl_sorting): Intelligent content ordering for Drupal Views using reinforcement learning
- [Analyze](https://www.drupal.org/project/analyze): Content analysis and quality scoring for Drupal
- [AI Content Strategy](https://www.drupal.org/project/ai_content_strategy): AI-driven content strategy recommendations for Drupal
