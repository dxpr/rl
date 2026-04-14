<?php

declare(strict_types=1);

namespace Drupal\rl_page_title\Drush\Commands;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Controller\TitleResolverInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Symfony\Component\Routing\RouterInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\rl\Drush\Commands\RlCommandsBase;
use Drupal\rl\Experiment\VariantParser;
use Drupal\rl\Registry\ExperimentRegistryInterface;
use Drupal\rl\Service\ExperimentManagerInterface;
use Drupal\rl_page_title\Entity\PageTitleExperiment;
use Drush\Attributes as CLI;
use Symfony\Component\HttpFoundation\Request;

/**
 * Drush commands for managing page title A/B test experiments.
 */
final class RlPageTitleCommands extends RlCommandsBase {

  /**
   * Constructs RlPageTitleCommands.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ExperimentRegistryInterface $experimentRegistry,
    protected readonly ExperimentManagerInterface $experimentManager,
    protected readonly AliasManagerInterface $aliasManager,
    protected readonly RouterInterface $router,
    protected readonly TitleResolverInterface $titleResolver,
  ) {
    parent::__construct();
  }

  /**
   * Resolve a sensible default label for the given internal path.
   *
   * Mirrors what the entity edit form's vertical tab does: prefer the
   * matched entity's label (e.g. node title for /node/12), and fall back
   * to the route's resolved title (e.g. "Administration" for /admin) so
   * that auto-generated experiment labels reflect what users actually
   * see in the page title rather than the raw URL.
   *
   * Returns NULL if neither resolution succeeds; callers should fall
   * back to the path itself.
   */
  protected function resolvePathLabel(string $internal_path): ?string {
    try {
      $match = $this->router->match($internal_path);
    }
    catch (\Exception $e) {
      return NULL;
    }

    // Prefer entity label when the route binds a content entity parameter.
    foreach ($match as $value) {
      if ($value instanceof ContentEntityInterface) {
        $label = $value->label();
        if ($label !== NULL && $label !== '') {
          return (string) $label;
        }
      }
    }

    // Otherwise resolve the route's title (covers /admin, /user/login, etc.).
    try {
      $route = $match['_route_object'] ?? NULL;
      if ($route === NULL) {
        return NULL;
      }
      $request = Request::create($internal_path);
      $title = $this->titleResolver->getTitle($request, $route);
      if ($title === NULL || $title === '') {
        return NULL;
      }
      return (string) $title;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Lists all page title experiments.
   */
  #[CLI\Command(name: 'rl:page-title:list', aliases: ['rl-ptl'])]
  #[CLI\Help(description: '[YAML] List all page title experiments with their target paths and language scope.')]
  #[CLI\Option(name: 'enabled', description: 'Filter by enabled status: yes, no, all')]
  #[CLI\Usage(name: 'drush rl:page-title:list', description: 'List all experiments')]
  #[CLI\Usage(name: 'drush rl-ptl --enabled=yes', description: 'List only active experiments')]
  public function list(array $options = ['enabled' => 'all']): string {
    $this->switchToAdmin();

    $storage = $this->entityTypeManager->getStorage('rl_page_title_experiment');
    $properties = [];
    if ($options['enabled'] === 'yes') {
      $properties['enabled'] = TRUE;
    }
    elseif ($options['enabled'] === 'no') {
      $properties['enabled'] = FALSE;
    }

    $entities = $properties ? $storage->loadByProperties($properties) : $storage->loadMultiple();
    $items = [];
    foreach ($entities as $entity) {
      if (!$entity instanceof PageTitleExperiment) {
        continue;
      }
      $rl_id = $entity->getRlExperimentId();
      $items[] = [
        'id' => $entity->id(),
        'label' => $entity->label(),
        'path' => $entity->getPath(),
        'langcode' => $entity->language()->getId(),
        'variants' => count($entity->getVariants()) + 1,
        'enabled' => (bool) $entity->isPublished(),
        'rl_experiment_id' => $rl_id,
        'impressions' => $this->experimentManager->getTotalTurns($rl_id),
      ];
    }

    return $this->successList($items);
  }

  /**
   * Shows full details for one page title experiment.
   */
  #[CLI\Command(name: 'rl:page-title:get', aliases: ['rl-ptg'])]
  #[CLI\Help(description: '[YAML] Show full details for one page title experiment, including variants and live RL stats.')]
  #[CLI\Argument(name: 'experimentId', description: 'The page title experiment entity ID')]
  #[CLI\Usage(name: 'drush rl:page-title:get 42', description: 'Show experiment 42')]
  public function get(string $experimentId): string {
    $this->switchToAdmin();

    $entity = $this->entityTypeManager->getStorage('rl_page_title_experiment')->load($experimentId);
    if (!$entity instanceof PageTitleExperiment) {
      return $this->error(sprintf('Page title experiment "%s" not found.', $experimentId));
    }

    $rl_id = $entity->getRlExperimentId();
    $arms = [];
    foreach ($this->experimentManager->getAllArmsData($rl_id) as $arm) {
      $arms[$arm->arm_id] = [
        'turns' => (int) $arm->turns,
        'rewards' => (int) $arm->rewards,
        'rate' => $arm->turns > 0 ? round(($arm->rewards / $arm->turns) * 100, 2) : 0.0,
      ];
    }

    return $this->yaml([
      'id' => $entity->id(),
      'label' => $entity->label(),
      'path' => $entity->getPath(),
      'langcode' => $entity->language()->getId(),
      'enabled' => (bool) $entity->isPublished(),
      'variants' => $entity->getVariants(),
      'rl_experiment_id' => $rl_id,
      'analytics' => [
        'total_turns' => $this->experimentManager->getTotalTurns($rl_id),
        'arms' => $arms,
      ],
    ]);
  }

  /**
   * Creates a new page title experiment.
   */
  #[CLI\Command(name: 'rl:page-title:create', aliases: ['rl-ptc'])]
  #[CLI\Help(description: '[YAML] Create a new page title A/B test experiment.')]
  #[CLI\Argument(name: 'path', description: 'Internal path or alias to test (e.g. /node/42, /blog, /user/login)')]
  #[CLI\Option(name: 'variants', description: 'Comma-separated alternative titles, OR multiple --variants=text flags')]
  #[CLI\Option(name: 'label', description: 'Human-readable label (default: derived from path)')]
  #[CLI\Option(name: 'langcode', description: 'Language code, or "und" for all languages (default: und)')]
  #[CLI\Option(name: 'disabled', description: 'Create as disabled instead of active')]
  #[CLI\Option(name: 'dry-run', description: 'Preview without creating')]
  #[CLI\Usage(name: 'drush rl:page-title:create /blog --variants="News & Insights,Latest Articles"', description: 'Create with two variants')]
  #[CLI\Usage(name: 'drush rl-ptc /node/42 --variants="Alt One" --variants="Alt Two" --langcode=es', description: 'Create Spanish-only experiment')]
  public function create(
    string $path,
    array $options = [
      'variants' => NULL,
      'label' => NULL,
      'langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED,
      'disabled' => FALSE,
      'dry-run' => FALSE,
    ],
  ): string {
    $this->switchToAdmin();

    $variants = $this->parseVariants($options['variants'] ?? NULL);
    if (empty($variants)) {
      return $this->error('At least one variant is required.', ['Use --variants="Alt 1,Alt 2" or --variants=Alt1 --variants=Alt2']);
    }

    $resolved = $this->aliasManager->getPathByAlias($path);
    $internal_path = PageTitleExperiment::normalizePath($resolved);
    $langcode = (string) ($options['langcode'] ?? LanguageInterface::LANGCODE_NOT_SPECIFIED);

    // Indexed duplicate detection against the UNIQUE lookup_hash column.
    $lookup_hash = PageTitleExperiment::computeLookupHash($internal_path, $langcode);
    $duplicates = $this->entityTypeManager
      ->getStorage('rl_page_title_experiment')
      ->loadByProperties(['lookup_hash' => $lookup_hash]);
    if ($duplicates) {
      $existing = reset($duplicates);
      return $this->error(
        sprintf('An experiment for "%s" (%s) already exists: %s.', $internal_path, $langcode, $existing->id()),
        ['Use rl:page-title:update to modify it.']
      );
    }

    $label = $options['label']
      ?? $this->resolvePathLabel($internal_path)
      ?? $internal_path;

    if ($options['dry-run']) {
      return $this->yaml([
        'dry_run' => TRUE,
        'action' => 'create',
        'experiment' => [
          'path' => $internal_path,
          'langcode' => $langcode,
          'label' => $label,
          'variants' => $variants,
          'enabled' => !$options['disabled'],
        ],
      ]);
    }

    $entity = $this->entityTypeManager->getStorage('rl_page_title_experiment')->create([
      'label' => $label,
      'path' => $internal_path,
      'langcode' => $langcode,
    ]);
    assert($entity instanceof PageTitleExperiment);
    $entity->setVariants($variants);
    if ($options['disabled']) {
      $entity->setUnpublished();
    }
    else {
      $entity->setPublished();
    }
    $entity->save();

    $this->experimentRegistry->register($entity->getRlExperimentId(), 'rl_page_title', $label);

    return $this->success(
      sprintf('Created page title experiment "%s".', $entity->id()),
      [
        'experiment' => [
          'id' => $entity->id(),
          'label' => $label,
          'path' => $internal_path,
          'langcode' => $langcode,
          'variants' => $variants,
          'rl_experiment_id' => $entity->getRlExperimentId(),
        ],
      ]
    );
  }

  /**
   * Updates an existing page title experiment.
   */
  #[CLI\Command(name: 'rl:page-title:update', aliases: ['rl-ptu'])]
  #[CLI\Help(description: '[YAML] Update an existing page title experiment.')]
  #[CLI\Argument(name: 'experimentId', description: 'The page title experiment entity ID')]
  #[CLI\Option(name: 'label', description: 'New human-readable label')]
  #[CLI\Option(name: 'variants', description: 'Replace variants list (comma-separated, or multiple --variants flags)')]
  #[CLI\Option(name: 'enable', description: 'Set enabled state to true')]
  #[CLI\Option(name: 'disable', description: 'Set enabled state to false')]
  #[CLI\Option(name: 'dry-run', description: 'Preview without saving')]
  public function update(
    string $experimentId,
    array $options = [
      'label' => NULL,
      'variants' => NULL,
      'enable' => FALSE,
      'disable' => FALSE,
      'dry-run' => FALSE,
    ],
  ): string {
    $this->switchToAdmin();

    $entity = $this->entityTypeManager->getStorage('rl_page_title_experiment')->load($experimentId);
    if (!$entity instanceof PageTitleExperiment) {
      return $this->error(sprintf('Page title experiment "%s" not found.', $experimentId));
    }

    $changes = [];
    if ($options['label'] !== NULL) {
      $changes['label'] = $options['label'];
    }
    if ($options['variants'] !== NULL) {
      $variants = $this->parseVariants($options['variants']);
      if (empty($variants)) {
        return $this->error('At least one variant is required when --variants is provided.');
      }
      $changes['variants'] = $variants;
    }
    if ($options['enable']) {
      $changes['enabled'] = TRUE;
    }
    elseif ($options['disable']) {
      $changes['enabled'] = FALSE;
    }

    if (empty($changes)) {
      return $this->error('Nothing to update. Provide --label, --variants, --enable, or --disable.');
    }

    if ($options['dry-run']) {
      return $this->yaml([
        'dry_run' => TRUE,
        'action' => 'update',
        'experiment_id' => $experimentId,
        'changes' => $changes,
      ]);
    }

    if (isset($changes['label'])) {
      $entity->set('label', $changes['label']);
    }
    if (isset($changes['variants'])) {
      $entity->setVariants($changes['variants']);
    }
    if (array_key_exists('enabled', $changes)) {
      if ($changes['enabled']) {
        $entity->setPublished();
      }
      else {
        $entity->setUnpublished();
      }
    }
    $entity->save();

    $this->experimentRegistry->register($entity->getRlExperimentId(), 'rl_page_title', $entity->label());

    return $this->success(
      sprintf('Updated page title experiment "%s".', $experimentId),
      ['changes' => $changes]
    );
  }

  /**
   * Deletes a page title experiment and purges its analytics.
   */
  #[CLI\Command(name: 'rl:page-title:delete', aliases: ['rl-ptd'])]
  #[CLI\Help(description: '[YAML] Delete a page title experiment AND purge its RL analytics (turns, rewards, snapshots, registry).')]
  #[CLI\Argument(name: 'experimentId', description: 'The page title experiment entity ID')]
  #[CLI\Option(name: 'dry-run', description: 'Preview what would be deleted')]
  public function delete(
    string $experimentId,
    array $options = [
      'dry-run' => FALSE,
    ],
  ): string {
    $this->switchToAdmin();

    $entity = $this->entityTypeManager->getStorage('rl_page_title_experiment')->load($experimentId);
    if (!$entity instanceof PageTitleExperiment) {
      return $this->error(sprintf('Page title experiment "%s" not found.', $experimentId));
    }

    $rl_id = $entity->getRlExperimentId();
    $turns = $this->experimentManager->getTotalTurns($rl_id);

    if ($options['dry-run']) {
      return $this->yaml([
        'dry_run' => TRUE,
        'action' => 'delete',
        'experiment' => [
          'id' => $entity->id(),
          'path' => $entity->getPath(),
          'langcode' => $entity->language()->getId(),
          'rl_experiment_id' => $rl_id,
          'analytics_turns' => $turns,
        ],
      ]);
    }

    // Capture the path before delete so we can invalidate the render-cache
    // tag afterward; mirrors the GUI delete form.
    $path = $entity->getPath();

    // Purge analytics first; if it fails, the entity is left intact for retry.
    $this->experimentManager->purgeExperiment($rl_id);
    $entity->delete();

    // Close the cache-as-you-invalidate loop: cached pages at this path
    // still carry `rl_page_title:{path}` and would keep serving the now-
    // deleted experiment's rendered variant until natural cache turnover.
    Cache::invalidateTags(['rl_page_title:' . $path]);

    return $this->success(
      sprintf('Deleted page title experiment "%s" and purged %d turns.', $experimentId, $turns),
    );
  }

  /**
   * Parse variants from --variants option.
   *
   * Accepts a comma-separated string OR an array (Drush passes multiple
   * --variants flags as an array).
   *
   * @param mixed $raw
   *   The raw option value.
   *
   * @return string[]
   *   Trimmed, non-empty variant strings.
   */
  protected function parseVariants(mixed $raw): array {
    if ($raw === NULL || $raw === '') {
      return [];
    }
    if (is_array($raw)) {
      $combined = implode("\n", $raw);
    }
    else {
      $combined = str_replace(',', "\n", (string) $raw);
    }
    return VariantParser::parse($combined);
  }

}
