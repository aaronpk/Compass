var map = L.map('map', { zoomControl: false }).setView([45.516, -122.660], 14, null, null, 24);

var opts = {
  attribution: 'Map data &copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors, <a href="https://creativecommons.org/licenses/by-sa/2.0/">CC-BY-SA</a>, Imagery © <a href="https://www.mapbox.com/">Mapbox</a>',
  maxZoom: 18,
  zoomOffset: -1,
  tileSize: 512,
  id: 'mapbox/light-v10',
  accessToken: 'pk.eyJ1IjoiYWFyb25wayIsImEiOiJja3A0eXV2ZXIwMGt3MnVuc2Uzcm1yYzFuIn0.-_qwPOLRiQk8t56xs6vkfg'
};

if(getQueryVariable('attribution') == 0) {
  opts.attribution = 'Mapbox';
}

L.tileLayer('https://api.mapbox.com/styles/v1/{id}/tiles/{z}/{x}/{y}?access_token={accessToken}', opts).addTo(map);

if(getQueryVariable('controls') != 0) {
  new L.Control.Zoom({ position: 'topleft' }).addTo(map);
}

var geojsonLineOptions = {
  color: "#0033ff",
  weight: 4,
  opacity: 0.5
};

var startIcon = L.icon({
  iconUrl: '/assets/map-pin-start.png',
  iconSize: [18,28],
  iconAnchor: [9,28]
});

// Load the current location and show on the map

var currentLocationMarker;
var currentTrack;

function getCurrentLocation() {
  var interval = 5000;
  
  if(getQueryVariable('interval')) {
    interval = getQueryVariable('interval');
  }
  
  $.getJSON("/share/current.json?token="+$("#share_token").val(), function(data){
    if(data.data) {
      moveMarkerToPosition(data.data);
      map.setView(currentLocationMarker.getLatLng());
    }
    setTimeout(getCurrentLocation, interval);
  });
}

getCurrentLocation();






function moveMarkerToPosition(feature) {
  if(feature && feature.geometry) {
    var coord = pointFromGeoJSON(feature.geometry.coordinates);
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

function pointFromGeoJSON(geo) {
  return L.latLng(geo[1], geo[0])
}

function getQueryVariable(variable) {
  var query = window.location.search.substring(1);
  var vars = query.split('&');
  for (var i = 0; i < vars.length; i++) {
      var pair = vars[i].split('=');
      if (decodeURIComponent(pair[0]) == variable) {
          return decodeURIComponent(pair[1]);
      }
  }
}
