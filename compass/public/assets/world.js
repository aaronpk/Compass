// World map of places visited. Countries are drawn from world.geojson and
// covered with a grid of hexagons (world-hex.json); the hexagons where time
// was spent on the ground are filled, darker for more days. The hexagons are
// big when the map is zoomed out and smaller as it is zoomed in.
//
// The grid is the one in app/Stats/Geo/HexGrid.php; keep the two in step.
(function() {
  var data = JSON.parse(document.getElementById('world-data').textContent);
  var visited = {};
  data.countries.forEach(function(country) { visited[country.code] = country; });

  var styles = getComputedStyle(document.documentElement);
  var css = function(name) { return styles.getPropertyValue(name).trim(); };
  var shades = [css('--hex-1'), css('--hex-2'), css('--hex-3'), css('--hex-4')];

  function shade(days) {
    if(days >= 100) return shades[3];
    if(days >= 15) return shades[2];
    if(days >= 3) return shades[1];
    return shades[0];
  }

  ////////////////////////////////////////////////////////////
  // Hexagon grids: pointy-top hexagons in the Mercator plane, odd rows half
  // a column to the east. A grid is named by its number of columns around
  // the world: 360 columns makes hexagons one degree of longitude wide.

  var ANGLES = [30, 90, 150, 210, 270, 330].map(function(degrees) {
    var angle = degrees * Math.PI / 180;
    return [Math.cos(angle), Math.sin(angle)];
  });

  function latitude(y) {
    return (2 * Math.atan(Math.exp(y * Math.PI / 180)) - Math.PI / 2) * 180 / Math.PI;
  }

  // The corners of a hexagon as [lat, lng] pairs
  function hexagon(column, row, columns) {
    var width = 360 / columns;
    var size = width / Math.sqrt(3);
    var x = width * (column + 0.5 * (row & 1));
    var y = 1.5 * size * row;
    if(x >= 180) x -= 360;
    return ANGLES.map(function(angle) {
      return [latitude(y + size * angle[1]), x + size * angle[0]];
    });
  }

  // Which grid to show at a zoom level: hexagons stay between about 8 and 16 pixels wide
  function gridFor(zoom) {
    if(zoom < 2.5) return 90;
    if(zoom < 3.5) return 180;
    if(zoom < 4.5) return 360;
    return 720;
  }

  ////////////////////////////////////////////////////////////

  var map = L.map('world-map', {
    preferCanvas: true,
    zoomSnap: 0.25,
    minZoom: 0.5,
    maxZoom: 7,
    worldCopyJump: true,
    attributionControl: false,
    maxBounds: [[-85, -220], [85, 220]]
  }).setView([25, 10], 1.5);

  // Bottom to top: country shapes (hover and tooltips), the grid of land
  // hexagons, the sea (which trims the grid to the coastlines), the visited
  // hexagons, then the borders
  var countryLayers = {};
  var gridLayer = L.layerGroup().addTo(map);
  var visitedLayer = L.layerGroup().addTo(map);
  var borderLayer = null;
  var seaLayer = null;
  var visitedShapes = [];
  // Land hexagons per grid, as they arrive: {columns: {country: [column, row, ...]}}
  var land = {};
  // Leaflet layers already built for a grid's land hexagons
  var landLayers = {};
  var fineRequested = false;
  var columns = null;
  // "all": anywhere on the ground. "stops": only where a stop was made.
  var view = data.flat ? 'all' : remembered();

  function remembered() {
    try {
      return localStorage.getItem('compass-world-view') == 'stops' ? 'stops' : 'all';
    } catch(e) {
      return 'all';
    }
  }

  function describeHex(hex) {
    var days = view == 'stops' ? hex[3] : hex[2];
    if(view == 'stops')
      return 'Stopped here on ' + days.toLocaleString() + (days == 1 ? ' day' : ' days');
    return days.toLocaleString() + (days == 1 ? ' day' : ' days') + ' here';
  }

  // One shape per country for all of its hexagons: a few hundred shapes to draw, not tens of thousands
  function drawGrid() {
    gridLayer.clearLayers();
    if(!land[columns]) {
      // The finest grid is a big file, fetched the first time the map is zoomed in that far
      if(columns == 720 && !fineRequested) {
        fineRequested = true;
        fetch(data.fineGrid).then(function(response) { return response.json(); }).then(function(grid) {
          land[720] = grid.levels[720];
          if(columns == 720) drawGrid();
        });
      }
      return;
    }
    if(!landLayers[columns]) {
      var level = land[columns];
      landLayers[columns] = Object.keys(level).map(function(code) {
        var cells = level[code], rings = [];
        for(var i = 0; i < cells.length; i += 2)
          rings.push([hexagon(cells[i], cells[i + 1], columns)]);
        return L.polygon(rings, {
          fillColor: css('--heat-0'), fillOpacity: 1, color: css('--surface'), weight: 1, interactive: false
        });
      });
    }
    landLayers[columns].forEach(function(shape) { gridLayer.addLayer(shape); });
    // Keep the order: grid, sea, visited hexagons, borders
    if(seaLayer) seaLayer.bringToFront();
    visitedShapes.forEach(function(shape) { shape.bringToFront(); });
    if(borderLayer) borderLayer.bringToFront();
  }

  function drawVisited() {
    visitedLayer.clearLayers();
    visitedShapes = [];
    (data.hexes[columns] || []).forEach(function(hex) {
      // The embed gets [column, row] only: no day counts, one colour
      var days = data.flat ? 1 : (view == 'stops' ? hex[3] : hex[2]);
      if(!days) return;
      var shape = L.polygon(hexagon(hex[0], hex[1], columns), {
        fillColor: data.flat ? shades[1] : shade(days), fillOpacity: 1, color: css('--surface'), weight: 1,
        interactive: !data.flat
      });
      if(!data.flat)
        shape.bindTooltip(function() { return describeHex(hex); }, {sticky: true});
      visitedShapes.push(shape);
      visitedLayer.addLayer(shape);
    });
    if(borderLayer) borderLayer.bringToFront();
  }

  // Switches to the grid that suits the zoom level
  function chooseGrid() {
    var wanted = gridFor(map.getZoom());
    if(wanted == columns) return;
    columns = wanted;
    drawGrid();
    drawVisited();
  }

  function describeCountry(feature) {
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

  function borderStyle(code, on) {
    return {fill: false, interactive: false, color: on ? css('--text') : css('--muted'), weight: on ? 1.8 : 0.5, opacity: on ? 1 : 0.6};
  }

  var borders = {};
  function highlight(code, on) {
    (borders[code] || []).forEach(function(shape) {
      shape.setStyle(borderStyle(code, on));
      if(on) shape.bringToFront();
    });
    var row = document.querySelector('.world-list tr[data-code="' + code + '"]');
    if(row) row.classList.toggle('highlight', on);
  }

  Promise.all([
    fetch(data.outlines).then(function(response) { return response.json(); }),
    fetch(data.grid).then(function(response) { return response.json(); })
  ]).then(function(loaded) {
    var geojson = loaded[0], grid = loaded[1];

    L.geoJSON(geojson, {
      style: {stroke: false, fillColor: css('--heat-0'), fillOpacity: 1},
      onEachFeature: function(feature, shape) {
        var code = feature.properties.code;
        (countryLayers[code] = countryLayers[code] || []).push(shape);
        shape.bindTooltip(function() { return describeCountry(feature); }, {sticky: true});
        shape.on('mouseover', function() { highlight(code, true); });
        shape.on('mouseout', function() { highlight(code, false); });
      }
    }).addTo(map);

    // The grid's hexagons overhang the coast. Rather than cut each one to
    // shape, the sea is painted back over them: one shape covering the world
    // with every country cut out of it (the even-odd fill makes each outline a
    // hole, and a lake inside a country sea again). Visited hexagons are drawn
    // above it, whole, so a visit to a small island or a harbour never disappears.
    var sea = [[[-89, -540], [-89, 540], [89, 540], [89, -540]]];
    geojson.features.forEach(function(feature) {
      feature.geometry.coordinates.forEach(function(polygon) {
        polygon.forEach(function(ring) {
          sea.push(ring.map(function(point) { return [point[1], point[0]]; }));
        });
      });
    });
    seaLayer = L.polygon(sea, {stroke: false, fillColor: css('--surface'), fillOpacity: 1, interactive: false}).addTo(map);

    Object.keys(grid.levels).forEach(function(level) { land[level] = grid.levels[level]; });
    chooseGrid();

    borderLayer = L.geoJSON(geojson, {
      style: function(feature) { return borderStyle(feature.properties.code, false); },
      onEachFeature: function(feature, shape) {
        (borders[feature.properties.code] = borders[feature.properties.code] || []).push(shape);
      }
    }).addTo(map);

    // Start zoomed to the hexagons visited, when there are any
    if(visitedShapes.length) {
      var bounds = L.latLngBounds([]);
      visitedShapes.forEach(function(shape) { bounds.extend(shape.getBounds()); });
      if(bounds.getEast() - bounds.getWest() < 300)
        map.fitBounds(bounds.pad(0.15), {maxZoom: 5});
    }
    map.on('zoomend', chooseGrid);
  });

  document.querySelectorAll('.world-list tr[data-code]').forEach(function(row) {
    row.addEventListener('mouseenter', function() { highlight(row.dataset.code, true); });
    row.addEventListener('mouseleave', function() { highlight(row.dataset.code, false); });
  });

  // Everywhere / Stops only
  var buttons = document.querySelectorAll('[data-world-view]');
  function showView(name) {
    view = name;
    buttons.forEach(function(button) {
      button.setAttribute('aria-pressed', button.dataset.worldView == name ? 'true' : 'false');
    });
    document.querySelectorAll('[data-world-note]').forEach(function(note) {
      note.hidden = note.dataset.worldNote != name;
    });
  }
  buttons.forEach(function(button) {
    button.addEventListener('click', function() {
      showView(button.dataset.worldView);
      try { localStorage.setItem('compass-world-view', view); } catch(e) {}
      drawVisited();
    });
  });
  showView(view);
})();
