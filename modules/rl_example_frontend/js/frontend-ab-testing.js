/**
 * @file
 * Frontend A/B testing for newsletter signup button.
 *
 * Uses Drupal.rl as the transport layer: one decide call resolves the
 * winning variant, one turn call fires when the form enters the viewport,
 * one reward call fires when the submit button is clicked. Drupal.rl
 * coalesces all of these with any other RL calls on the page into a
 * single POST.
 */

(function (Drupal, drupalSettings, once) {

  'use strict';

  Drupal.behaviors.rlExampleFrontendABTesting = {
    attach: function (context) {
      var config = drupalSettings.rlExampleFrontend;
      if (!config) {
        return;
      }

      once('rl-frontend-ab', '.rl-example-frontend-newsletter-form', context).forEach(function (form) {
        Drupal.rl.decide(config.experimentId, Object.keys(config.buttonTexts))
          .then(function (armId) {
            if (!config.buttonTexts[armId]) {
              return;
            }
            var submitButton = form.querySelector('input[type="submit"]');
            if (submitButton) {
              submitButton.value = config.buttonTexts[armId];
            }
            observeTurn(form, config.experimentId, armId);
            bindReward(form, config.experimentId, armId);
          })
          .catch(function () {
            // Leave the default button text in place on decide failure.
          });
      });

      function observeTurn(form, experimentId, armId) {
        var observer = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              Drupal.rl.turn(experimentId, armId);
              observer.disconnect();
            }
          });
        }, { threshold: 0.5 });
        observer.observe(form);
      }

      function bindReward(form, experimentId, armId) {
        var submitButton = form.querySelector('input[type="submit"]');
        if (!submitButton) {
          return;
        }
        submitButton.addEventListener('click', function () {
          Drupal.rl.reward(experimentId, armId);
        });
      }
    },
  };

})(Drupal, drupalSettings, once);
