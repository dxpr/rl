(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Tracks when the newsletter form becomes visible in the viewport.
   * This is critical for forms in footers that aren't immediately visible.
   */
  Drupal.behaviors.rlExampleViewportTracking = {
    attach: function (context, settings) {
      // Only proceed if we have the necessary settings
      if (!settings.rlExample || !settings.rlExample.tracking) {
        return;
      }

      const trackingData = settings.rlExample.tracking;

      // Use once() to ensure we only process each form once
      once('rl-example-viewport', '.rl-example-newsletter-form', context).forEach(function(form) {
        // Create an IntersectionObserver to detect when form enters viewport
        const observer = new IntersectionObserver(function(entries) {
          entries.forEach(function(entry) {
            if (entry.isIntersecting) {
              // Form is now visible - record the turn
              const formData = new FormData();
              formData.append('action', 'turns');
              formData.append('experiment_id', trackingData.experimentId);
              formData.append('arm_ids', trackingData.armId);
              
              // Send the turn signal
              navigator.sendBeacon(trackingData.rlEndpointUrl, formData);
              
              // Disconnect observer after recording turn
              observer.disconnect();
            }
          });
        }, {
          // Trigger when 100% of the form is visible
          threshold: 1.0
        });

        // Start observing the form
        observer.observe(form);
      });

      // Example: Reward tracking via JavaScript (commented out)
      // In this module, we handle rewards in the PHP submitCallback instead.
      // Uncomment and modify this if you need client-side reward tracking:
      //
      // once('rl-example-reward', '.rl-example-newsletter-form button[type="submit"]', context).forEach(function(button) {
      //   button.addEventListener('click', function(e) {
      //     // Send reward signal to RL
      //     const rewardData = new FormData();
      //     rewardData.append('action', 'reward');
      //     rewardData.append('experiment_id', trackingData.experimentId);
      //     rewardData.append('arm_id', trackingData.armId);
      //     
      //     navigator.sendBeacon(trackingData.rlEndpointUrl, rewardData);
      //   });
      // });
    }
  };

})(Drupal, drupalSettings, once);