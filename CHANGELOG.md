# Changelog

## Unreleased

### Added

- New `Drupal\rl\Experiment\VariantArmsTrait` providing reusable arm-id helpers
  (`getArmIds`, `getArmText`, `buildVariantExperimentId`) for experiments that
  follow the "v0 = original, v1..vN = stored variants" convention.
- New `Drupal\rl\Experiment\VariantParser` static helper for parsing textarea
  variant input into normalized lists.
- New `Drupal\rl\Experiment\VariantExperimentInterface` extending
  `ContentEntityInterface`. Variant-style experiment entities implement this
  to plug into the shared selector / decorator / delete-form base classes.
- New `Drupal\rl\Experiment\VariantSelectorBase`,
  `VariantExperimentDecoratorBase`, and `VariantExperimentDeleteFormBase` for
  consumer modules to extend.
- New submodule `rl_page_title` for A/B testing page titles on any page (nodes,
  Views displays, custom controllers, path-based). **Multilingual: per-language
  experiments scoped via the `langcode` entity key, with an "all languages"
  fallback. Each language has its own Thompson Sampling state.**
- New submodule `rl_menu_link` for A/B testing menu link labels (works for both
  `menu_link_content` entities and YAML-defined links). **Multilingual: same
  per-language scoping as rl_page_title.**

### Architecture

- Both new variant submodules use **content entities**, not config entities.
  This is a deliberate choice to scale to tens of thousands of experiments
  per site without the config-management cliff and the O(N) lookup penalty
  of config entities. Lookups are indexed; admin lists use Views; multilingual
  is first-class via the `langcode` entity key. Mirrors the Redirect module's
  storage approach.
- Both variant entities carry a computed `lookup_hash` base field
  (`sha256(normalized_target | langcode)`, stored via `Crypt::hashBase64()`)
  backed by a custom `SqlContentEntityStorageSchema` subclass that declares
  a UNIQUE index on the hash plus a secondary composite index on
  `(target, langcode)`. This is modelled directly on the Redirect module's
  `Redirect::hash` field and `RedirectStorageSchema` and gives:
  - O(1) indexed runtime selector lookups (a single `IN` query resolves
    both the language-specific and "all languages" fallback candidates),
  - O(1) indexed duplicate detection at save time (forms, Drush CLI, inline
    vertical-tab submit handlers all query by `lookup_hash`),
  - DB-level uniqueness as a second line of defence against concurrent
    saves racing past the form-level check.
  Target-only queries (`hook_entity_predelete` cleanup, list filters) use
  the secondary composite index.

### Changed

- **BC break (minor):** `ExperimentManagerInterface` now declares three new
  methods:
  - `purgeExperiment(string $experiment_id)` - removes turns, rewards,
    totals, snapshots, and registry entry for an experiment in a single
    transaction.
  - `getTotalTurnsMultiple(array $experiment_ids): array` - batched lookup
    of total turns for many experiments in one query, used by list builders
    to avoid N+1 query patterns.
  - `getAllArmsDataMultiple(array $experiment_ids): array` - batched lookup
    of arm data for many experiments in one query, paired with
    `getTotalTurnsMultiple()`.

  The same three methods are also added to `ExperimentDataStorageInterface`
  (the lower-level storage contract).

  Any downstream consumer that directly implements either interface (rather
  than extending the concrete classes) will need to add these methods to
  satisfy the contract. There are no known external implementations of
  either interface at the time of this change.

  Mitigation for downstream maintainers: copy the implementations from
  `ExperimentManager` and `ExperimentDataStorage`. The batch methods are
  thin `IN`-clause wrappers around the existing single-row queries; the
  purge method uses transactional deletes across `rl_arm_data`,
  `rl_experiment_totals`, `rl_arm_snapshots`, and `rl_experiment_registry`.
