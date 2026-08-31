# Building a consumer module

RL is a framework; on its own it stores experiment data and runs Thompson
Sampling. The actual "what gets tested" lives in consumer modules. This guide
walks through the architecture consumer modules share, using the two bundled
submodules (`rl_page_title` and `rl_menu_link`) as worked examples.

## Integration patterns at a glance

| Pattern | Server-side (recommended) | Client-side |
| --- | --- | --- |
| **Decision** | PHP at render time via `VariantSelectorBase` | JS via `Drupal.rl.decide()` after page load |
| **Turn** | JS on viewport entry or page load via `Drupal.rl.turn()` | Same |
| **Reward** | JS on conversion event via `Drupal.rl.reward()`, or PHP via `ExperimentManager::recordReward()` | Same |
| **Caching** | `CacheManager::overridePageCacheIfShorter()` ensures variants rotate | Full-page cache safe; all variants rendered, swapped client-side |
| **Examples** | `rl_page_title`, `rl_menu_link`, `rl_sorting` | DXPR Builder |

## Anatomy of a consumer module

A server-side consumer module typically provides five pieces:

1. **Experiment entity**: a content entity implementing `VariantExperimentInterface`
2. **Variant selector service**: a service extending `VariantSelectorBase`
3. **Report decorator service**: a service extending `VariantExperimentDecoratorBase`
4. **Hook integration**: hooks that intercept Drupal's render pipeline and swap content
5. **Tracking JS**: a small Drupal behaviour that records turns and rewards via `Drupal.rl`

### 1. Experiment entity

The experiment entity holds the variant data. Both `rl_page_title` and
`rl_menu_link` follow the same pattern: a content entity with
`VariantExperimentInterface` and `VariantArmsTrait`.

Key fields shared across both implementations:

- `label`: human-readable name for admin UIs and reports
- `variants_data`: JSON-encoded list of variant text strings
- `lookup_hash`: computed `sha256(target|langcode)` backed by a UNIQUE index
- `enabled`: publish/unpublish toggle (via `EntityPublishedTrait`)
- `langcode`: scopes the experiment per language

The arm convention enforced by `VariantArmsTrait`:

- `v0` = original text (read live from the source, never stored)
- `v1`..`vN` = stored variant texts, indexed sequentially

Each entity class provides two static helpers:

- `computeLookupHash(target, langcode)`: the UNIQUE index key for O(1) runtime lookup
- `buildRlExperimentId(target, langcode)` (via `VariantArmsTrait::buildVariantExperimentId()`):
  a deterministic `{module}-{12-char-sha1}` hash used as the key into RL's
  analytics tables

**rl_page_title** targets a normalised internal path (e.g. `/node/42`):

```php
$fields['path'] = BaseFieldDefinition::create('string')
  ->setLabel(t('Page URL or path'))
  ->setRequired(TRUE)
  ->setSetting('max_length', 2048);
```

**rl_menu_link** targets a menu link plugin ID (e.g.
`menu_link_content:some-uuid`):

```php
$fields['menu_link_plugin_id'] = BaseFieldDefinition::create('string')
  ->setLabel(t('Menu link plugin ID'))
  ->setRequired(TRUE)
  ->setSetting('max_length', 255);
```

Your module will have its own target identifier; the pattern stays the same.

### 2. Variant selector service

Extend `VariantSelectorBase` and implement four abstract methods. The base
class handles Thompson Sampling scoring, per-request caching, self-healing
registry registration, multilingual fallback, and page cache override.

Here is `rl_page_title`'s selector, trimmed to the essentials:

```php
class TitleVariantSelector extends VariantSelectorBase {

  protected function ownerModule(): string {
    return 'rl_page_title';
  }

  protected function entityTypeId(): string {
    return 'rl_page_title_experiment';
  }

  protected function entityClass(): string {
    return PageTitleExperiment::class;
  }

  protected function computeLookupHash(
    string $target,
    string $langcode
  ): string {
    return PageTitleExperiment::computeLookupHash($target, $langcode);
  }

  public function selectForCurrentPage(): ?array {
    return $this->selectForTarget(
      $this->getCurrentInternalPath(),
      $this->languageManager
        ->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)
        ->getId()
    );
  }

}
```

