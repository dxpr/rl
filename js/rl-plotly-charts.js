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
    const height3d = getContainerHeight('rl-plotly-3d-surface');
    const height2d = getContainerHeight('rl-plotly-2d-lines');

    // Breakpoint table: [maxWidth, fontSize, titleSize, axisTitleSize, tickSize,
    //   margins[l,r,t,b], camera[x,y,z], colorbarLen, colorbarThickness, maxLabelLength]
    const breakpoints = [
      [430,  10, 13, 11, 9,  [50, 30, 40, 60],   [2.0, -2.0, 1.2],  0.6,  15, 20],
      [768,  11, 14, 12, 10, [60, 35, 45, 80],   [1.8, -1.9, 1.0],  0.7,  18, 30],
      [1200, 12, 15, 13, 11, [70, 40, 50, 90],   [1.7, -1.8, 0.95], 0.75, 20, 40],
      [1920, 13, 16, 14, 11, [80, 40, 50, 100],  [1.6, -1.8, 0.9],  0.8,  20, 50],
      [Infinity, 14, 18, 15, 12, [100, 50, 60, 120], [1.5, -1.7, 0.85], 0.85, 25, 60]
    ];

    const bp = breakpoints.find(function(b) { return width <= b[0]; });
    return {
      height: height3d,
      height2d: height2d,
      fontSize: bp[1],
      titleSize: bp[2],
      axisTitleSize: bp[3],
      tickSize: bp[4],
      margin: { l: bp[5][0], r: bp[5][1], t: bp[5][2], b: bp[5][3] },
      camera: { eye: { x: bp[6][0], y: bp[6][1], z: bp[6][2] } },
      colorbarLen: bp[7],
      colorbarThickness: bp[8],
      maxLabelLength: bp[9]
    };
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

    // Y-axis metric: 'score' (Bayesian) or 'rate' (raw)
    const metric = data.metric || 'score';
    const metricLabel = metric === 'score' ? 'Conversion Score' : 'Conversion Rate';
    const chartTitle = metric === 'score'
      ? 'Learning-adjusted Conversion Rate (Conversion Score) Over Time'
      : 'Conversion Rate Over Time';

    const defaultLayout = {
      paper_bgcolor: 'rgba(255,255,255,1)',
      plot_bgcolor: 'rgba(255,255,255,1)',
      font: { size: config.fontSize, family: 'Arial, sans-serif' },
      margin: config.margin
    };

    // Determine number of arms and which chart to show
    let numArms = 0;
    if (data.lineChartData && data.lineChartData.arms) {
      numArms = data.lineChartData.arms.length;
    } else if (data.surface3d && data.surface3d.zMatrixScore) {
      numArms = data.surface3d.zMatrixScore.length;
    }

    // Chart selection based on arm count (threshold from config or default 10):
    // 1-threshold arms: 2D line chart
    // threshold+1 arms: 3D Posterior Landscape
    const lineChartThreshold = data.chartLineThreshold || 9;
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

    // 1. 2D Line Chart (up to threshold arms)
    if (showLineChart && data.lineChartData && data.lineChartData.arms && data.lineChartData.arms.length > 0 && lineChartEl) {
      try {
        const traces2d = [];
        const lineChartNumArms = data.lineChartData.arms.length;
        const maxLineArms = Math.min(lineChartNumArms, 20); // Limit to 20 arms for readability
        const xAxisLabel = data.xAxisLabel || 'Total Impressions';

        for (let idx = 0; idx < maxLineArms; idx++) {
          const arm = data.lineChartData.arms[idx];
          const armLabel = arm.label || ('Variant #' + idx);
          const truncatedLabel = truncateLabel(armLabel, config.maxLabelLength);

          const xValues = [];
          const yValues = [];
          arm.data.forEach(function(point) {
            xValues.push(point.x);
            // Use score or rate based on metric setting
            yValues.push(metric === 'score' ? point.score : point.rate);
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
            hovertemplate: '<b>' + armLabel + '</b><br>' + xAxisLabel + ': %{x}<br>' + metricLabel + ': %{y:.1f}%<extra></extra>'
          });
        }

        const lineChartHeight = config.height2d;

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
            text: chartTitle,
            font: { size: config.titleSize }
          },
          xaxis: xAxisConfig,
          yaxis: {
            title: { text: metricLabel + ' (%)', font: { size: config.axisTitleSize } },
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
          const tipEl = lineChartEl.parentElement.querySelector('.rl-chart-tip');
          if (tipEl) {
            tipEl.innerHTML = '<strong>Tip:</strong> Showing top ' + maxLineArms + ' active variants out of ' + lineChartNumArms + ' total. Hover for details.';
          }
        }
      } catch (e) {
        console.error('2D line chart error:', e);
      }
    }

    // 2. 3D Posterior Landscape (loss-landscape style) - threshold+1 arms
    const zMatrix = metric === 'score' ? data.surface3d.zMatrixScore : data.surface3d.zMatrixRate;
    if (showLandscape && data.surface3d && zMatrix && zMatrix.length > 0 && surface3dEl) {
      try {
        const landscapeNumArms = zMatrix.length;
        const numTimePoints = data.surface3d.xValues.length;

        // Get current (latest) value for each arm to sort
        const armRates = [];
        for (let i = 0; i < landscapeNumArms; i++) {
          const lastRate = zMatrix[i][numTimePoints - 1] || 0;
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
          sortedZMatrix.push(zMatrix[origIdx]);
          sortedLabels.push(armLabels[origIdx] || ('Variant #' + origIdx));
        }

        const armIndices = [...Array(landscapeNumArms).keys()];

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
            const val = sortedZMatrix[ai][ti];
            // Use custom label if available for time-based axes
            const xDisplay = (data.xLabels && data.xLabels[xValue]) ? data.xLabels[xValue] : xValue;
            // Build complete hover text for each point
            hoverRow.push('<b>' + fullLabel + '</b><br>' + xAxisLabel3d + ': ' + xDisplay + '<br>' + metricLabel + ': ' + val.toFixed(1) + '%');
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

        // Single pass for all statistics (min, max, sum, sumSq for variance)
        let zMin = Infinity, zMax = -Infinity, zSum = 0, zSumSq = 0, zCount = 0;
        for (let ai = 0; ai < sortedZMatrix.length; ai++) {
          for (let ti = 0; ti < sortedZMatrix[ai].length; ti++) {
            const val = sortedZMatrix[ai][ti];
            if (val < zMin) zMin = val;
            if (val > zMax) zMax = val;
            zSum += val;
            zSumSq += val * val;
            zCount++;
          }
        }
        const zMean = zSum / zCount;
        const zVariance = (zSumSq / zCount) - (zMean * zMean);
        const zStdDev = Math.sqrt(Math.max(0, zVariance));

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
            title: { text: metricLabel, side: 'right', font: { size: config.axisTitleSize } },
            thickness: config.colorbarThickness,
            len: config.colorbarLen
          }
        }], Object.assign({}, defaultLayout, {
          title: { text: chartTitle, font: { size: config.titleSize } },
          scene: {
            xaxis: xAxis3dConfig,
            yaxis: yAxisConfig,
            zaxis: {
              title: { text: metricLabel + ' (%)', font: { size: config.axisTitleSize } },
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
          const tipEl = surface3dEl.parentElement.querySelector('.rl-chart-tip');
          if (tipEl) {
            tipEl.innerHTML = '<strong>Tip:</strong> Showing top ' + landscapeNumArms + ' active variants out of ' + totalArmsAll + ' total. Taller/brighter = better ' + metricLabel.toLowerCase() + '.';
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
