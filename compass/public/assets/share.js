// A shared, live map. New locations arrive pushed by the server when it has
// nginx's nchan module set up, and by asking every few seconds otherwise.
var query = new URLSearchParams(window.location.search);

var map = Compass.createMap('map', {
  maxZoom: 18,
  attribution: (query.get('attribution') == '0' ? 'Mapbox' : null),
  zoomControl: (query.get('controls') != '0')
});

var shareToken = document.getElementById('share_token').value;
var statusBox = document.getElementById('share-status');
var followButton = document.getElementById('share-follow');
var metric = statusBox.dataset.metric == '1';

var marker, accuracyCircle, track;
var lastTimestamp = null;
var lastCoordinate = null;
// The map follows the marker until the viewer moves the map themselves
var following = true;

map.on('dragstart', function() {
  following = false;
  followButton.classList.remove('hidden');
});
followButton.addEventListener('click', function() {
  following = true;
  followButton.classList.add('hidden');
  if(marker) map.setView(marker.getLatLng());
});

// Draws a location unless it is one already shown
function showLocation(feature) {
  if(!feature || !feature.geometry) return;
  var coordinate = Compass.pointFromGeoJSON(feature.geometry.coordinates);
  if(!coordinate) return;
  var properties = feature.properties || {};
  var time = properties.timestamp ? Date.parse(properties.timestamp) || parseFloat(properties.timestamp) * 1000 : null;
  if(time && lastTimestamp && time <= lastTimestamp) return;
  if(time) lastTimestamp = time;

  if(!track) {
    track = L.polyline([coordinate], Compass.lineStyle).addTo(map);
  } else if(!lastCoordinate || !coordinate.equals(lastCoordinate)) {
    track.addLatLng(coordinate);
  }
  lastCoordinate = coordinate;

  if(!marker) {
    marker = L.marker(coordinate).addTo(map);
  } else {
    marker.setLatLng(coordinate);
  }

  var accuracy = parseFloat(properties.horizontal_accuracy);
  if(accuracy > 0) {
    if(!accuracyCircle) {
      accuracyCircle = L.circle(coordinate, {radius: accuracy, weight: 1, opacity: 0.4, fillOpacity: 0.12}).addTo(map);
    } else {
      accuracyCircle.setLatLng(coordinate).setRadius(accuracy);
    }
  }

  var speed = parseFloat(properties.speed);
  document.getElementById('share-speed').textContent = speed > 0.5
    ? Math.round(speed * (metric ? 3.6 : 2.23694)) + (metric ? ' km/h' : ' mph') : '';

  if(following) map.setView(coordinate);
  updateStatus();
}

function updateStatus() {
  var text = 'Waiting for a location…';
  if(lastTimestamp) {
    var seconds = Math.max(0, Math.round((Date.now() - lastTimestamp) / 1000));
    if(seconds < 10) text = 'Updated just now';
    else if(seconds < 90) text = 'Updated ' + seconds + ' seconds ago';
    else if(seconds < 5400) text = 'Updated ' + Math.round(seconds / 60) + ' minutes ago';
    else if(seconds < 172800) text = 'Updated ' + Math.round(seconds / 3600) + ' hours ago';
    else text = 'Updated ' + Math.round(seconds / 86400) + ' days ago';
  }
  document.getElementById('share-updated').textContent = text;
}
setInterval(updateStatus, 5000);

function shareEnded() {
  document.getElementById('share-updated').textContent = 'This shared link has ended.';
  document.getElementById('share-speed').textContent = '';
}

// The track so far, drawn once
Compass.getJSON('/share/history.json?token=' + encodeURIComponent(shareToken)).then(function(data) {
  if(data.linestring && data.linestring.coordinates.length) {
    var history = L.geoJSON(data.linestring, {style: Compass.lineStyle}).addTo(map);
    if(!marker && following) map.fitBounds(history.getBounds(), {maxZoom: Compass.fitMaxZoom});
  }
}).catch(function(error) { console.error(error); });

var pollTimer = null;
function poll() {
  var interval = parseInt(query.get('interval'), 10) || 5000;
  Compass.getJSON('/share/current.json?token=' + encodeURIComponent(shareToken)).then(function(data) {
    if(data.error) return shareEnded();
    if(data.data) showLocation(data.data);
  }).catch(function(error) {
    console.error(error);
  }).then(function() {
    // Keep asking whether or not this request worked
    pollTimer = setTimeout(poll, interval);
  });
}

// One request for where things stand now, then listen or keep asking
function start() {
  var live = statusBox.dataset.live;
  if(!live || !window.EventSource) return poll();

  Compass.getJSON('/share/current.json?token=' + encodeURIComponent(shareToken)).then(function(data) {
    if(data.data) showLocation(data.data);
  }).catch(function(error) { console.error(error); });

  var source = new EventSource(live);
  var failures = 0;
  source.onopen = function() { failures = 0; };
  source.onmessage = function(event) {
    var message;
    try { message = JSON.parse(event.data); } catch(e) { return; }
    (message.points || []).forEach(showLocation);
  };
  source.onerror = function() {
    // The browser reconnects by itself. If it keeps failing, the live
    // channel isn't there (or the share has ended), so ask instead.
    if(++failures >= 3) {
      source.close();
      if(!pollTimer) poll();
    }
  };
}
start();
