<?php

namespace Drupal\rl\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Database\Connection;
use Drupal\rl\Registry\ExperimentRegistryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for creating and editing RL experiments.
 */
class ExperimentForm extends FormBase {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The experiment registry.
   *
   * @var \Drupal\rl\Registry\ExperimentRegistryInterface
   */
  protected $experimentRegistry;

  /**
   * Constructs an ExperimentForm object.
   */
  public function __construct(Connection $database, ExperimentRegistryInterface $experiment_registry) {
    $this->database = $database;
    $this->experimentRegistry = $experiment_registry;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('rl.experiment_registry')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rl_experiment_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $experiment_uuid = NULL) {
    $experiment = NULL;

    if ($experiment_uuid) {
      $experiment = $this->database->select('rl_experiment_registry', 'er')
        ->fields('er')
        ->condition('uuid', $experiment_uuid)
        ->execute()
        ->fetchObject();

      if (!$experiment) {
        $this->messenger()->addError($this->t('Experiment not found.'));
        return $this->redirect('rl.reports.experiments');
      }
    }

    $form['uuid'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Experiment UUID'),
      '#required' => TRUE,
      '#default_value' => $experiment ? $experiment->uuid : '',
      '#disabled' => (bool) $experiment,
      '#description' => $experiment ? $this->t('UUID cannot be changed after creation.') : $this->t('Unique identifier for this experiment.'),
    ];

    $form['module'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Module'),
      '#required' => TRUE,
      '#default_value' => $experiment ? $experiment->module : '',
      '#description' => $this->t('The module that owns this experiment.'),
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $experiment ? $this->t('Update experiment') : $this->t('Create experiment'),
      '#button_type' => 'primary',
    ];

    $form['actions']['cancel'] = [
      '#type' => 'link',
      '#title' => $this->t('Cancel'),
      '#url' => $this->urlGenerator()->generateFromRoute('rl.reports.experiments'),
      '#attributes' => ['class' => ['button']],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $uuid = $form_state->getValue('uuid');
    $route_uuid = $this->getRouteMatch()->getParameter('experiment_uuid');

    if (!$route_uuid && $this->experimentRegistry->isRegistered($uuid)) {
      $form_state->setErrorByName('uuid', $this->t('An experiment with this UUID already exists.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $uuid = $form_state->getValue('uuid');
    $module = $form_state->getValue('module');
    $route_uuid = $this->getRouteMatch()->getParameter('experiment_uuid');

    if ($route_uuid) {
      $this->database->update('rl_experiment_registry')
        ->fields(['module' => $module])
        ->condition('uuid', $uuid)
        ->execute();

      $this->messenger()->addStatus($this->t('Experiment updated successfully.'));
    }
    else {
      $this->experimentRegistry->register($uuid, $module);
      $this->messenger()->addStatus($this->t('Experiment created successfully.'));
    }

    $form_state->setRedirect('rl.reports.experiments');
  }

}
