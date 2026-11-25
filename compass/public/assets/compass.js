/*
  Shared by the map page and the share page.
*/
var Compass = (function(){

  var attribution = 'Map data &copy; <a href="https://www.openstreetmap.org/">OpenStreetMap</a> contributors, <a href="https://creativecommons.org/licenses/by-sa/2.0/">CC-BY-SA</a>, Imagery © <a href="https://www.mapbox.com/">Mapbox</a>';

  // Creates the Leaflet map in the element with the given id. The Mapbox token
  // and style come from the element's data-mapbox-token and data-mapbox-style
  // attributes (set from config/compass.php).
  //   options.maxZoom      highest zoom level of the tile layer
  //   options.attribution  replaces the default attribution text
  //   options.zoomControl  false to leave out the zoom buttons
  function createMap(id, options) {
    options = options || {};
    var el = document.getElementById(id);
    var map = L.map(el, { zoomControl: false }).setView([45.516, -122.660], 14);

    // Without a token (MAPBOX_TOKEN isn't set) there are no tiles to ask for:
    // the tracks are drawn on a plain background
    if(el.dataset.mapboxToken) {
      L.tileLayer('https://api.mapbox.com/styles/v1/{id}/tiles/{z}/{x}/{y}?access_token={accessToken}', {
        attribution: options.attribution || attribution,
        maxZoom: options.maxZoom || 18,
        zoomOffset: -1,
        tileSize: 512,
        id: el.dataset.mapboxStyle,
        accessToken: el.dataset.mapboxToken
      }).addTo(map);
    }

    if(options.zoomControl !== false) {
      L.control.zoom({ position: 'topleft' }).addTo(map);
    }

    return map;
  }

  function pinIcon(name) {
    return L.icon({
      iconUrl: '/assets/map-pin-' + name + '.png',
      iconSize: [18,28],
      iconAnchor: [9,28]
    });
  }

  // GeoJSON coordinates are [longitude, latitude]
  function pointFromGeoJSON(geo) {
    if(geo) {
      return L.latLng(geo[1], geo[0]);
    }
  }

  // Fetches a URL and resolves with the parsed JSON response
  function getJSON(url) {
    return fetch(url, { credentials: 'same-origin' }).then(function(response){
      if(!response.ok) {
        throw new Error(url + ' returned HTTP ' + response.status);
      }
      return response.json();
    });
  }

  return {
    createMap: createMap,
    pinIcon: pinIcon,
    pointFromGeoJSON: pointFromGeoJSON,
    getJSON: getJSON,
    lineStyle: {
      color: "#0033ff",
      weight: 4,
      opacity: 0.5
    }
  };
})();
