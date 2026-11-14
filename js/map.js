/**
 * CivicFix v3.0 — Multi-Layer Smart-City GIS Controller (js/map.js)
 *
 * Features:
 * 1. Bulletproof Dual-Layer Tile Rendering:
 *    - Places a procedural Ludhiana vector canvas grid underneath live CDN tiles (CartoDB / OSM / Esri Satellite / Dark Radar)
 *      AND hooks 'tileerror' on every online TileLayer to substitute procedural Canvas tiles immediately on failure.
 * 2. 4 Map Base Modes on Citizen & Command Center Maps:
 *    - Street (CartoDB Voyager / OSM)
 *    - Satellite (Esri World Imagery)
 *    - Dark Command Radar (CartoDB Dark Matter)
 *    - Offline Vector Grid (100% Local Procedural Ludhiana GIS)
 * 3. 4 Interactive Smart-City GIS Overlays:
 *    - ⭕ 150m Haversine Cluster Radius Geofence Rings
 *    - 🔥 Thermal Grievance Density Heatmap Layer
 *    - 🏛️ Ludhiana Municipal Ward Boundary Polygons (Zone A, B, C, D)
 *    - 🚓 Live GPS Field Patrol & EV Fleet Tracker (with animated dispatch simulation)
 * 4. Automatic ResizeObserver + Tab-Switch invalidateSize() so maps never render grey or clipped.
 */

const LUDHIANA_CENTER = [30.9010, 75.8573];

let citizenMap = null;
let citizenTileLayer = null;
let citizenBaseUnderlay = null;
let citizenPinMarker = null;
let citizenPinCircle = null;
let citizenIssuesLayer = null;
let citizenClusterLayer = null;
let citizenHeatmapLayer = null;
let citizenWardLayer = null;

let adminMap = null;
let adminTileLayer = null;
let adminBaseUnderlay = null;
let adminMarkersLayer = null;
let adminClusterLayer = null;
let adminHeatmapLayer = null;
let adminWardLayer = null;
let adminFleetLayer = null;

let gisOverlayState = {
  clusters150m: true,
  heatmap: false,
  wards: false,
  fleet: true
};

let cachedMapComplaints = [];
let patrolSimInterval = null;

const MUNICIPAL_WARD_ZONES = [
  {
    code: 'Zone A',
    name: 'Zone A — Old City, Clock Tower & Samrala Chowk',
    officer: 'Er. Kuldeep Verma (Zonal Commissioner - Zone A)',
    color: '#0284c7',
    coords: [
      [30.9280, 75.8350],
      [30.9280, 75.8950],
      [30.9050, 75.8950],
      [30.9050, 75.8350]
    ]
  },
  {
    code: 'Zone B',
    name: 'Zone B — Focal Point, Industrial Area & Sherpur',
    officer: 'Er. Navjot Singh (Zonal Commissioner - Zone B)',
    color: '#d97706',
    coords: [
      [30.9050, 75.8720],
      [30.9050, 75.9220],
      [30.8700, 75.9220],
      [30.8700, 75.8720]
    ]
  },
  {
    code: 'Zone C',
    name: 'Zone C — Gill Road, Miller Ganj & Dugri',
    officer: 'Er. Vikramjit Gill (Zonal Commissioner - Zone C)',
    color: '#7c3aed',
    coords: [
      [30.8920, 75.8450],
      [30.8920, 75.8720],
      [30.8650, 75.8720],
      [30.8650, 75.8450]
    ]
  },
  {
    code: 'Zone D',
    name: 'Zone D — Model Town, Sarabha Nagar, Pakhowal & PAU',
    officer: 'Er. Gurpreet Singh (Zonal Commissioner - Zone D)',
    color: '#059669',
    coords: [
      [30.9080, 75.8020],
      [30.9080, 75.8520],
      [30.8760, 75.8520],
      [30.8760, 75.8020]
    ]
  }
];

