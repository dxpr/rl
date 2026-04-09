<?php

namespace Drupal\rl_menu_link;

use Drupal\Core\Entity\EntityInterface;
use Drupal\rl\Experiment\VariantExperimentListBuilderBase;
use Drupal\rl_menu_link\Entity\MenuLinkExperiment;

/**
 * List builder for Menu Link experiments.
 */
class MenuLinkExperimentListBuilder extends VariantExperimentListBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function entityClass(): string {
    return MenuLinkExperiment::class;
  }

  /**
   * {@inheritdoc}
   */
  protected function targetColumnLabel(): string {
    return $this->t('Menu link plugin ID');
  }

  /**
   * {@inheritdoc}
   */
  protected function emptyMessage(): string {
    return $this->t('No menu link experiments yet. Add one to start A/B testing.');
  }

  /**
   * {@inheritdoc}
   */
  protected function targetColumnValue(EntityInterface $entity): string {
    assert($entity instanceof MenuLinkExperiment);
    return $entity->getMenuLinkPluginId();
  }

}
