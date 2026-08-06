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
 * About decide() and rank(): client-side decide/rank exist to serve
 * consumers that render variants in JS (DXPR Builder's runtime, etc.)
 * where shifting the decision to PHP would burn full-page cache.
 * decide() returns a single winner; rank() returns the full sorted
 * arm list for use cases like reordering accordion items or lists.
 * Consumers that can decide server-side (ai_sorting, rl_page_title,
 * rl_menu_link, rl_example, rl_example_frontend) should keep doing
 * so; decide()/rank() are for the client-rendered path only.
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

  let queue = emptyQueue();
  let timer = null;

  function emptyQueue() {
    return {
      // experimentId -> { arms: [...], rank: bool, resolvers: [fn], rankResolvers: [fn] }
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
    for (const id in queue.decides) {
      if (Object.hasOwn(queue.decides, id)) {
        return true;
      }
    }
    return queue.turns.length > 0 || queue.rewards.length > 0;
  }

  function schedule() {
    if (timer !== null) {
      return;
    }
    timer = setTimeout(() => {
      timer = null;
      flush();
    }, 500);
  }

  function takeQueue() {
    const snapshot = queue;
    queue = emptyQueue();
    return snapshot;
  }

  function batchUrl(url) {
    return `${url + (!url.includes('?') ? '?' : '&')}action=batch`;
  }

  function buildPayload(snapshot) {
    const decides = [];
    for (const id in snapshot.decides) {
      if (Object.hasOwn(snapshot.decides, id)) {
        const entry = { id, arms: snapshot.decides[id].arms };
        if (snapshot.decides[id].rank) {
          entry.rank = true;
        }
        decides.push(entry);
      }
    }
    return {
      decides,
      turns: snapshot.turns,
      rewards: snapshot.rewards,
    };
  }

  function resolveDecides(snapshot, decisions) {
    for (const id in snapshot.decides) {
      if (!Object.hasOwn(snapshot.decides, id)) {
        continue;
      }
      const entry = snapshot.decides[id];
      const decision = (decisions && decisions[id]) || {};
      const armId = decision.armId || entry.arms[0];
      let ranking;
      if (Array.isArray(decision.ranking) && decision.ranking.length) {
        ranking = decision.ranking;
      }
      else {
        ranking = entry.arms.slice();
        const winnerIdx = ranking.indexOf(armId);
        if (winnerIdx > 0) {
          ranking.splice(winnerIdx, 1);
          ranking.unshift(armId);
        }
      }

      entry.resolvers.forEach((resolve) => {
        resolve(armId);
      });
      entry.rankResolvers.forEach((resolve) => {
        resolve(ranking);
      });
    }
  }

  function fallbackDecides(snapshot) {
    for (const id in snapshot.decides) {
      if (!Object.hasOwn(snapshot.decides, id)) {
        continue;
      }
      const entry = snapshot.decides[id];
      const fallback = entry.arms[0];
      const fallbackRanking = entry.arms.slice();
      entry.resolvers.forEach((resolve) => {
        resolve(fallback);
      });
      entry.rankResolvers.forEach((resolve) => {
        resolve(fallbackRanking);
      });
    }
  }

  function flush() {
    if (!hasPending()) {
      return;
    }
    const url = endpoint();
    const snapshot = takeQueue();
    if (!url) {
      fallbackDecides(snapshot);
      return;
    }
    const body = JSON.stringify(buildPayload(snapshot));

    fetch(batchUrl(url), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body,
      credentials: 'same-origin',
      keepalive: true,
    }).then((response) => {
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
        return null;
      }
      return response.json();
    }).then((json) => {
      if (json) {
        reportErrors(json.errors);
        resolveDecides(snapshot, json.decisions || {});
      }
    }).catch(() => {
      fallbackDecides(snapshot);
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
      try {
        Drupal.rl.onErrors(errors);
      }
      catch { /* never let a listener poison the next flush */ }
    }
    if (typeof console !== 'undefined' && typeof console.warn === 'function') {
      errors.forEach((err) => {
        if (!err || typeof err !== 'object') {
          return;
        }
        console.warn(
          `[rl] ${err.kind || 'entry'} for ${err.id || '(no id)'} rejected: ${err.reason || 'unknown'}`,
        );
      });
    }
  }

  function flushBeacon() {
    if (!hasPending()) {
      return;
    }
    const url = endpoint();
    if (!url) {
      return;
    }
    const snapshot = takeQueue();

    // sendBeacon is fire-and-forget: we cannot read the response, so
    // pending decides cannot be fulfilled from this path. Resolve them
    // with the default fallback. The page is navigating away anyway.
    fallbackDecides(snapshot);

    if (snapshot.turns.length === 0 && snapshot.rewards.length === 0) {
      return;
    }
    const body = JSON.stringify({
      decides: [],
      turns: snapshot.turns,
      rewards: snapshot.rewards,
    });

    if ('sendBeacon' in navigator) {
      const blob = new Blob([body], { type: 'application/json' });
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
    decide(experimentId, armIds) {
      if (!Array.isArray(armIds) || armIds.length < 2) {
        return Promise.reject(new Error('Drupal.rl.decide requires an array of at least 2 arm ids'));
      }
      return new Promise((resolve) => {
        let entry = queue.decides[experimentId];
        if (!entry) {
          entry = queue.decides[experimentId] = {
            arms: armIds.slice(),
            rank: false,
            resolvers: [],
            rankResolvers: [],
          };
        }
        entry.resolvers.push(resolve);
        schedule();
      });
    },

    /**
     * Request a full Thompson Sampling ranking for an experiment.
     *
     * Returns all arm IDs sorted by Thompson Sampling score (best
     * first). The same batching, deduplication, and fallback rules
     * as decide() apply. When both decide() and rank() are called
     * for the same experiment in the same flush cycle, they share
     * the same batch entry: the first caller's arm list wins (the
     * second caller's armIds are validated but not merged). Decide
     * callers receive the top arm; rank callers receive the full
     * sorted list.
     *
     * @param {string} experimentId
     *   The pre-registered experiment id.
     * @param {Array<string>} armIds
     *   The arm ids in play. Minimum 2.
     *
     * @return {Promise<Array<string>>}
     *   Resolves to all arm ids sorted by Thompson Sampling score
     *   (best first). Falls back to the caller-provided order on
     *   any failure.
     */
    rank(experimentId, armIds) {
      if (!Array.isArray(armIds) || armIds.length < 2) {
        return Promise.reject(new Error('Drupal.rl.rank requires an array of at least 2 arm ids'));
      }
      return new Promise((resolve) => {
        let entry = queue.decides[experimentId];
        if (!entry) {
          entry = queue.decides[experimentId] = {
            arms: armIds.slice(),
            rank: true,
            resolvers: [],
            rankResolvers: [],
          };
        }
        entry.rank = true;
        entry.rankResolvers.push(resolve);
        schedule();
      });
    },

    /**
     * Record an impression for a variant.
     *
     * @param {string} experimentId
     * @param {string} armId
     */
    turn(experimentId, armId) {
      queue.turns.push({ id: experimentId, arm: armId });
      schedule();
    },

    /**
     * Record a conversion for a variant.
     *
     * @param {string} experimentId
     * @param {string} armId
     */
    reward(experimentId, armId) {
      queue.rewards.push({ id: experimentId, arm: armId });
      schedule();
    },

    /**
     * Force an immediate flush of the buffered queue.
     */
    flush() {
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
  // Pending decides are resolved with the fallback arm - the page is
  // going away so the answer no longer matters.
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      flushBeacon();
    }
  });
  window.addEventListener('pagehide', flushBeacon);
})(Drupal, drupalSettings);
