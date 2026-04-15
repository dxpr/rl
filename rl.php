<?php

/**
 * @file
 * Handles RL experiment tracking via AJAX with minimal bootstrap.
 *
 * Following the statistics.php architecture for optimal performance.
 * Updated for Drupal 10/11 compatibility.
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

$action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$experiment_id = filter_input(INPUT_POST, 'experiment_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$arm_id = filter_input(INPUT_POST, 'arm_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

// Ping action is read-only and doesn't require experiment_id.
if ($action === 'ping') {
  http_response_code(200);
  exit('pong');
}

// Decide action: a batch Thompson Sampling lookup that resolves
// experiment_ids to winning arms. Generic (module-agnostic) — the
// caller passes experiment_ids it already knows about and the shape
// of each experiment. Used by rl integrations that want to avoid the
// per-request overhead of a full Drupal route boot for decision
// fetches. Schema:
//   experiment_ids  comma-separated list of pre-registered IDs
//   arm_counts      comma-separated parallel list of integers
// Response:
//   {"decisions":{"<id>":{"armId":"vN"}, ...}}
// Unknown / unregistered / zero-arm experiments are returned as NULL
// so the caller can fall back to the first arm.
if ($action === 'decide') {
  $ids_raw = filter_input(INPUT_POST, 'experiment_ids', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
  $counts_raw = filter_input(INPUT_POST, 'arm_counts', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
  if (!$ids_raw || !$counts_raw) {
    http_response_code(400);
    header('Content-Type: application/json');
    exit('{"decisions":{}}');
  }
  $ids = array_map('trim', explode(',', $ids_raw));
  $counts = array_map('intval', explode(',', $counts_raw));
  if (count($ids) !== count($counts)) {
    http_response_code(400);
    header('Content-Type: application/json');
    exit('{"decisions":{}}');
  }
  $pairs = [];
  foreach ($ids as $i => $eid) {
    if ($eid === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $eid)) {
      continue;
    }
    $count = $counts[$i] ?? 0;
    if ($count < 2) {
      continue;
    }
    $pairs[$eid] = $count;
  }
  // Fall through to Drupal kernel bootstrap below, then branch on
  // $action === 'decide' after the container is available.
}
elseif (!$action || !$experiment_id || !in_array($action, ['turn', 'turns', 'reward'])) {
  http_response_code(400);
  exit('Invalid request parameters');
}
elseif (!preg_match('/^[a-zA-Z0-9_-]+$/', $experiment_id)) {
  // Validate experiment ID format (alphanumeric, hyphens, underscores).
  http_response_code(400);
  exit('Invalid experiment_id format');
}

try {
  $levels_up = '../../../';

  chdir($levels_up);
  $drupal_root = getcwd();
  $autoload_path = $drupal_root . '/../vendor/autoload.php';

  if (!file_exists($autoload_path)) {
    $script_filename = $_SERVER['SCRIPT_FILENAME'] ?? '';
    if (!preg_match('/^[a-zA-Z0-9\/_.-]+$/', $script_filename)) {
      http_response_code(500);
      exit('Invalid script filename');
    }

    $drupal_root = dirname(dirname(dirname(dirname($script_filename))));
    $autoload_path = $drupal_root . '/../vendor/autoload.php';

    if (!file_exists($autoload_path)) {
      http_response_code(500);
      exit('Drupal autoload.php not found');
    }
  }

  $autoloader = require_once $autoload_path;

  $request = Request::createFromGlobals();
  $kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
  $kernel->boot();
  $container = $kernel->getContainer();

  $registry = $container->get('rl.experiment_registry');

  // Decide action: batch Thompson Sampling lookup, returns JSON.
  // Skips the single-experiment registration check above since this
  // action accepts a list and per-id registration is verified inline.
  if ($action === 'decide') {
    $manager = $container->has('rl.experiment_manager')
      ? $container->get('rl.experiment_manager')
      : NULL;
    if ($manager === NULL) {
      http_response_code(503);
      header('Content-Type: application/json');
      exit('{"decisions":{},"error":"rl.experiment_manager not available"}');
    }
    $decisions = new stdClass();
    foreach ($pairs as $eid => $arm_count) {
      if (!$registry->isRegistered($eid)) {
        // Unknown experiment — caller will fall back to arm 0.
        continue;
      }
      $arm_ids = [];
      for ($i = 0; $i < $arm_count; $i++) {
        $arm_ids[] = 'v' . $i;
      }
      try {
        $scores = $manager->getThompsonScores($eid, NULL, $arm_ids);
      }
      catch (\Throwable $e) {
        continue;
      }
      if (!is_array($scores) || !$scores) {
        continue;
      }
      arsort($scores);
      $winner = (string) key($scores);
      $decisions->$eid = ['armId' => $winner];
    }
    http_response_code(200);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, private, max-age=0');
    echo json_encode(['decisions' => $decisions]);
    exit();
  }

  if (!$registry->isRegistered($experiment_id)) {
    exit();
  }

  $storage = $container->get('rl.experiment_data_storage');


  switch ($action) {
    case 'turn':
      if ($arm_id && preg_match('/^[a-zA-Z0-9_-]+$/', $arm_id)) {
        $storage->recordTurn($experiment_id, $arm_id);
      }
      break;

    case 'turns':
      $arm_ids = filter_input(INPUT_POST, 'arm_ids', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
      if ($arm_ids) {
        $arm_ids_array = explode(',', $arm_ids);
        $arm_ids_array = array_map('trim', $arm_ids_array);

        $valid_arm_ids = [];
        foreach ($arm_ids_array as $aid) {
          if (preg_match('/^[a-zA-Z0-9_-]+$/', $aid)) {
            $valid_arm_ids[] = $aid;
          }
        }

        if (!empty($valid_arm_ids)) {
          $storage->recordTurns($experiment_id, $valid_arm_ids);
        }
      }
      break;

    case 'reward':
      if ($arm_id && preg_match('/^[a-zA-Z0-9_-]+$/', $arm_id)) {
        $storage->recordReward($experiment_id, $arm_id);
      }
      break;
  }

  http_response_code(200);
}
catch (\Exception $e) {
  // Log error and return 500.
  error_log('RL endpoint error: ' . $e->getMessage());
  http_response_code(500);
  exit('Server error');
}
