/**
 * @file
 * Client-side tracking for RL Page Title experiments.
 *
 * Records a turn on page load and a reward after the user has stayed on the
 * page for 10 seconds (a bounce-rate proxy).
 *
 * Each page load records exactly one turn and (if the user stays long
 * enough) one reward. There is no per-session dedupe: every visit is its
 * own event. That keeps the conversion rate observed by Thompson Sampling
 * unbiased across repeated visits.
 *
 * Per-page-load dedupe is provided by once() and a window-scoped flag so we
 * cannot record more than one event for the same arm on the same page load
 * even if Drupal.attachBehaviors is invoked multiple times.
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.rlPageTitleTracking = {
    attach(context) {
      if (!drupalSettings.rlPageTitle) {
        return;
      }

      once('rl-page-title-tracking', 'body', context).forEach(() => {
        const settings = drupalSettings.rlPageTitle;
        const experimentId = settings.experimentId;
        const armId = settings.armId;

        Drupal.rl.turn(experimentId, armId);

        // Record reward after 10 seconds (bounce-rate proxy). No
        // sessionStorage gate: every page load that crosses the threshold
        // emits a reward, which is the correct signal for Thompson Sampling.
        // The window-scoped flag only prevents duplicate rewards from the
        // same page load (e.g., if attachBehaviors fires twice).
        const pageLoadFlag = `__rl_pt_rewarded_${experimentId}_${armId}`;
        setTimeout(() => {
          if (window[pageLoadFlag]) {
            return;
          }
          window[pageLoadFlag] = true;
          Drupal.rl.reward(experimentId, armId);
        }, 10000);
      });
    },
  };
})(Drupal, drupalSettings, once);
