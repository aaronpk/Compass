/*
  The graph under the map: battery level and charging state, speed, and the
  events the app logged, over time. Drawn with Chart.js.

  showBatteryGraph(response, timezone) takes a response of
  /api/query?format=linestring. Hovering a point calls moveMarkerToPosition()
  (map.js) with the location of that point.

  Drag across the graph to zoom in, hold shift and drag to pan, double-click
  to zoom back out.
*/

var batteryChart;

var eventTypes = {
  "application_launched_with_location": { name: "Launched with Location",   color: '#42C093', y: 90 },
  "visit":                              { name: "Visit",                    color: '#8f799e', y: 80 },
  "paused_location_updates":            { name: "Paused Location Updates",  color: '#a0876e', y: 70 },
  "resumed_location_updates":           { name: "Resumed Location Updates", color: '#819e73', y: 60 },
  "exited_pause_region":                { name: "Exited Pause Region",      color: '#819e73', y: 50 },
  "did_finish_deferred_updates":        { name: "Finished Deferred Updates", color: '#9ea06e', y: 40 },
  "did_enter_background":               { name: "Entered Background",       color: '#799b9e', y: 30 },
  "will_resign_active":                 { name: "Will Resign Active",       color: '#737f9e', y: 20 },
  "will_terminate":                     { name: "Will Terminate",           color: '#9e7773', y: 10 }
};

// Different shapes as well as colours, several of the event colours are close
var eventPointStyles = ['triangle', 'rectRot', 'rect', 'circle', 'star', 'crossRot', 'rectRounded', 'cross', 'triangle'];

var batteryStates = {
  charging:  { name: 'Charging',  variable: '--chart-band-charging' },
  full:      { name: 'Full',      variable: '--chart-band-full' },
  unplugged: { name: 'Unplugged', variable: '--chart-band-unplugged' }
};

function cssVariable(name) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

/*
  Turns ["a","a","b","a"] into the runs of equal values:
  [{value: "a", from: 0, to: 1}, {value: "b", from: 2, to: 2}, {value: "a", from: 3, to: 3}]
*/
function findRanges(values) {
  var ranges = [];
  values.forEach(function(value, i){
    var last = ranges[ranges.length - 1];
    if(last && last.value === value) {
      last.to = i;
    } else {
      ranges.push({ value: value, from: i, to: i });
    }
  });
  return ranges;
}

// Formats times in the given timezone, or the browser's if it isn't one the browser knows
function timeFormatter(timezone) {
  function formatter(options) {
    options.hourCycle = 'h23';
    try {
      options.timeZone = timezone || undefined;
      return new Intl.DateTimeFormat('en-US', options);
    } catch(e) {
      delete options.timeZone;
      return new Intl.DateTimeFormat('en-US', options);
    }
  }
  var hm = formatter({ hour: '2-digit', minute: '2-digit' });
  var hms = formatter({ hour: '2-digit', minute: '2-digit', second: '2-digit' });
  var day = formatter({ month: 'short', day: 'numeric' });
  var parts = formatter({ year: 'numeric', month: 'numeric', day: 'numeric', hour: 'numeric', minute: 'numeric', second: 'numeric' });
  return {
    hm: function(ms) { return hm.format(ms); },
    hms: function(ms) { return hms.format(ms); },
    day: function(ms) { return day.format(ms); },
    // How far local time is ahead of UTC at this moment, in milliseconds
    offset: function(ms) {
      var p = {};
      parts.formatToParts(ms).forEach(function(part){ p[part.type] = parseInt(part.value, 10); });
      var local = Date.UTC(p.year, p.month - 1, p.day, p.hour, p.minute, p.second);
      return local - Math.floor(ms / 1000) * 1000;
    }
  };
}

// Steps between the labels on the time axis
var timeSteps = [1, 5, 15, 30, 60, 120, 300, 600, 900, 1800, 3600, 7200, 10800, 21600, 43200, 86400, 172800, 604800].map(function(s){ return s * 1000; });

