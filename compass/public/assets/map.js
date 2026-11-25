var map = Compass.createMap('map', { maxZoom: 24 });

var startIcon = Compass.pinIcon('start');
var endIcon = Compass.pinIcon('end');

var visible_layers = [];
var visible_data = [];
var highlightedMarker;
var animatedMarker;
var startMarker;
var endMarker;
var timers = [];

var database = document.getElementById('database').dataset;
var rangeFrom = document.getElementById('range-from');
var rangeTo = document.getElementById('range-to');
var timezone = document.getElementById('timezone');
var rangeGo = document.getElementById('range-go');

function resetAnimation() {
  if(animatedMarker) {
    map.removeLayer(animatedMarker);
  }
  timers.forEach(function(timer){
    clearTimeout(timer);
  });
  timers = [];
}

function displayLineOnMap(response, options) {
  var data = response.linestring;

  if(data && data.coordinates && data.coordinates.length > 0) {
    // For any null coordinates, fill it in with the previous location
    var lastCoord = null;
    for(var i = 0; i < data.coordinates.length; i++) {
      if(data.coordinates[i] == null) {
        data.coordinates[i] = lastCoord;
      } else {
        lastCoord = data.coordinates[i];
      }
    }

    // Points before the first known location have nowhere to go on the map
    // (the graph still gets every point, matched up by index)
    var line = {
      type: data.type,
      coordinates: data.coordinates.filter(function(coord){ return coord != null; })
    };

    visible_data.push(data);
    var layer = L.geoJSON(line, {
      style: Compass.lineStyle
    }).addTo(map);
    visible_layers.push(layer);

    // Show the start/end pins if necessary
    if(options.pins && line.coordinates.length > 0) {
      startMarker = L.marker(Compass.pointFromGeoJSON(line.coordinates[0]), {icon: startIcon}).addTo(map);
      endMarker = L.marker(Compass.pointFromGeoJSON(line.coordinates[line.coordinates.length-1]), {icon: endIcon}).addTo(map);
    }

    // If the new layer is completely outside the current view, zoom the map to fit all layers
    var bounds = layer.getBounds();
    if(bounds.isValid() && !map.getBounds().intersects(bounds)) {
      var full_bounds = L.latLngBounds([]);
      visible_layers.forEach(function(l){
        full_bounds.extend(l.getBounds());
      });
      // Not closer than street level: a day with a single point, or one spent
      // in one building, would otherwise zoom in as far as the map goes
      map.fitBounds(full_bounds, {maxZoom: Compass.fitMaxZoom});
    }

    showBatteryGraph(response, timezone.value);
  }
}

function queryURL(params) {
  params.tz = timezone.value;
  params.token = database.token;
  return "/api/query?format=linestring&" + new URLSearchParams(params);
}

// Shows one day. If append is true, it is added to what is already on the map.
function loadDay(date, append) {
  if(!append) {
    removeVisibleLayers();
  }
  if(selectedDates.indexOf(date) != -1) {
    return;
  }
  selectedDates.push(date);
  showMonth(date.substr(0, 7));
  updateCalendarLabel();

  resetAnimation();

  Compass.getJSON(queryURL({date: date})).then(function(data){
    displayLineOnMap(data, {pins: false});
  }).catch(function(error){
    console.error(error);
  });
  rangeFrom.value = date+' 00:00:00';
  rangeTo.value = date+' 23:59:59';
}

