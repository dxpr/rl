<?php

namespace Drupal\rl_page_title\Entity;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityPublishedInterface;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;
use Drupal\Core\Language\LanguageInterface;
use Drupal\rl\Experiment\VariantArmsTrait;
use Drupal\rl\Experiment\VariantExperimentInterface;

/**
 * Defines the Page Title Experiment content entity.
 *
 * Stored as a content entity (not config) so the same module can scale to
 * tens of thousands of experiments per site without the config-management
 * pollution and O(N) lookup penalties of config entities.
 *
 * Scale story mirrors the Redirect module: a computed `lookup_hash` base
 * field holds sha256(normalized_path | langcode) and is backed by a UNIQUE
 * index, giving O(1) duplicate detection at save time and O(1) runtime
 * selection regardless of how many experiments exist. A secondary index
 * on (path, langcode) keeps target-only queries (hook_entity_predelete
 * cleanup, Views filters) indexed as well. See
 * \Drupal\rl_page_title\PageTitleExperimentStorageSchema.
 *
 * Multilingual model: each (path, langcode) is its own row, mirroring
 * the Redirect module. The "all languages" fallback is langcode
 * LANGCODE_NOT_SPECIFIED. We do not use Drupal's translation framework;
 * each language gets its own row with its own variants list and its own
 * Thompson Sampling state.
 *
 * @ContentEntityType(
 *   id = "rl_page_title_experiment",
 *   label = @Translation("Page title experiment"),
 *   label_collection = @Translation("Page title experiments"),
 *   label_singular = @Translation("page title experiment"),
 *   label_plural = @Translation("page title experiments"),
 *   admin_permission = "administer rl page title experiments",
 *   handlers = {
 *     "storage_schema" = "Drupal\rl_page_title\PageTitleExperimentStorageSchema",
 *     "form" = {
 *       "default" = "Drupal\rl_page_title\Form\PageTitleExperimentForm",
 *       "add" = "Drupal\rl_page_title\Form\PageTitleExperimentForm",
 *       "edit" = "Drupal\rl_page_title\Form\PageTitleExperimentForm",
 *       "delete" = "Drupal\rl_page_title\Form\PageTitleExperimentDeleteForm",
 *     },
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "rl_page_title_experiment",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "langcode" = "langcode",
 *     "published" = "enabled",
 *   },
 *   links = {
 *     "add-form" = "/admin/config/services/rl-page-title/add",
 *     "edit-form" = "/admin/config/services/rl-page-title/{rl_page_title_experiment}/edit",
 *     "delete-form" = "/admin/config/services/rl-page-title/{rl_page_title_experiment}/delete",
 *   },
 * )
 */
class PageTitleExperiment extends ContentEntityBase implements VariantExperimentInterface, EntityPublishedInterface {

  use EntityPublishedTrait;
  use VariantArmsTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::publishedBaseFieldDefinitions($entity_type);

    $fields['label'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Experiment name'))
      ->setDescription(t('A short name you will recognize later in reports. If left blank, the name of the page being tested is used.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['path'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Page URL or path'))
      ->setDescription(t('The page you want to test. Enter the URL or path, for example <code>/blog/my-article</code> or <code>/node/42</code>. URL aliases are resolved automatically.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 2048)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->addConstraint('NotBlank');

    $fields['variants_data'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Variant titles (JSON)'))
      ->setDescription(t('JSON-encoded list of variant title strings.'))
      ->setRequired(TRUE);

    // Computed hash of (normalized path, langcode). Populated in preSave()
    // and backed by a UNIQUE index via PageTitleExperimentStorageSchema.
    // This is the column the runtime selector and form-level duplicate
    // detection query against, giving O(1) lookup regardless of row count.
    $fields['lookup_hash'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Lookup hash'))
      ->setDescription(t('Computed sha256(path|langcode). Uniquely identifies an experiment for indexed runtime lookup.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->setReadOnly(TRUE);

    // The 'enabled' field comes from publishedBaseFieldDefinitions(); we
    // narrow the type so the chained mutators are PHPStan-clean.
    $enabled = $fields['enabled'];
    assert($enabled instanceof BaseFieldDefinition);
    $enabled
      ->setLabel(t('Serve variants to visitors'))
      ->setDescription(t('Uncheck to pause the experiment without losing any collected data.'))
      ->setDefaultValue(TRUE)
      ->setDisplayOptions('form', [
        'type' => 'boolean_checkbox',
        'weight' => 5,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    $fields['changed'] = BaseFieldDefinition::create('changed')
      ->setLabel(t('Changed'));

    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);
    // Always normalize the path before saving so runtime lookups match.
    $normalized = self::normalizePath($this->getPath());
    $this->set('path', $normalized);
    // Recompute the lookup hash from the normalized path and current
    // langcode. This is what backs the UNIQUE index and the runtime
    // selector's indexed lookup.
    $langcode = $this->language()->getId() ?: LanguageInterface::LANGCODE_NOT_SPECIFIED;
    $this->set('lookup_hash', self::computeLookupHash($normalized, $langcode));
  }

  /**
   * Get the internal path being tested.
   */
  public function getPath(): string {
    $value = $this->get('path')->value;
    return $value !== NULL ? (string) $value : '';
  }

  /**
   * Set the internal path. Normalizes to leading slash, no trailing slash.
   */
  public function setPath(string $path): static {
    $this->set('path', self::normalizePath($path));
    return $this;
  }

  /**
   * Normalize a path to the canonical form used for storage and matching.
   *
   * Ensures a leading slash and removes any trailing slash so that runtime
   * lookup matches save-time storage exactly.
   */
  public static function normalizePath(string $path): string {
    $path = trim($path);
    if ($path === '' || $path === '/') {
      return '/';
    }
    if ($path[0] !== '/') {
      $path = '/' . $path;
    }
    return rtrim($path, '/');
  }

  /**
   * {@inheritdoc}
   */
  public function getVariants(): array {
    $value = $this->get('variants_data')->value;
    if ($value === NULL || $value === '') {
      return [];
    }
    $decoded = json_decode((string) $value, TRUE);
    return is_array($decoded) ? array_values($decoded) : [];
  }

  /**
   * Set the variant titles.
   *
   * @param string[] $variants
   *   List of alternative titles to test against the original.
   *
   * @return $this
   */
  public function setVariants(array $variants): static {
    $this->set('variants_data', json_encode(array_values($variants)));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getRlExperimentId(): string {
    $langcode = $this->language()->getId() ?: LanguageInterface::LANGCODE_NOT_SPECIFIED;
    return self::buildRlExperimentId($this->getPath(), $langcode);
  }

  /**
   * Build a deterministic RL experiment ID from a path and langcode.
   *
   * Including the langcode in the hash means each language scopes its
   * own Thompson Sampling state: an English experiment for /blog and a
   * Spanish experiment for /blog get separate analytics rows.
   */
  public static function buildRlExperimentId(string $path, string $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED): string {
    $key = self::normalizePath($path) . '|' . $langcode;
    return self::buildVariantExperimentId('rl_page_title', $key);
  }

  /**
   * Compute the deterministic lookup hash for a (path, langcode) pair.
   *
   * This is the column backed by the UNIQUE index and is used for indexed
   * duplicate detection (form validation, Drush create) and indexed runtime
   * selection (TitleVariantSelector). Unlike the RL experiment ID, this
   * hash is only used internally by the entity's own lookup path.
   *
   * Mirrors the Redirect module's Redirect::generateHash() pattern.
   */
  public static function computeLookupHash(string $path, string $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED): string {
    return Crypt::hashBase64(self::normalizePath($path) . '|' . $langcode);
  }

}
