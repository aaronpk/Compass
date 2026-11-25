// World map of countries visited. Draws country outlines from world.geojson
// and fills the visited ones, darker for more days.
(function() {
  var data = JSON.parse(document.getElementById('world-data').textContent);
  var visited = {};
  data.countries.forEach(function(country) { visited[country.code] = country; });

  var styles = getComputedStyle(document.documentElement);
  var css = function(name) { return styles.getPropertyValue(name).trim(); };
  var shades = [css('--heat-1'), css('--heat-2'), css('--heat-3'), css('--heat-4')];

  function shade(days) {
    if(days >= 100) return shades[3];
    if(days >= 15) return shades[2];
    if(days >= 3) return shades[1];
    return shades[0];
  }

  function style(feature) {
    var country = visited[feature.properties.code];
    return {
      color: css('--border'),
      weight: 0.6,
      fillColor: country ? shade(country.days) : css('--heat-0'),
      fillOpacity: 1
    };
  }

  function describe(feature) {
    var country = visited[feature.properties.code];
    var text = document.createElement('div');
    var name = document.createElement('strong');
    name.textContent = feature.properties.name;
    text.appendChild(name);
    if(country) {
      var format = function(date) {
        return new Date(date + 'T00:00:00Z').toLocaleDateString(undefined, {year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC'});
      };
      var detail = document.createElement('div');
      detail.textContent = country.days + (country.days == 1 ? ' day' : ' days') + ', ' +
        (country.first == country.last ? format(country.first) : format(country.first) + ' – ' + format(country.last));
      text.appendChild(detail);
    }
    return text;
  }

  var map = L.map('world-map', {
    zoomSnap: 0.25,
    minZoom: 0.5,
    maxZoom: 6,
    worldCopyJump: true,
    attributionControl: false,
    maxBounds: [[-85, -220], [85, 220]]
  }).setView([25, 10], 1.5);

  var layers = {};
  fetch(data.outlines).then(function(response) { return response.json(); }).then(function(geojson) {
    var layer = L.geoJSON(geojson, {
      style: style,
      onEachFeature: function(feature, shape) {
        (layers[feature.properties.code] = layers[feature.properties.code] || []).push(shape);
        shape.bindTooltip(function() { return describe(feature); }, {sticky: true});
        shape.on('mouseover', function() { highlight(feature.properties.code, true); });
        shape.on('mouseout', function() { highlight(feature.properties.code, false); });
      }
    }).addTo(map);

    // Start zoomed to the countries visited, when there are any
    var bounds = null;
    Object.keys(visited).forEach(function(code) {
      (layers[code] || []).forEach(function(shape) {
        bounds = bounds ? bounds.extend(shape.getBounds()) : L.latLngBounds(shape.getBounds().getSouthWest(), shape.getBounds().getNorthEast());
      });
    });
    if(bounds && bounds.getEast() - bounds.getWest() < 300)
      map.fitBounds(bounds.pad(0.15), {maxZoom: 4});
  });

  function highlight(code, on) {
    (layers[code] || []).forEach(function(shape) {
      shape.setStyle({weight: on ? 1.8 : 0.6, color: on ? css('--text') : css('--border')});
      if(on) shape.bringToFront();
    });
    var row = document.querySelector('.world-list tr[data-code="' + code + '"]');
    if(row) row.classList.toggle('highlight', on);
  }

  document.querySelectorAll('.world-list tr[data-code]').forEach(function(row) {
    row.addEventListener('mouseenter', function() { highlight(row.dataset.code, true); });
    row.addEventListener('mouseleave', function() { highlight(row.dataset.code, false); });
  });
})();
