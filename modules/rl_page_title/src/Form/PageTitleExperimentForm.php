<?php

namespace Drupal\rl_page_title\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\Url;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\rl\Experiment\VariantParser;
use Drupal\rl\Registry\ExperimentRegistryInterface;
use Drupal\rl\Service\ExperimentManagerInterface;
use Drupal\rl_page_title\Entity\PageTitleExperiment;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding and editing Page Title experiments.
 */
class PageTitleExperimentForm extends ContentEntityForm {

  /**
   * The path validator.
   *
   * @var \Drupal\Core\Path\PathValidatorInterface
   */
  protected PathValidatorInterface $pathValidator;

  /**
   * The path alias manager.
   *
   * @var \Drupal\path_alias\AliasManagerInterface
   */
  protected AliasManagerInterface $aliasManager;

  /**
   * The RL experiment registry.
   *
   * @var \Drupal\rl\Registry\ExperimentRegistryInterface
   */
  protected ExperimentRegistryInterface $experimentRegistry;

  /**
   * The RL experiment manager (for purging on retarget).
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
   *
   * @var string|null
   */
  protected ?string $pendingPurgeRlExperimentId = NULL;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->pathValidator = $container->get('path.validator');
    $instance->aliasManager = $container->get('path_alias.manager');
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
    assert($entity instanceof PageTitleExperiment);

    // Pre-populate from query parameters (for deep links from contextual UIs).
    $request = $this->getRequest();
    if ($entity->isNew()) {
      if ($entity->getPath() === '' && $request->query->has('path')) {
        $entity->setPath((string) $request->query->get('path'));
        $form['path']['widget'][0]['value']['#default_value'] = $entity->getPath();
      }
      if ($entity->label() === NULL && $request->query->has('label')) {
        $form['label']['widget'][0]['value']['#default_value'] = (string) $request->query->get('label');
      }
    }

    // Convert variants_data (JSON internal) to a textarea for editing. The
    // base field is hidden because it stores JSON; the textarea is the
    // user-facing surface.
    $form['variants_data']['#access'] = FALSE;
    $form['variants'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Alternative titles'),
      '#description' => $this->t('Enter one alternative per line. The original page title always stays in the test as the control, and each line below is rotated in for visitors and measured against it.'),
      '#default_value' => implode("\n", $entity->getVariants()),
      '#rows' => 6,
      '#required' => TRUE,
      '#weight' => 0,
    ];

    // Language selector. Default to LANGCODE_NOT_SPECIFIED ("all languages")
    // to mirror Redirect's behavior. Show all configured languages plus the
    // "all languages" option.
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

    $raw_path = trim((string) $form_state->getValue(['path', 0, 'value']));
    if ($raw_path === '') {
      $form_state->setErrorByName('path', $this->t('Please enter the page URL or path you want to test.'));
      return;
    }
    // Quietly add a leading slash if the user left it off. Forcing users
    // to type "/" is unnecessary friction and they get it wrong constantly.
    if ($raw_path[0] !== '/') {
      $raw_path = '/' . $raw_path;
      $form_state->setValue(['path', 0, 'value'], $raw_path);
    }
    if (!$this->pathValidator->isValid($raw_path)) {
      $form_state->setErrorByName('path', $this->t('No page was found at %path. Check the URL and try again.', ['%path' => $raw_path]));
    }

    // Resolve to canonical internal path and check for duplicates.
    $resolved = $this->aliasManager->getPathByAlias($raw_path);
    $internal_path = PageTitleExperiment::normalizePath($resolved);
    $form_state->setValue('_resolved_path', $internal_path);

    // Determine target language from the form.
    $langcode = (string) ($form_state->getValue(['langcode', 0, 'value']) ?? LanguageInterface::LANGCODE_NOT_SPECIFIED);

    $entity = $this->entity;
    assert($entity instanceof PageTitleExperiment);
    $duplicates = $this->entityTypeManager
      ->getStorage('rl_page_title_experiment')
      ->loadByProperties([
        'path' => $internal_path,
        'langcode' => $langcode,
      ]);
    foreach ($duplicates as $duplicate) {
      if ((string) $duplicate->id() !== (string) $entity->id()) {
        $form_state->setErrorByName('path', $this->t('An experiment named "@label" already tests this page in the same language. <a href=":url">Edit it instead</a>.', [
          '@label' => $duplicate->label(),
          ':url' => $duplicate->toUrl('edit-form')->toString(),
        ]));
        break;
      }
    }

    if (empty(VariantParser::parse((string) $form_state->getValue('variants', '')))) {
      $form_state->setErrorByName('variants', $this->t('Enter at least one alternative title, one per line.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $entity = $this->entity;
    assert($entity instanceof PageTitleExperiment);

    // Detect retarget: capture the old RL experiment ID for save() to purge
    // AFTER the entity is successfully written.
    $this->pendingPurgeRlExperimentId = NULL;
    if (!$entity->isNew()) {
      $original = $this->entityTypeManager
        ->getStorage('rl_page_title_experiment')
        ->loadUnchanged($entity->id());
      if ($original instanceof PageTitleExperiment) {
        $original_id = $original->getRlExperimentId();
        $new_id = PageTitleExperiment::buildRlExperimentId(
          (string) $form_state->getValue('_resolved_path'),
          (string) ($form_state->getValue(['langcode', 0, 'value']) ?? LanguageInterface::LANGCODE_NOT_SPECIFIED)
        );
        if ($original_id !== $new_id) {
          $this->pendingPurgeRlExperimentId = $original_id;
        }
      }
    }

    $entity->setPath((string) $form_state->getValue('_resolved_path'));
    $entity->setVariants(VariantParser::parse((string) $form_state->getValue('variants', '')));
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $entity = $this->entity;
    assert($entity instanceof PageTitleExperiment);
    $status = $entity->save();

    // Register with the RL experiment registry. Idempotent.
    $this->experimentRegistry->register(
      $entity->getRlExperimentId(),
      'rl_page_title',
      $entity->label()
    );

    // Now that the new entity is safely written, purge analytics for the
    // old RL ID if this was a retarget. Doing this AFTER save() means a
    // failed save leaves the original analytics intact for retry.
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

    // Invalidate page cache for the target path so the new variants take
    // effect immediately.
    Cache::invalidateTags(['rl_page_title:' . $entity->getPath()]);

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Created experiment %label.', ['%label' => $entity->label()]));
    }
    else {
      $this->messenger()->addStatus($this->t('Updated experiment %label.', ['%label' => $entity->label()]));
    }
    $form_state->setRedirectUrl(Url::fromRoute('view.rl_page_title_experiment.page_1'));
    return $status;
  }

}
