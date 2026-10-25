/* SPDX-License-Identifier: GPL-3.0-or-later. Flood Watcher. Copyright (C) 2026 Vernon Spain. See LICENSE. */
/**
 * Flood Watcher dashboard.
 *
 * Reads data/sensors.json (rewritten every 10-15 minutes by the collectors) and draws the gauges
 * on a map. Selecting a gauge shows a dial, quick facts and a 3-day graph in the side panel,
 * loading data/series/<ID>.json for the graph.
 * Also draws road closures and flooding from QLDTraffic (data/traffic.json), flood cameras (data/cameras.json), faint flood map shading (data/overlays/), and a
 * place search using OpenStreetMap Nominatim (one request per search, never while typing).
 * One catchment is shown at a time, chosen in the header (data/catchments.json lists them; the
 * address keeps the choice as ?c=<key>). Everything in the data carries its catchment.
 * All text from the data is inserted with textContent, never as HTML.
 */
(function () {
  'use strict';

  var REFRESH_MS = 5 * 60 * 1000;   // Re-check the data every 5 minutes.
  var STALE_MINUTES = 30;           // Warn if the newest reading is older than this.
  var TZ = 'Australia/Brisbane';

  // Escape text from outside sources for the few places it goes into HTML. Leaflet tooltips and
  // popups render a string argument as innerHTML (in every Leaflet version), so a gauge, dam,
  // camera or road name that contained markup would otherwise run. Names reach the map as plain
  // text, so escaping is invisible for them and only neutralises a crafted one. Popups build DOM
  // nodes with textContent and don't need this.
  var ESC = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  function esc(s) {
    return String(s === null || s === undefined ? '' : s).replace(/[&<>"']/g, function (c) { return ESC[c]; });
  }

  var TREND_LABELS = {
    rising_fast: 'Rising fast',
    rising: 'Rising',
    steady: 'Steady',
    falling: 'Falling',
    unknown: 'Trend unknown'
  };

  var TREND_COLOURS = {
    rising_fast: '#c62828',
    rising: '#e08a00',
    steady: '#2e8b57',
    falling: '#1f6fd1',
    unknown: '#a5adb8'
  };

  var FLOOD_LABELS = {
    below: 'Below minor flood level',
    minor: 'Minor flooding',
    moderate: 'Moderate flooding',
    major: 'Major flooding'
  };

  // Rain dial bands for a 24-hour total (mm). Colours come from the CSS rain ramp.
  var RAIN_BANDS = [
    [0, 10, '--rain-1'],
    [10, 25, '--rain-2'],
    [25, 50, '--rain-3'],
    [50, 100, '--rain-4'],
    [100, Infinity, '--rain-5']
  ];

  var state = {
    data: null,
    selected: null,
    fitted: false,
    centred: false,
    streamLayer: null,
    rainLayer: null,
    rainKey: 'rain_24h',
    cameraLayer: null,
    trafficLayer: null,
    communityLayer: null,
    overlays: [],        // Flood map layers: {key, label, hover, source, on, colour, feats, group, loading}.
    overlayIndex: null,
    searchPin: null,
    watchLayer: null,
    watchFallback: [],
    alerts: null,
    alertsExpanded: false,
    alertControl: null,
    alertPill: null,
    alertCard: null,
    alertOpen: false,
    alertTimer: null,
    alertSeen: {},
    alertNone: true,
    predict: null,
    predictLayer: null,
    predictOn: true,
    predictKey: '',
    areaLayer: null,
    areaFallback: null,
    catchment: null,      // The catchment shown (its key).
    catchments: [],       // [{key, name, status: 'live' | 'construction', bbox: [S, W, N, E]}].
    outlines: {},         // Catchment key -> its outline layer, once loaded.
    alertScope: 'here',   // Alerts card: 'here' (this catchment, in view) or 'all' (every catchment).
    trafficData: null,
    cameras: []
  };

  // ---------- Catchments ----------

  var CATCHMENT_KEY = 'floodwatcher.catchment';

  /** The catchment something belongs to (data from before catchments were added is the Brisbane River's). */
  function catchmentOf(x) { return (x && x.catchment) || 'brisbane'; }

  /** True if something belongs to the catchment on screen. */
  function inCatchment(x) { return catchmentOf(x) === state.catchment; }

  /** True if a BoM warning is about the catchment on screen. */
  function warningHere(w) { return (w.catchments || ['brisbane']).indexOf(state.catchment) >= 0; }

  /** A catchment's entry by key (null if unknown). */
  function catchmentInfo(key) {
    return state.catchments.find(function (c) { return c.key === key; }) || null;
  }

  // Names to use until the list of catchments has loaded.
  var CATCHMENT_NAMES = { brisbane: 'Brisbane River', mary: 'Mary River' };

  /** A catchment's name, e.g. "Mary River". */
  function catchmentName(key) {
    var c = catchmentInfo(key);
    return c ? c.name : (CATCHMENT_NAMES[key] || key);
  }

  /** True if a catchment is set up in full (not "under construction"). */
  function catchmentLive(key) {
    var c = catchmentInfo(key);
    return !!c && c.status !== 'construction';
  }

  // ---------- Formatting helpers ----------

  var dateTimeFmt = new Intl.DateTimeFormat('en-AU', {
    timeZone: TZ, day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit', hour12: false
  });
  var dayFmt = new Intl.DateTimeFormat('en-AU', { timeZone: TZ, day: '2-digit', month: '2-digit' });
  var hmFmt = new Intl.DateTimeFormat('en-AU', { timeZone: TZ, hour: '2-digit', minute: '2-digit', hour12: false });

  /** Format a Unix time (seconds) as DD/MM/YYYY HH:MM Brisbane time. */
  function fmtTime(seconds) {
    return dateTimeFmt.format(new Date(seconds * 1000)).replace(',', '');
  }

  /** Format a Unix time (seconds) as HH:MM Brisbane time. */
  function fmtHM(seconds) {
    return hmFmt.format(new Date(seconds * 1000));
  }

  /** Describe how long ago a Unix time was, in plain words. */
  function ago(seconds) {
    var mins = Math.round((Date.now() / 1000 - seconds) / 60);
    if (mins < 1) { return 'just now'; }
    if (mins < 60) { return mins + ' min ago'; }
    var hours = Math.floor(mins / 60);
    if (hours < 48) { return hours + ' hr ' + (mins % 60) + ' min ago'; }
    return Math.floor(hours / 24) + ' days ago';
  }

  /** Units for a height, saying which zero it is measured from. */
  function heightUnit(datum) {
    if (datum === 'AHD') { return 'm AHD'; }
    if (datum === 'LGH') { return 'm on gauge'; }
    return 'm';
  }

  /** Format a reading with its units. */
  function fmtValue(value, unit, datum) {
    if (value === null || value === undefined) { return 'no reading'; }
    if (unit === 'm') { return value.toFixed(2) + ' ' + heightUnit(datum); }
    if (unit === 'm3/s') { return value.toFixed(1) + ' m³/s'; }
    return value + ' ' + unit;
  }

  /** Round rain for display: whole mm from 10 up, one decimal below. */
  function fmtMm(mm) {
    if (mm === null || mm === undefined) { return '–'; }
    return mm >= 10 ? String(Math.round(mm)) : String(Math.round(mm * 10) / 10);
  }

  /** Create an element with optional class and text. */
  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) { node.className = className; }
    if (text !== undefined && text !== null) { node.textContent = text; }
    return node;
  }

  /** Rain intensity class (r0-r5) for an amount over the chosen period. */
  function rainClass(mm, key) {
    // Thresholds scale with the period so colours mean roughly the same intensity.
    var scale = { rain_1h: 0.2, rain_3h: 0.4, since_9am: 0.8, rain_24h: 1, rain_72h: 1.6 }[key] || 1;
    if (mm <= 0) { return 'r0'; }
    for (var i = 0; i < RAIN_BANDS.length; i++) {
      if (mm < RAIN_BANDS[i][1] * scale) { return 'r' + (i + 1); }
    }
    return 'r5';
  }

  /** True if a flood class means the gauge is at or above minor flood level. */
  function flooding(cls) {
    return cls === 'minor' || cls === 'moderate' || cls === 'major';
  }

  /**
   * Describe the time a rain figure runs to, e.g. "to 20:00" or "hour to 19:00", so it's clear
   * how current it is. Council gauges report every 5 minutes, so they need no label.
   */
  function rainAsAt(s, key) {
    if (s.interval !== '1 hour') { return ''; }
    if (key === 'rain_1h') {
      if (s.past_hour_to) { return 'to ' + fmtHM(s.past_hour_to); }
      return s.totals_to ? 'hour to ' + fmtHM(s.totals_to) : '';
    }
    if (key === 'since_9am') { return s.since_9am_to ? 'to ' + fmtHM(s.since_9am_to) : ''; }
    return s.totals_to ? 'to ' + fmtHM(s.totals_to) : '';
  }

  // ---------- Map ----------

  // Keep popups clear of the zoom buttons in the top-left corner.
  L.Popup.mergeOptions({ autoPanPaddingTopLeft: L.point(56, 16) });
  var map = L.map('map', { zoomControl: true }).setView([-27.48, 152.8], 9);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18,
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'
  }).addTo(map);
  state.rainLayer = L.layerGroup().addTo(map);
  state.streamLayer = L.layerGroup().addTo(map);
  state.communityLayer = L.layerGroup(); // Off until its checkbox is ticked.
  state.cameraLayer = L.layerGroup().addTo(map);
  state.damLayer = L.layerGroup().addTo(map);
  state.trafficLayer = L.layerGroup().addTo(map);
  state.watchLayer = L.layerGroup().addTo(map);
  state.areaLayer = L.layerGroup().addTo(map);
  state.predictAreaLayer = L.layerGroup().addTo(map); // Shaded while a prediction's popup is open.
  state.predictLayer = L.layerGroup().addTo(map);

  // Popups narrow enough to fit the map beside the 56 px kept clear for the buttons on the left
  // (above), plus Leaflet's own margins, so their close button is never off the edge. Measured
  // from the map, not the window: beside the side panel the map can be narrow too.
  function popupWidth() {
    return Math.max(200, Math.min(300, map.getSize().x - 116));
  }
  L.Popup.mergeOptions({ autoPanPadding: L.point(12, 12) });

  /** Build the marker for a river or creek gauge. */
  function streamMarker(s) {
    var suspect = !!s.note;
    var tidal = s.tidal === 'yes' || s.tidal === 'partial';
    var label = esc(s.name) + ' — ';

    // At or above an official flood level: a diamond in the flood class colour.
    if (!suspect && flooding(s.flood_class)) {
      var icon = L.divIcon({
        className: '',
        html: '<span class="flood-icon flood-' + s.flood_class + '"></span>', // Fixed class names only.
        iconSize: [0, 0]
      });
      var fm = L.marker([s.lat, s.lon], { icon: icon, keyboard: true, zIndexOffset: 1000 });
      fm.bindTooltip(label + FLOOD_LABELS[s.flood_class]);
      return fm;
    }

    // May be faulty or not reporting: a grey marker with a question mark, so it doesn't rely on colour.
    if (suspect || s.stale) {
      var qicon = L.divIcon({
        className: '',
        html: '<span class="q-icon">?</span>', // Fixed text only.
        iconSize: [0, 0]
      });
      var qm = L.marker([s.lat, s.lon], { icon: qicon, keyboard: true });
      qm.bindTooltip(label + (suspect ? 'may be faulty' : 'not reporting'));
      return qm;
    }

    var trend = s.trend || 'unknown';
    // Fully tidal gauges rise fast with every incoming tide, so never paint them red:
    // a red dot should mean something unusual, not the twice-daily tide.
    if (s.tidal === 'yes' && trend === 'rising_fast') { trend = 'rising'; }
    var colour = TREND_COLOURS[trend];
    var marker = L.circleMarker([s.lat, s.lon], {
      radius: 7,
      fillColor: colour,
      fillOpacity: 0.95,
      color: tidal ? '#1c2430' : '#ffffff',
      weight: tidal ? 3 : 2,
      bubblingMouseEvents: false
    });
    marker.bindTooltip(label + TREND_LABELS[s.trend || 'unknown']);
    return marker;
  }

  /** Build the marker for a rain gauge: a box showing millimetres, or a small dot if dry. */
  function rainMarker(s) {
    var mm = s[state.rainKey];
    var unknown = s.stale || mm === null || mm === undefined;
    var label = (unknown || s.note) ? '?' : String(Math.round(mm));
    var cls;
    if (s.note) {
      cls = 'rain-icon suspect';
    } else if (unknown) {
      cls = 'rain-icon stale';
    } else if (mm <= 0) {
      cls = 'rain-dot';
      label = '';
    } else {
      cls = 'rain-icon ' + rainClass(mm, state.rainKey);
    }
    var icon = L.divIcon({
      className: '',
      html: '<span class="' + cls + '">' + label + '</span>', // Numbers and fixed class names only.
      iconSize: [0, 0] // The box positions itself beside the gauge via CSS.
    });
    if (s.community) {
      // Community gauges: dashed outline, and the label says so.
      icon = L.divIcon({
        className: '',
        html: '<span class="' + cls + ' community">' + label + '</span>', // Numbers and fixed class names only.
        iconSize: [0, 0]
      });
    }
    var marker = L.marker([s.lat, s.lon], { icon: icon, keyboard: true });
    var asAt = rainAsAt(s, state.rainKey);
    marker.bindTooltip(esc(s.name) + ' — ' + (unknown ? 'not reporting' : fmtMm(mm) + ' mm' + (asAt ? ' (' + asAt + ')' : '')));
    return marker;
  }

  /** Redraw the markers of the catchment on screen from the current data. */
  function drawMarkers() {
    state.streamLayer.clearLayers();
    state.rainLayer.clearLayers();
    state.data.sensors.forEach(function (s) {
      if (!inCatchment(s)) { return; }
      var marker = s.type === 'stream' ? streamMarker(s) : rainMarker(s);
      marker.on('click', function () { select(s.id); });
      (s.community ? state.communityLayer : s.type === 'stream' ? state.streamLayer : state.rainLayer).addLayer(marker);
    });
    // On first load, show the whole catchment (unless a gauge link was opened).
    if (!state.fitted) {
      state.fitted = true;
      if (!location.hash) { fitCatchment(); }
    }
  }

  // ---------- Dial (plain SVG) ----------

  var SVGNS = 'http://www.w3.org/2000/svg';

  /** Create an SVG element with attributes. */
  function svgEl(tag, attrs) {
    var node = document.createElementNS(SVGNS, tag);
    Object.keys(attrs || {}).forEach(function (k) { node.setAttribute(k, attrs[k]); });
    return node;
  }

  /**
   * Draw a semicircle dial.
   * opts: min, max, value, bands [[from, to, cssVar]], fill (colour of the value arc),
   *       valueText, unitText, caption, labels [[at, text]].
   */
  function dial(opts) {
    var cx = 120, cy = 116, rBand = 102, rFill = 84;
    var svg = svgEl('svg', { viewBox: '-30 0 300 150', 'class': 'dial', role: 'img',
      'aria-label': opts.valueText + ' ' + opts.unitText + ', ' + opts.caption });
    var span = (opts.max - opts.min) || 1;
    var frac = function (v) { return Math.max(0, Math.min(1, (v - opts.min) / span)); };
    var pt = function (r, t) {
      var a = Math.PI * (1 - t);
      return [cx + r * Math.cos(a), cy - r * Math.sin(a)];
    };
    var arc = function (r, t0, t1) {
      var p0 = pt(r, t0), p1 = pt(r, t1);
      return 'M' + p0[0].toFixed(2) + ',' + p0[1].toFixed(2) +
        ' A' + r + ',' + r + ' 0 0 1 ' + p1[0].toFixed(2) + ',' + p1[1].toFixed(2);
    };

    // Background track for the value, then the coloured bands around the outside.
    svg.appendChild(svgEl('path', { d: arc(rFill, 0, 1), 'class': 'track', fill: 'none',
      'stroke-width': 20, 'stroke-linecap': 'butt' }));
    opts.bands.forEach(function (b) {
      var t0 = frac(b[0]), t1 = frac(Math.min(b[1], opts.max));
      if (t1 - t0 <= 0.001) { return; }
      svg.appendChild(svgEl('path', { d: arc(rBand, t0, t1), fill: 'none', 'stroke-width': 8,
        style: 'stroke: var(' + b[2] + ')' }));
    });
    // The value: an arc filled from the left, like water rising.
    var tv = frac(opts.value);
    if (tv > 0.004) {
      svg.appendChild(svgEl('path', { d: arc(rFill, 0, tv), fill: 'none', 'stroke-width': 20,
        style: 'stroke: ' + opts.fill }));
    }
    (opts.labels || []).forEach(function (l) {
      var t = frac(l[0]);
      var p, anchor;
      if (t < 0.12 || t > 0.88) {
        // Near either end: put the label just under the end of the dial so it can't be clipped.
        p = pt(rBand, t < 0.5 ? 0 : 1);
        p = [p[0], cy + 12];
        anchor = 'middle';
      } else {
        p = pt(rBand + 10, t);
        anchor = t < 0.4 ? 'end' : (t > 0.6 ? 'start' : 'middle');
      }
      var txt = svgEl('text', { x: p[0].toFixed(1), y: (p[1] + 3).toFixed(1), 'class': 'band-label',
        'text-anchor': anchor });
      txt.textContent = l[1];
      svg.appendChild(txt);
    });

    var value = svgEl('text', { x: cx, y: cy - 8, 'text-anchor': 'middle', 'class': 'value' });
    value.textContent = opts.valueText;
    var unit = svgEl('tspan', { 'class': 'unit', dx: 4 });
    unit.textContent = opts.unitText;
    value.appendChild(unit);
    svg.appendChild(value);
    var cap = svgEl('text', { x: cx, y: cy + 14, 'text-anchor': 'middle', 'class': 'caption' });
    cap.textContent = opts.caption;
    svg.appendChild(cap);
    return svg;
  }

  /** Dial for a rain gauge: the last 24 hours against intensity bands. */
  function rainDial(s) {
    var mm = s.rain_24h || 0;
    var max = Math.max(150, Math.ceil(mm / 50) * 50);
    var band = RAIN_BANDS.find(function (b) { return mm < b[1]; }) || RAIN_BANDS[RAIN_BANDS.length - 1];
    var caption = 'last 24 hours';
    if (s.history_from && s.history_from > Date.now() / 1000 - 86400) {
      caption = 'since ' + fmtHM(s.history_from) + ' (collection began)';
    } else if (s.interval === '1 hour' && s.totals_to) {
      caption = 'last 24 hours, to ' + fmtHM(s.totals_to);
    }
    return dial({
      min: 0, max: max, value: mm,
      bands: RAIN_BANDS.map(function (b) { return [b[0], Math.min(b[1], max), b[2]]; }),
      fill: 'var(' + band[2] + ')',
      valueText: fmtMm(mm), unitText: 'mm', caption: caption,
      labels: [[0, '0'], [50, '50'], [100, '100'], [max, String(max)]]
    });
  }

  /**
   * Dial for a river gauge: the level against BoM's flood levels (or the 3-day range when a
   * gauge has none), plus a one-line summary of how far it is from flooding.
   */
  function riverDial(s) {
    var lv = s.latest;
    var f = s.flood || {};
    var minor = f.minor, moderate = f.moderate, major = f.major;
    var hasLevels = minor !== null && minor !== undefined;
    var lowData = Math.min(s.min_72h !== undefined ? s.min_72h : lv, lv);
    var highData = Math.max(s.max_72h !== undefined ? s.max_72h : lv, lv);
    var lo, hi, bands = [], labels = [];

    if (hasLevels) {
      var top = major !== null ? major : (moderate !== null ? moderate : minor);
      var span = Math.max(0.5, top - minor);
      // Start the dial at the gauge's zero (or a little below the lowest AHD reading), but no
      // more than two flood-band widths below minor, so weir gauges reading 70+ m stay readable.
      var base = s.datum === 'AHD' ? lowData - 0.5 : Math.min(0, lowData);
      lo = Math.max(base, minor - 2 * span);
      lo = Math.min(lo, lowData - 0.05 * span);
      hi = Math.max(top + span * 0.25, lv + 0.3);
      bands.push([lo, minor, '--water']);
      if (moderate !== null) {
        bands.push([minor, moderate, '--flood-minor']);
        if (major !== null) {
          bands.push([moderate, major, '--flood-moderate']);
          bands.push([major, hi, '--flood-major']);
        } else {
          bands.push([moderate, hi, '--flood-moderate']);
        }
      } else {
        bands.push([minor, hi, '--flood-minor']);
      }
      labels.push([minor, 'minor ' + minor.toFixed(1)]);
      if (major !== null) { labels.push([major, 'major ' + major.toFixed(1)]); }
    } else {
      // No flood levels: centre the dial on the last 3 days' range.
      var pad = Math.max(0.5, highData - lowData);
      lo = lowData - pad;
      hi = highData + pad;
      bands.push([lo, hi, '--dial-track']);
    }

    // A doubted reading (see the note above the dial) isn't shown as a flood.
    var shownClass = s.note ? null : s.flood_class;
    var fill = flooding(shownClass) ? 'var(--flood-' + shownClass + ')' : 'var(--water)';
    var wrap = el('div');
    wrap.appendChild(dial({
      min: lo, max: hi, value: lv, bands: bands, fill: fill,
      valueText: lv.toFixed(2), unitText: 'm',
      caption: s.datum === 'AHD' ? 'height above mean sea level (AHD)' :
        (s.datum === 'LGH' ? 'height on the gauge' : 'water level'),
      labels: labels
    }));

    var note;
    if (!hasLevels) {
      note = 'BoM has no flood levels for this gauge.';
    } else if (s.flood_class === 'below') {
      note = (minor - lv).toFixed(2) + ' m below minor flood level';
    } else if (s.note) {
      note = 'This reading is above the minor flood level but may be faulty (see above).';
    } else {
      note = FLOOD_LABELS[s.flood_class] + ': ' + (lv - minor).toFixed(2) + ' m above minor flood level';
    }
    wrap.appendChild(el('p', 'dial-note' + (flooding(shownClass) ? ' flood-' + shownClass : ''), note));
    return wrap;
  }

  // ---------- Detail panel ----------

  /** A cell in the quick-facts grid. */
  function factCell(label, num, when) {
    var cell = el('div', 'fact');
    cell.appendChild(el('span', 'label', label));
    cell.appendChild(el('span', 'num', num));
    if (when) { cell.appendChild(el('span', 'when', when)); }
    return cell;
  }

  // ---------- Flood event upstream (river network from sensors.json "network") ----------

  /** Index the river network once per data load: id -> node key, and node key -> nodes just upstream. */
  function networkIndex() {
    var net = state.data && state.data.network;
    if (!net || !net.nodes) { return null; }
    if (state.netIndex && state.netIndex.data === state.data) { return state.netIndex; }
    var keyOf = {};
    var up = {};
    Object.keys(net.nodes).forEach(function (k) {
      var n = net.nodes[k];
      keyOf[k] = k;
      n.ids.forEach(function (id) { keyOf[id] = k; });
      if (n.down) { (up[n.down] = up[n.down] || []).push(k); }
    });
    state.netIndex = { data: state.data, net: net, keyOf: keyOf, up: up };
    return state.netIndex;
  }

  /**
   * Travel time from one node down to another along the river, added up from measured legs
   * (data/travel_times.csv). Returns {hours, low, high} or null if it hasn't been worked out.
   */
  function travelTime(ix, from, to) {
    var path = [from];
    for (var at = from; at !== to;) {
      at = ix.net.nodes[at].down;
      if (!at) { return null; }
      path.push(at);
    }
    var pos = {};
    path.forEach(function (k, i) { pos[k] = i; });
    var best = [{ hours: 0, low: 0, high: 0 }];
    for (var i = 0; i < path.length; i++) {
      if (!best[i]) { continue; }
      ix.net.travel.forEach(function (t) {
        if (t[0] !== path[i] || pos[t[1]] === undefined || pos[t[1]] <= i) { return; }
        var j = pos[t[1]];
        var sum = { hours: best[i].hours + t[2], low: best[i].low + t[3], high: best[i].high + t[4] };
        if (!best[j] || sum.hours < best[j].hours) { best[j] = sum; }
      });
    }
    return best[path.length - 1] || null;
  }

  /** Why an upstream gauge matters right now, or null if it doesn't. Higher rank = more serious. */
  function upstreamStatus(g) {
    if (!g || g.type !== 'stream' || g.stale || g.note || g.latest === null || g.latest === undefined) { return null; }
    var cls = g.flood_class;
    var rising = g.trend === 'rising' || g.trend === 'rising_fast';
    if (cls === 'minor' || cls === 'moderate' || cls === 'major') {
      return { rank: { minor: 3, moderate: 4, major: 5 }[cls],
        text: cls.charAt(0).toUpperCase() + cls.slice(1) + ' flooding' + (rising ? ', rising' : '') };
    }
    if (g.trend === 'rising_fast' && g.tidal !== 'yes') { return { rank: 2, text: 'Rising fast' }; }
    if (rising && g.flood && g.flood.minor !== undefined && g.latest >= g.flood.minor - 1) {
      return { rank: 1, text: 'Rising, ' + Math.max(0, g.flood.minor - g.latest).toFixed(2) + ' m below minor flood level' };
    }
    return null;
  }

  /** Format a travel time for the notice. */
  function fmtTravel(t) {
    if (!t) { return 'travel time to here not yet worked out'; }
    var h = Math.round(t.hours);
    var lo = Math.round(t.low);
    var hi = Math.round(t.high);
    var range = lo !== hi ? ' (usually ' + lo + '–' + hi + ' h)' : '';
    return 'its peak usually reaches here about ' + h + ' h later' + range;
  }

  /**
   * The small "Flood event upstream" notice for a gauge: closed by default, opens to list the
   * gauges upstream that are flooding or rising, and any prediction covering this gauge.
   * Returns null when this gauge isn't on the river network.
   */
  function upstreamNotice(s) {
    var ix = networkIndex();
    var key = ix && ix.keyOf[s.id];
    if (!key) { return null; }
    var byId = {};
    state.data.sensors.forEach(function (x) { byId[x.id] = x; });
    var found = [];
    var checked = 0;
    // Walk upstream, noting when a dam lies between the gauge and here.
    var queue = (ix.up[key] || []).map(function (k) { return { key: k, dam: false }; });
    while (queue.length) {
      var item = queue.shift();
      var node = ix.net.nodes[item.key];
      var held = item.dam || (node.dam && node.down !== key);
      var worst = null;
      node.ids.forEach(function (id) {
        var g = byId[id];
        if (g) { checked++; }
        var st = upstreamStatus(g);
        if (st && (!worst || st.rank > worst.status.rank)) { worst = { gauge: g, status: st }; }
      });
      if (worst) {
        worst.held = held;
        worst.travel = held ? null : travelTime(ix, item.key, key);
        found.push(worst);
      }
      (ix.up[item.key] || []).forEach(function (k) { queue.push({ key: k, dam: held }); });
    }
    // Predictions for this gauge: it's inside the prediction's area and downstream of (or at) one
    // of the gauges that set it off.
    var upKeys = {};
    upKeys[key] = true;
    var walk = [key];
    while (walk.length) {
      (ix.up[walk.pop()] || []).forEach(function (k) { if (!upKeys[k]) { upKeys[k] = true; walk.push(k); } });
    }
    var predictions = predictOn() ? ((state.alerts && state.alerts.alerts) || []).filter(function (a) {
      return isPrediction(a) && a.bbox && s.lat >= a.bbox[0] && s.lat <= a.bbox[2] && s.lon >= a.bbox[1] && s.lon <= a.bbox[3] &&
        (a.gauges || []).some(function (id) { return upKeys[ix.keyOf[id]]; });
    }) : [];
    if (!found.length && !predictions.length) {
      return checked ? el('p', 'upstream-quiet', '⬆ Upstream: nothing flooding or rising (' + checked +
        (checked === 1 ? ' gauge' : ' gauges') + ' checked).') : null;
    }

    found.sort(function (a, b) { return b.status.rank - a.status.rank || (a.held - b.held); });
    var worstRank = found.length ? found[0].status.rank : 0;
    var box = el('details', 'upstream ' + (worstRank >= 3 ? 'upstream-flood' : 'upstream-watch'));
    box.open = !!state.upstreamOpen;
    box.addEventListener('toggle', function () { state.upstreamOpen = box.open; });
    var parts = [];
    if (found.length) { parts.push(found.length + (found.length === 1 ? ' gauge' : ' gauges')); }
    if (predictions.length) { parts.push('prediction'); }
    box.appendChild(el('summary', null, '⬆ Flood event upstream · ' + parts.join(' + ')));
    var list = el('ul', 'upstream-list');
    predictions.forEach(function (a) {
      var li = el('li', 'upstream-predict');
      li.appendChild(el('strong', null, 'Predictive alert (' + (a.level === 'likely' ? 'flooding likely' : 'watch') + '): '));
      li.appendChild(document.createTextNode(a.text + '.'));
      list.appendChild(li);
    });
    found.forEach(function (f) {
      var li = el('li');
      var go = el('button', 'upstream-go', f.gauge.name);
      go.type = 'button';
      go.addEventListener('click', function () { goToGauge(f.gauge.id); });
      li.appendChild(go);
      li.appendChild(el('span', 'upstream-state', ' ' + f.status.text + ', ' + f.gauge.latest.toFixed(2) + ' m. '));
      li.appendChild(el('span', 'small', f.held
        ? 'Above a dam: what reaches here depends on the dam\'s releases.'
        : fmtTravel(f.travel).charAt(0).toUpperCase() + fmtTravel(f.travel).slice(1) + '.'));
      list.appendChild(li);
    });
    box.appendChild(list);
    box.appendChild(el('p', 'small', 'Travel times are from past floods and vary with rain and how wet the ground is. ' +
      'Follow BoM warnings.'));
    return box;
  }

  // ---------- Dams (sensors.json "dams": Seqwater's figures with BoM's lake and tailwater gauges) ----------

  var DAM_STATES = {
    unknown: 'No recent figures',
    normal: 'Below full',
    full: 'Full or spilling',
    flood_storage: 'Holding flood water',
    releasing: 'Flood releases'
  };

  /** A small dam-wall icon, coloured by the dam's state. */
  function damIconHtml(state) {
    return '<span class="dam-icon dam-' + state + '" aria-hidden="true"><svg viewBox="0 0 24 24" width="18" height="18">' +
      '<path d="M4 5h6l3 14H4z" fill="currentColor"/><path d="M14 9c1.5 1 3 1 4.5 0s3-1 4.5 0M14 13c1.5 1 3 1 4.5 0s3-1 4.5 0' +
      'M14 17c1.5 1 3 1 4.5 0s3-1 4.5 0" stroke="currentColor" stroke-width="1.6" fill="none"/></svg></span>';
  }

  /** The popup for a dam. */
  function damPopup(d) {
    var box = el('div', 'dam-popup');
    var top = el('div', 'dam-top');
    top.appendChild(el('span', 'dam-chip dam-' + d.state, DAM_STATES[d.state] || d.state));
    top.appendChild(el('strong', null, d.name));
    box.appendChild(top);
    d.why.forEach(function (w) { box.appendChild(el('div', null, w.charAt(0).toUpperCase() + w.slice(1) + '.')); });
    var facts = [];
    if (d.pct !== null) { facts.push(d.pct.toFixed(1) + '% full (Seqwater' + (d.observed ? ', ' + d.observed : '') + ')'); }
    if (d.level !== null) {
      facts.push('Lake ' + d.level.toFixed(2) + ' m' + (d.fsl !== null ? ' (full supply ' + d.fsl.toFixed(2) + ' m)' : '') +
        ', ' + (TREND_LABELS[d.level_trend] || 'trend unknown').toLowerCase() + ' (BoM gauge)');
    }
    if (d.tail_rise !== null && d.tail_rise !== undefined) {
      var below = d.tail_rise >= 0.05 ? 'up ' + d.tail_rise.toFixed(2) + ' m' : 'steady';
      facts.push('River below the dam: ' + below + ' over 6 hours');
    }
    if (d.comment && !/spill|flood storage/i.test(d.comment)) { facts.push('Seqwater: ' + d.comment); }
    facts.forEach(function (f) { box.appendChild(el('div', 'small', f)); });
    var links = el('div', 'dam-links small');
    [['Lake gauge', d.level_id], ['Gauge below the dam', d.tail_id]].forEach(function (pair) {
      if (!pair[1] || !state.data.sensors.some(function (x) { return x.id === pair[1]; })) { return; }
      var b = el('button', 'nearlink', pair[0]);
      b.type = 'button';
      b.addEventListener('click', function () { map.closePopup(); select(pair[1]); });
      links.appendChild(b);
    });
    var a = el('a', null, 'Seqwater dam levels and releases');
    a.href = (state.data && state.data.dams_source) || 'https://www.seqwater.com.au/water-supply/dam-levels';
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    links.appendChild(a);
    box.appendChild(links);
    return box;
  }

  /** Draw a marker for each dam in the catchment on screen. */
  function drawDams() {
    state.damLayer.clearLayers();
    ((state.data && state.data.dams) || []).forEach(function (d) {
      if (!inCatchment(d)) { return; }
      var m = L.marker([d.lat, d.lon], {
        icon: L.divIcon({ className: '', html: damIconHtml(d.state), iconSize: [0, 0] }),
        keyboard: true, zIndexOffset: 600, title: d.name + ': ' + (DAM_STATES[d.state] || d.state).toLowerCase()
      });
      m.bindTooltip(esc(d.name) + ' — ' + esc((DAM_STATES[d.state] || d.state).toLowerCase()));
      m.bindPopup(function () { return damPopup(d); });
      state.damLayer.addLayer(m);
    });
  }

  /**
   * Dams whose water reaches a place, for its score: flood releases count as flooding (2 for
   * Wivenhoe and Somerset, which hold back the big floods; 1 for the others); a dam holding flood
   * water or spilling counts as "Watch" only. Below Wivenhoe, Somerset and Cressbrook don't count
   * on their own: their water goes into Lake Wivenhoe.
   *
   * @param {Object} seen Network nodes upstream of the place.
   * @param {Array} atNodes The place's nearest network nodes.
   */
  state.damsUpstream = function (seen, atNodes) {
    var out = [];
    var at = {};
    atNodes.forEach(function (n) { at[n.key] = true; });
    ((state.data && state.data.dams) || []).forEach(function (d) {
      var reaches = (d.node && seen[d.node]) || (d.down && (seen[d.down] || at[d.down]));
      if (!reaches || ['full', 'flood_storage', 'releasing'].indexOf(d.state) < 0) { return; }
      // A dam that empties into another dam's lake (Somerset and Cressbrook into Wivenhoe) only
      // reaches places below that dam through it, so there it's the lower dam that counts.
      if (d.into && (seen[d.into] || at[d.into])) { return; }
      var big = d.key === 'wivenhoe' || d.key === 'somerset';
      var weight = d.state === 'releasing' ? (big ? 2 : 1) : 0.5;
      out.push({
        name: d.name, weight: weight, flood: d.state === 'releasing',
        text: d.name + ': ' + (d.why.length ? d.why.join('; ') : (DAM_STATES[d.state] || d.state).toLowerCase()) + '.'
      });
    });
    return out;
  };

  // ---------- Upstream water score for a place ----------

  var SCORE_LEVELS = ['low', 'watch', 'minor', 'moderate', 'major'];
  var SCORE_LABELS = { low: 'Low', watch: 'Watch', minor: 'Minor', moderate: 'Moderate', major: 'Major' };
  // How much each kind of upstream water adds to the total (see placeScore).
  var SCORE_WEIGHTS = { major: 3, moderate: 2, minor: 1, rising_fast: 0.5, near: 0.5 };

  /** The waterway a gauge is on: "Oxley Creek at Corinda" → "Oxley Creek". */
  function waterwayOf(name) {
    return String(name || '').split(/\s+(?:at|@)\s+/i)[0].replace(/\s+Mouth$/i, '').trim();
  }

  /** Straight-line distance in metres. */
  function metresBetween(lat1, lon1, lat2, lon2) {
    return map.distance([lat1, lon1], [lat2, lon2]);
  }

  /**
   * How much water is coming to a place, as a level like the legend's, with the reasons.
   *
   * - Here: river and creek gauges within 3 km (or inside the area's box), and the nearest gauge on
   *   each river or creek within 6 km (up to four). Their own flood level counts as is.
   * - Upstream: every gauge upstream of those on the river network. Each waterway counts once, at
   *   its worst gauge: major flooding 3, moderate 2, minor 1, rising fast or near minor 0.5, and
   *   half that above a dam (the dam holds it back). Dam flood releases count too. Anything
   *   coming is "Watch"; flooding upstream adding up to 4 or more (e.g. two rivers in moderate
   *   flood) is "Minor". Rising water on its own never goes past "Watch".
   * - A predictive flood alert covering the place: "Minor" if flooding is likely, else "Watch".
   *
   * @param {number} lat
   * @param {number} lon
   * @param {Array} [box] [S, W, N, E] for a saved area (its gauges count as "here").
   * @return {{level: string, reasons: Array<{level: string, text: string}>, total: number}}
   */
  function placeScore(lat, lon, box) {
    var reasons = [];
    var level = 'low';
    var raise = function (l) { if (SCORE_LEVELS.indexOf(l) > SCORE_LEVELS.indexOf(level)) { level = l; } };
    var sensors = (state.data && state.data.sensors) || [];
    var byId = {};
    sensors.forEach(function (x) { byId[x.id] = x; });
    var usable = function (g) {
      return g && g.type === 'stream' && !g.community && !g.note && !g.stale && g.latest !== null && g.latest !== undefined;
    };
    var inside = function (g) {
      return box ? g.lat >= box[0] && g.lat <= box[2] && g.lon >= box[1] && g.lon <= box[3]
        : metresBetween(lat, lon, g.lat, g.lon) <= 3000;
    };
    var km = function (g) { return (metresBetween(lat, lon, g.lat, g.lon) / 1000).toFixed(1) + ' km away'; };
    var counted = {};

    // Here: the place's own gauges.
    var hereGauge = function (g, where) {
      if (counted[g.id] || !usable(g)) { return; }
      counted[g.id] = true;
      var st = upstreamStatus(g);
      if (!st) { return; }
      var l = st.rank >= 3 ? g.flood_class : 'watch';
      raise(l);
      reasons.push({ level: l, text: g.name + ' (' + where + '): ' + st.text.charAt(0).toLowerCase() + st.text.slice(1) +
        ', ' + g.latest.toFixed(2) + ' m.' });
    };
    sensors.forEach(function (g) { if (usable(g) && inside(g)) { hereGauge(g, box ? 'in this area' : km(g)); } });

    // The nearest point on each of the closest two waterways on the river network.
    var ix = networkIndex();
    var atNodes = [];
    if (ix) {
      var nearest = {};
      Object.keys(ix.net.nodes).forEach(function (k) {
        ix.net.nodes[k].ids.forEach(function (id) {
          var g = byId[id];
          if (!g) { return; }
          var d = metresBetween(lat, lon, g.lat, g.lon);
          var w = waterwayOf(g.name);
          if (d <= 6000 && (!nearest[w] || d < nearest[w].d)) { nearest[w] = { key: k, d: d, g: g }; }
        });
      });
      // Every river and creek within 6 km (up to four): a suburb like Oxley sits on a creek and on
      // the Brisbane River, which backs up the creek in a big flood.
      atNodes = Object.keys(nearest).map(function (w) { return nearest[w]; })
        .sort(function (a, b) { return a.d - b.d; }).slice(0, 4);
      atNodes.forEach(function (n) { hereGauge(n.g, 'nearest on the ' + waterwayOf(n.g.name) + ', ' + km(n.g)); });
    }

    // Upstream: each waterway counts once, at its worst.
    var worstBy = {};
    var seen = {};
    atNodes.forEach(function (at) {
      var queue = (ix.up[at.key] || []).map(function (k) { return { key: k, held: false }; });
      while (queue.length) {
        var item = queue.shift();
        if (seen[item.key]) { continue; }
        seen[item.key] = true;
        var node = ix.net.nodes[item.key];
        var held = item.held || (node.dam && node.down !== at.key);
        node.ids.forEach(function (id) {
          var g = byId[id];
          if (!usable(g) || counted[id]) { return; }
          var st = upstreamStatus(g);
          if (!st) { return; }
          var kind = st.rank >= 3 ? g.flood_class : st.rank === 2 ? 'rising_fast' : 'near';
          var weight = SCORE_WEIGHTS[kind] * (held ? 0.5 : 1);
          var w = waterwayOf(g.name);
          if (!worstBy[w] || weight > worstBy[w].weight) {
            var t = held ? null : travelTime(ix, item.key, at.key);
            worstBy[w] = {
              weight: weight,
              flood: st.rank >= 3,
              text: g.name + ': ' + st.text.charAt(0).toLowerCase() + st.text.slice(1) + ', ' + g.latest.toFixed(2) + ' m' +
                (held ? ' (above a dam, which holds it back).' : t ? '; usually reaches the ' + waterwayOf(at.g.name) +
                  ' here in about ' + Math.round(t.hours) + ' h.' : '.')
            };
          }
        });
        (ix.up[item.key] || []).forEach(function (k) { queue.push({ key: k, held: held }); });
      }
    });

    // Dam flood releases upstream (see the dams section).
    (state.damsUpstream ? state.damsUpstream(seen, atNodes) : []).forEach(function (d) {
      worstBy['dam:' + d.name] = { weight: d.weight, flood: !!d.flood, text: d.text };
    });

    // Only flooding counts towards "Minor"; rising water on its own is a "Watch".
    var total = 0;
    var flooding = 0;
    Object.keys(worstBy).forEach(function (w) {
      total += worstBy[w].weight;
      if (worstBy[w].flood) { flooding += worstBy[w].weight; }
      reasons.push({ level: 'upstream', text: worstBy[w].text, weight: worstBy[w].weight });
    });
    if (total > 0) { raise(flooding >= 4 ? 'minor' : 'watch'); }

    // Predictive flood alerts covering the place.
    if (predictOn()) {
      ((state.alerts && state.alerts.alerts) || []).filter(isPrediction).forEach(function (a) {
        if (a.bbox && lat >= a.bbox[0] && lat <= a.bbox[2] && lon >= a.bbox[1] && lon <= a.bbox[3]) {
          var l = a.level === 'likely' ? 'minor' : 'watch';
          raise(l);
          reasons.push({ level: l, text: 'Predictive flood alert (' + (PREDICT_LABELS[a.level] || 'watch').toLowerCase() +
            '): ' + a.name + '.' });
        }
      });
    }

    reasons.sort(function (a, b) {
      var r = function (x) { return x.level === 'upstream' ? 0.5 + (x.weight || 0) / 10 : SCORE_LEVELS.indexOf(x.level); };
      return r(b) - r(a);
    });
    return { level: level, reasons: reasons, total: total, rivers: atNodes.length };
  }

  /** The score as a small block for a popup or the areas list. */
  function scoreBlock(score, compact) {
    var box = el('div', 'score score-' + score.level + (compact ? ' compact' : ''));
    var head = el('div', 'score-head');
    head.appendChild(el('span', 'score-badge lvl-' + score.level, SCORE_LABELS[score.level]));
    head.appendChild(el('span', 'score-title', 'Water coming here'));
    box.appendChild(head);
    if (!score.reasons.length) {
      box.appendChild(el('div', 'small', score.rivers
        ? 'Nothing flooding or rising here or upstream.'
        : 'No river or creek gauges near here to go by.'));
    }
    score.reasons.slice(0, compact ? 2 : 6).forEach(function (r) { box.appendChild(el('div', 'score-reason', r.text)); });
    if (compact && score.reasons.length > 2) {
      var more = el('button', 'score-more', '+ ' + (score.reasons.length - 2) + ' more');
      more.type = 'button';
      more.addEventListener('click', function () { box.replaceWith(scoreBlock(score, false)); });
      box.appendChild(more);
    }
    if (!compact) {
      box.appendChild(el('div', 'small', 'From gauges here and upstream: an indicator, not a forecast for this spot.'));
    }
    return box;
  }

  /** Redraw the upstream notice for the open gauge (alerts can arrive after the gauge is opened). */
  function refreshUpstream() {
    var slot = document.getElementById('upstream-slot');
    var s = slot && state.data && state.data.sensors.find(function (x) { return x.id === state.selected; });
    if (!s) { return; }
    var notice = upstreamNotice(s);
    slot.replaceChildren();
    if (notice) { slot.appendChild(notice); }
  }

  /** Show a gauge's dial, facts and graph in the side panel. */
  function select(id) {
    var s = state.data.sensors.find(function (x) { return x.id === id; });
    if (!s) { return; }
    state.selected = id;
    if (location.hash !== '#' + id) { history.replaceState(null, '', '#' + id); }

    var detail = document.getElementById('detail');
    detail.replaceChildren();
    var head = el('div', 'detail-head');
    head.appendChild(el('h2', null, s.name));
    var star = el('button', 'watch-btn');
    star.type = 'button';
    var setStar = function (on) {
      star.textContent = on ? '★ Watching' : '☆ Watch';
      star.setAttribute('aria-pressed', on ? 'true' : 'false');
      star.title = on ? 'Stop watching this gauge' : 'Add to your watch list (saved in this browser)';
    };
    setStar(isWatched(s.id));
    star.addEventListener('click', function () { setStar(toggleWatch(s.id)); });
    head.appendChild(star);
    detail.appendChild(head);
    if (s.community) {
      var meta = el('p', 'meta', 'Community rain gauge run by a member of the public, via Weather Underground. ' +
        'Unofficial and unchecked: home gauges can read low or high. Position shown to within about 1 km. ');
      if (/^[A-Z0-9]{3,20}$/.test(s.loc || '')) {
        var wl = el('a', null, 'Station page');
        wl.href = 'https://www.wunderground.com/dashboard/pws/' + s.loc;
        wl.target = '_blank';
        wl.rel = 'noopener';
        meta.appendChild(wl);
      }
      detail.appendChild(meta);
    } else {
      detail.appendChild(el('p', 'meta', (s.type === 'stream' ? 'River level from the ' : 'Rain gauge from the ') +
        s.source_name));
    }

    if (s.note) { detail.appendChild(el('p', 'warn', 'This gauge may be faulty: ' + s.note)); }
    if (s.stale) { detail.appendChild(el('p', 'warn', 'This gauge has not reported recently.')); }
    if (s.type === 'stream') {
      var slot = el('div');
      slot.id = 'upstream-slot';
      detail.appendChild(slot);
      refreshUpstream();
    }

    var grid = el('div', 'facts-grid');
    if (s.type === 'stream') {
      if (s.latest === null) {
        detail.appendChild(el('p', 'hint', 'No reading yet.'));
      } else {
        detail.appendChild(riverDial(s));
        var tags = el('div', 'tags');
        var trend = s.note ? 'unknown' : (s.trend || 'unknown'); // A doubted reading has no trustworthy trend.
        tags.appendChild(el('span', 'trendtag trend-' + trend, TREND_LABELS[trend]));
        detail.appendChild(tags);
      }
      var rate = (s.rate_per_hr !== null && s.rate_per_hr !== undefined)
        ? (s.rate_per_hr > 0 ? '+' : '') + s.rate_per_hr.toFixed(2) + ' m/h' : '–';
      grid.appendChild(factCell('Change', rate, 'over the last hour'));
      grid.appendChild(factCell('Last reading', s.latest_t ? fmtHM(s.latest_t) : '–',
        s.latest_t ? ago(s.latest_t) : ''));
      grid.appendChild(factCell('3-day range', s.min_72h !== undefined
        ? s.min_72h.toFixed(2) + '–' + s.max_72h.toFixed(2) + ' m' : '–', ''));
      if (s.flow && s.flow.latest !== null) {
        grid.appendChild(factCell('Flow', s.flow.latest.toFixed(1) + ' m³/s',
          'at ' + fmtHM(s.flow.latest_t) + ', ' + s.flow.source_name));
      } else if (s.flood && s.flood.minor !== null) {
        grid.appendChild(factCell('Flood levels', s.flood.minor.toFixed(1) + ' / ' +
          (s.flood.moderate !== null ? s.flood.moderate.toFixed(1) : '–') + ' / ' +
          (s.flood.major !== null ? s.flood.major.toFixed(1) : '–'), 'minor / moderate / major (BoM)'));
      } else {
        grid.appendChild(factCell('Flood levels', 'none', 'BoM has not set any'));
      }
      detail.appendChild(grid);
      if (s.flow && s.flow.latest !== null && s.flood && s.flood.minor !== null) {
        detail.appendChild(el('p', 'small', 'BoM flood levels: minor ' + s.flood.minor.toFixed(2) +
          (s.flood.moderate !== null ? ', moderate ' + s.flood.moderate.toFixed(2) : '') +
          (s.flood.major !== null ? ', major ' + s.flood.major.toFixed(2) : '') + ' m.'));
      }
      if (s.tidal === 'yes' || s.tidal === 'partial') {
        detail.appendChild(el('p', 'tidenote', s.tidal === 'yes'
          ? 'Tidal gauge: the level rises and falls with the tide twice a day, so a rise here is often just the tide.'
          : 'Partly tidal: the tide has some effect on this gauge.'));
      }
      if (s.source !== 'qld') {
        detail.appendChild(el('p', 'small',
          'This gauge reports when the level changes, so a quiet gauge usually means a steady level.'));
      }
    } else {
      detail.appendChild(rainDial(s));
      grid.appendChild(factCell('Past hour', fmtMm(s.rain_1h) + ' mm', rainAsAt(s, 'rain_1h')));
      grid.appendChild(factCell('Since 9am', fmtMm(s.since_9am) + ' mm', rainAsAt(s, 'since_9am')));
      grid.appendChild(factCell('Last 3 hours', fmtMm(s.rain_3h) + ' mm', rainAsAt(s, 'rain_3h')));
      if (s.history_from) {
        grid.appendChild(factCell('Since ' + fmtHM(s.history_from) + ' ' + dayFmt.format(new Date(s.history_from * 1000)),
          fmtMm(s.rain_72h) + ' mm', 'when collection began'));
      } else {
        grid.appendChild(factCell('Last 3 days', fmtMm(s.rain_72h) + ' mm', rainAsAt(s, 'rain_72h')));
      }
      detail.appendChild(grid);
      detail.appendChild(el('p', 'small', 'Reports every ' + s.interval +
        (s.latest_t ? '; last report ' + fmtTime(s.latest_t) : '') + '.' +
        (s.interval === '1 hour' ? (s.past_hour_to
          ? ' Past hour and since 9am are BoM\'s latest figures; longer totals use full hours only.'
          : ' Hourly totals are published after each hour ends, so the latest rain can take up to an hour to appear.')
          : '')));
    }

    // The matching gauge at the same spot (rain for a river gauge, river for a rain gauge).
    if (s.pair) {
      var p = state.data.sensors.find(function (x) { return x.id === s.pair; });
      if (p) {
        var text = p.type === 'rain'
          ? 'Rain gauge here: ' + fmtMm(p.rain_24h) + ' mm in the last 24 hours'
          : 'River gauge here: ' + (p.latest !== null ? fmtValue(p.latest, 'm', p.datum) : 'no reading');
        var btn = el('button', 'pairlink', text);
        btn.type = 'button';
        btn.addEventListener('click', function () { select(p.id); });
        detail.appendChild(btn);
      }
    }

    // Other gauges at the same spot, from other sources.
    if (s.others && s.others.length) {
      detail.appendChild(el('h3', 'subhead', 'Also measured here'));
      var list = el('ul', 'others');
      s.others.forEach(function (o) {
        list.appendChild(el('li', null, o.source_name + ': ' + fmtValue(o.latest, o.unit, o.datum) +
          (o.latest_t ? ' at ' + fmtHM(o.latest_t) : '') + (o.note ? ' (may be faulty)' : '')));
      });
      detail.appendChild(list);
      if (s.type === 'stream') {
        detail.appendChild(el('p', 'small',
          'Gauges can measure from different zero points (AHD or the gauge\'s own zero), so their numbers may differ.'));
      }
    }

    var chartBox = el('div', 'chart');
    chartBox.appendChild(el('p', 'small', 'Loading graph…'));
    detail.appendChild(chartBox);

    panelNudge();
    fetch('data/series/' + encodeURIComponent(id) + '.json?t=' + state.data.generated)
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
      .then(function (series) {
        if (state.selected !== id) { return; } // User picked another gauge meanwhile.
        chartBox.replaceChildren(series.type === 'stream' ? streamChart(series, s) : rainChart(series));
      })
      .catch(function () {
        chartBox.replaceChildren(el('p', 'small', 'Graph unavailable.'));
      });
  }

  // ---------- Charts (plain SVG) ----------

  var W = 380, H = 196, PAD = { l: 44, r: 34, t: 22, b: 24 };

  /** Choose about 4 tidy axis values between min and max. */
  function niceTicks(min, max) {
    if (min === max) { min -= 0.5; max += 0.5; }
    var raw = (max - min) / 4;
    var mag = Math.pow(10, Math.floor(Math.log10(raw)));
    var step = [1, 2, 2.5, 5, 10].map(function (m) { return m * mag; }).find(function (v) { return v >= raw; });
    var ticks = [];
    for (var v = Math.floor(min / step) * step; v <= max + step * 0.001; v += step) {
      ticks.push(Math.round(v * 1000) / 1000);
    }
    if (ticks[ticks.length - 1] < max) { ticks.push(ticks[ticks.length - 1] + step); }
    return ticks;
  }

  /** Decimal places needed to tell tick labels apart. */
  function tickDecimals(ticks) {
    var step = ticks.length > 1 ? ticks[1] - ticks[0] : 1;
    return Math.min(2, Math.max(0, -Math.floor(Math.log10(step) + 1e-9)));
  }

  /** Draw the shared frame: horizontal grid, left labels, day labels on x. */
  function frame(svg, t0, t1, ticks, unit) {
    var y0 = ticks[0], y1 = ticks[ticks.length - 1];
    var x = function (t) { return PAD.l + (t - t0) / (t1 - t0) * (W - PAD.l - PAD.r); };
    var y = function (v) { return H - PAD.b - (v - y0) / (y1 - y0) * (H - PAD.t - PAD.b); };
    var dec = tickDecimals(ticks);
    ticks.forEach(function (v) {
      svg.appendChild(svgEl('line', { x1: PAD.l, x2: W - PAD.r, y1: y(v), y2: y(v), 'class': 'gridline' }));
      var label = svgEl('text', { x: PAD.l - 4, y: y(v) + 3, 'text-anchor': 'end' });
      label.textContent = v.toFixed(dec) + unit;
      svg.appendChild(label);
    });
    // Day boundaries at Brisbane midnight (UTC+10, no daylight saving).
    var first = Math.ceil((t0 + 36000) / 86400) * 86400 - 36000;
    for (var d = first; d <= t1; d += 86400) {
      svg.appendChild(svgEl('line', { x1: x(d), x2: x(d), y1: PAD.t, y2: H - PAD.b, 'class': 'gridline' }));
      var dl = svgEl('text', { x: x(d) + 3, y: H - 8 });
      dl.textContent = dayFmt.format(new Date(d * 1000));
      svg.appendChild(dl);
    }
    return { x: x, y: y };
  }

  /**
   * River graph: the level as a step line (it holds until the next change) with water filled
   * beneath, and official flood levels drawn when they are close enough to be useful.
   */
  function streamChart(series, s) {
    var pts = series.points;
    if (!pts.length) { return el('p', 'small', 'No readings in the last 3 days.'); }
    var t0 = series.start, t1 = state.data.data_time;
    if (pts[pts.length - 1][0] > t1) { t1 = pts[pts.length - 1][0]; }
    var values = pts.map(function (p) { return p[1]; });
    var lo = Math.min.apply(null, values), hi = Math.max.apply(null, values);

    // Show a flood level if it is within 1.5 x the data range (or 1 m) above the highest reading.
    var levels = [], offChart = [];
    var reach = Math.max(1, (hi - lo) * 1.5);
    ['minor', 'moderate', 'major'].forEach(function (k) {
      var v = s.flood ? s.flood[k] : null;
      if (v === null || v === undefined) { return; }
      if (v <= hi + reach) { levels.push([k, v]); } else { offChart.push(k + ' ' + v.toFixed(2) + ' m'); }
    });
    levels.forEach(function (l) { hi = Math.max(hi, l[1]); });

    var ticks = niceTicks(lo, hi);
    var svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img', 'aria-label': 'Water level over the last 3 days' });
    var g = frame(svg, t0, t1, ticks, ' m');
    var line = '';
    pts.forEach(function (p, i) {
      var px = g.x(Math.max(p[0], t0)), py = g.y(p[1]);
      line += i === 0 ? 'M' + px + ',' + py : 'H' + px + 'V' + py;
    });
    line += 'H' + g.x(t1); // Carry the latest level forward to now.
    var bottom = g.y(ticks[0]);
    svg.appendChild(svgEl('path', { d: line + 'V' + bottom + 'H' + g.x(Math.max(pts[0][0], t0)) + 'Z', 'class': 'waterfill' }));
    svg.appendChild(svgEl('path', { d: line, 'class': 'line' }));
    levels.forEach(function (l) {
      var yy = g.y(l[1]);
      svg.appendChild(svgEl('line', { x1: PAD.l, x2: W - PAD.r, y1: yy, y2: yy, 'class': 'floodline flood-' + l[0] }));
      var t = svgEl('text', { x: W - PAD.r - 2, y: yy - 3, 'text-anchor': 'end' });
      t.textContent = l[0];
      svg.appendChild(t);
    });

    if (!offChart.length) { return svg; }
    var box = el('div');
    box.appendChild(svg);
    box.appendChild(el('p', 'small', 'Flood levels above the graph: ' + offChart.join(', ') + '.'));
    return box;
  }

  /** Rain graph: hourly bars, with the running total as a line against the right-hand scale. */
  function rainChart(series) {
    var bars = series.hourly;
    var t0 = series.start, t1 = state.data.data_time;
    var maxv = Math.max(1, Math.max.apply(null, bars.map(function (b) { return b[1]; })));
    var ticks = niceTicks(0, maxv);
    var svg = svgEl('svg', { viewBox: '0 0 ' + W + ' ' + H, role: 'img',
      'aria-label': 'Hourly rainfall and running total over the last 3 days' });
    var g = frame(svg, t0, t1, ticks, '');
    var bw = Math.max(1, g.x(t0 + 3600) - g.x(t0) - 1);
    var total = 0, run = [];
    bars.forEach(function (b) {
      total += b[1];
      run.push([b[0] + 3600, total]);
      if (b[1] <= 0) { return; }
      var top = g.y(b[1]);
      var rect = svgEl('rect', { x: g.x(b[0]), y: top, width: bw, height: g.y(0) - top, 'class': 'bar' });
      var tip = svgEl('title');
      tip.textContent = fmtTime(b[0]) + ': ' + b[1] + ' mm in the hour';
      rect.appendChild(tip);
      svg.appendChild(rect);
    });

    // Running total on its own scale, labelled on the right.
    if (total > 0) {
      var rticks = niceTicks(0, total);
      var rmax = rticks[rticks.length - 1];
      var ry = function (v) { return H - PAD.b - v / rmax * (H - PAD.t - PAD.b); };
      var d = 'M' + g.x(t0) + ',' + ry(0);
      run.forEach(function (p) { d += 'L' + g.x(Math.min(p[0], t1)) + ',' + ry(p[1]); });
      svg.appendChild(svgEl('path', { d: d, 'class': 'runline' }));
      rticks.forEach(function (v) {
        var t = svgEl('text', { x: W - PAD.r + 4, y: ry(v) + 3, 'class': 'runtext' });
        t.textContent = String(v);
        svg.appendChild(t);
      });
    }
    var cap = svgEl('text', { x: PAD.l, y: PAD.t - 10 });
    cap.textContent = 'mm per hour';
    svg.appendChild(cap);
    var cap2 = svgEl('text', { x: W - 2, y: PAD.t - 10, 'text-anchor': 'end', 'class': 'runtext' });
    cap2.textContent = 'running total';
    svg.appendChild(cap2);
    return svg;
  }

  // ---------- Flood cameras ----------

  // A small camera symbol; fixed markup only.
  var CAMERA_HTML = '<span class="cam-icon"><svg viewBox="0 0 24 24" aria-hidden="true">' +
    '<rect x="2" y="7" width="14" height="11" rx="2"></rect><path d="M16 11l6-3.5v10L16 14z"></path></svg></span>';

  var POSITION_NOTES = {
    exact: '',
    gauge: 'Shown at the river gauge at the same crossing; the camera is close by.',
    approx: 'Position is approximate.'
  };

  /** Build the popup for a camera: its name, who runs it and a link to their camera page. */
  function cameraPopup(c) {
    var box = el('div', 'cam-popup');
    box.appendChild(el('strong', null, c.name));
    box.appendChild(el('div', 'small', c.provider));
    if (POSITION_NOTES[c.position]) { box.appendChild(el('div', 'small', POSITION_NOTES[c.position])); }
    var a = el('a', 'cam-link', 'Open camera page');
    a.href = c.url;
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    box.appendChild(a);
    box.appendChild(el('div', 'small', 'Opens the ' + c.provider + ' site in a new tab.'));
    return box;
  }

  /** Draw a marker for each camera in the catchment on screen. */
  function drawCameras() {
    state.cameraLayer.clearLayers();
    state.cameras.forEach(function (c) {
      if (!/^https:\/\//i.test(c.url) || !inCatchment(c)) { return; }
      var icon = L.divIcon({ className: '', html: CAMERA_HTML, iconSize: [0, 0] });
      var m = L.marker([c.lat, c.lon], { icon: icon, keyboard: true, title: c.name + ' camera' });
      m.bindTooltip(esc(c.name) + ' — flood camera');
      m.bindPopup(cameraPopup(c));
      state.cameraLayer.addLayer(m);
    });
  }

  /** Load the camera list and draw the cameras. */
  function loadCameras() {
    fetch('data/cameras.json?t=' + Date.now())
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
      .then(function (data) {
        state.cameras = data.cameras || [];
        drawCameras();
      })
      .catch(function () { /* Cameras are optional; the gauges still work without them. */ });
  }

  // ---------- Flood map shading ----------

  // Shading sits below the gauge markers and never catches clicks; hits are worked out below.
  map.createPane('floodPane');
  map.getPane('floodPane').style.zIndex = 350;
  map.getPane('floodPane').style.pointerEvents = 'none';
  var floodRenderer = L.canvas({ pane: 'floodPane', padding: 0.3 });

  var LIKELIHOOD_ORDER = ['Very Low', 'Low', 'Medium', 'High'];
  var BRISBANE_BOUNDS = L.latLngBounds([-27.68, 152.66], [-27.0, 153.22]); // Council area, roughly.

  /** Read a colour token from the style sheet. */
  function cssVar(name) {
    return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  }

  /** Unpack one ring (see lib/arcgis.php): first point, then changes, in 1/100000 degree. */
  function decodeRing(flat) {
    var pts = [];
    var x = 0;
    var y = 0;
    for (var i = 0; i + 1 < flat.length; i += 2) {
      x += flat[i];
      y += flat[i + 1];
      pts.push([y / 1e5, x / 1e5]);
    }
    return pts;
  }

  /** True if a point is inside a shape's rings (even-odd rule, so holes work). */
  function insideRings(rings, lat, lng) {
    var inside = false;
    rings.forEach(function (ring) {
      for (var i = 0, j = ring.length - 1; i < ring.length; j = i++) {
        var yi = ring[i][0];
        var xi = ring[i][1];
        var yj = ring[j][0];
        var xj = ring[j][1];
        if (((yi > lat) !== (yj > lat)) && (lng < (xj - xi) * (lat - yi) / (yj - yi) + xi)) {
          inside = !inside;
        }
      }
    });
    return inside;
  }

  /** True if a flood map layer is switched on and belongs to the catchment on screen. */
  function overlayShown(o) { return o.on && o.style !== 'outline' && inCatchment(o); }

  /** The switched-on flood map layers that cover a point. */
  function overlaysAt(latlng) {
    var x = Math.round(latlng.lng * 1e5);
    var y = Math.round(latlng.lat * 1e5);
    return state.overlays.filter(function (o) {
      return overlayShown(o) && o.feats && o.feats.some(function (f) {
        return x >= f.b[0] && x <= f.b[2] && y >= f.b[1] && y <= f.b[3] && insideRings(f.rings, latlng.lat, latlng.lng);
      });
    });
  }

  /** Download a layer's shapes (once) and draw them faintly. */
  function loadOverlay(o) {
    if (o.feats || o.loading) { return; }
    o.loading = true;
    o.status.textContent = ' loading…';
    fetch('data/overlays/' + encodeURIComponent(o.key) + '.json?t=' + o.fetched)
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
      .then(function (data) {
        var colour = cssVar('--ov-' + o.key) || cssVar('--ov-default');
        o.feats = (data.f || []).map(function (f) {
          return { b: f.b, rings: f.r.map(decodeRing) };
        });
        var outline = o.style === 'outline';
        o.feats.forEach(function (f) {
          o.group.addLayer(L.polygon(f.rings, {
            renderer: floodRenderer,
            interactive: false,
            color: colour,
            weight: outline ? 2.5 : 0.6,
            opacity: outline ? 0.9 : 0.45,
            dashArray: outline ? '10 6' : null,
            fill: !outline,
            fillColor: colour,
            fillOpacity: 0.17
          }));
        });
        o.status.textContent = '';
      })
      .catch(function () { o.status.textContent = ' (could not load)'; })
      .then(function () { o.loading = false; });
  }

  /** Switch a flood map layer on or off (it's drawn only while its catchment is on screen). */
  function setOverlay(o, on) {
    o.on = on;
    if (overlayShown(o)) {
      map.addLayer(o.group);
      loadOverlay(o);
    } else {
      map.removeLayer(o.group);
    }
  }

  /** Offer and draw only the flood maps of the catchment on screen. */
  function applyOverlayCatchment() {
    var sources = [];
    var any = false;
    state.overlays.forEach(function (o) {
      var here = inCatchment(o);
      o.row.hidden = !here;
      setOverlay(o, o.on);
      if (here) {
        any = true;
        if (sources.indexOf(o.source) < 0) { sources.push(o.source); }
      }
    });
    document.getElementById('overlays').hidden = !any;
    document.getElementById('overlay-credits').textContent = sources.join('; ') || 'none for this catchment yet';
  }

  /** Read the list of flood maps and build their checkboxes. */
  function loadOverlayIndex() {
    fetch('data/overlays/index.json?t=' + Date.now())
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
      .then(function (index) {
        state.overlayIndex = index;
        var list = document.getElementById('overlay-list');
        (index.layers || []).forEach(function (layer) {
          // Catchment outlines now come from data/catchments/ (an older index may still list one).
          if (!/^[a-z0-9_]+$/.test(layer.key) || layer.style === 'outline') { return; }
          var o = {
            key: layer.key, label: layer.label, hover: layer.hover, fetched: layer.fetched,
            catchment: layer.catchment || 'brisbane', source: layer.source,
            style: 'fill', on: false, feats: null, loading: false, group: L.layerGroup()
          };
          var row = el('label', 'overlay-row');
          var box = el('input');
          box.type = 'checkbox';
          box.checked = !!layer.on;
          var swatch = el('span', o.style === 'outline' ? 'swatch outline' : 'swatch');
          var swatchColour = cssVar('--ov-' + o.key) || cssVar('--ov-default');
          if (o.style === 'outline') { swatch.style.borderColor = swatchColour; } else { swatch.style.background = swatchColour; }
          row.appendChild(box);
          row.appendChild(swatch);
          row.appendChild(document.createTextNode(layer.label));
          o.status = el('span', 'small');
          row.appendChild(o.status);
          list.appendChild(row);
          o.row = row;
          box.addEventListener('change', function () { setOverlay(o, box.checked); hideInfo(); });
          state.overlays.push(o);
          o.on = !!layer.on;
        });
        applyOverlayCatchment();
      })
      .catch(function () { /* No flood maps downloaded yet; the section stays hidden. */ });
  }

  // Hover read-out (mouse only): a small box on the map naming the flood maps under the pointer.
  var info = L.control({ position: 'bottomleft' });
  info.onAdd = function () {
    var div = L.DomUtil.create('div', 'map-info');
    div.hidden = true;
    return div;
  };
  info.addTo(map);

  /** Hide the hover read-out. */
  function hideInfo() { info.getContainer().hidden = true; }

  var hoverPending = null;
  // Only for a real mouse: on phones a tap shows a popup instead.
  var canHover = window.matchMedia && window.matchMedia('(hover: hover)').matches;
  map.on('mousemove', function (e) {
    if (!canHover || hoverPending) { return; }
    hoverPending = window.requestAnimationFrame(function () {
      hoverPending = null;
      var hits = overlaysAt(e.latlng);
      var div = info.getContainer();
      div.textContent = hits.map(function (o) { return o.hover; }).join(' · ');
      div.hidden = hits.length === 0;
    });
  });
  map.on('mouseout', hideInfo);

  /** Turn "Creek River Storm Tide" into "creek, river and storm tide". */
  function floodTypes(text) {
    var parts = (String(text || '').match(/Overland Flow|Storm Tide|Creek|River/gi) || []).map(function (p) {
      return p.toLowerCase();
    });
    if (parts.length < 2) { return parts.join(''); }
    return parts.slice(0, -1).join(', ') + ' and ' + parts[parts.length - 1];
  }

  /** Ask the council's map service for the flood likelihood at one point, and show it in `line`. */
  function lookupLikelihood(latlng, line) {
    var index = state.overlayIndex;
    if (!index || !index.likelihood_url || !BRISBANE_BOUNDS.contains(latlng)) { return; }
    line.textContent = 'Checking council flood likelihood…';
    var url = index.likelihood_url + '/query?' + [
      'geometry=' + latlng.lng.toFixed(6) + ',' + latlng.lat.toFixed(6),
      'geometryType=esriGeometryPoint', 'inSR=4326', 'spatialRel=esriSpatialRelIntersects',
      'outFields=FLOOD_RISK,FLOOD_TYPE', 'returnGeometry=false', 'f=json'
    ].join('&');
    var ctrl = window.AbortController ? new AbortController() : null;
    var timer = setTimeout(function () { if (ctrl) { ctrl.abort(); } }, 8000);
    fetch(url, ctrl ? { signal: ctrl.signal } : {})
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
      .then(function (data) {
        if (data.error) { throw new Error('service'); }
        var best = null;
        (data.features || []).forEach(function (f) {
          var a = f.attributes || {};
          if (!best || LIKELIHOOD_ORDER.indexOf(a.FLOOD_RISK) > LIKELIHOOD_ORDER.indexOf(best.FLOOD_RISK)) { best = a; }
        });
        line.textContent = '';
        if (!best) {
          line.textContent = 'Not in Brisbane City Council\'s flood likelihood map (it covers Brisbane only).';
          return;
        }
        line.appendChild(document.createTextNode('Council flood likelihood: '));
        line.appendChild(el('strong', null, best.FLOOD_RISK));
        var types = floodTypes(best.FLOOD_TYPE);
        if (types) { line.appendChild(document.createTextNode(' (from ' + types + ')')); }
        if (index.likelihood_link && /^https:\/\//.test(index.likelihood_link)) {
          line.appendChild(document.createTextNode('. '));
          var a = el('a', null, 'What this means');
          a.href = index.likelihood_link;
          a.target = '_blank';
          a.rel = 'noopener';
          line.appendChild(a);
        }
      })
      .catch(function () { line.textContent = 'Couldn\'t reach the council\'s flood map just now.'; })
      .then(function () { clearTimeout(timer); });
  }

  /** The nearest river and creek gauges to a point, closest first. */
  function nearestStreams(latlng, count) {
    if (!state.data) { return []; }
    return state.data.sensors
      .filter(function (s) { return s.type === 'stream'; })
      .map(function (s) { return { s: s, d: latlng.distanceTo([s.lat, s.lon]) }; })
      .filter(function (x) { return x.d <= 15000; })
      .sort(function (a, b) { return a.d - b.d; })
      .slice(0, count);
  }

  /** Show what's known about a spot: flood maps covering it, council likelihood, nearby gauges. */
  function placePopup(latlng, title, withGauges) {
    var box = el('div', 'place-popup');
    box.appendChild(el('strong', null, title || 'This spot'));
    if (state.data) { box.appendChild(scoreBlock(placeScore(latlng.lat, latlng.lng))); }
    var hits = overlaysAt(latlng);
    var anyOn = state.overlays.some(overlayShown);
    if (hits.length) {
      var ul = el('ul', 'place-hits');
      hits.forEach(function (o) { ul.appendChild(el('li', null, o.hover)); });
      box.appendChild(ul);
    } else if (anyOn) {
      box.appendChild(el('div', 'small', state.catchment === 'brisbane'
        ? 'Not inside the flood maps that are switched on. These maps cover Brisbane, and Ipswich for 2011, ' +
          'so outside those areas check your council\'s flood map.'
        : 'Not inside the flood maps that are switched on. This catchment has only the state\'s broad-scale ' +
          'map so far: check your council\'s flood map.'));
    }
    var line = el('div', 'likelihood');
    box.appendChild(line);
    lookupLikelihood(latlng, line);
    if (withGauges) {
      var near = nearestStreams(latlng, 3);
      if (near.length) {
        box.appendChild(el('div', 'subhead', 'Nearest river and creek gauges'));
        near.forEach(function (x) {
          var btn = el('button', 'nearlink');
          btn.type = 'button';
          var km = x.d < 1000 ? Math.round(x.d / 10) * 10 + ' m' : (x.d / 1000).toFixed(1) + ' km';
          var status = x.s.note ? 'may be faulty' : x.s.stale ? 'not reporting'
            : flooding(x.s.flood_class) ? FLOOD_LABELS[x.s.flood_class] : TREND_LABELS[x.s.trend || 'unknown'];
          btn.textContent = x.s.name + ' — ' + km + ', ' + status.toLowerCase();
          btn.addEventListener('click', function () { map.closePopup(); select(x.s.id); });
          box.appendChild(btn);
        });
      }
    }
    L.popup({ maxWidth: popupWidth() })
      .setLatLng(latlng).setContent(box).openOn(map);
  }

  // A click or tap on the map (not on a marker) shows the flood maps at that spot.
  map.on('click', function (e) {
    if (state.overlays.some(overlayShown)) { placePopup(e.latlng, null, false); }
  });

  // ---------- Place search (OpenStreetMap Nominatim) ----------

  var lastSearch = 0;

  /** Go to a found place, mark it, and show what's there. */
  function goToPlace(lat, lon, name) {
    var latlng = L.latLng(lat, lon);
    if (state.searchPin) { map.removeLayer(state.searchPin); }
    state.searchPin = L.marker(latlng, {
      icon: L.divIcon({ className: '', html: '<span class="search-pin"></span>', iconSize: [0, 0] }),
      interactive: false,
      zIndexOffset: 2000
    }).addTo(map);
    // Jump without animation so the popup can pan itself fully into view afterwards.
    map.setView(latlng, Math.max(map.getZoom(), 15), { animate: false });
    placePopup(latlng, name, true);
  }

  document.getElementById('search-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var q = document.getElementById('search-input').value.trim();
    var list = document.getElementById('search-results');
    var status = document.getElementById('search-status');
    if (q.length < 3) { return; }
    // OpenStreetMap's free service asks for no more than one search a second.
    if (Date.now() - lastSearch < 1200) { return; }
    lastSearch = Date.now();
    list.hidden = true;
    list.textContent = '';
    status.hidden = false;
    status.textContent = 'Searching…';
    // Search the catchment on screen and a little around it (south-east Queensland until it's known).
    var c = catchmentInfo(state.catchment);
    var b = c && c.bbox ? [c.bbox[1] - 0.3, c.bbox[2] + 0.3, c.bbox[3] + 0.3, c.bbox[0] - 0.3] : [151.2, -26.4, 153.6, -28.6];
    var url = 'https://nominatim.openstreetmap.org/search?format=jsonv2&limit=6&countrycodes=au' +
      '&accept-language=en-AU&bounded=1&viewbox=' + b.map(function (v) { return v.toFixed(2); }).join(',') +
      '&q=' + encodeURIComponent(q);
    fetch(url)
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
      .then(function (results) {
        if (!results.length) {
          status.textContent = 'No matches in or near the ' + catchmentName(state.catchment) + ' catchment. Try a street and suburb.';
          return;
        }
        status.hidden = true;
        results.forEach(function (res) {
          var li = el('li');
          var btn = el('button', 'resultlink', res.display_name.replace(/, Australia$/, ''));
          btn.type = 'button';
          btn.addEventListener('click', function () {
            list.hidden = true;
            goToPlace(parseFloat(res.lat), parseFloat(res.lon), res.display_name.split(',')[0]);
          });
          li.appendChild(btn);
          list.appendChild(li);
        });
        list.hidden = false;
        if (results.length === 1) { list.querySelector('button').click(); }
      })
      .catch(function () { status.textContent = 'Search isn\'t available just now. Please try again shortly.'; });
  });

  // ---------- Road closures and flooding (QLDTraffic) ----------

  var ROAD_COLOURS = { closed: '#c62828', flooding: '#1f6fd1' };

  /** Build the popup for a road event, using only text from the data (never markup). */
  function roadPopup(ev) {
    var box = el('div', 'road-popup');
    var where = [ev.road, ev.locality].filter(Boolean).join(', ');
    box.appendChild(el('strong', null, where || 'Road event'));
    if (ev.road_given) {
      box.appendChild(el('div', 'small', 'QLDTraffic gave the place as “' + ev.road_given + '”, which doesn\'t look like ' +
        'a Queensland road name. Go by where it is on the map.'));
    }
    if (ev.impact) { box.appendChild(el('div', ev.closed ? 'road-closed' : 'road-open', ev.impact)); }
    box.appendChild(el('div', 'small', [ev.type, ev.subtype].filter(Boolean).join(' — ')));
    if (ev.description) { box.appendChild(el('div', null, ev.description)); }
    if (ev.advice) { box.appendChild(el('div', null, 'Advice: ' + ev.advice)); }
    if (ev.information) { box.appendChild(el('div', 'small', ev.information)); }
    var when = [];
    if (ev.start) { when.push('Since ' + fmtTime(ev.start)); }
    if (ev.updated) { when.push('updated ' + fmtTime(ev.updated)); }
    if (when.length) { box.appendChild(el('div', 'small', when.join(', '))); }
    if (ev.source) { box.appendChild(el('div', 'small', 'Reported by ' + ev.source)); }
    var a = el('a', null, 'More on QLDTraffic');
    a.href = ev.link && /^https:\/\//i.test(ev.link) ? ev.link : 'https://qldtraffic.qld.gov.au/';
    a.target = '_blank';
    a.rel = 'noopener noreferrer';
    box.appendChild(a);
    return box;
  }

  /** Draw the road events in the catchment on screen: coloured lines along affected roads, and a sign at each point. */
  function drawTraffic(data) {
    state.trafficData = data;
    state.trafficLayer.clearLayers();
    var events = (data.events || []).filter(inCatchment);
    events.forEach(function (ev) {
      var colour = ev.closed ? ROAD_COLOURS.closed : ROAD_COLOURS.flooding;
      var label = (ev.closed ? 'Road closed' : 'Flooding') + ' — ' + ([ev.road, ev.locality].filter(Boolean).map(esc).join(', ') || 'road');
      var popup = roadPopup(ev);
      (ev.lines || []).forEach(function (line) {
        var pl = L.polyline(line, {
          color: colour, weight: 5, opacity: 0.85, dashArray: ev.closed ? null : '8 6',
          bubblingMouseEvents: false
        });
        pl.bindTooltip(label, { sticky: true });
        pl.bindPopup(popup);
        state.trafficLayer.addLayer(pl);
      });
      // A sign at each point, or at the middle of the first line if there are no points.
      var pts = (ev.points || []).slice();
      if (!pts.length && ev.lines && ev.lines.length) { pts.push(ev.lines[0][Math.floor(ev.lines[0].length / 2)]); }
      pts.forEach(function (p) {
        var icon = L.divIcon({
          className: '',
          html: ev.closed ? '<span class="road-icon closed"></span>' : '<span class="road-icon flooding">≈</span>',
          iconSize: [0, 0]
        });
        var m = L.marker(p, { icon: icon, keyboard: true, zIndexOffset: 1500, title: label });
        m.bindTooltip(label);
        m.bindPopup(popup);
        state.trafficLayer.addLayer(m);
      });
    });
    var closed = events.filter(function (ev) { return ev.closed; }).length;
    var flooding = events.length - closed;
    var note = document.getElementById('traffic-note');
    if (data.problem) {
      // Collection is failing: say so plainly, and how old any roads still shown are.
      note.textContent = 'Road information isn\'t available right now (' + data.problem.message + ').' +
        (data.fetched && events.length ? ' Roads shown are from ' + fmtTime(data.fetched) + '.' : '') +
        ' Check QLDTraffic directly.';
      return;
    }
    var age = (Date.now() / 1000 - data.fetched) / 60;
    note.textContent = closed + ' road closure' + (closed === 1 ? '' : 's') + ' and ' + flooding +
      ' other flooding report' + (flooding === 1 ? '' : 's') + ' in this catchment, as at ' + fmtTime(data.fetched) +
      (age > (data.stale_minutes || 60) ? ' (may be out of date)' : '') + '.';
  }

  /** Load the latest road events. */
  function loadTraffic() {
    fetch('data/traffic.json?t=' + Date.now())
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
      .then(drawTraffic)
      .catch(function () {
        document.getElementById('traffic-note').textContent = 'Road information isn\'t available yet.';
      });
  }

  // ---------- Watch list (saved in this browser only) ----------

  var WATCH_KEY = 'floodwatcher.watch';

  /** The ids of watched gauges. Browser storage can be blocked, so failures mean "none". */
  function watchList() {
    try {
      var list = JSON.parse(window.localStorage.getItem(WATCH_KEY) || '[]');
      return Array.isArray(list) ? list.filter(function (x) { return typeof x === 'string'; }) : [];
    } catch (e) {
      return [];
    }
  }

  /** Add or remove a gauge from the watch list; returns true if it is now watched. */
  function toggleWatch(id) {
    var list = watchList();
    var i = list.indexOf(id);
    if (i >= 0) { list.splice(i, 1); } else { list.push(id); }
    try {
      window.localStorage.setItem(WATCH_KEY, JSON.stringify(list));
    } catch (e) {
      /* Storage blocked (e.g. private browsing): the star still works until the page closes. */
    }
    state.watchFallback = list;
    drawWatchRings();
    showAlerts();
    if (push.sub) { pushSync().then(function () { pushShow(); }).catch(function (e) { pushShow(e.message); }); }
    return i < 0;
  }

  /** True if a gauge is on the watch list. */
  function isWatched(id) {
    return watchList().indexOf(id) >= 0 || (state.watchFallback || []).indexOf(id) >= 0;
  }

  /** Draw a gold ring around each watched gauge. */
  function drawWatchRings() {
    state.watchLayer.clearLayers();
    if (!state.data) { return; }
    state.data.sensors.forEach(function (s) {
      if (isWatched(s.id) && inCatchment(s)) {
        state.watchLayer.addLayer(L.circleMarker([s.lat, s.lon], {
          radius: 13, color: '#d49a00', weight: 3, fill: false, interactive: false
        }));
      }
    });
  }

  // ---------- Predictive flood alerts (flood potential rules) ----------

  var PREDICT_KEY = 'floodwatcher.predictive';

  /** True for a flood potential rule's alert (as opposed to an observed gauge level). */
  function isPrediction(a) { return a && (a.kind === 'potential' || a.kind === 'potential_rain'); }

  /** Whether predictive alerts are switched on in this browser (on unless switched off). */
  function predictOn() {
    try { return window.localStorage.getItem(PREDICT_KEY) !== 'off'; } catch (e) { return state.predictOn !== false; }
  }

  /** Switch predictive alerts on or off in this browser. */
  function setPredictOn(on) {
    state.predictOn = on;
    try { window.localStorage.setItem(PREDICT_KEY, on ? 'on' : 'off'); } catch (e) { /* Kept until the page closes. */ }
    showPredictions();
    refreshUpstream();
  }

  // ---------- Flood-prone land a prediction covers (worked out on the server, lib/floodmask.php) ----------

  /**
   * Shade the flood-prone land a prediction covers, in the prediction colour. The server sends it
   * as runs of squares along each row ({cell, w, s, runs: [[row, first column, last column]]});
   * runs that line up exactly in neighbouring rows are joined into blocks so fewer shapes are drawn.
   * Without it (flood maps not set up on the site), the prediction's box is outlined instead.
   */
  function drawFloodArea(a, layer) {
    var colour = cssVar('--predict');
    var area = a.area;
    if (!area || !area.runs || !area.runs.length) {
      layer.addLayer(L.rectangle([[a.bbox[0], a.bbox[1]], [a.bbox[2], a.bbox[3]]], {
        color: colour, weight: 2, dashArray: '8 5', fillColor: colour, fillOpacity: 0.06, interactive: false
      }));
      return;
    }
    var blocks = [];
    var open = {};     // Blocks reaching the previous row, keyed "first column:last column".
    var prevRow = null;
    var byRow = [];    // The runs grouped by row (the server sends them in row order).
    area.runs.forEach(function (run) {
      var last = byRow[byRow.length - 1];
      if (last && last.row === run[0]) { last.runs.push(run); } else { byRow.push({ row: run[0], runs: [run] }); }
    });
    byRow.forEach(function (g) {
      var next = prevRow !== null && g.row === prevRow + 1;
      var here = {};
      g.runs.forEach(function (run) {
        var key = run[1] + ':' + run[2];
        if (next && open[key]) {
          here[key] = open[key];
          here[key].r1 = g.row;
        } else {
          here[key] = { c0: run[1], c1: run[2], r0: g.row, r1: g.row };
        }
      });
      Object.keys(open).forEach(function (k) { if (here[k] !== open[k]) { blocks.push(open[k]); } });
      open = here;
      prevRow = g.row;
    });
    Object.keys(open).forEach(function (k) { blocks.push(open[k]); });
    var cell = area.cell;
    blocks.forEach(function (b) {
      layer.addLayer(L.rectangle([
        [area.s + b.r0 * cell, area.w + b.c0 * cell], [area.s + (b.r1 + 1) * cell, area.w + (b.c1 + 1) * cell]
      ], { renderer: floodRenderer, stroke: false, fillColor: colour, fillOpacity: 0.38, interactive: false }));
    });
  }

  // Phones and narrow windows: the panels start small so they don't cover the map.
  var SMALL_SCREEN = window.matchMedia ? window.matchMedia('(max-width: 760px)') : { matches: false };

  /** Read a remembered value from this browser (null if none, or storage is blocked). */
  function memGet(key) {
    try { return JSON.parse(window.localStorage.getItem(key) || 'null'); } catch (e) { return null; }
  }

  /** Remember a value in this browser (silently skipped if storage is blocked). */
  function memSet(key, value) {
    try { window.localStorage.setItem(key, JSON.stringify(value)); } catch (e) { /* Kept for this page only. */ }
  }

  var PREDICT_LABELS = { likely: 'Flooding likely', watch: 'Watch' };

  /** The popup for a prediction: what, why, since when, and a button to show the area. */
  function predictPopup(a) {
    var box = el('div', 'predict-popup predict-' + a.level);
    var top = el('div', 'predict-top');
    top.appendChild(el('span', 'predict-chip', PREDICT_LABELS[a.level] || 'Watch'));
    top.appendChild(el('span', 'predict-kind', 'Predictive flood alert'));
    box.appendChild(top);
    box.appendChild(el('strong', null, a.name));
    box.appendChild(el('div', 'predict-text', a.text.charAt(0).toUpperCase() + a.text.slice(1) + '.'));
    var foot = el('div', 'predict-foot');
    foot.appendChild(el('span', 'small', 'Since ' + fmtHM(a.since)));
    if (a.bbox) {
      var show = el('button', 'predict-show', 'Show area');
      show.type = 'button';
      show.addEventListener('click', function () {
        map.closePopup();
        map.fitBounds(L.latLngBounds([a.bbox[0], a.bbox[1]], [a.bbox[2], a.bbox[3]]), { padding: [20, 20] });
        state.predictAreaLayer.clearLayers();
        drawFloodArea(a, state.predictAreaLayer);
      });
      foot.appendChild(show);
    }
    box.appendChild(foot);
    box.appendChild(el('p', 'small', 'This site\'s estimate from past floods, not a BoM warning. ' +
      'Follow BoM warnings and emergency services.'));
    return box;
  }

  /**
   * Predictive flood alerts: a pulsing purple icon on the map where the flooding is predicted (tap
   * it for the details; the flood-prone land it covers is shaded while that's open), and a small
   * chip in the top corner while any is active, which jumps to them. Switched on and off with the
   * "Predictive flood alerts" tick box in the map options.
   */
  function showPredictions() {
    var chip = state.predict;
    if (!chip) { return; }
    var on = predictOn();
    var active = on ? ((state.alerts || {}).alerts || []).filter(function (a) { return isPrediction(a) && inCatchment(a); }) : [];
    // Redraw the icons only when the predictions change (this runs on every map move, and
    // redrawing would close an open popup).
    var key = state.catchment + JSON.stringify(active.map(function (a) { return [a.id, a.level, a.text, a.since, a.lat, a.lon]; }));
    if (key === state.predictDrawn) { return; }
    state.predictDrawn = key;
    state.predictLayer.clearLayers();
    state.predictMarkers = {};
    active.forEach(function (a) {
      var icon = L.divIcon({
        className: '',
        html: '<span class="predict-pin predict-pin-' + a.level + '" aria-hidden="true">!</span>',
        iconSize: [0, 0]
      });
      var m = L.marker([a.lat, a.lon], {
        icon: icon, keyboard: true, zIndexOffset: 1000,
        title: 'Predictive flood alert (' + (PREDICT_LABELS[a.level] || 'Watch').toLowerCase() + '): ' + a.name
      });
      m.bindPopup(function () { return predictPopup(a); });
      m.on('popupopen', function () { drawFloodArea(a, state.predictAreaLayer); });
      m.on('popupclose', function () { state.predictAreaLayer.clearLayers(); });
      state.predictLayer.addLayer(m);
      state.predictMarkers[a.id] = m;
    });

    chip.hidden = !active.length;
    if (!active.length) {
      state.predictAreaLayer.clearLayers();
      return;
    }
    var worst = active.some(function (a) { return a.level === 'likely'; }) ? 'likely' : 'watch';
    chip.className = 'predict-chip-btn predict-' + worst;
    chip.textContent = active.length === 1
      ? '! ' + (PREDICT_LABELS[active[0].level] || 'Watch') + ': ' + active[0].name.split(' (')[0]
      : '! ' + active.length + ' predictive flood alerts';
    chip.title = 'Show on the map';
    chip.onclick = function (e) {
      L.DomEvent.stop(e);
      if (active.length === 1) {
        var only = state.predictMarkers[active[0].id];
        map.setView(only.getLatLng(), Math.max(map.getZoom(), 10));
        only.openPopup();
      } else {
        map.fitBounds(L.latLngBounds(active.map(function (a) { return [a.lat, a.lon]; })), { padding: [60, 60], maxZoom: 11 });
      }
    };
  }

  // ---------- Alerts card (top right of the map) ----------

  var LEVEL_LABELS = {
    major: 'Major', moderate: 'Moderate', minor: 'Minor', warning: 'Flood warning', likely: 'Likely', watch: 'Watch'
  };
  var LEVEL_RANK = { watch: 0, likely: 1, warning: 2, minor: 3, moderate: 4, major: 5 };
  var ALERT_AUTO_CLOSE_MS = 10000;

  /** Jump to a gauge on the map and open its details (switching to its catchment if need be). */
  function goToGauge(id, a) {
    var s = state.data && state.data.sensors.find(function (x) { return x.id === id; });
    var target = s || a;
    if (target && !inCatchment(target)) { setCatchment(catchmentOf(target), { keepView: true }); }
    if (!s) {
      // A flood potential alert isn't a gauge: just show its place.
      if (a && a.lat) { map.setView([a.lat, a.lon], Math.max(map.getZoom(), 11)); }
      return;
    }
    map.setView([s.lat, s.lon], Math.max(map.getZoom(), 12));
    select(id);
  }

  /** One gauge alert line: a button that opens the gauge. */
  function alertRow(a) {
    var btn = el('button', 'alert-row');
    btn.type = 'button';
    btn.appendChild(el('span', 'lvl lvl-' + a.level, LEVEL_LABELS[a.level] || a.level));
    if (isWatched(a.id)) { btn.appendChild(el('span', 'star', '★')); }
    btn.appendChild(el('strong', null, a.name));
    if (!inCatchment(a)) { btn.appendChild(el('span', 'alert-where', ' (' + catchmentName(catchmentOf(a)) + ')')); }
    btn.appendChild(document.createTextNode(' — ' + a.text +
      (a.not_reporting ? ' (gauge not reporting since)' : '') + ', since ' + fmtHM(a.since) + '.'));
    btn.addEventListener('click', function () { goToGauge(a.id, a); });
    return btn;
  }

  /**
   * Split the current alerts into those shown and those elsewhere. "This catchment" (the usual
   * choice) shows the catchment's alerts in the map view; "All catchments" shows every one.
   */
  function alertsInView() {
    var data = state.alerts || {};
    var all = state.alertScope === 'all';
    var view = map.getBounds().pad(0.05);
    var where = {};
    (state.data ? state.data.sensors : []).forEach(function (s) { where[s.id] = [s.lat, s.lon]; });
    var inView = function (a) {
      if (a.bbox) { return view.intersects(L.latLngBounds([a.bbox[0], a.bbox[1]], [a.bbox[2], a.bbox[3]])); }
      var p = where[a.id] || (a.lat ? [a.lat, a.lon] : null);
      return p ? view.contains(p) : true;
    };
    var warnIn = function (w) {
      // A warning with no area (it names none of our gauges' rivers) is always shown.
      return !w.bbox || view.intersects(L.latLngBounds([w.bbox[0], w.bbox[1]], [w.bbox[2], w.bbox[3]]));
    };
    var allWarnings = data.warnings || [];
    var allAlerts = (data.alerts || []).filter(function (a) { return !isPrediction(a); }); // Predictions are map icons.
    var warnings = all ? allWarnings : allWarnings.filter(warningHere);
    var alerts = all ? allAlerts : allAlerts.filter(inCatchment);
    var shownW = all ? warnings : warnings.filter(warnIn);
    var shownA = all ? alerts : alerts.filter(inView);
    return {
      warnings: shownW,
      alerts: shownA,
      cleared: (data.cleared || []).filter(function (c) {
        return isWatched(c.id) && !/^P-/.test(c.id) && (all || (inCatchment(c) && inView(c)));
      }),
      elsewhere: (warnings.length - shownW.length) + (alerts.length - shownA.length),
      // In other catchments (counted while showing this catchment only).
      other: all ? 0 : (allWarnings.length - warnings.length) + (allAlerts.length - alerts.length),
      total: allWarnings.length + allAlerts.length
    };
  }

  /** Fill the card with the alerts for the area on screen, and update the button. */
  function showAlerts() {
    var card = state.alertCard;
    var pill = state.alertPill;
    if (!card) { return; }
    var v = alertsInView();
    var body = card.querySelector('.alert-body');
    body.replaceChildren();

    if (v.warnings.length) {
      var wbox = el('div', 'warnings');
      v.warnings.forEach(function (w) {
        var row = el('div', 'warning-row');
        row.appendChild(el('span', 'lvl lvl-' + w.level, w.final ? 'Final' : (LEVEL_LABELS[w.level] || 'Warning')));
        var a = el('a', 'warning-title', w.title);
        if (!warningHere(w)) {
          row.appendChild(el('span', 'alert-where', (w.catchments || ['brisbane']).map(catchmentName).join(', ') + ': '));
        }
        a.href = /^https:\/\//.test(w.url) ? w.url : 'https://www.bom.gov.au/qld/warnings/';
        a.target = '_blank';
        a.rel = 'noopener';
        row.appendChild(a);
        row.appendChild(el('span', 'small', ' BoM, issued ' + fmtTime(w.issued)));
        (w.headlines || []).slice(0, 2).forEach(function (h) {
          // Shown as BoM wrote it (in capitals): lower-casing would also lower-case the place names.
          row.appendChild(el('div', 'warning-headline', h));
        });
        wbox.appendChild(row);
      });
      body.appendChild(wbox);
    }

    var rows = v.alerts.filter(function (a) { return isWatched(a.id); })
      .concat(v.alerts.filter(function (a) { return !isWatched(a.id); }));
    if (rows.length) {
      var counts = {};
      rows.forEach(function (a) { counts[a.level] = (counts[a.level] || 0) + 1; });
      var summary = ['major', 'moderate', 'minor'].filter(function (k) { return counts[k]; })
        .map(function (k) { return counts[k] + ' at ' + k + ' flood level'; });
      if (counts.likely) { summary.push(counts.likely + ' where flooding is likely (this site\'s estimate)'); }
      if (counts.watch) { summary.push(counts.watch + ' to watch (rising, or heavy rain upstream)'); }
      body.appendChild(el('div', 'alert-summary', 'Gauges: ' + summary.join(', ') + '.'));
      var shown = state.alertsExpanded ? rows : rows.slice(0, 4);
      shown.forEach(function (a) { body.appendChild(alertRow(a)); });
      if (rows.length > 4) {
        var more = el('button', 'alert-more', state.alertsExpanded ? 'Show fewer' : 'Show all ' + rows.length);
        more.type = 'button';
        more.addEventListener('click', function (e) {
          L.DomEvent.stop(e); // The card is redrawn; don't let the click fall through to the map.
          state.alertsExpanded = !state.alertsExpanded;
          showAlerts();
        });
        body.appendChild(more);
      }
    }
    v.cleared.forEach(function (c) {
      body.appendChild(el('div', 'alert-clear', '★ All clear: ' + c.name + ' is ' + c.text + ' (' + fmtHM(c.at) + ').'));
    });

    var here = v.warnings.length + v.alerts.length;
    if (!here) {
      body.appendChild(el('p', 'alert-none', state.alertScope === 'all'
        ? 'No flood warnings or gauge alerts in any catchment.'
        : 'No flood warnings or gauge alerts in this part of the map.'));
    }
    if (v.elsewhere) {
      var other = el('button', 'alert-more', v.elsewhere + ' more elsewhere in the catchment: show all');
      other.type = 'button';
      other.addEventListener('click', function () { resetView(); });
      body.appendChild(other);
    }
    if (v.other) {
      var others = el('button', 'alert-more', v.other + ' in other catchments: show all catchments');
      others.type = 'button';
      others.addEventListener('click', function (e) { L.DomEvent.stop(e); setAlertScope('all'); });
      body.appendChild(others);
    }
    var scope = card.querySelector('.alert-scope');
    scope.textContent = state.alertScope === 'all' ? 'This catchment only' : 'All catchments';
    scope.setAttribute('aria-pressed', state.alertScope === 'all' ? 'true' : 'false');
    card.querySelector('.alert-title').textContent = state.alertScope === 'all' ? 'Alerts in all catchments' : 'Alerts in this area';
    if (here) {
      body.appendChild(el('div', 'small', 'Gauge alerts are worked out by this site from raw readings and are ' +
        'not official. BoM flood warnings are official: follow them.'));
    }

    // The button: the worst level on screen sets its colour.
    var worst = null;
    v.warnings.concat(v.alerts).forEach(function (x) {
      if (worst === null || LEVEL_RANK[x.level] > LEVEL_RANK[worst]) { worst = x.level; }
    });
    var parts = [];
    if (v.warnings.length) { parts.push(v.warnings.length + ' BoM warning' + (v.warnings.length === 1 ? '' : 's')); }
    if (v.alerts.length) { parts.push(v.alerts.length + ' gauge alert' + (v.alerts.length === 1 ? '' : 's')); }
    var away = v.elsewhere + v.other;
    pill.textContent = here ? '⚠ ' + parts.join(' · ') : 'No alerts here' + (away ? ' (' + away + ' elsewhere)' : '');
    pill.className = 'alert-pill' + (worst ? ' lvl-' + worst : ' quiet') + (state.alertPulse ? ' pulse' : '');
    state.alertNone = v.total === 0 && !watchList().length;
    applyAlertVisibility();
    showPredictions();
  }

  /** Show alerts for this catchment ('here') or all catchments ('all'); remembered in this browser. */
  function setAlertScope(scope) {
    state.alertScope = scope === 'all' ? 'all' : 'here';
    memSet('floodwatcher.alertScope', state.alertScope);
    state.alertsExpanded = false;
    showAlerts();
  }

  /** Show the button or the card (or neither, when there's nothing to say). */
  function applyAlertVisibility() {
    state.alertCard.hidden = !state.alertOpen || state.alertNone;
    state.alertPill.hidden = state.alertOpen || state.alertNone;
  }

  /** Open or close the card. */
  function setAlertsOpen(open) {
    state.alertOpen = open;
    applyAlertVisibility();
    state.alertPill.setAttribute('aria-expanded', open ? 'true' : 'false');
    clearTimeout(state.alertTimer);
  }

  /**
   * Something new: open the card for a while, then shrink it back to the button unless it's being
   * used. On a phone the card would cover most of the map, so the button pulses instead.
   */
  function flashAlerts() {
    if (SMALL_SCREEN.matches) {
      var pill = state.alertPill;
      pill.classList.remove('pulse');
      void pill.offsetWidth; // Restart the animation.
      pill.classList.add('pulse');
      state.alertPulse = true; // Kept when the button is redrawn, until the pulsing ends.
      pill.addEventListener('animationend', function done() {
        state.alertPulse = false;
        pill.classList.remove('pulse');
        pill.removeEventListener('animationend', done);
      });
      return;
    }
    setAlertsOpen(true);
    state.alertTimer = setTimeout(function closeLater() {
      var c = state.alertCard;
      if (c.matches(':hover') || c.contains(document.activeElement)) {
        state.alertTimer = setTimeout(closeLater, 3000);
        return;
      }
      setAlertsOpen(false);
    }, ALERT_AUTO_CLOSE_MS);
  }

  var AlertControl = L.Control.extend({
    options: { position: 'topright' },
    onAdd: function () {
      var box = L.DomUtil.create('div', 'alert-control');
      // Predictive flood alerts: a small chip while any is active (the alerts are icons on the map).
      var predict = L.DomUtil.create('button', 'predict-chip-btn', box);
      predict.type = 'button';
      predict.hidden = true;
      predict.setAttribute('aria-live', 'polite');
      state.predict = predict;
      var pill = L.DomUtil.create('button', 'alert-pill quiet', box);
      pill.type = 'button';
      pill.setAttribute('aria-expanded', 'false');
      var card = L.DomUtil.create('section', 'alert-card', box);
      card.setAttribute('aria-label', 'Flood warnings and gauge alerts for this part of the map');
      card.setAttribute('aria-live', 'polite');
      card.hidden = true;
      var head = L.DomUtil.create('div', 'alert-head', card);
      head.appendChild(el('strong', 'alert-title', 'Alerts in this area'));
      var scope = L.DomUtil.create('button', 'alert-scope', head);
      scope.type = 'button';
      scope.textContent = 'All catchments';
      scope.title = 'Switch between this catchment\'s alerts and every catchment\'s';
      L.DomEvent.on(scope, 'click', function (e) {
        L.DomEvent.stop(e);
        setAlertScope(state.alertScope === 'all' ? 'here' : 'all');
      });
      var min = L.DomUtil.create('button', 'alert-min', head);
      min.type = 'button';
      min.textContent = '–';
      min.title = 'Minimise';
      min.setAttribute('aria-label', 'Minimise alerts');
      L.DomUtil.create('div', 'alert-body', card);
      L.DomEvent.disableClickPropagation(box);
      L.DomEvent.disableScrollPropagation(box);
      L.DomEvent.on(pill, 'click', function () { setAlertsOpen(true); });
      L.DomEvent.on(min, 'click', function () { setAlertsOpen(false); });
      state.alertPill = pill;
      state.alertCard = card;
      return box;
    }
  });
  state.alertControl = new AlertControl();
  map.addControl(state.alertControl);
  state.alertNone = true;
  applyAlertVisibility();
  showPredictions();

  // Once a popup has opened: make sure it's fully on screen, and if the panels would cover it,
  // they step aside until it closes.
  map.on('popupopen', function (e) {
    var box = state.alertControl.getContainer();
    // Popups made when the page loaded (cameras, roads) get the width that fits the map now.
    if (e.popup.options.maxWidth !== popupWidth()) {
      e.popup.options.maxWidth = popupWidth();
      e.popup.update();
    }
    var settle = function () {
      var popup = e.popup.getElement();
      if (!popup || !map.hasLayer(e.popup)) { return; }
      // Leaflet keeps the size the popup had when it opened; have it measure again (and move the
      // map to fit) now that its content may have grown.
      e.popup.update();
      var a = popup.getBoundingClientRect();
      var b = box.getBoundingClientRect();
      var overlap = a.left < b.right && a.right > b.left && a.top < b.bottom && a.bottom > b.top;
      box.classList.toggle('stepped-aside', overlap);
    };
    // After the map has panned to fit the popup, and again once late details (the council's flood
    // likelihood) have filled in and made it bigger.
    setTimeout(settle, 350);
    setTimeout(settle, 1500);
  });
  map.on('popupclose', function () { state.alertControl.getContainer().classList.remove('stepped-aside'); });

  // Re-filter when the map moves (after it settles).
  var alertMoveTimer = null;
  map.on('moveend', function () {
    clearTimeout(alertMoveTimer);
    alertMoveTimer = setTimeout(function () { if (state.alerts) { showAlerts(); } }, 150);
  });

  /** Load the latest alerts; open the card on first load and whenever something new appears. */
  function loadAlerts() {
    fetch('data/alerts.json?t=' + Date.now())
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
      .then(function (data) {
        state.alerts = data;
        refreshUpstream(); // Predictions covering the open gauge.
        renderAreas();     // And any saved area.
        // What counts as new: a warning issue, or a gauge alert at a level not seen before.
        var keys = (data.warnings || []).map(function (w) { return 'w:' + w.id + ':' + w.issued; })
          .concat((data.alerts || []).filter(function (a) { return !isPrediction(a); })
            .map(function (a) { return 'a:' + a.id + ':' + a.level; }));
        var seen = memGet('floodwatcher.alertsSeen') || [];
        seen.forEach(function (k) { state.alertSeen[k] = true; });
        var fresh = keys.some(function (k) { return !state.alertSeen[k]; });
        keys.forEach(function (k) { state.alertSeen[k] = true; });
        memSet('floodwatcher.alertsSeen', keys); // Only the current ones: the list never grows.
        showAlerts();
        var v = alertsInView();
        if (fresh && v.warnings.length + v.alerts.length > 0) { flashAlerts(); }
      })
      .catch(function () { /* No alerts file yet: nothing to show. */ });
  }

  // ---------- Push notifications ----------

  var push = { reg: null, sub: null, key: null };

  /** Turn the server's base64url public key into the bytes the browser wants. */
  function keyBytes(b64) {
    var s = (b64 + '='.repeat((4 - b64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    var raw = atob(s);
    var out = new Uint8Array(raw.length);
    for (var i = 0; i < raw.length; i++) { out[i] = raw.charCodeAt(i); }
    return out;
  }

  /** POST to the sign-up endpoint; resolves with the JSON reply or rejects with its message. */
  function pushCall(body) {
    return fetch('push.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (j) {
        if (!r.ok) { throw new Error(j.error || 'The server said no (' + r.status + ').'); }
        return j;
      });
    });
  }

  /** Send this browser's current choices (watch list, BoM warnings) to the server. */
  function pushSync() {
    if (!push.sub) { return Promise.resolve(); }
    return pushCall({
      action: 'subscribe',
      subscription: push.sub.toJSON(),
      watch: watchList().concat(state.watchFallback || []),
      areas: areaList(),
      warnings: document.getElementById('notify-warnings').checked,
      warn_catchment: warnCatchment(),
      predictive: document.getElementById('notify-predictive').checked
    });
  }

  /**
   * The catchment "All BoM flood warnings" is for: the one on screen when the box was ticked
   * (remembered in this browser), or the one on screen now if it's live.
   */
  function warnCatchment() {
    var saved = (memGet(NOTIFY_KEY) || {}).warnCatchment;
    if (saved && catchmentLive(saved)) { return saved; }
    return catchmentLive(state.catchment) ? state.catchment : 'brisbane';
  }

  /** Show which catchment the warnings choice is for. */
  function showWarnCatchment() {
    document.getElementById('notify-warnings-name').textContent = catchmentName(warnCatchment());
  }

  /** Show the right buttons and wording for this browser's state. */
  function pushShow(message) {
    var on = !!push.sub;
    document.getElementById('notify-on').hidden = on;
    document.getElementById('notify-test').hidden = !on;
    document.getElementById('notify-off').hidden = !on;
    var n = watchList().concat(state.watchFallback || []).length;
    var na = areaList().length;
    var what = [];
    if (document.getElementById('notify-warnings').checked) {
      what.push('all BoM flood warnings for the ' + catchmentName(warnCatchment()) + ' catchment');
    }
    if (document.getElementById('notify-predictive').checked) { what.push('predictive flood alerts'); }
    if (na) { what.push(na + ' area' + (na === 1 ? '' : 's')); }
    if (n) { what.push(n + ' watched gauge' + (n === 1 ? '' : 's')); }
    var status = message || (on
      ? 'On for this device: ' + (what.length ? what.join(', ') : 'nothing chosen yet') + '.'
      : 'Off. Get BoM flood warnings and alerts for your areas and watched gauges, even with this page closed.');
    document.getElementById('notify-status').textContent = status;
  }

  /** Work out whether this browser can do push, and if it's already signed up. */
  function pushInit() {
    var section = document.getElementById('notify');
    var ios = /iPad|iPhone|iPod/.test(navigator.userAgent) ||
      (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var standalone = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches;
    if (!('serviceWorker' in navigator) || !('PushManager' in window) || !window.isSecureContext) {
      section.hidden = false;
      document.getElementById('notify-on').hidden = true;
      document.getElementById('notify-status').textContent = ios && !standalone
        ? 'On iPhone or iPad: tap Share, then "Add to Home Screen", and open Flood Watcher from there to turn on notifications.'
        : 'This browser can\'t receive notifications from websites.';
      return;
    }
    section.hidden = false;
    navigator.serviceWorker.register('sw.js', { scope: './' }).then(function (reg) {
      push.reg = reg;
      return reg.pushManager.getSubscription();
    }).then(function (sub) {
      push.sub = sub;
      if (sub) { pushSync().catch(function () { /* Shown again on the next change. */ }); }
      pushShow();
    }).catch(function () {
      pushShow('Notifications couldn\'t start in this browser.');
    });
  }

  document.getElementById('notify-on').addEventListener('click', function () {
    if (!push.reg) { return; }
    pushShow('Asking your browser…');
    Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') { throw new Error('Notifications are blocked for this site. Allow them in your browser\'s site settings.'); }
      return fetch('push.php').then(function (r) { return r.json(); });
    }).then(function (j) {
      if (!j.publicKey) { throw new Error(j.error || 'Notifications aren\'t available on this site right now.'); }
      return push.reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(j.publicKey) });
    }).then(function (sub) {
      push.sub = sub;
      return pushSync();
    }).then(function () { pushShow(); }).catch(function (e) { pushShow(e.message); });
  });

  document.getElementById('notify-off').addEventListener('click', function () {
    if (!push.sub) { return; }
    var sub = push.sub;
    pushCall({ action: 'unsubscribe', endpoint: sub.endpoint }).catch(function () { /* Unsubscribing below is enough. */ })
      .then(function () { return sub.unsubscribe(); })
      .then(function () { push.sub = null; pushShow(); });
  });

  document.getElementById('notify-test').addEventListener('click', function () {
    if (!push.sub) { return; }
    pushShow('Sending a test…');
    pushCall({ action: 'test', endpoint: push.sub.endpoint })
      .then(function () { pushShow('Test sent. It should arrive within a minute.'); })
      .catch(function (e) { pushShow(e.message); });
  });

  // Remember the two notification choices in this browser, so a reload doesn't reset them.
  var NOTIFY_KEY = 'floodwatcher.notify';
  try {
    var saved = JSON.parse(window.localStorage.getItem(NOTIFY_KEY) || '{}');
    if (typeof saved.warnings === 'boolean') { document.getElementById('notify-warnings').checked = saved.warnings; }
    if (typeof saved.predictive === 'boolean') { document.getElementById('notify-predictive').checked = saved.predictive; }
  } catch (e) {
    /* Storage blocked: the boxes start ticked. */
  }
  ['notify-warnings', 'notify-predictive'].forEach(function (id) {
    document.getElementById(id).addEventListener('change', function () {
      var warnings = document.getElementById('notify-warnings').checked;
      // Ticking "All BoM flood warnings" makes it for the catchment on screen (if it's live).
      var wc = warnCatchment();
      if (id === 'notify-warnings' && warnings && catchmentLive(state.catchment)) { wc = state.catchment; }
      memSet(NOTIFY_KEY, {
        warnings: warnings,
        predictive: document.getElementById('notify-predictive').checked,
        warnCatchment: wc
      });
      showWarnCatchment();
      pushSync().then(function () { pushShow(); }).catch(function (e) { pushShow(e.message); });
    });
  });

  // ---------- Saved areas (for notifications; saved in this browser and with the sign-up) ----------

  var AREA_KEY = 'floodwatcher.areas';
  var MAX_AREAS = 5;

  /** Saved areas: [{name, s, w, n, e}]. Browser storage can be blocked, so failures mean "none". */
  function areaList() {
    if (state.areaFallback) { return state.areaFallback; }
    try {
      var list = JSON.parse(window.localStorage.getItem(AREA_KEY) || '[]');
      return Array.isArray(list) ? list.filter(function (a) {
        return a && typeof a.name === 'string' && [a.s, a.w, a.n, a.e].every(function (v) { return typeof v === 'number'; });
      }) : [];
    } catch (e) {
      return [];
    }
  }

  /** Save the areas, redraw them, and update the sign-up if notifications are on. */
  function saveAreas(list) {
    state.areaFallback = null;
    try {
      window.localStorage.setItem(AREA_KEY, JSON.stringify(list));
    } catch (e) {
      state.areaFallback = list; // Storage blocked: keep them until the page closes.
    }
    renderAreas();
    if (push.sub) { pushSync().then(function () { pushShow(); }).catch(function (e) { pushShow(e.message); }); }
  }

  /** A short place name for the current view: the selected gauge's place, or the nearest gauge's. */
  function suggestAreaName() {
    var pick = state.selected && state.data && state.data.sensors.find(function (x) { return x.id === state.selected; });
    if (!pick && state.data) {
      var c = map.getCenter();
      var best = Infinity;
      state.data.sensors.forEach(function (s) {
        var d = c.distanceTo([s.lat, s.lon]);
        if (d < best) { best = d; pick = s; }
      });
    }
    if (!pick) { return ''; }
    var at = pick.name.split(/\s+at\s+/i);
    return (at[1] || at[0]).replace(/\s*\(.*\)\s*$/, '').slice(0, 40);
  }

  /** Show the saved areas in the panel and as outlines on the map. */
  function renderAreas() {
    var list = areaList();
    var ul = document.getElementById('area-list');
    ul.replaceChildren();
    state.areaLayer.clearLayers();
    list.forEach(function (a, i) {
      var bounds = L.latLngBounds([a.s, a.w], [a.n, a.e]);
      var rect = L.rectangle(bounds, {
        color: cssVar('--accent'), weight: 1.5, dashArray: '4 4', fill: false, interactive: false
      });
      state.areaLayer.addLayer(rect);
      // The name sits just inside the top-left corner, away from the gauges in the middle.
      var label = el('span', 'area-label');
      label.textContent = a.name;
      state.areaLayer.addLayer(L.marker([a.n, a.w], {
        icon: L.divIcon({ className: '', html: label.outerHTML, iconSize: [0, 0] }),
        interactive: false,
        keyboard: false
      }));

      var li = el('li', 'area-item');
      li.appendChild(el('span', 'area-name', a.name));
      if (state.data) {
        li.appendChild(scoreBlock(placeScore((a.s + a.n) / 2, (a.w + a.e) / 2, [a.s, a.w, a.n, a.e]), true));
      }
      var show = el('button', 'area-btn', 'Show');
      show.type = 'button';
      show.addEventListener('click', function () { map.fitBounds(bounds); });
      var del = el('button', 'area-btn', 'Remove');
      del.type = 'button';
      del.setAttribute('aria-label', 'Remove the area ' + a.name);
      del.addEventListener('click', function () {
        var next = areaList().slice();
        next.splice(i, 1);
        saveAreas(next);
      });
      li.appendChild(show);
      li.appendChild(del);
      ul.appendChild(li);
    });
    if (!list.length) { ul.appendChild(el('li', 'small', 'None yet.')); }
    document.getElementById('area-form').hidden = list.length >= MAX_AREAS;
  }

  document.getElementById('area-name').addEventListener('focus', function (e) {
    if (!e.target.value) { e.target.value = suggestAreaName(); e.target.select(); }
  });

  document.getElementById('area-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var input = document.getElementById('area-name');
    var name = input.value.replace(/[<>\u0000-\u001f]/g, '').trim().slice(0, 40) || suggestAreaName() || 'My area';
    var b = map.getBounds();
    var round = function (v) { return Math.round(v * 10000) / 10000; };
    var list = areaList().slice();
    if (list.length >= MAX_AREAS) { return; }
    list.push({ name: name, s: round(b.getSouth()), w: round(b.getWest()), n: round(b.getNorth()), e: round(b.getEast()) });
    input.value = '';
    saveAreas(list);
    if (!push.sub) {
      pushShow('Area saved. Turn on notifications below to be told about it.');
    }
  });

  // ---------- Community rain (Weather Underground; looked up for the map view) ----------

  var community = { stations: [], loaded: false, timer: null, moveTimer: null, busy: false };

  /** Build the popup for a community station. */
  function communityPopup(st) {
    var box = el('div', 'road-popup');
    box.appendChild(el('strong', null, 'Community gauge ' + st.id));
    if (st.today !== null && st.today !== undefined) {
      box.appendChild(el('div', null, 'Today since midnight: ' + fmtMm(st.today) + ' mm' +
        (st.rate ? ' (raining at ' + fmtMm(st.rate) + ' mm an hour)' : '') + ', as at ' + fmtHM(st.at) + '.'));
    } else {
      box.appendChild(el('div', null, 'No rain figure from this station in the last two hours.'));
    }
    if (st.note) { box.appendChild(el('div', 'road-closed', st.note)); }
    box.appendChild(el('div', 'small', 'Run by a member of the public, via Weather Underground. Unofficial and unchecked: ' +
      'home gauges can read low or high. Position shown to within about 1 km.'));
    if (/^[A-Z0-9]{3,20}$/.test(st.id)) {
      var a = el('a', null, 'Station page on Weather Underground');
      a.href = 'https://www.wunderground.com/dashboard/pws/' + st.id;
      a.target = '_blank';
      a.rel = 'noopener';
      box.appendChild(a);
    }
    return box;
  }

  /** Draw every working station (small dashed dots), plus rain boxes for those looked up. */
  function drawCommunity(found) {
    state.communityLayer.clearLayers();
    var byId = {};
    (found || []).forEach(function (st) { byId[st.id] = st; });
    community.stations.forEach(function (st) {
      if (!inCatchment(st)) { return; }
      var got = byId[st.id];
      var cls;
      var label = '';
      if (got && got.today !== null && got.today !== undefined) {
        cls = got.note ? 'rain-icon suspect community' :
          got.today <= 0 ? 'rain-dot community' : 'rain-icon community ' + rainClass(got.today, 'since_9am');
        label = got.note ? '?' : got.today > 0 ? String(Math.round(got.today)) : '';
      } else {
        cls = 'community-dot';
      }
      var m = L.marker([st.lat, st.lon], {
        icon: L.divIcon({ className: '', html: '<span class="' + cls + '">' + label + '</span>', iconSize: [0, 0] }),
        keyboard: !!got
      });
      m.bindTooltip(got && got.today !== null && got.today !== undefined
        ? 'Community gauge, ' + fmtMm(got.today) + ' mm today (unofficial)'
        : 'Community gauge: zoom in to see its rain');
      m.bindPopup(function () { return communityPopup(got || { id: st.id, today: null }); });
      state.communityLayer.addLayer(m);
    });
  }

  /** Ask the server for the rain at the stations in view. */
  function fetchCommunity() {
    var note = document.getElementById('community-note');
    if (!map.hasLayer(state.communityLayer) || community.busy) { return; }
    var b = map.getBounds();
    var inView = community.stations.filter(function (st) { return inCatchment(st) && b.contains([st.lat, st.lon]); }).length;
    if (!inView) {
      drawCommunity([]);
      note.textContent = 'No working community gauges in this part of the map' +
        (catchmentLive(state.catchment) ? '.' : ' (none are looked up for catchments under construction).');
      return;
    }
    community.busy = true;
    note.textContent = 'Loading community rain…';
    var q = ['s=' + b.getSouth().toFixed(4), 'w=' + b.getWest().toFixed(4), 'n=' + b.getNorth().toFixed(4), 'e=' + b.getEast().toFixed(4)];
    fetch('wu.php?' + q.join('&'))
      .then(function (r) { return r.json().then(function (j) { if (!r.ok) { throw new Error(j.error || r.status); } return j; }); })
      .then(function (j) {
        drawCommunity(j.stations);
        note.textContent = 'Community rain today (since midnight): showing ' + j.shown + ' of ' + j.in_view +
          ' gauges in view' + (j.shown < j.in_view ? '; zoom in to see more.' : '.');
      })
      .catch(function (e) { note.textContent = 'Community rain isn\'t available just now (' + e.message + ').'; })
      .then(function () { community.busy = false; });
  }

  /** Turn the layer on: load the station list once, then look up the view now and every 15 minutes. */
  function startCommunity() {
    var go = function () {
      fetchCommunity();
      clearInterval(community.timer);
      community.timer = setInterval(fetchCommunity, 15 * 60 * 1000);
    };
    if (community.loaded) { go(); return; }
    fetch('data/community.json?t=' + Date.now())
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
      .then(function (j) {
        community.stations = (j.stations || []).filter(function (s) { return /^[A-Z0-9]{3,20}$/.test(s.id); });
        community.loaded = true;
        drawCommunity([]);
        go();
      })
      .catch(function () {
        document.getElementById('community-note').textContent = 'Community gauges aren\'t set up on this site yet.';
      });
  }

  map.on('moveend', function () {
    if (!map.hasLayer(state.communityLayer)) { return; }
    clearTimeout(community.moveTimer);
    community.moveTimer = setTimeout(fetchCommunity, 700); // Wait for the map to settle.
  });

  // ---------- Reset ----------

  /** Put everything back to the starting view: whole catchment, nothing selected, no search. */
  function resetView() {
    map.closePopup();
    hideInfo();
    if (state.searchPin) { map.removeLayer(state.searchPin); state.searchPin = null; }
    document.getElementById('search-input').value = '';
    document.getElementById('search-results').hidden = true;
    document.getElementById('search-status').hidden = true;
    state.selected = null;
    history.replaceState(null, '', location.pathname + location.search);
    clearDetail();
    fitCatchment();
  }

  /** Empty the gauge details panel. */
  function clearDetail() {
    state.selected = null;
    var detail = document.getElementById('detail');
    detail.textContent = '';
    if (catchmentLive(state.catchment) || !state.catchments.length) {
      detail.appendChild(el('p', 'hint', 'Choose a gauge on the map to see its readings and last 3 days.'));
      return;
    }
    // A catchment under construction: say so, and offer the live ones.
    detail.appendChild(el('p', 'hint', 'The ' + catchmentName(state.catchment) + ' catchment is under construction: ' +
      'no gauges are shown here yet. Catchments with gauges:'));
    state.catchments.filter(function (c) { return c.status !== 'construction'; }).forEach(function (c) {
      var b = el('button', 'nearlink', c.name);
      b.type = 'button';
      b.addEventListener('click', function () { setCatchment(c.key); });
      detail.appendChild(b);
    });
  }

  /** Zoom to the whole catchment on screen (its box, or its gauges until the box is known). */
  function fitCatchment() {
    var c = catchmentInfo(state.catchment);
    if (c && c.bbox) {
      map.fitBounds([[c.bbox[0], c.bbox[1]], [c.bbox[2], c.bbox[3]]], { padding: [12, 12] });
      return;
    }
    var pts = state.data ? state.data.sensors.filter(inCatchment).map(function (s) { return [s.lat, s.lon]; }) : [];
    if (pts.length) { map.fitBounds(pts, { padding: [20, 20] }); }
  }

  // The catchment outline: a dashed line, drawn below the gauges and never catching clicks.
  state.outlineLayer = L.layerGroup().addTo(map);

  /** Draw the outline of the catchment on screen (loaded once per catchment). */
  function drawOutline() {
    state.outlineLayer.clearLayers();
    var key = state.catchment;
    if (!/^[a-z0-9_]+$/.test(key || '')) { return; }
    var show = function () {
      if (state.catchment === key && state.outlines[key]) { state.outlineLayer.addLayer(state.outlines[key]); }
    };
    if (state.outlines[key]) { show(); return; }
    fetch('data/catchments/' + key + '.json?t=' + (state.catchmentsGenerated || 0))
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
      .then(function (data) {
        var colour = cssVar('--ov-catchment') || cssVar('--ov-default');
        var group = L.layerGroup();
        (data.f || []).forEach(function (f) {
          group.addLayer(L.polygon(f.r.map(decodeRing), {
            renderer: floodRenderer, interactive: false, color: colour, weight: 2.5, opacity: 0.9,
            dashArray: '10 6', fill: false
          }));
        });
        state.outlines[key] = group;
        show();
      })
      .catch(function () { /* No outline yet (not downloaded): the gauges still show. */ });
  }

  /** The catchment chooser in the header: live catchments first, then those under construction. */
  function fillCatchmentSelect() {
    var sel = document.getElementById('catchment-select');
    sel.replaceChildren();
    var live = state.catchments.filter(function (c) { return c.status !== 'construction'; });
    var later = state.catchments.filter(function (c) { return c.status === 'construction'; });
    var add = function (parent, c) {
      var o = el('option', null, c.name);
      o.value = c.key;
      parent.appendChild(o);
    };
    if (later.length) {
      var g1 = el('optgroup');
      g1.label = 'With gauges';
      live.forEach(function (c) { add(g1, c); });
      sel.appendChild(g1);
      var g2 = el('optgroup');
      g2.label = 'Under construction';
      later.forEach(function (c) { add(g2, c); });
      sel.appendChild(g2);
    } else {
      live.forEach(function (c) { add(sel, c); });
    }
    sel.value = state.catchment;
  }

  /** Bring the page's wording, the notices and the catchment's outline in line with the catchment on screen. */
  function showCatchmentChrome() {
    var name = catchmentName(state.catchment);
    var live = catchmentLive(state.catchment) || !state.catchments.length;
    document.title = 'Flood Watcher — ' + name + ' catchment';
    var sel = document.getElementById('catchment-select');
    if (sel.value !== state.catchment) { sel.value = state.catchment; }
    document.querySelectorAll('[data-catchment]').forEach(function (n) {
      var want = n.getAttribute('data-catchment');
      n.hidden = want === 'other' ? state.catchment === 'brisbane' : want !== state.catchment;
    });
    var note = document.getElementById('construction-note');
    note.hidden = live;
    note.textContent = live ? '' : 'Under construction: no gauges for the ' + name + ' catchment yet, just its outline. ' +
      'Choose one "with gauges" at the top for live readings.';
    showWarnCatchment();
    drawOutline();
  }

  /**
   * Show a catchment: its gauges, dams, roads, cameras, flood maps, predictions and alerts, and
   * its outline. The choice goes in the address (?c=mary) and is remembered in this browser.
   *
   * @param {string} key
   * @param {{keepView: boolean, initial: boolean}} [opts] keepView: don't zoom to the catchment.
   */
  function setCatchment(key, opts) {
    opts = opts || {};
    if (state.catchments.length && !catchmentInfo(key)) { key = (catchmentInfo('brisbane') || state.catchments[0]).key; }
    var changed = key !== state.catchment;
    state.catchment = key;
    memSet(CATCHMENT_KEY, key);
    var params = new URLSearchParams(location.search);
    if (params.get('c') !== key) {
      params.set('c', key);
      history.replaceState(null, '', location.pathname + '?' + params.toString() + location.hash);
    }
    showCatchmentChrome();
    if (!changed && !opts.initial) { return; }
    var open = state.selected && state.data && state.data.sensors.find(function (x) { return x.id === state.selected; });
    if (!open || !inCatchment(open)) {
      if (state.selected) { history.replaceState(null, '', location.pathname + location.search); }
      clearDetail();
    }
    map.closePopup();
    if (state.data) {
      drawMarkers();
      drawDams();
      drawWatchRings();
    }
    if (state.trafficData) { drawTraffic(state.trafficData); }
    drawCameras();
    if (community.loaded) { drawCommunity([]); fetchCommunity(); }
    applyOverlayCatchment();
    state.predictDrawn = null;
    if (state.alerts) { showAlerts(); } else { showPredictions(); }
    renderAreas();
    if (!opts.keepView) { fitCatchment(); }
  }

  /** The catchment to start with: the address (?c=), then this browser's last choice, then the default. */
  function startingCatchment() {
    var asked = new URLSearchParams(location.search).get('c');
    if (asked && /^[a-z0-9_]{1,32}$/.test(asked)) { return asked; }
    var saved = memGet(CATCHMENT_KEY);
    return typeof saved === 'string' && /^[a-z0-9_]{1,32}$/.test(saved) ? saved : 'brisbane';
  }

  /** Load the list of catchments (all of Queensland), falling back to the live ones in the gauge data. */
  function loadCatchments() {
    return fetch('data/catchments.json?t=' + Date.now())
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
      .then(function (j) {
        state.catchmentsGenerated = j.generated;
        state.catchments = (j.catchments || []).filter(function (c) { return /^[a-z0-9_]{1,32}$/.test(c.key); });
      })
      .catch(function () {
        state.catchments = ((state.data && state.data.catchments) || []).map(function (c) {
          return { key: c.key, name: c.name, status: 'live', bbox: c.bbox };
        });
      })
      .then(function () {
        if (!state.catchments.length) {
          state.catchments = [{ key: 'brisbane', name: 'Brisbane River', status: 'live', bbox: null }];
        }
        var wanted = state.catchment;
        fillCatchmentSelect();
        setCatchment(catchmentInfo(wanted) ? wanted : (catchmentInfo('brisbane') || state.catchments[0]).key,
          { keepView: !!location.hash, initial: true });
      });
  }

  document.getElementById('catchment-select').addEventListener('change', function (e) {
    setCatchment(e.target.value);
  });

  // A "Reset" button on the map, under the zoom buttons (alone on phones, where those are hidden).
  var ResetControl = L.Control.extend({
    options: { position: 'topleft' },
    onAdd: function () {
      var btn = L.DomUtil.create('button', 'reset-control');
      btn.type = 'button';
      btn.textContent = '↺ Reset';
      btn.title = 'Show the whole catchment and clear the selection and search';
      L.DomEvent.disableClickPropagation(btn);
      L.DomEvent.on(btn, 'click', resetView);
      return btn;
    }
  });
  map.addControl(new ResetControl());

  // ---------- Freshness and loading ----------

  /** Update the header time and the out-of-date warnings. */
  function showFreshness() {
    var d = state.data;
    document.getElementById('freshness').textContent =
      'Latest data ' + fmtTime(d.data_time) + ' (' + ago(d.data_time) + ')';
    var alertBox = document.getElementById('stale-alert');
    var ageMin = (Date.now() / 1000 - d.data_time) / 60;
    var problems = [];
    if (ageMin > STALE_MINUTES) {
      problems.push('Data may be out of date: the newest reading is ' + ago(d.data_time) + '.');
    }
    var runs = d.runs || (d.last_run ? { bcc: d.last_run } : {});
    Object.keys(runs).forEach(function (k) {
      var r = runs[k];
      if (r.status === 'error') {
        problems.push('Collecting ' + (r.name || 'data') + ' data failed at ' + fmtTime(r.time) + '.');
      }
    });
    alertBox.textContent = problems.join(' ');
    alertBox.hidden = problems.length === 0;

    var th = d.thresholds;
    document.getElementById('threshold-text').textContent =
      'Rising means more than ' + th.rising_m_per_hr + ' m per hour; rising fast means more than ' +
      th.fast_m_per_hr + ' m per hour.';
  }

  /** Fetch the latest data file and redraw. */
  function load() {
    return fetch('data/sensors.json?t=' + Date.now())
      .then(function (r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
      .then(function (data) {
        state.data = data;
        drawMarkers();
        drawDams();
        drawWatchRings();
        showFreshness();
        if (state.alerts) { showAlerts(); } // Gauge positions are known now.
        renderAreas(); // Their scores use the latest readings.
        var wanted = state.selected || location.hash.slice(1);
        if (wanted) {
          var s = data.sensors.find(function (x) { return x.id === wanted; });
          // A link to a gauge in another catchment opens that catchment.
          if (s && !inCatchment(s)) { setCatchment(catchmentOf(s), { keepView: true }); }
          select(wanted);
          if (s && !state.centred) { state.centred = true; map.setView([s.lat, s.lon], 13); }
        }
      })
      .catch(function () {
        var alertBox = document.getElementById('stale-alert');
        alertBox.textContent = 'Could not load gauge data. Please try again shortly.';
        alertBox.hidden = false;
      });
  }

  // ---------- Side panel (search, gauge details, options): opened and closed by its tab ----------

  var PANEL_KEY = 'floodwatcher.panelOpen';
  var panelTab = document.getElementById('panel-tab');

  /** Open or close the side panel; remembered in this browser. */
  function setPanel(open) {
    document.body.classList.toggle('panel-open', open);
    panelTab.setAttribute('aria-expanded', open ? 'true' : 'false');
    panelTab.title = open ? 'Hide the panel' : 'Show search, gauge details and map options';
    if (open) { document.getElementById('panel-tab-dot').hidden = true; }
    memSet(PANEL_KEY, open);
  }

  /** Something new in the panel while it's closed: a dot on the tab says so. */
  function panelNudge() {
    if (!document.body.classList.contains('panel-open')) { document.getElementById('panel-tab-dot').hidden = false; }
  }

  panelTab.addEventListener('click', function () { setPanel(!document.body.classList.contains('panel-open')); });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && document.body.classList.contains('panel-open')) {
      setPanel(false);
      panelTab.focus();
    }
  });
  setPanel(memGet(PANEL_KEY) === true);

  // ---------- Controls ----------

  document.getElementById('show-stream').addEventListener('change', function (e) {
    if (e.target.checked) { map.addLayer(state.streamLayer); } else { map.removeLayer(state.streamLayer); }
  });
  document.getElementById('show-rain').addEventListener('change', function (e) {
    if (e.target.checked) { map.addLayer(state.rainLayer); } else { map.removeLayer(state.rainLayer); }
  });
  document.getElementById('rain-period').addEventListener('change', function (e) {
    state.rainKey = e.target.value;
    if (state.data) {
      drawMarkers();
      if (state.selected) { select(state.selected); }
    }
  });

  document.getElementById('show-community').addEventListener('change', function (e) {
    var note = document.getElementById('community-note');
    if (e.target.checked) {
      map.addLayer(state.communityLayer);
      note.hidden = false;
      startCommunity();
    } else {
      map.removeLayer(state.communityLayer);
      note.hidden = true;
      clearInterval(community.timer);
    }
  });
  document.getElementById('show-roads').addEventListener('change', function (e) {
    if (e.target.checked) { map.addLayer(state.trafficLayer); } else { map.removeLayer(state.trafficLayer); }
  });
  // Predictive flood alerts on the map (remembered in this browser).
  var predictBox = document.getElementById('show-predictions');
  predictBox.checked = predictOn();
  predictBox.addEventListener('change', function (e) { setPredictOn(e.target.checked); });

  document.getElementById('show-dams').addEventListener('change', function (e) {
    if (e.target.checked) { map.addLayer(state.damLayer); } else { map.removeLayer(state.damLayer); }
  });

  document.getElementById('show-cameras').addEventListener('change', function (e) {
    if (e.target.checked) { map.addLayer(state.cameraLayer); } else { map.removeLayer(state.cameraLayer); }
  });

  // Start with the catchment from the address or this browser, before the data arrives.
  state.catchment = startingCatchment();
  state.alertScope = memGet('floodwatcher.alertScope') === 'all' ? 'all' : 'here';
  showCatchmentChrome();
  load().then(loadCatchments);
  loadAlerts();
  pushInit();
  renderAreas();
  loadTraffic();
  loadCameras();
  loadOverlayIndex();
  setInterval(load, REFRESH_MS);
  setInterval(loadTraffic, REFRESH_MS);
  setInterval(loadAlerts, REFRESH_MS);
  setInterval(function () { if (state.data) { showFreshness(); } }, 60 * 1000);
}());
