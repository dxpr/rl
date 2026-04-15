/**
 * @file
 * Tracking for the rl_example_frontend newsletter block.
 *
 * The winning variant is picked server-side in NewsletterBlock::build()
 * and the button is rendered with the winning text already in place. This
 * file only needs to report the impression when the form enters the
 * viewport and the conversion when the user clicks the submit button.
 * Both events go through Drupal.rl, which batches them with any other RL
 * calls on the page.
 */

(function (Drupal, drupalSettings, once) {

  'use strict';

  Drupal.behaviors.rlExampleFrontendTracking = {
    attach: function (context) {
      var config = drupalSettings.rlExampleFrontend;
      if (!config || !config.experimentId || !config.armId) {
        return;
      }

      once('rl-frontend-ab', '.rl-example-frontend-newsletter-form', context).forEach(function (form) {
        var observer = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              Drupal.rl.turn(config.experimentId, config.armId);
              observer.disconnect();
            }
          });
        }, { threshold: 0.5 });
        observer.observe(form);

        var submitButton = form.querySelector('input[type="submit"]');
        if (submitButton) {
          submitButton.addEventListener('click', function () {
            Drupal.rl.reward(config.experimentId, config.armId);
          });
        }
      });
    },
  };

})(Drupal, drupalSettings, once);
