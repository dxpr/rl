/**
 * @file
 * Client-side tracking for RL Menu Link experiments.
 *
 * Records a turn (impression) when a tracked menu link enters the viewport,
 * and a reward when the user clicks the link. Tracked anchors are identified
 * by data-rl-ml-experiment-id and data-rl-ml-arm-id attributes injected by
 * the preprocess_menu hook.
 *
 * Reward dedupe key includes the arm ID, so when Thompson Sampling rotates
 * the variant on a later visit in the same session the new arm still gets
 * credit on the next click.
 */

(function (Drupal, drupalSettings, once) {

  'use strict';

  Drupal.behaviors.rlMenuLinkTracking = {
    attach: function (context) {
      if (!drupalSettings.rlMenuLink || !drupalSettings.rlMenuLink.rlEndpointUrl) {
        return;
      }

      var endpointUrl = drupalSettings.rlMenuLink.rlEndpointUrl;
      var anchors = once('rl-menu-link-tracking', 'a[data-rl-ml-experiment-id]', context);

      anchors.forEach(function (anchor) {
        var experimentId = anchor.getAttribute('data-rl-ml-experiment-id');
        var armId = anchor.getAttribute('data-rl-ml-arm-id');
        if (!experimentId || !armId) {
          return;
        }

        // Turn tracking via IntersectionObserver.
        if ('IntersectionObserver' in window) {
          var observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
              if (entry.isIntersecting && !entry.target.dataset.rlMlTracked) {
                entry.target.dataset.rlMlTracked = '1';
                _send('turn', experimentId, armId);
              }
            });
          }, { threshold: 0.1 });
          observer.observe(anchor);
        }
        else {
          _send('turn', experimentId, armId);
        }

        // Reward tracking on click.
        anchor.addEventListener('click', function () {
          var rewardKey = 'rl-ml-reward-' + experimentId + '-' + armId;
          if (sessionStorage.getItem(rewardKey)) {
            return;
          }
          sessionStorage.setItem(rewardKey, '1');
          _send('reward', experimentId, armId);
        });
      });

      function _send(action, experimentId, armId) {
        var data = new FormData();
        data.append('action', action);
        data.append('experiment_id', experimentId);
        data.append('arm_id', armId);
        navigator.sendBeacon(endpointUrl, data);
      }
    }
  };

})(Drupal, drupalSettings, once);