// Ticks on round local times (every 5 minutes, every hour, ...) instead of round numbers of milliseconds
function timeTicks(min, max, maxTicks, format) {
  var step = timeSteps[timeSteps.length - 1];
  for(var i = 0; i < timeSteps.length; i++) {
    if((max - min) / timeSteps[i] <= maxTicks) {
      step = timeSteps[i];
      break;
    }
  }
  var offset = format.offset(min);
  var ticks = [];
  for(var t = Math.ceil((min + offset) / step) * step - offset; t <= max && ticks.length < 100; t += step) {
    ticks.push({ value: t });
  }
  return ticks;
}

// Shades the time the battery spent charging, full and unplugged
var batteryStateBands = {
  id: 'batteryStateBands',
  beforeDatasetsDraw: function(chart, args, options) {
    var area = chart.chartArea;
    var x = chart.scales.x;
    var ctx = chart.ctx;
    ctx.save();
    ctx.beginPath();
    ctx.rect(area.left, area.top, area.width, area.height);
    ctx.clip();
    (options.bands || []).forEach(function(band){
      var state = batteryStates[band.value];
      if(state) {
        var from = x.getPixelForValue(band.from);
        var to = x.getPixelForValue(band.to);
        ctx.fillStyle = cssVariable(state.variable);
        ctx.fillRect(from, area.top, to - from, area.height);
      }
    });
    ctx.restore();
  }
};

// Draws the selection while dragging across the graph to zoom
var dragSelection = {
  id: 'dragSelection',
  afterDraw: function(chart) {
    var selection = chart.$dragSelection;
    if(selection) {
      var area = chart.chartArea;
      var ctx = chart.ctx;
      ctx.save();
      ctx.fillStyle = cssVariable('--chart-grid');
      ctx.fillRect(Math.min(selection.start, selection.end), area.top, Math.abs(selection.end - selection.start), area.height);
      ctx.restore();
    }
  }
};

// Shows the given range of time, or everything if no range is given
function setGraphRange(min, max) {
  batteryChart.options.scales.x.min = min;
  batteryChart.options.scales.x.max = max;
  batteryChart.update('none');
  document.getElementById('chart-reset-zoom').classList.toggle('hidden', min === undefined);
}

// Drag to zoom, shift-drag to pan, double-click to reset
(function(){
  var canvas = document.getElementById('battery-chart-canvas');
  var drag = null;

  // Horizontal position of the mouse in the canvas, kept inside the plot
  function position(evt) {
    var area = batteryChart.chartArea;
    return Math.max(area.left, Math.min(area.right, evt.clientX - canvas.getBoundingClientRect().left));
  }

  canvas.addEventListener('mousedown', function(evt){
    if(!batteryChart || evt.button != 0) {
      return;
    }
    var area = batteryChart.chartArea;
    var y = evt.clientY - canvas.getBoundingClientRect().top;
    if(y < area.top || y > area.bottom) {
      return;
    }
    var x = batteryChart.scales.x;
    drag = { chart: batteryChart, start: position(evt), end: position(evt), pan: evt.shiftKey, min: x.min, max: x.max };
    evt.preventDefault();
  });
  // On the window, so that a drag can continue and end outside of the canvas
  window.addEventListener('mousemove', function(evt){
    if(!drag || drag.chart != batteryChart) {
      drag = null;
      return;
    }
    drag.end = position(evt);
    if(drag.pan) {
      var x = batteryChart.scales.x;
      var shift = (drag.start - drag.end) * (drag.max - drag.min) / (x.right - x.left);
      setGraphRange(drag.min + shift, drag.max + shift);
    } else {
      batteryChart.$dragSelection = drag;
      batteryChart.draw();
    }
  });
  window.addEventListener('mouseup', function(){
    if(!drag || drag.chart != batteryChart) {
      drag = null;
      return;
    }
    var finished = drag;
    drag = null;
    batteryChart.$dragSelection = null;
    if(!finished.pan && Math.abs(finished.end - finished.start) > 5) {
      var x = batteryChart.scales.x;
      setGraphRange(x.getValueForPixel(Math.min(finished.start, finished.end)), x.getValueForPixel(Math.max(finished.start, finished.end)));
    } else {
      batteryChart.draw();
    }
  });
  canvas.addEventListener('dblclick', function(){
    if(batteryChart) {
      setGraphRange(undefined, undefined);
    }
  });
  document.getElementById('chart-reset-zoom').addEventListener('click', function(){
    if(batteryChart) {
      setGraphRange(undefined, undefined);
    }
  });
})();

