/* AISSeaStats — admin page helpers (zone map, confirmations). */
(function () {
  'use strict';

  Array.prototype.forEach.call(document.querySelectorAll('form.confirm'), function (f) {
    f.addEventListener('submit', function (e) {
      if (!window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); }
    });
  });

  var el = document.getElementById('zone-map');
  if (!el || typeof L === 'undefined') { return; }
  var lat = parseFloat(el.getAttribute('data-lat'));
  var lon = parseFloat(el.getAttribute('data-lon'));
  var zones = [];
  try { zones = JSON.parse(el.getAttribute('data-zones')) || []; } catch (e) { zones = []; }
  var map = L.map(el, { scrollWheelZoom: false });
  L.tileLayer(el.getAttribute('data-tiles'), { maxZoom: 18, attribution: el.getAttribute('data-attribution') }).addTo(map);
  var accent = getComputedStyle(document.documentElement).getPropertyValue('--series-1').trim() || '#2a78d6';
  var st = getComputedStyle(document.documentElement).getPropertyValue('--series-2').trim() || '#eb6834';
  var bounds = null;
  function extend(b) { bounds = bounds ? bounds.extend(b) : L.latLngBounds(b.getSouthWest ? [b.getSouthWest(), b.getNorthEast()] : [b, b]); }
  if (!isNaN(lat) && !isNaN(lon)) {
    L.circleMarker([lat, lon], { radius: 6, color: '#fff', weight: 2, fillColor: st, fillOpacity: 1 }).addTo(map);
    extend(L.latLng(lat, lon).toBounds(20000));
  }
  zones.forEach(function (z) {
    var center = L.latLng(Number(z.lat), Number(z.lon));
    var radius = Number(z.radius_nm) * 1852;
    L.circle(center, { radius: radius, color: accent, weight: 2, fillOpacity: 0.12 })
      .bindTooltip(z.name, { permanent: true, direction: 'center' }).addTo(map);
    extend(center.toBounds(radius * 2));
  });
  if (bounds) { map.fitBounds(bounds, { padding: [30, 30], maxZoom: 12 }); }
  else { map.setView([48.5, 3], 5); }

  var preview = null;
  map.on('click', function (e) {
    document.getElementById('zone_lat').value = e.latlng.lat.toFixed(5);
    document.getElementById('zone_lon').value = e.latlng.lng.toFixed(5);
    var r = parseFloat(document.getElementById('zone_radius').value.replace(',', '.')) || 2;
    if (preview) { map.removeLayer(preview); }
    preview = L.circle(e.latlng, { radius: r * 1852, color: st, weight: 2, dashArray: '4 4', fillOpacity: 0.08 }).addTo(map);
    document.getElementById('zone_name').focus();
  });
})();
