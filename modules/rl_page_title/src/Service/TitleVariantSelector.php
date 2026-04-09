<?php

namespace Drupal\rl_page_title\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\rl\Experiment\VariantSelectorBase;
use Drupal\rl\Service\CacheManager;
use Drupal\rl\Service\ExperimentManagerInterface;
use Drupal\rl_page_title\Entity\PageTitleExperiment;

/**
 * Selects the winning page title variant for the current request.
 *
 * Source-agnostic: works for any page Drupal serves, regardless of whether the
 * title comes from a node, a Views display, or a custom controller. The
 * matching is purely path-based via the resolved internal path.
 */
class TitleVariantSelector extends VariantSelectorBase {

  /**
   * The current path stack.
   *
   * @var \Drupal\Core\Path\CurrentPathStack
   */
  protected CurrentPathStack $currentPath;

  /**
   * Constructs a TitleVariantSelector.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ExperimentManagerInterface $experiment_manager,
    CacheManager $cache_manager,
    CurrentPathStack $current_path,
  ) {
    parent::__construct($entity_type_manager, $experiment_manager, $cache_manager);
    $this->currentPath = $current_path;
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
   */
  public function selectForCurrentPage(): ?array {
    return $this->selectForPath($this->getCurrentInternalPath());
  }

  /**
   * Select the winning variant for a specific internal path.
   */
  public function selectForPath(string $internal_path): ?array {
    return $this->selectForTarget(PageTitleExperiment::normalizePath($internal_path));
  }

  /**
   * Get the resolved internal path for the current request.
   */
  protected function getCurrentInternalPath(): string {
    return PageTitleExperiment::normalizePath($this->currentPath->getPath());
  }

}
