<?php

namespace Drupal\rl\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\rl\Registry\ExperimentRegistryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Filters the experiments overview by keywords, source and activity.
 *
 * Submitting redirects to the overview with the filters in the URL, so
 * filtered lists can be bookmarked, paged, sorted and shared.
 */
final class ExperimentsFilterForm extends FormBase {

  /**
   * Query parameters that hold the filters.
   */
  const FILTER_KEYS = ['search', 'source', 'activity'];

  /**
   * Constructs the experiments filter form.
   */
  public function __construct(
    protected ExperimentRegistryInterface $experimentRegistry,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('rl.experiment_registry'));
  }

  /**
   * Reads the filters from the query string.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   *
   * @return string[]
   *   The search, source and activity filters; empty strings when unset.
   */
  public static function getFilters(Request $request): array {
    $query = $request->query->all();
    $filters = [];
    foreach (self::FILTER_KEYS as $key) {
      $filters[$key] = isset($query[$key]) && is_string($query[$key]) ? trim($query[$key]) : '';
    }
    if (!in_array($filters['activity'], ['active', 'idle'], TRUE)) {
      $filters['activity'] = '';
    }
    return $filters;
  }

  /**
   * Tells whether any filter is set; a search for "0" counts as set.
   *
   * @param string[] $filters
   *   The filters from ::getFilters().
   */
  public static function isFiltered(array $filters): bool {
    return count(array_filter($filters, static fn (string $value): bool => $value !== '')) > 0;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'rl_experiments_filter';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $filters = self::getFilters($this->getRequest());
    $sources = array_filter(array_unique($this->experimentRegistry->getAll()));
    natcasesort($sources);
    $sources = array_combine($sources, $sources);

    $form['#attributes']['class'][] = 'form--inline';
    $form['search'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Name or ID'),
      '#size' => 30,
      '#maxlength' => 255,
      '#default_value' => $filters['search'],
    ];
    $form['source'] = [
      '#type' => 'select',
      '#title' => $this->t('Source'),
      '#options' => $sources,
      '#empty_option' => $this->t('- Any -'),
      '#default_value' => isset($sources[$filters['source']]) ? $filters['source'] : '',
    ];
    $form['activity'] = [
      '#type' => 'select',
      '#title' => $this->t('Activity'),
      '#options' => [
        'active' => $this->t('With impressions'),
        'idle' => $this->t('No impressions yet'),
      ],
      '#empty_option' => $this->t('- Any -'),
      '#default_value' => $filters['activity'],
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Filter'),
    ];
    if (self::isFiltered($filters)) {
      $form['actions']['reset'] = [
        '#type' => 'link',
        '#title' => $this->t('Reset'),
        '#url' => Url::fromRoute('rl.reports.experiments', [], ['query' => $this->getSortQuery()]),
        '#attributes' => ['class' => ['button']],
      ];
    }
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $query = [];
    foreach (self::FILTER_KEYS as $key) {
      $value = trim((string) $form_state->getValue($key));
      if ($value !== '') {
        $query[$key] = $value;
      }
    }
    // A new filter starts on the first page and keeps the chosen sort order.
    $form_state->setIgnoreDestination();
    $form_state->setRedirect('rl.reports.experiments', [], [
      'query' => $query + $this->getSortQuery(),
    ]);
  }

  /**
   * Returns the sort parameters of the current request.
   */
  protected function getSortQuery(): array {
    return array_filter(
      array_intersect_key($this->getRequest()->query->all(), array_flip(['order', 'sort'])),
      'is_string',
    );
  }

}
