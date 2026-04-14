<?php

namespace Drupal\rl_page_title\Decorator;

use Drupal\Core\Language\LanguageInterface;
use Drupal\rl\Experiment\VariantExperimentDecoratorBase;
use Drupal\rl\Experiment\VariantExperimentInterface;
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
  protected function buildExperimentDisplay(VariantExperimentInterface $experiment): array {
    assert($experiment instanceof PageTitleExperiment);
    $langcode = $experiment->language()->getId();
    $lang_label = $langcode === LanguageInterface::LANGCODE_NOT_SPECIFIED
      ? (string) t('all languages')
      : $langcode;
    return [
      '#type' => 'inline_template',
      '#template' => '{{ label }} <small>({{ path }}, {{ lang }})</small>',
      '#context' => [
        'label' => $experiment->label() ?: $experiment->getPath(),
        'path' => $experiment->getPath(),
        'lang' => $lang_label,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  protected function buildOriginalArmDisplay(VariantExperimentInterface $experiment): array {
    return [
      '#type' => 'inline_template',
      '#template' => '<em>{{ "(original title)"|t }}</em>',
    ];
  }

}
