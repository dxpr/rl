/**
 * @file
 * Client-side tracking for RL Page Title experiments.
 *
 * Records a turn (impression) on page load and a reward after the user has
 * stayed on the page for 10 seconds (a bounce-rate proxy). The reward dedupe
 * key includes the arm ID, so when Thompson Sampling rotates to a different
 * variant on a later visit in the same session the new arm still gets credit.
 */

(function (Drupal, drupalSettings, once) {

  'use strict';

  Drupal.behaviors.rlPageTitleTracking = {
    attach: function (context) {
      if (!drupalSettings.rlPageTitle) {
        return;
      }

      // Use once() to ensure we only attach per page load (per body element).
      once('rl-page-title-tracking', 'body', context).forEach(function () {
        var settings = drupalSettings.rlPageTitle;
        var experimentId = settings.experimentId;
        var armId = settings.armId;
        var endpointUrl = settings.rlEndpointUrl;

        // Record turn (impression).
        var turnData = new FormData();
        turnData.append('action', 'turn');
        turnData.append('experiment_id', experimentId);
        turnData.append('arm_id', armId);
        navigator.sendBeacon(endpointUrl, turnData);

        // Record reward after 10 seconds (bounce-rate proxy). Dedupe key
        // includes the arm so a later visit with a different arm still
        // sends a reward for that arm.
        var rewardKey = 'rl-pt-reward-' + experimentId + '-' + armId;
        setTimeout(function () {
          if (sessionStorage.getItem(rewardKey)) {
            return;
          }
          sessionStorage.setItem(rewardKey, '1');
          var rewardData = new FormData();
          rewardData.append('action', 'reward');
          rewardData.append('experiment_id', experimentId);
          rewardData.append('arm_id', armId);
          navigator.sendBeacon(endpointUrl, rewardData);
        }, 10000);
      });
    }
  };

})(Drupal, drupalSettings, once);