function batteryDataset(data) {
  return {
    label: 'Battery',
    kind: 'battery',
    yAxisID: 'y',
    data: data.properties.map(function(d, i){
      return {
        x: d.unixtime * 1000,
        y: ('battery_level' in d ? Math.round(d.battery_level * 100) : null),
        state: d.battery_state,
        location: data.coordinates[i]
      };
    }),
    borderColor: cssVariable('--chart-battery'),
    backgroundColor: cssVariable('--chart-battery'),
    borderWidth: 2,
    pointRadius: data.properties.length > 500 ? 0 : 2,
    pointHoverRadius: 5
  };
}

function speedDataset(data, system) {
  return {
    label: 'Speed',
    kind: 'speed',
    yAxisID: 'speed',
    data: data.properties.map(function(d, i){
      return {
        x: d.unixtime * 1000,
        y: ('speed' in d && d.speed >= 0 ? Math.round(d.speed * system.multiplier) : null),
        location: data.coordinates[i]
      };
    }),
    borderColor: cssVariable('--chart-speed'),
    backgroundColor: cssVariable('--chart-speed'),
    borderWidth: 1,
    pointRadius: data.properties.length > 500 ? 0 : 1.5,
    pointHoverRadius: 4
  };
}

// One scatter series per kind of event, each on its own row
function eventDatasets(response) {
  // The API leaves out "events" when there are none
  var events = response.events || [];
  var datasets = [];

  Object.keys(eventTypes).forEach(function(action, index){
    var type = eventTypes[action];
    var points = events.filter(function(event){
      return event.properties && event.properties.action == action;
    }).map(function(event){
      return {
        x: event.properties.unixtime * 1000,
        y: type.y,
        location: (event.geometry ? event.geometry.coordinates : null)
      };
    });
    if(points.length > 0) {
      datasets.push({
        label: type.name,
        kind: 'event',
        type: 'scatter',
        yAxisID: 'y',
        data: points,
        borderColor: type.color,
        backgroundColor: type.color,
        pointStyle: eventPointStyles[index],
        pointRadius: 4,
        pointHoverRadius: 6
      });
    }
  });

  return datasets;
}

