// Monthly digest on the stats page: switches month without reloading and
// draws the weekday chart. The numbers come from a JSON block in the page.
(function() {
  var data = JSON.parse(document.getElementById('stats-data').textContent);
  var months = data.months;
  var names = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  var perUnit = data.metric ? 1000 : 1609.344;
  var unit = data.metric ? 'km' : 'mi';
  var select = document.getElementById('digest-month');
  var styles = getComputedStyle(document.documentElement);
  var css = function(name) { return styles.getPropertyValue(name).trim(); };
  var chart;

  function format(metres) {
    var value = metres / perUnit;
    return value.toLocaleString(undefined, {maximumFractionDigits: value < 100 ? 1 : 0}) + ' ' + unit;
  }

  function show(index) {
    var month = months[index];
    select.value = index;
    document.getElementById('digest-title').textContent = names[index] + ' ' + data.year;
    document.getElementById('digest-distance').textContent = format(month.distance_m);
    document.getElementById('digest-active').textContent = month.active_days + '/' + month.days_in_month;
    document.getElementById('digest-countries').textContent = month.countries;
    document.getElementById('digest-cities').textContent = month.cities;
    document.getElementById('digest-prev').disabled = index == 0;
    document.getElementById('digest-next').disabled = index == 11;

    var top = document.getElementById('digest-top');
    top.textContent = '';
    month.top_cities.forEach(function(city) {
      var item = document.createElement('li');
      var name = document.createElement('span');
      name.textContent = city.name + ', ' + city.country;
      var days = document.createElement('span');
      days.className = 'muted';
      days.textContent = city.days + (city.days == 1 ? ' day' : ' days');
      item.appendChild(name);
      item.appendChild(days);
      top.appendChild(item);
    });
    if(!month.top_cities.length) {
      var none = document.createElement('li');
      none.className = 'muted';
      none.textContent = 'No stops recorded';
      top.appendChild(none);
    }

    var values = month.weekdays.map(function(metres) { return Math.round(metres / perUnit * 10) / 10; });
    if(chart) {
      chart.data.datasets[0].data = values;
      chart.update();
      return;
    }
    chart = new Chart(document.getElementById('digest-weekdays'), {
      type: 'bar',
      data: {
        labels: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'],
        datasets: [{data: values, backgroundColor: css('--accent'), borderRadius: 3}]
      },
      options: {
        maintainAspectRatio: false,
        animation: false,
        plugins: {
          legend: {display: false},
          tooltip: {callbacks: {label: function(item) { return item.parsed.y.toLocaleString() + ' ' + unit; }}}
        },
        scales: {
          x: {grid: {display: false}, ticks: {color: css('--muted')}},
          y: {beginAtZero: true, grid: {color: css('--chart-grid')}, ticks: {color: css('--muted')}}
        }
      }
    });
  }

  select.addEventListener('change', function() { show(parseInt(select.value, 10)); });
  document.getElementById('digest-prev').addEventListener('click', function() { show(Math.max(0, parseInt(select.value, 10) - 1)); });
  document.getElementById('digest-next').addEventListener('click', function() { show(Math.min(11, parseInt(select.value, 10) + 1)); });

  // Start on the current month for this year, otherwise the last month with any movement
  var start = 11;
  if(data.year == new Date().getFullYear()) {
    start = new Date().getMonth();
  } else {
    while(start > 0 && months[start].distance_m == 0) start--;
  }
  show(start);
})();

// Tabs: any role="tablist" whose tabs name their panel in aria-controls
document.querySelectorAll('[role="tablist"]').forEach(function(list) {
  var tabs = Array.prototype.slice.call(list.querySelectorAll('[role="tab"]'));
  function select(tab) {
    tabs.forEach(function(other) {
      var on = other == tab;
      other.setAttribute('aria-selected', on ? 'true' : 'false');
      other.tabIndex = on ? 0 : -1;
      document.getElementById(other.getAttribute('aria-controls')).hidden = !on;
    });
  }
  tabs.forEach(function(tab, index) {
    tab.addEventListener('click', function() { select(tab); });
    tab.addEventListener('keydown', function(evt) {
      var step = evt.key == 'ArrowRight' ? 1 : evt.key == 'ArrowLeft' ? -1 : 0;
      if(step) {
        var next = tabs[(index + step + tabs.length) % tabs.length];
        select(next);
        next.focus();
      }
    });
  });
});
