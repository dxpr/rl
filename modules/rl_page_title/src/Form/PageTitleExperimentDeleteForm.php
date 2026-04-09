<?php

namespace Drupal\rl_page_title\Form;

use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rl\Service\ExperimentManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for deleting a Page Title experiment.
 *
 * Extends the standard delete confirmation to also purge the RL analytics
 * tables (turns, rewards, totals, snapshots, registry) so that deletion is
 * complete and no orphaned data lingers in /admin/reports/rl.
 */
class PageTitleExperimentDeleteForm extends EntityDeleteForm {

  /**
   * The RL experiment manager.
   *
   * @var \Drupal\rl\Service\ExperimentManagerInterface
   */
  protected ExperimentManagerInterface $experimentManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->experimentManager = $container->get('rl.experiment_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will permanently delete the experiment and all its tracking data (impressions, rewards, snapshots). This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    /** @var \Drupal\rl_page_title\Entity\PageTitleExperiment $entity */
    $entity = $this->getEntity();
    $rl_experiment_id = $entity->getRlExperimentId();

    parent::submitForm($form, $form_state);

    // Purge RL analytics for this experiment.
    $this->experimentManager->purgeExperiment($rl_experiment_id);
  }

}