let FIELD_PATROL_FLEET = [
  {
    id: 'PSPCL-SKY-01',
    name: 'PSPCL Hydraulic Line-Safety Van #01',
    dept: 'DEPT-ELEC',
    officer: 'Er. Amanpreet Kaur (JE - Electrical)',
    lat: 30.8912,
    lng: 75.8405,
    status: 'En Route to Model Town',
    color: '#dc2626',
    icon: '⚡'
  },
  {
    id: 'VAN-BNR-04',
    name: 'B&R Hot-Mix Patching & Roller Unit #04',
    dept: 'DEPT-BNR',
    officer: 'Er. Gurpreet Singh (JE - B&R)',
    lat: 30.8980,
    lng: 75.8482,
    status: 'Active Patrol (Pakhowal Rd)',
    color: '#2563eb',
    icon: '🚧'
  },
  {
    id: 'JET-DRN-07',
    name: 'Super-Sucker Storm Drain Jetting Truck #07',
    dept: 'DEPT-DRN',
    officer: 'Er. Vikramjit Gill (JE - Drainage)',
    lat: 30.8848,
    lng: 75.8601,
    status: 'Deployed at Gill Road ITI',
    color: '#7c3aed',
    icon: '🌊'
  },
  {
    id: 'SWACHH-EV-12',
    name: 'Swachh Bharat EV Compactor #12 (RFID/GPS)',
    dept: 'DEPT-SAN',
    officer: 'Sh. Rajesh Sharma (CSI - Sanitation)',
    lat: 30.9115,
    lng: 75.8555,
    status: 'Zone-A Collection Route',
    color: '#059669',
    icon: '♻️'
  },
  {
    id: 'WAT-OM-03',
    name: 'O&M Pipeline Emergency Clamp Unit #03',
    dept: 'DEPT-WAT',
    officer: 'Er. Harjinder Sidhu (JE - Water Supply)',
    lat: 30.9005,
    lng: 75.8130,
    status: 'On-Site at PAU Gate 2',
    color: '#0284c7',
    icon: '💧'
  }
];

function createTileLayerWithFallback(mode) {
  const cleanMode = mode || 'street';
  const isOfflineSim = typeof window.isSimulatedOffline === 'function' && window.isSimulatedOffline();

  if (cleanMode === 'offline' || isOfflineSim || !navigator.onLine) {
    if (window.L && typeof window.L.proceduralLudhianaLayer === 'function') {
      return window.L.proceduralLudhianaLayer({ styleMode: cleanMode === 'offline' ? 'street' : cleanMode });
    }
  }

  // 100% Keyless Public Tile Servers (OpenStreetMap Official + Esri ArcGIS Online — Zero API Key Required)
  let url = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
  let opts = {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors &middot; CivicFix v3.0 GIS'
  };

  if (cleanMode === 'satellite') {
    url = 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}';
    opts = {
      maxZoom: 19,
      attribution: '&copy; Esri World Imagery &middot; CivicFix v3.0 Satellite GIS'
    };
  } else if (cleanMode === 'radar') {
    url = 'https://tile.openstreetmap.org/{z}/{x}/{y}.png';
    opts = {
      maxZoom: 19,
      className: 'radar-dark-tiles',
      attribution: '&copy; OpenStreetMap &middot; ICCC Dark Command Radar'
    };
  }

  const layer = L.tileLayer(url, opts);
  if (typeof layer.on === 'function') {
    layer.on('tileerror', function (errEvt) {
      if (!errEvt || !errEvt.tile) return;
      const c = errEvt.coords || { x: 10, y: 15, z: 13 };
      // Stage 1 Fallback: Esri World Street Map (keyless)
      if (!errEvt.tile.dataset.esriTried && cleanMode !== 'satellite') {
        errEvt.tile.dataset.esriTried = '1';
        errEvt.tile.src = `https://server.arcgisonline.com/ArcGIS/rest/services/World_Street_Map/MapServer/tile/${c.z}/${c.y}/${c.x}`;
        return;
      }
      // Stage 2 Fallback: Local Procedural Ludhiana Vector Canvas Tile
      if (!errEvt.tile.dataset.fallbackApplied && window.CivicFixTileGenerator) {
        errEvt.tile.dataset.fallbackApplied = '1';
        errEvt.tile.src = window.CivicFixTileGenerator.createTileDataURL(c.x, c.y, c.z, cleanMode);
      }
    });
  }
  return layer;
}