`rl_menu_link` is nearly identical; only the target identifier changes
from an internal path to a menu link plugin ID.

The return value from `selectForTarget()` is either `NULL` (no active
experiment) or an array:

```php
[
  'experiment_id' => 'rl_page_title-a1b2c3d4e5f6',
  'arm_id'        => 'v1',
  'text'          => 'The winning variant text',
]
```

Register the service in `*.services.yml`:

```yaml
services:
  my_module.variant_selector:
    class: Drupal\my_module\Service\MyVariantSelector
    arguments:
      - '@entity_type.manager'
      - '@rl.experiment_manager'
      - '@rl.cache_manager'
      - '@rl.experiment_registry'
```

### 3. Report decorator service

The RL reports page (`/admin/reports/rl`) shows raw experiment and arm IDs by
default. A decorator maps those IDs back to something human-readable.

Extend `VariantExperimentDecoratorBase` and implement:

- `experimentIdPrefix()`: the prefix your experiment IDs start with
  (e.g. `rl_page_title-`)
- `entityTypeId()`, `entityClass()`: which entity to load
- `buildExperimentDisplay()`: render array for the experiment label
- `buildOriginalArmDisplay()`: render array for the v0 (original) arm

Tag the service with `rl_experiment_decorator`:

```yaml
services:
  my_module.decorator:
    class: Drupal\my_module\Decorator\MyDecorator
    arguments: ['@entity_type.manager']
    tags:
      - { name: rl_experiment_decorator }
```

See the [Experiment decorators guide](decorators.md) for a standalone
example that does not use the base class.

### 4. Hook integration

This is where consumer modules diverge most. The hooks depend on what you are
testing.

**rl_page_title** uses `hook_preprocess_page_title()` and
`hook_preprocess_html()` to swap the visible and `<title>` tag titles:

```php
function rl_page_title_preprocess_page_title(array &$variables) {
  $result = \Drupal::service('rl_page_title.variant_selector')
    ->selectForCurrentPage();
  if ($result && $result['text'] !== NULL) {
    $variables['title'] = $result['text'];
  }
}
```

**rl_menu_link** uses `hook_preprocess_menu()` to walk the menu tree and
replace link titles:

```php
function rl_menu_link_preprocess_menu(array &$variables) {
  foreach ($variables['items'] as &$item) {
    $plugin_id = $item['original_link']->getPluginId();
    $result = $selector->selectForPluginId($plugin_id);
    if ($result && $result['text'] !== NULL) {
      $item['title'] = $result['text'];
    }
  }
}
```

Both modules also use `hook_form_alter()` to add a "variant titles" vertical
tab on entity edit forms, so editors can manage experiments without visiting a
separate admin page. They attach the experiment data to the host entity's save
flow.

Both implement `hook_entity_predelete()` to cascade-delete experiments and
purge analytics when the parent entity is removed.

### 5. Tracking JavaScript

Each consumer attaches a small JS behaviour that calls `Drupal.rl.turn()` and
`Drupal.rl.reward()`. The pattern varies by what counts as an impression and
what counts as a conversion:

| Module | Turn (impression) | Reward (conversion) |
| --- | --- | --- |
| `rl_page_title` | Page load | Visitor stays 10+ seconds |
| `rl_menu_link` | Link enters viewport (IntersectionObserver) | Visitor clicks the link |
| `rl_example` | Form enters viewport | AJAX form submit (server-side `recordReward()`) |
| `rl_example_frontend` | Form enters viewport | Click event via `Drupal.rl.reward()` |

Pass the experiment ID and arm ID to the page via `drupalSettings`:

