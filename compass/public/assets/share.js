var map = L.map('map', { zoomControl: false }).setView([45.516, -122.660], 14, null, null, 24);

L.tileLayer('https://api.tiles.mapbox.com/v4/{id}/{z}/{x}/{y}.png?access_token={accessToken}', {
    attribution: 'Map data &copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors, <a href="https://creativecommons.org/licenses/by-sa/2.0/">CC-BY-SA</a>, Imagery © <a href="https://www.mapbox.com/">Mapbox</a>',
    maxZoom: 18,
    id: 'mapbox.streets',
    accessToken: 'pk.eyJ1IjoiYWFyb25wayIsImEiOiI1T0tpNjdzIn0.OQjXyI3xSt8Dj8na3l90Sg'
}).addTo(map);

new L.Control.Zoom({ position: 'topleft' }).addTo(map);

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

function getCurrentLocation() {
  $.getJSON("/share/current.json?token="+$("#share_token").val(), function(data){
    if(data.data) {
      moveMarkerToPosition(data.data);
      map.setView(currentLocationMarker.getLatLng());
    }
    setTimeout(getCurrentLocation, 5000);
  });
}

getCurrentLocation();






function moveMarkerToPosition(feature) {
  if(feature && feature.geometry) {
    var coord = pointFromGeoJSON(feature.geometry.coordinates);
    if(coord) {
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
