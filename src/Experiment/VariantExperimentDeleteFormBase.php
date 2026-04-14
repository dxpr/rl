<?php

namespace Drupal\rl\Experiment;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Entity\ContentEntityDeleteForm;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rl\Service\ExperimentManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base delete form that purges RL analytics before removing the entity.
 *
 * Works for any entity that implements VariantExperimentInterface; subclasses
 * exist only as the concrete form class referenced in the entity annotation.
 *
 * Extends ContentEntityDeleteForm (not EntityDeleteForm) because the variant
 * experiments are content entities. EntityDeleteForm uses EntityForm's
 * copyFormValuesToEntity() which iterates over all form values including
 * the "submit" button and tries to set them as entity fields, which fails
 * with "Field submit is unknown" on content entities.
 */
abstract class VariantExperimentDeleteFormBase extends ContentEntityDeleteForm {

  /**
   * The RL experiment manager.
   *
   * @var \Drupal\rl\Service\ExperimentManagerInterface
   */
  protected ExperimentManagerInterface $experimentManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->experimentManager = $container->get('rl.experiment_manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    // Surface the real stakes: if the experiment has collected any data,
    // show the numbers so the user knows exactly what they are throwing
    // away. A generic "permanently delete" warning does not land the same
    // way as "7,413 impressions and 214 conversions will be deleted".
    $entity = $this->getEntity();
    if ($entity instanceof VariantExperimentInterface) {
      try {
        $rl_id = $entity->getRlExperimentId();
        $turns = (int) $this->experimentManager->getTotalTurns($rl_id);
        $rewards = 0;
        foreach ($this->experimentManager->getAllArmsData($rl_id) as $arm) {
          $rewards += (int) $arm->rewards;
        }
        if ($turns > 0 || $rewards > 0) {
          return $this->t('@turns impressions and @rewards conversions will be permanently deleted along with all historical snapshots. This action cannot be undone.', [
            '@turns' => number_format($turns),
            '@rewards' => number_format($rewards),
          ]);
        }
      }
      catch (\Exception $e) {
        // Fall back to the generic warning below.
      }
    }

    return $this->t('This experiment has not collected any data yet, so nothing will be lost. This action cannot be undone.');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $entity = $this->getEntity();

    // Capture cache tags to invalidate BEFORE the entity is deleted; the
    // concrete subclass needs the entity's current field values (path,
    // plugin_id, etc.) to build the tag list.
    $cache_tags = ($entity instanceof EntityInterface)
      ? $this->getCacheTagsToInvalidate($entity)
      : [];

    // Purge analytics first. If purging fails, the config entity is left
    // intact as a recovery anchor and the deletion is aborted.
    if ($entity instanceof VariantExperimentInterface) {
      $this->experimentManager->purgeExperiment($entity->getRlExperimentId());
    }

    parent::submitForm($form, $form_state);

    // Invalidate render-cache tags last, after the entity is gone. This
    // closes the cache-as-you-invalidate loop for the delete path: the
    // save path already invalidates the same tags when an experiment is
    // created or updated; without this call, cached menus and pages keep
    // serving rendered variants from a now-deleted experiment until some
    // unrelated cache clear happens.
    if ($cache_tags) {
      Cache::invalidateTags($cache_tags);
    }
  }

  /**
   * Returns the render-cache tags to invalidate when this entity is deleted.
   *
   * Subclasses supply the tags that the module's preprocess / view-alter
   * hooks attach at render time. Invalidating them on delete prevents
   * cached menus or pages from continuing to serve a variant that belongs
   * to a now-deleted experiment.
   *
   * Called before the entity is deleted, so the entity is still loaded
   * and its field values are readable. Default implementation returns an
   * empty array so downstream modules that have not opted in are
   * unaffected.
   *
   * @param \Drupal\Core\Entity\EntityInterface $entity
   *   The entity about to be deleted.
   *
   * @return string[]
   *   List of cache tag strings to invalidate after successful deletion.
   */
  protected function getCacheTagsToInvalidate(EntityInterface $entity): array {
    return [];
  }

}
