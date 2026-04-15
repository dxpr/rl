<?php

/**
 * @file
 * Handles RL experiment tracking via a minimal Drupal bootstrap.
 *
 * Following the statistics.php architecture for optimal performance.
 *
 * Four actions are supported:
 *   - ping: liveness check, no experiment touched.
 *   - turn / turns / reward: legacy form-POST tracking used by
 *     production consumers (ai_sorting, and any third-party JS that was
 *     written before Drupal.rl shipped). These remain fully supported.
 *   - batch: JSON POST body used by Drupal.rl on the client side. Carries
 *     multiple turn and reward events for potentially several experiments
 *     in a single request. See the docblock on handle_batch_request()
 *     below for the payload shape.
 *
 * Deciding which variant to show is an application concern that belongs in
 * PHP at render time (see ai_sorting's Views sort plugin, or
 * VariantSelectorBase in this module). rl.php intentionally does not
 * expose a client-side decide endpoint.
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

// The action can arrive in the query string (Drupal.rl batch requests) or
// as a form field (legacy consumers + ping).
$action = $_GET['action'] ?? filter_input(INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

// Ping is a cheap liveness check used by hook_requirements() to verify
// the web server serves rl.php directly. No Drupal bootstrap needed.
if ($action === 'ping') {
  http_response_code(200);
  exit('pong');
}

$experiment_id = NULL;
$arm_id = NULL;
$payload = NULL;

if ($action === 'batch') {
  // Read and decode the JSON body before bootstrapping Drupal so malformed
  // requests cost nothing.
  $raw_body = file_get_contents('php://input');
  if ($raw_body === FALSE || $raw_body === '') {
    http_response_code(400);
    header('Content-Type: application/json');
    exit('{"error":"empty body"}');
  }
  $payload = json_decode($raw_body, TRUE);
  if (!is_array($payload)) {
    http_response_code(400);
    header('Content-Type: application/json');
    exit('{"error":"invalid json"}');
  }
}
elseif (in_array($action, ['turn', 'turns', 'reward'], TRUE)) {
  $experiment_id = filter_input(INPUT_POST, 'experiment_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
  $arm_id = filter_input(INPUT_POST, 'arm_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
  if (!$experiment_id || !preg_match('/^[a-zA-Z0-9_-]+$/', $experiment_id)) {
    http_response_code(400);
    exit('Invalid experiment_id');
  }
}
else {
  http_response_code(400);
  exit('Invalid action');
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

  if ($action === 'batch') {
    handle_batch_request($payload, $registry, $storage);
    http_response_code(200);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, private, max-age=0');
    echo '{"ok":true}';
    exit;
  }

  if (!$registry->isRegistered($experiment_id)) {
    exit();
  }

  switch ($action) {
    case 'turn':
      if ($arm_id && preg_match('/^[a-zA-Z0-9_-]+$/', $arm_id)) {
        $storage->recordTurn($experiment_id, $arm_id);
      }
      break;

    case 'turns':
      $arm_ids = filter_input(INPUT_POST, 'arm_ids', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
      if ($arm_ids) {
        $arm_ids_array = array_map('trim', explode(',', $arm_ids));

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

/**
 * Process a Drupal.rl batch payload.
 *
 * Expected JSON shape (both sections are optional):
 * @code
 * {
 *   "turns":   [{"id": "<experiment_id>", "arm": "v0"}, ...],
 *   "rewards": [{"id": "<experiment_id>", "arm": "v1"}, ...]
 * }
 * @endcode
 *
 * Unknown or malformed entries are silently skipped so one bad event does
 * not poison the rest of the batch.
 *
 * @param array $payload
 *   The decoded JSON body.
 * @param \Drupal\rl\Registry\ExperimentRegistryInterface $registry
 * @param \Drupal\rl\Storage\ExperimentDataStorageInterface $storage
 */
function handle_batch_request(array $payload, $registry, $storage): void {
  $id_pattern = '/^[a-zA-Z0-9_-]+$/';

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
}
