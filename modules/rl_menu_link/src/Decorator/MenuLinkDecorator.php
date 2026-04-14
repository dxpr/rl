<?php

namespace Drupal\rl_menu_link\Decorator;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\rl\Experiment\VariantExperimentDecoratorBase;
use Drupal\rl\Experiment\VariantExperimentInterface;
use Drupal\rl_menu_link\Entity\MenuLinkExperiment;

/**
 * Decorates RL reports with menu link experiment data.
 */
class MenuLinkDecorator extends VariantExperimentDecoratorBase {

  /**
   * The menu link manager.
   *
   * @var \Drupal\Core\Menu\MenuLinkManagerInterface
   */
  protected MenuLinkManagerInterface $menuLinkManager;

  /**
   * Constructs a MenuLinkDecorator.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, MenuLinkManagerInterface $menu_link_manager) {
    parent::__construct($entity_type_manager);
    $this->menuLinkManager = $menu_link_manager;
  }

  /**
   * {@inheritdoc}
   */
  protected function experimentIdPrefix(): string {
    return 'rl_menu_link-';
  }

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
  protected function buildExperimentDisplay(VariantExperimentInterface $experiment): array {
    assert($experiment instanceof MenuLinkExperiment);
    $plugin_id = $experiment->getMenuLinkPluginId();
    $original_label = $this->getOriginalLabel($plugin_id) ?? $plugin_id;
    $langcode = $experiment->language()->getId();
    $lang_label = $langcode === LanguageInterface::LANGCODE_NOT_SPECIFIED
      ? (string) t('all languages')
      : $langcode;
    return [
      '#type' => 'inline_template',
      '#template' => '{{ label }} <small>({{ plugin_id }}, {{ lang }})</small>',
      '#context' => [
        'label' => $experiment->label() ?: $original_label,
        'plugin_id' => $plugin_id,
        'lang' => $lang_label,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function buildOriginalArmDisplay(VariantExperimentInterface $experiment): array {
    assert($experiment instanceof MenuLinkExperiment);
    $original = $this->getOriginalLabel($experiment->getMenuLinkPluginId());
    return [
      '#type' => 'inline_template',
      '#template' => '<em>{{ label }}</em>',
      '#context' => ['label' => $original ?: (string) t('(original)')],
    ];
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
