var query = new URLSearchParams(window.location.search);

var map = Compass.createMap('map', {
  maxZoom: 18,
  attribution: (query.get('attribution') == '0' ? 'Mapbox' : null),
  zoomControl: (query.get('controls') != '0')
});

var shareToken = document.getElementById('share_token').value;

// Load the current location and show on the map

var currentLocationMarker;
var currentTrack;
var lastSeenTimestamp;

function getCurrentLocation() {
  var interval = parseInt(query.get('interval'), 10) || 5000;

  Compass.getJSON("/share/current.json?token="+encodeURIComponent(shareToken)).then(function(data){
    if(data.data) {
      moveMarkerToPosition(data.data);
      if(currentLocationMarker && lastSeenTimestamp != data.data.properties.timestamp) {
        map.setView(currentLocationMarker.getLatLng());
      }
      lastSeenTimestamp = data.data.properties.timestamp;
    }
  }).catch(function(error){
    console.error(error);
  }).then(function(){
    // Keep polling whether or not this request worked
    setTimeout(getCurrentLocation, interval);
  });
}

function getRecentHistory() {
  Compass.getJSON("/share/history.json?token="+encodeURIComponent(shareToken)).then(function(data){
    if(data.linestring) {
      L.geoJSON(data.linestring, {
        style: Compass.lineStyle
      }).addTo(map);
    }
  }).catch(function(error){
    console.error(error);
  });
}

getCurrentLocation();
getRecentHistory();

function moveMarkerToPosition(feature) {
  if(feature && feature.geometry) {
    var coord = Compass.pointFromGeoJSON(feature.geometry.coordinates);
    if(coord) {
      if(!currentTrack) {
        currentTrack = L.polyline([coord, coord]).addTo(map);
      } else {
        currentTrack.addLatLng(coord);
      }

      if(!currentLocationMarker) {
        currentLocationMarker = L.marker(coord).addTo(map);
      } else {
        currentLocationMarker.setLatLng(coord);
      }
    }
  }
}
