<?php

namespace Drupal\rl_menu_link\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\Core\Url;
use Drupal\rl\Experiment\VariantParser;
use Drupal\rl\Registry\ExperimentRegistryInterface;
use Drupal\rl\Service\ExperimentManagerInterface;
use Drupal\rl_menu_link\Entity\MenuLinkExperiment;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding and editing Menu Link experiments.
 */
class MenuLinkExperimentForm extends ContentEntityForm {

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
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected LanguageManagerInterface $languageManager;

  /**
   * RL experiment ID of the previous target, captured for post-save purge.
   */
  protected ?string $pendingPurgeRlExperimentId = NULL;

  /**
   * Old plugin ID captured in submitForm() for post-save cache invalidation.
   *
   * Populated only when the target plugin ID changes (a retarget). `save()`
   * uses it to invalidate `rl_menu_link:{old_plugin_id}` in addition to
   * the new plugin ID's tag, so cached menus that used to render the
   * previous target stop serving a variant that no longer applies.
   *
   * @var string|null
   */
  protected ?string $pendingInvalidateOldPluginId = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->menuLinkManager = $container->get('plugin.manager.menu.link');
    $instance->experimentRegistry = $container->get('rl.experiment_registry');
    $instance->experimentManager = $container->get('rl.experiment_manager');
    $instance->languageManager = $container->get('language_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    $entity = $this->entity;
    assert($entity instanceof MenuLinkExperiment);

    $request = $this->getRequest();
    if ($entity->isNew()) {
      if ($entity->getMenuLinkPluginId() === '' && $request->query->has('menu_link_plugin_id')) {
        $entity->setMenuLinkPluginId((string) $request->query->get('menu_link_plugin_id'));
        $form['menu_link_plugin_id']['widget'][0]['value']['#default_value'] = $entity->getMenuLinkPluginId();
      }
    }

    // Hide the JSON storage field; the textarea is the user-facing surface.
    $form['variants_data']['#access'] = FALSE;
    $form['variants'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Alternative menu link titles'),
      '#description' => $this->t('Enter one alternative per line. The original menu link title always stays in the test as the control, and each line below is rotated in for visitors and measured against it.'),
      '#default_value' => implode("\n", $entity->getVariants()),
      '#rows' => 6,
      '#required' => TRUE,
      '#weight' => 0,
    ];

    // Language selector with "all languages" default.
    $languages = $this->languageManager->getLanguages(LanguageInterface::STATE_CONFIGURABLE);
    $language_options = [LanguageInterface::LANGCODE_NOT_SPECIFIED => $this->t('- All languages -')];
    foreach ($languages as $language) {
      $language_options[$language->getId()] = $language->getName();
    }
    $form['langcode']['widget'][0]['value'] = [
      '#type' => 'select',
      '#title' => $this->t('Language'),
      '#description' => $this->t('Restrict this experiment to visitors in one language, or apply it to all languages. Each language tracks its own results independently.'),
      '#options' => $language_options,
      '#default_value' => $entity->language()->getId(),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $plugin_id = trim((string) $form_state->getValue(['menu_link_plugin_id', 0, 'value']));
    if ($plugin_id === '') {
      $form_state->setErrorByName('menu_link_plugin_id', $this->t('Menu link is required.'));
      return;
    }
    if (!$this->menuLinkManager->hasDefinition($plugin_id)) {
      $form_state->setErrorByName('menu_link_plugin_id', $this->t('No menu link with identifier %id was found. Try editing the link from Structure &rsaquo; Menus and using its "Label variants" tab instead.', ['%id' => $plugin_id]));
      return;
    }

    $langcode = (string) ($form_state->getValue(['langcode', 0, 'value']) ?? LanguageInterface::LANGCODE_NOT_SPECIFIED);

    $entity = $this->entity;
    assert($entity instanceof MenuLinkExperiment);
    // Indexed duplicate detection against the UNIQUE lookup_hash column.
    $lookup_hash = MenuLinkExperiment::computeLookupHash($plugin_id, $langcode);
    $duplicates = $this->entityTypeManager
      ->getStorage('rl_menu_link_experiment')
      ->loadByProperties(['lookup_hash' => $lookup_hash]);
    foreach ($duplicates as $duplicate) {
      if ((string) $duplicate->id() !== (string) $entity->id()) {
        $form_state->setErrorByName('menu_link_plugin_id', $this->t('An experiment named "@label" already tests this menu link in the same language. <a href=":url">Edit it instead</a>.', [
          '@label' => $duplicate->label(),
          ':url' => $duplicate->toUrl('edit-form')->toString(),
        ]));
        break;
      }
    }

    if (empty(VariantParser::parse((string) $form_state->getValue('variants', '')))) {
      $form_state->setErrorByName('variants', $this->t('Enter at least one alternative menu link title, one per line.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $entity = $this->entity;
    assert($entity instanceof MenuLinkExperiment);
    $new_plugin_id = trim((string) $form_state->getValue(['menu_link_plugin_id', 0, 'value']));

    $this->pendingPurgeRlExperimentId = NULL;
    $this->pendingInvalidateOldPluginId = NULL;
    if (!$entity->isNew()) {
      $original = $this->entityTypeManager
        ->getStorage('rl_menu_link_experiment')
        ->loadUnchanged($entity->id());
      if ($original instanceof MenuLinkExperiment) {
        $original_id = $original->getRlExperimentId();
        $new_id = MenuLinkExperiment::buildRlExperimentId(
          $new_plugin_id,
          (string) ($form_state->getValue(['langcode', 0, 'value']) ?? LanguageInterface::LANGCODE_NOT_SPECIFIED)
        );
        if ($original_id !== $new_id) {
          $this->pendingPurgeRlExperimentId = $original_id;
          $this->pendingInvalidateOldPluginId = $original->getMenuLinkPluginId();
        }
      }
    }

    $entity->setMenuLinkPluginId($new_plugin_id);
    $entity->setVariants(VariantParser::parse((string) $form_state->getValue('variants', '')));
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $entity = $this->entity;
    assert($entity instanceof MenuLinkExperiment);
    $status = $entity->save();

    $this->experimentRegistry->register(
      $entity->getRlExperimentId(),
      'rl_menu_link',
      $entity->label()
    );

    if ($this->pendingPurgeRlExperimentId !== NULL) {
      try {
        $this->experimentManager->purgeExperiment($this->pendingPurgeRlExperimentId);
      }
      catch (\Exception $e) {
        $this->messenger()->addWarning($this->t('Experiment retargeted, but old analytics could not be purged: @message. The previous experiment data is now orphaned and can be cleared manually from <a href=":url">Reinforcement Learning reports</a>.', [
          '@message' => $e->getMessage(),
          ':url' => '/admin/reports/rl',
        ]));
      }
      $this->pendingPurgeRlExperimentId = NULL;
    }

    // Invalidate menu caches so the new variants take effect immediately.
    // On a retarget we also invalidate the OLD plugin ID's tag so cached
    // menus that were rendering the previous target stop serving its
    // variant label.
    $invalidate_tags = [
      'rl_menu_link:all',
      'rl_menu_link:' . $entity->getMenuLinkPluginId(),
    ];
    if ($this->pendingInvalidateOldPluginId !== NULL
        && $this->pendingInvalidateOldPluginId !== $entity->getMenuLinkPluginId()) {
      $invalidate_tags[] = 'rl_menu_link:' . $this->pendingInvalidateOldPluginId;
    }
    Cache::invalidateTags($invalidate_tags);
    $this->pendingInvalidateOldPluginId = NULL;

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Created experiment %label.', ['%label' => $entity->label()]));
    }
    else {
      $this->messenger()->addStatus($this->t('Updated experiment %label.', ['%label' => $entity->label()]));
    }
    $form_state->setRedirectUrl(Url::fromRoute('view.rl_menu_link_experiment.page_1'));
    return $status;
  }

}
