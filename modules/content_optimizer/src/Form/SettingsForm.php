<?php

namespace Drupal\content_optimizer\Form;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configuration form for Content Optimizer.
 */
class SettingsForm extends ConfigFormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->entityTypeManager = $container->get('entity_type.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'content_optimizer_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['content_optimizer.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('content_optimizer.settings');

    $form['reward'] = [
      '#type' => 'details',
      '#title' => $this->t('Reward Strategy'),
      '#open' => TRUE,
    ];

    $form['reward']['reward_strategy'] = [
      '#type' => 'select',
      '#title' => $this->t('Reward signal'),
      '#options' => [
        'time_on_page' => $this->t('Time on page'),
        'scroll_depth' => $this->t('Scroll depth (50%)'),
        'next_click' => $this->t('Any click on the page'),
      ],
      '#default_value' => $config->get('reward_strategy') ?? 'time_on_page',
      '#description' => $this->t('How to determine if a variant performed well.'),
    ];

    $form['reward']['reward_threshold'] = [
      '#type' => 'number',
      '#title' => $this->t('Time threshold (seconds)'),
      '#default_value' => $config->get('reward_threshold') ?? 10,
      '#min' => 1,
      '#max' => 300,
      '#description' => $this->t('For time-on-page strategy: seconds before counting as a reward.'),
      '#states' => [
        'visible' => [
          ':input[name="reward_strategy"]' => ['value' => 'time_on_page'],
        ],
      ],
    ];

    $form['entity_types_section'] = [
      '#type' => 'details',
      '#title' => $this->t('Entity Type Support'),
      '#open' => TRUE,
    ];

    // Build checkboxes for content entity types.
    $entity_type_options = [];
    foreach ($this->entityTypeManager->getDefinitions() as $id => $definition) {
      if ($definition->getGroup() === 'content' && $definition->hasLinkTemplate('canonical')) {
        $entity_type_options[$id] = $definition->getLabel();
      }
    }

    $form['entity_types_section']['entity_types'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Show variant editor on forms for'),
      '#options' => $entity_type_options,
      '#default_value' => $config->get('entity_types') ?? ['node'],
      '#description' => $this->t('Entity types that show the "Title variants" vertical tab on edit forms.'),
    ];

    $form['performance'] = [
      '#type' => 'details',
      '#title' => $this->t('Performance'),
      '#open' => TRUE,
    ];

    $form['performance']['cache_ttl'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache TTL (seconds)'),
      '#default_value' => $config->get('cache_ttl') ?? 60,
      '#min' => 0,
      '#max' => 86400,
      '#description' => $this->t('Page cache lifetime for pages with active experiments. Lower values mean faster learning but more server load.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $entity_types = array_values(array_filter($form_state->getValue('entity_types')));

    $this->config('content_optimizer.settings')
      ->set('reward_strategy', $form_state->getValue('reward_strategy'))
      ->set('reward_threshold', (int) $form_state->getValue('reward_threshold'))
      ->set('entity_types', $entity_types)
      ->set('cache_ttl', (int) $form_state->getValue('cache_ttl'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
