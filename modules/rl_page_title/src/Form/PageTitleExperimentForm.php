<?php

namespace Drupal\rl_page_title\Form;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\rl\Experiment\VariantParser;
use Drupal\rl\Registry\ExperimentRegistryInterface;
use Drupal\rl\Service\ExperimentManagerInterface;
use Drupal\rl_page_title\Entity\PageTitleExperiment;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for adding and editing Page Title experiments.
 */
class PageTitleExperimentForm extends EntityForm {

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
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->pathValidator = $container->get('path.validator');
    $instance->aliasManager = $container->get('path_alias.manager');
    $instance->experimentRegistry = $container->get('rl.experiment_registry');
    $instance->experimentManager = $container->get('rl.experiment_manager');
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
    $default_path = $entity->getPath() ?: ($request->query->get('path') ?? '');
    $default_label = $entity->label() ?: ($request->query->get('label') ?? '');

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#description' => $this->t('Human-readable name for this experiment, e.g. "Blog index page title".'),
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

    $form['path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path'),
      '#description' => $this->t('Internal path of the page to test, with leading slash. Examples: <code>/node/42</code>, <code>/blog</code>, <code>/user/login</code>, <code>/node/add</code>. Aliases are accepted and resolved to the internal path on save.'),
      '#default_value' => $default_path,
      '#required' => TRUE,
      '#maxlength' => 2048,
    ];

    $variants = $entity->getVariants();
    $form['variants'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Variant titles'),
      '#description' => $this->t('Alternative titles, one per line. The original title is always tested as variant 1; the lines below are tested against it.'),
      '#default_value' => implode("\n", $variants),
      '#rows' => 6,
      '#required' => TRUE,
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#description' => $this->t('When unchecked, the experiment is paused: the original title always shows and no impressions or rewards are recorded.'),
      '#default_value' => $entity->isNew() ? TRUE : (bool) $entity->status(),
    ];

    return $form;
  }

  /**
   * Machine name existence callback.
   */
  public function experimentExists($id): bool {
    return (bool) $this->entityTypeManager
      ->getStorage('rl_page_title_experiment')
      ->load($id);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $raw_path = trim($form_state->getValue('path') ?? '');
    if ($raw_path === '') {
      $form_state->setErrorByName('path', $this->t('Path is required.'));
      return;
    }
    if ($raw_path[0] !== '/') {
      $form_state->setErrorByName('path', $this->t('Path must start with a slash.'));
    }
    elseif (!$this->pathValidator->isValid($raw_path)) {
      $form_state->setErrorByName('path', $this->t('The path %path does not match a valid Drupal route.', ['%path' => $raw_path]));
    }

    // Resolve to canonical internal path and check for duplicates.
    $resolved = $this->aliasManager->getPathByAlias($raw_path);
    $internal_path = PageTitleExperiment::normalizePath($resolved);
    $form_state->setValue('_resolved_path', $internal_path);

    $entity = $this->entity;
    assert($entity instanceof PageTitleExperiment);
    $duplicates = $this->entityTypeManager
      ->getStorage('rl_page_title_experiment')
      ->loadByProperties(['path' => $internal_path]);
    foreach ($duplicates as $duplicate) {
      if ($duplicate->id() !== $entity->id()) {
        $form_state->setErrorByName('path', $this->t('Another experiment (%label) already targets %path. Edit that experiment instead.', [
          '%label' => $duplicate->label(),
          '%path' => $internal_path,
        ]));
        break;
      }
    }

    if (empty(VariantParser::parse($form_state->getValue('variants') ?? ''))) {
      $form_state->setErrorByName('variants', $this->t('Provide at least one variant title.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    parent::submitForm($form, $form_state);

    $entity = $this->entity;
    assert($entity instanceof PageTitleExperiment);

    // If the path is being retargeted, purge analytics for the old RL ID.
    if (!$entity->isNew()) {
      $original = $this->entityTypeManager
        ->getStorage('rl_page_title_experiment')
        ->loadUnchanged($entity->id());
      if ($original instanceof PageTitleExperiment && $original->getPath() !== $form_state->getValue('_resolved_path')) {
        $this->experimentManager->purgeExperiment($original->getRlExperimentId());
      }
    }

    $entity->setPath($form_state->getValue('_resolved_path'));
    $entity->setVariants(VariantParser::parse($form_state->getValue('variants')));
    $entity->set('enabled', (bool) $form_state->getValue('enabled'));
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    $entity = $this->entity;
    assert($entity instanceof PageTitleExperiment);
    $status = $entity->save();

    // Register with the RL experiment registry so the tracking endpoint
    // accepts events for this experiment. Idempotent on the registry side.
    $this->experimentRegistry->register(
      $entity->getRlExperimentId(),
      'rl_page_title',
      $entity->label()
    );

    // Invalidate page cache for the target path so the new variants take
    // effect immediately rather than waiting for the cached page to expire.
    Cache::invalidateTags(['rl_page_title:' . $entity->getPath()]);

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
