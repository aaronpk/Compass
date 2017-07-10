function speedSeries(response) {

  var data = response.linestring;

  var system = {
    label: 'mph',
    multiplier: 2.23694
  };
  
  if(document.getElementById('is-metric').value == true) {
    system.label = 'km/h';
    system.multiplier = 3.6;
  }

  var series = {
    name: "Speed",
    yAxis: 1,
    tooltip: {
      animation: true,
      pointFormatter: function(){
        moveMarkerToPosition(this);
        return '<b>'+this.y+system.label+'</b>';
      }
    },
    lineWidth: 1,
    color: '#a8a8a8',
    marker: {
      enabled: true,
      radius: 1,
      symbol: 'circle',
      fillColor: '#a8a8a8'
    },
    turboThreshold: 0,
    data: []
  };

  series.data = data.properties.map(function(d,i){
    return {
      x: new Date(d.unixtime*1000),
      y: ('speed' in d && d.speed >= 0 ? Math.round(d.speed * system.multiplier) : null), 
      location: data.coordinates[i]
    }
  });

  return [series];
}
