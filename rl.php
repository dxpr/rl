<?php

/**
 * @file
 * Handles RL experiment tracking via AJAX with minimal bootstrap.
 * 
 * Following the statistics.php architecture for optimal performance.
 */

use Drupal\Core\DrupalKernel;
use Symfony\Component\HttpFoundation\Request;

// CRITICAL: Only accept POST requests for security and caching reasons
$action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_STRING);
$experiment_uuid = filter_input(INPUT_POST, 'experiment_uuid', FILTER_SANITIZE_STRING);
$arm_id = filter_input(INPUT_POST, 'arm_id', FILTER_SANITIZE_STRING);

// Early exit if not POST or missing required parameters
if (!$action || !$experiment_uuid) {
  exit();
}

// Catch exceptions when site is not configured or storage fails
try {
  // Navigate to Drupal root (assumes module in modules/custom/rl)
  chdir('../../..');

  $autoloader = require_once 'autoload.php';

  $request = Request::createFromGlobals();
  $kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
  $kernel->boot();
  $container = $kernel->getContainer();

  // Get the experiment data storage service
  $storage = $container->get('rl.experiment_data_storage');

  // Handle the different actions
  switch ($action) {
    case 'turn':
      if ($arm_id) {
        $storage->recordTurn($experiment_uuid, $arm_id);
      }
      break;

    case 'turns':
      $arm_ids = filter_input(INPUT_POST, 'arm_ids', FILTER_SANITIZE_STRING);
      if ($arm_ids) {
        $arm_ids_array = explode(',', $arm_ids);
        $arm_ids_array = array_map('trim', $arm_ids_array);
        $storage->recordTurns($experiment_uuid, $arm_ids_array);
      }
      break;

    case 'reward':
      if ($arm_id) {
        $storage->recordReward($experiment_uuid, $arm_id);
      }
      break;
  }
}
catch (\Exception $e) {
  // Silently fail - same as statistics.php
  // Do nothing if there is PDO Exception or other failure
}