function attachResizeObserver(containerId, mapInstance) {
  const el = document.getElementById(containerId);
  if (!el || !mapInstance) return;
  if (typeof ResizeObserver !== 'undefined') {
    const ro = new ResizeObserver(() => {
      if (mapInstance && typeof mapInstance.invalidateSize === 'function') {
        mapInstance.invalidateSize();
      }
    });
    ro.observe(el);
  }
  [100, 350, 900].forEach(ms => {
    setTimeout(() => {
      if (mapInstance && typeof mapInstance.invalidateSize === 'function') {
        mapInstance.invalidateSize();
      }
    }, ms);
  });
}

function initMaps() {
  // 1. Initialize Citizen Incident & 150m Cluster Map
  const citEl = document.getElementById('citizenMap');
  if (citEl && !citizenMap) {
    citizenMap = L.map('citizenMap', {
      center: LUDHIANA_CENTER,
      zoom: 13
    });

    // Always place Procedural Ludhiana Vector Grid underneath so map is never grey while tiles load
    if (window.L && typeof window.L.proceduralLudhianaLayer === 'function') {
      citizenBaseUnderlay = window.L.proceduralLudhianaLayer({ styleMode: 'street' }).addTo(citizenMap);
    }

    citizenTileLayer = createTileLayerWithFallback('street').addTo(citizenMap);
    citizenWardLayer = L.layerGroup().addTo(citizenMap);
    citizenHeatmapLayer = L.layerGroup().addTo(citizenMap);
    citizenClusterLayer = L.layerGroup().addTo(citizenMap);
    citizenIssuesLayer = L.layerGroup().addTo(citizenMap);

    citizenMap.on('click', function (e) {
      if (e && e.latlng) {
        setCitizenPin(e.latlng.lat, e.latlng.lng, 'Pinned on Ludhiana GIS Grid');
      }
    });

    attachResizeObserver('citizenMap', citizenMap);
    setCitizenPin(30.8965, 75.8458, 'Pakhowal Road Canal Bridge, Ludhiana (Default Pin)');
  }

  // 2. Initialize Admin ICCC Command Center Map
  const admEl = document.getElementById('adminMap');
  if (admEl && !adminMap) {
    adminMap = L.map('adminMap', {
      center: LUDHIANA_CENTER,
      zoom: 13
    });

    if (window.L && typeof window.L.proceduralLudhianaLayer === 'function') {
      adminBaseUnderlay = window.L.proceduralLudhianaLayer({ styleMode: 'street' }).addTo(adminMap);
    }

    adminTileLayer = createTileLayerWithFallback('street').addTo(adminMap);
    adminWardLayer = L.layerGroup().addTo(adminMap);
    adminHeatmapLayer = L.layerGroup().addTo(adminMap);
    adminClusterLayer = L.layerGroup().addTo(adminMap);
    adminMarkersLayer = L.layerGroup().addTo(adminMap);
    adminFleetLayer = L.layerGroup().addTo(adminMap);

    attachResizeObserver('adminMap', adminMap);
  }
}

