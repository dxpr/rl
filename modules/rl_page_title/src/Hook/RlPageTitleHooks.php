<?php

namespace Drupal\rl_page_title\Hook;

use Drupal\Core\Cache\MemoryCache\MemoryCache;
use Drupal\rl\Service\ExperimentManager;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Language\LanguageManagerInterface;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Render\Element;
use Drupal\Component\Utility\Html;
use Drupal\Core\Language\LanguageInterface;
use Drupal\rl_page_title\Entity\PageTitleExperiment;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\ContentEntityFormInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for rl_page_title.
 */
class RlPageTitleHooks {
  use StringTranslationTrait;

  /**
   * Constructs the hook service.
   */
  public function __construct(
    protected MemoryCache $rlRuntimeCache,
    protected ExperimentManager $rlExperimentManager,
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountProxyInterface $currentUser,
    protected LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    if ($route_name === 'help.page.rl_page_title') {
      $url = Url::fromRoute('view.rl_page_title_experiment.page_1')->toString();
      return '<p>' . $this->t('A/B test page titles for any page using Thompson Sampling. Source-agnostic: works for nodes, Views displays, custom controllers, and any path. Manage experiments at <a href=":url">Reinforcement Learning page title experiments</a>.', [
        ':url' => $url,
      ]) . '</p>';
    }
  }

  /**
   * Implements hook_preprocess_page_title().
   *
   * Captures the original title text in a per-request static so the
   * preprocess_html hook can do a targeted substitution within whatever
   * structure the head_title has been built into (which may already have
   * been mutated by metatag, dxpr_seo, etc.).
   */
  #[Hook('preprocess_page_title')]
  public function preprocessPageTitle(array &$variables) {
    $result = _rl_page_title_get_active_variant();
    if (!$result || $result['text'] === NULL) {
      return;
    }
    $original = (string) $variables['title'];
    if ($original !== '') {
      $this->rlRuntimeCache->set('rl_page_title_original', $original);
    }
    $variables['title'] = $result['text'];
  }

  /**
   * Implements hook_preprocess_html().
   *
   * Replaces the page-name portion of the <title> tag with the winning
   * variant while preserving the site name suffix and any other content
   * upstream modules added.
   *
   * Why a targeted substitution rather than overwriting head_title.title:
   *
   * - In a vanilla Drupal site, head_title is a multi-key array like
   *   `['title' => 'Home', 'name' => 'DXPR CMS']` joined by ` | `.
   *   Replacing the `title` value gives "Welcome | DXPR CMS" naturally.
   * - With Metatag installed, metatag_preprocess_html collapses the array
   *   into a single key with the FULL consolidated string (including the
   *   site name), e.g. `['title' => 'Home | DXPR CMS']`. Replacing the
   *   value here would drop the site name.
   *
   * Doing a substring replacement of the original page title within
   * whatever string the upstream module produced handles both cases:
   * "Home" -> "Welcome" inside "Home" yields "Welcome", and the same
   * substitution inside "Home | DXPR CMS" yields "Welcome | DXPR CMS".
   */
  #[Hook('preprocess_html')]
  public function preprocessHtml(array &$variables) {
    $result = _rl_page_title_get_active_variant();
    if (!$result || $result['text'] === NULL) {
      return;
    }
    if (!isset($variables['head_title']['title'])) {
      return;
    }

    // Resolve the original title text. Prefer the value captured during
    // preprocess_page_title; fall back to the title resolver for pages
    // without a rendered page title block.
    $original = $this->rlRuntimeCache->get('rl_page_title_original')->data ?? NULL;
    if ($original === NULL || $original === '') {
      $original = _rl_page_title_resolve_route_title();
    }
    if ($original === NULL || $original === '') {
      return;
    }

    $variables['head_title']['title'] = _rl_page_title_replace_head_title(
      $variables['head_title']['title'],
      $original,
      $result['text']
    );
  }

