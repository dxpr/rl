<?php

namespace Drupal\rl\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\rl\Storage\SnapshotStorageInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Confirmation form for disabling event log and deleting all snapshots.
 */
class DisableEventLogConfirmForm extends ConfirmFormBase {

  /**
   * The snapshot storage service.
   *
   * @var \Drupal\rl\Storage\SnapshotStorageInterface
   */
  protected SnapshotStorageInterface $snapshotStorage;

  /**
   * Constructs a DisableEventLogConfirmForm object.
   *
   * @param \Drupal\rl\Storage\SnapshotStorageInterface $snapshot_storage
   *   The snapshot storage service.
   */
  public function __construct(SnapshotStorageInterface $snapshot_storage) {
    $this->snapshotStorage = $snapshot_storage;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    // @phpstan-ignore new.static
    return new static(
      $container->get('rl.snapshot_storage')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'rl_disable_event_log_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to disable event logging?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    $count = $this->snapshotStorage->getCount();
    if ($count > 0) {
      return $this->t('This will permanently delete all @count event log entries. The historical visualization charts will no longer be available for any experiments. This action cannot be undone.', [
        '@count' => number_format($count),
      ]);
    }
    return $this->t('Event logging will be disabled. No log entries exist to delete.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Disable and delete all logs');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return new Url('rl.settings');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    // Delete all snapshots.
    $deleted = $this->snapshotStorage->deleteAll();

    // Disable event logging.
    $this->configFactory()->getEditable('rl.settings')
      ->set('enable_event_log', FALSE)
      ->save();

    if ($deleted > 0) {
      $this->messenger()->addStatus($this->t('Event logging has been disabled and @count log entries have been deleted.', [
        '@count' => number_format($deleted),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('Event logging has been disabled.'));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