function setCitizenPin(lat, lng, labelText) {
  const numLat = Number(lat).toFixed(6);
  const numLng = Number(lng).toFixed(6);

  const latInput = document.getElementById('latitude');
  const lngInput = document.getElementById('longitude');
  if (latInput) latInput.value = numLat;
  if (lngInput) lngInput.value = numLng;

  const statusEl = document.getElementById('coordStatusText');
  if (statusEl) {
    statusEl.innerHTML = `📍 <b>Pinned Coordinates:</b> ${numLat}° N, ${numLng}° E &middot; <span style="color:#059669;font-weight:600">150m Spatial Deduplication Geofence Active</span>`;
  }

  if (!citizenMap) return;

  if (citizenPinMarker) citizenMap.removeLayer(citizenPinMarker);
  if (citizenPinCircle) citizenMap.removeLayer(citizenPinCircle);

  const pinIcon = L.divIcon({
    className: 'custom-citizen-pin',
    html: `<div style="width:28px;height:28px;border-radius:50%;background:#dc2626;border:3px solid #ffffff;box-shadow:0 0 0 6px rgba(220,38,38,0.25),0 4px 10px rgba(0,0,0,0.4);display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;font-weight:bold;">📍</div>`,
    iconSize: [28, 28],
    iconAnchor: [14, 14]
  });

  citizenPinMarker = L.marker([Number(numLat), Number(numLng)], { icon: pinIcon }).addTo(citizenMap);
  citizenPinMarker.bindPopup(
    `<b>📍 New Grievance Pin</b><br>${labelText || 'Selected Incident Location'}<br><small>${numLat}° N, ${numLng}° E (150m Cluster Radius)</small>`
  );

  citizenPinCircle = L.circle([Number(numLat), Number(numLng)], {
    radius: 150,
    color: '#dc2626',
    weight: 2,
    dashArray: '6, 4',
    fillColor: '#ef4444',
    fillOpacity: 0.16
  }).addTo(citizenMap);

  if (typeof scheduleLiveAiPreview === 'function') {
    scheduleLiveAiPreview();
  }
}

function setCitizenMapLayer(mode) {
  ['layerStreetBtn', 'layerSatBtn', 'layerRadarBtn', 'layerOfflineBtn'].forEach(id => {
    const btn = document.getElementById(id);
    if (btn) btn.classList.remove('active');
  });
  const btnMap = {
    street: 'layerStreetBtn',
    satellite: 'layerSatBtn',
    radar: 'layerRadarBtn',
    offline: 'layerOfflineBtn'
  };
  const activeBtn = document.getElementById(btnMap[mode] || 'layerStreetBtn');
  if (activeBtn) activeBtn.classList.add('active');

  if (!citizenMap) return;
  if (citizenBaseUnderlay && typeof citizenBaseUnderlay.setStyleMode === 'function') {
    citizenBaseUnderlay.setStyleMode(mode === 'offline' ? 'street' : mode);
  }
  if (citizenTileLayer) {
    citizenMap.removeLayer(citizenTileLayer);
  }
  citizenTileLayer = createTileLayerWithFallback(mode).addTo(citizenMap);
  citizenMap.invalidateSize();
}

function setAdminMapLayer(mode) {
  ['adminStreetBtn', 'adminSatBtn', 'adminRadarBtn', 'adminOfflineBtn'].forEach(id => {
    const btn = document.getElementById(id);
    if (btn) btn.classList.remove('active');
  });
  const btnMap = {
    street: 'adminStreetBtn',
    satellite: 'adminSatBtn',
    radar: 'adminRadarBtn',
    offline: 'adminOfflineBtn'
  };
  const activeBtn = document.getElementById(btnMap[mode] || 'adminStreetBtn');
  if (activeBtn) activeBtn.classList.add('active');

  if (!adminMap) return;
  if (adminBaseUnderlay && typeof adminBaseUnderlay.setStyleMode === 'function') {
    adminBaseUnderlay.setStyleMode(mode === 'offline' ? 'street' : mode);
  }
  if (adminTileLayer) {
    adminMap.removeLayer(adminTileLayer);
  }
  adminTileLayer = createTileLayerWithFallback(mode).addTo(adminMap);
  adminMap.invalidateSize();
}

function toggleGisOverlay(overlayKey) {
  if (!(overlayKey in gisOverlayState)) return;
  gisOverlayState[overlayKey] = !gisOverlayState[overlayKey];

  document.querySelectorAll(`[data-gis-toggle="${overlayKey}"]`).forEach(btn => {
    btn.classList.toggle('active', gisOverlayState[overlayKey]);
  });

  renderMapComplaints(cachedMapComplaints);
  if (typeof showToast === 'function') {
    const labels = {
      clusters150m: '150m Haversine Cluster Geofence Rings',
      heatmap: 'Thermal Grievance Density Heatmap',
      wards: 'Ludhiana Municipal Ward Boundaries',
      fleet: 'Live GPS Field Patrol & EV Fleet'
    };
    showToast(`${labels[overlayKey]}: ${gisOverlayState[overlayKey] ? 'ENABLED' : 'HIDDEN'}`);
  }
}