// Shows everything between the "from" and "to" fields
function loadRange() {
  var stateObj = {from: rangeFrom.value, to: rangeTo.value, tz: timezone.value};
  var historyURL = "/map/" + encodeURIComponent(database.name) + "?" + new URLSearchParams(stateObj);
  window.history.pushState(stateObj, "GPS Log", historyURL);
  resetAnimation();
  removeVisibleLayers();
  updateCalendarLabel();
  if(/^\d{4}-\d\d/.test(rangeFrom.value)) {
    showMonth(rangeFrom.value.substr(0, 7));
  }

  rangeGo.classList.add("loading");
  Compass.getJSON(queryURL({start: rangeFrom.value, end: rangeTo.value})).then(function(data){
    var tripForm = document.getElementById("trip-create-form");
    if(tripForm) {
      tripForm.classList.remove("hidden");
    }
    displayLineOnMap(data, {pins: true});
  }).catch(function(error){
    console.error(error);
  }).then(function(){
    rangeGo.classList.remove("loading");
  });
}

// Moves a marker along the first visible line
function playAnimation() {
  if(!visible_data.length) {
    return;
  }
  var coordinates = visible_data[0].coordinates.filter(function(coord){ return coord != null; });
  if(!coordinates.length) {
    return;
  }

  resetAnimation();

  animatedMarker = L.marker(Compass.pointFromGeoJSON(coordinates[0])).addTo(map);

  var interval = 3;
  coordinates.forEach(function(coord, i){
    timers.push(setTimeout(function(){
      animatedMarker.setLatLng(Compass.pointFromGeoJSON(coord));
    }, interval*i));
  });
}

function createTrip(button) {
  button.classList.add("loading");
  fetch('/api/trip-complete', {
    method: 'POST',
    credentials: 'same-origin',
    body: new URLSearchParams({
      start: rangeFrom.value,
      end: rangeTo.value,
      tz: timezone.value,
      mode: document.getElementById("trip-mode").value,
      token: database.writeToken
    })
  }).catch(function(error){
    console.error(error);
  }).then(function(){
    button.classList.remove("loading");
  });
}

function removeVisibleLayers() {
  selectedDates = [];
  renderCalendar();
  visible_layers.forEach(function(layer){
    map.removeLayer(layer);
  });
  visible_layers = [];
  visible_data = [];
  if(startMarker) {
    map.removeLayer(startMarker);
    map.removeLayer(endMarker);
    startMarker = endMarker = null;
  }
}

// Called by the graph when a point is hovered
function moveMarkerToPosition(point) {
  if(point && point.location) {
    var coord = Compass.pointFromGeoJSON(point.location);
    if(coord) {
      if(!highlightedMarker) {
        highlightedMarker = L.marker(coord).addTo(map);
      } else {
        highlightedMarker.setLatLng(coord);
      }
    }
  }
}

////////////////////////////////////////////////////////////
// Calendar: one month at a time, days shaded by how far was travelled

var calendar = document.getElementById('calendar');
var calPanel = document.getElementById('cal-panel');
var calGrid = document.getElementById('cal-grid');
var calMonth = document.getElementById('cal-month');
var calYear = document.getElementById('cal-year');
var calToggle = document.getElementById('cal-toggle');
var calLabel = document.getElementById('cal-label');
var firstMonth = calendar.dataset.first;        // "YYYY-MM"
var lastMonth = database.today.substr(0, 7);
var shownMonth = lastMonth;
var selectedDates = [];
var dayStats = {};                              // year => {date: [ground m, flight m]}, or false while loading
var phone = window.matchMedia('(max-width: 700px)');

// Dates are plain "YYYY-MM-DD" strings; UTC is only used to do arithmetic on them
function parseDate(str) {
  var p = str.split('-');
  return new Date(Date.UTC(+p[0], p[1] - 1, +(p[2] || 1)));
}
function addDays(str, n) {
  var d = parseDate(str);
  d.setUTCDate(d.getUTCDate() + n);
  return d.toISOString().substr(0, 10);
}
function addMonths(month, n) {
  var d = parseDate(month);
  d.setUTCMonth(d.getUTCMonth() + n);
  return d.toISOString().substr(0, 7);
}
function formatDate(str, options) {
  options.timeZone = 'UTC';
  return new Intl.DateTimeFormat(undefined, options).format(parseDate(str));
}

