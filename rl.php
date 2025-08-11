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
$experiment_uuid = filter_input(INPUT_POST, 'experiment_uuid', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$arm_id = filter_input(INPUT_POST, 'arm_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

if (!$action || !$experiment_uuid || !in_array($action, ['turn', 'turns', 'reward'])) {
  http_response_code(400);
  exit('Invalid request parameters');
}

if (!preg_match('/^[a-zA-Z0-9]+$/', $experiment_uuid)) {
  http_response_code(400);
  exit('Invalid experiment_uuid format');
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
  if (!$registry->isRegistered($experiment_uuid)) {
    exit();
  }

  $storage = $container->get('rl.experiment_data_storage');

  switch ($action) {
    case 'turn':
      if ($arm_id && preg_match('/^[a-zA-Z0-9_-]+$/', $arm_id)) {
        $storage->recordTurn($experiment_uuid, $arm_id);
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
          $storage->recordTurns($experiment_uuid, $valid_arm_ids);
        }
      }
      break;

    case 'reward':
      if ($arm_id && preg_match('/^[a-zA-Z0-9_-]+$/', $arm_id)) {
        $storage->recordReward($experiment_uuid, $arm_id);
      }
      break;
  }

}
catch (\Exception $e) {
}
