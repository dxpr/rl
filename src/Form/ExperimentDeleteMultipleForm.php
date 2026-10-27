<?php

namespace Drupal\rl\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\rl\Service\ExperimentDeletion;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Confirms deletion of the current user's selected experiments.
 *
 * @see \Drupal\Core\Entity\Form\DeleteMultipleForm
 */
class ExperimentDeleteMultipleForm extends ConfirmFormBase {

  /**
   * The selected experiments.
   *
   * @var array
   */
  protected array $selection = [];

  /**
   * The private selection token.
   *
   * @var string
   */
  protected string $selectionToken;

  /**
   * Constructs a bulk deletion confirmation form.
   */
  public function __construct(
    protected PrivateTempStoreFactory $tempStoreFactory,
    protected ExperimentDeletion $experimentDeletion,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    // @phpstan-ignore new.static
    return new static(
      $container->get('tempstore.private'),
      $container->get('rl.experiment_deletion'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'rl_experiment_delete_multiple_confirm';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->formatPlural(count($this->selection), 'Are you sure you want to delete this experiment?', 'Are you sure you want to delete these @count experiments?');
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will permanently delete the selected experiments and all their data (turns, rewards, totals, snapshots). This action cannot be undone. Experiments auto-recreate on next render.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl(): Url {
    return Url::fromRoute('rl.reports.experiments', [], [
      'query' => array_intersect_key($this->getRequest()->query->all(), array_flip(['order', 'sort', 'page'])),
    ]);
  }

  /**
   * {@inheritdoc}
   *
   * @return array|\Symfony\Component\HttpFoundation\RedirectResponse
   *   The confirmation form, or a redirect when the selection has expired.
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?string $selection = NULL) {
    $this->selectionToken = $selection ?? '';
    $this->selection = $this->tempStoreFactory->get('rl_experiment_delete_multiple')->get($this->selectionToken) ?? [];
    if (!$this->selection) {
      return new RedirectResponse($this->getCancelUrl()->toString());
    }
    $items = [];
    foreach ($this->selection as $experiment) {
      $items[] = $this->t('@name (@id)', ['@name' => $experiment['name'], '@id' => $experiment['id']]);
    }
    $form['experiments'] = ['#theme' => 'item_list', '#items' => $items];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->currentUser()->hasPermission('administer rl experiments')) {
      throw new AccessDeniedHttpException();
    }
    $store = $this->tempStoreFactory->get('rl_experiment_delete_multiple');
    // Expired or already consumed confirmations must not delete anything.
    if ($store->get($this->selectionToken) !== $this->selection) {
      $form_state->setRedirectUrl($this->getCancelUrl());
      return;
    }
    try {
      $count = $this->experimentDeletion->deleteMultiple(array_column($this->selection, 'id'));
      $store->delete($this->selectionToken);
      $this->messenger()->addStatus($this->formatPlural($count, 'Deleted @count experiment.', 'Deleted @count experiments.'));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('An error occurred while deleting the experiments.'));
      $this->getLogger('rl')->error('Error deleting experiments: @message', ['@message' => $e->getMessage()]);
    }
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
