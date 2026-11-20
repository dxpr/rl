<?php

namespace Drupal\rl_menu_link\Hook;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\Element;
use Drupal\Component\Utility\Html;
use Drupal\rl_menu_link\Entity\MenuLinkExperiment;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for rl_menu_link.
 */
class RlMenuLinkHooks {
  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    if ($route_name === 'help.page.rl_menu_link') {
      return '<p>' . $this->t('A/B test menu link titles using Thompson Sampling. Works for menu_link_content entities and YAML-defined links. Manage experiments at <a href=":url">Reinforcement Learning menu link experiments</a>.', [
        ':url' => Url::fromRoute('view.rl_menu_link_experiment.page_1')->toString(),
      ]) . '</p>';
    }
  }

  /**
   * Implements hook_preprocess_menu().
   *
   * Walks the menu tree and replaces link titles with the winning variant.
   * Tracking is wired entirely through data attributes injected onto the
   * rendered anchors; the JS reads them directly from the DOM.
   */
  #[Hook('preprocess_menu')]
  public function preprocessMenu(array &$variables) {
    if (empty($variables['items'])) {
      return;
    }
    $touched_plugin_ids = [];
    _rl_menu_link_process_items($variables['items'], $touched_plugin_ids);
    // Always attach the rl_menu_link:all tag so any future experiment creation
    // can invalidate cached menu output even for menus that did not have an
    // experiment when they were first cached.
    $variables['#cache']['tags'][] = 'rl_menu_link:all';
    if (!empty($touched_plugin_ids)) {
      // The rl.php endpoint URL comes from drupalSettings.rl.endpointUrl,
      // published by rl_page_attachments() whenever the rl/api library is
      // loaded.
      $variables['#attached']['library'][] = 'rl_menu_link/tracking';
      // Add per-plugin-id tags so each experiment can invalidate the menus
      // that contain its specific link.
      foreach ($touched_plugin_ids as $plugin_id) {
        $variables['#cache']['tags'][] = 'rl_menu_link:' . $plugin_id;
      }
    }
  }

  /**
   * Implements hook_form_alter().
   *
   * Adds the title variants vertical tab to menu_link_content edit forms.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, $form_id) {
    $form_object = $form_state->getFormObject();
    if (!$form_object instanceof ContentEntityFormInterface) {
      return;
    }
    $operation = $form_object->getOperation();
    if (!in_array($operation, [
      'default',
      'edit',
    ], TRUE)) {
      return;
    }
    $entity = $form_object->getEntity();
    if (!$entity || $entity->isNew()) {
      return;
    }
    if ($entity->getEntityTypeId() !== 'menu_link_content') {
      return;
    }
    if (!method_exists($entity, 'getPluginId')) {
      return;
    }
    if (!\Drupal::currentUser()->hasPermission('administer rl menu link experiments')) {
      return;
    }
    $plugin_id = $entity->getPluginId();
    // Use the entity's current translation language for the experiment scope.
    $entity_langcode = $entity->language()->getId() ?: LanguageInterface::LANGCODE_NOT_SPECIFIED;
    // Indexed lookup against the UNIQUE lookup_hash column.
    /** @var \Drupal\rl_menu_link\Entity\MenuLinkExperiment|null $existing */
    $existing = NULL;
    $lookup_hash = MenuLinkExperiment::computeLookupHash($plugin_id, $entity_langcode);
    $matches = \Drupal::entityTypeManager()->getStorage('rl_menu_link_experiment')->loadByProperties([
      'lookup_hash' => $lookup_hash,
    ]);
    if ($matches) {
      $candidate = reset($matches);
      if ($candidate instanceof MenuLinkExperiment) {
        $existing = $candidate;
      }
    }
    $is_multilingual = \Drupal::languageManager()->isMultilingual();
    $language_name = $entity_langcode === LanguageInterface::LANGCODE_NOT_SPECIFIED ? $this->t('all languages') : \Drupal::languageManager()->getLanguage($entity_langcode)?->getName() ?? $entity_langcode;
    $baseline_title = (string) ($entity->label() ?? '');
    $form['rl_menu_link'] = [
      '#type' => 'details',
      '#title' => $this->t('A/B test menu link title'),
      '#group' => 'advanced',
      '#open' => FALSE,
      '#weight' => 50,
      '#tree' => TRUE,
    ];
    $form['rl_menu_link']['baseline'] = [
      '#type' => 'item',
      '#title' => $this->t('Current menu link title'),
      '#markup' => '<p class="rl-menu-link-baseline"><em>' . Html::escape($baseline_title) . '</em></p>',
    ];
    if ($existing) {
      $rl_experiment_id = $existing->getRlExperimentId();
      $experiment_manager = \Drupal::service('rl.experiment_manager');
      $total_turns = $experiment_manager->getTotalTurns($rl_experiment_id);
      $arms = $experiment_manager->getAllArmsData($rl_experiment_id);
      $total_rewards = 0;
      $leading_arm = NULL;
      $leading_rate = -1.0;
      // Require at least this many turns per arm before the arm is eligible
      // to be displayed as the current leader. Below this threshold the
      // observed rate is dominated by sampling noise.
      $min_per_arm_for_leader = 10;
      foreach ($arms as $arm) {
        $turns = (int) $arm->turns;
        $rewards = (int) $arm->rewards;
        $total_rewards += $rewards;
        if ($turns >= $min_per_arm_for_leader) {
          $rate = $rewards / $turns;
          if ($rate > $leading_rate) {
            $leading_rate = $rate;
            $leading_arm = (string) $arm->arm_id;
          }
        }
      }
      $leading_text = NULL;
      if ($leading_arm !== NULL) {
        if ($leading_arm === 'v0') {
          $leading_text = $baseline_title;
        }
        else {
          $index = (int) substr($leading_arm, 1) - 1;
          $variants = $existing->getVariants();
          if (isset($variants[$index])) {
            $leading_text = (string) $variants[$index];
          }
        }
      }
      $state_line = $existing->isPublished() ? $this->t('This test is running.') : $this->t('This test is paused. It will not show variants to visitors until you turn it back on.');
      if ($is_multilingual) {
        $state_line = $existing->isPublished() ? $this->t('This test is running in @lang.', [
          '@lang' => $language_name,
        ]) : $this->t('This test is paused in @lang. It will not show variants to visitors until you turn it back on.', [
          '@lang' => $language_name,
        ]);
      }
      if ($total_turns === 0) {
        $progress_line = $this->t('No visitors have seen this test yet. Come back after some traffic arrives.');
      }
      elseif ($total_turns < 10 || $leading_text === NULL) {
        $progress_line = $this->t('Still collecting data. @turns visitors have seen a variant so far.', [
          '@turns' => number_format($total_turns),
        ]);
      }
      elseif ($leading_arm === 'v0') {
        $progress_line = $this->t('Your original title is currently getting the most clicks. @turns impressions, @rewards clicks.', [
          '@turns' => number_format($total_turns),
          '@rewards' => number_format($total_rewards),
        ]);
      }
      else {
        $progress_line = $this->t('"@alt" is currently out-clicking your original. @turns impressions, @rewards clicks.', [
          '@alt' => $leading_text,
          '@turns' => number_format($total_turns),
          '@rewards' => number_format($total_rewards),
        ]);
      }
      $form['rl_menu_link']['state'] = [
        '#type' => 'item',
        '#markup' => '<p><strong>' . $state_line . '</strong></p><p>' . $progress_line . '</p>',
      ];
      if ($total_turns > 0) {
        $form['rl_menu_link']['report'] = [
          '#type' => 'link',
          '#title' => $this->t('See the full report'),
          '#url' => Url::fromRoute('rl.reports.experiment_detail', [
            'experiment_id' => $rl_experiment_id,
          ]),
          '#attributes' => [
            'target' => '_blank',
          ],
        ];
      }
    }
    else {
      $intro = $is_multilingual ? $this->t('Not sure if this menu link title is pulling its weight? Add a few alternatives below and we will rotate them through for visitors in @lang, then tell you which one gets the most clicks.', [
        '@lang' => $language_name,
      ]) : $this->t('Not sure if this menu link title is pulling its weight? Add a few alternatives below and we will rotate them through for visitors, then tell you which one gets the most clicks.');
      $form['rl_menu_link']['intro'] = [
        '#type' => 'item',
        '#markup' => '<p>' . $intro . '</p>',
      ];
    }
    $form['rl_menu_link']['variants'] = [
      '#type' => 'textarea',
      '#title' => $existing ? $this->t('Alternatives you are testing') : $this->t('Try these alternative titles'),
      '#description' => $this->t('Enter one alternative per line. Your current menu link title (shown above) always stays in the test.'),
      '#default_value' => $existing ? implode("\n", $existing->getVariants()) : '',
      '#rows' => 4,
    ];
    $form['rl_menu_link']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Run this test'),
      '#description' => $this->t('Turn off to pause. Your collected data is kept safe.'),
      '#default_value' => $existing ? (bool) $existing->isPublished() : TRUE,
    ];
    if ($existing) {
      $form['rl_menu_link']['remove_link'] = [
        '#type' => 'link',
        '#title' => $this->t('Stop testing and delete collected data'),
        '#url' => $existing->toUrl('delete-form'),
        '#attributes' => [
          'class' => [
            'button',
            'button--small',
            'button--danger',
          ],
        ],
        '#prefix' => '<div class="rl-menu-link-remove">',
        '#suffix' => '</div>',
      ];
    }
    $form['rl_menu_link']['_plugin_id'] = [
      '#type' => 'value',
      '#value' => $plugin_id,
    ];
    $form['rl_menu_link']['_langcode'] = [
      '#type' => 'value',
      '#value' => $entity_langcode,
    ];
    if ($existing) {
      $form['rl_menu_link']['_existing_id'] = [
        '#type' => 'value',
        '#value' => $existing->id(),
      ];
    }
    // Attach our submit handler to all submit-style actions.
    if (isset($form['actions']) && is_array($form['actions'])) {
      foreach (Element::children($form['actions']) as $action_key) {
        if (isset($form['actions'][$action_key]['#type']) && $form['actions'][$action_key]['#type'] === 'submit') {
          $form['actions'][$action_key]['#submit'][] = '_rl_menu_link_form_submit';
        }
      }
    }
  }

  /**
   * Implements hook_entity_predelete().
   *
   * Cleans up menu link experiments when the menu link content entity is
   * deleted, and purges associated RL analytics.
   */
  #[Hook('entity_predelete')]
  public function entityPredelete(EntityInterface $entity) {
    if ($entity->getEntityTypeId() !== 'menu_link_content') {
      return;
    }
    $plugin_id = method_exists($entity, 'getPluginId') ? $entity->getPluginId() : NULL;
    if (!$plugin_id) {
      return;
    }
    $storage = \Drupal::entityTypeManager()->getStorage('rl_menu_link_experiment');
    $matches = $storage->loadByProperties([
      'menu_link_plugin_id' => $plugin_id,
    ]);
    if (!$matches) {
      return;
    }
    $manager = \Drupal::service('rl.experiment_manager');
    $invalidate_tags = [
      'rl_menu_link:all' => TRUE,
    ];
    foreach ($matches as $experiment) {
      if (!$experiment instanceof MenuLinkExperiment) {
        continue;
      }
      $rl_id = $experiment->getRlExperimentId();
      $invalidate_tags['rl_menu_link:' . $experiment->getMenuLinkPluginId()] = TRUE;
      // Purge analytics first; if it fails, the config entity is still around
      // for a retry.
      $manager->purgeExperiment($rl_id);
      $experiment->delete();
    }
    // Close the cache-as-you-invalidate loop: cached menus that referenced
    // the deleted plugin ID still carry the per-plugin tag plus the global
    // `rl_menu_link:all` tag and would keep serving the cascade-deleted
    // experiment's variant label until natural cache turnover.
    Cache::invalidateTags(array_keys($invalidate_tags));
  }

  /**
   * Implements hook_preprocess_views_view_field().
   *
   * Rewrites the Label cell in the menu link experiment admin Views table
   * so it links to the experiment report instead of the entity edit form.
   * The report is the more useful destination for someone scanning the
   * list: it shows per-arm statistics. The Operations column still
   * provides Edit/Delete for entity-level actions.
   */
  #[Hook('preprocess_views_view_field')]
  public function preprocessViewsViewField(array &$variables): void {
    if ($variables['view']->id() !== 'rl_menu_link_experiment') {
      return;
    }
    if ($variables['field']->field !== 'label') {
      return;
    }
    $entity = $variables['row']->_entity ?? NULL;
    if (!$entity instanceof MenuLinkExperiment) {
      return;
    }
    $variables['output'] = [
      '#type' => 'link',
      '#title' => $entity->label(),
      '#url' => Url::fromRoute('rl.reports.experiment_detail', [
        'experiment_id' => $entity->getRlExperimentId(),
      ]),
    ];
  }

}
