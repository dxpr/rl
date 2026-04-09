<?php

namespace Drupal\rl\Experiment;

use Drupal\Core\Entity\EntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rl\Service\ExperimentManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base delete form that purges RL analytics before removing the config entity.
 *
 * Works for any entity that implements VariantExperimentInterface; subclasses
 * exist only as the concrete form class referenced in the entity annotation.
 */
abstract class VariantExperimentDeleteFormBase extends EntityDeleteForm {

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

    // Purge analytics first. If purging fails, the config entity is left
    // intact as a recovery anchor and the deletion is aborted.
    if ($entity instanceof VariantExperimentInterface) {
      $this->experimentManager->purgeExperiment($entity->getRlExperimentId());
    }

    parent::submitForm($form, $form_state);
  }

}
