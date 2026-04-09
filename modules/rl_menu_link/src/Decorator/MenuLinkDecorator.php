<?php

namespace Drupal\rl_menu_link\Decorator;

use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\rl\Decorator\ExperimentDecoratorInterface;
use Drupal\rl_menu_link\Entity\MenuLinkExperiment;

/**
 * Decorates RL reports with menu link experiment data.
 */
class MenuLinkDecorator implements ExperimentDecoratorInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The menu link manager.
   *
   * @var \Drupal\Core\Menu\MenuLinkManagerInterface
   */
  protected MenuLinkManagerInterface $menuLinkManager;

  /**
   * Cache of experiments, keyed by RL experiment ID.
   *
   * @var array<string, \Drupal\rl_menu_link\Entity\MenuLinkExperiment|false>
   */
  protected array $cache = [];

  /**
   * Constructs a MenuLinkDecorator.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MenuLinkManagerInterface $menu_link_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->menuLinkManager = $menu_link_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function decorateExperiment(string $experiment_id): ?array {
    $experiment = $this->loadExperiment($experiment_id);
    if (!$experiment) {
      return NULL;
    }
    $plugin_id = $experiment->getMenuLinkPluginId();
    $original_label = $this->getOriginalLabel($plugin_id) ?? $plugin_id;
    return [
      '#markup' => Html::escape($experiment->label() ?: $original_label) . ' <small>(' . Html::escape($plugin_id) . ')</small>',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function decorateArm(string $experiment_id, string $arm_id): ?array {
    $experiment = $this->loadExperiment($experiment_id);
    if (!$experiment) {
      return NULL;
    }
    $text = $experiment->getArmText($arm_id);
    if ($text === NULL) {
      $original = $this->getOriginalLabel($experiment->getMenuLinkPluginId());
      return [
        '#markup' => '<em>' . Html::escape($original ?: (string) t('(original)')) . '</em>',
      ];
    }
    return ['#markup' => Html::escape($text)];
  }

  /**
   * Load an experiment by RL experiment ID.
   */
  protected function loadExperiment(string $experiment_id): ?MenuLinkExperiment {
    if (!str_starts_with($experiment_id, 'rl_menu_link-')) {
      return NULL;
    }
    if (array_key_exists($experiment_id, $this->cache)) {
      return $this->cache[$experiment_id] ?: NULL;
    }
    $storage = $this->entityTypeManager->getStorage('rl_menu_link_experiment');
    /** @var \Drupal\rl_menu_link\Entity\MenuLinkExperiment $experiment */
    foreach ($storage->loadMultiple() as $experiment) {
      if ($experiment->getRlExperimentId() === $experiment_id) {
        $this->cache[$experiment_id] = $experiment;
        return $experiment;
      }
    }
    $this->cache[$experiment_id] = FALSE;
    return NULL;
  }

  /**
   * Get the original label of a menu link by plugin ID.
   */
  protected function getOriginalLabel(string $plugin_id): ?string {
    if (!$this->menuLinkManager->hasDefinition($plugin_id)) {
      return NULL;
    }
    try {
      $link = $this->menuLinkManager->createInstance($plugin_id);
      return (string) $link->getTitle();
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

}
