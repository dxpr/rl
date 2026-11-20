<?php

namespace Drupal\rl\Hook;

use Drupal\rl\Storage\SnapshotStorage;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for rl.
 */
class RlHooks {
  use StringTranslationTrait;

  /**
   * Constructs the hook service.
   */
  public function __construct(
    protected SnapshotStorage $rlSnapshotStorage,
    protected ModuleExtensionList $moduleList,
    protected AccountProxyInterface $currentUser,
    protected RouteMatchInterface $routeMatch,
    protected LoggerChannelFactoryInterface $loggerFactory,
    protected RequestStack $requestStack,
  ) {}

  /**
   * Implements hook_cron().
   */
  #[Hook('cron')]
  public function cron() {
    // Clean up old snapshots if event logging is enabled.
    $snapshot_storage = $this->rlSnapshotStorage;
    if ($snapshot_storage->isEnabled()) {
      $deleted = $snapshot_storage->cleanup();
      if ($deleted > 0) {
        $this->loggerFactory->get('rl')->info('Cleaned up @count old snapshots.', [
          '@count' => $deleted,
        ]);
      }
    }
  }

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme() {
    return [
      'rl_charts' => [
        'variables' => [
          'title' => NULL,
          'subtitle' => NULL,
          'tip_hover' => NULL,
          'tip_taller' => NULL,
          'interaction_hint' => NULL,
          'date_filter' => NULL,
        ],
        'template' => 'rl-charts',
      ],
    ];
  }

  /**
   * Implements hook_page_attachments().
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments) {
    // Attach experiment highlight styles for users who can view RL reports.
    if ($this->currentUser->hasPermission('view rl reports')) {
      $attachments['#attached']['library'][] = 'rl/experiment-highlight';
    }
    // Publish the rl.php endpoint URL to drupalSettings so any page that
    // attaches the rl/api library can use Drupal.rl without each consumer
    // module recomputing the URL.
    $rl_path = $this->moduleList->getPath('rl');
    $base_path = $this->requestStack->getCurrentRequest()->getBasePath();
    $attachments['#attached']['drupalSettings']['rl']['endpointUrl'] = $base_path . '/' . $rl_path . '/rl.php';
  }

  /**
   * Implements hook_preprocess_html().
   */
  #[Hook('preprocess_html')]
  public function preprocessHtml(&$variables) {
    // Add body class on RL admin pages for styling.
    $route_name = $this->routeMatch->getRouteName();
    if ($route_name && str_starts_with($route_name, 'rl.')) {
      $variables['attributes']['class'][] = 'rl-admin-page';
    }
  }

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match) {
    switch ($route_name) {
      case 'help.page.rl':
        $output = '';
        $output .= '<h3>' . $this->t('About') . '</h3>';
        $output .= '<p>' . $this->t('The Reinforcement Learning module provides a core API for tracking multi-armed bandit experiments using turns and rewards. It supports Thompson Sampling for optimizing decision making.') . '</p>';
        $output .= '<h3>' . $this->t('Features') . '</h3>';
        $output .= '<ul>';
        $output .= '<li>' . $this->t('Abstract experiment tracking with deterministic IDs') . '</li>';
        $output .= '<li>' . $this->t('Turn and reward recording') . '</li>';
        $output .= '<li>' . $this->t('Thompson Sampling algorithm implementation') . '</li>';
        $output .= '<li>' . $this->t('Optimized endpoints for high-performance tracking') . '</li>';
        $output .= '<li>' . $this->t('Service API for server-side integration') . '</li>';
        $output .= '</ul>';
        return $output;
    }
  }

}
