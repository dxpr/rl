<?php

namespace Drupal\rl_menu_link\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\rl\Experiment\VariantParser;
use Drupal\rl\Registry\ExperimentRegistryInterface;
use Drupal\rl\Service\ExperimentManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding and editing Menu Link experiments.
 */
class MenuLinkExperimentForm extends EntityForm {

  /**
   * The menu link manager.
   *
   * @var \Drupal\Core\Menu\MenuLinkManagerInterface
   */
  protected MenuLinkManagerInterface $menuLinkManager;

  /**
   * The RL experiment registry.
   *
   * @var \Drupal\rl\Registry\ExperimentRegistryInterface
   */
  protected ExperimentRegistryInterface $experimentRegistry;

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
    $instance->menuLinkManager = $container->get('plugin.manager.menu.link');
    $instance->experimentRegistry = $container->get('rl.experiment_registry');
    $instance->experimentManager = $container->get('rl.experiment_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\rl_menu_link\Entity\MenuLinkExperiment $entity */
    $entity = $this->entity;
    $request = $this->getRequest();
    $default_plugin = $entity->getMenuLinkPluginId() ?: ($request->query->get('menu_link_plugin_id') ?? '');
    $default_label = $entity->label() ?: ($request->query->get('label') ?? '');

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#description' => $this->t('Human-readable name for this experiment.'),
      '#default_value' => $default_label,
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name' => [
        'exists' => [$this, 'experimentExists'],
        'source' => ['label'],
      ],
      '#disabled' => !$entity->isNew(),
    ];

    $form['menu_link_plugin_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Menu link plugin ID'),
      '#description' => $this->t('The plugin ID of the menu link to test. Examples: <code>menu_link_content:11111111-2222-3333-4444-555555555555</code> for a user-created menu link, or <code>system.admin_content</code> for a YAML-defined link.'),
      '#default_value' => $default_plugin,
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $variants = $entity->getVariants();
    $form['variants'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Variant labels'),
      '#description' => $this->t('Alternative labels, one per line. The original label is always tested as variant 1; the lines below are tested against it.'),
      '#default_value' => implode("\n", $variants),
      '#rows' => 6,
      '#required' => TRUE,
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $entity->isNew() ? TRUE : (bool) $entity->status(),
    ];

    return $form;
  }

  /**
   * Machine name exists callback.
   */
  public function experimentExists($id): bool {
    return (bool) $this->entityTypeManager
      ->getStorage('rl_menu_link_experiment')
      ->load($id);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $plugin_id = trim($form_state->getValue('menu_link_plugin_id') ?? '');
    if ($plugin_id === '') {
      $form_state->setErrorByName('menu_link_plugin_id', $this->t('Plugin ID is required.'));
      return;
    }
    if (!$this->menuLinkManager->hasDefinition($plugin_id)) {
      $form_state->setErrorByName('menu_link_plugin_id', $this->t('No menu link with plugin ID %id is registered.', ['%id' => $plugin_id]));
      return;
    }

    /** @var \Drupal\rl_menu_link\Entity\MenuLinkExperiment $entity */
    $entity = $this->entity;
    $duplicates = $this->entityTypeManager
      ->getStorage('rl_menu_link_experiment')
      ->loadByProperties(['menu_link_plugin_id' => $plugin_id]);
    foreach ($duplicates as $duplicate) {
      if ($duplicate->id() !== $entity->id()) {
        $form_state->setErrorByName('menu_link_plugin_id', $this->t('Another experiment (%label) already targets this menu link. Edit that experiment instead.', [
          '%label' => $duplicate->label(),
        ]));
        break;
      }
    }

    if (empty(VariantParser::parse($form_state->getValue('variants') ?? ''))) {
      $form_state->setErrorByName('variants', $this->t('Provide at least one variant label.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    /** @var \Drupal\rl_menu_link\Entity\MenuLinkExperiment $entity */
    $entity = $this->entity;
    $new_plugin_id = trim($form_state->getValue('menu_link_plugin_id'));

    // If the plugin ID is being retargeted, purge analytics for the old ID.
    if (!$entity->isNew()) {
      $original = $this->entityTypeManager
        ->getStorage('rl_menu_link_experiment')
        ->loadUnchanged($entity->id());
      if ($original && $original->getMenuLinkPluginId() !== $new_plugin_id) {
        $this->experimentManager->purgeExperiment($original->getRlExperimentId());
      }
    }

    $entity->setMenuLinkPluginId($new_plugin_id);
    $entity->setVariants(VariantParser::parse($form_state->getValue('variants')));
    $entity->set('enabled', (bool) $form_state->getValue('enabled'));
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\rl_menu_link\Entity\MenuLinkExperiment $entity */
    $entity = $this->entity;
    $status = $entity->save();

    $this->experimentRegistry->register(
      $entity->getRlExperimentId(),
      'rl_menu_link',
      $entity->label()
    );

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Created experiment %label.', ['%label' => $entity->label()]));
    }
    else {
      $this->messenger()->addStatus($this->t('Updated experiment %label.', ['%label' => $entity->label()]));
    }
    $form_state->setRedirectUrl($entity->toUrl('collection'));
    return $status;
  }

}
