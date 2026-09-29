/* AISSeaStats — statistics page. No framework, no build step. */
(function () {
  'use strict';

  var cfg = JSON.parse(document.getElementById('cfg').textContent);
  var state = { days: 30, period: 'day', topBy: 'passages', charts: {}, maps: {} };

  // ---------- helpers ----------
  function t(key, vars) {
    // Singular form "<key>.one" when the count is 1.
    if (vars && Number(String(vars.n).replace(/\D/g, '')) === 1 && cfg.i18n[key + '.one']) { key += '.one'; }
    var s = cfg.i18n[key] || key;
    if (vars) {
      Object.keys(vars).forEach(function (k) { s = s.split('{' + k + '}').join(vars[k]); });
    }
    return s;
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function $(id) { return document.getElementById(id); }
  function api(q, params) {
    var url = 'api.php?q=' + encodeURIComponent(q);
    Object.keys(params || {}).forEach(function (k) { url += '&' + k + '=' + encodeURIComponent(params[k]); });
    return fetch(url, { headers: { Accept: 'application/json' } }).then(function (r) {
      if (!r.ok) { throw new Error('HTTP ' + r.status); }
      return r.json();
    });
  }
  var locale = cfg.lang === 'fr' ? 'fr-FR' : 'en-GB';
  var nf = new Intl.NumberFormat(locale);
  var nf1 = new Intl.NumberFormat(locale, { maximumFractionDigits: 1, minimumFractionDigits: 1 });
  function num(n) { return n == null ? '–' : nf.format(n); }
  function nm(v) {
    if (v == null || v === '') { return '–'; }
    v = Number(v);
    return nf1.format(v) + ' NM';
  }
  function nmKm(v) {
    if (v == null || v === '') { return '–'; }
    return nm(v) + ' (' + nf.format(Math.round(Number(v) * 1.852)) + ' km)';
  }
  function dtf(opts) { return new Intl.DateTimeFormat(locale, Object.assign({ timeZone: cfg.tz }, opts)); }
  var fmtDateTime = dtf({ day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
  var fmtDate = dtf({ day: '2-digit', month: 'short', year: 'numeric' });
  var fmtHour = dtf({ hour: '2-digit', minute: '2-digit' });
  var fmtDayShort = new Intl.DateTimeFormat(locale, { day: '2-digit', month: 'short', timeZone: 'UTC' });
  var fmtMonth = new Intl.DateTimeFormat(locale, { month: 'short', year: '2-digit', timeZone: 'UTC' });
  function ago(ts) {
    var s = Math.max(0, Math.floor(Date.now() / 1000) - ts);
    if (s < 60) { return t('time.seconds', { n: s }); }
    if (s < 3600) { return t('time.minutes', { n: Math.floor(s / 60) }); }
    if (s < 86400) { return t('time.hours', { n: Math.floor(s / 3600) }); }
    return t('time.days', { n: Math.floor(s / 86400) });
  }
  function flag(cc) {
    if (!cc || !/^[A-Z]{2}$/.test(cc)) { return ''; }
    return String.fromCodePoint(0x1F1E6 + cc.charCodeAt(0) - 65, 0x1F1E6 + cc.charCodeAt(1) - 65);
  }
  function css(name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); }
  function vname(v) { return v.name || ('MMSI ' + v.mmsi); }

  // AIS ship type code -> category key
  function typeCat(st, vclass) {
    st = Number(st || 0);
    if (vclass === 'SAR') { return 'sar_aircraft'; }
    if (vclass === 'EMRG') { return 'emergency'; }
    if (st >= 70 && st <= 79) { return 'cargo'; }
    if (st >= 80 && st <= 89) { return 'tanker'; }
    if (st >= 60 && st <= 69) { return 'passenger'; }
    if (st >= 40 && st <= 49) { return 'hsc'; }
    if (st === 30) { return 'fishing'; }
    if (st === 31 || st === 32 || st === 52) { return 'tug'; }
    if (st === 36) { return 'sailing'; }
    if (st === 37) { return 'pleasure'; }
    if (st === 35) { return 'military'; }
    if (st === 50) { return 'pilot'; }
    if (st === 51 || st === 55 || st === 58) { return 'authority'; }
    if (st === 33 || st === 34 || st === 53 || st === 54) { return 'service'; }
    if (st === 0) { return 'unknown'; }
    return 'other';
  }
  function typeLabel(st, vclass) { return t('type.' + typeCat(st, vclass)); }

  var TAG_ICONS = {
    emergency: 'M12 2 1 21h22L12 2zm1 15h-2v2h2v-2zm0-8h-2v6h2V9z',
    military: 'M12 2 4 5v6c0 5 3.4 9.7 8 11 4.6-1.3 8-6 8-11V5l-8-3z',
    authority: 'M12 1 3 5v6c0 5.5 3.8 10.7 9 12 5.2-1.3 9-6.5 9-12V5l-9-4zm-1 15-4-4 1.4-1.4L11 13.2l5.6-5.6L18 9l-7 7z',
    yacht: 'M12 2v14H4l8-14zm2 3 6 11h-6V5zM2 18h20l-2 4H4l-2-4z',
    hazmat: 'M12 2 2 12l10 10 10-10L12 2zm-1 5h2v6h-2V7zm0 8h2v2h-2v-2z',
    large: 'M3 17h18l-2 4H5l-2-4zm3-2V9h4V5h4v4h4v6H6z',
    rare_flag: 'M5 3v18h2v-7h9l1 2h4V5h-6l-1-2H5z',
    watchlist: 'M12 5C7 5 2.7 8.1 1 12.5 2.7 16.9 7 20 12 20s9.3-3.1 11-7.5C21.3 8.1 17 5 12 5zm0 12.5a5 5 0 1 1 0-10 5 5 0 0 1 0 10zm0-8a3 3 0 1 0 0 6 3 3 0 0 0 0-6z'
  };
  function tagChip(tag, n) {
    var d = TAG_ICONS[tag] || TAG_ICONS.watchlist;
    return '<span class="chip"><svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="' + d + '"/></svg>' +
      esc(t('tag.' + tag)) + (n != null ? ' <span class="n">' + num(n) + '</span>' : '') + '</span>';
  }
  function tagChips(tags) {
    if (!tags) { return ''; }
    return tags.split(',').filter(Boolean).map(function (x) { return tagChip(x); }).join('');
  }
  function zoneLabel(z) {
    if (z == null) { return '?'; }
    if (z === 'STATION') { return t('zone.station'); }
    var key = 'dir.' + z;
    return cfg.i18n[key] ? t(key) : z;
  }
  function vlink(v) {
    return '<button type="button" class="vlink" data-mmsi="' + esc(v.mmsi) + '">' + esc(vname(v)) + '</button>';
  }

  // ---------- Chart.js defaults ----------
  function chartTheme() {
    Chart.defaults.color = css('--text-2');
    Chart.defaults.borderColor = css('--grid');
    Chart.defaults.font.family = getComputedStyle(document.body).fontFamily;
    Chart.defaults.font.size = 12;
    Chart.defaults.plugins.tooltip.backgroundColor = css('--surface');
    Chart.defaults.plugins.tooltip.titleColor = css('--text');
    Chart.defaults.plugins.tooltip.bodyColor = css('--text-2');
    Chart.defaults.plugins.tooltip.borderColor = css('--border');
    Chart.defaults.plugins.tooltip.borderWidth = 1;
    Chart.defaults.plugins.tooltip.padding = 10;
    Chart.defaults.plugins.tooltip.boxPadding = 4;
    Chart.defaults.animation = { duration: 250 };
  }
  function makeChart(id, config) {
    if (state.charts[id]) { state.charts[id].destroy(); }
    state.charts[id] = new Chart($(id), config);
    return state.charts[id];
  }

  // ---------- Status + KPIs ----------
  function renderSummary(s) {
    var st = $('status');
    if (!s.last_msg) {
      st.innerHTML = '<span class="dot warning"></span>' + esc(t('status.waiting'));
    } else {
      var age = Math.floor(Date.now() / 1000) - s.last_msg;
      var cls = age < 300 ? 'good' : (age < 3600 ? 'warning' : 'critical');
      var label = age < 300 ? t('status.live') : t('status.stale');
      st.innerHTML = '<span class="dot ' + cls + '"></span>' + esc(label) + ' · ' + esc(t('status.last', { ago: ago(s.last_msg) })) +
        ' · ' + esc(t('status.active', { n: num(s.active_now) }));
    }
    var rec = s.range_record;
    var tiles = [
      { label: t('kpi.today'), value: num(s.vessels.today), sub: t('kpi.new_today', { n: num(s.new_today) }) },
      { label: t('kpi.d7'), value: num(s.vessels.d7), sub: t('kpi.unique') },
      { label: t('kpi.d30'), value: num(s.vessels.d30), sub: t('kpi.unique') },
      { label: t('kpi.all'), value: num(s.vessels.all), sub: s.first_day ? t('kpi.since', { d: fmtDayShort.format(new Date(s.first_day + 'T00:00:00Z')) }) : '' },
      { label: t('kpi.msgs_today'), value: num(s.msgs_today), sub: t('kpi.messages') },
      { label: t('kpi.range'), value: s.range_today != null ? nm(s.range_today) : '–',
        sub: rec ? t('kpi.record', { d: nm(rec.max_dist_nm) }) : '' }
    ];
    $('kpis').innerHTML = tiles.map(function (k) {
      return '<div class="kpi"><div class="label">' + esc(k.label) + '</div><div class="value">' + esc(k.value) +
        '</div><div class="sub" title="' + esc(k.sub) + '">' + esc(k.sub) + '</div></div>';
    }).join('');
  }

  // ---------- Vessel counts ----------
  function renderCounts(data) {
    var s = data.series;
    var labels = s.map(function (p) {
      if (data.period === 'hour') { return fmtHour.format(new Date(p.t * 1000)); }
      if (data.period === 'month') { return fmtMonth.format(new Date(p.t + '-01T00:00:00Z')); }
      return fmtDayShort.format(new Date(p.t + 'T00:00:00Z'));
    });
    var color = css('--series-1');
    makeChart('chart-counts', {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: t('counts.vessels'),
          data: s.map(function (p) { return p.vessels; }),
          backgroundColor: color,
          hoverBackgroundColor: css('--accent'),
          borderRadius: { topLeft: 4, topRight: 4 },
          borderSkipped: 'bottom',
          maxBarThickness: 28,
          categoryPercentage: 0.86,
          barPercentage: 0.92
        }]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            callbacks: {
              title: function (items) {
                var p = s[items[0].dataIndex];
                if (data.period === 'hour') {
                  return fmtDateTime.format(new Date(p.t * 1000)) + ' – ' + fmtHour.format(new Date((p.t + 3600) * 1000));
                }
                if (data.period === 'month') { return new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(p.t + '-01T00:00:00Z')); }
                return new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(p.t + 'T00:00:00Z'));
              },
              label: function (item) { return ' ' + t('counts.vessels_n', { n: num(item.raw) }); },
              afterBody: function (items) {
                var p = s[items[0].dataIndex];
                var lines = [t('counts.msgs_n', { n: num(p.msgs) })];
                if (p['new'] != null) { lines.push(t('counts.new_n', { n: num(p['new']) })); }
                if (p.range != null) { lines.push(t('counts.range_n', { d: nm(p.range) })); }
                return lines;
              }
            }
          }
        },
        scales: {
          x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
          y: { beginAtZero: true, border: { display: false }, ticks: { precision: 0 }, title: { display: true, text: t('counts.axis') } }
        }
      }
    });
    var total = s.reduce(function (a, p) { return a + p.vessels; }, 0);
    $('counts-note').textContent = total === 0 ? t('counts.empty') : t('counts.note.' + data.period);
  }

  // ---------- Routes ----------
  function baseMap(el, opts) {
    var map = L.map(el, Object.assign({ scrollWheelZoom: false, attributionControl: true }, opts || {}));
    L.tileLayer(cfg.tiles, { maxZoom: 18, attribution: cfg.attribution }).addTo(map);
    return map;
  }
  function stationLatLng() {
    var s = cfg.station;
    return (s.lat != null && s.lon != null) ? [Number(s.lat), Number(s.lon)] : null;
  }
  function arrowHead(from, to, color, weight) {
    // Small triangle at 62 % of the segment, pointing to the exit.
    var f = 0.62;
    var lat = from[0] + (to[0] - from[0]) * f;
    var lon = from[1] + (to[1] - from[1]) * f;
    var ang = Math.atan2((to[0] - from[0]), (to[1] - from[1]) * Math.cos(lat * Math.PI / 180));
    var len = Math.sqrt(Math.pow(to[0] - from[0], 2) + Math.pow((to[1] - from[1]) * Math.cos(lat * Math.PI / 180), 2));
    var size = len * 0.05 + weight * 0.002;
    function pt(a, r) { return [lat + Math.sin(a) * r, lon + Math.cos(a) * r / Math.cos(lat * Math.PI / 180)]; }
    return L.polygon([pt(ang, size), pt(ang + 2.5, size * 0.9), pt(ang - 2.5, size * 0.9)],
      { color: color, weight: 1, fillColor: color, fillOpacity: 0.95, interactive: false });
  }
  function renderRoutes(data) {
    var el = $('routes-map');
    var color = css('--route-1');
    if (!state.maps.routes) {
      state.maps.routes = baseMap(el);
      state.maps.routesLayer = L.layerGroup().addTo(state.maps.routes);
    }
    var map = state.maps.routes;
    var layer = state.maps.routesLayer;
    layer.clearLayers();
    var st = stationLatLng();
    var bounds = [];
    if (st) {
      L.circleMarker(st, { radius: 6, color: css('--surface'), weight: 2, fillColor: css('--series-2'), fillOpacity: 1 })
        .bindTooltip(esc(cfg.station.name)).addTo(layer);
      bounds.push(st);
    }
    var routes = data.routes || [];
    var max = routes.length ? Number(routes[0].passages) : 1;
    var lines = [];
    routes.forEach(function (r, i) {
      var a = [Number(r.from_lat), Number(r.from_lon)];
      var b = [Number(r.to_lat), Number(r.to_lon)];
      var w = 2 + 6 * (Number(r.passages) / max);
      var op = 0.95 - i * 0.06;
      var line = L.polyline([a, b], { color: color, weight: w, opacity: op, lineCap: 'round' })
        .bindTooltip(esc(zoneLabel(r.from)) + ' → ' + esc(zoneLabel(r.to)) + ' · ' + esc(t('routes.passages_n', { n: num(r.passages) })), { sticky: true });
      line.addTo(layer);
      arrowHead(a, b, color, w).addTo(layer);
      L.circleMarker(a, { radius: 3, color: color, weight: 1, fillColor: css('--surface'), fillOpacity: 1, interactive: false }).addTo(layer);
      lines.push(line);
      bounds.push(a, b);
    });
    if (bounds.length > 1) {
      map.fitBounds(bounds, { padding: [24, 24], maxZoom: 11 });
    } else if (st) {
      map.setView(st, 9);
    } else {
      map.setView([48.5, 3], 5);
    }
    setTimeout(function () { map.invalidateSize(); }, 50);

    var list = $('routes-list');
    if (!routes.length) {
      list.innerHTML = '<li class="empty">' + esc(t('routes.empty')) + '</li>';
    } else {
      list.innerHTML = routes.map(function (r, i) {
        return '<li data-i="' + i + '"><span class="route-name">' + esc(zoneLabel(r.from)) + '<span class="arrow">→</span>' +
          esc(zoneLabel(r.to)) + '</span><span class="val">' + esc(t('routes.passages_n', { n: num(r.passages) })) +
          ' · ' + esc(t('routes.vessels_n', { n: num(r.vessels) })) + '</span></li>';
      }).join('');
      Array.prototype.forEach.call(list.querySelectorAll('li[data-i]'), function (li) {
        var line = lines[Number(li.getAttribute('data-i'))];
        li.addEventListener('mouseenter', function () { line.setStyle({ color: css('--series-2') }); line.bringToFront(); });
        li.addEventListener('mouseleave', function () { line.setStyle({ color: color }); });
      });
    }
    var dest = data.destinations || [];
    $('dest-list').innerHTML = dest.length ? dest.map(function (d) {
      return '<li><span>' + esc(d.destination) + '</span><span class="val">' + esc(t('routes.vessels_n', { n: num(d.vessels) })) + '</span></li>';
    }).join('') : '<li class="empty">' + esc(t('routes.no_dest')) + '</li>';
  }

  // ---------- Top vessels ----------
  function renderTop(data) {
    var tbody = $('top-table').querySelector('tbody');
    if (!data.rows.length) {
      tbody.innerHTML = '<tr><td class="empty">' + esc(t('top.empty')) + '</td></tr>';
      return;
    }
    tbody.innerHTML = data.rows.map(function (v, i) {
      var val;
      switch (data.by) {
        case 'days': val = t('top.days_n', { n: num(v.value) }); break;
        case 'length': val = num(v.value) + ' m'; break;
        case 'speed': val = nf1.format(v.value) + ' kn'; break;
        case 'distance': val = nm(v.value); break;
        default: val = t('top.passages_n', { n: num(v.value) });
      }
      return '<tr><td class="rank-n">' + (i + 1) + '</td><td>' + '<span class="flag" title="' + esc(v.country || '') + '">' +
        flag(v.country) + '</span>' + vlink(v) + '<span class="type">' + esc(typeLabel(v.shiptype, v.vclass)) +
        (v.length_m ? ' · ' + num(v.length_m) + ' m' : '') + '</span></td><td class="r">' + esc(val) + '</td></tr>';
    }).join('');
  }

  // ---------- Remarkable ----------
  function renderRemarkable(data) {
    var c = data.counts || {};
    var keys = Object.keys(c).sort(function (a, b) { return c[b] - c[a]; });
    $('remarkable-counts').innerHTML = keys.map(function (k) { return tagChip(k, c[k]); }).join('');
    var list = $('remarkable-list');
    if (!data.rows.length) {
      list.innerHTML = '<li class="empty">' + esc(t('remarkable.empty')) + '</li>';
      return;
    }
    list.innerHTML = data.rows.map(function (v) {
      return '<li><div><span class="flag">' + flag(v.country) + '</span>' + vlink(v) + '<span class="type">' +
        esc(typeLabel(v.shiptype, v.vclass)) + (v.length_m ? ' · ' + num(v.length_m) + ' m' : '') + '</span></div>' +
        '<div class="when">' + esc(fmtDateTime.format(new Date(v.start_ts * 1000))) + '</div>' +
        '<div class="chips">' + tagChips(v.tags) + '</div></li>';
    }).join('');
  }

  // ---------- Fleet ----------
  function hbar(id, labels, values, tooltipLabel) {
    var h = Math.max(120, labels.length * 26 + 30);
    $(id).parentNode.style.height = h + 'px';
    makeChart(id, {
      type: 'bar',
      data: { labels: labels, datasets: [{ data: values, backgroundColor: css('--series-1'), borderRadius: { topRight: 4, bottomRight: 4 }, borderSkipped: 'left', maxBarThickness: 18 }] },
      options: {
        indexAxis: 'y', responsive: true, maintainAspectRatio: false,
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (i) { return ' ' + tooltipLabel(i.raw); } } } },
        scales: {
          x: { beginAtZero: true, border: { display: false }, ticks: { precision: 0 } },
          y: { grid: { display: false }, border: { display: false } }
        }
      }
    });
  }
  function renderFleet(data) {
    var cats = {};
    data.types.forEach(function (r) {
      var k = typeCat(r.shiptype, null);
      cats[k] = (cats[k] || 0) + Number(r.vessels);
    });
    var entries = Object.keys(cats).map(function (k) { return [k, cats[k]]; }).sort(function (a, b) { return b[1] - a[1]; });
    hbar('chart-types', entries.map(function (e) { return t('type.' + e[0]); }), entries.map(function (e) { return e[1]; }),
      function (v) { return t('routes.vessels_n', { n: num(v) }); });
    var names = typeof Intl.DisplayNames === 'function' ? new Intl.DisplayNames([locale], { type: 'region' }) : null;
    hbar('chart-flags', data.flags.map(function (f) {
      var n = f.country;
      try { if (names) { n = names.of(f.country) || f.country; } } catch (e) { /* unknown code */ }
      return flag(f.country) + ' ' + n;
    }), data.flags.map(function (f) { return Number(f.vessels); }), function (v) { return t('routes.vessels_n', { n: num(v) }); });
  }

  // ---------- Range polar ----------
  function renderPolar(data) {
    var labels = [];
    for (var i = 0; i < 36; i++) {
      var deg = i * 10;
      labels.push(deg % 90 === 0 ? t('dir.' + ['N', 'E', 'S', 'W'][deg / 90]) : (deg % 30 === 0 ? deg + '°' : ''));
    }
    var c1 = css('--series-1');
    makeChart('chart-polar', {
      type: 'radar',
      data: {
        labels: labels,
        datasets: [
          { label: t('range.period'), data: data.period, borderColor: c1, backgroundColor: hexA(c1, 0.18), borderWidth: 2, pointRadius: 0, pointHoverRadius: 5, fill: true },
          { label: t('range.all'), data: data.all, borderColor: css('--text-3'), borderDash: [4, 4], borderWidth: 1.5, pointRadius: 0, pointHoverRadius: 4, fill: false }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 12, boxHeight: 2 } },
          tooltip: { callbacks: {
            title: function (items) { return t('range.sector', { a: items[0].dataIndex * 10, b: items[0].dataIndex * 10 + 10 }); },
            label: function (i) { return ' ' + i.dataset.label + ' : ' + nmKm(i.raw); }
          } }
        },
        scales: { r: { beginAtZero: true, angleLines: { color: css('--grid') }, grid: { color: css('--grid') },
          pointLabels: { color: css('--text-2'), font: { size: 12, weight: '600' } },
          ticks: { backdropColor: 'transparent', color: css('--text-3'), callback: function (v) { return v + ' NM'; } } } }
      }
    });
    var maxP = Math.max.apply(null, data.period);
    $('range-note').textContent = maxP > 0 ? t('range.note', { d: nmKm(maxP) }) : t('range.empty');
  }
  function hexA(hex, a) {
    var m = /^#?([0-9a-f]{6})$/i.exec(hex);
    if (!m) { return hex; }
    var n = parseInt(m[1], 16);
    return 'rgba(' + (n >> 16) + ',' + ((n >> 8) & 255) + ',' + (n & 255) + ',' + a + ')';
  }

  // ---------- Vessel dialog ----------
  var SILHOUETTE = '<svg viewBox="0 0 120 60" aria-hidden="true"><path fill="currentColor" d="M8 38h104l-12 16H22z"/><path fill="currentColor" opacity=".55" d="M34 22h40v14H34zM78 28h14v8H78zM44 10h8v12h-8z"/><path d="M4 58c6 0 6-3 12-3s6 3 12 3 6-3 12-3 6 3 12 3 6-3 12-3 6 3 12 3 6-3 12-3 6 3 12 3 6-3 12-3" fill="none" stroke="currentColor" stroke-width="2" opacity=".5"/></svg>';

  function openVessel(mmsi) {
    var dlg = $('vessel-dialog');
    var body = $('vd-body');
    body.innerHTML = '<p class="muted">' + esc(t('status.loading')) + '</p>';
    if (!dlg.open) { dlg.showModal(); }
    api('vessel', { mmsi: mmsi }).then(function (v) {
      var photo = v.photo
        ? '<a href="' + esc(v.photo.page) + '" target="_blank" rel="noopener"><img src="' + esc(v.photo.thumb) + '" alt="' + esc(vname(v)) + '" loading="lazy"></a>'
        : SILHOUETTE;
      var credit = v.photo
        ? '<div class="vd-credit">' + esc(t('vessel.photo_credit', { a: v.photo.author || '?', l: v.photo.license || '?' })) + ' · Wikimedia Commons</div>'
        : '<div class="vd-credit">' + esc(v.imo ? t('vessel.no_photo') : t('vessel.no_photo_imo')) + '</div>';
      var facts = [
        ['MMSI', v.mmsi],
        [t('vessel.imo'), v.imo],
        [t('vessel.eni'), v.eni],
        [t('vessel.callsign'), v.callsign],
        [t('vessel.type'), typeLabel(v.shiptype, v.vclass) + (v.shiptype ? ' (' + v.shiptype + ')' : '')],
        [t('vessel.class'), v.vclass ? t('class.' + v.vclass) : null],
        [t('vessel.flag'), v.country ? flag(v.country) + ' ' + v.country : null],
        [t('vessel.size'), v.length_m ? v.length_m + ' × ' + (v.beam_m || '?') + ' m' : null],
        [t('vessel.draught'), v.draught_m ? nf1.format(v.draught_m) + ' m' : null],
        [t('vessel.destination'), v.destination],
        [t('vessel.first_seen'), fmtDate.format(new Date(v.first_seen * 1000))],
        [t('vessel.last_seen'), fmtDateTime.format(new Date(v.last_seen * 1000)) + ' (' + ago(v.last_seen) + ')'],
        [t('vessel.passages'), num(v.passages) + ' · ' + t('top.days_n', { n: num(v.days_seen) })],
        [t('vessel.max_dist'), nmKm(v.max_dist_nm)],
        [t('vessel.max_speed'), v.max_speed_kn != null ? nf1.format(v.max_speed_kn) + ' kn' : null],
        [t('vessel.msgs'), num(v.msgs)]
      ].filter(function (f) { return f[1] != null && f[1] !== '' && f[1] !== 0; });
      var passages = (v.recent_passages || []).map(function (p) {
        var route = Number(p.closed) ? esc(zoneLabel(p.entry_zone)) + ' → ' + esc(zoneLabel(p.exit_zone)) : esc(t('vessel.ongoing'));
        return '<li>' + esc(fmtDateTime.format(new Date(p.start_ts * 1000))) + ' · ' + route +
          (p.max_dist_nm != null ? ' · ' + esc(nm(p.max_dist_nm)) : '') + '</li>';
      }).join('');
      body.innerHTML =
        '<div class="vd-head"><div><h2 id="vd-title"><span class="flag">' + flag(v.country) + '</span>' + esc(vname(v)) + '</h2>' +
        '<div class="chips vd-tags"></div></div>' +
        '<button type="button" class="icon-btn" id="vd-close" aria-label="' + esc(t('vessel.close')) + '">✕</button></div>' +
        '<div class="vd-grid"><div><div class="vd-photo">' + photo + '</div>' + credit + '</div>' +
        '<dl class="facts">' + facts.map(function (f) { return '<dt>' + esc(f[0]) + '</dt><dd>' + esc(f[1]) + '</dd>'; }).join('') + '</dl></div>' +
        (v.trace && v.trace.length ? '<div class="vd-map" id="vd-map"></div>' : '<p class="muted small">' + esc(t('vessel.no_trace')) + '</p>') +
        (passages ? '<div class="vd-passages"><h3 class="sub">' + esc(t('vessel.recent')) + '</h3><ul>' + passages + '</ul></div>' : '') +
        '<div class="vd-links"><span class="muted">' + esc(t('vessel.more')) + '</span>' +
        '<a href="' + esc(v.links.marinetraffic) + '" target="_blank" rel="noopener">MarineTraffic</a>' +
        '<a href="' + esc(v.links.vesselfinder) + '" target="_blank" rel="noopener">VesselFinder</a></div>';
      body.querySelector('.vd-tags').innerHTML = tagChips(v.tags);
      $('vd-close').addEventListener('click', function () { dlg.close(); });
      if (v.trace && v.trace.length) {
        var map = baseMap($('vd-map'), { zoomControl: true });
        var pts = v.trace.map(function (p) { return [p[0], p[1]]; });
        L.polyline(pts, { color: css('--series-1'), weight: 3, opacity: 0.9 }).addTo(map);
        var last = pts[pts.length - 1];
        L.circleMarker(last, { radius: 6, color: css('--surface'), weight: 2, fillColor: css('--series-1'), fillOpacity: 1 })
          .bindTooltip(esc(t('vessel.last_pos', { d: fmtDateTime.format(new Date(v.trace[v.trace.length - 1][2] * 1000)) }))).addTo(map);
        var st = stationLatLng();
        if (st) { L.circleMarker(st, { radius: 5, color: css('--surface'), weight: 2, fillColor: css('--series-2'), fillOpacity: 1 }).bindTooltip(esc(cfg.station.name)).addTo(map); }
        map.fitBounds(pts.length > 1 ? pts : [last, last], { padding: [20, 20], maxZoom: 13 });
        setTimeout(function () { map.invalidateSize(); }, 60);
      }
    }).catch(function () {
      body.innerHTML = '<p>' + esc(t('error.load')) + '</p><button type="button" class="btn secondary" id="vd-close">' + esc(t('vessel.close')) + '</button>';
      $('vd-close').addEventListener('click', function () { dlg.close(); });
    });
  }
  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.vlink') : null;
    if (b) { openVessel(b.getAttribute('data-mmsi')); }
  });
  $('vessel-dialog').addEventListener('click', function (e) {
    if (e.target === this) { this.close(); } // click on backdrop
  });

  // ---------- Search ----------
  var searchTimer = null;
  $('search').addEventListener('input', function () {
    var q = this.value.trim();
    clearTimeout(searchTimer);
    var ul = $('search-results');
    if (q.length < 2) { ul.hidden = true; return; }
    searchTimer = setTimeout(function () {
      api('search', { s: q }).then(function (rows) {
        ul.innerHTML = rows.length ? rows.map(function (v) {
          return '<li><button type="button" class="vlink-search" data-mmsi="' + esc(v.mmsi) + '"><span>' + flag(v.country) + ' ' +
            esc(vname(v)) + '</span><span class="muted small">' + esc(v.mmsi) + '</span></button></li>';
        }).join('') : '<li class="empty">' + esc(t('search.none')) + '</li>';
        ul.hidden = false;
      }).catch(function () { ul.hidden = true; });
    }, 250);
  });
  $('search-results').addEventListener('click', function (e) {
    var b = e.target.closest('.vlink-search');
    if (b) { this.hidden = true; openVessel(b.getAttribute('data-mmsi')); }
  });
  document.addEventListener('click', function (e) {
    if (!e.target.closest('.search')) { $('search-results').hidden = true; }
  });

  // ---------- Controls ----------
  function segment(id, attr, onChange) {
    var root = $(id);
    root.addEventListener('click', function (e) {
      var b = e.target.closest('button');
      if (!b) { return; }
      Array.prototype.forEach.call(root.querySelectorAll('button'), function (x) { x.classList.toggle('on', x === b); });
      onChange(b.getAttribute(attr));
    });
  }
  segment('counts-period', 'data-period', function (v) { state.period = v; loadCounts(); });
  segment('range-filter', 'data-days', function (v) { state.days = Number(v); loadPeriodWidgets(); });
  segment('top-by', 'data-by', function (v) { state.topBy = v; loadTop(); });

  $('theme-toggle').addEventListener('click', function () {
    var root = document.documentElement;
    var dark = root.getAttribute('data-theme') === 'dark' ||
      (!root.getAttribute('data-theme') && window.matchMedia('(prefers-color-scheme: dark)').matches);
    var next = dark ? 'light' : 'dark';
    root.setAttribute('data-theme', next);
    try { localStorage.setItem('aisseastats-theme', next); } catch (e) { /* ignore */ }
    rerender();
  });
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', rerender);

  // ---------- Loading ----------
  var last = {};
  function fail(id) { return function (err) { console.error(id, err); }; }
  function loadSummary() { return api('summary').then(function (d) { last.summary = d; renderSummary(d); }).catch(fail('summary')); }
  function loadCounts() { return api('counts', { period: state.period }).then(function (d) { last.counts = d; renderCounts(d); }).catch(fail('counts')); }
  function loadTop() { return api('top', { by: state.topBy, days: state.days }).then(function (d) { last.top = d; renderTop(d); }).catch(fail('top')); }
  function loadRemarkable() { return api('remarkable').then(function (d) { last.remarkable = d; renderRemarkable(d); }).catch(fail('remarkable')); }
  function loadPeriodWidgets() {
    api('routes', { days: state.days }).then(function (d) { last.routes = d; renderRoutes(d); }).catch(fail('routes'));
    api('fleet', { days: state.days }).then(function (d) { last.fleet = d; renderFleet(d); }).catch(fail('fleet'));
    api('polar', { days: state.days }).then(function (d) { last.polar = d; renderPolar(d); }).catch(fail('polar'));
    loadTop();
  }
  function rerender() {
    chartTheme();
    if (last.counts) { renderCounts(last.counts); }
    if (last.fleet) { renderFleet(last.fleet); }
    if (last.polar) { renderPolar(last.polar); }
    if (last.routes) { renderRoutes(last.routes); }
  }

  chartTheme();
  loadSummary();
  loadCounts();
  loadRemarkable();
  loadPeriodWidgets();
  setInterval(function () {
    if (document.hidden) { return; }
    loadSummary();
    loadRemarkable();
    if (state.period === 'hour') { loadCounts(); }
  }, 60000);
})();
