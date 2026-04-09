<?php

namespace Drupal\rl_page_title\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\rl\Experiment\VariantSelectorBase;
use Drupal\rl\Registry\ExperimentRegistryInterface;
use Drupal\rl\Service\CacheManager;
use Drupal\rl\Service\ExperimentManagerInterface;
use Drupal\rl_page_title\Entity\PageTitleExperiment;

/**
 * Selects the winning page title variant for the current request.
 *
 * Source-agnostic: works for any page Drupal serves regardless of whether
 * the title comes from a node, a Views display, or a custom controller.
 * Matching is path-based via the resolved internal path.
 *
 * Multilingual model: each lookup tries the current request language
 * first, then falls back to LANGCODE_NOT_SPECIFIED ("all languages").
 * The base class handles the fallback; this service supplies the current
 * language code via the language manager.
 */
class TitleVariantSelector extends VariantSelectorBase {

  /**
   * The current path stack.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected CurrentPathStack $currentPath;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * Constructs a TitleVariantSelector.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ExperimentManagerInterface $experiment_manager,
    CacheManager $cache_manager,
    ExperimentRegistryInterface $experiment_registry,
    CurrentPathStack $current_path,
    LanguageManagerInterface $language_manager,
  ) {
    parent::__construct($entity_type_manager, $experiment_manager, $cache_manager, $experiment_registry);
    $this->currentPath = $current_path;
    $this->languageManager = $language_manager;
  }

  /**
   * {@inheritdoc}
   */
  protected function ownerModule(): string {
    return 'rl_page_title';
  }

  /**
   * {@inheritdoc}
   */
  protected function entityTypeId(): string {
    return 'rl_page_title_experiment';
  }

  /**
   * {@inheritdoc}
   */
  protected function entityClass(): string {
    return PageTitleExperiment::class;
  }

  /**
   * {@inheritdoc}
   */
  protected function targetProperty(): string {
    return 'path';
  }

  /**
   * Select the winning variant for the current request.
   *
   * Uses the resolved internal path and the current interface language.
   * Falls back to LANGCODE_NOT_SPECIFIED via the base class if no
   * language-specific experiment exists.
   */
  public function selectForCurrentPage(): ?array {
    return $this->selectForPath(
      $this->getCurrentInternalPath(),
      $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_INTERFACE)->getId()
    );
  }

  /**
   * Select the winning variant for a specific internal path and language.
   *
   * @param string $internal_path
   *   The path to look up. Will be normalized.
   * @param string $langcode
   *   The language code. Defaults to LANGCODE_NOT_SPECIFIED if omitted.
   *
   * @return array|null
   *   The selection result or NULL.
   */
  public function selectForPath(string $internal_path, string $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED): ?array {
    return $this->selectForTarget(
      PageTitleExperiment::normalizePath($internal_path),
      $langcode
    );
  }

  /**
   * Get the resolved internal path for the current request.
   */
  protected function getCurrentInternalPath(): string {
    return PageTitleExperiment::normalizePath($this->currentPath->getPath());
  }

}
