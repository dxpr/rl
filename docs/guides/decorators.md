# Experiment decorators

Decorators customise how experiments and arms are displayed in the RL reports
interface. By default, experiments and arms show their raw IDs, but decorators
can provide human-readable labels.

## Creating a decorator

Implement the `ExperimentDecoratorInterface`:

```php
<?php

namespace Drupal\my_module\Decorator;

use Drupal\rl\Decorator\ExperimentDecoratorInterface;

/**
 * Decorates experiments owned by my_module.
 */
class MyExperimentDecorator implements ExperimentDecoratorInterface {

  /**
   * {@inheritdoc}
   */
  public function decorateExperiment(string $experiment_id): ?array {
    if (!str_starts_with($experiment_id, 'my_module-')) {
      return NULL;
    }
    return ['#markup' => 'My Custom Experiment Name'];
  }

  /**
   * {@inheritdoc}
   */
  public function decorateArm(string $experiment_id, string $arm_id): ?array {
    if (!str_starts_with($experiment_id, 'my_module-')) {
      return NULL;
    }
    $entity = \Drupal::entityTypeManager()->getStorage('node')->load($arm_id);
    if ($entity) {
      $label = htmlspecialchars($entity->label());
      $id = htmlspecialchars($arm_id);
      return [
        '#markup' => $label . ' <small>(' . $id . ')</small>',
      ];
    }
    return NULL;
  }

}
```

## Registering the decorator

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

## Best practices

- **Check experiment prefix**: return `NULL` early for experiments your
  decorator does not handle.
- **Handle missing entities**: entities may be deleted; return `NULL` if the
  entity cannot be loaded.
- **Use render arrays**: return proper Drupal render arrays for consistent
  theming and security.
- **Escape output**: use `htmlspecialchars()` for any user-provided content.
