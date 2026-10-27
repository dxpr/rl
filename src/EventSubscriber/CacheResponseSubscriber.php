<?php

namespace Drupal\rl\EventSubscriber;

use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\Core\Cache\MemoryCache\MemoryCacheInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Modifies response cache headers for RL experiments.
 */
class CacheResponseSubscriber implements EventSubscriberInterface {

  /**
   * Constructs the cache response subscriber.
   */
  public function __construct(protected MemoryCacheInterface $runtimeCache) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::RESPONSE => 'onResponse',
    ];
  }

  /**
   * Modifies response cache headers when RL cache override is set.
   *
   * @param \Symfony\Component\HttpKernel\Event\ResponseEvent $event
   *   The response event.
   */
  public function onResponse(ResponseEvent $event) {
    $cache_override = $this->runtimeCache->get('rl_cache_override')->data ?? NULL;

    if ($cache_override !== NULL) {
      $response = $event->getResponse();
      $response->setMaxAge($cache_override);

      // Also update the cacheability metadata for Drupal's internal page cache.
      if ($response instanceof CacheableResponseInterface) {
        $response->getCacheableMetadata()->setCacheMaxAge($cache_override);
      }

      // Consume the override so it cannot affect another response.
      $this->runtimeCache->delete('rl_cache_override');
    }
  }

}
