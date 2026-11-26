/*
  The Places pages: the list of places, and the page of one place. Both show
  fences on a map and let a circle be placed, moved and resized by hand.
  Only core Leaflet is used.
*/
(function(){

  var data = JSON.parse(document.getElementById('places-data').textContent);
  var map = Compass.createMap('places-map', { maxZoom: 19 });
  var hint = document.getElementById('places-map-hint');
  var style = getComputedStyle(document.documentElement);
  var accent = style.getPropertyValue('--accent').trim() || '#0b6e69';
  var quiet = { color: accent, weight: 2, opacity: 0.7, fillOpacity: 0.12 };
  var active = { color: accent, weight: 3, opacity: 1, fillOpacity: 0.25 };

  // Fences already saved, by id
  var layers = {};
  var bounds = L.latLngBounds([]);
  data.fences.forEach(function(fence){
    var layer = null;
    if(fence.kind == 'circle') {
      layer = L.circle([fence.lat, fence.lng], Object.assign({ radius: fence.radius }, quiet));
    } else if(fence.kind == 'polygon' && fence.geometry) {
      layer = L.geoJSON(fence.geometry, { style: quiet });
    }
    if(!layer) return;
    layer.bindTooltip(fence.name);
    if(fence.url) {
      // On the list, a fence opens its own page
      layer.on('click', function(e){
        L.DomEvent.stopPropagation(e);
        location.href = fence.url;
      });
    }
    layer.addTo(map);
    layers[fence.id] = layer;
    bounds.extend(layer.getBounds());
  });

  if(bounds.isValid()) {
    map.fitBounds(bounds.pad(0.3), { maxZoom: 16 });
  } else if(data.center) {
    map.setView(data.center, 15);
  }

  function handleIcon(name) {
    return L.divIcon({ className: 'place-handle ' + name, iconSize: [16, 16], iconAnchor: [8, 8] });
  }

  // The point due east of a centre, a number of metres away
  function eastOf(latlng, metres) {
    var dLng = metres / (111195 * Math.cos(latlng.lat * Math.PI / 180));
    return L.latLng(latlng.lat, latlng.lng + dLng);
  }

  ////////////////////////////////////////////////////////////
  // One circle being edited. It reads and writes the lat, lng and radius
  // fields of the form it belongs to.

  var editing = null;

  function CircleEditor(form, savedLayer) {
    this.form = form;
    this.saved = savedLayer || null;
    this.lat = form.elements.lat;
    this.lng = form.elements.lng;
    this.radius = form.elements.radius;
    this.circle = null;
    var self = this;
    this.radius.addEventListener('input', function(){
      if(editing === self) self.draw();
    });
  }

  CircleEditor.prototype.center = function() {
    var lat = parseFloat(this.lat.value), lng = parseFloat(this.lng.value);
    return isFinite(lat) && isFinite(lng) && (lat || lng) ? L.latLng(lat, lng) : null;
  };

  CircleEditor.prototype.metres = function() {
    var r = parseFloat(this.radius.value);
    return isFinite(r) && r > 0 ? r : 150;
  };

  CircleEditor.prototype.moveTo = function(latlng) {
    this.lat.value = latlng.lat.toFixed(5);
    this.lng.value = latlng.lng.toFixed(5);
    this.draw();
  };

  CircleEditor.prototype.draw = function() {
    var center = this.center();
    if(!center) return;
    var self = this;
    if(!this.circle) {
      if(this.saved) map.removeLayer(this.saved);
      this.circle = L.circle(center, Object.assign({ radius: this.metres(), interactive: false }, active)).addTo(map);
      this.pin = L.marker(center, { draggable: true, icon: handleIcon('center'), title: 'Drag to move', keyboard: false }).addTo(map);
      this.grip = L.marker(eastOf(center, this.metres()), { draggable: true, icon: handleIcon('edge'), title: 'Drag to resize', keyboard: false }).addTo(map);
      this.pin.on('drag', function(){
        self.lat.value = self.pin.getLatLng().lat.toFixed(5);
        self.lng.value = self.pin.getLatLng().lng.toFixed(5);
        self.circle.setLatLng(self.pin.getLatLng());
        self.grip.setLatLng(eastOf(self.pin.getLatLng(), self.metres()));
      });
      this.grip.on('drag', function(){
        var metres = Math.round(map.distance(self.pin.getLatLng(), self.grip.getLatLng()));
        metres = Math.max(50, Math.min(100000, metres));
        self.radius.value = metres;
        self.circle.setRadius(metres);
      });
      this.grip.on('dragend', function(){
        self.grip.setLatLng(eastOf(self.pin.getLatLng(), self.metres()));
      });
    } else {
      this.circle.setLatLng(center);
      this.circle.setRadius(this.metres());
      this.pin.setLatLng(center);
      this.grip.setLatLng(eastOf(center, this.metres()));
    }
  };

  CircleEditor.prototype.start = function() {
    if(editing && editing !== this) editing.stop();
    editing = this;
    this.draw();
    if(this.circle) map.fitBounds(this.circle.getBounds().pad(0.4), { maxZoom: 17 });
    if(hint) hint.hidden = false;
  };

  CircleEditor.prototype.stop = function() {
    if(this.circle) {
      map.removeLayer(this.circle);
      map.removeLayer(this.pin);
      map.removeLayer(this.grip);
      this.circle = null;
      if(this.saved) this.saved.addTo(map);
    }
    if(editing === this) editing = null;
  };

  map.on('click', function(e){
    if(editing) editing.moveTo(e.latlng);
  });

  ////////////////////////////////////////////////////////////
  // GeoJSON typed or loaded into a form: show it on the map as it is entered

  var preview = null;

  function showPreview(form) {
    var text = form.elements.geojson.value.trim();
    var status = form.querySelector('[data-geojson-status]');
    if(preview) { map.removeLayer(preview); preview = null; }
    if(status) status.textContent = '';
    if(!text) return;
    try {
      var layer = L.geoJSON(JSON.parse(text), {
        style: active,
        // A bare geometry is handed over as it is, a Feature with its geometry inside
        filter: function(feature){ return /Polygon$/.test((feature.geometry || feature).type); }
      });
      if(!layer.getLayers().length) throw new Error('none');
      preview = layer.addTo(map);
      map.fitBounds(layer.getBounds().pad(0.3), { maxZoom: 17 });
    } catch(err) {
      if(status) status.textContent = 'That doesn\'t look like a GeoJSON Polygon or MultiPolygon yet.';
    }
  }

  document.querySelectorAll('form[data-editor="polygon"]').forEach(function(form){
    form.elements.geojson.addEventListener('input', function(){ showPreview(form); });
    var file = form.querySelector('[data-geojson-file]');
    if(file) {
      file.addEventListener('change', function(){
        if(!file.files[0]) return;
        file.files[0].text().then(function(text){
          form.elements.geojson.value = text;
          showPreview(form);
        });
      });
    }
  });

  ////////////////////////////////////////////////////////////
  // Searching for a place by name. The search server (OpenStreetMap's
  // Nominatim unless another is configured) asks not to be queried on every
  // keystroke, so a search runs only when the form is sent.

  (function(){
    var form = document.getElementById('place-search');
    if(!form) return;
    var input = form.elements.q;
    var button = form.querySelector('button');
    var status = document.getElementById('place-search-status');
    var list = document.getElementById('place-results');
    var marker = null;
    var lastQuery = null;

    function say(text) {
      status.textContent = text;
      status.hidden = !text;
    }

    function clear() {
      list.hidden = true;
      list.textContent = '';
    }

    function choose(result) {
      var latlng = L.latLng(parseFloat(result.lat), parseFloat(result.lon));
      // boundingbox is [south, north, west, east]
      var box = result.boundingbox;
      if(box && box.length == 4) {
        map.fitBounds([[parseFloat(box[0]), parseFloat(box[2])], [parseFloat(box[1]), parseFloat(box[3])]], { maxZoom: 17 });
      } else {
        map.setView(latlng, 16);
      }
      clear();
      say('');
      if(marker) { map.removeLayer(marker); marker = null; }

      // Adding a circle: put it on the place that was found, and suggest its
      // name. A circle that is already saved is left where it is; the map
      // only moves, and a pin shows the spot.
      var adding = editing && !editing.saved;
      if(adding) {
        editing.moveTo(latlng);
        var name = editing.form.elements.name;
        if(name && !name.value && result.name) name.value = result.name.slice(0, 100);
      } else {
        marker = L.circleMarker(latlng, { radius: 7, color: '#fff', weight: 2, fillColor: accent, fillOpacity: 1, interactive: false }).addTo(map);
      }
    }

    function show(results) {
      clear();
      if(!results.length) {
        say('Nothing found. Try adding the city, or a street address.');
        return;
      }
      say('');
      results.forEach(function(result){
        var item = document.createElement('li');
        var pick = document.createElement('button');
        pick.type = 'button';
        var title = document.createElement('strong');
        // The full name starts with the short one; show the rest as the detail
        var full = result.display_name || '';
        var name = result.name || full.split(',')[0];
        title.textContent = name;
        var detail = document.createElement('span');
        detail.className = 'muted small';
        detail.textContent = full.indexOf(name + ', ') === 0 ? full.slice(name.length + 2) : full;
        pick.appendChild(title);
        pick.appendChild(detail);
        pick.addEventListener('click', function(){ choose(result); });
        item.appendChild(pick);
        list.appendChild(item);
      });
      list.hidden = false;
      // One clear answer needs no choosing
      if(results.length == 1) choose(results[0]);
    }

    form.addEventListener('submit', function(e){
      e.preventDefault();
      var query = input.value.trim();
      if(!query || button.disabled) return;
      if(query === lastQuery && !list.hidden) return;
      lastQuery = query;
      // Prefer results near what the map is showing, without ruling out the rest
      var view = map.getBounds();
      var params = new URLSearchParams({
        q: query, format: 'jsonv2', limit: 6,
        viewbox: [view.getWest(), view.getNorth(), view.getEast(), view.getSouth()].join(',')
      });
      button.disabled = true;
      say('Searching…');
      fetch(form.dataset.url + '?' + params, { headers: { 'Accept': 'application/json' } })
        .then(function(response){
          if(!response.ok) throw new Error('HTTP ' + response.status);
          return response.json();
        })
        .then(show)
        .catch(function(){
          clear();
          say('The search didn\'t answer. Try again in a moment.');
        })
        .then(function(){
          // The public server allows one search a second
          setTimeout(function(){ button.disabled = false; }, 1000);
        });
    });

    input.addEventListener('input', function(){
      if(!input.value) { clear(); say(''); }
    });

    // The note and the list float over the map, so they need a way to be
    // put away without choosing: Escape, or a click anywhere else
    function dismiss() {
      if(button.disabled && status.textContent == 'Searching…') return;
      clear();
      say('');
    }
    form.addEventListener('keydown', function(e){
      if(e.key == 'Escape') dismiss();
    });
    document.addEventListener('click', function(e){
      if(!form.contains(e.target)) dismiss();
    });
  })();

  ////////////////////////////////////////////////////////////
  // Which form is in use decides what the map is editing

  function highlight(id, on) {
    var layer = layers[id];
    if(layer && layer.setStyle) layer.setStyle(on ? active : quiet);
  }

  // The list: pointing at a place shows which one it is on the map
  document.querySelectorAll('a[data-fence]').forEach(function(link){
    var id = link.dataset.fence;
    ['mouseenter', 'focus'].forEach(function(type){ link.addEventListener(type, function(){ highlight(id, true); }); });
    ['mouseleave', 'blur'].forEach(function(type){ link.addEventListener(type, function(){ highlight(id, false); }); });
  });

  if(data.editing) {
    // The page of one place: its circle can be moved straight away, and a
    // shape is shown as it is until new GeoJSON is typed in
    var form = document.querySelector('form[data-fence="' + data.editing + '"]');
    if(form && form.dataset.editor == 'circle') {
      new CircleEditor(form, layers[data.editing]).start();
    } else {
      highlight(data.editing, true);
      if(hint) hint.hidden = true;
    }
    return;
  }

  // The list of places: the tabs of the "Add a place" card
  var newCircle = new CircleEditor(document.getElementById('add-circle'));

  function useNewForm() {
    var open = document.querySelector('[role="tabpanel"]:not([hidden])');
    if(preview) { map.removeLayer(preview); preview = null; }
    if(open && open.id == 'add-circle') {
      newCircle.start();
    } else {
      if(editing) editing.stop();
      hint.hidden = true;
      if(open && open.id == 'add-polygon') showPreview(open);
    }
  }

  var tabs = Array.prototype.slice.call(document.querySelectorAll('.places [role="tab"]'));
  function select(tab, focus) {
    tabs.forEach(function(t){
      var on = t === tab;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
    });
    if(focus) tab.focus();
    useNewForm();
  }
  tabs.forEach(function(tab, i){
    tab.addEventListener('click', function(){ select(tab); });
    tab.addEventListener('keydown', function(e){
      if(e.key == 'ArrowRight' || e.key == 'ArrowLeft') {
        e.preventDefault();
        select(tabs[(i + (e.key == 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length], true);
      }
    });
  });

  // A circle form that was sent back with a mistake keeps its position
  if(newCircle.center()) newCircle.draw();
  editing = newCircle;

})();
