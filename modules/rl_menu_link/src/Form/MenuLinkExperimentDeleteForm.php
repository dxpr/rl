<?php

namespace Drupal\rl_menu_link\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\rl\Experiment\VariantExperimentDeleteFormBase;
use Drupal\rl_menu_link\Entity\MenuLinkExperiment;

/**
 * Confirmation form for deleting a Menu Link experiment.
 *
 * The base class handles purge-then-delete plus cache-tag invalidation; this
 * subclass supplies the tag list for every menu rendering the variant.
 */
class MenuLinkExperimentDeleteForm extends VariantExperimentDeleteFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getCacheTagsToInvalidate(EntityInterface $entity): array {
    if (!$entity instanceof MenuLinkExperiment) {
      return [];
    }
    return [
      'rl_menu_link:all',
      'rl_menu_link:' . $entity->getMenuLinkPluginId(),
    ];
  }

}
