<?php

/**
 * @file
 * Handles RL experiment tracking via a minimal Drupal bootstrap.
 *
 * Following the statistics.php architecture for optimal performance.
 *
 * Two actions are supported:
 *   - ping: liveness check, no experiment touched.
 *   - batch: JSON POST body used by Drupal.rl on the client side. Carries
 *     multiple decide / turn / reward events for potentially several
 *     experiments in a single request. See the docblock on
 *     handle_batch_request() below for the payload shape.
 *
 * The legacy action=turn / action=turns / action=reward / action=decide
 * form endpoints were removed in favor of action=batch when the shared
 * Drupal.rl client library was introduced.
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

// Action can arrive in the query string (JSON batch requests) or as a form
// field (ping uses form POST from the Drupal health check).
$action = $_GET['action'] ?? filter_input(INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

// Ping is a cheap liveness check used by hook_requirements() to verify the
// web server serves rl.php directly. No Drupal bootstrap needed.
if ($action === 'ping') {
  http_response_code(200);
  exit('pong');
}

if ($action !== 'batch') {
  http_response_code(400);
  exit('Invalid action');
}

// Read and decode the JSON body before bootstrapping Drupal so malformed
// requests cost nothing.
$raw_body = file_get_contents('php://input');
if ($raw_body === FALSE || $raw_body === '') {
  http_response_code(400);
  header('Content-Type: application/json');
  exit('{"decisions":{}}');
}
$payload = json_decode($raw_body, TRUE);
if (!is_array($payload)) {
  http_response_code(400);
  header('Content-Type: application/json');
  exit('{"decisions":{}}');
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
  $storage = $container->get('rl.experiment_data_storage');
  $manager = $container->has('rl.experiment_manager') ? $container->get('rl.experiment_manager') : NULL;

  $decisions = handle_batch_request($payload, $registry, $storage, $manager);

  http_response_code(200);
  header('Content-Type: application/json');
  header('Cache-Control: no-store, private, max-age=0');
  echo json_encode(['decisions' => $decisions]);
}
catch (\Exception $e) {
  // Log error and return 500.
  error_log('RL endpoint error: ' . $e->getMessage());
  http_response_code(500);
  exit('Server error');
}

/**
 * Process a Drupal.rl batch payload.
 *
 * Expected JSON shape (each section is optional):
 * @code
 * {
 *   "decides": [
 *     {"id": "<experiment_id>", "arms": ["v0", "v1", ...]},
 *     ...
 *   ],
 *   "turns":   [{"id": "<experiment_id>", "arm": "v0"}, ...],
 *   "rewards": [{"id": "<experiment_id>", "arm": "v1"}, ...]
 * }
 * @endcode
 *
 * Returns the decisions map keyed by experiment id:
 * @code
 * {"<experiment_id>": {"armId": "v1"}, ...}
 * @endcode
 *
 * Unknown or malformed entries are silently skipped so one bad event does
 * not poison the rest of the batch. Callers that receive no decision for a
 * given experiment should fall back to their own default variant.
 *
 * @param array $payload
 *   The decoded JSON body.
 * @param \Drupal\rl\Registry\ExperimentRegistryInterface $registry
 * @param \Drupal\rl\Storage\ExperimentDataStorageInterface $storage
 * @param \Drupal\rl\Service\ExperimentManagerInterface|null $manager
 *
 * @return object
 *   Decisions object (stdClass to preserve JSON object shape when empty).
 */
function handle_batch_request(array $payload, $registry, $storage, $manager): \stdClass {
  $decisions = new \stdClass();
  $id_pattern = '/^[a-zA-Z0-9_-]+$/';

  if ($manager !== NULL && isset($payload['decides']) && is_array($payload['decides'])) {
    foreach ($payload['decides'] as $decide) {
      if (!is_array($decide)) {
        continue;
      }
      $eid = isset($decide['id']) ? (string) $decide['id'] : '';
      if ($eid === '' || !preg_match($id_pattern, $eid)) {
        continue;
      }
      if (!$registry->isRegistered($eid)) {
        continue;
      }
      $arms = $decide['arms'] ?? [];
      if (!is_array($arms) || count($arms) < 2) {
        continue;
      }
      $arm_ids = [];
      foreach ($arms as $arm) {
        $arm_id = (string) $arm;
        if ($arm_id === '' || !preg_match($id_pattern, $arm_id)) {
          continue 2;
        }
        $arm_ids[] = $arm_id;
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
      $decisions->{$eid} = ['armId' => (string) key($scores)];
    }
  }

  if (isset($payload['turns']) && is_array($payload['turns'])) {
    foreach ($payload['turns'] as $turn) {
      if (!is_array($turn)) {
        continue;
      }
      $eid = isset($turn['id']) ? (string) $turn['id'] : '';
      $aid = isset($turn['arm']) ? (string) $turn['arm'] : '';
      if ($eid === '' || $aid === '') {
        continue;
      }
      if (!preg_match($id_pattern, $eid) || !preg_match($id_pattern, $aid)) {
        continue;
      }
      if (!$registry->isRegistered($eid)) {
        continue;
      }
      $storage->recordTurn($eid, $aid);
    }
  }

  if (isset($payload['rewards']) && is_array($payload['rewards'])) {
    foreach ($payload['rewards'] as $reward) {
      if (!is_array($reward)) {
        continue;
      }
      $eid = isset($reward['id']) ? (string) $reward['id'] : '';
      $aid = isset($reward['arm']) ? (string) $reward['arm'] : '';
      if ($eid === '' || $aid === '') {
        continue;
      }
      if (!preg_match($id_pattern, $eid) || !preg_match($id_pattern, $aid)) {
        continue;
      }
      if (!$registry->isRegistered($eid)) {
        continue;
      }
      $storage->recordReward($eid, $aid);
    }
  }

  return $decisions;
}
