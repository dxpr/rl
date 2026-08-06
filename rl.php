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
 * PHP at render time when possible (see ai_sorting's Views sort plugin,
 * or VariantSelectorBase in this module). For client-rendered consumers
 * that cannot decide server-side without breaking page cache, the batch
 * action supports "decides" entries that return Thompson Sampling winners
 * and, optionally, full ranked arm lists via "rank": true.
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

// The action can arrive in the query string (Drupal.rl batch requests) or
// as a form field (legacy consumers + ping).
$action = filter_input(INPUT_GET, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS)
  ?: filter_input(INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

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
  $manager = $container->has('rl.experiment_manager') ? $container->get('rl.experiment_manager') : NULL;

  if ($action === 'batch') {
    $result = handle_batch_request($payload, $registry, $storage, $manager);
    // 422 only when every entry was rejected, so a stale container
    // mid-deploy (partial success) still gets a 200 with errors[].
    $status = ($result['requested'] > 0 && $result['succeeded'] === 0) ? 422 : 200;
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store, private, max-age=0');
    $body = [
      'ok' => $status === 200,
      'decisions' => $result['decisions'],
    ];
    if (!empty($result['errors'])) {
      $body['errors'] = $result['errors'];
    }
    echo json_encode($body);
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
 * Expected JSON shape (all three sections are optional):
 * @code
 * {
 *   "decides": [
 *     {"id": "<experiment_id>", "arms": ["<arm>", "<arm>", ...]},
 *     {"id": "<experiment_id>", "arms": [...], "rank": true},
 *     ...
 *   ],
 *   "turns":   [{"id": "<experiment_id>", "arm": "<arm>"}, ...],
 *   "rewards": [{"id": "<experiment_id>", "arm": "<arm>"}, ...]
 * }
 * @endcode
 *
 * Decides resolve to Thompson Sampling winners and are returned keyed
 * by experiment id. When "rank": true is set on a decide entry, the
 * full sorted arm list is included as "ranking":
 * @code
 * {"decisions": {"<experiment_id>": {"armId": "<arm>"}}}
 * {"decisions": {"<experiment_id>": {"armId": "<arm>", "ranking": ["<arm>", ...]}}}
 * @endcode
 *
 * Rejected entries are recorded in `errors` with a machine-readable
 * `reason` (unknown_experiment, invalid_arm_id, missing_arms,
 * invalid_id, scoring_failed, manager_unavailable, malformed_entry).
 * The caller compares `requested` vs `succeeded` to pick the HTTP
 * status.
 *
 * @param array $payload
 *   The decoded JSON body.
 * @param \Drupal\rl\Registry\ExperimentRegistryInterface $registry
 *   Used to validate experiment ids.
 * @param \Drupal\rl\Storage\ExperimentDataStorageInterface $storage
 *   Used to persist turns and rewards.
 * @param \Drupal\rl\Service\ExperimentManagerInterface|null $manager
 *   Computes Thompson Sampling scores. NULL marks every decide as
 *   "manager_unavailable".
 *
 * @return array{
 *   decisions: \stdClass,
 *   errors: array<int, array{kind: string, id: string, reason: string}>,
 *   requested: int,
 *   succeeded: int,
 *   }
 *   `decisions` is a stdClass so json_encode emits `{}` when empty.
 */
function handle_batch_request(array $payload, $registry, $storage, $manager = NULL): array {
  $id_pattern = '/^[a-zA-Z0-9_-]+$/';
  $decisions = new \stdClass();
  $errors = [];
  $requested = 0;
  $succeeded = 0;

  if (isset($payload['decides']) && is_array($payload['decides'])) {
    foreach ($payload['decides'] as $decide) {
      if (!is_array($decide)) {
        $requested++;
        $errors[] = ['kind' => 'decide', 'id' => '', 'reason' => 'malformed_entry'];
        continue;
      }
      $eid = isset($decide['id']) ? (string) $decide['id'] : '';
      if ($eid === '' || !preg_match($id_pattern, $eid)) {
        $requested++;
        $errors[] = ['kind' => 'decide', 'id' => $eid, 'reason' => 'invalid_id'];
        continue;
      }
      $requested++;
      if ($manager === NULL) {
        $errors[] = ['kind' => 'decide', 'id' => $eid, 'reason' => 'manager_unavailable'];
        continue;
      }
      if (!$registry->isRegistered($eid)) {
        $errors[] = ['kind' => 'decide', 'id' => $eid, 'reason' => 'unknown_experiment'];
        continue;
      }
      $arms = $decide['arms'] ?? [];
      if (!is_array($arms) || count($arms) < 2) {
        $errors[] = ['kind' => 'decide', 'id' => $eid, 'reason' => 'missing_arms'];
        continue;
      }
      $arm_ids = [];
      $valid = TRUE;
      foreach ($arms as $arm) {
        $arm_id = (string) $arm;
        if ($arm_id === '' || !preg_match($id_pattern, $arm_id)) {
          $valid = FALSE;
          break;
        }
        $arm_ids[] = $arm_id;
      }
      if (!$valid) {
        $errors[] = ['kind' => 'decide', 'id' => $eid, 'reason' => 'invalid_arm_id'];
        continue;
      }
      try {
        $scores = $manager->getThompsonScores($eid, NULL, $arm_ids);
      }
      catch (\Throwable $e) {
        $errors[] = ['kind' => 'decide', 'id' => $eid, 'reason' => 'scoring_failed'];
        continue;
      }
      if (!is_array($scores) || !$scores) {
        $errors[] = ['kind' => 'decide', 'id' => $eid, 'reason' => 'scoring_failed'];
        continue;
      }
      arsort($scores);
      $decision = ['armId' => (string) key($scores)];
      if (!empty($decide['rank'])) {
        $decision['ranking'] = array_map('strval', array_keys($scores));
      }
      $decisions->{$eid} = $decision;
      $succeeded++;
    }
  }

  if (isset($payload['turns']) && is_array($payload['turns'])) {
    foreach ($payload['turns'] as $turn) {
      if (!is_array($turn)) {
        $requested++;
        $errors[] = ['kind' => 'turn', 'id' => '', 'reason' => 'malformed_entry'];
        continue;
      }
      $eid = isset($turn['id']) ? (string) $turn['id'] : '';
      $aid = isset($turn['arm']) ? (string) $turn['arm'] : '';
      if ($eid === '' || $aid === '' || !preg_match($id_pattern, $eid) || !preg_match($id_pattern, $aid)) {
        $requested++;
        $errors[] = ['kind' => 'turn', 'id' => $eid, 'reason' => 'invalid_id'];
        continue;
      }
      $requested++;
      if (!$registry->isRegistered($eid)) {
        $errors[] = ['kind' => 'turn', 'id' => $eid, 'reason' => 'unknown_experiment'];
        continue;
      }
      $storage->recordTurn($eid, $aid);
      $succeeded++;
    }
  }

  if (isset($payload['rewards']) && is_array($payload['rewards'])) {
    foreach ($payload['rewards'] as $reward) {
      if (!is_array($reward)) {
        $requested++;
        $errors[] = ['kind' => 'reward', 'id' => '', 'reason' => 'malformed_entry'];
        continue;
      }
      $eid = isset($reward['id']) ? (string) $reward['id'] : '';
      $aid = isset($reward['arm']) ? (string) $reward['arm'] : '';
      if ($eid === '' || $aid === '' || !preg_match($id_pattern, $eid) || !preg_match($id_pattern, $aid)) {
        $requested++;
        $errors[] = ['kind' => 'reward', 'id' => $eid, 'reason' => 'invalid_id'];
        continue;
      }
      $requested++;
      if (!$registry->isRegistered($eid)) {
        $errors[] = ['kind' => 'reward', 'id' => $eid, 'reason' => 'unknown_experiment'];
        continue;
      }
      $storage->recordReward($eid, $aid);
      $succeeded++;
    }
  }

  return [
    'decisions' => $decisions,
    'errors' => $errors,
    'requested' => $requested,
    'succeeded' => $succeeded,
  ];
}
