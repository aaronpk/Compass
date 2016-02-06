var map = L.map('map', { zoomControl: false }).setView([45.516, -122.660], 14, null, null, 24);

var layer = L.esri.basemapLayer("Topographic");
layer.maxZoom = 24;
layer.maxNativeZoom = 24;
layer.addTo(map);

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
var endIcon = L.icon({
  iconUrl: '/assets/map-pin-end.png',
  iconSize: [18,28],
  iconAnchor: [9,28]
});

var visible_layers = [];
var visible_data = [];
var highlightedMarker;
var animatedMarker;
var startMarker;
var endMarker;
var timers = [];

function pointFromGeoJSON(geo) {
  return L.latLng(geo[1], geo[0])
}

function resetAnimation() {
  if(animatedMarker) {
    map.removeLayer(animatedMarker);
  }
  if(timers.length > 0) {
    for(var i in timers) {
      clearTimeout(timers[i]);
    }
  }
}

function displayLineOnMap(response, options) {
  var data = response.linestring;

  if(data.coordinates && data.coordinates.length > 0) {
    // For any null coordinates, fill it in with the previous location
    var lastCoord = null;
    for(var i in data.coordinates) {
      if(data.coordinates[i] == null) {
        data.coordinates[i] = lastCoord;
      } else {
        lastCoord = data.coordinates[i];
      }
    }

    visible_data.push(data);
    visible_layers.push(L.geoJson(data, {
      style: geojsonLineOptions
    }).addTo(map));

    // Show the start/end pins if necessary
    if(options.pins) {
      startMarker = L.marker(pointFromGeoJSON(visible_data[0].coordinates[0]), {icon: startIcon});
      startMarker.addTo(map);
      endMarker = L.marker(pointFromGeoJSON(visible_data[0].coordinates[ visible_data[0].coordinates.length-1 ]), {icon: endIcon});
      endMarker.addTo(map);
    }

    // If the new layer is completely outside the current view, zoom the map to fit all layers
    var vlayer = visible_layers[visible_layers.length - 1];
    var is_outside = false;
    if(!map.getBounds().intersects(vlayer.getBounds())) {
      is_outside = true;
    }

    if(is_outside) {
      console.log('is outside');
      console.log(vlayer);
      var full_bounds;
      for(var i in visible_layers) {
      if(visible_layers[i].getBounds) {
          if(full_bounds) {
            full_bounds.extend(visible_layers[i].getBounds());
          } else {
            full_bounds = visible_layers[i].getBounds();
          }
        }
      }
      map.fitBounds(full_bounds);
    }

    showBatteryGraph(response);
  }
}

jQuery(function($){

  $('.calendar a').click(function(evt){
    var append = evt.altKey;

    if(!append) {
      removeVisibleLayers();
    }
    $(this).addClass('selected');

    resetAnimation();

    var db_name = $("#database").data("name");
    var db_token = $("#database").data("token");

    $.get("/api/query?format=linestring&date="+$(this).data('date')+"&tz="+$("#timezone").val()+"&token="+db_token, function(data){
      displayLineOnMap(data, {pins: false});
    });
    $("#range-from").val($(this).data('date')+' 00:00:00');
    $("#range-to").val($(this).data('date')+' 23:59:59');

    return false;
  });

  $('#range-go').click(function(evt) {
    var stateObj = {from: $('#range-from').val(), to: $('#range-to').val(), tz: $('#timezone').val()};
    var baseURL = "/map/" + $("#database").data('name');
    var historyURL = baseURL + "?from="+stateObj.from+"&to="+stateObj.to+"&tz="+stateObj.tz;
    window.history.pushState(stateObj, "GPS Log", historyURL)
    resetAnimation();
    removeVisibleLayers();

    $("#range-go").addClass("loading");
    var db_token = $("#database").data("token");
    $.get("/api/query?format=linestring&start="+$('#range-from').val()+"&end="+$('#range-to').val()+"&tz="+$("#timezone").val()+"&token="+db_token, function(data){
      $("#range-go").removeClass("loading");
      displayLineOnMap(data, {pins: true});
    });
    return false;
  })

  $('#btn-play').click(function(){
    console.log(visible_data[0].coordinates[0]);
    var point = pointFromGeoJSON(visible_data[0].coordinates[0]);

    resetAnimation();

    animatedMarker = L.marker(point);
    animatedMarker.addTo(map);

    timers = [];

    var interval = 3;
    for(var i in visible_data[0].coordinates) {
      (function(i){
        timers.push(setTimeout(function(){
          point = pointFromGeoJSON(visible_data[0].coordinates[i]);
          animatedMarker.setLatLng(point);
        }, interval*i));
      })(i);
    }
  });

  if($("#range-from").val() == "") {
    console.log("Autoselecting calendar day");
    $(".calendar a[data-date="+((new Date()).toISOString().slice(0,10))+"]").focus().click();
  } else {
    console.log("Loading range");
    $("#range-go").click();
  }

});

function removeVisibleLayers() {
  $('.calendar a').removeClass('selected');
  if(visible_layers.length) {
    for(var i in visible_layers) {
      map.removeLayer(visible_layers[i]);
    }
  }
  visible_layers = [];
  visible_data = [];
  if(startMarker) {
    map.removeLayer(startMarker);
    map.removeLayer(endMarker);
  }
}

function moveMarkerToPosition(point) {
  if(point.location) {
    var coord = pointFromGeoJSON(point.location);
    if(coord) {
      if(!highlightedMarker) {
        highlightedMarker = L.marker(coord).addTo(map);
      } else {
        highlightedMarker.setLatLng(coord);
      }
    }
  }
}

function pointFromGeoJSON(geo) {
  return L.latLng(geo[1], geo[0])
}
