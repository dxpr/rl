<?php

namespace Drupal\rl_page_title\Form;

use Drupal\Core\Entity\EntityInterface;
use Drupal\rl\Experiment\VariantExperimentDeleteFormBase;
use Drupal\rl_page_title\Entity\PageTitleExperiment;

/**
 * Confirmation form for deleting a Page Title experiment.
 *
 * The base class handles purge-then-delete plus cache-tag invalidation; this
 * subclass supplies the tag list for the page being uncached.
 */
class PageTitleExperimentDeleteForm extends VariantExperimentDeleteFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getCacheTagsToInvalidate(EntityInterface $entity): array {
    if (!$entity instanceof PageTitleExperiment) {
      return [];
    }
    return ['rl_page_title:' . $entity->getPath()];
  }

}
