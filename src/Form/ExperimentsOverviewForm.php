<?php

namespace Drupal\rl\Form;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\Core\Utility\TableSort;
use Drupal\rl\Storage\ExperimentDataStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Lists experiments with sortable statistics and bulk operations.
 *
 * @see \Drupal\comment\Form\CommentAdminOverview
 */
class ExperimentsOverviewForm extends FormBase {

  /**
   * Constructs the experiment overview form.
   */
  public function __construct(
    protected ExperimentDataStorageInterface $experimentStorage,
    protected DateFormatterInterface $dateFormatter,
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected PagerManagerInterface $pagerManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    // @phpstan-ignore new.static
    return new static(
      $container->get('rl.experiment_data_storage'),
      $container->get('date.formatter'),
      $container->get('tempstore.private'),
      $container->get('pager.manager'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'rl_experiments_overview';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $can_administer = $this->currentUser()->hasPermission('administer rl experiments');
    $header = [
      'experiment_name' => ['data' => $this->t('Experiment'), 'field' => 'experiment_name'],
      'module' => ['data' => $this->t('Source'), 'field' => 'module', 'class' => [RESPONSIVE_PRIORITY_LOW]],
      'total_turns' => ['data' => $this->t('Impressions'), 'field' => 'total_turns'],
      'total_rewards' => ['data' => $this->t('Conversions'), 'field' => 'total_rewards'],
      'arm_count' => ['data' => $this->t('Variants'), 'field' => 'arm_count', 'class' => [RESPONSIVE_PRIORITY_MEDIUM]],
      'registered_at' => [
        'data' => $this->t('Created'),
        'field' => 'registered_at',
        'sort' => 'desc',
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
      'last_activity' => [
        'data' => $this->t('Last Activity'),
        'field' => 'last_activity',
        'class' => [RESPONSIVE_PRIORITY_LOW],
      ],
    ];

    $experiments = $this->experimentStorage->getExperimentsWithStats();
    foreach ($experiments as $experiment) {
      // Sort the values users see, including names and activity fallbacks.
      $experiment->experiment_name = $experiment->experiment_name ?: $experiment->experiment_id;
      $experiment->last_activity = $experiment->totals_updated ?: $experiment->registered_at;
    }
    $sort = TableSort::getContextFromRequest($header, $this->getRequest());
    $field = $sort['sql'];
    usort($experiments, static function (object $a, object $b) use ($field, $sort): int {
      $comparison = in_array($field, ['experiment_name', 'module'], TRUE)
        ? strnatcasecmp($a->$field, $b->$field)
        : (int) $a->$field <=> (int) $b->$field;
      // Keep ties stable across pages and requests.
      return ($sort['sort'] === 'desc' ? -$comparison : $comparison)
        ?: strcmp($a->experiment_id, $b->experiment_id);
    });
    $pager = $this->pagerManager->createPager(count($experiments), 50);
    $experiments = array_slice($experiments, $pager->getCurrentPage() * 50, 50);

    $form['add'] = [
      '#type' => 'link',
      '#title' => $this->t('Add experiment'),
      '#url' => Url::fromRoute('rl.experiment.add'),
      '#attributes' => ['class' => ['button', 'button--primary']],
      '#access' => $can_administer,
    ];
    $form['description'] = [
      '#markup' => '<p>' . $this->t('Deleting an experiment resets its data. Experiments auto-recreate on next render.') . '</p>',
    ];
    $form['selection_help'] = [
      '#markup' => '<p>' . $this->t('Select experiments on this page to apply an action.') . '</p>',
      '#access' => $can_administer && !empty($experiments),
    ];
    $form['options'] = [
      '#type' => 'details',
      '#title' => $this->t('Update options'),
      '#open' => TRUE,
      '#attributes' => ['class' => ['container-inline']],
      '#access' => $can_administer && !empty($experiments),
    ];
    $form['options']['operation'] = [
      '#type' => 'select',
      '#title' => $this->t('Action'),
      '#options' => ['delete' => $this->t('Delete selected experiments')],
    ];
    $form['options']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply to selected items'),
    ];

    $rows = [];
    $form['#experiments'] = [];
    foreach ($experiments as $experiment) {
      $id = $experiment->experiment_id;
      // IDs may contain brackets or be "0"; use safe, non-numeric form keys.
      $key = 'experiment_' . hash('sha256', $id);
      $form['#experiments'][$key] = ['id' => $id, 'name' => $experiment->experiment_name];
      $name = [
        'view' => [
          '#type' => 'container',
          'link' => [
            '#type' => 'link',
            '#title' => $experiment->experiment_name,
            '#url' => Url::fromRoute('rl.reports.experiment_detail', ['experiment_id' => $id]),
          ],
        ],
        'operations' => [
          '#type' => 'operations',
          '#links' => [
            'delete' => [
              'title' => $this->t('Delete'),
              'url' => Url::fromRoute('rl.experiment.delete', ['experiment_id' => $id], ['query' => $this->getDestinationArray()]),
            ],
          ],
          '#access' => $can_administer,
        ],
      ];
      $rows[$key] = [
        'experiment_name' => ['data' => $name],
        'module' => $experiment->module,
        'total_turns' => $experiment->total_turns ?: 0,
        'total_rewards' => $experiment->total_rewards,
        'arm_count' => $experiment->arm_count,
        'registered_at' => $experiment->registered_at > 0 ? $this->dateFormatter->format($experiment->registered_at, 'short') : $this->t('Never'),
        'last_activity' => $experiment->last_activity > 0 ? $this->dateFormatter->format($experiment->last_activity, 'short') : $this->t('Never'),
      ];
    }
    $form['experiments'] = [
      '#type' => $can_administer ? 'tableselect' : 'table',
      '#header' => $header,
      '#empty' => $this->t('No experiments found.'),
    ];
    if ($can_administer) {
      // Core tableselect uses this title for accessible checkbox labels.
      foreach ($rows as $key => &$row) {
        $row['title']['data']['#title'] = $form['#experiments'][$key]['name'];
      }
      $form['experiments']['#options'] = $rows;
    }
    else {
      $form['experiments']['#rows'] = $rows;
    }
    $form['pager'] = ['#type' => 'pager'];
    $form['#cache']['max-age'] = 0;
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('administer rl experiments')) {
      throw new AccessDeniedHttpException();
    }
    $selected = array_filter($form_state->getValue('experiments', []));
    $selection = array_intersect_key($form['#experiments'], $selected);
    if (!$selection) {
      $form_state->setErrorByName('experiments', $this->t('Select one or more experiments.'));
    }
    $form_state->set('selection', array_values($selection));
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('administer rl experiments')) {
      throw new AccessDeniedHttpException();
    }
    // Separate tokens prevent another tab from replacing this selection.
    $token = Crypt::randomBytesBase64();
    $this->tempStoreFactory->get('rl_experiment_delete_multiple')->set($token, $form_state->get('selection'));
    $form_state->setRedirect('rl.experiment.delete_multiple', ['selection' => $token], [
      'query' => array_intersect_key($this->getRequest()->query->all(), array_flip(['order', 'sort', 'page'])),
    ]);
  }

}
