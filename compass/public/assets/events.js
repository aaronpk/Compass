function collectEventSeries(data) {

  var events = data.events;

  var series = {
    "application_launched_with_location": {
      name: "Launched with Location",
      color: '#42C093',
      y: 90,
      data: []
    },
    "visit": {
      name: "Visit",
      type: 'scatter',
      color: '#8f799e',
      y: 80,
      data: []
    },
    "paused_location_updates": {
      name: "Paused Location Updates",
      color: '#a0876e',
      y: 70,
      data: []
    },
    "resumed_location_updates": {
      name: "Resumed Location Updates",
      color: '#819e73',
      y: 60,
      data: []
    },
    "exited_pause_region": {
      name: "Exited Pause Region",
      color: '#819e73',
      y: 50,
      data: []
    },
    "did_finish_deferred_updates": {
      name: "Finished Deferred Updates",
      color: '#9ea06e',
      y: 40,
      data: []
    },
    "did_enter_background": {
      name: "Entered Background",
      color: '#799b9e',
      y: 30,
      data: []
    },
    "will_resign_active": {
      name: "Will Resign Active",
      color: '#737f9e',
      y: 20,
      data: []
    },
    "will_terminate": {
      name: "Will Terminate",
      color: '#9e7773',
      y: 10,
      data: []
    }
  };

  for(var i=0; i<events.length; i++) {
    if(series[events[i].properties.action]) {
      series[events[i].properties.action].data.push({
        x: new Date(events[i].properties.unixtime*1000), 
        y: series[events[i].properties.action].y,
        location: (events[i].geometry ? events[i].geometry.coordinates : null)
      });
    }
  }
  
  var response = [];
  series = Object.values(series);
  
  for(var i=0; i<series.length; i++) {
    if(series[i].data.length > 0) {
      series[i].type = 'scatter';
      series[i].yAxis = 0;
      series[i].tooltip = {
        pointFormatter: function() {
          moveMarkerToPosition(this);
          var h = this.x.getHours();
          var m = this.x.getMinutes();
          var s = this.x.getSeconds();
          if(m < 10) m = '0'+m;
          if(s < 10) s = '0'+s;
          return h+':'+m+':'+s;
        }
      };
      response.push(series[i]);
    }
  }
  
  return response;
}
