<?php

namespace Drupal\rl\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\rl\Service\ExperimentManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for RL experiment operations.
 */
class ExperimentController extends ControllerBase {

  /**
   * The experiment manager service.
   *
   * @var \Drupal\rl\Service\ExperimentManagerInterface
   */
  protected $experimentManager;

  /**
   * Constructs a new ExperimentController.
   *
   * @param \Drupal\rl\Service\ExperimentManagerInterface $experiment_manager
   *   The experiment manager service.
   */
  public function __construct(ExperimentManagerInterface $experiment_manager) {
    $this->experimentManager = $experiment_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('rl.experiment_manager')
    );
  }

  /**
   * Records a turn for a specific arm.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string $experiment_uuid
   *   The experiment UUID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response indicating success or failure.
   */
  public function recordTurn(Request $request, $experiment_uuid) {
    $data = json_decode($request->getContent(), TRUE);
    $arm_id = $data['arm_id'] ?? NULL;

    if (empty($arm_id)) {
      return new JsonResponse(['error' => 'Missing arm_id'], 400);
    }

    try {
      $this->experimentManager->recordTurn($experiment_uuid, $arm_id);
      return new JsonResponse(['success' => TRUE]);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => 'Failed to record turn'], 500);
    }
  }

  /**
   * Records turns for multiple arms.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string $experiment_uuid
   *   The experiment UUID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response indicating success or failure.
   */
  public function recordTurns(Request $request, $experiment_uuid) {
    $data = json_decode($request->getContent(), TRUE);
    $arm_ids = $data['arm_ids'] ?? [];

    if (empty($arm_ids) || !is_array($arm_ids)) {
      return new JsonResponse(['error' => 'Missing or invalid arm_ids'], 400);
    }

    try {
      $this->experimentManager->recordTurns($experiment_uuid, $arm_ids);
      return new JsonResponse(['success' => TRUE]);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => 'Failed to record turns'], 500);
    }
  }

  /**
   * Records a reward for a specific arm.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string $experiment_uuid
   *   The experiment UUID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response indicating success or failure.
   */
  public function recordReward(Request $request, $experiment_uuid) {
    $data = json_decode($request->getContent(), TRUE);
    $arm_id = $data['arm_id'] ?? NULL;

    if (empty($arm_id)) {
      return new JsonResponse(['error' => 'Missing arm_id'], 400);
    }

    try {
      $this->experimentManager->recordReward($experiment_uuid, $arm_id);
      return new JsonResponse(['success' => TRUE]);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => 'Failed to record reward'], 500);
    }
  }

  /**
   * Gets UCB1 scores for all arms in an experiment.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request.
   * @param string $experiment_uuid
   *   The experiment UUID.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response with the UCB1 scores.
   */
  public function getUCB1Scores(Request $request, $experiment_uuid) {
    $alpha = $request->query->get('alpha', 2.0);

    try {
      $scores = $this->experimentManager->getUCB1Scores($experiment_uuid, (float) $alpha);
      return new JsonResponse(['scores' => $scores]);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => 'Failed to get scores'], 500);
    }
  }

}