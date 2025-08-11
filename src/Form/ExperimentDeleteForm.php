<?php

namespace Drupal\rl\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for deleting RL experiments.
 */
class ExperimentDeleteForm extends ConfirmFormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The experiment UUID.
   *
   * @var string
   */
  protected $experimentUuid;

  /**
   * Constructs an ExperimentDeleteForm object.
   */
  public function __construct(Connection $database) {
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rl_experiment_delete_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $experiment_uuid = NULL) {
    $this->experimentUuid = $experiment_uuid;

    $experiment = $this->database->select('rl_experiment_registry', 'er')
      ->fields('er')
      ->condition('uuid', $experiment_uuid)
      ->execute()
      ->fetchObject();

    if (!$experiment) {
      $this->messenger()->addError($this->t('Experiment not found.'));
      return $this->redirect('rl.reports.experiments');
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to delete experiment %uuid?', [
      '%uuid' => $this->experimentUuid,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will permanently delete the experiment and all its data (turns, rewards, totals). This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return new Url('rl.reports.experiments');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $transaction = $this->database->startTransaction();

    try {
      $this->database->delete('rl_arm_data')
        ->condition('experiment_uuid', $this->experimentUuid)
        ->execute();

      $this->database->delete('rl_experiment_totals')
        ->condition('experiment_uuid', $this->experimentUuid)
        ->execute();

      $this->database->delete('rl_experiment_registry')
        ->condition('uuid', $this->experimentUuid)
        ->execute();

      $this->messenger()->addStatus($this->t('Experiment %uuid has been deleted.', [
        '%uuid' => $this->experimentUuid,
      ]));
    }
    catch (\Exception $e) {
      $transaction->rollBack();
      $this->messenger()->addError($this->t('An error occurred while deleting the experiment.'));
      $this->getLogger('rl')->error('Error deleting experiment @uuid: @message', [
        '@uuid' => $this->experimentUuid,
        '@message' => $e->getMessage(),
      ]);
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
