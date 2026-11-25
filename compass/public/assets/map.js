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
      map.fitBounds(full_bounds);
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
function loadDay(link, append) {
  if(!append) {
    removeVisibleLayers();
  }
  link.classList.add('selected');

  resetAnimation();

  var date = link.dataset.date;
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
  document.querySelectorAll('.calendar a.selected').forEach(function(link){
    link.classList.remove('selected');
  });
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

document.getElementById('calendar').addEventListener('click', function(evt){
  var link = evt.target.closest('.calendar a');
  if(link) {
    evt.preventDefault();
    // Alt-click adds the day to the ones already shown
    loadDay(link, evt.altKey);
  }
});

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
  var today = document.querySelector('.calendar a[data-date="'+database.today+'"]');
  if(today) {
    today.focus();
    loadDay(today, false);
  }
} else {
  loadRange();
}
