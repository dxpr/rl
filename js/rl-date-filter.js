(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.rlDateFilter = {
    attach: function (context) {
      const selects = once('rl-date-filter', '.rl-filter-select', context);

      selects.forEach(function (select) {
        select.addEventListener('change', function () {
          const urls = JSON.parse(this.dataset.urls);
          const url = urls[this.value];

          // Find the chart box and add loading overlay
          const chartBox = this.closest('.rl-chart-box');
          if (chartBox) {
            const loader = document.createElement('div');
            loader.className = 'rl-chart-loading';
            chartBox.appendChild(loader);
          }

          // Navigate after a brief delay to show the spinner
          setTimeout(function () {
            window.location.href = url;
          }, 50);
        });
      });
    }
  };

})(Drupal, once);
