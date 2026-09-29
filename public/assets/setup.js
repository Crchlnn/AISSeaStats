/* AISSeaStats — setup wizard helpers. Everything runs in the browser: config.json is never uploaded. */
(function () {
  'use strict';

  // Pre-select the browser's time zone on first display.
  var tz = document.getElementById('timezone');
  if (tz && tz.getAttribute('data-posted') === '0') {
    try {
      var zone = Intl.DateTimeFormat().resolvedOptions().timeZone;
      if (zone && Array.prototype.some.call(tz.options, function (o) { return o.value === zone || o.text === zone; })) {
        tz.value = zone;
      }
    } catch (e) { /* keep default */ }
  }

  var btn = document.getElementById('import-btn');
  if (!btn) { return; }
  var text = document.getElementById('import-text');
  var file = document.getElementById('import-file');
  var out = document.getElementById('import-result');
  var msgs = {
    fr: { ok: 'Champs pré-remplis : ', none: 'Aucune position trouvée dans ce fichier.', bad: 'Ce n’est pas un JSON valide.', name: 'nom', pos: 'position' },
    en: { ok: 'Fields filled in: ', none: 'No position found in this file.', bad: 'This is not valid JSON.', name: 'name', pos: 'position' }
  }[document.documentElement.lang === 'fr' ? 'fr' : 'en'];

  // Find the first object holding numeric lat/lon (managed mode: control.viewer, CLI JSON: server).
  function findStation(o, depth) {
    if (!o || typeof o !== 'object' || depth > 6) { return null; }
    var lat = Number(o.lat), lon = Number(o.lon);
    if (o.lat != null && o.lon != null && isFinite(lat) && isFinite(lon) && Math.abs(lat) <= 90 && Math.abs(lon) <= 180 && !(lat === 0 && lon === 0)) {
      return { lat: lat, lon: lon, name: typeof o.station === 'string' ? o.station : null };
    }
    var keys = Object.keys(o);
    // Prefer the viewer / server sections, then anything else.
    keys.sort(function (a, b) { return (/viewer|server/.test(b) ? 1 : 0) - (/viewer|server/.test(a) ? 1 : 0); });
    for (var i = 0; i < keys.length; i++) {
      var r = findStation(o[keys[i]], depth + 1);
      if (r) { return r; }
    }
    return null;
  }

  function apply(raw) {
    var data;
    try { data = JSON.parse(raw); } catch (e) { out.textContent = msgs.bad; return; }
    var st = findStation(data, 0);
    if (!st) { out.textContent = msgs.none; return; }
    var done = [];
    document.getElementById('station_lat').value = String(st.lat);
    document.getElementById('station_lon').value = String(st.lon);
    done.push(msgs.pos);
    if (st.name && !document.getElementById('station_name').value) {
      document.getElementById('station_name').value = st.name.slice(0, 48);
      done.push(msgs.name);
    }
    out.textContent = msgs.ok + done.join(', ');
    text.value = ''; // do not keep the file (it may contain a password hash) in the page
    document.getElementById('password').focus();
  }

  btn.addEventListener('click', function () {
    if (file.files && file.files[0]) {
      var r = new FileReader();
      r.onload = function () { apply(String(r.result)); };
      r.readAsText(file.files[0]);
    } else {
      apply(text.value);
    }
  });
})();
