(function (Drupal, drupalSettings, once) {
  'use strict';

  Drupal.behaviors.rlPlotlyCharts = {
    attach: function (context, settings) {
      if (!settings.rlPlotly) {
        return;
      }

      const containers = once('rl-plotly-charts', '.rl-plotly-container', context);
      if (!containers.length) {
        return;
      }

      const data = settings.rlPlotly;

      // Small delay to ensure Plotly is ready
      setTimeout(function() {
        initPlotlyCharts(data);
      }, 200);
    }
  };

  /**
   * Get the actual height of a chart container element.
   */
  function getContainerHeight(elementId) {
    const el = document.getElementById(elementId);
    if (el) {
      const height = el.clientHeight || el.offsetHeight;
      // Return at least a minimum height
      return Math.max(height, 200);
    }
    return 400; // Fallback
  }

  /**
   * Get responsive configuration based on screen width.
   * Heights are calculated from container elements, not fixed values.
   */
  function getResponsiveConfig() {
    const width = window.innerWidth;

    // Get actual container heights
    const height3d = getContainerHeight('rl-plotly-3d-surface');
    const height2d = getContainerHeight('rl-plotly-2d-lines');

    if (width <= 430) {
      // iPhone 13 mini and small phones
      return {
        height: height3d,
        height2d: height2d,
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
        height: height3d,
        height2d: height2d,
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
        height: height3d,
        height2d: height2d,
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
        height: height3d,
        height2d: height2d,
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
        height: height3d,
        height2d: height2d,
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
    if (!label && label !== 0) return 'Variant';
    // Convert to string if not already (handles numeric IDs)
    const str = String(label);
    if (str.length <= maxLen) return str;
    return str.substring(0, maxLen - 3) + '...';
  }

  function initPlotlyCharts(data) {
    const config = getResponsiveConfig();

    const defaultLayout = {
      paper_bgcolor: 'rgba(255,255,255,1)',
      plot_bgcolor: 'rgba(255,255,255,1)',
      font: { size: config.fontSize, family: 'Arial, sans-serif' },
      margin: config.margin
    };

    // Determine number of arms and which chart to show
    let numArms = 0;
    if (data.ridgelineData && data.ridgelineData.arms) {
      numArms = data.ridgelineData.arms.length;
    } else if (data.surface3d && data.surface3d.zMatrix) {
      numArms = data.surface3d.zMatrix.length;
    }

    // Chart selection based on arm count (threshold from config or default 10):
    // 1-threshold arms: 2D line chart
    // threshold+1 arms: 3D Posterior Landscape
    const lineChartThreshold = data.chartLineThreshold || 10;
    const showLineChart = numArms >= 1 && numArms <= lineChartThreshold;
    const showLandscape = numArms > lineChartThreshold;

    // Hide unused chart containers
    const lineChartEl = document.getElementById('rl-plotly-2d-lines');
    const surface3dEl = document.getElementById('rl-plotly-3d-surface');

    if (lineChartEl) {
      lineChartEl.parentElement.parentElement.style.display = showLineChart ? 'block' : 'none';
    }
    if (surface3dEl) {
      surface3dEl.parentElement.parentElement.style.display = showLandscape ? 'block' : 'none';
    }

    // 1. 2D Line Chart - Conversion Rate Over Time (1-threshold arms)
    if (showLineChart && data.ridgelineData && data.ridgelineData.arms && data.ridgelineData.arms.length > 0 && lineChartEl) {
      try {
        const traces2d = [];
        const lineChartNumArms = data.ridgelineData.arms.length;
        const maxLineArms = Math.min(lineChartNumArms, 20); // Limit to 20 arms for readability

        for (let idx = 0; idx < maxLineArms; idx++) {
          const arm = data.ridgelineData.arms[idx];
          const armLabel = arm.label || ('Variant #' + idx);
          const truncatedLabel = truncateLabel(armLabel, config.maxLabelLength);

          const xValues = [];
          const yValues = [];
          arm.data.forEach(function(point) {
            xValues.push(point.x);
            yValues.push(point.y);
          });

          traces2d.push({
            type: 'scatter',
            mode: 'lines',
            name: truncatedLabel,
            x: xValues,
            y: yValues,
            line: {
              color: arm.color,
              width: 2
            },
            hovertemplate: '<b>' + armLabel + '</b><br>' + (data.xAxisLabel || 'Impressions') + ': %{x}<br>Rate: %{y:.1f}%<extra></extra>'
          });
        }

        const lineChartHeight = config.height2d;
        const xAxisLabel = data.xAxisLabel || 'Total Impressions';

        // Configure x-axis based on time axis type
        const xAxisConfig = {
          title: { text: xAxisLabel, font: { size: config.axisTitleSize } },
          tickfont: { size: config.tickSize },
          gridcolor: 'rgba(0,0,0,0.1)'
        };

        // Use custom tick labels for time-based axes
        if (data.xLabels && data.timeAxis !== 'trials') {
          const tickVals = Object.keys(data.xLabels).map(Number);
          const tickText = Object.values(data.xLabels);
          xAxisConfig.tickvals = tickVals;
          xAxisConfig.ticktext = tickText;
          xAxisConfig.tickangle = -45;
        }

        Plotly.newPlot('rl-plotly-2d-lines', traces2d, Object.assign({}, defaultLayout, {
          title: {
            text: 'Conversion Rate Over Time',
            font: { size: config.titleSize }
          },
          xaxis: xAxisConfig,
          yaxis: {
            title: { text: 'Conversion Rate (%)', font: { size: config.axisTitleSize } },
            tickfont: { size: config.tickSize },
            gridcolor: 'rgba(0,0,0,0.1)',
            rangemode: 'tozero'
          },
          height: lineChartHeight,
          showlegend: lineChartNumArms <= lineChartThreshold,
          legend: {
            orientation: lineChartNumArms <= 5 ? 'v' : 'h',
            yanchor: lineChartNumArms <= 5 ? 'top' : 'bottom',
            y: lineChartNumArms <= 5 ? 1 : -0.2,
            xanchor: 'left',
            x: lineChartNumArms <= 5 ? 1.02 : 0,
            font: { size: config.tickSize }
          },
          hovermode: 'closest'
        }), { responsive: true });

        // Update tip text if showing subset of variants
        if (lineChartNumArms > maxLineArms) {
          const tipEl = lineChartEl.parentElement.querySelector('.rl-help-text');
          if (tipEl) {
            tipEl.innerHTML = '<strong>Tip:</strong> Showing top ' + maxLineArms + ' active variants out of ' + lineChartNumArms + ' total. Hover for details.';
          }
        }
      } catch (e) {
        console.error('2D line chart error:', e);
      }
    }

    // 2. 3D Posterior Landscape (loss-landscape style) - threshold+1 arms
    if (showLandscape && data.surface3d && data.surface3d.zMatrix && data.surface3d.zMatrix.length > 0 && surface3dEl) {
      try {
        const landscapeNumArms = data.surface3d.zMatrix.length;
        const numTimePoints = data.surface3d.xValues.length;

        // Get current (latest) conversion rate for each arm to sort
        const armRates = [];
        for (let i = 0; i < landscapeNumArms; i++) {
          const lastRate = data.surface3d.zMatrix[i][numTimePoints - 1] || 0;
          armRates.push({ index: i, rate: lastRate });
        }
        // Sort by rate ASCENDING (lowest rate = lowest index = front, highest rate = back)
        armRates.sort(function(a, b) { return a.rate - b.rate; });

        // Reorder data based on sorted indices
        const sortedZMatrix = [];
        const sortedLabels = [];
        const armLabels = data.surface3d.armLabels || [];
        for (let i = 0; i < armRates.length; i++) {
          const origIdx = armRates[i].index;
          sortedZMatrix.push(data.surface3d.zMatrix[origIdx]);
          sortedLabels.push(armLabels[origIdx] || ('Variant #' + origIdx));
        }

        const armIndices = [];
        for (let i = 0; i < landscapeNumArms; i++) {
          armIndices.push(i);
        }

        // Build pre-formatted hovertext array (Plotly 3D surfaces don't support %{text} in hovertemplate)
        const hoverTextData = [];
        const truncatedLabels = [];
        const xAxisLabel3d = data.xAxisLabel || 'Impressions';

        for (let ai = 0; ai < landscapeNumArms; ai++) {
          const hoverRow = [];
          const fullLabel = sortedLabels[ai] || ('Variant #' + ai);
          const displayLabel = truncateLabel(fullLabel, config.maxLabelLength);
          truncatedLabels.push(displayLabel);

          for (let ti = 0; ti < numTimePoints; ti++) {
            const xValue = data.surface3d.xValues[ti];
            const rate = sortedZMatrix[ai][ti];
            // Use custom label if available for time-based axes
            const xDisplay = (data.xLabels && data.xLabels[xValue]) ? data.xLabels[xValue] : xValue;
            // Build complete hover text for each point
            hoverRow.push('<b>' + fullLabel + '</b><br>' + xAxisLabel3d + ': ' + xDisplay + '<br>Rate: ' + rate.toFixed(1) + '%');
          }
          hoverTextData.push(hoverRow);
        }

        // Configure Y-axis based on number of arms
        const yAxisConfig = {
          title: { text: 'Variant', font: { size: config.axisTitleSize } },
          tickfont: { size: config.tickSize }
        };

        // Show arm labels on Y-axis for small number of arms
        if (landscapeNumArms <= lineChartThreshold) {
          yAxisConfig.tickvals = armIndices;
          yAxisConfig.ticktext = truncatedLabels;
          yAxisConfig.tickangle = 0;
        }

        // Configure X-axis based on time axis type
        const xAxis3dConfig = {
          title: { text: xAxisLabel3d, font: { size: config.axisTitleSize } },
          tickfont: { size: config.tickSize }
        };

        // Use custom tick labels for time-based axes
        if (data.xLabels && data.timeAxis !== 'trials') {
          const tickVals3d = Object.keys(data.xLabels).map(Number);
          const tickText3d = Object.values(data.xLabels);
          xAxis3dConfig.tickvals = tickVals3d;
          xAxis3dConfig.ticktext = tickText3d;
        }

        // Calculate color bounds and surface statistics for adaptive lighting
        let zMin = Infinity;
        let zMax = -Infinity;
        let zSum = 0;
        let zCount = 0;
        for (let ai = 0; ai < sortedZMatrix.length; ai++) {
          for (let ti = 0; ti < sortedZMatrix[ai].length; ti++) {
            const val = sortedZMatrix[ai][ti];
            if (val < zMin) zMin = val;
            if (val > zMax) zMax = val;
            zSum += val;
            zCount++;
          }
        }
        const zMean = zSum / zCount;

        // Calculate variance for adaptive lighting
        let zVariance = 0;
        for (let ai = 0; ai < sortedZMatrix.length; ai++) {
          for (let ti = 0; ti < sortedZMatrix[ai].length; ti++) {
            const diff = sortedZMatrix[ai][ti] - zMean;
            zVariance += diff * diff;
          }
        }
        zVariance = zVariance / zCount;
        const zStdDev = Math.sqrt(zVariance);

        // Coefficient of variation: higher = more varied surface
        const coeffOfVar = zMean > 0 ? zStdDev / zMean : 0;

        // Ensure minimum range for color differentiation
        if (zMax - zMin < 0.1) {
          zMax = zMin + 0.1;
        }

        // Adaptive lighting based on surface variance
        // Low variance (flat surface): high ambient to brighten dark areas
        // High variance (ridged surface): moderate ambient, keep good contrast
        const varianceFactor = Math.min(1, coeffOfVar * 2); // Normalize to 0-1
        const adaptiveLighting = {
          ambient: 0.9 - (varianceFactor * 0.25),   // 0.9 for flat, 0.65 for ridged
          diffuse: 0.8,                              // Keep constant
          specular: 0.15 + (varianceFactor * 0.1),  // 0.15 for flat, 0.25 for ridged
          roughness: 0.5,                            // Keep constant
          fresnel: 0.2                               // Keep constant
        };

        Plotly.newPlot('rl-plotly-3d-surface', [{
          type: 'surface',
          z: sortedZMatrix,
          x: data.surface3d.xValues,
          y: armIndices,
          surfacecolor: sortedZMatrix,
          hovertext: hoverTextData,
          hoverinfo: 'text',
          cmin: zMin,
          cmax: zMax,
          cauto: false,
          colorscale: 'Viridis',
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
          lighting: adaptiveLighting,
          lightposition: {
            x: 100,
            y: 200,
            z: 100
          },
          colorbar: {
            title: { text: 'Conv. Rate', side: 'right', font: { size: config.axisTitleSize } },
            thickness: config.colorbarThickness,
            len: config.colorbarLen
          }
        }], Object.assign({}, defaultLayout, {
          title: { text: 'Conversion Rate Over Time', font: { size: config.titleSize } },
          scene: {
            xaxis: xAxis3dConfig,
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

        // Update tip text if showing subset of variants
        const totalArmsAll = data.totalArmsAll || landscapeNumArms;
        if (landscapeNumArms < totalArmsAll) {
          const tipEl = surface3dEl.parentElement.querySelector('.rl-help-text');
          if (tipEl) {
            tipEl.innerHTML = '<strong>Tip:</strong> Showing top ' + landscapeNumArms + ' active variants out of ' + totalArmsAll + ' total. Taller/brighter = better conversion rate.';
          }
        }
      } catch (e) {
        console.error('3D posterior landscape error:', e);
      }
    }

  }

  /**
   * Debounce function for performance optimization.
   */
  function debounce(func, wait) {
    let timeout;
    return function executedFunction() {
      const context = this;
      const args = arguments;
      clearTimeout(timeout);
      timeout = setTimeout(function() {
        func.apply(context, args);
      }, wait);
    };
  }

  /**
   * Track last window dimensions to prevent unnecessary redraws.
   */
  let lastWindowWidth = window.innerWidth;
  let lastWindowHeight = window.innerHeight;

  /**
   * Handle chart resize - only triggers on actual window size changes.
   * Uses Plotly.Plots.resize() which is designed for responsive charts.
   */
  function handleResize() {
    // Only resize if window dimensions actually changed
    const currentWidth = window.innerWidth;
    const currentHeight = window.innerHeight;

    if (currentWidth === lastWindowWidth && currentHeight === lastWindowHeight) {
      return;
    }

    lastWindowWidth = currentWidth;
    lastWindowHeight = currentHeight;

    const data = drupalSettings.rlPlotly;
    if (!data) return;

    // Use Plotly.Plots.resize() for responsive charts - it respects the container
    const chartIds = ['rl-plotly-2d-lines', 'rl-plotly-3d-surface'];

    chartIds.forEach(function(chartId) {
      const el = document.getElementById(chartId);
      if (el && el.data && el.layout) {
        Plotly.Plots.resize(el);
      }
    });
  }

  // Debounced resize handler (300ms delay for performance)
  const debouncedResize = debounce(handleResize, 300);

  // Only use window resize event - avoid ResizeObserver to prevent infinite loops
  window.addEventListener('resize', debouncedResize, { passive: true });

})(Drupal, drupalSettings, once);
