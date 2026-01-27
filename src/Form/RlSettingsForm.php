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
  public function getFormId(): string {
    return 'rl_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['rl.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
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
      '#description' => $this->t('Maximum rows in rl_arm_snapshots. Cron deletes non-milestone rows when over limit. Preserved: early trials (40% of per-arm budget, permanent), recent trials (40%, rotating), and periodic milestone samples at adaptive intervals.'),
      '#default_value' => $config->get('event_log_max_rows') ?? 100000,
      '#min' => 1000,
      '#max' => 10000000,
      '#states' => [
        'visible' => [
          ':input[name="enable_event_log"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['charts'] = [
      '#type' => 'details',
      '#title' => $this->t('Chart Settings'),
      '#open' => TRUE,
    ];

    $form['charts']['chart_line_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Line chart threshold'),
      '#description' => $this->t('Maximum number of variants to show in the 2D line chart. Experiments with more variants will use the 3D landscape visualization instead.'),
      '#default_value' => $config->get('chart_line_threshold') ?? 10,
      '#min' => 2,
      '#max' => 50,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('rl.settings')
      ->set('debug_mode', $form_state->getValue('debug_mode'))
      ->set('enable_event_log', $form_state->getValue('enable_event_log'))
      ->set('event_log_max_rows', (int) $form_state->getValue('event_log_max_rows'))
      ->set('chart_line_threshold', (int) $form_state->getValue('chart_line_threshold'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
