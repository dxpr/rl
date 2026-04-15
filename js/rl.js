/**
 * @file
 * Thin RL transport proxy with request batching.
 *
 * Exposes Drupal.rl.decide(), Drupal.rl.turn(), Drupal.rl.reward(), and
 * Drupal.rl.flush(). Batches calls into a single POST to rl.php so that
 * multiple RL-powered features on the same page share one request instead
 * of each making its own.
 *
 * Batching strategy:
 *   - decide() flushes on the next tick (setTimeout 0), catching every
 *     module that registers synchronously during Drupal.behaviors.attach.
 *   - turn() / reward() flush in a 500 ms window to coalesce events that
 *     arrive as the user interacts with the page.
 *   - visibilitychange / pagehide flush immediately via navigator.sendBeacon
 *     so buffered events survive navigation.
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
  var timerDelay = null;

  function emptyQueue() {
    return {
      decides: Object.create(null),
      turns: [],
      rewards: [],
    };
  }

  function endpoint() {
    if (drupalSettings.rl && drupalSettings.rl.endpointUrl) {
      return drupalSettings.rl.endpointUrl;
    }
    return null;
  }

  function hasPending() {
    for (var id in queue.decides) {
      if (Object.prototype.hasOwnProperty.call(queue.decides, id)) {
        return true;
      }
    }
    return queue.turns.length > 0 || queue.rewards.length > 0;
  }

  function schedule(delay) {
    if (timer !== null && timerDelay <= delay) {
      return;
    }
    if (timer !== null) {
      clearTimeout(timer);
    }
    timerDelay = delay;
    timer = setTimeout(function () {
      timer = null;
      timerDelay = null;
      flush();
    }, delay);
  }

  function takeQueue() {
    var snapshot = queue;
    queue = emptyQueue();
    return snapshot;
  }

  function buildPayload(snapshot) {
    var decides = [];
    for (var id in snapshot.decides) {
      if (Object.prototype.hasOwnProperty.call(snapshot.decides, id)) {
        decides.push({ id: id, arms: snapshot.decides[id].arms });
      }
    }
    return {
      decides: decides,
      turns: snapshot.turns,
      rewards: snapshot.rewards,
    };
  }

  function resolveDecides(snapshot, decisions) {
    for (var id in snapshot.decides) {
      if (!Object.prototype.hasOwnProperty.call(snapshot.decides, id)) {
        continue;
      }
      var entry = snapshot.decides[id];
      var armId = null;
      if (decisions && decisions[id] && decisions[id].armId) {
        armId = decisions[id].armId;
      }
      // Fallback to the first arm when the server returns no decision
      // (unregistered experiment, missing data, etc.) so callers always
      // get a usable variant.
      if (!armId) {
        armId = entry.arms[0];
      }
      entry.resolvers.forEach(function (resolver) {
        resolver.resolve(armId);
      });
    }
  }

  function rejectDecides(snapshot, reason) {
    for (var id in snapshot.decides) {
      if (!Object.prototype.hasOwnProperty.call(snapshot.decides, id)) {
        continue;
      }
      snapshot.decides[id].resolvers.forEach(function (resolver) {
        resolver.reject(reason);
      });
    }
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
      var dropped = takeQueue();
      rejectDecides(dropped, new Error('Drupal.rl: endpointUrl not set'));
      return;
    }
    var snapshot = takeQueue();
    var body = JSON.stringify(buildPayload(snapshot));

    fetch(batchUrl(url), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: body,
      credentials: 'same-origin',
      keepalive: true,
    })
      .then(function (response) {
        if (!response.ok) {
          rejectDecides(snapshot, new Error('Drupal.rl: HTTP ' + response.status));
          return null;
        }
        return response.json();
      })
      .then(function (json) {
        if (json) {
          resolveDecides(snapshot, json.decisions || {});
        }
      })
      .catch(function (err) {
        rejectDecides(snapshot, err);
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
    var body = JSON.stringify(buildPayload(snapshot));

    if ('sendBeacon' in navigator) {
      var blob = new Blob([body], { type: 'application/json' });
      navigator.sendBeacon(batchUrl(url), blob);
    }
    // Decide promises cannot be fulfilled once the page is unloading, so
    // reject any that happen to still be buffered. Callers on a page that
    // is going away do not need the answer.
    rejectDecides(snapshot, new Error('Drupal.rl: page unloading'));
  }

  Drupal.rl = {

    /**
     * Request a Thompson Sampling decision for an experiment.
     *
     * @param {string} experimentId
     *   The pre-registered experiment id.
     * @param {Array<string>} armIds
     *   The arm ids the caller is offering. The winning arm id is returned
     *   unchanged so the caller can map it back to its own variant table.
     *
     * @return {Promise<string>}
     *   Resolves to the winning arm id. Falls back to armIds[0] when the
     *   server cannot provide a decision.
     */
    decide: function (experimentId, armIds) {
      return new Promise(function (resolve, reject) {
        var entry = queue.decides[experimentId];
        if (!entry) {
          entry = queue.decides[experimentId] = {
            arms: armIds.slice(),
            resolvers: [],
          };
        }
        entry.resolvers.push({ resolve: resolve, reject: reject });
        schedule(0);
      });
    },

    /**
     * Record an impression for a variant.
     *
     * @param {string} experimentId
     * @param {string} armId
     */
    turn: function (experimentId, armId) {
      queue.turns.push({ id: experimentId, arm: armId });
      schedule(500);
    },

    /**
     * Record a conversion for a variant.
     *
     * @param {string} experimentId
     * @param {string} armId
     */
    reward: function (experimentId, armId) {
      queue.rewards.push({ id: experimentId, arm: armId });
      schedule(500);
    },

    /**
     * Force an immediate flush of the buffered queue.
     */
    flush: function () {
      if (timer !== null) {
        clearTimeout(timer);
        timer = null;
        timerDelay = null;
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