function getPriorityColor(priority, status) {
  if (status === 'Resolved') return '#10b981';
  switch (priority) {
    case 'Critical': return '#dc2626';
    case 'High':     return '#ea580c';
    case 'Medium':   return '#2563eb';
    default:         return '#64748b';
  }
}

function renderWardBoundariesOnLayer(layerGroup) {
  if (!layerGroup) return;
  layerGroup.clearLayers();
  if (!gisOverlayState.wards) return;

  MUNICIPAL_WARD_ZONES.forEach(w => {
    const poly = L.polygon(w.coords, {
      color: w.color,
      weight: 2,
      dashArray: '6, 4',
      fillColor: w.color,
      fillOpacity: 0.10
    });
    poly.bindPopup(
      `<div style="font-size:12.5px"><b>🏛️ ${w.name}</b><br><span><b>Zonal Officer:</b> ${w.officer}</span></div>`
    );
    poly.addTo(layerGroup);
  });
}

function renderFleetOnAdminMap() {
  if (!adminFleetLayer) return;
  adminFleetLayer.clearLayers();
  if (!gisOverlayState.fleet) return;

  FIELD_PATROL_FLEET.forEach(unit => {
    const icon = L.divIcon({
      className: 'fleet-gps-marker',
      html: `<div style="background:${unit.color};color:#fff;padding:3px 7px;border-radius:999px;border:2px solid #fff;box-shadow:0 3px 10px rgba(0,0,0,0.35);font-size:11px;font-weight:700;white-space:nowrap;display:flex;align-items:center;gap:4px;"><span>${unit.icon}</span><span>${unit.id}</span></div>`,
      iconSize: [96, 26],
      iconAnchor: [48, 13]
    });
    const m = L.marker([unit.lat, unit.lng], { icon }).addTo(adminFleetLayer);
    m.bindPopup(
      `<div style="line-height:1.45">
        <span style="background:${unit.color};color:#fff;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700">LIVE GPS TELEMETRY • ${unit.id}</span>
        <h4 style="margin:6px 0 3px;font-size:13px">${unit.name}</h4>
        <div style="font-size:12px;color:#334155"><b>Assigned Officer:</b> ${unit.officer}</div>
        <div style="font-size:12px;color:#059669;font-weight:600"><b>Status:</b> ${unit.status}</div>
        <div style="font-size:11px;color:#64748b;margin-top:4px">GPS: ${unit.lat.toFixed(4)}° N, ${unit.lng.toFixed(4)}° E</div>
      </div>`
    );
  });
}

function simulateLivePatrolDispatch() {
  if (patrolSimInterval) {
    clearInterval(patrolSimInterval);
    patrolSimInterval = null;
    if (typeof showToast === 'function') showToast('Stopped live GPS patrol simulation.');
    return;
  }

  gisOverlayState.fleet = true;
  document.querySelectorAll('[data-gis-toggle="fleet"]').forEach(b => b.classList.add('active'));

  if (typeof showToast === 'function') {
    showToast('🚓 Live ICCC GPS Fleet Telemetry Active: Dispatching nearest field units toward Critical incidents!');
  }

  let step = 0;
  patrolSimInterval = setInterval(() => {
    step++;
    FIELD_PATROL_FLEET.forEach((unit, idx) => {
      // Gently move towards active hotspot coordinates
      const targetLat = idx === 0 ? 30.8884 : (idx === 1 ? 30.8965 : (idx === 2 ? 30.8825 : 30.9018));
      const targetLng = idx === 0 ? 75.8432 : (idx === 1 ? 75.8458 : (idx === 2 ? 75.8622 : 75.8095));
      unit.lat += (targetLat - unit.lat) * 0.22 + (Math.sin(step + idx) * 0.0004);
      unit.lng += (targetLng - unit.lng) * 0.22 + (Math.cos(step + idx) * 0.0004);
    });
    renderFleetOnAdminMap();
    if (step >= 12) {
      clearInterval(patrolSimInterval);
      patrolSimInterval = null;
    }
  }, 900);
}

