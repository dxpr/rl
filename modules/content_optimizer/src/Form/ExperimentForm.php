<?php

namespace Drupal\content_optimizer\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\content_optimizer\Service\ExperimentRegistrationService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Form for creating and editing Content Optimizer experiments.
 */
class ExperimentForm extends ContentEntityForm {

  /**
   * The experiment registration service.
   *
   * @var \Drupal\content_optimizer\Service\ExperimentRegistrationService
   */
  protected ExperimentRegistrationService $registrationService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->registrationService = $container->get('content_optimizer.experiment_registration');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state) {
    $form = parent::form($form, $form_state);

    /** @var \Drupal\content_optimizer\Entity\Experiment $experiment */
    $experiment = $this->entity;
    $is_new = $experiment->isNew();

    // Pre-populate from query parameters (linked from node form).
    $request = $this->getRequest();

    $form['target_entity_type'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Entity type'),
      '#required' => TRUE,
      '#default_value' => $experiment->get('target_entity_type')->value ?: ($request->query->get('entity_type') ?? 'node'),
      '#description' => $this->t('Machine name of the entity type (e.g., node, menu_link_content).'),
      '#disabled' => !$is_new,
    ];

    $form['target_entity_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Entity ID'),
      '#default_value' => $experiment->get('target_entity_id')->value ?: $request->query->get('entity_id'),
      '#description' => $this->t('The numeric ID of the entity to test. Leave empty for path-based experiments.'),
      '#min' => 1,
      '#disabled' => !$is_new,
    ];

    $form['target_field'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Field'),
      '#required' => TRUE,
      '#default_value' => $experiment->get('target_field')->value ?: ($request->query->get('field') ?? 'title'),
      '#description' => $this->t('The field to test (e.g., title).'),
      '#disabled' => !$is_new,
    ];

    $form['target_path'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path'),
      '#default_value' => $experiment->get('target_path')->value,
      '#description' => $this->t('For non-entity experiments, the page path (e.g., /blog).'),
      '#states' => [
        'visible' => [
          ':input[name="target_entity_id"]' => ['value' => ''],
        ],
      ],
    ];

    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $is_new ? TRUE : $experiment->isPublished(),
    ];

    // Variants section.
    $form['variants_section'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Variants'),
      '#description' => $this->t('The original field value is always tested as "v0". Add alternative texts below.'),
      '#prefix' => '<div id="content-optimizer-variants-wrapper">',
      '#suffix' => '</div>',
    ];

    $existing_variants = $experiment->getVariants();
    $num_variants = $form_state->get('num_variants');
    if ($num_variants === NULL) {
      $num_variants = max(1, count($existing_variants));
      $form_state->set('num_variants', $num_variants);
    }

    for ($i = 0; $i < $num_variants; $i++) {
      $form['variants_section']['variant_' . $i] = [
        '#type' => 'textfield',
        '#title' => $this->t('Variant @num', ['@num' => $i + 1]),
        '#default_value' => $existing_variants[$i] ?? '',
        '#maxlength' => 512,
      ];
    }

    $form['variants_section']['add_variant'] = [
      '#type' => 'submit',
      '#value' => $this->t('Add variant'),
      '#submit' => ['::addVariantSubmit'],
      '#ajax' => [
        'callback' => '::variantsAjaxCallback',
        'wrapper' => 'content-optimizer-variants-wrapper',
      ],
      '#limit_validation_errors' => [],
      '#button_type' => 'small',
    ];

    if ($num_variants > 1) {
      $form['variants_section']['remove_variant'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove last variant'),
        '#submit' => ['::removeVariantSubmit'],
        '#ajax' => [
          'callback' => '::variantsAjaxCallback',
          'wrapper' => 'content-optimizer-variants-wrapper',
        ],
        '#limit_validation_errors' => [],
        '#button_type' => 'small',
      ];
    }

    // Remove the auto-generated base field widgets we handle manually.
    unset($form['variants_data']);

    return $form;
  }

  /**
   * AJAX callback for variants section.
   */
  public function variantsAjaxCallback(array &$form, FormStateInterface $form_state) {
    return $form['variants_section'];
  }

  /**
   * Submit handler for adding a variant.
   */
  public function addVariantSubmit(array &$form, FormStateInterface $form_state) {
    $num = $form_state->get('num_variants') ?? 1;
    $form_state->set('num_variants', $num + 1);
    $form_state->setRebuild();
  }

  /**
   * Submit handler for removing a variant.
   */
  public function removeVariantSubmit(array &$form, FormStateInterface $form_state) {
    $num = $form_state->get('num_variants') ?? 1;
    if ($num > 1) {
      $form_state->set('num_variants', $num - 1);
    }
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    parent::validateForm($form, $form_state);

    $entity_id = $form_state->getValue('target_entity_id');
    $path = $form_state->getValue('target_path');

    if (empty($entity_id) && empty($path)) {
      $form_state->setErrorByName('target_entity_id', $this->t('Provide either an entity ID or a path.'));
    }

    // Check at least one variant has text.
    $has_variant = FALSE;
    $num_variants = $form_state->get('num_variants') ?? 1;
    for ($i = 0; $i < $num_variants; $i++) {
      if (!empty(trim($form_state->getValue('variant_' . $i) ?? ''))) {
        $has_variant = TRUE;
        break;
      }
    }
    if (!$has_variant) {
      $form_state->setErrorByName('variant_0', $this->t('Add at least one variant text.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state) {
    /** @var \Drupal\content_optimizer\Entity\Experiment $experiment */
    $experiment = $this->entity;

    // Collect variant texts.
    $variants = [];
    $num_variants = $form_state->get('num_variants') ?? 1;
    for ($i = 0; $i < $num_variants; $i++) {
      $text = trim($form_state->getValue('variant_' . $i) ?? '');
      if (!empty($text)) {
        $variants[] = $text;
      }
    }
    $experiment->setVariants($variants);

    // Set published status.
    if ($form_state->getValue('enabled')) {
      $experiment->setPublished();
    }
    else {
      $experiment->setUnpublished();
    }

    $status = $experiment->save();

    // Register with RL.
    $this->registrationService->register(
      $experiment->getRlExperimentId(),
      $experiment->getExperimentLabel()
    );

    if ($status === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('Experiment created. Variants will be tested on the next page view.'));
    }
    else {
      $this->messenger()->addStatus($this->t('Experiment updated.'));
    }

    $form_state->setRedirectUrl(Url::fromRoute('content_optimizer.experiments'));
  }

}
