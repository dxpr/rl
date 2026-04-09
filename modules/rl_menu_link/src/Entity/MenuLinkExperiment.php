<?php

namespace Drupal\rl_menu_link\Entity;

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
 * Defines the Menu Link Experiment content entity.
 *
 * Content entity (not config) for the same scalability and multilingual
 * reasons as PageTitleExperiment: indexed lookups by (menu_link_plugin_id,
 * langcode), per-language Thompson Sampling scope, and Views-based admin
 * UI without the config-management cliff.
 *
 * @ContentEntityType(
 *   id = "rl_menu_link_experiment",
 *   label = @Translation("Menu link experiment"),
 *   label_collection = @Translation("Menu link experiments"),
 *   label_singular = @Translation("menu link experiment"),
 *   label_plural = @Translation("menu link experiments"),
 *   admin_permission = "administer rl menu link experiments",
 *   handlers = {
 *     "form" = {
 *       "default" = "Drupal\rl_menu_link\Form\MenuLinkExperimentForm",
 *       "add" = "Drupal\rl_menu_link\Form\MenuLinkExperimentForm",
 *       "edit" = "Drupal\rl_menu_link\Form\MenuLinkExperimentForm",
 *       "delete" = "Drupal\rl_menu_link\Form\MenuLinkExperimentDeleteForm",
 *     },
 *     "views_data" = "Drupal\views\EntityViewsData",
 *     "list_builder" = "Drupal\Core\Entity\EntityListBuilder",
 *     "access" = "Drupal\Core\Entity\EntityAccessControlHandler",
 *     "route_provider" = {
 *       "html" = "Drupal\Core\Entity\Routing\AdminHtmlRouteProvider",
 *     },
 *   },
 *   base_table = "rl_menu_link_experiment",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "label" = "label",
 *     "langcode" = "langcode",
 *     "published" = "enabled",
 *   },
 *   links = {
 *     "add-form" = "/admin/config/services/rl-menu-link/add",
 *     "edit-form" = "/admin/config/services/rl-menu-link/{rl_menu_link_experiment}/edit",
 *     "delete-form" = "/admin/config/services/rl-menu-link/{rl_menu_link_experiment}/delete",
 *   },
 * )
 */
class MenuLinkExperiment extends ContentEntityBase implements VariantExperimentInterface, EntityPublishedInterface {

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
      ->setDescription(t('A short name you will recognize later in reports. If left blank, the name of the menu link is used.'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -10,
      ])
      ->setDisplayConfigurable('form', TRUE);

    $fields['menu_link_plugin_id'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Menu link'))
      ->setDescription(t('The internal identifier of the menu link you want to test. The easiest way to set this is to open the menu link from <a href=":menu_admin">Structure &rsaquo; Menus</a> and use the "A/B test menu link title" tab on its edit form instead of this page.', [
        ':menu_admin' => '/admin/structure/menu',
      ]))
      ->setRequired(TRUE)
      ->setSetting('max_length', 255)
      ->setDisplayOptions('form', [
        'type' => 'string_textfield',
        'weight' => -5,
      ])
      ->setDisplayConfigurable('form', TRUE)
      ->addConstraint('NotBlank');

    $fields['variants_data'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Alternative titles (JSON)'))
      ->setDescription(t('JSON-encoded list of alternative menu link title strings.'))
      ->setRequired(TRUE);

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
    $this->set('menu_link_plugin_id', trim($this->getMenuLinkPluginId()));
  }

  /**
   * Get the menu link plugin ID.
   */
  public function getMenuLinkPluginId(): string {
    $value = $this->get('menu_link_plugin_id')->value;
    return $value !== NULL ? (string) $value : '';
  }

  /**
   * Set the menu link plugin ID.
   */
  public function setMenuLinkPluginId(string $plugin_id): static {
    $this->set('menu_link_plugin_id', trim($plugin_id));
    return $this;
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
   * Set variant labels.
   *
   * @param string[] $variants
   *   List of alternative labels to test against the original.
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
    return self::buildRlExperimentId($this->getMenuLinkPluginId(), $langcode);
  }

  /**
   * Build a deterministic RL experiment ID from a plugin ID and langcode.
   */
  public static function buildRlExperimentId(string $plugin_id, string $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED): string {
    $key = trim($plugin_id) . '|' . $langcode;
    return self::buildVariantExperimentId('rl_menu_link', $key);
  }

}
