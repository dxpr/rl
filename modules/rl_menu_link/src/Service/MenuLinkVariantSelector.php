<?php

namespace Drupal\rl_menu_link\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\rl\Experiment\VariantSelectorBase;
use Drupal\rl\Service\CacheManager;
use Drupal\rl\Service\ExperimentManagerInterface;
use Drupal\rl_menu_link\Entity\MenuLinkExperiment;

/**
 * Selects the winning variant for a menu link by its plugin ID.
 *
 * Multilingual model: each lookup tries the current request language
 * first, then falls back to LANGCODE_NOT_SPECIFIED via the base class.
 */
class MenuLinkVariantSelector extends VariantSelectorBase {

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * Constructs a MenuLinkVariantSelector.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ExperimentManagerInterface $experiment_manager,
    CacheManager $cache_manager,
    LanguageManagerInterface $language_manager,
  ) {
    parent::__construct($entity_type_manager, $experiment_manager, $cache_manager);
    $this->languageManager = $language_manager;
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
  protected function targetProperty(): string {
    return 'menu_link_plugin_id';
  }

  /**
   * Select the winning variant for a menu link plugin ID.
   */
  public function selectForPluginId(string $plugin_id): ?array {
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId();
    return $this->selectForTarget(trim($plugin_id), $langcode);
  }

}
