<?php

namespace Drupal\rl_menu_link\Service;

use Drupal\rl\Experiment\VariantSelectorBase;
use Drupal\rl_menu_link\Entity\MenuLinkExperiment;

/**
 * Selects the winning variant for a menu link by its plugin ID.
 */
class MenuLinkVariantSelector extends VariantSelectorBase {

  /**
   * {@inheritdoc}
   */
  protected function entityTypeId(): string {
    return 'rl_menu_link_experiment';
  }

  /**
   * {@inheritdoc}
   */
  protected function entityClass(): string {
    return MenuLinkExperiment::class;
  }

  /**
   * {@inheritdoc}
   */
  protected function targetProperty(): string {
    return 'menu_link_plugin_id';
  }

  /**
   * Select the winning variant for a menu link plugin ID.
   */
  public function selectForPluginId(string $plugin_id): ?array {
    return $this->selectForTarget(trim($plugin_id));
  }

}
