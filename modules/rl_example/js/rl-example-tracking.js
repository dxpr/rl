/**
 * @file
 * Records a turn for the newsletter form when it enters the viewport.
 *
 * Critical for forms in footers that are not immediately visible. The
 * reward is recorded server-side in the AJAX submit callback; this file
 * only needs to register the impression.
 */

(function (Drupal, drupalSettings, once) {

  'use strict';

  Drupal.behaviors.rlExampleViewportTracking = {
    attach: function (context) {
      var tracking = drupalSettings.rlExample && drupalSettings.rlExample.tracking;
      if (!tracking) {
        return;
      }

      once('rl-example-viewport', '.rl-example-newsletter-form', context).forEach(function (form) {
        var observer = new IntersectionObserver(function (entries) {
          entries.forEach(function (entry) {
            if (entry.isIntersecting) {
              Drupal.rl.turn(tracking.experimentId, tracking.armId);
              observer.disconnect();
            }
          });
        }, { threshold: 1.0 });
        observer.observe(form);
      });
    },
  };

})(Drupal, drupalSettings, once);
