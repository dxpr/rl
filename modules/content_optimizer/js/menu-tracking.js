/**
 * @file
 * Client-side tracking for Content Optimizer menu link experiments.
 *
 * Uses IntersectionObserver for impressions and click listeners for rewards,
 * following the same pattern as ai_sorting.
 */

(function (Drupal, drupalSettings, once) {

  'use strict';

  Drupal.behaviors.contentOptimizerMenuTracking = {
    attach: function (context) {
      if (!drupalSettings.contentOptimizerMenu || !drupalSettings.contentOptimizerMenu.experiments) {
        return;
      }

      var experiments = drupalSettings.contentOptimizerMenu.experiments;
      var endpointUrl = drupalSettings.contentOptimizerMenu.rlEndpointUrl;

      once('content-optimizer-menu', 'nav a, .menu a', context).forEach(function (link) {
        var href = link.getAttribute('href');
        if (!href) {
          return;
        }

        // Find matching experiment for this link.
        var matched = null;
        for (var i = 0; i < experiments.length; i++) {
          var exp = experiments[i];
          if (href.indexOf('/node/' + exp.entityId) !== -1 ||
              href.indexOf('/' + exp.entityId) !== -1) {
            matched = exp;
            break;
          }
        }

        if (!matched) {
          return;
        }

        // Turn tracking via IntersectionObserver.
        var turnObserver = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting && !entry.target.dataset.coTracked) {
              entry.target.dataset.coTracked = '1';
              var turnData = new FormData();
              turnData.append('action', 'turn');
              turnData.append('experiment_id', matched.experimentId);
              turnData.append('arm_id', matched.armId);
              navigator.sendBeacon(endpointUrl, turnData);
            }
          });
        }, { threshold: 0.1 });

        turnObserver.observe(link);

        // Reward tracking on click.
        link.addEventListener('click', function () {
          var rewardKey = 'co-menu-reward-' + matched.experimentId;
          if (!sessionStorage.getItem(rewardKey)) {
            sessionStorage.setItem(rewardKey, '1');
            var rewardData = new FormData();
            rewardData.append('action', 'reward');
            rewardData.append('experiment_id', matched.experimentId);
            rewardData.append('arm_id', matched.armId);
            navigator.sendBeacon(endpointUrl, rewardData);
          }
        });
      });
    }
  };

})(Drupal, drupalSettings, once);