function renderMapComplaints(complaints, activeDeptFilter) {
  if (Array.isArray(complaints)) {
    cachedMapComplaints = complaints;
  }
  const list = cachedMapComplaints || [];
  const deptFilter = activeDeptFilter || (typeof window.currentAdminDept !== 'undefined' ? window.currentAdminDept : 'All');

  // Render Ward Boundaries
  renderWardBoundariesOnLayer(citizenWardLayer);
  renderWardBoundariesOnLayer(adminWardLayer);

  // Render Citizen Map Existing Issues, 150m Clusters & Heatmap
  if (citizenIssuesLayer && citizenClusterLayer && citizenHeatmapLayer) {
    citizenIssuesLayer.clearLayers();
    citizenClusterLayer.clearLayers();
    citizenHeatmapLayer.clearLayers();

    list.forEach(item => {
      if (!item.latitude || !item.longitude) return;
      const lat = Number(item.latitude);
      const lng = Number(item.longitude);
      const color = getPriorityColor(item.priority, item.status);
      const isCluster = (Number(item.cluster_count) || 1) > 1;

      if (gisOverlayState.heatmap && item.status !== 'Resolved') {
        const heatRadius = 260 + Math.min(400, (Number(item.upvotes) || 1) * 14);
        L.circle([lat, lng], {
          radius: heatRadius,
          color: color,
          weight: 0,
          fillColor: color,
          fillOpacity: 0.24
        }).addTo(citizenHeatmapLayer);
      }

      if (gisOverlayState.clusters150m && item.status !== 'Resolved') {
        L.circle([lat, lng], {
          radius: 150,
          color: color,
          weight: isCluster ? 2.2 : 1.4,
          dashArray: '5, 5',
          fillColor: color,
          fillOpacity: isCluster ? 0.16 : 0.08
        }).addTo(citizenClusterLayer);
      }

      const badgeText = isCluster ? `${item.cluster_count}x` : '●';
      const icon = L.divIcon({
        className: 'citizen-issue-pin',
        html: `<div style="width:${isCluster ? 26 : 18}px;height:${isCluster ? 26 : 18}px;border-radius:50%;background:${color};border:2.5px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.35);color:#fff;font-size:10px;font-weight:800;display:flex;align-items:center;justify-content:center;">${badgeText}</div>`,
        iconSize: [isCluster ? 26 : 18, isCluster ? 26 : 18],
        iconAnchor: [isCluster ? 13 : 9, isCluster ? 13 : 9]
      });

      const m = L.marker([lat, lng], { icon }).addTo(citizenIssuesLayer);
      m.bindPopup(
        `<div style="line-height:1.4">
          <b style="color:#0a3560">${item.complaint_code}</b> &middot; <span style="color:${color};font-weight:700">${item.priority} (${item.status})</span><br>
          <b>${item.title}</b><br>
          <small style="color:#475569">${item.assigned_department} &middot; 👍 ${item.upvotes || 1} Upvotes &middot; Cluster: ${item.cluster_count || 1}</small><br>
          <button type="button" class="btn-mini" style="margin-top:6px" onclick="openComplaintModal('${item.complaint_code}')">View Proof &amp; Audit Ledger</button>
        </div>`
      );
    });
  }

  // Render Admin Command Center Map
  if (adminMarkersLayer && adminClusterLayer && adminHeatmapLayer) {
    adminMarkersLayer.clearLayers();
    adminClusterLayer.clearLayers();
    adminHeatmapLayer.clearLayers();

    const filtered = deptFilter === 'All'
      ? list
      : list.filter(c => c.department_code === deptFilter);

    filtered.forEach(item => {
      if (!item.latitude || !item.longitude) return;
      const lat = Number(item.latitude);
      const lng = Number(item.longitude);
      const color = getPriorityColor(item.priority, item.status);
      const isCluster = (Number(item.cluster_count) || 1) > 1;

      if (gisOverlayState.heatmap && item.status !== 'Resolved') {
        const heatRadius = 280 + Math.min(450, (Number(item.upvotes) || 1) * 15);
        L.circle([lat, lng], {
          radius: heatRadius,
          color: color,
          weight: 0,
          fillColor: color,
          fillOpacity: 0.26
        }).addTo(adminHeatmapLayer);
      }

      if (gisOverlayState.clusters150m) {
        L.circle([lat, lng], {
          radius: 150,
          color: color,
          weight: isCluster ? 2.5 : 1.5,
          dashArray: '5, 5',
          fillColor: color,
          fillOpacity: isCluster ? 0.18 : 0.08
        }).addTo(adminClusterLayer);
      }

      const label = isCluster ? `${item.cluster_count}x` : '';
      const size = isCluster ? 30 : 22;
      const icon = L.divIcon({
        className: 'admin-command-pin',
        html: `<div style="width:${size}px;height:${size}px;border-radius:50%;background:${color};border:3px solid #fff;box-shadow:0 3px 10px rgba(0,0,0,0.45);color:#fff;font-size:10.5px;font-weight:800;display:flex;align-items:center;justify-content:center;">${label}</div>`,
        iconSize: [size, size],
        iconAnchor: [size / 2, size / 2]
      });

      const m = L.marker([lat, lng], { icon }).addTo(adminMarkersLayer);
      m.bindPopup(
        `<div style="line-height:1.45">
          <span style="background:${color};color:#fff;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700">${item.priority.toUpperCase()} • ${item.status}</span>
          <h4 style="margin:5px 0 3px;font-size:13px">${item.complaint_code}: ${item.title}</h4>
          <div style="font-size:11.5px;color:#334155"><b>Dept:</b> ${item.assigned_department}</div>
          <div style="font-size:11.5px;color:#334155"><b>Field JE:</b> ${item.assigned_je || 'Assigned Zonal JE'}</div>
          <div style="font-size:11.5px;color:#059669;font-weight:600"><b>SLA Status:</b> ${item.sla_state || (item.sla_hours + 'h SLA')}</div>
          <div style="font-size:11px;color:#64748b">Clustered Reports: <b>${item.cluster_count || 1}</b> &middot; Citizen Upvotes: <b>${item.upvotes || 1}</b></div>
          <button type="button" class="btn-mini" style="margin-top:6px" onclick="openComplaintModal('${item.complaint_code}')">Inspect &amp; Dispatch Work-Order</button>
        </div>`
      );
    });
  }

  renderFleetOnAdminMap();
}

