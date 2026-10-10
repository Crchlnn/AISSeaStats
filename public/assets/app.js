/* AISSeaStats — statistics page. No framework, no build step. */
(function () {
  'use strict';

  var cfg = JSON.parse(document.getElementById('cfg').textContent);
  // One period drives the whole page; the vessel chart picks its granularity from it.
  var RANGES = {
    '2': { days: 2, period: 'hour' },
    '7': { days: 7, period: 'day' },
    '30': { days: 30, period: 'day' },
    '90': { days: 90, period: 'day' },
    '365': { days: 365, period: 'month', months: 12 },
    'all': { days: 3650, period: 'month', months: 0 }
  };
  var VIEW_KEY = 'aisseastats-view';
  var state = { range: '30', topBy: 'passages', charts: {}, maps: {}, fitRange: null };
  try {
    var saved = JSON.parse(localStorage.getItem(VIEW_KEY) || '{}');
    if (saved && RANGES[saved.range]) { state.range = saved.range; }
    if (saved && /^(passages|days|length|speed|distance)$/.test(saved.topBy || '')) { state.topBy = saved.topBy; }
  } catch (e) { /* storage unavailable: defaults */ }
  function saveView() {
    try { localStorage.setItem(VIEW_KEY, JSON.stringify({ range: state.range, topBy: state.topBy })); } catch (e) { /* ignore */ }
  }
  function range() { return RANGES[state.range]; }
  var NB = '\u00a0'; // keeps "12 NM", "2 h" on one line

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
    return nf1.format(v) + NB + 'NM';
  }
  function nmKm(v) {
    if (v == null || v === '') { return '–'; }
    return nm(v) + ' (' + nf.format(Math.round(Number(v) * 1.852)) + NB + 'km)';
  }
  function dtf(opts) { return new Intl.DateTimeFormat(locale, Object.assign({ timeZone: cfg.tz }, opts)); }
  var fmtDateTime = dtf({ day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });
  var fmtDate = dtf({ day: '2-digit', month: 'short', year: 'numeric' });
  var fmtHour = dtf({ hour: '2-digit', minute: '2-digit' });
  var fmtDayShort = new Intl.DateTimeFormat(locale, { day: '2-digit', month: 'short', timeZone: 'UTC' });
  var fmtMonth = new Intl.DateTimeFormat(locale, { month: 'short', year: '2-digit', timeZone: 'UTC' });
  var fmtHourNum = dtf({ hour: '2-digit', hourCycle: 'h23' });
  var fmtDayMonth = dtf({ day: '2-digit', month: 'short' });
  function localHour(ts) { return parseInt(fmtHourNum.format(new Date(ts * 1000)), 10) % 24; }
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
    // No animations: some browsers left a re-created chart stuck on its first frame (blank bars).
    Chart.defaults.animation = false;
  }
  // Dashed line between 23:00 and 00:00 on the 48 h view, so the two days read apart.
  var midnightPlugin = {
    id: 'midnight',
    beforeDatasetsDraw: function (chart) {
      var idx = chart.$midnights || [];
      if (!idx.length) { return; }
      var x = chart.scales.x, area = chart.chartArea, ctx = chart.ctx;
      var step = x.getPixelForValue(1) - x.getPixelForValue(0);
      ctx.save();
      ctx.strokeStyle = css('--text-3');
      ctx.lineWidth = 1;
      ctx.setLineDash([4, 4]);
      idx.forEach(function (i) {
        var px = Math.round(x.getPixelForValue(i) - step / 2) + 0.5;
        ctx.beginPath(); ctx.moveTo(px, area.top); ctx.lineTo(px, area.bottom + 6); ctx.stroke();
      });
      ctx.restore();
    }
  };
  function makeChart(id, config) {
    config.options = Object.assign({ animation: false }, config.options);
    var existing = state.charts[id];
    if (existing && existing.config.type === config.type) {
      existing.data = config.data;
      existing.options = config.options;
      existing.update('none');
      return existing;
    }
    if (existing) { existing.destroy(); }
    config.plugins = (config.plugins || []).concat(id === 'chart-counts' ? [midnightPlugin, eventPlugin] : []);
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
  // Distance bands of the vessel chart (also the squares of a bar's vessel list; colours match .band-0 to .band-3 in app.css).
  function bandDefs(lim) {
    lim = lim || [20, 50];
    return [
      { label: '< ' + lim[0] + NB + 'NM', color: css('--series-1') },
      { label: lim[0] + '–' + lim[1] + NB + 'NM', color: css('--series-3') },
      { label: '≥ ' + lim[1] + NB + 'NM', color: css('--series-2') },
      { label: t('counts.band_unknown'), color: hexA(css('--text-3'), 0.45) }
    ];
  }
  function bandSquare(k, label) {
    return '<span class="band-sq band-' + k + '" role="img" aria-label="' + esc(label) + '" title="' + esc(label) + '"></span>';
  }

  function renderCounts(data) {
    var s = data.series;
    var narrow = $('chart-counts').parentNode.clientWidth < 620;
    var hourStep = narrow ? 6 : 3;
    var labels = s.map(function (p) {
      if (data.period === 'hour') { return fmtHour.format(new Date(p.t * 1000)); }
      if (data.period === 'month') { return fmtMonth.format(new Date(p.t + '-01T00:00:00Z')); }
      return fmtDayShort.format(new Date(p.t + 'T00:00:00Z'));
    });
    function barTitle(i) {
      var p = s[i];
      if (data.period === 'hour') {
        return fmtDateTime.format(new Date(p.t * 1000)) + ' – ' + fmtHour.format(new Date((p.t + 3600) * 1000));
      }
      if (data.period === 'month') { return new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric', timeZone: 'UTC' }).format(new Date(p.t + '-01T00:00:00Z')); }
      return new Intl.DateTimeFormat(locale, { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(new Date(p.t + 'T00:00:00Z'));
    }
    // Vessels stacked by distance band; the band of a vessel is its furthest position in that hour, day or month.
    var BANDS = bandDefs(data.bands);
    var rows = s.map(function (p) {
      var b = (p.bands || [p.vessels, 0, 0, 0]).slice();
      var sum = b[0] + b[1] + b[2] + b[3];
      if (p.vessels > sum) { b[3] += p.vessels - sum; } // hour not split yet: shown as "unknown"
      return b;
    });
    function total(i) { var b = rows[i]; return b[0] + b[1] + b[2] + b[3]; }
    var shown = BANDS.map(function (_, k) { return k === 0 || rows.some(function (b) { return b[k] > 0; }); });
    var order = [];
    shown.forEach(function (on, k) { if (on) { order.push(k); } });
    // Rounded top only on the highest non-empty segment of each bar; 2 px surface gap between segments.
    var topOf = rows.map(function (b) { var top = -1; order.forEach(function (k) { if (b[k] > 0) { top = k; } }); return top; });
    var surface = css('--surface');
    var datasets = order.map(function (k) {
      return {
        label: BANDS[k].label,
        bandIndex: k,
        data: rows.map(function (b) { return b[k]; }),
        backgroundColor: BANDS[k].color,
        hoverBackgroundColor: BANDS[k].color,
        borderColor: surface,
        borderWidth: function (ctx) { return topOf[ctx.dataIndex] === k ? 0 : { top: 2 }; },
        borderRadius: function (ctx) { return topOf[ctx.dataIndex] === k ? { topLeft: 4, topRight: 4 } : 0; },
        borderSkipped: 'bottom',
        maxBarThickness: 28,
        categoryPercentage: 0.86,
        barPercentage: 0.92,
        stack: 'v'
      };
    });
    makeChart('chart-counts', {
      type: 'bar',
      data: { labels: labels, datasets: datasets },
      options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: {
            display: datasets.length > 1, position: 'top', align: 'end',
            labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'rectRounded', padding: 12 },
            onClick: function () { /* bands stay visible: totals must add up */ }
          },
          tooltip: {
            filter: function (item) { return item.raw > 0; },
            itemSort: function (a, b) { return b.datasetIndex - a.datasetIndex; },
            callbacks: {
              title: function (items) { return barTitle(items[0].dataIndex); },
              label: function (item) { return ' ' + t('counts.band_n', { b: item.dataset.label, n: num(item.raw) }); },
              afterBody: function (items) {
                var i = items.length ? items[0].dataIndex : 0;
                var p = s[i];
                var lines = [t('counts.vessels_n', { n: num(total(i)) }), t('counts.msgs_n', { n: num(p.msgs) })];
                if (p['new'] != null) { lines.push(t('counts.new_n', { n: num(p['new']) })); }
                if (p.range != null) { lines.push(t('counts.range_n', { d: nm(p.range) })); }
                return lines;
              }
            }
          }
        },
        scales: {
          x: data.period === 'hour' ? {
            // Every 3 h (6 h on a phone), and the date under each midnight: 00:00 / 29 sept.
            stacked: true,
            grid: { display: false },
            ticks: {
              autoSkip: false, maxRotation: 0,
              callback: function (value, index) {
                var p = s[index];
                if (!p) { return null; }
                var h = localHour(p.t);
                if (h % hourStep !== 0) { return null; }
                return h === 0 ? [this.getLabelForValue(value), fmtDayMonth.format(new Date(p.t * 1000))] : this.getLabelForValue(value);
              }
            }
          } : { stacked: true, grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
          y: { stacked: true, beginAtZero: true, border: { display: false }, ticks: { precision: 0 }, title: { display: true, text: t('counts.axis') } }
        },
        // Click a bar to list the vessels of that hour, day or month.
        onClick: function (evt, elements) {
          if (!elements.length) { return; }
          var i = elements[0].index, p = s[i];
          if (!p || !total(i)) { return; }
          showList(data.period === 'hour' ? { by: 'hour', t: p.t } : { by: data.period, value: p.t }, barTitle(i));
        },
        onHover: function (evt, elements) {
          var ok = elements.length && total(elements[0].index) > 0;
          evt.native.target.style.cursor = ok ? 'pointer' : 'default';
        }
      }
    });
    var chart = state.charts['chart-counts'];
    chart.$midnights = data.period === 'hour' ? s.map(function (p, i) { return localHour(p.t) === 0 ? i : -1; }).filter(function (i) { return i > 0; }) : [];
    // Propagation days: a marker above the bar, and a line under the chart.
    var events = data.events || [];
    var byDay = {};
    events.forEach(function (e) { byDay[e.t] = true; });
    chart.$events = data.period === 'day' ? s.map(function (p, i) { return byDay[p.t] ? { i: i, total: total(i) } : null; }).filter(Boolean) : [];
    chart.$eventColor = css('--series-2');
    chart.draw();
    var sumAll = rows.reduce(function (a, b, i) { return a + total(i); }, 0);
    $('counts-note').textContent = sumAll === 0 ? t('counts.empty') : t('counts.note.' + data.period);
    var ev = $('counts-events');
    if (!events.length) {
      ev.hidden = true;
      ev.innerHTML = '';
    } else {
      ev.hidden = false;
      ev.innerHTML = '<span class="ev-mark" aria-hidden="true"></span><b>' + esc(t('prop.title')) + '</b> ' + events.map(function (e) {
        return esc(t('prop.item', {
          d: fmtDayShort.format(new Date(e.t + 'T00:00:00Z')), max: nm(e.max), n: num(e.far), th: nm(e.threshold), usual: nm(e.usual)
        }));
      }).join(' · ') + ' · <a href="https://dxinfocentre.com/tropo_eur.html" target="_blank" rel="noopener">' + esc(t('prop.forecast')) + '</a>';
    }
  }
  // Small triangle above the bar of a propagation day.
  var eventPlugin = {
    id: 'propagation',
    afterDatasetsDraw: function (chart) {
      var evs = chart.$events || [];
      if (!evs.length) { return; }
      var x = chart.scales.x, y = chart.scales.y, ctx = chart.ctx;
      ctx.save();
      ctx.fillStyle = chart.$eventColor;
      evs.forEach(function (e) {
        var px = x.getPixelForValue(e.i), py = y.getPixelForValue(e.total) - 6;
        ctx.beginPath(); ctx.moveTo(px - 5, py - 7); ctx.lineTo(px + 5, py - 7); ctx.lineTo(px, py); ctx.closePath(); ctx.fill();
      });
      ctx.restore();
    }
  };

  // ---------- Routes ----------
  function baseMap(el, opts) {
    var map = L.map(el, Object.assign({ scrollWheelZoom: false, attributionControl: true }, opts || {}));
    // OpenStreetMap refuses tile requests without a Referer; send our origin.
    L.tileLayer(cfg.tiles, { maxZoom: 18, attribution: cfg.attribution, referrerPolicy: 'strict-origin-when-cross-origin' }).addTo(map);
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
    // Re-centre only when the period changes, not on the automatic refresh (keeps the user's zoom).
    if (state.fitRange !== state.range) {
      state.fitRange = state.range;
      if (bounds.length > 1) {
        map.fitBounds(bounds, { padding: [24, 24], maxZoom: 11 });
      } else if (st) {
        map.setView(st, 9);
      } else {
        map.setView([48.5, 3], 5);
      }
    }
    setTimeout(function () { map.invalidateSize(); }, 50);

    var list = $('routes-list');
    if (!routes.length) {
      list.innerHTML = '<li class="empty">' + esc(t('routes.empty')) + '</li>';
    } else {
      list.innerHTML = routes.map(function (r, i) {
        return '<li data-i="' + i + '" class="clickable" tabindex="0" role="button" data-list="route" data-from="' + esc(r.from) + '" data-to="' + esc(r.to) + '"' +
          ' data-title="' + esc(zoneLabel(r.from) + ' → ' + zoneLabel(r.to)) + '"><span class="route-name">' + esc(zoneLabel(r.from)) + '<span class="arrow">→</span>' +
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
      return '<li class="clickable" tabindex="0" role="button" data-list="dest" data-value="' + esc(d.dkey) + '" data-title="' + esc(d.destination) + '">' +
        '<span>' + esc(d.destination) + '</span><span class="val">' + esc(t('routes.vessels_n', { n: num(d.vessels) })) + '</span></li>';
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
        case 'length': val = num(v.value) + NB + 'm'; break;
        case 'speed': val = nf1.format(v.value) + NB + 'kn'; break;
        case 'distance': val = nm(v.value); break;
        default: val = t('top.passages_n', { n: num(v.value) });
      }
      return '<tr><td class="rank-n">' + (i + 1) + '</td><td>' + '<span class="flag" title="' + esc(v.country || '') + '">' +
        flag(v.country) + '</span>' + vlink(v) + '<span class="type">' + esc(typeLabel(v.shiptype, v.vclass)) +
        (v.length_m ? ' · ' + num(v.length_m) + NB + 'm' : '') + '</span></td><td class="r">' + esc(val) + '</td></tr>';
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
        esc(typeLabel(v.shiptype, v.vclass)) + (v.length_m ? ' · ' + num(v.length_m) + NB + 'm' : '') + '</span></div>' +
        '<div class="when">' + esc(fmtDateTime.format(new Date(v.start_ts * 1000))) + '</div>' +
        '<div class="chips">' + tagChips(v.tags) + '</div></li>';
    }).join('');
  }

  // ---------- Fleet ----------
  // HTML bars rather than a chart: labels stay readable on a phone and every row opens its vessel list.
  function bars(el, rows) {
    if (!rows.length) {
      el.innerHTML = '<li class="empty">' + esc(t('top.empty')) + '</li>';
      return;
    }
    var max = Math.max.apply(null, rows.map(function (r) { return r.n; }));
    el.innerHTML = rows.map(function (r) {
      return '<li><button type="button" class="bar-row" data-list="' + r.kind + '" data-value="' + esc(r.value) + '" data-title="' + esc(r.title) + '">' +
        '<span class="bar-label">' + (r.flag ? '<span class="bar-flag" aria-hidden="true">' + r.flag + '</span>' : '') + esc(r.label) + '</span>' +
        '<span class="bar-track"><span class="bar-fill"></span></span>' +
        '<span class="bar-n">' + num(r.n) + '</span></button></li>';
    }).join('');
    Array.prototype.forEach.call(el.querySelectorAll('.bar-fill'), function (f, i) {
      f.style.width = Math.max(2, rows[i].n / max * 100) + '%'; // CSSOM, allowed by the CSP
    });
  }
  var regionNames = null;
  try { regionNames = new Intl.DisplayNames([locale], { type: 'region' }); } catch (e) { regionNames = null; }
  function countryName(cc) {
    try { return (regionNames && regionNames.of(cc)) || cc; } catch (e) { return cc; }
  }
  function renderFleet(data) {
    var cats = {};
    data.types.forEach(function (r) {
      var k = typeCat(r.shiptype, null);
      cats[k] = (cats[k] || 0) + Number(r.vessels);
    });
    bars($('fleet-types'), Object.keys(cats).map(function (k) {
      return { kind: 'type', value: k, label: t('type.' + k), title: t('type.' + k), n: cats[k] };
    }).sort(function (a, b) { return b.n - a.n; }));
    bars($('fleet-flags'), data.flags.map(function (f) {
      var name = countryName(f.country);
      return { kind: 'flag', value: f.country, label: name, title: flag(f.country) + ' ' + name, flag: flag(f.country), n: Number(f.vessels) };
    }));
  }

  // ---------- Range polar ----------
  // Date (with year) and, when known, time of a range record; older records only have their day.
  var fmtRecTime = dtf({ day: 'numeric', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  var fmtRecDay = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });
  function recordWhen(r) {
    return r.ts ? fmtRecTime.format(new Date(r.ts * 1000)) : fmtRecDay.format(new Date(r.day + 'T00:00:00Z'));
  }
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
            label: function (i) { return ' ' + i.dataset.label + ' : ' + nmKm(i.raw); },
            // Who set the record and when; said once when the period's record is the all-time one.
            afterLabel: function (i) {
              var recs = i.datasetIndex === 0 ? data.period_rec : data.all_rec;
              var r = recs && recs[i.dataIndex];
              if (!r || !(i.raw > 0)) { return ''; }
              var p = data.period_rec && data.period_rec[i.dataIndex];
              if (i.datasetIndex === 1 && p && p.mmsi === r.mmsi && p.day === r.day && data.period[i.dataIndex] === i.raw) { return ''; }
              return '   ' + vname(r) + ' · ' + recordWhen(r);
            }
          } }
        },
        scales: { r: { beginAtZero: true, angleLines: { color: css('--grid') }, grid: { color: css('--grid') },
          pointLabels: { color: css('--text-2'), font: { size: 12, weight: '600' } },
          ticks: { backdropColor: 'transparent', color: css('--text-3'), callback: function (v) { return v + NB + 'NM'; } } } }
      }
    });
    var maxP = Math.max.apply(null, data.period);
    $('range-note').textContent = maxP > 0 ? t('range.note', { d: nmKm(maxP) }) : t('range.empty');
    var far = data.furthest || [];
    $('furthest-list').innerHTML = far.length ? far.map(function (v) {
      return '<li><span class="rank-main"><span class="flag">' + flag(v.country) + '</span>' + vlink(v) +
        '<span class="type">' + esc((v.ts ? fmtDateTime.format(new Date(v.ts * 1000)) : (v.day ? fmtDayShort.format(new Date(v.day + 'T00:00:00Z')) : '')) +
          (v.dir ? ' · ' + t('dir.' + v.dir) : '')) + '</span></span>' +
        '<span class="val">' + esc(nm(v.dist)) + '</span></li>';
    }).join('') : '<li class="empty">' + esc(t('range.empty')) + '</li>';
  }

  // ---------- Durations ----------
  function dur(sec) {
    sec = Math.max(0, Number(sec) || 0);
    if (sec < 3600) { return t('time.minutes', { n: Math.max(1, Math.round(sec / 60)) }); }
    if (sec < 3 * 3600 && Math.round(sec / 60) % 60 !== 0) {
      var m = Math.round(sec / 60);
      return t('dur.hm', { h: Math.floor(m / 60), m: String(m % 60).padStart(2, '0') });
    }
    if (sec < 48 * 3600) { return t('time.hours', { n: Math.round(sec / 3600) }); }
    return t('dur.days', { n: nf1.format(sec / 86400) });
  }

  // ---------- Regulars (time between passages) ----------
  function renderRegulars(data) {
    var tbody = $('regulars-table').querySelector('tbody');
    var rows = data.rows || [];
    if (!rows.length) {
      tbody.innerHTML = '<tr><td class="empty">' + esc(t('reg.empty')) + '</td></tr>';
      return;
    }
    tbody.innerHTML = rows.map(function (v, i) {
      return '<tr><td class="rank-n">' + (i + 1) + '</td><td><span class="flag" title="' + esc(v.country || '') + '">' + flag(v.country) + '</span>' +
        vlink(v) + '<span class="type">' + esc(typeLabel(v.shiptype, v.vclass)) + ' · ' + esc(t('top.passages_n', { n: num(v.passages) })) + '</span></td>' +
        '<td class="r"><span class="reg-every">' + esc(t('reg.every', { d: dur(v.avg) })) + '</span>' +
        '<span class="type">' + esc(t('reg.spread', { d: dur(v.sd) })) + '</span></td></tr>';
    }).join('');
  }

  // ---------- Busiest hours (weekday x hour) ----------
  var WEEKDAYS = [];
  (function () {
    var f = new Intl.DateTimeFormat(locale, { weekday: 'short', timeZone: 'UTC' });
    var fl = new Intl.DateTimeFormat(locale, { weekday: 'long', timeZone: 'UTC' });
    for (var d = 0; d < 7; d++) {
      var date = new Date(Date.UTC(2024, 0, 1 + d)); // 1 Jan 2024 was a Monday
      WEEKDAYS.push({ s: f.format(date).replace(/\.$/, ''), l: fl.format(date) });
    }
  })();
  function hh(h) { return String(h).padStart(2, '0') + ':00'; }
  function renderHeatmap(data) {
    var el = $('heatmap');
    var max = data.max || 0;
    if (!data.hours || max <= 0) {
      el.innerHTML = '<p class="empty">' + esc(t('heat.empty')) + '</p>';
      $('heatmap-note').textContent = '';
      return;
    }
    var html = '<div class="hm-row hm-head"><span></span>';
    for (var h = 0; h < 24; h++) { html += '<span class="hm-h' + (h % 6 === 0 ? ' major' : (h % 3 === 0 ? ' minor' : '')) + '">' + (h % 3 === 0 ? h : '') + '</span>'; }
    html += '</div>';
    var best = null, low = null;
    data.cells.forEach(function (row, d) {
      html += '<div class="hm-row"><span class="hm-day" title="' + esc(WEEKDAYS[d].l) + '">' + esc(WEEKDAYS[d].s) + '</span>';
      row.forEach(function (v, h) {
        var tip = WEEKDAYS[d].l + ' ' + hh(h) + '–' + hh((h + 1) % 24) + ' · ' + (v == null ? t('heat.nodata') : t('heat.avg', { n: nf1.format(v) }));
        html += '<span class="hm-cell' + (v == null ? ' nodata' : '') + '" data-v="' + (v == null ? '' : v) + '" title="' + esc(tip) + '" aria-label="' + esc(tip) + '"></span>';
        if (v != null) {
          if (!best || v > best.v) { best = { v: v, d: d, h: h }; }
          if (!low || v < low.v) { low = { v: v, d: d, h: h }; }
        }
      });
      html += '</div>';
    });
    html += '<div class="hm-legend"><span>' + esc(t('heat.less')) + '</span>';
    for (var k = 1; k <= 5; k++) { html += '<span class="hm-cell" data-v="' + (max * k / 5) + '"></span>'; }
    html += '<span>' + esc(t('heat.more', { n: nf1.format(max) })) + '</span></div>';
    el.innerHTML = html;
    var accent = css('--series-1');
    Array.prototype.forEach.call(el.querySelectorAll('.hm-cell[data-v]'), function (c) {
      var v = c.getAttribute('data-v');
      if (v === '') { return; }
      c.style.backgroundColor = hexA(accent, 0.1 + 0.9 * Math.min(1, Number(v) / max)); // CSSOM, allowed by the CSP
    });
    $('heatmap-note').textContent = t('heat.note', {
      days: num(data.days),
      best: WEEKDAYS[best.d].l + ' ' + hh(best.h), bv: nf1.format(best.v),
      low: WEEKDAYS[low.d].l + ' ' + hh(low.h), lv: nf1.format(low.v)
    });
  }

  // ---------- Station reception (uptime) ----------
  var fmtDayWeek = new Intl.DateTimeFormat(locale, { weekday: 'short', day: '2-digit', month: 'short', timeZone: 'UTC' });
  function renderUptime(data) {
    var el = $('uptime');
    var strip = data.strip || [];
    var labels = { ok: t('up.ok'), none: t('up.none'), now: t('up.now'), before: t('up.before'), future: '', skip: '' };
    var html = '<div class="up-row up-head"><span></span>';
    for (var h = 0; h < 24; h++) { html += '<span class="up-h">' + (h % 6 === 0 ? h : '') + '</span>'; }
    html += '</div>';
    strip.forEach(function (r) {
      var date = new Date(r.day + 'T00:00:00Z');
      var day = fmtDayWeek.format(date);
      html += '<div class="up-row"><span class="up-day" title="' + esc(day) + '">' + esc(fmtDayShort.format(date)) + '</span>';
      r.cells.forEach(function (c, h) {
        var tip = labels[c] ? day + ' ' + hh(h) + ' · ' + labels[c] : '';
        html += '<span class="up-cell ' + c + '"' + (tip ? ' title="' + esc(tip) + '" aria-label="' + esc(tip) + '"' : ' aria-hidden="true"') + '></span>';
      });
      html += '</div>';
    });
    html += '<div class="up-legend"><span class="up-cell ok"></span>' + esc(t('up.ok')) + '<span class="up-cell none"></span>' + esc(t('up.none')) +
      '<span class="up-cell before"></span>' + esc(t('up.before')) + '</div>';
    el.innerHTML = html;
    var sum = $('uptime-summary');
    if (data.uptime == null) {
      sum.textContent = t('up.waiting');
    } else {
      sum.textContent = t('up.summary', { p: nf1.format(data.uptime), period: periodLabel() }) + ' · ' +
        (data.gaps_total ? t('up.gaps', { n: num(data.gaps_total), d: dur(data.longest * 3600) }) : t('up.nogap'));
    }
    var gl = $('uptime-gaps');
    gl.innerHTML = (data.gaps || []).map(function (g) {
      return '<li><span>' + esc(fmtDateTime.format(new Date(g.from * 1000)) + ' → ' + fmtDateTime.format(new Date(g.to * 1000))) + '</span>' +
        '<span class="val">' + esc(dur(g.hours * 3600)) + '</span></li>';
    }).join('');
    gl.hidden = !(data.gaps || []).length;
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
    body.setAttribute('data-mmsi', String(mmsi));
    body.innerHTML = '<p class="muted">' + esc(t('status.loading')) + '</p>';
    if (!dlg.open) { dlg.showModal(); }
    api('vessel', { mmsi: mmsi }).then(function (v) {
      var photo = '<div class="vd-photo-wait">' + SILHOUETTE + '</div>';
      var credit = '<div class="vd-credit" id="vd-credit">' + esc(t('vessel.photo_loading')) + '</div>';
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
        [t('vessel.gaps'), v.gaps ? t('vessel.gaps_val', { avg: dur(v.gaps.avg), min: dur(v.gaps.min), max: dur(v.gaps.max) }) : null],
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
        '<div><dl class="facts">' + facts.map(function (f) { return '<dt>' + esc(f[0]) + '</dt><dd>' + esc(f[1]) + '</dd>'; }).join('') + '</dl>' +
        (v.name ? '' : '<p class="muted small vd-noname">' + esc(t(v.vclass === 'B' ? 'vessel.no_name_b' : 'vessel.no_name')) + '</p>') + '</div></div>' +
        (v.trace && v.trace.length ? '<div class="vd-map" id="vd-map"></div>' : '<p class="muted small">' + esc(t('vessel.no_trace')) + '</p>') +
        (passages ? '<div class="vd-passages"><h3 class="sub">' + esc(t('vessel.recent')) + '</h3><ul>' + passages + '</ul></div>' : '') +
        '<div class="vd-links"><span class="muted">' + esc(t('vessel.more')) + '</span>' +
        '<a href="' + esc(v.links.aiscatcher) + '" target="_blank" rel="noopener">aiscatcher.org</a>' +
        '<a href="' + esc(v.links.vesselfinder) + '" target="_blank" rel="noopener">VesselFinder</a>' +
        '<a href="' + esc(v.links.shipspotting) + '" target="_blank" rel="noopener">' + esc(t('vessel.shipspotting')) + '</a></div>';
      body.querySelector('.vd-tags').innerHTML = tagChips(v.tags);
      loadPhoto(v);
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
  // Photo after the card is open: the free sources can take a few seconds the first time.
  function loadPhoto(v) {
    api('photo', { mmsi: v.mmsi }).then(function (d) {
      var box = document.querySelector('#vd-body .vd-photo');
      var cr = $('vd-credit');
      if (!box || !cr || String(v.mmsi) !== String(($('vd-body').getAttribute('data-mmsi')))) { return; }
      var p = d.photo;
      var add = cfg.isAdmin ? ' <a href="admin.php?photo_mmsi=' + encodeURIComponent(v.mmsi) + '#photos">' + esc(p ? t('vessel.photo_replace') : t('vessel.photo_add')) + '</a>' : '';
      if (!p) {
        cr.innerHTML = esc(v.imo ? t('vessel.no_photo') : t('vessel.no_photo_imo')) + add;
        return;
      }
      var img = '<img src="' + esc(p.thumb) + '" alt="' + esc(vname(v)) + '" loading="lazy">';
      box.innerHTML = p.page ? '<a href="' + esc(p.page) + '" target="_blank" rel="noopener">' + img + '</a>' : img;
      var who = p.source === 'local'
        ? t('vessel.photo_local', { a: p.author || t('vessel.photo_station') })
        : t('vessel.photo_credit', { a: p.author || '?', l: p.license || '?' }) + ' · ' + (p.source === 'wikidata' ? 'Wikidata / Wikimedia Commons' : 'Wikimedia Commons');
      cr.innerHTML = esc(who) + add;
    }).catch(function () {
      var cr = $('vd-credit');
      if (cr) { cr.textContent = t('vessel.no_photo_error'); }
    });
  }

  document.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('.vlink') : null;
    if (b) { openVessel(b.getAttribute('data-mmsi')); return; }
    var l = e.target.closest ? e.target.closest('[data-list]') : null;
    if (l) { openList(l); }
  });
  document.addEventListener('keydown', function (e) {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.matches && e.target.matches('li[data-list]')) {
      e.preventDefault();
      openList(e.target);
    }
  });

  // ---------- Vessel list (a type, a flag, a route, a destination) ----------
  function periodLabel() { return t('range.label.' + state.range); }
  function openList(el) {
    var kind = el.getAttribute('data-list');
    var params = { by: kind, days: range().days };
    if (kind === 'route') {
      params.from = el.getAttribute('data-from');
      params.to = el.getAttribute('data-to');
    } else {
      params.value = el.getAttribute('data-value');
    }
    showList(params, el.getAttribute('data-title'));
  }
  // Lists for one bar of the vessel chart (an hour, a day or a month) carry their own time span.
  var TIME_LISTS = { hour: 1, day: 1, month: 1 };
  function showList(params, title) {
    var dlg = $('list-dialog');
    var body = $('ld-body');
    var kind = params.by;
    var timed = TIME_LISTS[kind] === 1;
    body.innerHTML = '<p class="muted">' + esc(t('status.loading')) + '</p>';
    if (!dlg.open) { dlg.showModal(); }
    api('list', params).then(function (d) {
      var rows = d.rows || [];
      var head = '<div class="vd-head"><div><h2 id="ld-title">' + esc(title) + '</h2>' +
        '<p class="muted small">' + esc(timed ? t('list.subtitle_bar', { n: num(rows.length) }) : t('list.subtitle', { n: num(rows.length), p: periodLabel() })) + '</p></div>' +
        '<button type="button" class="icon-btn ld-close" aria-label="' + esc(t('vessel.close')) + '">✕</button></div>';
      // A bar of the vessel chart: a square per vessel for its distance band, and the totals per band.
      var bands = d.bands ? bandDefs(d.limits) : null;
      var legend = '';
      if (bands) {
        legend = '<ul class="band-legend" aria-label="' + esc(t('list.bands')) + '">' + bands.map(function (b, k) {
          return k === 0 || d.bands[k] > 0
            ? '<li>' + bandSquare(k, b.label) + '<span>' + esc(b.label) + '</span> <strong>' + esc(num(d.bands[k])) + '</strong></li>' : '';
        }).join('') + '</ul>';
      }
      var table = rows.length ? '<table class="table"><tbody>' + rows.map(function (v) {
        var right = kind === 'route' ? t('top.passages_n', { n: num(v.passages) })
          : (v.msgs != null ? t('counts.msgs_n', { n: num(v.msgs) }) : fmtDateTime.format(new Date(v.last_seen * 1000)));
        var sq = bands ? bandSquare(v.band, bands[v.band] ? bands[v.band].label : '') : '';
        return '<tr><td>' + sq + '<span class="flag" title="' + esc(v.country || '') + '">' + flag(v.country) + '</span>' + vlink(v) +
          '<span class="type">' + esc(typeLabel(v.shiptype, v.vclass)) + (v.length_m ? ' · ' + num(v.length_m) + NB + 'm' : '') +
          (bands && v.dist != null ? ' · ' + nm(v.dist) : '') + '</span></td>' +
          '<td class="r">' + esc(right) + '</td></tr>';
      }).join('') + '</tbody></table>' : '<p class="empty">' + esc(t('top.empty')) + '</p>';
      body.innerHTML = head + legend + table + (d.truncated ? '<p class="muted small">' + esc(t('list.truncated')) + '</p>' : '');
      body.querySelector('.ld-close').addEventListener('click', function () { dlg.close(); });
    }).catch(function () {
      body.innerHTML = '<p>' + esc(t('error.load')) + '</p><button type="button" class="btn secondary ld-close">' + esc(t('vessel.close')) + '</button>';
      body.querySelector('.ld-close').addEventListener('click', function () { dlg.close(); });
    });
  }
  $('list-dialog').addEventListener('click', function (e) {
    if (e.target === this) { this.close(); }
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
  function markOn(id, attr, value) {
    Array.prototype.forEach.call($(id).querySelectorAll('button'), function (x) { x.classList.toggle('on', x.getAttribute(attr) === value); });
  }
  markOn('range', 'data-range', state.range);
  markOn('top-by', 'data-by', state.topBy);
  segment('range', 'data-range', function (v) { state.range = v; saveView(); loadCounts(); loadPeriodWidgets(); });
  segment('top-by', 'data-by', function (v) { state.topBy = v; saveView(); loadTop(); });

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
  // Each answer is ignored if the period changed meanwhile (fast clicks).
  function current(key, fn) { return function (d) { if (key === state.range) { fn(d); } }; }
  function loadCounts() {
    var r = range();
    var p = { period: r.period, days: r.days };
    if (r.months != null) { p.months = r.months; }
    return api('counts', p).then(current(state.range, function (d) { last.counts = d; renderCounts(d); })).catch(fail('counts'));
  }
  function loadTop() { return api('top', { by: state.topBy, days: range().days }).then(current(state.range, function (d) { last.top = d; renderTop(d); })).catch(fail('top')); }
  function loadRemarkable() { return api('remarkable').then(function (d) { last.remarkable = d; renderRemarkable(d); }).catch(fail('remarkable')); }
  function loadPeriodWidgets() {
    var k = state.range;
    var days = range().days;
    api('routes', { days: days }).then(current(k, function (d) { last.routes = d; renderRoutes(d); })).catch(fail('routes'));
    api('fleet', { days: days }).then(current(k, function (d) { last.fleet = d; renderFleet(d); })).catch(fail('fleet'));
    api('polar', { days: days }).then(current(k, function (d) { last.polar = d; renderPolar(d); })).catch(fail('polar'));
    api('regulars', { days: days }).then(current(k, function (d) { renderRegulars(d); })).catch(fail('regulars'));
    api('heatmap', { days: days }).then(current(k, function (d) { last.heatmap = d; renderHeatmap(d); })).catch(fail('heatmap'));
    api('uptime', { days: days }).then(current(k, function (d) { renderUptime(d); })).catch(fail('uptime'));
    loadTop();
  }
  function stamp() { $('updated').textContent = t('refresh.at', { t: fmtHour.format(new Date()) }); }
  function rerender() {
    chartTheme();
    if (last.counts) { renderCounts(last.counts); }
    if (last.fleet) { renderFleet(last.fleet); }
    if (last.polar) { renderPolar(last.polar); }
    if (last.routes) { renderRoutes(last.routes); }
    if (last.heatmap) { renderHeatmap(last.heatmap); }
  }

  chartTheme();
  loadSummary();
  loadCounts();
  loadRemarkable();
  loadPeriodWidgets();
  stamp();
  // Status and key figures every minute, everything else every 5 minutes (skipped while the tab is hidden).
  var ticks = 0;
  setInterval(function () {
    if (document.hidden) { return; }
    ticks++;
    loadSummary();
    if (ticks % 5 === 0) {
      loadCounts();
      loadRemarkable();
      loadPeriodWidgets();
      stamp();
    }
  }, 60000);
  // Catch up at once when the tab becomes visible again after a while.
  var hiddenAt = 0;
  document.addEventListener('visibilitychange', function () {
    if (document.hidden) { hiddenAt = Date.now(); return; }
    if (hiddenAt && Date.now() - hiddenAt > 5 * 60000) {
      loadSummary(); loadCounts(); loadRemarkable(); loadPeriodWidgets(); stamp();
    }
  });
})();