  /**
   * Implements hook_page_attachments().
   *
   * Always attaches a path-scoped cache tag, regardless of whether an
   * experiment exists yet. If a page is cached BEFORE an experiment is created
   * for it, the tag must already be there for invalidation to work.
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments) {
    $internal_path = _rl_page_title_current_internal_path();
    $attachments['#cache']['tags'][] = 'rl_page_title:' . $internal_path;
    $attachments['#cache']['tags'][] = 'rl_page_title:all';
    $result = _rl_page_title_get_active_variant();
    if (!$result) {
      return;
    }
    // The rl.php endpoint URL comes from drupalSettings.rl.endpointUrl,
    // published by rl_page_attachments() whenever the rl/api library is loaded.
    $attachments['#attached']['library'][] = 'rl_page_title/tracking';
    $attachments['#attached']['drupalSettings']['rlPageTitle'] = [
      'experimentId' => $result['experiment_id'],
      'armId' => $result['arm_id'],
    ];
  }

  /**
   * Implements hook_form_alter().
   *
   * Adds the page title variants vertical tab to content entity edit forms
   * for entities that have a canonical link template. Uses the entity's
   * current translation language so the experiment is scoped to that
   * language; an English node and its Spanish translation get separate
   * experiments via the same vertical tab.
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
    if (!$entity instanceof ContentEntityInterface || $entity->isNew()) {
      return;
    }
    if (!$entity->hasLinkTemplate('canonical')) {
      return;
    }
    if ($entity->getEntityTypeId() === 'rl_page_title_experiment') {
      return;
    }
    // Skip entities whose "canonical" URL is actually their edit form (e.g.
    // menu_link_content). For those entities there is no public page whose
    // title could meaningfully be tested, and without this check the tab
    // would appear on the menu link edit form next to the rl_menu_link tab
    // and testing a nonsensical admin path.
    if ($entity->hasLinkTemplate('edit-form')) {
      try {
        $canonical_url = $entity->toUrl('canonical')->toString();
        $edit_url = $entity->toUrl('edit-form')->toString();
        if ($canonical_url === $edit_url) {
          return;
        }
      }
      catch (\Exception $e) {
        return;
      }
    }
    if (!$this->currentUser->hasPermission('administer rl page title experiments')) {
      return;
    }
    try {
      $internal_path = PageTitleExperiment::normalizePath('/' . $entity->toUrl('canonical')->getInternalPath());
    }
    catch (\Exception $e) {
      return;
    }
    // Use the entity's current translation language for the experiment scope.
    $entity_langcode = $entity->language()->getId() ?: LanguageInterface::LANGCODE_NOT_SPECIFIED;
    // Indexed lookup against the UNIQUE lookup_hash column.
    $existing = NULL;
    $lookup_hash = PageTitleExperiment::computeLookupHash($internal_path, $entity_langcode);
    $matches = $this->entityTypeManager->getStorage('rl_page_title_experiment')->loadByProperties([
      'lookup_hash' => $lookup_hash,
    ]);
    if ($matches) {
      $candidate = reset($matches);
      if ($candidate instanceof PageTitleExperiment) {
        $existing = $candidate;
      }
    }
    $is_multilingual = $this->languageManager->isMultilingual();
    $language_name = $entity_langcode === LanguageInterface::LANGCODE_NOT_SPECIFIED ? $this->t('all languages') : $this->languageManager->getLanguage($entity_langcode)?->getName() ?? $entity_langcode;
    $baseline_title = (string) ($entity->label() ?? '');
    $form['rl_page_title'] = [
      '#type' => 'details',
      '#title' => $this->t('A/B test title'),
      '#group' => 'advanced',
      '#open' => FALSE,
      '#weight' => 50,
      '#tree' => TRUE,
      '#access' => TRUE,
    ];
    $form['rl_page_title']['baseline'] = [
      '#type' => 'item',
      '#title' => $this->t('Current title'),
      '#markup' => '<p class="rl-page-title-baseline"><em>' . Html::escape($baseline_title) . '</em></p>',
    ];
    if ($existing) {
      $rl_experiment_id = $existing->getRlExperimentId();
      $experiment_manager = $this->rlExperimentManager;
      $total_turns = $experiment_manager->getTotalTurns($rl_experiment_id);
      $arms = $experiment_manager->getAllArmsData($rl_experiment_id);
      $total_rewards = 0;
      $leading_arm = NULL;
      $leading_rate = -1.0;
      // Require at least this many turns per arm before the arm is eligible
      // to be displayed as the current leader. Below this threshold the
      // observed rate is dominated by sampling noise (a lucky 2-for-2 start
      // reads as 100 % but says nothing about the true rate).
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
        $progress_line = $this->t('No visitors have seen this test yet. Save the page and come back after some traffic arrives.');
      }
      elseif ($total_turns < 10 || $leading_text === NULL) {
        $progress_line = $this->t('Still collecting data. @turns visitors have seen a variant so far.', [
          '@turns' => number_format($total_turns),
        ]);
      }
      elseif ($leading_arm === 'v0') {
        $progress_line = $this->t('Your original title is currently ahead. @turns visitors, @rewards read the page.', [
          '@turns' => number_format($total_turns),
          '@rewards' => number_format($total_rewards),
        ]);
      }
      else {
        $progress_line = $this->t('"@alt" is currently ahead of your original. @turns visitors, @rewards read the page.', [
          '@alt' => $leading_text,
          '@turns' => number_format($total_turns),
          '@rewards' => number_format($total_rewards),
        ]);
      }
      $form['rl_page_title']['state'] = [
        '#type' => 'item',
        '#markup' => '<p><strong>' . $state_line . '</strong></p><p>' . $progress_line . '</p>',
      ];
      if ($total_turns > 0) {
        $form['rl_page_title']['report'] = [
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
      $intro = $is_multilingual ? $this->t('Not sure if your title is pulling its weight? Add a few alternatives below and we will show each one to a share of visitors reading this page in @lang, then tell you which one brings in the most engaged readers.', [
        '@lang' => $language_name,
      ]) : $this->t('Not sure if your title is pulling its weight? Add a few alternatives below and we will show each one to a share of visitors, then tell you which one brings in the most engaged readers.');
      $form['rl_page_title']['intro'] = [
        '#type' => 'item',
        '#markup' => '<p>' . $intro . '</p>',
      ];
    }
    $form['rl_page_title']['variants'] = [
      '#type' => 'textarea',
      '#title' => $existing ? $this->t('Alternatives you are testing') : $this->t('Try these alternative titles'),
      '#description' => $this->t('Enter one alternative per line. Your current title (shown above) always stays in the test.'),
      '#default_value' => $existing ? implode("\n", $existing->getVariants()) : '',
      '#rows' => 4,
    ];
    $form['rl_page_title']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Run this test'),
      '#description' => $this->t('Turn off to pause. Your collected data is kept safe.'),
      '#default_value' => $existing ? (bool) $existing->isPublished() : TRUE,
    ];
    // Explicit "Stop testing" affordance for an existing experiment. This
    // replaces the old "leave the textarea empty to silently delete"
    // shortcut, which was a foot-gun: a user clearing the field by accident
    // would destroy all their collected data on the next save.
    if ($existing) {
      $form['rl_page_title']['remove_link'] = [
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
        '#prefix' => '<div class="rl-page-title-remove">',
        '#suffix' => '</div>',
      ];
    }
    $form['rl_page_title']['_path'] = [
      '#type' => 'value',
      '#value' => $internal_path,
    ];
    $form['rl_page_title']['_langcode'] = [
      '#type' => 'value',
      '#value' => $entity_langcode,
    ];
    if ($existing) {
      $form['rl_page_title']['_existing_id'] = [
        '#type' => 'value',
        '#value' => $existing->id(),
      ];
    }
    if (isset($form['actions']) && is_array($form['actions'])) {
      foreach (Element::children($form['actions']) as $action_key) {
        if (isset($form['actions'][$action_key]['#type']) && $form['actions'][$action_key]['#type'] === 'submit') {
          $form['actions'][$action_key]['#submit'][] = '_rl_page_title_entity_form_submit';
        }
      }
    }
  }

  /**
   * Implements hook_entity_predelete().
   *
   * Cleans up page title experiments when the target entity is deleted, and
   * also purges the corresponding RL analytics tables. All language scopes
   * for the deleted entity's path are cleaned up at once.
   */
  #[Hook('entity_predelete')]
  public function entityPredelete(EntityInterface $entity) {
    if (!$entity instanceof ContentEntityInterface) {
      return;
    }
    if (!$entity->hasLinkTemplate('canonical')) {
      return;
    }
    if ($entity->getEntityTypeId() === 'rl_page_title_experiment') {
      return;
    }
    try {
      $internal_path = PageTitleExperiment::normalizePath('/' . $entity->toUrl('canonical')->getInternalPath());
    }
    catch (\Exception $e) {
      return;
    }
    $storage = $this->entityTypeManager->getStorage('rl_page_title_experiment');
    $matches = $storage->loadByProperties([
      'path' => $internal_path,
    ]);
    if (!$matches) {
      return;
    }
    $manager = $this->rlExperimentManager;
    $invalidate_tags = [];
    foreach ($matches as $experiment) {
      if (!$experiment instanceof PageTitleExperiment) {
        continue;
      }
      $rl_id = $experiment->getRlExperimentId();
      $invalidate_tags['rl_page_title:' . $experiment->getPath()] = TRUE;
      $manager->purgeExperiment($rl_id);
      $experiment->delete();
    }
    // Close the cache-as-you-invalidate loop: any page that was rendered
    // before the host entity delete still carries `rl_page_title:{path}`
    // and would keep serving the cascade-deleted experiment's variant
    // until natural cache turnover.
    if ($invalidate_tags) {
      Cache::invalidateTags(array_keys($invalidate_tags));
    }
  }

  /**
   * Implements hook_preprocess_views_view_field().
   *
   * Rewrites the Label cell in the page title experiment admin Views table
   * so it links to the experiment report instead of the entity edit form.
   * The report is the more useful destination for someone scanning the
   * list: it shows per-arm statistics. The Operations column still
   * provides Edit/Delete for entity-level actions.
   */
  #[Hook('preprocess_views_view_field')]
  public function preprocessViewsViewField(array &$variables): void {
    if ($variables['view']->id() !== 'rl_page_title_experiment') {
      return;
    }
    if ($variables['field']->field !== 'label') {
      return;
    }
    $entity = $variables['row']->_entity ?? NULL;
    if (!$entity instanceof PageTitleExperiment) {
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
