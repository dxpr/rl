/**
 * @file
 * Client-side tracking for RL Menu Link experiments.
 *
 * Records a turn when a tracked menu link enters the viewport and a reward
 * when the user clicks the link. Tracked anchors are identified by
 * data-rl-ml-experiment-id and data-rl-ml-arm-id attributes injected by the
 * preprocess_menu hook.
 *
 * Each click on a tracked link records a reward. There is no per-session
 * cap so repeated clicks during the same session each count - this keeps
 * the conversion signal Thompson Sampling sees unbiased across visits.
 *
 * Per-page-load dedupe is provided by once() and a per-anchor data attribute
 * to prevent the same impression or click from firing multiple times within
 * a single page load (e.g., from repeated attachBehaviors invocations).
 */

(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.rlMenuLinkTracking = {
    attach(context) {
      const anchors = once('rl-menu-link-tracking', 'a[data-rl-ml-experiment-id]', context);

      anchors.forEach((anchor) => {
        const experimentId = anchor.getAttribute('data-rl-ml-experiment-id');
        const armId = anchor.getAttribute('data-rl-ml-arm-id');
        if (!experimentId || !armId) {
          return;
        }

        // Turn tracking via IntersectionObserver. The data attribute prevents
        // multiple turns for the same anchor in a single page load.
        if ('IntersectionObserver' in window) {
          const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
              if (entry.isIntersecting && !entry.target.dataset.rlMlTracked) {
                entry.target.dataset.rlMlTracked = '1';
                Drupal.rl.turn(experimentId, armId);
              }
            });
          }, { threshold: 0.1 });
          observer.observe(anchor);
        }
        else {
          Drupal.rl.turn(experimentId, armId);
        }

        // Reward tracking on click. No sessionStorage cap; each click is a
        // valid reward signal. The data attribute prevents double-counting
        // a single click event.
        anchor.addEventListener('click', () => {
          if (anchor.dataset.rlMlClicked) {
            return;
          }
          anchor.dataset.rlMlClicked = '1';
          Drupal.rl.reward(experimentId, armId);
          // Clear the flag after the click event finishes propagating, so a
          // second click later in the same page load can also be recorded.
          window.setTimeout(() => {
            delete anchor.dataset.rlMlClicked;
          }, 0);
        });
      });
    },
  };
})(Drupal, drupalSettings, once);
