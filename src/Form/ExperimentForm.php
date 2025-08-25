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
  public function buildForm(array $form, FormStateInterface $form_state, $experiment_id = NULL) {
    $experiment = NULL;

    if ($experiment_id) {
      $experiment = $this->database->select('rl_experiment_registry', 'er')
        ->fields('er')
        ->condition('experiment_id', $experiment_id)
        ->execute()
        ->fetchObject();

      if (!$experiment) {
        $this->messenger()->addError($this->t('Experiment not found.'));
        return $this->redirect('rl.reports.experiments');
      }
    }

    $form['experiment_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Experiment ID'),
      '#required' => TRUE,
      '#default_value' => $experiment ? $experiment->experiment_id : '',
      '#disabled' => (bool) $experiment,
      '#description' => $experiment ? $this->t('ID cannot be changed after creation.') : $this->t('Unique identifier for this experiment.'),
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
    $experiment_id = $form_state->getValue('experiment_id');
    $route_experiment_id = $this->getRouteMatch()->getParameter('experiment_id');

    if (!$route_experiment_id && $this->experimentRegistry->isRegistered($experiment_id)) {
      $form_state->setErrorByName('experiment_id', $this->t('An experiment with this ID already exists.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $experiment_id = $form_state->getValue('experiment_id');
    $module = $form_state->getValue('module');
    $route_experiment_id = $this->getRouteMatch()->getParameter('experiment_id');

    if ($route_experiment_id) {
      $this->database->update('rl_experiment_registry')
        ->fields(['module' => $module])
        ->condition('experiment_id', $experiment_id)
        ->execute();

      $this->messenger()->addStatus($this->t('Experiment updated successfully.'));
    }
    else {
      $this->experimentRegistry->register($experiment_id, $module);
      $this->messenger()->addStatus($this->t('Experiment created successfully.'));
    }

    $form_state->setRedirect('rl.reports.experiments');
  }

}
