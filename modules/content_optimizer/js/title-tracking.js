/**
 * @file
 * Client-side tracking for Content Optimizer page title experiments.
 *
 * Records a turn (impression) on page load, and a reward based on the
 * configured strategy (time on page, scroll depth, or next click).
 */

(function (Drupal, drupalSettings, once) {

  'use strict';

  Drupal.behaviors.contentOptimizerTitleTracking = {
    attach: function (context) {
      if (!drupalSettings.contentOptimizer) {
        return;
      }

      once('content-optimizer-title', 'body', context).forEach(function () {
        var settings = drupalSettings.contentOptimizer;
        var experimentId = settings.experimentId;
        var armId = settings.armId;
        var endpointUrl = settings.rlEndpointUrl;
        var strategy = settings.rewardStrategy || 'time_on_page';
        var threshold = settings.rewardThreshold || 10000;

        // Record turn (impression): this variant was shown.
        var turnData = new FormData();
        turnData.append('action', 'turn');
        turnData.append('experiment_id', experimentId);
        turnData.append('arm_id', armId);
        navigator.sendBeacon(endpointUrl, turnData);

        // Reward tracking based on strategy.
        var rewardKey = 'co-reward-' + experimentId;

        function sendReward() {
          if (sessionStorage.getItem(rewardKey)) {
            return;
          }
          sessionStorage.setItem(rewardKey, '1');
          var rewardData = new FormData();
          rewardData.append('action', 'reward');
          rewardData.append('experiment_id', experimentId);
          rewardData.append('arm_id', armId);
          navigator.sendBeacon(endpointUrl, rewardData);
        }

        if (strategy === 'time_on_page') {
          // Reward if user stays for the configured threshold.
          setTimeout(sendReward, threshold);
        }
        else if (strategy === 'scroll_depth') {
          // Reward if user scrolls past 50% of the page.
          var scrollHandler = function () {
            var scrollPercent = (window.scrollY + window.innerHeight) / document.documentElement.scrollHeight;
            if (scrollPercent >= 0.5) {
              sendReward();
              window.removeEventListener('scroll', scrollHandler);
            }
          };
          window.addEventListener('scroll', scrollHandler, { passive: true });
        }
        else if (strategy === 'next_click') {
          // Reward on any link click on the page.
          var clickHandler = function (e) {
            var link = e.target.closest('a');
            if (link && link.href) {
              sendReward();
              document.removeEventListener('click', clickHandler);
            }
          };
          document.addEventListener('click', clickHandler);
        }
      });
    }
  };

})(Drupal, drupalSettings, once);
