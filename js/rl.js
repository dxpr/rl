/**
 * @file
 * Thin RL transport proxy with request batching.
 *
 * Exposes Drupal.rl.decide(), Drupal.rl.rank(), Drupal.rl.turn(),
 * Drupal.rl.reward(), and Drupal.rl.flush(). Batches calls into a
 * single POST to rl.php so that
 * multiple RL-powered features on the same page share one request
 * instead of each making its own.
 *
 * Batching strategy: events accumulate for 500 ms and then flush in one
 * POST that can carry decides, turns, and rewards simultaneously.
 * visibilitychange / pagehide flush tracking writes immediately via
 * navigator.sendBeacon so buffered events survive navigation.
 *
 * About decide(): client-side decide exists to serve consumers that
 * render variants in JS (DXPR Builder's runtime, etc.) where shifting
 * the decision to PHP would burn full-page cache. Consumers that can
 * decide server-side (ai_sorting, rl_page_title, rl_menu_link,
 * rl_example, rl_example_frontend) should keep doing so; decide() is
 * for the client-rendered path only.
 *
 * Important discipline for callers:
 *   Read the arm id list from the DOM at call time. Do not hardcode
 *   arm ids in JS. The server-side renderer that produced the page's
 *   cached HTML should emit the arm list as a data attribute on the
 *   variant container; decide() just echoes that list back to the
 *   server so Thompson Sampling can seed cold-start arms. This mirrors
 *   ai_sorting's PHP pattern of recomputing the arm list from the
 *   current view query on every render.
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
    return {
      // experimentId -> { arms: [...], resolvers: [fn] }
      decides: Object.create(null),
      // experimentId -> { arms: [...], resolvers: [fn] }
      ranks: Object.create(null),
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
    var id;
    for (id in queue.decides) {
      if (Object.prototype.hasOwnProperty.call(queue.decides, id)) {
        return true;
      }
    }
    for (id in queue.ranks) {
      if (Object.prototype.hasOwnProperty.call(queue.ranks, id)) {
        return true;
      }
    }
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

  function buildPayload(snapshot) {
    var decides = [];
    var rankIds = Object.create(null);
    var id;
    for (id in snapshot.ranks) {
      if (Object.prototype.hasOwnProperty.call(snapshot.ranks, id)) {
        decides.push({ id: id, arms: snapshot.ranks[id].arms, rank: true });
        rankIds[id] = true;
      }
    }
    for (id in snapshot.decides) {
      if (Object.prototype.hasOwnProperty.call(snapshot.decides, id)) {
        if (!rankIds[id]) {
          decides.push({ id: id, arms: snapshot.decides[id].arms });
        }
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
      // Fallback: first arm in the caller-provided list. This keeps the
      // promise contract "always resolves to a usable arm" so consumers
      // do not need a .catch() for the common failure modes (unknown
      // experiment, empty data, network error).
      if (!armId) {
        armId = entry.arms[0];
      }
      entry.resolvers.forEach(function (resolve) {
        resolve(armId);
      });
    }
  }

  function fallbackDecides(snapshot) {
    for (var id in snapshot.decides) {
      if (!Object.prototype.hasOwnProperty.call(snapshot.decides, id)) {
        continue;
      }
      var entry = snapshot.decides[id];
      var fallback = entry.arms[0];
      entry.resolvers.forEach(function (resolve) {
        resolve(fallback);
      });
    }
  }

  function resolveRanks(snapshot, decisions) {
    for (var id in snapshot.ranks) {
      if (!Object.prototype.hasOwnProperty.call(snapshot.ranks, id)) {
        continue;
      }
      var entry = snapshot.ranks[id];
      var ranking = null;
      if (decisions && decisions[id] && Array.isArray(decisions[id].ranking)) {
        ranking = decisions[id].ranking;
      }
      if (!ranking) {
        ranking = entry.arms.slice();
        // If the server returned a winner but no ranking (e.g. old
        // rl.php without rank support), move it to the front so the
        // caller benefits from the information we do have.
        if (decisions && decisions[id] && decisions[id].armId) {
          var winner = decisions[id].armId;
          var idx = ranking.indexOf(winner);
          if (idx > 0) {
            ranking.splice(idx, 1);
            ranking.unshift(winner);
          }
        }
      }
      entry.resolvers.forEach(function (resolve) {
        resolve(ranking);
      });
    }
  }

  function fallbackRanks(snapshot) {
    for (var id in snapshot.ranks) {
      if (!Object.prototype.hasOwnProperty.call(snapshot.ranks, id)) {
        continue;
      }
      var entry = snapshot.ranks[id];
      entry.resolvers.forEach(function (resolve) {
        resolve(entry.arms);
      });
    }
  }

  function flush() {
    if (!hasPending()) {
      return;
    }
    var url = endpoint();
    var snapshot = takeQueue();
    if (!url) {
      fallbackDecides(snapshot);
      fallbackRanks(snapshot);
      return;
    }
    var body = JSON.stringify(buildPayload(snapshot));

    fetch(batchUrl(url), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: body,
      credentials: 'same-origin',
      keepalive: true,
    }).then(function (response) {
      // rl.php returns 422 when every entry in a non-empty batch was
      // rejected (unknown experiment, invalid ids, manager
      // unavailable). Read the JSON body anyway so the errors array
      // reaches the console; without this, a site-wide mistake like
      // "registry cache missed the new hook so no experiment rows
      // exist" looks identical to a healthy empty batch and the
      // operator has nothing to grep for in devtools. Other non-2xx
      // statuses (4xx malformed, 5xx bootstrap failure) have no
      // useful body, so fall back.
      if (!response.ok && response.status !== 422) {
        fallbackDecides(snapshot);
        fallbackRanks(snapshot);
        return null;
      }
      return response.json();
    }).then(function (json) {
      if (json) {
        reportErrors(json.errors);
        var decisions = json.decisions || {};
        resolveDecides(snapshot, decisions);
        resolveRanks(snapshot, decisions);
      }
    }).catch(function () {
      fallbackDecides(snapshot);
      fallbackRanks(snapshot);
    });
  }

  // Surface rl.php's per-entry errors on the console so mis-registered
  // experiments become visible in devtools instead of silently falling
  // back to armIds[0]. Runtimes that need richer handling can stub
  // window.Drupal.rl.onErrors; by default we only warn.
  function reportErrors(errors) {
    if (!Array.isArray(errors) || !errors.length) {
      return;
    }
    if (typeof Drupal.rl.onErrors === 'function') {
      try { Drupal.rl.onErrors(errors); } catch (e) { /* never let a listener poison the next flush */ }
    }
    if (typeof console !== 'undefined' && typeof console.warn === 'function') {
      errors.forEach(function (err) {
        if (!err || typeof err !== 'object') { return; }
        console.warn(
          '[rl] ' + (err.kind || 'entry') + ' for ' + (err.id || '(no id)') + ' rejected: ' + (err.reason || 'unknown')
        );
      });
    }
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

    // sendBeacon is fire-and-forget: we cannot read the response, so
    // pending decides and ranks cannot be fulfilled from this path.
    // Resolve them with their default fallbacks. The page is navigating
    // away anyway.
    fallbackDecides(snapshot);
    fallbackRanks(snapshot);

    if (snapshot.turns.length === 0 && snapshot.rewards.length === 0) {
      return;
    }
    var body = JSON.stringify({
      decides: [],
      turns: snapshot.turns,
      rewards: snapshot.rewards,
    });

    if ('sendBeacon' in navigator) {
      var blob = new Blob([body], { type: 'application/json' });
      navigator.sendBeacon(batchUrl(url), blob);
    }
  }

  Drupal.rl = {

    /**
     * Request a Thompson Sampling decision for an experiment.
     *
     * The armIds list must be read from the DOM (data attribute emitted
     * by the server-side renderer that produced the cached HTML) at
     * call time, never hardcoded. This mirrors ai_sorting's PHP pattern
     * of recomputing the arm list from the current view query on every
     * render, keeping JS and the cached HTML downstream of the same
     * source of truth.
     *
     * @param {string} experimentId
     *   The pre-registered experiment id.
     * @param {Array<string>} armIds
     *   The arm ids in play. Minimum 2. Must match ^[a-zA-Z0-9_-]+$
     *   server-side (UUIDs without braces satisfy this). The winning
     *   arm id is returned unchanged so the caller can map it back to
     *   its own variant table.
     *
     * @return {Promise<string>}
     *   Resolves to the winning arm id. Falls back to armIds[0] on any
     *   server failure (unknown experiment, no data, network error) so
     *   callers do not need a .catch() for the common path.
     */
    decide: function (experimentId, armIds) {
      if (!Array.isArray(armIds) || armIds.length < 2) {
        return Promise.reject(new Error('Drupal.rl.decide requires an array of at least 2 arm ids'));
      }
      return new Promise(function (resolve) {
        var entry = queue.decides[experimentId];
        if (!entry) {
          entry = queue.decides[experimentId] = {
            arms: armIds.slice(),
            resolvers: [],
          };
        }
        entry.resolvers.push(resolve);
        schedule();
      });
    },

    /**
     * Request a full Thompson Sampling ranking for an experiment.
     *
     * Like decide(), but returns the complete sorted arm list instead
     * of a single winner. Useful for client-side list reordering
     * (accordions, FAQs, carousels) where the full ranking matters,
     * not just who is first.
     *
     * Same batching, same discipline (read arm ids from the DOM),
     * same fallback behaviour: on any failure the promise resolves to
     * the caller-provided arm order so the list stays usable.
     *
     * If both decide() and rank() target the same experiment in one
     * batch window, they share one wire request using the rank
     * caller's arm list.
     *
     * @param {string} experimentId
     *   The pre-registered experiment id.
     * @param {Array<string>} armIds
     *   The arm ids in play. Minimum 2.
     *
     * @return {Promise<Array<string>>}
     *   Resolves to the full sorted arm list (best first). Falls back
     *   to the caller-provided armIds order on any failure.
     */
    rank: function (experimentId, armIds) {
      if (!Array.isArray(armIds) || armIds.length < 2) {
        return Promise.reject(new Error('Drupal.rl.rank requires an array of at least 2 arm ids'));
      }
      return new Promise(function (resolve) {
        var entry = queue.ranks[experimentId];
        if (!entry) {
          entry = queue.ranks[experimentId] = {
            arms: armIds.slice(),
            resolvers: [],
          };
        }
        entry.resolvers.push(resolve);
        schedule();
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

  // Flush buffered tracking events on navigation so turns and rewards
  // are not lost. visibilitychange covers tab switches and mobile
  // navigation; pagehide covers desktop back/forward cache restoration.
  // Pending decides and ranks are resolved with their fallbacks; the
  // page is going away so the answer no longer matters.
  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      flushBeacon();
    }
  });
  window.addEventListener('pagehide', flushBeacon);

})(Drupal, drupalSettings);