// Every day with data is shaded, darker the further was travelled on the
// ground: 1 for a day spent in one place, up to 5 for a long drive. Flights
// get a dot instead, or every travel day would look the same.
function activityLevel(day) {
  if(day[0] >= 200000) return 5;
  if(day[0] >= 50000) return 4;
  if(day[0] >= 10000) return 3;
  if(day[0] >= 1000) return 2;
  return 1;
}

function describeDay(date, day) {
  var text = formatDate(date, {weekday: 'long', month: 'long', day: 'numeric', year: 'numeric'});
  if(day) {
    var metric = document.getElementById('is-metric').value == '1';
    var total = (day[0] + day[1]) / (metric ? 1000 : 1609.344);
    text += ': ' + (total < 10 ? total.toFixed(1) : Math.round(total).toLocaleString()) + (metric ? ' km' : ' mi');
    if(day[1] > 0) {
      text += ', with a flight';
    }
  }
  return text;
}

function loadDayStats(year) {
  if(year in dayStats) {
    return;
  }
  dayStats[year] = false;
  Compass.getJSON('/api/days?' + new URLSearchParams({token: database.token, year: year})).then(function(data){
    dayStats[year] = data.days || {};
    if(shownMonth.substr(0, 4) == year) {
      renderCalendar();
    }
  }).catch(function(error){
    // The calendar works without the shading
    console.error(error);
  });
}

function renderCalendar() {
  var year = shownMonth.substr(0, 4);
  var stats = dayStats[year] || {};
  var first = parseDate(shownMonth);
  var days = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0)).getUTCDate();
  var offset = (first.getUTCDay() + 6) % 7;   // weeks start on Monday

  var html = '<tr>';
  for(var n = 0; n < 7; n++) {
    // 2024-01-01 was a Monday
    html += '<th>' + formatDate(addDays('2024-01-01', n), {weekday: 'short'}) + '</th>';
  }
  html += '</tr><tr>';
  if(offset > 0) {
    html += '<td colspan="' + offset + '"></td>';
  }
  for(var d = 1; d <= days; d++) {
    if(d > 1 && (offset + d - 1) % 7 == 0) {
      html += '</tr><tr>';
    }
    var date = shownMonth + '-' + (d < 10 ? '0' : '') + d;
    if(date > database.today) {
      html += '<td class="future">' + d + '</td>';
      continue;
    }
    var classes = [];
    if(stats[date]) classes.push('lv' + activityLevel(stats[date]));
    if(stats[date] && stats[date][1] > 0) classes.push('flight');
    if(selectedDates.indexOf(date) != -1) classes.push('selected');
    if(date == database.today) classes.push('today');
    html += '<td><a href="#" data-date="' + date + '" class="' + classes.join(' ') + '" title="' + describeDay(date, stats[date]) + '">' + d + '</a></td>';
  }
  calGrid.innerHTML = html + '</tr>';

  calYear.value = year;
  calMonth.value = shownMonth.substr(5, 2);
  Array.prototype.forEach.call(calMonth.options, function(option){
    var month = year + '-' + option.value;
    option.disabled = month < firstMonth || month > lastMonth;
  });
  document.getElementById('cal-prev-month').disabled = shownMonth <= firstMonth;
  document.getElementById('cal-next-month').disabled = shownMonth >= lastMonth;
}

function showMonth(month) {
  if(month < firstMonth) month = firstMonth;
  if(month > lastMonth) month = lastMonth;
  shownMonth = month;
  loadDayStats(month.substr(0, 4));
  renderCalendar();
}

