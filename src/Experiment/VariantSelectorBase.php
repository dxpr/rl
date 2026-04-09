<?php

namespace Drupal\rl\Experiment;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\rl\Registry\ExperimentRegistryInterface;
use Drupal\rl\Service\CacheManager;
use Drupal\rl\Service\ExperimentManagerInterface;

/**
 * Base class for "load experiment, score arms, return winner" selectors.
 *
 * Consumer modules build a thin selector that subclasses this base, declares
 * which entity type to load and which property to match on, and that's it.
 * The Thompson scoring + per-request caching + page cache override pattern
 * is identical across consumers and lives here.
 *
 * Multilingual lookup model: language-specific match first, then fall back
 * to LANGCODE_NOT_SPECIFIED ("all languages"). Mirrors the Redirect module.
 */
abstract class VariantSelectorBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * The RL experiment manager.
   *
   * @var \Drupal\rl\Service\ExperimentManagerInterface
   */
  protected ExperimentManagerInterface $experimentManager;

  /**
   * The RL cache manager.
   *
   * @var \Drupal\rl\Service\CacheManager
   */
  protected CacheManager $cacheManager;

  /**
   * The RL experiment registry.
   *
   * @var \Drupal\rl\Registry\ExperimentRegistryInterface
   */
  protected ExperimentRegistryInterface $experimentRegistry;

  /**
   * Per-request cache of selection results, keyed by "target|langcode".
   *
   * Value is either a result array (with experiment_id, arm_id, text) or
   * FALSE if a previous lookup found no match.
   *
   * @var array<string, array|false>
   */
  protected array $resultCache = [];

  /**
   * Per-request set of RL experiment IDs already verified as registered.
   *
   * Avoids hitting the registry twice for the same experiment within a
   * single request.
   *
   * @var array<string, true>
   */
  protected array $registrationVerified = [];

  /**
   * Constructs a VariantSelectorBase.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ExperimentManagerInterface $experiment_manager,
    CacheManager $cache_manager,
    ExperimentRegistryInterface $experiment_registry,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->experimentManager = $experiment_manager;
    $this->cacheManager = $cache_manager;
    $this->experimentRegistry = $experiment_registry;
  }

  /**
   * The owner module name to use when (re-)registering an experiment.
   *
   * Used by self-healing registration in selectForTarget(): if the entity
   * exists but the registry row was lost (e.g., the user purged analytics
   * via Drush without deleting the entity), we re-register on the next
   * page load so the rl.php tracking endpoint accepts the experiment.
   */
  abstract protected function ownerModule(): string;

  /**
   * Page cache TTL applied while an experiment is active on the page.
   *
   * Subclasses may override to expose a different TTL.
   */
  protected function cacheTtl(): int {
    return 60;
  }

  /**
   * The entity type ID of the experiment content entity to load.
   */
  abstract protected function entityTypeId(): string;

  /**
   * The fully-qualified class name of the experiment entity.
   *
   * Used for narrow type assertions on loaded entities.
   */
  abstract protected function entityClass(): string;

  /**
   * The property name on the experiment entity that holds the lookup target.
   *
   * For rl_page_title this is `path`; for rl_menu_link it is
   * `menu_link_plugin_id`.
   */
  abstract protected function targetProperty(): string;

  /**
   * Look up the variant selection for a given target and language.
   *
   * Tries the language-specific match first, then falls back to
   * LANGCODE_NOT_SPECIFIED ("all languages"). Returns NULL if neither
   * lookup finds an enabled experiment.
   *
   * @param string $target
   *   The target value (path, plugin ID, etc.) to look up.
   * @param string $langcode
   *   The current language code.
   *
   * @return array|null
   *   Array with keys experiment_id, arm_id, text (NULL means original);
   *   or NULL if no enabled experiment matches.
   */
  public function selectForTarget(string $target, string $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED): ?array {
    $cache_key = $target . '|' . $langcode;
    if (isset($this->resultCache[$cache_key])) {
      $cached = $this->resultCache[$cache_key];
      return $cached === FALSE ? NULL : $cached;
    }

    // Language-specific lookup first.
    $experiment = $this->loadExperimentByTarget($target, $langcode);
    // Fall back to "all languages" experiment if no language-specific match.
    if ($experiment === NULL && $langcode !== LanguageInterface::LANGCODE_NOT_SPECIFIED) {
      $experiment = $this->loadExperimentByTarget($target, LanguageInterface::LANGCODE_NOT_SPECIFIED);
    }
    if ($experiment === NULL) {
      $this->resultCache[$cache_key] = FALSE;
      return NULL;
    }

    $rl_experiment_id = $experiment->getRlExperimentId();

    // Self-healing registration: the entity is the source of truth for
    // whether an experiment exists, but the rl.php tracking endpoint
    // checks the registry table. If a user purged analytics via Drush
    // without deleting the entity, the registry row is gone but the
    // entity remains, and tracking would silently no-op. Re-registering
    // here on the next page load brings the registry back in sync.
    // Idempotent: register() is an upsert, and we cache the verification
    // for the rest of the request to avoid extra DB writes.
    if (!isset($this->registrationVerified[$rl_experiment_id])) {
      if (!$this->experimentRegistry->isRegistered($rl_experiment_id)) {
        $this->experimentRegistry->register(
          $rl_experiment_id,
          $this->ownerModule(),
          (string) $experiment->label(),
        );
      }
      $this->registrationVerified[$rl_experiment_id] = TRUE;
    }

    $arm_ids = $experiment->getArmIds();
    $scores = $this->experimentManager->getThompsonScores($rl_experiment_id, NULL, $arm_ids);
    arsort($scores);
    $best_arm = (string) key($scores);

    // Shorten cache so variants rotate.
    $this->cacheManager->overridePageCacheIfShorter($this->cacheTtl());

    $result = [
      'experiment_id' => $rl_experiment_id,
      'arm_id' => $best_arm,
      'text' => $experiment->getArmText($best_arm),
    ];
    $this->resultCache[$cache_key] = $result;
    return $result;
  }

  /**
   * Load an enabled experiment by target value and langcode.
   *
   * @return \Drupal\rl\Experiment\VariantExperimentInterface|null
   *   The matching experiment, or NULL if none is enabled for this target.
   */
  protected function loadExperimentByTarget(string $target, string $langcode): ?VariantExperimentInterface {
    $storage = $this->entityTypeManager->getStorage($this->entityTypeId());
    $matches = $storage->loadByProperties([
      $this->targetProperty() => $target,
      'langcode' => $langcode,
      'enabled' => TRUE,
    ]);
    if (!$matches) {
      return NULL;
    }
    $entity = reset($matches);
    $class = $this->entityClass();
    if (!($entity instanceof VariantExperimentInterface) || !($entity instanceof $class)) {
      return NULL;
    }
    return $entity;
  }

}
