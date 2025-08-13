(function (Drupal, drupalSettings, once) {
  'use strict';

  /**
   * Frontend A/B testing for newsletter signup button.
   * 
   * This approach eliminates cache complexity by:
   * 1. Fetching Thompson sampling scores via AJAX on page load
   * 2. Overriding button text based on best performing arm
   * 3. Tracking turns via IntersectionObserver 
   * 4. Tracking rewards via click handlers
   */
  Drupal.behaviors.rlExampleFrontendABTesting = {
    attach: function (context, settings) {
      // Only proceed if we have the necessary settings
      if (!settings.rlExampleFrontend) {
        return;
      }

      const config = settings.rlExampleFrontend;
      let selectedArmId = null;

      // Use once() to ensure we only process each form once
      once('rl-frontend-ab', '.rl-example-frontend-newsletter-form', context).forEach(function(form) {
        
        // Step 1: Get Thompson sampling scores and select best arm
        fetchThompsonScores(config.experimentId, Object.keys(config.buttonTexts))
          .then(function(scores) {
            // Find the arm with highest score
            let bestScore = -1;
            let bestArmId = null;
            
            for (const armId in scores) {
              if (scores[armId] > bestScore) {
                bestScore = scores[armId];
                bestArmId = armId;
              }
            }
            
            if (bestArmId && config.buttonTexts[bestArmId]) {
              selectedArmId = bestArmId;
              
              // Step 2: Override button text with selected variation
              const submitButton = form.querySelector('input[type="submit"]');
              if (submitButton) {
                submitButton.value = config.buttonTexts[bestArmId];
              }
              
              // Step 3: Set up intersection observer for turn tracking
              setupTurnTracking(form, config, selectedArmId);
              
              // Step 4: Set up click handler for reward tracking
              setupRewardTracking(form, config, selectedArmId);
            }
          })
          .catch(function(error) {
            console.warn('RL Frontend A/B: Failed to fetch scores, using fallback:', error);
            // Fallback: use first available button text
            const fallbackArmId = Object.keys(config.buttonTexts)[0];
            selectedArmId = fallbackArmId;
            
            const submitButton = form.querySelector('input[type="submit"]');
            if (submitButton) {
              submitButton.value = config.buttonTexts[fallbackArmId];
            }
            
            setupTurnTracking(form, config, selectedArmId);
            setupRewardTracking(form, config, selectedArmId);
          });
      });

      /**
       * Fetch Thompson sampling scores from RL system
       */
      function fetchThompsonScores(experimentId, armIds) {
        return new Promise(function(resolve, reject) {
          // Make AJAX request to get Thompson sampling scores
          const xhr = new XMLHttpRequest();
          xhr.open('POST', config.rlEndpointUrl);
          xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
          
          xhr.onload = function() {
            if (xhr.status === 200) {
              try {
                const response = JSON.parse(xhr.responseText);
                if (response.scores) {
                  resolve(response.scores);
                } else {
                  reject('No scores in response');
                }
              } catch (e) {
                reject('Invalid JSON response');
              }
            } else {
              reject('HTTP ' + xhr.status);
            }
          };
          
          xhr.onerror = function() {
            reject('Network error');
          };
          
          // Send request for Thompson scores
          const params = 'action=scores&experiment_uuid=' + encodeURIComponent(experimentId) + 
                        '&arm_ids=' + encodeURIComponent(armIds.join(','));
          xhr.send(params);
        });
      }

      /**
       * Set up intersection observer for turn tracking
       */
      function setupTurnTracking(form, config, armId) {
        const observer = new IntersectionObserver(function(entries) {
          entries.forEach(function(entry) {
            if (entry.isIntersecting) {
              // Form is now visible - record the turn
              const formData = new FormData();
              formData.append('action', 'turns');
              formData.append('experiment_uuid', config.experimentId);
              formData.append('arm_ids', armId);
              
              // Send the turn signal
              if (navigator.sendBeacon) {
                navigator.sendBeacon(config.rlEndpointUrl, formData);
              } else {
                // Fallback for browsers without sendBeacon
                const xhr = new XMLHttpRequest();
                xhr.open('POST', config.rlEndpointUrl, true);
                xhr.send(formData);
              }
              
              // Disconnect observer after recording turn
              observer.disconnect();
            }
          });
        }, {
          // Trigger when 50% of the form is visible
          threshold: 0.5
        });

        // Start observing the form
        observer.observe(form);
      }

      /**
       * Set up click handler for reward tracking
       */
      function setupRewardTracking(form, config, armId) {
        const submitButton = form.querySelector('input[type="submit"]');
        if (submitButton) {
          submitButton.addEventListener('click', function(e) {
            // Send reward signal to RL - user clicked the button
            const formData = new FormData();
            formData.append('action', 'rewards');
            formData.append('experiment_uuid', config.experimentId);
            formData.append('arm_id', armId);
            
            // Use sendBeacon for non-blocking send
            if (navigator.sendBeacon) {
              navigator.sendBeacon(config.rlEndpointUrl, formData);
            } else {
              // Fallback for browsers without sendBeacon
              const xhr = new XMLHttpRequest();
              xhr.open('POST', config.rlEndpointUrl, true);
              xhr.send(formData);
            }
          });
        }
      }
    }
  };

})(Drupal, drupalSettings, once);