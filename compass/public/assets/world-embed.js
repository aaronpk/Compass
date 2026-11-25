// Embeds a Compass world map in another website. Usage:
//
//   <div data-compass-world="https://compass.example/embed/world/TOKEN"></div>
//   <script async src="https://compass.example/assets/world-embed.js"></script>
//
// Optional attributes on the div: data-height (pixels, default 420) and
// data-year (show one year only).
(function() {
  document.querySelectorAll('[data-compass-world]').forEach(function(element) {
    if(element.querySelector('iframe')) return;
    var url = element.getAttribute('data-compass-world');
    if(!/^https?:\/\//.test(url)) return;
    if(element.getAttribute('data-year'))
      url += '?year=' + encodeURIComponent(element.getAttribute('data-year'));
    var frame = document.createElement('iframe');
    frame.src = url;
    frame.title = 'Countries visited';
    frame.loading = 'lazy';
    frame.style.border = '0';
    frame.style.width = '100%';
    frame.style.height = (parseInt(element.getAttribute('data-height'), 10) || 420) + 'px';
    element.appendChild(frame);
  });
})();
