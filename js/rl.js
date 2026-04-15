/**
 * @file
 * Thin RL transport proxy with request batching.
 *
 * Exposes Drupal.rl.turn(), Drupal.rl.reward(), and Drupal.rl.flush().
 * Batches calls into a single POST to rl.php so that multiple RL-powered
 * features on the same page share one request instead of each making its
 * own.
 *
 * Batching strategy: events accumulate for 500 ms and then flush in one
 * POST. visibilitychange / pagehide flush immediately via
 * navigator.sendBeacon so buffered events survive navigation.
 *
 * Deciding which variant to show is a server-side concern - see
 * ai_sorting's Views sort plugin or VariantSelectorBase in this module.
 * Drupal.rl deliberately does not provide a decide API: client-side
 * decides would require the caller to know the current arm set, which
 * belongs in the experiment owner's domain model, not in runtime JS.
 *
 * Modelled on Drupal.history in Drupal core, which batches node-view
 * tracking the same way.
 *
 * @namespace
 */

(function (Drupal, drupalSettings) {

  'use strict';

  var queue = emptyQueue();
  var timer = null;

  function emptyQueue() {
    return { turns: [], rewards: [] };
  }

  function endpoint() {
    if (drupalSettings.rl && drupalSettings.rl.endpointUrl) {
      return drupalSettings.rl.endpointUrl;
    }
    return null;
  }

  function hasPending() {
    return queue.turns.length > 0 || queue.rewards.length > 0;
  }

  function schedule() {
    if (timer !== null) {
      return;
    }
    timer = setTimeout(function () {
      timer = null;
      flush();
    }, 500);
  }

  function takeQueue() {
    var snapshot = queue;
    queue = emptyQueue();
    return snapshot;
  }

  function batchUrl(url) {
    return url + (url.indexOf('?') === -1 ? '?' : '&') + 'action=batch';
  }

  function flush() {
    if (!hasPending()) {
      return;
    }
    var url = endpoint();
    if (!url) {
      // No endpoint configured - drop the queue rather than dangling.
      takeQueue();
      return;
    }
    var snapshot = takeQueue();
    var body = JSON.stringify(snapshot);

    fetch(batchUrl(url), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: body,
      credentials: 'same-origin',
      keepalive: true,
    }).catch(function () {
      // Swallow network errors. Tracking is best-effort.
    });
  }

  function flushBeacon() {
    if (!hasPending()) {
      return;
    }
    var url = endpoint();
    if (!url) {
      return;
    }
    var snapshot = takeQueue();
    var body = JSON.stringify(snapshot);

    if ('sendBeacon' in navigator) {
      var blob = new Blob([body], { type: 'application/json' });
      navigator.sendBeacon(batchUrl(url), blob);
    }
  }

  Drupal.rl = {

    /**
     * Record an impression for a variant.
     *
     * @param {string} experimentId
     * @param {string} armId
     */
    turn: function (experimentId, armId) {
      queue.turns.push({ id: experimentId, arm: armId });
      schedule();
    },

    /**
     * Record a conversion for a variant.
     *
     * @param {string} experimentId
     * @param {string} armId
     */
    reward: function (experimentId, armId) {
      queue.rewards.push({ id: experimentId, arm: armId });
      schedule();
    },

    /**
     * Force an immediate flush of the buffered queue.
     */
    flush: function () {
      if (timer !== null) {
        clearTimeout(timer);
        timer = null;
      }
      flush();
    },

  };

  // Flush buffered events on navigation so turns and rewards are not lost.
  // visibilitychange covers tab switches and mobile navigation; pagehide
  // covers desktop back/forward cache restoration.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      flushBeacon();
    }
  });
  window.addEventListener('pagehide', flushBeacon);

})(Drupal, drupalSettings);
