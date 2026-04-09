<?php

namespace Drupal\rl_page_title\Decorator;

use Drupal\rl\Experiment\VariantExperimentDecoratorBase;
use Drupal\rl_page_title\Entity\PageTitleExperiment;

/**
 * Decorates RL reports with human-readable page title experiment data.
 */
class PageTitleDecorator extends VariantExperimentDecoratorBase {

  /**
   * {@inheritdoc}
   */
  protected function experimentIdPrefix(): string {
    return 'rl_page_title-';
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
  protected function buildExperimentDisplay(object $experiment): array {
    assert($experiment instanceof PageTitleExperiment);
    return [
      '#type' => 'inline_template',
      '#template' => '{{ label }} <small>({{ path }})</small>',
      '#context' => [
        'label' => $experiment->label() ?: $experiment->getPath(),
        'path' => $experiment->getPath(),
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function buildOriginalArmDisplay(object $experiment): array {
    return [
      '#type' => 'inline_template',
      '#template' => '<em>{{ "(original title)"|t }}</em>',
    ];
  }

}
