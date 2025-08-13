<?php

namespace Drupal\rl\Service;

use Drupal\Core\Config\ConfigFactoryInterface;

/**
 * Cache manager service for RL experiments.
 */
class CacheManager {

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs a CacheManager object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   */
  public function __construct(ConfigFactoryInterface $config_factory) {
    $this->configFactory = $config_factory;
  }

  /**
   * Overrides page cache if experiment cache is shorter than site cache.
   *
   * @param int $experiment_cache_seconds
   *   The experiment cache lifetime in seconds.
   */
  public function overridePageCacheIfShorter(int $experiment_cache_seconds): void {
    $site_cache = $this->configFactory->get('system.performance')->get('cache.page.max_age');

    // If site cache is disabled (0) or experiment cache is longer/equal,
    // leave page cache unchanged.
    if ($site_cache == 0 || $experiment_cache_seconds >= $site_cache) {
      return;
    }

    // Experiment cache is shorter than site cache - store for response
    // subscriber.
    $cache_override = &drupal_static('rl_cache_override');
    $cache_override = $experiment_cache_seconds;
  }

}
