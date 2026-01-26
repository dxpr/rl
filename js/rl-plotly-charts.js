(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.rlPlotlyCharts = {
    attach: function (context, settings) {
      if (!settings.rlPlotly) {
        return;
      }

      var containers = once('rl-plotly-charts', '.rl-plotly-container', context);
      if (!containers.length) {
        return;
      }

      var data = settings.rlPlotly;

      // Small delay to ensure Plotly is ready
      setTimeout(function() {
        initPlotlyCharts(data);
      }, 200);
    }
  };

  /**
   * Get responsive configuration based on screen width.
   */
  function getResponsiveConfig() {
    var width = window.innerWidth;

    if (width <= 430) {
      // iPhone 13 mini and small phones
      return {
        height: 400,
        fontSize: 10,
        titleSize: 13,
        axisTitleSize: 11,
        tickSize: 9,
        margin: { l: 50, r: 30, t: 40, b: 60 },
        camera: { eye: { x: 2.0, y: -2.0, z: 1.2 } },
        colorbarLen: 0.6,
        colorbarThickness: 15,
        maxLabelLength: 20
      };
    } else if (width <= 768) {
      // Tablets portrait
      return {
        height: 500,
        fontSize: 11,
        titleSize: 14,
        axisTitleSize: 12,
        tickSize: 10,
        margin: { l: 60, r: 35, t: 45, b: 80 },
        camera: { eye: { x: 1.8, y: -1.9, z: 1.0 } },
        colorbarLen: 0.7,
        colorbarThickness: 18,
        maxLabelLength: 30
      };
    } else if (width <= 1200) {
      // Tablets landscape / small laptops
      return {
        height: 600,
        fontSize: 12,
        titleSize: 15,
        axisTitleSize: 13,
        tickSize: 11,
        margin: { l: 70, r: 40, t: 50, b: 90 },
        camera: { eye: { x: 1.7, y: -1.8, z: 0.95 } },
        colorbarLen: 0.75,
        colorbarThickness: 20,
        maxLabelLength: 40
      };
    } else if (width <= 1920) {
      // Standard desktop / Full HD
      return {
        height: 700,
        fontSize: 13,
        titleSize: 16,
        axisTitleSize: 14,
        tickSize: 11,
        margin: { l: 80, r: 40, t: 50, b: 100 },
        camera: { eye: { x: 1.6, y: -1.8, z: 0.9 } },
        colorbarLen: 0.8,
        colorbarThickness: 20,
        maxLabelLength: 50
      };
    } else {
      // 4K and large displays
      return {
        height: 900,
        fontSize: 14,
        titleSize: 18,
        axisTitleSize: 15,
        tickSize: 12,
        margin: { l: 100, r: 50, t: 60, b: 120 },
        camera: { eye: { x: 1.5, y: -1.7, z: 0.85 } },
        colorbarLen: 0.85,
        colorbarThickness: 25,
        maxLabelLength: 60
      };
    }
  }

  /**
   * Truncate label to max length.
   */
  function truncateLabel(label, maxLen) {
    if (!label) return 'Unknown';
    if (label.length <= maxLen) return label;
    return label.substring(0, maxLen - 3) + '...';
  }

  function initPlotlyCharts(data) {
    var config = getResponsiveConfig();

    var defaultLayout = {
      paper_bgcolor: 'rgba(255,255,255,1)',
      plot_bgcolor: 'rgba(255,255,255,1)',
      font: { size: config.fontSize, family: 'Arial, sans-serif' },
      margin: config.margin
    };

    // 1. 3D Posterior Landscape (loss-landscape style)
    var surface3dEl = document.getElementById('rl-plotly-3d-surface');
    if (data.surface3d && data.surface3d.zMatrix && data.surface3d.zMatrix.length > 0 && surface3dEl) {
      try {
        var numArms = data.surface3d.zMatrix.length;
        var numTimePoints = data.surface3d.xValues.length;
        var armIndices = [];
        for (var i = 0; i < numArms; i++) {
          armIndices.push(i);
        }

        // Build text array for human-readable arm labels in tooltips
        var armLabels = data.surface3d.armLabels || [];
        var textData = [];
        var truncatedLabels = [];

        for (var ai = 0; ai < numArms; ai++) {
          var labelRow = [];
          var fullLabel = armLabels[ai] || ('Arm #' + ai);
          var displayLabel = truncateLabel(fullLabel, config.maxLabelLength);
          truncatedLabels.push(displayLabel);

          for (var ti = 0; ti < numTimePoints; ti++) {
            labelRow.push(fullLabel);
          }
          textData.push(labelRow);
        }

        // Configure Y-axis based on number of arms
        var yAxisConfig = {
          title: { text: numArms <= 10 ? 'Variant' : 'Arm Index', font: { size: config.axisTitleSize } },
          tickfont: { size: config.tickSize }
        };

        // Show arm labels on Y-axis for small number of arms
        if (numArms <= 10) {
          yAxisConfig.tickvals = armIndices;
          yAxisConfig.ticktext = truncatedLabels;
          yAxisConfig.tickangle = 0;
        }

        Plotly.newPlot('rl-plotly-3d-surface', [{
          type: 'surface',
          z: data.surface3d.zMatrix,
          x: data.surface3d.xValues,
          y: armIndices,
          text: textData,
          hoverinfo: 'text',
          hovertemplate: '<b>%{text}</b><br>Turn: %{x}<br>Rate: %{z:.1f}%<extra></extra>',
          colorscale: [
            [0, 'rgb(68, 1, 84)'],
            [0.1, 'rgb(72, 35, 116)'],
            [0.2, 'rgb(64, 67, 135)'],
            [0.3, 'rgb(52, 94, 141)'],
            [0.4, 'rgb(41, 120, 142)'],
            [0.5, 'rgb(32, 144, 140)'],
            [0.6, 'rgb(34, 167, 132)'],
            [0.7, 'rgb(68, 190, 112)'],
            [0.8, 'rgb(121, 209, 81)'],
            [0.9, 'rgb(189, 222, 38)'],
            [1, 'rgb(253, 231, 36)']
          ],
          contours: {
            z: {
              show: true,
              usecolormap: true,
              highlightcolor: '#ffffff',
              project: { z: false }
            },
            x: { show: false },
            y: { show: false }
          },
          lighting: {
            ambient: 0.6,
            diffuse: 0.8,
            specular: 0.3,
            roughness: 0.5,
            fresnel: 0.2
          },
          lightposition: {
            x: 100,
            y: 200,
            z: 100
          },
          colorbar: {
            title: { text: 'Rate %', side: 'right', font: { size: config.axisTitleSize } },
            thickness: config.colorbarThickness,
            len: config.colorbarLen
          }
        }], Object.assign({}, defaultLayout, {
          title: { text: 'Posterior Landscape: ' + numArms + ' Arms Over Time', font: { size: config.titleSize } },
          scene: {
            xaxis: {
              title: { text: 'Experiment Turns', font: { size: config.axisTitleSize } },
              tickfont: { size: config.tickSize }
            },
            yaxis: yAxisConfig,
            zaxis: {
              title: { text: 'Conversion Rate (%)', font: { size: config.axisTitleSize } },
              tickfont: { size: config.tickSize }
            },
            camera: {
              eye: config.camera.eye,
              center: { x: 0, y: 0, z: -0.1 }
            },
            aspectratio: { x: 1.5, y: 1, z: 0.8 }
          },
          height: config.height
        }), { responsive: true });
      } catch (e) {
        console.error('3D posterior landscape error:', e);
      }
    }

    // 2. 3D Stacked Ridgelines
    var ridgelinesEl = document.getElementById('rl-plotly-ridgelines');
    if (data.ridgelineData && data.ridgelineData.arms && data.ridgelineData.arms.length > 0 && ridgelinesEl) {
      try {
        var traces = [];
        var numArms = data.ridgelineData.arms.length;

        // Limit visible arms for ridgelines (too many makes it unreadable)
        var maxRidgelineArms = Math.min(numArms, 30);
        var armLabelsRidge = [];
        var armIndicesRidge = [];

        for (var idx = 0; idx < maxRidgelineArms; idx++) {
          var arm = data.ridgelineData.arms[idx];
          var armLabel = arm.label || ('Arm #' + idx);
          var truncatedLabel = truncateLabel(armLabel, config.maxLabelLength);
          armLabelsRidge.push(truncatedLabel);
          armIndicesRidge.push(idx);

          // Create a surface for each arm offset in the Y direction
          var zData = [];
          var xData = [];
          var yData = [];

          // Create two rows for each arm to form a ribbon
          for (var row = 0; row < 2; row++) {
            var zRow = [];
            var xRow = [];
            var yRow = [];
            arm.data.forEach(function(point) {
              xRow.push(point.x);
              yRow.push(idx + row * 0.1);
              zRow.push(point.y);
            });
            xData.push(xRow);
            yData.push(yRow);
            zData.push(zRow);
          }

          traces.push({
            type: 'surface',
            x: xData,
            y: yData,
            z: zData,
            colorscale: [[0, arm.color], [1, arm.color]],
            showscale: false,
            opacity: 0.85,
            name: truncatedLabel,
            hovertemplate: '<b>' + armLabel + '</b><br>Turn: %{x}<br>Rate: %{z:.1f}%<extra></extra>'
          });
        }

        // Configure Y-axis based on number of arms
        var yAxisConfigRidge = {
          title: { text: maxRidgelineArms <= 10 ? 'Variant' : 'Arm Index', font: { size: config.axisTitleSize } },
          tickfont: { size: config.tickSize }
        };

        // Show arm labels on Y-axis for small number of arms
        if (maxRidgelineArms <= 10) {
          yAxisConfigRidge.tickvals = armIndicesRidge;
          yAxisConfigRidge.ticktext = armLabelsRidge;
          yAxisConfigRidge.tickangle = 0;
        }

        Plotly.newPlot('rl-plotly-ridgelines', traces, Object.assign({}, defaultLayout, {
          title: { text: 'Showing ' + maxRidgelineArms + ' of ' + numArms + ' arms', font: { size: config.titleSize, color: '#666' } },
          scene: {
            xaxis: { title: { text: 'Experiment Turns', font: { size: config.axisTitleSize } }, tickfont: { size: config.tickSize } },
            yaxis: yAxisConfigRidge,
            zaxis: { title: { text: 'Conversion Rate (%)', font: { size: config.axisTitleSize } }, tickfont: { size: config.tickSize } },
            camera: { eye: { x: config.camera.eye.x - 0.1, y: config.camera.eye.y + 0.3, z: config.camera.eye.z - 0.1 } }
          },
          height: config.height - 50,
          showlegend: false
        }), { responsive: true });
      } catch (e) {
        console.error('Plotly ridgelines error:', e);
      }
    }
  }

  // Debounced resize handler
  var resizeTimeout;
  window.addEventListener('resize', function() {
    clearTimeout(resizeTimeout);
    resizeTimeout = setTimeout(function() {
      var data = drupalSettings.rlPlotly;
      if (data) {
        initPlotlyCharts(data);
      }
    }, 300);
  });

})(Drupal, drupalSettings, once);
