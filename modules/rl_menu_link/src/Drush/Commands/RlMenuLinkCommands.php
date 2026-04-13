<?php

declare(strict_types=1);

namespace Drupal\rl_menu_link\Drush\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Menu\MenuLinkManagerInterface;
use Drupal\rl\Drush\Commands\RlCommandsBase;
use Drupal\rl\Experiment\VariantParser;
use Drupal\rl\Registry\ExperimentRegistryInterface;
use Drupal\rl\Service\ExperimentManagerInterface;
use Drupal\rl_menu_link\Entity\MenuLinkExperiment;
use Drush\Attributes as CLI;

/**
 * Drush commands for managing menu link A/B test experiments.
 */
final class RlMenuLinkCommands extends RlCommandsBase {

  /**
   * Constructs RlMenuLinkCommands.
   */
  public function __construct(
    protected readonly EntityTypeManagerInterface $entityTypeManager,
    protected readonly ExperimentRegistryInterface $experimentRegistry,
    protected readonly ExperimentManagerInterface $experimentManager,
    protected readonly MenuLinkManagerInterface $menuLinkManager,
  ) {
    parent::__construct();
  }

  /**
   * Resolve a sensible default label for the given menu link plugin ID.
   *
   * Mirrors what the entity edit form's vertical tab does: ask the menu
   * link manager for the link's current title (e.g. "Blog" for a
   * menu_link_content link, "Content" for system.admin_content) so the
   * auto-generated experiment label reflects what users actually see in
   * the navigation rather than the raw plugin ID.
   *
   * Returns NULL if the plugin can't be instantiated; callers should fall
   * back to the plugin ID itself.
   */
  protected function resolvePluginLabel(string $plugin_id): ?string {
    if (!$this->menuLinkManager->hasDefinition($plugin_id)) {
      return NULL;
    }
    try {
      $title = (string) $this->menuLinkManager->createInstance($plugin_id)->getTitle();
      return $title !== '' ? $title : NULL;
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Lists all menu link experiments.
   */
  #[CLI\Command(name: 'rl:menu-link:list', aliases: ['rl-mll'])]
  #[CLI\Help(description: '[YAML] List all menu link experiments with their plugin IDs and language scope.')]
  #[CLI\Option(name: 'enabled', description: 'Filter by enabled status: yes, no, all')]
  public function list(array $options = ['enabled' => 'all']): string {
    $this->switchToAdmin();

    $storage = $this->entityTypeManager->getStorage('rl_menu_link_experiment');
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
      if (!$entity instanceof MenuLinkExperiment) {
        continue;
      }
      $rl_id = $entity->getRlExperimentId();
      $items[] = [
        'id' => $entity->id(),
        'label' => $entity->label(),
        'menu_link_plugin_id' => $entity->getMenuLinkPluginId(),
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
   * Shows full details for one menu link experiment.
   */
  #[CLI\Command(name: 'rl:menu-link:get', aliases: ['rl-mlg'])]
  #[CLI\Help(description: '[YAML] Show full details for one menu link experiment, including variants and live RL stats.')]
  #[CLI\Argument(name: 'experimentId', description: 'The menu link experiment entity ID')]
  public function get(string $experimentId): string {
    $this->switchToAdmin();

    $entity = $this->entityTypeManager->getStorage('rl_menu_link_experiment')->load($experimentId);
    if (!$entity instanceof MenuLinkExperiment) {
      return $this->error(sprintf('Menu link experiment "%s" not found.', $experimentId));
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

    $original_label = NULL;
    if ($this->menuLinkManager->hasDefinition($entity->getMenuLinkPluginId())) {
      try {
        $original_label = (string) $this->menuLinkManager
          ->createInstance($entity->getMenuLinkPluginId())
          ->getTitle();
      }
      catch (\Exception $e) {
        // Ignore.
      }
    }

    return $this->yaml([
      'id' => $entity->id(),
      'label' => $entity->label(),
      'menu_link_plugin_id' => $entity->getMenuLinkPluginId(),
      'original_label' => $original_label,
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
   * Creates a new menu link experiment.
   */
  #[CLI\Command(name: 'rl:menu-link:create', aliases: ['rl-mlc'])]
  #[CLI\Help(description: '[YAML] Create a new menu link A/B test experiment.')]
  #[CLI\Argument(name: 'pluginId', description: 'Menu link plugin ID (e.g. menu_link_content:abc-uuid or system.admin_content)')]
  #[CLI\Option(name: 'variants', description: 'Comma-separated alternative labels, OR multiple --variants flags')]
  #[CLI\Option(name: 'label', description: 'Human-readable experiment label (default: derived from plugin ID)')]
  #[CLI\Option(name: 'langcode', description: 'Language code, or "und" for all languages (default: und)')]
  #[CLI\Option(name: 'disabled', description: 'Create as disabled')]
  #[CLI\Option(name: 'dry-run', description: 'Preview without creating')]
  #[CLI\Usage(name: 'drush rl:menu-link:create system.admin_content --variants="Content,Manage Content"', description: 'Test core admin link')]
  #[CLI\Usage(name: 'drush rl-mlc menu_link_content:abc-uuid --variants="Services,What We Do" --langcode=en', description: 'English-only experiment')]
  public function create(
    string $pluginId,
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

    $pluginId = trim($pluginId);
    if (!$this->menuLinkManager->hasDefinition($pluginId)) {
      return $this->error(sprintf('No menu link plugin "%s" is registered.', $pluginId));
    }

    $langcode = (string) ($options['langcode'] ?? LanguageInterface::LANGCODE_NOT_SPECIFIED);

    // Indexed duplicate detection against the UNIQUE lookup_hash column.
    $lookup_hash = MenuLinkExperiment::computeLookupHash($pluginId, $langcode);
    $duplicates = $this->entityTypeManager
      ->getStorage('rl_menu_link_experiment')
      ->loadByProperties(['lookup_hash' => $lookup_hash]);
    if ($duplicates) {
      $existing = reset($duplicates);
      return $this->error(
        sprintf('An experiment for "%s" (%s) already exists: %s.', $pluginId, $langcode, $existing->id()),
        ['Use rl:menu-link:update to modify it.']
      );
    }

    $label = $options['label'] ?? $this->resolvePluginLabel($pluginId) ?? $pluginId;

    if ($options['dry-run']) {
      return $this->yaml([
        'dry_run' => TRUE,
        'action' => 'create',
        'experiment' => [
          'menu_link_plugin_id' => $pluginId,
          'langcode' => $langcode,
          'label' => $label,
          'variants' => $variants,
          'enabled' => !$options['disabled'],
        ],
      ]);
    }

    $entity = $this->entityTypeManager->getStorage('rl_menu_link_experiment')->create([
      'label' => $label,
      'menu_link_plugin_id' => $pluginId,
      'langcode' => $langcode,
    ]);
    assert($entity instanceof MenuLinkExperiment);
    $entity->setVariants($variants);
    if ($options['disabled']) {
      $entity->setUnpublished();
    }
    else {
      $entity->setPublished();
    }
    $entity->save();

    $this->experimentRegistry->register($entity->getRlExperimentId(), 'rl_menu_link', $label);

    return $this->success(
      sprintf('Created menu link experiment "%s".', $entity->id()),
      [
        'experiment' => [
          'id' => $entity->id(),
          'label' => $label,
          'menu_link_plugin_id' => $pluginId,
          'langcode' => $langcode,
          'variants' => $variants,
          'rl_experiment_id' => $entity->getRlExperimentId(),
        ],
      ]
    );
  }

  /**
   * Updates an existing menu link experiment.
   */
  #[CLI\Command(name: 'rl:menu-link:update', aliases: ['rl-mlu'])]
  #[CLI\Help(description: '[YAML] Update an existing menu link experiment.')]
  #[CLI\Argument(name: 'experimentId', description: 'The menu link experiment entity ID')]
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

    $entity = $this->entityTypeManager->getStorage('rl_menu_link_experiment')->load($experimentId);
    if (!$entity instanceof MenuLinkExperiment) {
      return $this->error(sprintf('Menu link experiment "%s" not found.', $experimentId));
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

    $this->experimentRegistry->register($entity->getRlExperimentId(), 'rl_menu_link', $entity->label());

    return $this->success(
      sprintf('Updated menu link experiment "%s".', $experimentId),
      ['changes' => $changes]
    );
  }

  /**
   * Deletes a menu link experiment and purges its analytics.
   */
  #[CLI\Command(name: 'rl:menu-link:delete', aliases: ['rl-mld'])]
  #[CLI\Help(description: '[YAML] Delete a menu link experiment AND purge its RL analytics.')]
  #[CLI\Argument(name: 'experimentId', description: 'The menu link experiment entity ID')]
  #[CLI\Option(name: 'dry-run', description: 'Preview what would be deleted')]
  public function delete(
    string $experimentId,
    array $options = [
      'dry-run' => FALSE,
    ],
  ): string {
    $this->switchToAdmin();

    $entity = $this->entityTypeManager->getStorage('rl_menu_link_experiment')->load($experimentId);
    if (!$entity instanceof MenuLinkExperiment) {
      return $this->error(sprintf('Menu link experiment "%s" not found.', $experimentId));
    }

    $rl_id = $entity->getRlExperimentId();
    $turns = $this->experimentManager->getTotalTurns($rl_id);

    if ($options['dry-run']) {
      return $this->yaml([
        'dry_run' => TRUE,
        'action' => 'delete',
        'experiment' => [
          'id' => $entity->id(),
          'menu_link_plugin_id' => $entity->getMenuLinkPluginId(),
          'langcode' => $entity->language()->getId(),
          'rl_experiment_id' => $rl_id,
          'analytics_turns' => $turns,
        ],
      ]);
    }

    $this->experimentManager->purgeExperiment($rl_id);
    $entity->delete();

    return $this->success(
      sprintf('Deleted menu link experiment "%s" and purged %d turns.', $experimentId, $turns),
    );
  }

  /**
   * Parse variants from --variants option.
   *
   * @param mixed $raw
   *   The raw option value (string or array).
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
