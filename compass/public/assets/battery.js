function pointFromGeoJSON(geo) {
  if(geo) {
    return L.latLng(geo[1], geo[0])
  }
}

function showBatteryGraph(response) {

  var data = response.linestring;

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
      buckets[i][j].from = new Date(data.properties[buckets[i][j].from].unixtime * 1000);
      buckets[i][j].to = new Date(data.properties[buckets[i][j].to].unixtime * 1000);
      batteryStateBands.push(buckets[i][j]);
    }
  }

  $('#battery-chart').highcharts({
    chart: {
      height: 160,
      zoomType: 'x',
      panning: true,
      panKey: 'shift'
    },
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
    legend: {
      enabled: false
    },
    xAxis: {
      type: 'datetime',
      plotBands: batteryStateBands,
      labels: {
        rotation: -35,
        style: {
          fontSize: '8px'
        }
      }
    },
    yAxis: [{
      title: {
        text: 'Battery %'
      },
      plotLines: [{
        value: 0,
        width: 1,
        color: '#808080'
      }],
      max: 100,
      min: 0
    },{
      title: {
        text: 'Speed'
      },
      plotLines: [{
        value: 0,
        width: 1,
        color: '#dddddd'
      }],
      min: 0,
      opposite: true
    }],
    series: [
    {
      name: 'Battery',
      yAxis: 0,
      data: data.properties.map(function(d,i){
        return {
          x: new Date(d.unixtime*1000), // i,
          y: ('battery_level' in d ? Math.round(d.battery_level * 100) : 0), 
          state: d.battery_state,
          location: data.coordinates[i]
        }
      }),
      tooltip: {
        animation: true,
        pointFormatter: function(){
          moveMarkerToPosition(this);
          return this.state+'<br><b>'+this.y+'%</b>';
        }
      },
      turboThreshold: 0,
      lineWidth: 1,
      color: '#a8cff4',
      marker: {
        enabled: true,
        radius: 2,
        fillColor: '#7cb5ec'
      }
    }].concat(speedSeries(response)).concat(collectEventSeries(response)),
  });
}
