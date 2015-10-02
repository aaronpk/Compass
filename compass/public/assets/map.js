Array.prototype.clean = function(deleteValue) {
  for (var i = 0; i < this.length; i++) {
    if (this[i] == deleteValue) {         
      this.splice(i, 1);
      i--;
    }
  }
  return this;
};

/*
  Array.findRanges
  
  Turns this:
  
  ["a","a","a","b","b","c","c","c","c","c","a","a","c"]
  
  into this:
  
  {
    "a":[
      {
        "from":0,
        "to":2
      },
      {
        "from":10,
        "to":11
      }
    ],
    "b":[
      {
        "from":3,
        "to":4
      }
    ],
    "c":[
      {
        "from":5,
        "to":9
      },
      {
        "from":12,
        "to":12
      }
    ]
  }

*/

Array.prototype.findRanges = function() {
  var buckets = {};
  for(var i = 0; i < this.length; i++) {
    if(!(this[i] in buckets)) {
      buckets[this[i]] = [{
        from: i,
        to: i
      }]
    } else {
      var last = buckets[this[i]][ buckets[this[i]].length-1 ];
      if(i == last.to + 1) {
        last.to = i;
      } else {
        buckets[this[i]].push({
          from: i,
          to: i
        })
      }
    }
  }
  return buckets;
};

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

var visible_layers = [];
var visible_data = [];
var highlightedMarker;
var animatedMarker;
var timers = [];

var batteryChart;

/*
Chart.defaults.global.animation = false;
Chart.defaults.global.responsive = true;
*/

  
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

jQuery(function($){

  $('.calendar a').click(function(evt){
    var append = evt.altKey;

    if(!append) {
      $('.calendar a').removeClass('selected');
      if(visible_layers.length) {
        for(var i in visible_layers) {
          map.removeLayer(visible_layers[i]);
        }
      }
      visible_layers = [];
      visible_data = [];
    }
    $(this).addClass('selected');

    resetAnimation();

    var db_name = $("#database").data("name");
    var db_token = $("#database").data("token");

    $.get("/api/query?format=linestring&date="+$(this).data('date')+"&tz=America/Los_Angeles&token="+db_token, function(data){
      if(data.coordinates && data.coordinates.length > 0) {
        visible_data.push(data);
        visible_layers.push(L.geoJson(data, {
          style: geojsonLineOptions
        }).addTo(map));

        // If the new layer is completely outside the current view, zoom the map to fit all layers
        var layer = visible_layers[visible_layers.length - 1];
        var is_outside = false;
        if(!map.getBounds().intersects(layer.getBounds())) {
          is_outside = true;
        }

        if(is_outside) {
          var full_bounds;
          for(var i in visible_layers) {
            layer = visible_layers[i];
            if(full_bounds) {
              full_bounds.extend(layer.getBounds());
            } else {
              full_bounds = layer.getBounds();
            }
          }
          map.fitBounds(full_bounds);
        }
        
        var batteryStateBands = [];
        var buckets = data.properties.map(function(d){return d.battery_state}).findRanges();

        for(var i in buckets) {
          for(var j=0; j<buckets[i].length; j++) {
            switch(i) {
              case "charging":
                buckets[i][j].color = "rgba(193,236,171,0.4)";
                break;
              case "full":
                buckets[i][j].color = "rgba(171,204,236,0.4)";
                break;
              case "unplugged":
                buckets[i][j].color = "rgba(236,178,171,0.4)";
                break;
              default:
                buckets[i][j].color = "#ffffff";
                break;
            }
            batteryStateBands.push(buckets[i][j]);
          }
        }

        $('#battery-chart').highcharts({
          title: {
            text: '',
            style: {
              display: 'none'
            }
          },
          subtitle: {
            text: '',
            style: {
              display: 'none'
            }
          },
          chart: {
            height: 80
          },
          legend: {
            enabled: false
          },
          xAxis: {
            categories: data.properties.map(function(d){
              if(isNaN(d.timestamp)) {
                return d.timestamp.substr(11,5);
              } else {
                var date = new Date(d.timestamp * 1000);
                return date.getHours() + ":" + ("0" + date.getMinutes()).substr(-2);
              }
            }),
            plotBands: batteryStateBands,
            labels: {
              style: {
                fontSize: '8px'
              }
            }
          },
          yAxis: {
            title: {
              text: ''
            },
            plotLines: [{
              value: 0,
              width: 1,
              color: '#808080'
            }],
            max: 100,
            min: 0
          },
          series: [{
            name: 'Battery',
            data: data.properties.map(function(d,i){
              return {
                x: i, 
                y: ('battery_level' in d ? d.battery_level * 100 : -1), 
                state: d.battery_state
              }
            }),
            tooltip: {
              animation: true,
              pointFormat: '{point.state}<br><b>{point.y}</b>',
              valueSuffix: '%'
            },
            turboThreshold: 0
          }]
        });
        $('#battery-chart').mousemove(function(event){
          var chart = $('#battery-chart').highcharts();
          var percent = (event.offsetX - chart.plotLeft) / chart.plotWidth;
          if(percent >= 0 && percent <= 1) {
            var coord = pointFromGeoJSON(visible_data[0].coordinates[Math.round(percent * visible_data[0].coordinates.length)]);
            if(!highlightedMarker) {
              highlightedMarker = L.marker(coord).addTo(map);
            } else {
              highlightedMarker.setLatLng(coord);
            }
          }
        });
                
      }
    });

    return false;
  });

  function pointFromGeoJSON(geo) {
    return L.latLng(geo[1], geo[0])
  }

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

  $(".calendar a[data-date='"+((new Date()).toISOString().slice(0,10))+"']").focus().click();

  ////////////////////

  //batteryChart = new Chart(document.getElementById("battery-chart").getContext("2d"));

});