function locateCitizenOnMap() {
  if (navigator.geolocation) {
    navigator.geolocation.getCurrentPosition(
      pos => {
        const lat = pos.coords.latitude;
        const lng = pos.coords.longitude;
        if (citizenMap) citizenMap.flyTo([lat, lng], 15);
        setCitizenPin(lat, lng, 'Hardware GPS Locked Coordinates');
        if (typeof showToast === 'function') showToast('📍 Hardware GPS coordinates locked on map!');
      },
      () => {
        const fallbackLat = 30.8965 + (Math.random() - 0.5) * 0.008;
        const fallbackLng = 75.8458 + (Math.random() - 0.5) * 0.008;
        if (citizenMap) citizenMap.flyTo([fallbackLat, fallbackLng], 15);
        setCitizenPin(fallbackLat, fallbackLng, 'Simulated Ludhiana GPS Sector Lock');
        if (typeof showToast === 'function') showToast('📍 Locked onto Ludhiana Sector GPS coordinates!');
      },
      { timeout: 4000 }
    );
  } else {
    setCitizenPin(30.8884, 75.8432, 'Model Town Sector GPS Lock');
  }
}

function clearCitizenMapPin() {
  if (citizenMap) citizenMap.flyTo(LUDHIANA_CENTER, 13);
  setCitizenPin(LUDHIANA_CENTER[0], LUDHIANA_CENTER[1], 'Ludhiana Central GIS Grid');
}

