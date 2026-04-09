# Changelog

## Unreleased

### Added

- New `Drupal\rl\Experiment\VariantArmsTrait` providing reusable arm-id helpers
  (`getArmIds`, `getArmText`, `buildVariantExperimentId`) for experiments that
  follow the "v0 = original, v1..vN = stored variants" convention.
- New `Drupal\rl\Experiment\VariantParser` static helper for parsing textarea
  variant input into normalized lists.
- New submodule `rl_page_title` for A/B testing page titles on any page (nodes,
  Views displays, custom controllers, path-based).
- New submodule `rl_menu_link` for A/B testing menu link labels (works for both
  `menu_link_content` entities and YAML-defined links).

### Changed

- **BC break (minor):** `ExperimentManagerInterface` now declares a new method
  `purgeExperiment(string $experiment_id)`. Any downstream consumer that
  directly implements this interface (rather than extending the concrete
  `ExperimentManager` class) will need to add this method to satisfy the
  contract. The method removes turns, rewards, totals, snapshots, and the
  registry entry for an experiment in a single transaction. There are no
  known external implementations of `ExperimentManagerInterface` at the time
  of this change.

  Mitigation for downstream maintainers: copy the implementation from
  `ExperimentManager::purgeExperiment()`, which uses transactional deletes
  across `rl_arm_data`, `rl_experiment_totals`, `rl_arm_snapshots`, and
  `rl_experiment_registry`. The method is straightforward to implement.
