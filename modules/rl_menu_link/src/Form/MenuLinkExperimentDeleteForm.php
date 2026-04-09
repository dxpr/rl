<?php

namespace Drupal\rl_menu_link\Form;

use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rl\Service\ExperimentManagerInterface;
use Drupal\rl_menu_link\Entity\MenuLinkExperiment;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for deleting a Menu Link experiment.
 *
 * Extends the standard delete form to also purge the RL analytics tables.
 */
class MenuLinkExperimentDeleteForm extends EntityDeleteForm {

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
    $entity = $this->getEntity();
    $rl_experiment_id = NULL;
    if ($entity instanceof MenuLinkExperiment) {
      $rl_experiment_id = $entity->getRlExperimentId();
    }

    parent::submitForm($form, $form_state);

    if ($rl_experiment_id !== NULL) {
      $this->experimentManager->purgeExperiment($rl_experiment_id);
    }
  }

}
