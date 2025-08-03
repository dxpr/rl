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

// CRITICAL: Only accept POST requests for security and caching reasons
$action = filter_input(INPUT_POST, 'action', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$experiment_uuid = filter_input(INPUT_POST, 'experiment_uuid', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$arm_id = filter_input(INPUT_POST, 'arm_id', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

// Validate inputs more strictly
if (!$action || !$experiment_uuid || !in_array($action, ['turn', 'turns', 'reward'])) {
  http_response_code(400);
  exit();
}

// Additional validation for experiment_uuid (should be alphanumeric/hash)
if (!preg_match('/^[a-zA-Z0-9]+$/', $experiment_uuid)) {
  http_response_code(400);
  exit();
}

// Catch exceptions when site is not configured or storage fails
try {
  // Assumes module in modules/contrib/rl, so three levels below root.
  chdir('../../..');

  $autoloader = require_once 'autoload.php';

  $request = Request::createFromGlobals();
  $kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
  $kernel->boot();
  $container = $kernel->getContainer();

  // Check if experiment is registered
  $registry = $container->get('rl.experiment_registry');
  if (!$registry->isRegistered($experiment_uuid)) {
    // Silently ignore unregistered experiments like statistics module
    exit();
  }

  // Get the experiment data storage service
  $storage = $container->get('rl.experiment_data_storage');

  // Handle the different actions
  switch ($action) {
    case 'turn':
      // Validate arm_id for single turn
      if ($arm_id && preg_match('/^[a-zA-Z0-9_-]+$/', $arm_id)) {
        $storage->recordTurn($experiment_uuid, $arm_id);
      }
      break;

    case 'turns':
      // Handle multiple turns with better validation
      $arm_ids = filter_input(INPUT_POST, 'arm_ids', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
      if ($arm_ids) {
        $arm_ids_array = explode(',', $arm_ids);
        $arm_ids_array = array_map('trim', $arm_ids_array);
        
        // Validate each arm_id
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
      // Validate arm_id for reward
      if ($arm_id && preg_match('/^[a-zA-Z0-9_-]+$/', $arm_id)) {
        $storage->recordReward($experiment_uuid, $arm_id);
      }
      break;
  }
  
  // Send success response
  http_response_code(200);
  
}
catch (\Exception $e) {
  // Do nothing if there is PDO Exception or other failure.
}