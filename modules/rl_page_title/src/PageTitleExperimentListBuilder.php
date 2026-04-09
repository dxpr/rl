<?php

namespace Drupal\rl_page_title;

use Drupal\Core\Entity\EntityInterface;
use Drupal\rl\Experiment\VariantExperimentListBuilderBase;
use Drupal\rl_page_title\Entity\PageTitleExperiment;

/**
 * List builder for Page Title experiments.
 */
class PageTitleExperimentListBuilder extends VariantExperimentListBuilderBase {

  /**
   * {@inheritdoc}
   */
  protected function entityClass(): string {
    return PageTitleExperiment::class;
  }

  /**
   * {@inheritdoc}
   */
  protected function targetColumnLabel(): string {
    return $this->t('Path');
  }

  /**
   * {@inheritdoc}
   */
  protected function emptyMessage(): string {
    return $this->t('No page title experiments yet. Add one to start A/B testing.');
  }

  /**
   * {@inheritdoc}
   */
  protected function targetColumnValue(EntityInterface $entity): string {
    assert($entity instanceof PageTitleExperiment);
    return $entity->getPath();
  }

}
