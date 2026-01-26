<?php

namespace Drupal\rl\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure RL module settings.
 */
class RlSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'rl_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['rl.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('rl.settings');

    $form['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug mode'),
      '#description' => $this->t('When enabled, Thompson Sampling scores will be logged for debugging purposes. <strong>Warning:</strong> This can generate significant log entries in high-traffic sites and should only be enabled temporarily for troubleshooting.'),
      '#default_value' => $config->get('debug_mode') ?? FALSE,
    ];

    $form['event_log'] = [
      '#type' => 'details',
      '#title' => $this->t('Event Log (Historical Visualization)'),
      '#open' => TRUE,
    ];

    $form['event_log']['enable_event_log'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable event log'),
      '#description' => $this->t('When enabled, snapshots of experiment state are recorded over time to allow visualization of how posterior beliefs evolved. This adds one database write per turn/reward.'),
      '#default_value' => $config->get('enable_event_log') ?? FALSE,
    ];

    $form['event_log']['event_log_max_rows'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum event log rows'),
      '#description' => $this->t('Maximum total rows in the event log table. Older non-milestone entries are deleted during cron to stay within this limit.'),
      '#default_value' => $config->get('event_log_max_rows') ?? 100000,
      '#min' => 1000,
      '#max' => 10000000,
      '#states' => [
        'visible' => [
          ':input[name="enable_event_log"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('rl.settings')
      ->set('debug_mode', $form_state->getValue('debug_mode'))
      ->set('enable_event_log', $form_state->getValue('enable_event_log'))
      ->set('event_log_max_rows', (int) $form_state->getValue('event_log_max_rows'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