```php
$build['#attached']['drupalSettings']['myModule'] = [
  'experimentId' => $result['experiment_id'],
  'armId' => $result['arm_id'],
];
```

Then in your JS:

```javascript
Drupal.behaviors.myModuleTracking = {
  attach(context) {
    once('my-module-tracking', '.my-element', context)
      .forEach((el) => {
        var config = drupalSettings.myModule;
        Drupal.rl.turn(config.experimentId, config.armId);

        el.addEventListener('click', function () {
          Drupal.rl.reward(config.experimentId, config.armId);
        });
      });
  },
};
```

All `Drupal.rl` calls are batched (500ms window) and sent to `rl.php` in a
single request. See the [JavaScript API](../api/javascript.md) for the full
reference.

## Without VariantSelectorBase: the direct API approach

Not every integration needs a content entity and a selector service. For
simpler cases (a block, a form, a widget), you can use the RL services
directly. The bundled example modules demonstrate this.

`rl_example` tests which button text gets more newsletter signups:

```php
public function build() {
  $this->experimentRegistry->register(
    'my_experiment',
    'my_module',
    'My A/B Test'
  );

  $scores = $this->experimentManager->getThompsonScores(
    'my_experiment',
    NULL,
    ['subscribe', 'updates', 'notify']
  );
  arsort($scores);
  $best = key($scores);
  $this->cacheManager->overridePageCacheIfShorter(60);

  return ['#markup' => $this->buttonTexts[$best]];
}
```

The three required services:

| Service | Purpose |
| --- | --- |
| `rl.experiment_registry` | Register your experiment so `rl.php` accepts tracking events for it |
| `rl.experiment_manager` | Get Thompson Sampling scores, record turns and rewards |
| `rl.cache_manager` | Shorten the page cache so variants rotate |

## rl_sorting: Views integration

[RL: A/B Test Views Content](https://www.drupal.org/project/rl_sorting)
(`rl_sorting`) is a separate project that integrates RL with Drupal Views.
It provides a Views sort plugin that reorders view results by Thompson
Sampling score, turning any View into an engagement-optimised ranking.

The pattern is fully server-side: the sort plugin calls
`getThompsonScores()` with the content IDs from the View results as arm IDs,
then reorders the rows before rendering. Turns are batched via
`Drupal.rl.turn()` for all visible items; rewards fire when a visitor clicks
through to a listed item.

## DXPR Builder integration

[DXPR Builder](https://www.drupal.org/project/dxpr_builder) uses the
client-side pattern. All variants are rendered into the page HTML (so the page
can be served from Varnish or Fastly), and the builder's JS calls
`Drupal.rl.decide()` at page load to pick the winner and swap the visible
slot. This keeps the full-page cache intact while still running experiments.

## Cache considerations

Server-side selectors need the page cache to expire before variants can
rotate. `VariantSelectorBase` calls
`CacheManager::overridePageCacheIfShorter()` automatically. Both bundled
submodules default to 60 seconds; override `cacheTtl()` in your selector to
change it.

Client-side integrations (DXPR Builder) are fully compatible with aggressive
page caching because the decision happens after load.

See the [Cache management guide](cache.md) for details.

## Putting it together: a checklist

When building a new consumer module:

1. **Define your experiment entity** implementing `VariantExperimentInterface`
   with `VariantArmsTrait`
2. **Create a variant selector service** extending `VariantSelectorBase`
3. **Create a report decorator** extending `VariantExperimentDecoratorBase`
   and tagging it with `rl_experiment_decorator`
4. **Hook into the render pipeline** to swap content with the winning variant
5. **Write tracking JS** that records turns on impression and rewards on
   conversion
6. **Handle cleanup**: implement `hook_entity_predelete()` to cascade-delete
   experiments when the parent entity is removed
7. **Register experiments**: the base class self-heals registration, but
   explicit `register()` calls in form submit handlers ensure the registry
   is in sync from the start
