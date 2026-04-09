/**
 * @file
 * Client-side tracking for RL Page Title experiments.
 *
 * Records a turn (impression) on page load and a reward after the user has
 * stayed on the page for 10 seconds (a bounce-rate proxy).
 */

(function (Drupal, drupalSettings) {

  'use strict';

  Drupal.behaviors.rlPageTitleTracking = {
    attach: function () {
      if (!drupalSettings.rlPageTitle) {
        return;
      }

      var settings = drupalSettings.rlPageTitle;
      var experimentId = settings.experimentId;
      var armId = settings.armId;
      var endpointUrl = settings.rlEndpointUrl;
      var attachedKey = '__rl_page_title_attached_' + experimentId;

      // Run once per page load (not per Drupal.attachBehaviors call).
      if (window[attachedKey]) {
        return;
      }
      window[attachedKey] = true;

      // Record turn.
      var turnData = new FormData();
      turnData.append('action', 'turn');
      turnData.append('experiment_id', experimentId);
      turnData.append('arm_id', armId);
      navigator.sendBeacon(endpointUrl, turnData);

      // Record reward after 10 seconds (bounce-rate proxy).
      var rewardKey = 'rl-pt-reward-' + experimentId;
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
    }
  };

})(Drupal, drupalSettings);