function showBatteryGraph(response, timezone) {
  var data = response.linestring;
  // The API leaves out "properties" when there are none
  data.properties = data.properties || [];

  var system = {
    label: 'mph',
    multiplier: 2.23694
  };
  if(document.getElementById('is-metric').value == true) {
    system.label = 'km/h';
    system.multiplier = 3.6;
  }

  var format = timeFormatter(timezone);
  var text = cssVariable('--text');
  var muted = cssVariable('--muted');
  var grid = cssVariable('--chart-grid');

  var bands = findRanges(data.properties.map(function(d){ return d.battery_state; })).map(function(range){
    return {
      value: range.value,
      from: data.properties[range.from].unixtime * 1000,
      to: data.properties[range.to].unixtime * 1000
    };
  });
  var statesShown = Object.keys(batteryStates).filter(function(state){
    return bands.some(function(band){ return band.value == state; });
  });

  var datasets = [batteryDataset(data), speedDataset(data, system)].concat(eventDatasets(response));

  if(batteryChart) {
    batteryChart.destroy();
  }
  document.getElementById('chart-reset-zoom').classList.add('hidden');

  batteryChart = new Chart(document.getElementById('battery-chart-canvas'), {
    type: 'line',
    data: { datasets: datasets },
    plugins: [batteryStateBands, dragSelection],
    options: {
      responsive: true,
      maintainAspectRatio: false,
      animation: false,
      color: text,
      interaction: {
        mode: 'nearest',
        intersect: false
      },
      onHover: function(evt, active, chart) {
        if(active.length) {
          moveMarkerToPosition(chart.data.datasets[active[0].datasetIndex].data[active[0].index]);
        }
      },
      scales: {
        x: {
          type: 'linear',
          bounds: 'data',
          afterBuildTicks: function(axis) {
            axis.ticks = timeTicks(axis.min, axis.max, Math.max(2, Math.floor(axis.width / 70)), format);
          },
          ticks: {
            color: muted,
            maxRotation: 0,
            autoSkip: false,
            callback: function(value, index, ticks) {
              // Name the day on the first label and wherever the day changes
              var label = format.hm(value);
              var day = format.day(value);
              if(this.max - this.min > 43200000 && (index == 0 || format.day(ticks[index - 1].value) != day)) {
                return [label, day];
              }
              return label;
            }
          },
          grid: { color: grid },
          border: { color: grid }
        },
        y: {
          min: 0,
          max: 100,
          title: { display: true, text: 'Battery %', color: muted },
          ticks: { color: muted, stepSize: 25 },
          grid: { color: grid },
          border: { color: grid }
        },
        speed: {
          position: 'right',
          min: 0,
          title: { display: true, text: 'Speed (' + system.label + ')', color: muted },
          ticks: { color: muted, maxTicksLimit: 5, precision: 0 },
          grid: { drawOnChartArea: false },
          border: { color: grid }
        }
      },
      plugins: {
        batteryStateBands: { bands: bands },
        legend: {
          position: 'top',
          align: 'start',
          labels: {
            color: text,
            boxWidth: 11,
            boxHeight: 11,
            usePointStyle: true,
            font: { size: 11 },
            generateLabels: function(chart) {
              var labels = Chart.defaults.plugins.legend.labels.generateLabels(chart);
              labels.forEach(function(label){
                if(chart.data.datasets[label.datasetIndex].kind != 'event') {
                  label.pointStyle = 'line';
                  label.lineWidth = 3;
                }
              });
              // The shaded bands get a legend entry too
              statesShown.forEach(function(state){
                labels.push({
                  text: batteryStates[state].name,
                  fontColor: text,
                  fillStyle: cssVariable(batteryStates[state].variable),
                  strokeStyle: grid,
                  lineWidth: 1,
                  pointStyle: 'rect',
                  band: true
                });
              });
              return labels;
            }
          },
          onClick: function(evt, item, legend) {
            if(!item.band) {
              Chart.defaults.plugins.legend.onClick.call(this, evt, item, legend);
            }
          }
        },
        tooltip: {
          animation: false,
          displayColors: true,
          usePointStyle: true,
          callbacks: {
            title: function(items) {
              if(!items.length) {
                return '';
              }
              var ms = items[0].parsed.x;
              return format.day(ms) + ' ' + format.hms(ms);
            },
            label: function(item) {
              switch(item.dataset.kind) {
                case 'battery':
                  return ' Battery ' + item.parsed.y + '%' + (item.raw.state ? ' (' + item.raw.state + ')' : '');
                case 'speed':
                  return ' Speed ' + item.parsed.y + ' ' + system.label;
                default:
                  return ' ' + item.dataset.label;
              }
            }
          }
        }
      }
    }
  });
  batteryChart.$response = response;
  batteryChart.$timezone = timezone;
}

// The colours come from the stylesheet, so follow a change of colour scheme
window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function(){
  if(batteryChart && batteryChart.$response) {
    showBatteryGraph(batteryChart.$response, batteryChart.$timezone);
  }
});
