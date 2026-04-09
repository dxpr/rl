<?php

namespace Drupal\content_optimizer\Entity;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Entity\EntityPublishedTrait;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Defines the Content Optimizer Experiment entity.
 *
 * @ContentEntityType(
 *   id = "content_optimizer_experiment",
 *   label = @Translation("Content Optimizer Experiment"),
 *   label_collection = @Translation("Content Optimizer Experiments"),
 *   label_singular = @Translation("experiment"),
 *   label_plural = @Translation("experiments"),
 *   handlers = {
 *     "form" = {
 *       "default" = "Drupal\content_optimizer\Form\ExperimentForm",
 *       "add" = "Drupal\content_optimizer\Form\ExperimentForm",
 *       "edit" = "Drupal\content_optimizer\Form\ExperimentForm",
 *       "delete" = "Drupal\content_optimizer\Form\ExperimentDeleteForm",
 *     },
 *     "list_builder" = "Drupal\content_optimizer\ExperimentListBuilder",
 *     "access" = "Drupal\content_optimizer\ExperimentAccessControlHandler",
 *   },
 *   base_table = "content_optimizer_experiment",
 *   admin_permission = "administer content optimizer",
 *   entity_keys = {
 *     "id" = "id",
 *     "uuid" = "uuid",
 *     "langcode" = "langcode",
 *     "published" = "enabled",
 *   },
 *   links = {
 *     "add-form" = "/admin/config/content/content-optimizer/add",
 *     "edit-form" = "/admin/config/content/content-optimizer/{content_optimizer_experiment}/edit",
 *     "delete-form" = "/admin/config/content/content-optimizer/{content_optimizer_experiment}/delete",
 *     "collection" = "/admin/config/content/content-optimizer",
 *   },
 * )
 */
class Experiment extends ContentEntityBase {

  use EntityPublishedTrait;

  /**
   * {@inheritdoc}
   */
  public static function baseFieldDefinitions(EntityTypeInterface $entity_type) {
    $fields = parent::baseFieldDefinitions($entity_type);
    $fields += static::publishedBaseFieldDefinitions($entity_type);

    $fields['target_entity_type'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Target entity type'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->setDefaultValue('node');

    $fields['target_entity_id'] = BaseFieldDefinition::create('integer')
      ->setLabel(t('Target entity ID'))
      ->setSetting('unsigned', TRUE);

    $fields['target_field'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Target field'))
      ->setRequired(TRUE)
      ->setSetting('max_length', 64)
      ->setDefaultValue('title');

    $fields['target_path'] = BaseFieldDefinition::create('string')
      ->setLabel(t('Target path'))
      ->setDescription(t('For non-entity experiments, the page path.'))
      ->setSetting('max_length', 2048);

    $fields['variants_data'] = BaseFieldDefinition::create('string_long')
      ->setLabel(t('Variants'))
      ->setDescription(t('JSON-encoded variant texts.'));

    $fields['uid'] = BaseFieldDefinition::create('entity_reference')
      ->setLabel(t('Author'))
      ->setSetting('target_type', 'user')
      ->setDefaultValueCallback(static::class . '::getDefaultEntityOwner');

    $fields['created'] = BaseFieldDefinition::create('created')
      ->setLabel(t('Created'));

    return $fields;
  }

  /**
   * Default value callback for the uid field.
   */
  public static function getDefaultEntityOwner() {
    return [\Drupal::currentUser()->id()];
  }

  /**
   * Get the RL experiment ID for this experiment.
   *
   * @return string
   *   Deterministic experiment ID.
   */
  public function getRlExperimentId(): string {
    $entity_id = $this->get('target_entity_id')->value;
    if ($entity_id) {
      return 'content_optimizer-' . $this->get('target_entity_type')->value . '-' . $entity_id . '-' . $this->get('target_field')->value;
    }
    // Path-based experiment.
    $path = $this->get('target_path')->value;
    $path_safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', trim($path, '/'));
    return 'content_optimizer-path-' . $path_safe . '-' . $this->get('target_field')->value;
  }

  /**
   * Get the variant texts.
   *
   * @return string[]
   *   Array of variant text strings.
   */
  public function getVariants(): array {
    $data = $this->get('variants_data')->value;
    if (empty($data)) {
      return [];
    }
    $decoded = json_decode($data, TRUE);
    return is_array($decoded) ? $decoded : [];
  }

  /**
   * Set the variant texts.
   *
   * @param string[] $variants
   *   Array of variant text strings.
   *
   * @return $this
   */
  public function setVariants(array $variants): static {
    $this->set('variants_data', json_encode(array_values($variants)));
    return $this;
  }

  /**
   * Get a human-readable label for this experiment.
   *
   * @return string
   *   Label like "node 42 title" or "path /about title".
   */
  public function getExperimentLabel(): string {
    $entity_id = $this->get('target_entity_id')->value;
    $field = $this->get('target_field')->value;
    if ($entity_id) {
      $type = $this->get('target_entity_type')->value;
      $entity = \Drupal::entityTypeManager()->getStorage($type)->load($entity_id);
      $entity_label = $entity ? $entity->label() : "$type $entity_id";
      return "$entity_label ($field)";
    }
    $path = $this->get('target_path')->value;
    return "$path ($field)";
  }

  /**
   * Build the arm IDs for this experiment.
   *
   * @return string[]
   *   Array of arm IDs: v0 for original, v1-vN for variants.
   */
  public function getArmIds(): array {
    $arm_ids = ['v0'];
    $variants = $this->getVariants();
    for ($i = 0; $i < count($variants); $i++) {
      $arm_ids[] = 'v' . ($i + 1);
    }
    return $arm_ids;
  }

  /**
   * Get the text for a specific arm ID.
   *
   * @param string $arm_id
   *   Arm ID like "v0", "v1", etc.
   *
   * @return string|null
   *   The variant text, or NULL for v0 (use original).
   */
  public function getArmText(string $arm_id): ?string {
    if ($arm_id === 'v0') {
      return NULL;
    }
    $index = (int) substr($arm_id, 1) - 1;
    $variants = $this->getVariants();
    return $variants[$index] ?? NULL;
  }

}
