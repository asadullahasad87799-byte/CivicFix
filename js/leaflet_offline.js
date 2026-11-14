/**
 * CivicFix v3.0 — Dual-Engine Procedural GIS Tile Generator & Offline Leaflet Fallback (js/leaflet_offline.js)
 *
 * 1. Exports window.CivicFixTileGenerator:
 *    Generates crisp 256x256 cartographic PNG data-URLs and full-viewport HTML5 Canvas maps of Ludhiana
 *    (30.9010° N, 75.8573° E) in 'street', 'satellite', 'radar', and 'offline' styles.
 *    Used both as an instant underlay/fallback for real Leaflet 1.9.4 tiles AND for Offline Blackout Mode.
 * 2. If real Leaflet (window.L) failed to load from CDN (e.g., laptop has zero internet at page boot),
 *    provides a complete, interactive pan/zoom/marker/circle/polygon Leaflet-compatible GIS engine
 *    so the map NEVER shows a blank grey box under any network condition.
 */

(function () {
  'use strict';

  var LUDHIANA_LANDMARKS = [
    { name: 'Clock Tower (Ghanta Ghar)', lat: 30.9136, lng: 75.8534, zone: 'Zone A', type: 'hub' },
    { name: 'Ludhiana Junction Rly Stn', lat: 30.9118, lng: 75.8475, zone: 'Zone A', type: 'transit' },
    { name: 'Samrala Chowk Flyover',     lat: 30.9096, lng: 75.8902, zone: 'Zone A', type: 'junction' },
    { name: 'Civil Lines & Rakh Bagh',   lat: 30.9062, lng: 75.8390, zone: 'Zone D', type: 'park' },
    { name: 'PAU Campus Gate No. 2',     lat: 30.9018, lng: 75.8095, zone: 'Zone D', type: 'campus' },
    { name: 'Ferozepur Rd Mini Sectt.',  lat: 30.8992, lng: 75.8265, zone: 'Zone D', type: 'gov' },
    { name: 'Sarabha Nagar Main Mkt',    lat: 30.8915, lng: 75.8242, zone: 'Zone D', type: 'market' },
    { name: 'Pakhowal Canal Bridge',     lat: 30.8965, lng: 75.8458, zone: 'Zone D', type: 'bridge' },
    { name: 'Model Town Gol Market',     lat: 30.8884, lng: 75.8432, zone: 'Zone D', type: 'market' },
    { name: 'Bharat Nagar Chowk',        lat: 30.9024, lng: 75.8438, zone: 'Zone D', type: 'junction' },
    { name: 'Gill Road ITI Chowk',       lat: 30.8825, lng: 75.8622, zone: 'Zone C', type: 'junction' },
    { name: 'Dugri Phase-2 Urban Est.',  lat: 30.8715, lng: 75.8440, zone: 'Zone C', type: 'residential' },
    { name: 'Focal Point Phase-V',       lat: 30.8870, lng: 75.9015, zone: 'Zone B', type: 'industrial' },
    { name: 'Sherpur Chowk NH-44',       lat: 30.8928, lng: 75.8845, zone: 'Zone B', type: 'junction' },
    { name: 'Haibowal Kalan',            lat: 30.9225, lng: 75.8240, zone: 'Zone A', type: 'residential' }
  ];

  function createProceduralTile(x, y, z, styleMode) {
    var canvas = document.createElement('canvas');
    canvas.width = 256;
    canvas.height = 256;
    var ctx = canvas.getContext('2d');
    var mode = styleMode || 'street';

    // Base fill
    if (mode === 'satellite') {
      var gradSat = ctx.createLinearGradient(0, 0, 256, 256);
      gradSat.addColorStop(0, '#102a20');
      gradSat.addColorStop(0.5, '#16382b');
      gradSat.addColorStop(1, '#0d231b');
      ctx.fillStyle = gradSat;
    } else if (mode === 'radar') {
      var gradRad = ctx.createLinearGradient(0, 0, 256, 256);
      gradRad.addColorStop(0, '#071022');
      gradRad.addColorStop(1, '#0c1b33');
      ctx.fillStyle = gradRad;
    } else {
      var gradSt = ctx.createLinearGradient(0, 0, 256, 256);
      gradSt.addColorStop(0, '#f3f6f1');
      gradSt.addColorStop(0.5, '#eef2eb');
      gradSt.addColorStop(1, '#e8efe5');
      ctx.fillStyle = gradSt;
    }
    ctx.fillRect(0, 0, 256, 256);

    // Deterministic pseudo-random seed per tile
    var seed = ((x * 73856093) ^ (y * 19349663) ^ (z * 83492791)) >>> 0;

    // Urban blocks / park patches
    for (var i = 0; i < 6; i++) {
      var bx = ((seed + i * 47) % 200) + 8;
      var by = ((seed + i * 89) % 200) + 8;
      var bw = 28 + ((seed + i * 19) % 45);
      var bh = 22 + ((seed + i * 31) % 38);
      if (mode === 'satellite') {
        ctx.fillStyle = (i % 2 === 0) ? 'rgba(34, 84, 58, 0.45)' : 'rgba(68, 64, 60, 0.35)';
      } else if (mode === 'radar') {
        ctx.fillStyle = (i % 2 === 0) ? 'rgba(14, 165, 233, 0.06)' : 'rgba(16, 185, 129, 0.05)';
      } else {
        ctx.fillStyle = (i % 3 === 0) ? '#dcfce7' : '#e2e8f0';
      }
      ctx.fillRect(bx, by, bw, bh);
    }

    // Sub-grid street lines
    ctx.lineWidth = 1;
    ctx.strokeStyle = mode === 'satellite'
      ? 'rgba(255,255,255,0.12)'
      : (mode === 'radar' ? 'rgba(56,189,248,0.16)' : '#cbd5e1');
    for (var g = 32; g < 256; g += 32) {
      ctx.beginPath();
      ctx.moveTo(g, 0);
      ctx.lineTo(g, 256);
      ctx.stroke();
      ctx.beginPath();
      ctx.moveTo(0, g);
      ctx.lineTo(256, g);
      ctx.stroke();
    }

    // Canal / Waterway ribbon
    ctx.save();
    ctx.beginPath();
    var wyStart = ((x * 53 + y * 29) % 180) + 30;
    ctx.moveTo(0, wyStart);
    ctx.bezierCurveTo(85, wyStart + 28, 170, wyStart - 24, 256, wyStart + 18);
    ctx.lineWidth = 7;
    ctx.strokeStyle = mode === 'satellite' ? '#0284c7' : (mode === 'radar' ? '#0ea5e9' : '#7dd3fc');
    ctx.stroke();
    ctx.restore();

    // Arterial Highway / NH-44 / Ferozepur Road corridors
    ctx.save();
    ctx.beginPath();
    var rx = ((x * 41) % 160) + 48;
    ctx.moveTo(rx, 0);
    ctx.lineTo(256 - rx / 2, 256);
    ctx.moveTo(0, ((y * 67) % 160) + 44);
    ctx.lineTo(256, 256 - ((y * 43) % 140) - 30);
    ctx.lineWidth = 4.5;
    ctx.strokeStyle = mode === 'satellite'
      ? 'rgba(250, 204, 21, 0.65)'
      : (mode === 'radar' ? 'rgba(56, 189, 248, 0.45)' : '#fcd34d');
    ctx.stroke();
    ctx.lineWidth = 2.2;
    ctx.strokeStyle = mode === 'satellite'
      ? '#fde047'
      : (mode === 'radar' ? '#38bdf8' : '#ffffff');
    ctx.stroke();
    ctx.restore();

    // Sector label & tile telemetry watermark
    var lm = LUDHIANA_LANDMARKS[(Math.abs(x + y * 3)) % LUDHIANA_LANDMARKS.length];
    ctx.font = '600 10px system-ui, -apple-system, sans-serif';
    ctx.fillStyle = mode === 'satellite'
      ? 'rgba(255,255,255,0.78)'
      : (mode === 'radar' ? 'rgba(125,211,252,0.8)' : '#475569');
    ctx.fillText(lm.name, 14, 22);

    ctx.font = '500 9px monospace';
    ctx.fillStyle = mode === 'satellite'
      ? 'rgba(203,213,225,0.55)'
      : (mode === 'radar' ? 'rgba(56,189,248,0.5)' : '#94a3b8');
    ctx.fillText('LDH-GIS z' + z + ' [' + x + ',' + y + '] ' + lm.zone, 14, 244);

    return canvas;
  }

  window.CivicFixTileGenerator = {
    landmarks: LUDHIANA_LANDMARKS,
    createTileCanvas: createProceduralTile,
    createTileDataURL: function (x, y, z, styleMode) {
      return createProceduralTile(x, y, z, styleMode).toDataURL('image/png');
    }
  };

  // If Real Leaflet 1.9.4 loaded from CDN, enhance it with a custom Procedural GridLayer and return!
  if (typeof window.L !== 'undefined' && typeof window.L.GridLayer !== 'undefined') {
    window.L.ProceduralLudhianaLayer = window.L.GridLayer.extend({
      initialize: function (options) {
        window.L.setOptions(this, options || {});
        this._styleMode = (options && options.styleMode) || 'street';
      },
      setStyleMode: function (mode) {
        this._styleMode = mode || 'street';
        this.redraw();
      },
      createTile: function (coords) {
        return createProceduralTile(coords.x, coords.y, coords.z, this._styleMode);
      }
    });
    window.L.proceduralLudhianaLayer = function (opts) {
      return new window.L.ProceduralLudhianaLayer(opts);
    };
    return;
  }

  // ============================================================================
  // SECONDARY ENGINE: Full Interactive HTML5 Canvas + SVG Offline GIS Engine
  // Activates automatically if laptop is offline at boot and CDN Leaflet is unreachable.
  // ============================================================================
  function FallbackMap(containerId, opts) {
    this.el = typeof containerId === 'string' ? document.getElementById(containerId) : containerId;
    this.center = { lat: 30.9010, lng: 75.8573 };
    this.zoom = (opts && opts.zoom) || 13;
    this.styleMode = 'street';
    this.layers = [];
    this.handlers = {};
    this._dragging = false;
    this._dragStart = null;

    if (!this.el) return;
    this.el.innerHTML = '';
    this.el.style.position = 'relative';
    this.el.style.overflow = 'hidden';
    this.el.style.userSelect = 'none';
    this.el.style.cursor = 'grab';

    this.canvas = document.createElement('canvas');
    this.canvas.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;display:block;z-index:1;';
    this.el.appendChild(this.canvas);

    this.overlayPane = document.createElement('div');
    this.overlayPane.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;z-index:5;pointer-events:none;';
    this.el.appendChild(this.overlayPane);

    this.popupPane = document.createElement('div');
    this.popupPane.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;z-index:15;pointer-events:none;';
    this.el.appendChild(this.popupPane);

    var self = this;
    var zoomCtrl = document.createElement('div');
    zoomCtrl.style.cssText = 'position:absolute;top:12px;left:12px;z-index:20;display:flex;flex-direction:column;border-radius:8px;overflow:hidden;box-shadow:0 4px 12px rgba(15,23,42,0.18);border:1px solid #cbd5e1;';
    var btnIn = document.createElement('button');
    btnIn.type = 'button';
    btnIn.textContent = '+';
    btnIn.style.cssText = 'width:32px;height:32px;background:#fff;border:none;border-bottom:1px solid #e2e8f0;font-size:18px;font-weight:700;cursor:pointer;color:#0f172a;';
    btnIn.onclick = function (e) { e.stopPropagation(); self.setZoom(Math.min(18, self.zoom + 1)); };

    var btnOut = document.createElement('button');
    btnOut.type = 'button';
    btnOut.textContent = '−';
    btnOut.style.cssText = 'width:32px;height:32px;background:#fff;border:none;font-size:18px;font-weight:700;cursor:pointer;color:#0f172a;';
    btnOut.onclick = function (e) { e.stopPropagation(); self.setZoom(Math.max(10, self.zoom - 1)); };
    zoomCtrl.appendChild(btnIn);
    zoomCtrl.appendChild(btnOut);
    this.el.appendChild(zoomCtrl);

    var attr = document.createElement('div');
    attr.style.cssText = 'position:absolute;bottom:4px;right:8px;z-index:20;background:rgba(255,255,255,0.88);padding:2px 8px;border-radius:4px;font-size:11px;color:#334155;font-weight:600;';
    attr.innerHTML = 'CivicFix v3.0 Hybrid GIS Engine &middot; Ludhiana Municipal Grid';
    this.el.appendChild(attr);

    this.el.addEventListener('mousedown', function (e) {
      if (e.target.tagName === 'BUTTON' || e.target.closest('.cf-popup') || e.target.closest('.cf-marker')) return;
      self._dragging = true;
      self._moved = false;
      self._dragStart = { x: e.clientX, y: e.clientY, lat: self.center.lat, lng: self.center.lng };
      self.el.style.cursor = 'grabbing';
    });

    window.addEventListener('mousemove', function (e) {
      if (!self._dragging || !self._dragStart) return;
      var dx = e.clientX - self._dragStart.x;
      var dy = e.clientY - self._dragStart.y;
      if (Math.abs(dx) > 4 || Math.abs(dy) > 4) self._moved = true;
      var degPerPx = 0.08 / (Math.max(300, self.el.clientWidth) * Math.pow(1.35, self.zoom - 13));
      self.center.lng = self._dragStart.lng - dx * degPerPx;
      self.center.lat = self._dragStart.lat + dy * degPerPx;
      self._render();
    });

    window.addEventListener('mouseup', function (e) {
      if (!self._dragging) return;
      self._dragging = false;
      self.el.style.cursor = 'grab';
      if (!self._moved && self.handlers['click']) {
        var rect = self.el.getBoundingClientRect();
        var latlng = self._xyToLatLng(e.clientX - rect.left, e.clientY - rect.top);
        self.handlers['click'].forEach(function (fn) { fn({ latlng: latlng }); });
      }
    });

    setTimeout(function () { self._render(); }, 30);
  }

  FallbackMap.prototype._latLngToXY = function (lat, lng) {
    var w = this.el.clientWidth || 640;
    var h = this.el.clientHeight || 420;
    var scale = Math.pow(1.35, this.zoom - 13);
    var scaleX = (w / 0.085) * scale;
    var scaleY = (h / 0.060) * scale;
    return {
      x: (w / 2) + (lng - this.center.lng) * scaleX,
      y: (h / 2) - (lat - this.center.lat) * scaleY
    };
  };

  FallbackMap.prototype._xyToLatLng = function (x, y) {
    var w = this.el.clientWidth || 640;
    var h = this.el.clientHeight || 420;
    var scale = Math.pow(1.35, this.zoom - 13);
    var scaleX = (w / 0.085) * scale;
    var scaleY = (h / 0.060) * scale;
    return {
      lat: this.center.lat - (y - h / 2) / scaleY,
      lng: this.center.lng + (x - w / 2) / scaleX
    };
  };

  FallbackMap.prototype.setView = function (coords, zoom) {
    if (Array.isArray(coords)) {
      this.center = { lat: Number(coords[0]), lng: Number(coords[1]) };
    } else if (coords && coords.lat !== undefined) {
      this.center = { lat: Number(coords.lat), lng: Number(coords.lng) };
    }
    if (zoom) this.zoom = zoom;
    this._render();
    return this;
  };

  FallbackMap.prototype.flyTo = function (coords, zoom) {
    return this.setView(coords, zoom);
  };

  FallbackMap.prototype.setZoom = function (z) {
    this.zoom = z;
    this._render();
    return this;
  };

  FallbackMap.prototype.invalidateSize = function () {
    this._render();
    return this;
  };

  FallbackMap.prototype.on = function (evt, fn) {
    this.handlers[evt] = this.handlers[evt] || [];
    this.handlers[evt].push(fn);
    return this;
  };

  FallbackMap.prototype.removeLayer = function (layer) {
    this.layers = this.layers.filter(function (l) { return l !== layer; });
    this._render();
    return this;
  };

  FallbackMap.prototype._render = function () {
    if (!this.el) return;
    var w = this.el.clientWidth || 640;
    var h = this.el.clientHeight || 420;
    this.canvas.width = w;
    this.canvas.height = h;
    var ctx = this.canvas.getContext('2d');
    var mode = this.styleMode || 'street';

    // Draw tiled procedural background
    for (var tx = 0; tx < w; tx += 256) {
      for (var ty = 0; ty < h; ty += 256) {
        var tileCanvas = createProceduralTile(Math.floor(tx / 256) + 10, Math.floor(ty / 256) + 20, this.zoom, mode);
        ctx.drawImage(tileCanvas, tx, ty);
      }
    }

    // Draw landmarks
    var self = this;
    LUDHIANA_LANDMARKS.forEach(function (lm) {
      var pt = self._latLngToXY(lm.lat, lm.lng);
      if (pt.x < -40 || pt.x > w + 40 || pt.y < -20 || pt.y > h + 20) return;
      ctx.fillStyle = mode === 'satellite' || mode === 'radar' ? '#38bdf8' : '#0a3560';
      ctx.beginPath();
      ctx.arc(pt.x, pt.y, 3.5, 0, Math.PI * 2);
      ctx.fill();
      ctx.font = '600 11px system-ui, sans-serif';
      ctx.fillStyle = mode === 'satellite' || mode === 'radar' ? '#f8fafc' : '#1e293b';
      ctx.fillText(lm.name, pt.x + 6, pt.y + 4);
    });

    this.overlayPane.innerHTML = '';
    this.layers.forEach(function (layer) {
      if (layer && typeof layer._renderOnFallback === 'function') {
        layer._renderOnFallback(self, ctx);
      }
    });
  };

  function FallbackTileLayer(url, opts) {
    this.url = url || '';
    this.opts = opts || {};
  }
  FallbackTileLayer.prototype.addTo = function (map) {
    if (!map) return this;
    if (this.url.indexOf('Imagery') !== -1) map.styleMode = 'satellite';
    else if (this.url.indexOf('dark') !== -1) map.styleMode = 'radar';
    else map.styleMode = (this.opts && this.opts.styleMode) || 'street';
    map._render();
    return this;
  };
  FallbackTileLayer.prototype.on = function () { return this; };

  function FallbackMarker(latlng, opts) {
    this.latlng = Array.isArray(latlng) ? { lat: Number(latlng[0]), lng: Number(latlng[1]) } : latlng;
    this.opts = opts || {};
    this.popupHtml = '';
    this.handlers = {};
  }
  FallbackMarker.prototype.addTo = function (target) {
    if (target && target.layers) {
      target.layers.push(this);
      if (target._render) target._render();
    }
    return this;
  };
  FallbackMarker.prototype.setLatLng = function (ll) {
    this.latlng = Array.isArray(ll) ? { lat: Number(ll[0]), lng: Number(ll[1]) } : ll;
    return this;
  };
  FallbackMarker.prototype.getLatLng = function () { return this.latlng; };
  FallbackMarker.prototype.bindPopup = function (html) { this.popupHtml = html; return this; };
  FallbackMarker.prototype.openPopup = function () { return this; };
  FallbackMarker.prototype.on = function (evt, fn) {
    this.handlers[evt] = fn;
    return this;
  };
  FallbackMarker.prototype._renderOnFallback = function (map) {
    var pt = map._latLngToXY(this.latlng.lat, this.latlng.lng);
    var div = document.createElement('div');
    div.className = 'cf-marker';
    div.style.cssText = 'position:absolute;left:' + pt.x + 'px;top:' + pt.y + 'px;transform:translate(-50%,-50%);pointer-events:auto;cursor:pointer;z-index:10;';
    if (this.opts.icon && this.opts.icon.html) {
      div.innerHTML = this.opts.icon.html;
    } else {
      div.innerHTML = '<div style="width:22px;height:22px;border-radius:50%;background:#dc2626;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.4);"></div>';
    }
    var self = this;
    div.onclick = function (e) {
      e.stopPropagation();
      if (self.popupHtml) {
        map.popupPane.innerHTML = '<div class="cf-popup" style="position:absolute;left:' + Math.min(Math.max(20, pt.x - 130), (map.el.clientWidth || 500) - 280) + 'px;top:' + Math.max(12, pt.y - 150) + 'px;width:260px;background:#fff;color:#0f172a;border-radius:10px;padding:12px;box-shadow:0 10px 25px rgba(15,23,42,0.28);border:1px solid #cbd5e1;pointer-events:auto;z-index:30;font-size:12.5px;">' +
          '<button type="button" onclick="this.parentElement.remove()" style="float:right;border:none;background:none;font-weight:bold;cursor:pointer;font-size:14px;">✕</button>' +
          self.popupHtml + '</div>';
      }
    };
    map.overlayPane.appendChild(div);
  };

  function FallbackCircle(latlng, opts) {
    this.latlng = Array.isArray(latlng) ? { lat: Number(latlng[0]), lng: Number(latlng[1]) } : latlng;
    this.opts = opts || {};
    this.popupHtml = '';
  }
  FallbackCircle.prototype.addTo = function (target) {
    if (target && target.layers) target.layers.push(this);
    return this;
  };
  FallbackCircle.prototype.bindPopup = function (html) { this.popupHtml = html; return this; };
  FallbackCircle.prototype._renderOnFallback = function (map, ctx) {
    var pt = map._latLngToXY(this.latlng.lat, this.latlng.lng);
    var radiusMeters = this.opts.radius || 150;
    var pxRadius = Math.max(18, Math.min(90, (radiusMeters / 150) * 26 * Math.pow(1.25, map.zoom - 13)));
    ctx.save();
    ctx.beginPath();
    ctx.arc(pt.x, pt.y, pxRadius, 0, Math.PI * 2);
    ctx.fillStyle = this.opts.fillColor || 'rgba(239, 68, 68, 0.18)';
    ctx.globalAlpha = this.opts.fillOpacity !== undefined ? this.opts.fillOpacity : 0.22;
    ctx.fill();
    ctx.globalAlpha = 0.85;
    ctx.lineWidth = this.opts.weight || 2;
    ctx.strokeStyle = this.opts.color || '#ef4444';
    if (this.opts.dashArray) ctx.setLineDash([5, 4]);
    ctx.stroke();
    ctx.restore();
  };

  function FallbackPolygon(latlngs, opts) {
    this.latlngs = latlngs || [];
    this.opts = opts || {};
    this.popupHtml = '';
  }
  FallbackPolygon.prototype.addTo = function (target) {
    if (target && target.layers) target.layers.push(this);
    return this;
  };
  FallbackPolygon.prototype.bindPopup = function (html) { this.popupHtml = html; return this; };
  FallbackPolygon.prototype._renderOnFallback = function (map, ctx) {
    if (!this.latlngs.length) return;
    ctx.save();
    ctx.beginPath();
    this.latlngs.forEach(function (ll, idx) {
      var pt = map._latLngToXY(ll[0], ll[1]);
      if (idx === 0) ctx.moveTo(pt.x, pt.y);
      else ctx.lineTo(pt.x, pt.y);
    });
    ctx.closePath();
    ctx.fillStyle = this.opts.fillColor || this.opts.color || '#3b82f6';
    ctx.globalAlpha = this.opts.fillOpacity || 0.12;
    ctx.fill();
    ctx.globalAlpha = 0.75;
    ctx.lineWidth = this.opts.weight || 2;
    ctx.strokeStyle = this.opts.color || '#2563eb';
    ctx.setLineDash([6, 4]);
    ctx.stroke();
    ctx.restore();
  };

  function FallbackLayerGroup() {
    this.layers = [];
    this._map = null;
  }
  FallbackLayerGroup.prototype.addTo = function (map) {
    this._map = map;
    if (map && map.layers.indexOf(this) === -1) map.layers.push(this);
    if (map && map._render) map._render();
    return this;
  };
  FallbackLayerGroup.prototype.clearLayers = function () {
    this.layers = [];
    if (this._map && this._map._render) this._map._render();
    return this;
  };
  FallbackLayerGroup.prototype._renderOnFallback = function (map, ctx) {
    this.layers.forEach(function (l) {
      if (l && l._renderOnFallback) l._renderOnFallback(map, ctx);
    });
  };

  window.L = {
    map: function (id, opts) { return new FallbackMap(id, opts); },
    tileLayer: function (url, opts) { return new FallbackTileLayer(url, opts); },
    proceduralLudhianaLayer: function (opts) { return new FallbackTileLayer('offline', opts); },
    marker: function (ll, opts) { return new FallbackMarker(ll, opts); },
    circle: function (ll, opts) { return new FallbackCircle(ll, opts); },
    polygon: function (lls, opts) { return new FallbackPolygon(lls, opts); },
    layerGroup: function () { return new FallbackLayerGroup(); },
    divIcon: function (opts) { return opts || {}; }
  };
})();
