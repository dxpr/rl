<?php

namespace Drupal\content_optimizer\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 * Confirmation form for deleting a Content Optimizer experiment.
 */
class ExperimentDeleteForm extends ContentEntityDeleteForm {

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    /** @var \Drupal\content_optimizer\Entity\Experiment $entity */
    $entity = $this->getEntity();
    return $this->t('Are you sure you want to delete the experiment for %label?', [
      '%label' => $entity->getExperimentLabel(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will delete the experiment configuration. RL tracking data can be cleaned up separately in the RL reports.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('content_optimizer.experiments');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);
    $this->messenger()->addStatus($this->t('Experiment deleted.'));
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
