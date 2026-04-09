<?php

namespace Drupal\rl_menu_link\Decorator;

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
   * Map of RL experiment ID to entity, built lazily on first lookup.
   *
   * @var array<string, \Drupal\rl_menu_link\Entity\MenuLinkExperiment>|null
   */
  protected ?array $experimentMap = NULL;

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
      '#type' => 'inline_template',
      '#template' => '{{ label }} <small>({{ plugin_id }})</small>',
      '#context' => [
        'label' => $experiment->label() ?: $original_label,
        'plugin_id' => $plugin_id,
      ],
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
        '#type' => 'inline_template',
        '#template' => '<em>{{ label }}</em>',
        '#context' => ['label' => $original ?: (string) t('(original)')],
      ];
    }
    return [
      '#type' => 'inline_template',
      '#template' => '{{ text }}',
      '#context' => ['text' => $text],
    ];
  }

  /**
   * Load an experiment by RL experiment ID using a lazy O(N once)/O(1) map.
   */
  protected function loadExperiment(string $experiment_id): ?MenuLinkExperiment {
    if (!str_starts_with($experiment_id, 'rl_menu_link-')) {
      return NULL;
    }
    if ($this->experimentMap === NULL) {
      $this->experimentMap = [];
      $storage = $this->entityTypeManager->getStorage('rl_menu_link_experiment');
      foreach ($storage->loadMultiple() as $experiment) {
        if ($experiment instanceof MenuLinkExperiment) {
          $this->experimentMap[$experiment->getRlExperimentId()] = $experiment;
        }
      }
    }
    return $this->experimentMap[$experiment_id] ?? NULL;
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
