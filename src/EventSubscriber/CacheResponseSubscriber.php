<?php

namespace Drupal\rl\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Modifies response cache headers for RL experiments.
 */
class CacheResponseSubscriber implements EventSubscriberInterface {

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
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
    $cache_override = &drupal_static('rl_cache_override');

    if ($cache_override !== NULL) {
      $response = $event->getResponse();
      $response->setPublic();
      $response->setMaxAge($cache_override);

      // Clear the static variable.
      $cache_override = NULL;
    }
  }

}
