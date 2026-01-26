(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.rlDateFilter = {
    attach: function (context) {
      const selects = once('rl-date-filter', '.rl-filter-select', context);

      selects.forEach(function (select) {
        select.addEventListener('change', function () {
          const urls = JSON.parse(this.dataset.urls);
          const url = urls[this.value];

          // Find visible chart boxes and add loading overlay
          const chartBoxes = document.querySelectorAll('.rl-chart-box');
          chartBoxes.forEach(function (chartBox) {
            if (chartBox.offsetParent !== null) {
              const loader = document.createElement('div');
              loader.className = 'rl-chart-loading';
              chartBox.appendChild(loader);
            }
          });

          // Navigate after a brief delay to show the spinner
          setTimeout(function () {
            window.location.href = url;
          }, 50);
        });
      });
    }
  };

})(Drupal, once);