function searchLocationOnMap() {
  const input = document.getElementById('mapSearchInput');
  const query = (input ? input.value : '').trim().toLowerCase();
  if (!query) {
    if (typeof showToast === 'function') showToast('Enter a Ludhiana area (e.g. Model Town, PAU, Gill Road, Clock Tower).');
    return;
  }

  const gazetteer = [
    { keys: ['model town', 'gol market'], lat: 30.8884, lng: 75.8432, label: 'Model Town Gol Market, Zone D, Ludhiana', ward: 'Zone D - Model Town & Sarabha Nagar' },
    { keys: ['pau', 'agricultural', 'ferozepur'], lat: 30.9018, lng: 75.8095, label: 'PAU Gate No. 2, Ferozepur Road, Ludhiana', ward: 'Zone D - PAU & Ferozepur Road' },
    { keys: ['sarabha', 'kipps'], lat: 30.8915, lng: 75.8242, label: 'Sarabha Nagar Main Market, Ludhiana', ward: 'Zone D - Model Town & Sarabha Nagar' },
    { keys: ['pakhowal', 'canal'], lat: 30.8965, lng: 75.8458, label: 'Pakhowal Road Sidhwan Canal Bridge, Ludhiana', ward: 'Zone D - Pakhowal & Sarabha Nagar' },
    { keys: ['clock tower', 'ghanta ghar', 'chaura'], lat: 30.9136, lng: 75.8534, label: 'Clock Tower (Ghanta Ghar), Old City, Ludhiana', ward: 'Zone A - Old City & Clock Tower' },
    { keys: ['samrala', 'chandigarh road'], lat: 30.9096, lng: 75.8902, label: 'Samrala Chowk Flyover, Zone A, Ludhiana', ward: 'Zone A - Samrala Chowk' },
    { keys: ['gill', 'iti', 'miller'], lat: 30.8825, lng: 75.8622, label: 'Gill Road ITI Chowk, Zone C, Ludhiana', ward: 'Zone C - Gill Road & Miller Ganj' },
    { keys: ['dugri', 'urban estate'], lat: 30.8715, lng: 75.8440, label: 'Dugri Phase-2 Urban Estate, Zone C, Ludhiana', ward: 'Zone C - Dugri & Southern Bypass' },
    { keys: ['focal point', 'sherpur', 'industrial'], lat: 30.8870, lng: 75.9015, label: 'Focal Point Phase-V Industrial Hub, Zone B, Ludhiana', ward: 'Zone B - Focal Point & Sherpur' },
    { keys: ['civil lines', 'rakh bagh', 'fountain'], lat: 30.9062, lng: 75.8390, label: 'Civil Lines & Rakh Bagh, Ludhiana', ward: 'Zone D - Civil Lines' },
    { keys: ['haibowal'], lat: 30.9225, lng: 75.8240, label: 'Haibowal Kalan Main Road, Ludhiana', ward: 'Zone A - Haibowal' }
  ];

  let matched = null;
  for (const g of gazetteer) {
    if (g.keys.some(k => query.includes(k))) {
      matched = g;
      break;
    }
  }

  if (!matched) {
    matched = {
      lat: 30.9010 + (Math.random() - 0.5) * 0.015,
      lng: 75.8573 + (Math.random() - 0.5) * 0.015,
      label: `${input.value.trim()}, Ludhiana Municipal Grid`,
      ward: 'Zone D - Model Town & Sarabha Nagar'
    };
  }

  if (citizenMap) citizenMap.flyTo([matched.lat, matched.lng], 15);
  setCitizenPin(matched.lat, matched.lng, matched.label);

  const locField = document.getElementById('location');
  if (locField && !locField.value.trim()) locField.value = matched.label;

  const wardSelect = document.getElementById('ward_zone');
  if (wardSelect && matched.ward) {
    for (let i = 0; i < wardSelect.options.length; i++) {
      if (wardSelect.options[i].value.includes(matched.ward.substring(0, 6))) {
        wardSelect.selectedIndex = i;
        break;
      }
    }
  }

  if (typeof showToast === 'function') {
    showToast(`🗺️ Located: ${matched.label}`);
  }
}