// What is on the map, in the bar above the calendar
function updateCalendarLabel() {
  var from = rangeFrom.value.substr(0, 10), to = rangeTo.value.substr(0, 10);
  var short = {month: 'short', day: 'numeric', year: 'numeric'};
  var isDate = /^\d{4}-\d\d-\d\d$/;
  if(selectedDates.length == 1) {
    calLabel.textContent = formatDate(selectedDates[0], {weekday: 'short', month: 'short', day: 'numeric', year: 'numeric'});
  } else if(selectedDates.length > 1) {
    calLabel.textContent = selectedDates.length + ' days';
  } else if(isDate.test(from) && isDate.test(to) && from != to) {
    calLabel.textContent = formatDate(from, short) + ' – ' + formatDate(to, short);
  } else if(isDate.test(from)) {
    calLabel.textContent = formatDate(from, short);
  } else {
    calLabel.textContent = 'Choose a day';
  }
  var current = currentDate();
  document.getElementById('cal-prev-day').disabled = addDays(current, -1) < firstMonth + '-01';
  document.getElementById('cal-next-day').disabled = current >= database.today;
}

// The day the previous and next buttons step from
function currentDate() {
  if(selectedDates.length) {
    return selectedDates[selectedDates.length - 1];
  }
  var from = rangeFrom.value.substr(0, 10);
  return /^\d{4}-\d\d-\d\d$/.test(from) ? from : database.today;
}

function stepDay(n) {
  var date = addDays(currentDate(), n);
  if(date > database.today || date < firstMonth + '-01') {
    return;
  }
  loadDay(date, false);
}

function setCalendarOpen(open) {
  calPanel.hidden = !open;
  calToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
}

(function(){
  for(var y = +lastMonth.substr(0, 4); y >= +firstMonth.substr(0, 4); y--) {
    calYear.add(new Option(y, y));
  }
  for(var m = 1; m <= 12; m++) {
    var value = (m < 10 ? '0' : '') + m;
    calMonth.add(new Option(formatDate('2024-' + value, {month: 'long'}), value));
  }
})();

calGrid.addEventListener('click', function(evt){
  var link = evt.target.closest('a[data-date]');
  if(link) {
    evt.preventDefault();
    // Alt-click adds the day to the ones already shown
    loadDay(link.dataset.date, evt.altKey);
    if(phone.matches && !evt.altKey) {
      setCalendarOpen(false);
    }
  }
});
calToggle.addEventListener('click', function(){
  setCalendarOpen(calPanel.hidden);
});
document.getElementById('cal-prev-day').addEventListener('click', function(){ stepDay(-1); });
document.getElementById('cal-next-day').addEventListener('click', function(){ stepDay(1); });
document.getElementById('cal-prev-month').addEventListener('click', function(){ showMonth(addMonths(shownMonth, -1)); });
document.getElementById('cal-next-month').addEventListener('click', function(){ showMonth(addMonths(shownMonth, 1)); });
document.getElementById('cal-today').addEventListener('click', function(){
  loadDay(database.today, false);
  if(phone.matches) {
    setCalendarOpen(false);
  }
});
calMonth.addEventListener('change', function(){ showMonth(calYear.value + '-' + calMonth.value); });
calYear.addEventListener('change', function(){ showMonth(calYear.value + '-' + calMonth.value); });

// Open beside the map on a wide screen, tucked under the date on a phone
setCalendarOpen(!phone.matches);

rangeGo.addEventListener('click', function(evt){
  evt.preventDefault();
  loadRange();
});

[rangeFrom, rangeTo, timezone].forEach(function(input){
  input.addEventListener('keydown', function(evt){
    if(evt.key == 'Enter') {
      loadRange();
    }
  });
});

// Nothing in the page has this id at the moment, the handler is kept for when it does
var playButton = document.getElementById('btn-play');
if(playButton) {
  playButton.addEventListener('click', playAnimation);
}

var tripCreate = document.getElementById('trip-create');
if(tripCreate) {
  tripCreate.addEventListener('click', function(){
    createTrip(tripCreate);
  });
}

if(rangeFrom.value == "") {
  // Today in the database's timezone, not the browser's or UTC
  loadDay(database.today, false);
} else {
  showMonth(lastMonth);
  loadRange();
}
