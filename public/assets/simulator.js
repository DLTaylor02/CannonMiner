(() => {
  const config = window.CannonMinerSimulation;
  const canvas = document.getElementById('simulation-map');
  const context = canvas.getContext('2d');
  const fuelGameCanvas = document.getElementById('fuel-minigame');
  const fuelGameContext = fuelGameCanvas.getContext('2d');
  const tireGameCanvas = document.getElementById('tire-minigame');
  const tireGameContext = tireGameCanvas.getContext('2d');
  const summaryCanvas = document.getElementById('summary-route-map');
  const statusNode = document.getElementById('simulation-status');
  const timeNode = document.getElementById('simulation-time');
  const errorNode = document.getElementById('simulation-error');
  const canControl = Boolean(config.canControl);
  const isSuperadmin = Boolean(config.isSuperadmin);
  const seenEvents = new Set();
  let lastEvent = 0;
  let currentStatus = '';
  let polling = false;
  let pollTimer = null;
  let speedTimer = null;
  let currentVehicleTop = Infinity;
  let latestState = null;
  let latestSimulation = null;
  let stateBoundaries = null;
  let mapHistoryIndex = null;
  let pollCount = 0;
  let fuelGameState = null;
  let fuelDrag = null;
  let fuelAnimationFrame = null;
  let fuelCompletionSent = false;
  let fuelInteractionPending = false;
  let fuelPendingStep = null;
  let fuelingStartedAt = 0;
  let tireGameState = null;
  let tireDrag = null;
  let tireCarry = null;
  let tireCursor = {x:500,y:260};
  let tireHold = null;
  let tireInteractionPending = false;
  let tirePendingStep = null;
  let tirePendingLug = null;
  let tireAnimationFrame = null;
  const liveMapLayers = new Map();
  if (typeof performance.setResourceTimingBufferSize === 'function') performance.setResourceTimingBufferSize(150);
  const fmt = new Intl.DateTimeFormat(undefined, {weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit', timeZoneName: 'short'});
  const newYorkFmt = new Intl.DateTimeFormat(undefined, {weekday: 'short', year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit', timeZone: 'America/New_York', timeZoneName: 'short'});
  const daylightStatus = value => { const hour = value.getHours(); if (hour >= 5 && hour < 8) return {icon: '\u{1f305}', label: 'Sunrise'}; if (hour >= 8 && hour < 17) return {icon: '\u2600\ufe0f', label: 'Daylight'}; if (hour >= 17 && hour < 20) return {icon: '\u{1f307}', label: 'Sunset'}; return {icon: '\u{1f319}', label: 'Night'}; };
  const escape = value => String(value).replace(/[&<>"']/g, char => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char]));
  const duration = seconds => { seconds = Math.max(0, Math.round(Number(seconds) || 0)); const hours = Math.floor(seconds / 3600), minutes = Math.floor(seconds % 3600 / 60), rest = seconds % 60; return `${hours ? hours + 'h ' : ''}${minutes ? minutes + 'm ' : ''}${rest}s`; };
  const elapsedDuration = seconds => { seconds = Math.max(0, Math.round(Number(seconds) || 0)); return `${Math.floor(seconds / 3600)}h ${Math.floor(seconds % 3600 / 60)}m ${seconds % 60}s`; };
  const locations = {redball: 'Redball Garage, NY', portofino: 'Portofino Marina, CA', bar: 'Barstow, CA', big: 'Big Springs, NE', cole: 'Columbus East, OH', coln: 'Columbus North, OH', cov: 'Cove Fort, UT', den: 'Denver, CO', elr: 'El Reno, OK', har: 'Harrisburg, PA', nash: 'Nashville, TN', stl: 'St. Louis, IL', you: 'Youngstown, OH'};
  const mapEventSymbols = {fuel_stop: '\u26fd', driver_change: '\u21c4', roadside_rest_started: '\u{1f634}', traffic_event: '\u{1f6a6}', invented_traffic: '\u{1f68c}', police_event: '\u{1f693}', crash_event: '\u{1f4a5}', wildlife_event: '\u{1f98c}', flat_tire: '\u26ab', mechanical_failure: '\u{1f527}', road_event: '\u{1f6a7}', obstacle_response: '\u2705', weather_fog: '\u{1f32b}\ufe0f', weather_rain: '\u{1f327}\ufe0f', weather_ice: '\u{1f9ca}', weather_snow: '\u{1f328}\ufe0f', weather_tail_wind: '\u{1f4a8}', weather_head_wind: '\u{1f32c}\ufe0f'};
  const eventSymbol = (type, payload = {}) => { if (['consumable_used', 'consumable_rejected', 'consumable_wore_off'].includes(type)) return payload.consumable === 'snack' ? '\u{1f37f}' : '\u{1f964}'; if (type === 'weather_event') return mapEventSymbols[`weather_${payload.weather}`] || ''; if (type === 'driver_changed' || type === 'driver_change_complete') return mapEventSymbols.driver_change; if (type === 'traffic_delay' || type === 'traffic_cap_lifted') return mapEventSymbols.traffic_event; if (type === 'invented_traffic_cleared' || type === 'event_acknowledged') return mapEventSymbols.obstacle_response; if (type === 'flat_tire_ended_run') return mapEventSymbols.flat_tire; if (type === 'mechanical_failure_ended_run') return mapEventSymbols.mechanical_failure; if (type === 'crew_jailed') return mapEventSymbols.police_event; return mapEventSymbols[type] || ''; };
  const locationName = node => locations[node] || String(node).replaceAll('_', ' ');
  const routeName = name => String(name).split('_to_').map(locationName).join(' to ');
  fetch('/assets/data/us-states-20m.json', {cache: 'force-cache'}).then(response => { if (!response.ok) throw new Error('State map unavailable'); return response.json(); }).then(data => { stateBoundaries = data.states || []; liveMapLayers.clear(); if (latestState) { renderLiveMap(latestState); if (!document.getElementById('simulation-summary').hidden) drawSummaryMap(latestState); } }).catch(() => {});
  const equipmentMarkup = (equipment, consumables = {}) => { const items = [], cells = Number(equipment?.fuel_cells || 0); if (cells) items.push({icon: '\u26fd', text: `${cells} fuel cell${cells === 1 ? '' : 's'}: +${cells * 20} gal capacity`, quantity: cells}); if (equipment?.cruising_tune) items.push({icon: '\u{1f6e0}\ufe0f', text: 'Cruising tune: +5 MPG above 70 mph; tuned top speed unlocked'}); if (equipment?.tuned_up) items.push({icon: '\u{1f527}', text: 'Tuned up: Lemon rating reduced to 1%'}); if (equipment?.cruising_tires) items.push({icon: '\u25c9', text: 'Cruising tires: +1 MPG; flat-tire chance reduced 50%'}); if (equipment?.additional_spare && !equipment?.additional_spare_consumed) items.push({icon: '\u{1f9f0}', text: 'Additional Spare: one additional recoverable flat tire'}); if (equipment?.radio_scanner) items.push({icon: '\u{1f4fb}', text: 'Radio Scanner: early warning police response'}); if (equipment?.radar_scanner) items.push({icon: '\u{1f4e1}', text: 'Radar Scanner: police encounter chance reduced 50%'}); if (equipment?.radar_jammer) items.push({icon: '\u{1f6e1}\ufe0f', text: 'Radar Jammer: active police countermeasure'}); if (equipment?.police_camo) items.push({icon: '\u{1f46e}', text: 'Police Camo: traffic moves aside; every police stop ends the run'}); if (equipment?.thermal_camera) items.push({icon: '\u{1f321}\ufe0f', text: 'Thermal Camera: wildlife detection prevents a collision'}); if (equipment?.redbull_cooler) items.push({icon: '\u{1f964}', text: `RedBull cooler: ${Number(consumables?.redbull?.remaining || 0)} of 6 remaining`}); if (Number(equipment?.snacks || 0) > 0) items.push({icon: '\u{1f37f}', text: `Snacks: ${Number(consumables?.snack?.remaining || 0)} of ${Number(equipment.snacks)} remaining`}); return items.length ? items.map(item => `<span class="${item.quantity > 1 ? 'equipment-stack' : ''}" tabindex="0" title="${escape(item.text)}" aria-label="${escape(item.text)}"><i aria-hidden="true">${item.icon}</i>${item.quantity > 1 ? `<b aria-hidden="true">x${item.quantity}</b>` : ''}</span>`).join('') : 'None'; };
  const equipmentSummary = equipment => { const items = [], cells = Number(equipment?.fuel_cells || 0); if (cells) items.push({icon: '\u26fd', text: `${cells} fuel cell${cells === 1 ? '' : 's'}`}); if (equipment?.cruising_tune) items.push({icon: '\u{1f6e0}\ufe0f', text: 'Cruising tune'}); if (equipment?.tuned_up) items.push({icon: '\u{1f527}', text: 'Tuned up'}); if (equipment?.cruising_tires) items.push({icon: '\u25c9', text: 'Cruising tires'}); if (equipment?.additional_spare) items.push({icon: '\u{1f9f0}', text: 'Additional Spare'}); if (equipment?.radio_scanner) items.push({icon: '\u{1f4fb}', text: 'Radio Scanner'}); if (equipment?.radar_scanner) items.push({icon: '\u{1f4e1}', text: 'Radar Scanner'}); if (equipment?.radar_jammer) items.push({icon: '\u{1f6e1}\ufe0f', text: 'Radar Jammer'}); if (equipment?.police_camo) items.push({icon: '\u{1f46e}', text: 'Police Camo'}); if (equipment?.thermal_camera) items.push({icon: '\u{1f321}\ufe0f', text: 'Thermal Camera'}); if (equipment?.redbull_cooler) items.push({icon: '\u{1f964}', text: 'Cooler full of RedBull'}); if (Number(equipment?.snacks || 0) > 0) items.push({icon: '\u{1f37f}', text: `${Number(equipment.snacks)} snack${Number(equipment.snacks) === 1 ? '' : 's'}`}); return items; };

  function decodePolyline(encoded) {
    let index = 0, lat = 0, lng = 0;
    const points = [];
    while (index < encoded.length) {
      let shift = 0, result = 0, byte;
      do { byte = encoded.charCodeAt(index++) - 63; result |= (byte & 31) << shift; shift += 5; } while (byte >= 32);
      lat += (result & 1) ? ~(result >> 1) : result >> 1;
      shift = 0; result = 0;
      do { byte = encoded.charCodeAt(index++) - 63; result |= (byte & 31) << shift; shift += 5; } while (byte >= 32);
      lng += (result & 1) ? ~(result >> 1) : result >> 1;
      points.push([lng / 1e5, lat / 1e5]);
    }
    return points;
  }

  const pointAt = (points, fraction) => { const position = Math.max(0, Math.min(1, fraction)) * (points.length - 1), low = Math.floor(position), share = position - low, high = Math.min(points.length - 1, low + 1); return [points[low][0] + (points[high][0] - points[low][0]) * share, points[low][1] + (points[high][1] - points[low][1]) * share]; };
  const speedColor = ratio => { ratio = Math.max(0, Math.min(1, Number(ratio) || 0)); const red = [255, 0, 0], green = [101, 201, 157], rgb = red.map((value, index) => Math.round(value + (green[index] - value) * ratio)); return `rgb(${rgb.join(',')})`; };
  function strokeRange(target, points, project, from, to, color, width = 8) {
    if (points.length < 2 || to <= from) return;
    const start = Math.max(0, Math.min(1, from)) * (points.length - 1), end = Math.max(0, Math.min(1, to)) * (points.length - 1);
    target.strokeStyle = color; target.lineWidth = width; target.lineCap = 'round'; target.lineJoin = 'round'; target.beginPath();
    let [x, y] = project(pointAt(points, from)); target.moveTo(x, y);
    for (let index = Math.ceil(start); index <= Math.floor(end); index++) { [x, y] = project(points[index]); target.lineTo(x, y); }
    [x, y] = project(pointAt(points, to)); target.lineTo(x, y); target.stroke();
  }
  function drawMapEvents(target, points, project, events, lift = 0, occupied = []) {
    const offsets = [[0,0]];
    for (let ring = 1; ring <= 8; ring += 1) { const distance = ring * 29; offsets.push([0,-distance],[distance,0],[0,distance],[-distance,0],[distance,-distance],[distance,distance],[-distance,distance],[-distance,-distance]); }
    (events || []).forEach(event => {
      if (!mapEventSymbols[event.type]) return;
      const [routeX, routeY] = project(pointAt(points, Number(event.fraction))), anchorY = routeY + lift;
      let x = routeX, y = anchorY;
      for (const [offsetX, offsetY] of offsets) {
        const candidateX = routeX + offsetX, candidateY = anchorY + offsetY;
        if (candidateX < 15 || candidateX > target.canvas.width - 15 || candidateY < 15 || candidateY > target.canvas.height - 15) continue;
        if (occupied.every(point => Math.hypot(candidateX - point.x, candidateY - point.y) >= 29)) { x = candidateX; y = candidateY; break; }
      }
      occupied.push({x, y});
      if (Math.hypot(x - routeX, y - anchorY) > 4) { target.strokeStyle = 'rgba(255,255,255,.55)'; target.lineWidth = 1.5; target.beginPath(); target.moveTo(routeX, routeY); target.lineTo(x, y); target.stroke(); }
      target.fillStyle = '#fff'; target.strokeStyle = '#18211d'; target.lineWidth = 2; target.beginPath(); target.arc(x, y, 13, 0, Math.PI * 2); target.fill(); target.stroke();
      target.fillStyle = '#18211d'; target.font = 'bold 16px sans-serif'; target.textAlign = 'center'; target.textBaseline = 'middle'; target.fillText(mapEventSymbols[event.type], x, y + 1);
    });
    return occupied;
  }
  function routeProjection(targetCanvas, allPoints, pad) {
    const mercator = point => { const latitude = Math.max(-85, Math.min(85, Number(point[1]))), radians = latitude * Math.PI / 180; return [Number(point[0]), Math.log(Math.tan(Math.PI / 4 + radians / 2)) * 180 / Math.PI]; };
    const projected = allPoints.map(mercator), xs = projected.map(point => point[0]), ys = projected.map(point => point[1]), minX = Math.min(...xs), maxX = Math.max(...xs), minY = Math.min(...ys), maxY = Math.max(...ys);
    const scale = Math.min((targetCanvas.width - pad * 2) / Math.max(.001, maxX - minX), (targetCanvas.height - pad * 2) / Math.max(.001, maxY - minY));
    return point => { const mapped = mercator(point); return [pad + (mapped[0] - minX) * scale, targetCanvas.height - pad - (mapped[1] - minY) * scale]; };
  }

  function stateLabelArea(polygon, project, canvas) {
    const points = [];
    for (let index = 0; index + 1 < polygon.outer.length; index += 2) points.push(project([polygon.outer[index], polygon.outer[index + 1]]));
    if (points.length < 3) return null;
    const minY = Math.max(9, Math.min(...points.map(point => point[1]))), maxY = Math.min(canvas.height - 9, Math.max(...points.map(point => point[1])));
    if (maxY <= minY) return null;
    const spansAt = y => {
      const intersections = [];
      points.forEach((point, index) => {
        const next = points[(index + 1) % points.length];
        if ((point[1] > y) === (next[1] > y)) return;
        intersections.push(point[0] + (y - point[1]) * (next[0] - point[0]) / (next[1] - point[1]));
      });
      intersections.sort((a, b) => a - b);
      const spans = [];
      for (let index = 0; index + 1 < intersections.length; index += 2) {
        const left = Math.max(4, intersections[index]), right = Math.min(canvas.width - 4, intersections[index + 1]);
        if (right > left) spans.push([left, right]);
      }
      return spans;
    };
    let best = null;
    for (let step = 0; step <= 20; step += 1) {
      const y = minY + (maxY - minY) * step / 20, centerSpans = spansAt(y), upperSpans = spansAt(y - 8), lowerSpans = spansAt(y + 8);
      centerSpans.forEach(center => upperSpans.forEach(upper => lowerSpans.forEach(lower => {
        const left = Math.max(center[0], upper[0], lower[0]), right = Math.min(center[1], upper[1], lower[1]), width = right - left;
        if (width > 0 && (!best || width > best.width)) best = {x: (left + right) / 2, y, width, height: 16, area: width * 16};
      })));
    }
    return best;
  }

  function drawStateBoundaries(target, project, showLabels = false) {
    if (!stateBoundaries?.length) return false;
    const stateLabels = [];
    stateBoundaries.forEach((state, stateIndex) => {
      let bestLabelArea = null;
      target.beginPath();
      (state.polygons || []).forEach(polygon => {
        [polygon.outer, ...(polygon.holes || [])].forEach(ring => {
          if (!Array.isArray(ring) || ring.length < 4) return;
          for (let index = 0; index + 1 < ring.length; index += 2) { const [x, y] = project([ring[index], ring[index + 1]]); if (index === 0) target.moveTo(x, y); else target.lineTo(x, y); }
          target.closePath();
        });
        const labelArea = showLabels ? stateLabelArea(polygon, project, target.canvas) : null;
        if (labelArea && (!bestLabelArea || labelArea.area > bestLabelArea.area)) bestLabelArea = labelArea;
      });
      target.fillStyle = stateIndex % 2 === 0 ? '#18231e' : '#1d2923'; target.fill('evenodd');
      target.strokeStyle = 'rgba(174,194,183,.52)'; target.lineWidth = 1.15; target.stroke();
      if (showLabels && bestLabelArea && bestLabelArea.width >= 48) stateLabels.push({...bestLabelArea, name: state.name});
    });
    if (showLabels) {
      target.font = 'bold 13px sans-serif'; target.textAlign = 'center'; target.textBaseline = 'middle';
      stateLabels.forEach(label => { if (target.measureText(label.name).width > label.width - 8) return; target.fillStyle = 'rgba(222,232,226,.56)'; target.fillText(label.name, label.x, label.y); });
    }
    return true;
  }

  function drawSummaryLocations(target, decoded, project, occupiedEvents = []) {
    const nodes = [], seen = new Set(), labels = [], routeLines = decoded.map(({points}) => points.map(project));
    const routeCrosses = box => routeLines.some(line => line.some((point, index) => {
      if (!index) return false;
      const previous = line[index - 1], distance = Math.hypot(point[0] - previous[0], point[1] - previous[1]), steps = Math.max(1, Math.ceil(distance / 5));
      for (let step = 0; step <= steps; step += 1) { const share = step / steps, x = previous[0] + (point[0] - previous[0]) * share, y = previous[1] + (point[1] - previous[1]) * share; if (x >= box.x - 7 && x <= box.x + box.width + 7 && y >= box.y - 7 && y <= box.y + box.height + 7) return true; }
      return false;
    }));
    decoded.forEach(({segment, points}) => {
      [[segment.start, points[0]], [segment.end, points[points.length - 1]]].forEach(([node, point]) => {
        if (!node || seen.has(node)) return;
        seen.add(node); nodes.push({node, point});
      });
    });
    target.font = 'bold 14px sans-serif'; target.textBaseline = 'middle';
    nodes.forEach(({node, point}, index) => {
      const [routeX, routeY] = project(point), text = locationName(node), width = Math.ceil(target.measureText(text).width) + 14, height = 26;
      const above = index % 2 === 0;
      const offsets = above ? [[0,-42],[0,16],[0,-72],[0,46],[0,-102],[0,76],[width / 2 + 22,-13],[-width / 2 - 22,-13]] : [[0,16],[0,-42],[0,46],[0,-72],[0,76],[0,-102],[width / 2 + 22,-13],[-width / 2 - 22,-13]];
      let box = null;
      for (const [offsetX, offsetY] of offsets) {
        const candidate = {x: Math.max(4, Math.min(target.canvas.width - width - 4, routeX - width / 2 + offsetX)), y: Math.max(4, Math.min(target.canvas.height - height - 4, routeY + offsetY)), width, height};
        const avoidsLabels = labels.every(label => candidate.x + candidate.width + 5 < label.x || label.x + label.width + 5 < candidate.x || candidate.y + candidate.height + 5 < label.y || label.y + label.height + 5 < candidate.y);
        const avoidsEvents = occupiedEvents.every(event => event.x < candidate.x - 15 || event.x > candidate.x + candidate.width + 15 || event.y < candidate.y - 15 || event.y > candidate.y + candidate.height + 15);
        if (avoidsLabels && avoidsEvents && !routeCrosses(candidate)) { box = candidate; break; }
      }
      box ||= {x: Math.max(4, Math.min(target.canvas.width - width - 4, routeX - width / 2)), y: Math.max(4, Math.min(target.canvas.height - height - 4, routeY + 16)), width, height};
      labels.push(box);
      const labelCenterX = box.x + box.width / 2, labelCenterY = box.y + box.height / 2;
      target.strokeStyle = 'rgba(255,255,255,.65)'; target.lineWidth = 1; target.beginPath(); target.moveTo(routeX, routeY); target.lineTo(labelCenterX, labelCenterY); target.stroke();
      target.fillStyle = 'rgba(16,23,19,.94)'; target.strokeStyle = 'rgba(255,255,255,.65)'; target.lineWidth = 1; target.beginPath(); target.roundRect(box.x, box.y, box.width, box.height, 4); target.fill(); target.stroke();
      target.fillStyle = '#fff'; target.textAlign = 'center'; target.fillText(text, labelCenterX, labelCenterY + .5);
      target.fillStyle = '#65c99d'; target.strokeStyle = '#fff'; target.lineWidth = 2; target.beginPath(); target.arc(routeX, routeY, 5, 0, Math.PI * 2); target.fill(); target.stroke();
    });
  }

  function paintMapBackground(target) {
    target.fillStyle = '#101713'; target.fillRect(0, 0, target.canvas.width, target.canvas.height);
    target.strokeStyle = 'rgba(255,255,255,.06)'; target.lineWidth = 1;
    for (let x = 0; x < target.canvas.width; x += 50) { target.beginPath(); target.moveTo(x, 0); target.lineTo(x, target.canvas.height); target.stroke(); }
    for (let y = 0; y < target.canvas.height; y += 50) { target.beginPath(); target.moveTo(0, y); target.lineTo(target.canvas.width, y); target.stroke(); }
  }

  function liveMapLayer(segment) {
    const key = segment.polyline;
    if (liveMapLayers.has(key)) return liveMapLayers.get(key);
    const points = decodePolyline(segment.polyline);
    if (points.length < 2) return null;
    const background = document.createElement('canvas'); background.width = canvas.width; background.height = canvas.height;
    const backgroundContext = background.getContext('2d'), project = routeProjection(background, points, 55);
    paintMapBackground(backgroundContext); drawStateBoundaries(backgroundContext, project, true);
    const layer = {background, points, project}; liveMapLayers.set(key, layer);
    if (liveMapLayers.size > 12) liveMapLayers.delete(liveMapLayers.keys().next().value);
    return layer;
  }

  function drawMap(segment, showVehicle = true) {
    if (!segment || !segment.polyline) { paintMapBackground(context); context.fillStyle = '#aab8b0'; context.font = '24px sans-serif'; context.textAlign = 'center'; context.fillText(segment ? 'Route geometry unavailable' : 'Choose an onward segment', canvas.width / 2, canvas.height / 2); return; }
    const layer = liveMapLayer(segment);
    if (!layer) return;
    const {background, points, project} = layer; context.clearRect(0, 0, canvas.width, canvas.height); context.drawImage(background, 0, 0);
    strokeRange(context, points, project, 0, 1, '#66706b');
    (segment.speed_trace || []).forEach(band => strokeRange(context, points, project, Number(band.from), Number(band.to), speedColor(band.ratio)));
    drawMapEvents(context, points, project, segment.map_events, -25);
    if (!showVehicle) return;
    const fraction = Math.max(0, Math.min(1, segment.progress_fraction ?? (segment.elapsed_seconds / Math.max(1, segment.total_seconds)))), position = fraction * (points.length - 1), low = Math.floor(position), share = position - low;
    const a = project(points[low]), b = project(points[Math.min(points.length - 1, low + 1)]), x = a[0] + (b[0] - a[0]) * share, y = a[1] + (b[1] - a[1]) * share;
    context.fillStyle = '#fff'; context.beginPath(); context.arc(x, y, 11, 0, Math.PI * 2); context.fill(); context.fillStyle = '#e93d32'; context.beginPath(); context.arc(x, y, 7, 0, Math.PI * 2); context.fill();
  }

  function renderLiveMap(state) {
    const segments = [...(state.traveled_segments || [])];
    if (state.current_segment?.polyline) segments.push(state.current_segment);
    const latestIndex = segments.length - 1;
    if (mapHistoryIndex !== null) mapHistoryIndex = Math.max(0, Math.min(latestIndex, mapHistoryIndex));
    const selectedIndex = mapHistoryIndex === null ? latestIndex : mapHistoryIndex, segment = segments[selectedIndex] || null, active = Boolean(state.current_segment) && selectedIndex === latestIndex;
    document.getElementById('map-segment-previous').disabled = selectedIndex <= 0;
    document.getElementById('map-segment-next').disabled = selectedIndex >= latestIndex;
    if (!segment) { document.querySelector('.simulation-map-status').hidden = true; drawMap(null); return; }
    document.getElementById('simulation-location').textContent = routeName(segment.name || `${segment.start}_to_${segment.end}`);
    if (!active) document.getElementById('simulation-traffic').textContent = `Completed segment ${selectedIndex + 1} of ${segments.length}`;
    document.querySelector('.simulation-map-status').hidden = !active;
    drawMap(segment, active);
  }

  function drawSummaryMap(state) {
    if (!summaryCanvas) return;
    const target = summaryCanvas.getContext('2d'), segments = [...(state.traveled_segments || [])];
    if (state.current_segment?.polyline) segments.push(state.current_segment);
    const decoded = segments.filter(segment => segment.polyline).map(segment => ({segment, points: decodePolyline(segment.polyline)})).filter(item => item.points.length > 1);
    target.fillStyle = '#101713'; target.fillRect(0, 0, summaryCanvas.width, summaryCanvas.height);
    if (!decoded.length) { target.fillStyle = '#aab8b0'; target.font = '24px sans-serif'; target.textAlign = 'center'; target.fillText('Route geometry unavailable', summaryCanvas.width / 2, summaryCanvas.height / 2); return; }
    const project = routeProjection(summaryCanvas, decoded.flatMap(item => item.points), 36);
    drawStateBoundaries(target, project);
    decoded.forEach(({points}) => strokeRange(target, points, project, 0, 1, '#66706b', 7));
    decoded.forEach(({segment, points}) => (segment.speed_trace || []).forEach(band => strokeRange(target, points, project, Number(band.from), Number(band.to), speedColor(band.ratio), 7)));
    const occupiedEvents = [];
    decoded.forEach(({segment, points}) => drawMapEvents(target, points, project, segment.map_events, -17, occupiedEvents));
    drawSummaryLocations(target, decoded, project, occupiedEvents);
  }

  const eventText = (type, payload) => ({simulation_created: `Simulation created in ${payload.mode} mode`, segment_entered: `The crew began the journey from ${routeName(payload.segment)}`, traffic_delay: `Observed traffic encountered; speed limited to ${payload.speed_cap} mph`, traffic_cap_lifted: `Observed traffic cleared; returning to ${payload.speed} mph`, invented_traffic: payload.bypassed ? `Police Camo cleared a ${payload.blocker} without slowing` : `The driver was unable to read the traffic and got stuck behind a ${payload.blocker}`, invented_traffic_cleared: `The crew cleared the ${payload.blocker} after ${payload.miles} miles`, weather_event: payload.weather === 'tail_wind' ? 'Tail wind improved fuel economy by 15%' : payload.weather === 'head_wind' ? 'Head wind reduced fuel economy by 15%' : `${String(payload.weather || 'weather').replace(/^./, letter => letter.toUpperCase())}; speed limited to ${payload.speed_cap} mph`, weather_cleared: `${String(payload.weather || 'Weather').replaceAll('_', ' ').replace(/^./, letter => letter.toUpperCase())} cleared`, crash_event: 'Crash ended the run', police_event: payload.countermeasure_outcome === 'bypass' ? 'Counter measures bypassed a police encounter' : payload.countermeasure_outcome === 'slowdown' ? `Counter measures cleared police with ${duration(payload.delay_seconds)} lost` : payload.jailed ? 'Police pulled the crew over' : 'Police stop added 30 minutes', road_event: `Road obstacle${payload.mitigated ? ' mitigated' : ''} +${duration(payload.delay_seconds)}`, flat_tire: payload.ends_run ? 'Flat tire ended the run: no spare remained' : `Flat tire: ${duration(payload.service_seconds)} repair, ${duration(payload.delay_seconds)} total, ${payload.spares_remaining} spare${Number(payload.spares_remaining) === 1 ? '' : 's'} remaining`, flat_tire_ended_run: 'Flat tire ended the run', mechanical_failure: payload.ends_run ? 'Terminal mechanical failure ended the run' : `Mechanical failure: ${duration(payload.service_seconds)} repair, ${duration(payload.delay_seconds)} total`, mechanical_failure_ended_run: 'Mechanical failure ended the run', segment_completed: `Arrived at ${locationName(payload.node)}`, route_choice_required: `Route choice required at ${locationName(payload.node)}`, fatigue_threshold: `${payload.driver} fatigue reached ${payload.fatigue}%`, driver_exhausted: `${payload.driver} reached full fatigue`, copilot_exhausted: `${payload.driver} must rest`, roadside_rest_started: 'The crew pulled over to rest', roadside_rest_ended: `Roadside rest ended after ${duration(payload.seconds)}`, driver_change_requested: 'Driver selection opened', driver_change_cancelled: 'Driver change cancelled', driver_changed: `${payload.driver} took the wheel`, driver_change_complete: payload.during_fuel ? 'Driver changed during fuel stop with no added time' : `Driver change completed in ${duration(payload.seconds)}`, copilot_changed: payload.copilot ? `${payload.copilot} assigned as co-pilot` : 'Co-pilot role cleared', target_speed_changed: `Target speed set to ${payload.speed} mph`, fuel_warning: `Fuel below ${payload.percent}%`, fuel_stop_requested: 'The crew is looking for the next suitable gas station', fuel_minigame_completed: 'The crew completed the fueling checklist', fuel_stop: `Fuel stop: ${payload.gallons} gal, ${duration(payload.seconds)}`, consumable_used: payload.consumable === 'snack' ? `${payload.driver} ate a snack; fatigue reduced ${Number(payload.fatigue_reduction || 0).toFixed(1)}%; ${payload.remaining} remain` : Number(payload.driving_penalty) > 0 ? `${payload.driver} drank RedBull ${payload.dose}; The jitters reduce driving skill by ${payload.driving_penalty} points until 50 fatigue points are recovered` : `${payload.driver} drank a RedBull; ${payload.remaining} remain`, consumable_rejected: payload.consumable === 'snack' ? `${payload.driver} was too full for another snack` : `${payload.driver} declined a fifth RedBull`, consumable_wore_off: payload.consumable === 'redbull_penalty' ? `The jitters cleared; ${payload.driver} recovered ${payload.driving_penalty} driving points` : `${payload.driver}'s RedBull wore off`, paused: 'Simulation paused', resumed: 'Simulation resumed', event_acknowledged: payload.event === 'invented_traffic' ? 'Crew responded to the traffic obstruction' : payload.event ? `Crew responded to ${String(payload.event).replaceAll('_', ' ')}` : 'Crew responded to obstacle', crew_jailed: 'Crew taken to jail. Run ended.', out_of_fuel: 'Fuel exhausted. Run ended.', traffic_unavailable: `Run ended: ${payload.message}`, arrived_portofino: 'Arrived at Portofino Marina, CA'}[type] || String(type).replaceAll('_', ' '));
  const displayEventText = (type, payload) => type === 'wildlife_event' ? (payload.thermal_camera ? 'Thermal Camera detected wildlife; speed limited to 65 mph for 1 mile' : 'Wildlife collision ended the run') : type === 'wildlife_cleared' ? `Wildlife hazard cleared; returning to ${payload.speed} mph` : eventText(type, payload);

  function addEvents(events) {
    const list = document.getElementById('event-log');
    events.forEach(event => { lastEvent = Math.max(lastEvent, Number(event.id)); if (seenEvents.has(event.id)) return; seenEvents.add(event.id); const row = document.createElement('li'), time = document.createElement('time'), icon = document.createElement('span'), text = document.createElement('span'), payload = event.payload || {}; time.textContent = fmt.format(new Date(event.simulated_at)); icon.className = 'event-log-icon'; icon.textContent = eventSymbol(event.type, payload); icon.setAttribute('aria-hidden', 'true'); text.textContent = event.type === 'weather_cleared' ? `${displayEventText(event.type, payload)} after ${Number(payload.weather_miles).toFixed(1)} miles` : displayEventText(event.type, payload); row.append(time, icon, text); list.prepend(row); });
  }

  function consumableFlavor(pending) {
    const variant = Math.abs(Number(pending.flavor_variant) || 0) % 5;
    let key = pending.event;
    if (pending.event === 'redbull_used') key = Number(pending.dose) === 3 ? 'redbull_three' : Number(pending.dose) === 4 ? 'redbull_four' : 'redbull_used';
    const flavor = {
      redbull_used: ['The can opens with the sound of questionable confidence.', 'The cooler contributes another cold can to the cause.', 'A metallic click announces that sleep has lost this round.', 'The crew watches another set of borrowed wings deploy.', 'The road gets longer; the can gets emptier.'],
      redbull_three: ['The driver now appears to be steering between heartbeats.', 'One hand takes the can. The other begins negotiating with the steering wheel.', 'The driver insists the dashboard is blinking first.', 'The cabin gains the energy of a drum solo in a metal trash can.', 'The driver can suddenly hear the lane markings.'],
      redbull_four: ['The driver has achieved a deeply concerning relationship with time.', 'The can opens and every loose object in the cabin starts vibrating in sympathy.', 'The driver reports that colors now have opinions.', 'The crew has crossed from alert into experimentally awake.', 'The steering wheel is no longer being held; it is being interrogated.'],
      redbull_rejected: ['The heart has formally declined the assignment.', 'The can is returned before the driver begins seeing through time.', 'Someone in the car remembers that humans have operating limits.', 'The driver considers can number five and chooses continued existence.', 'The cooler keeps this one. The driver has had enough borrowed wings.'],
      redbull_wore_off: ['Gravity has submitted its invoice.', 'The borrowed wings quietly clock out.', 'The caffeine tide rolls back out to sea.', 'The last artificial spark fades from the dashboard glow.', 'The driver returns to ordinary human operating voltage.'],
      redbull_penalty_cleared: ['The shaking finally leaves the steering wheel.', 'Fine motor control has rejoined the crew.', 'The driver can once again point at a lane without drawing a circle.', 'The caffeine static clears and steady hands return.', 'The driver stops vibrating faster than the car.'],
      snack_used: ['The bag opens and morale immediately improves.', 'Road nutrition has arrived in its most scientifically crunchy form.', 'A strategic handful disappears before anyone can count it.', 'The crew executes a flawless snack maneuver.', 'The cabin observes a brief but effective crunch break.'],
      snack_rejected: ['The stomach has posted a NO VACANCY sign.', 'One more bite would require a separate cargo manifest.', 'The snack bag approaches; the crew member waves it off with dignity.', 'The spirit is willing, but the snack compartment is full.', 'The crew member wisely avoids becoming part of the vehicle load calculation.']
    }[key]?.[variant] || '';
    if (key === 'redbull_used') return `${flavor} ${pending.driver} gains two in-game hours without fatigue gain. ${pending.remaining} can${Number(pending.remaining) === 1 ? ' remains' : 's remain'}.`;
    if (key === 'redbull_three') return `${flavor} ${pending.driver} gains two in-game hours without fatigue gain, but The jitters reduce driving skill by 10 points until 50 fatigue points are recovered.`;
    if (key === 'redbull_four') return `${flavor} ${pending.driver} gains two in-game hours without fatigue gain, but The jitters reduce driving skill by another 20 points until 50 fatigue points are recovered.`;
    if (key === 'redbull_rejected') return `${flavor} No RedBull was consumed.`;
    if (key === 'redbull_wore_off') return `${flavor} ${pending.driver} can feel fatigue building normally again.`;
    if (key === 'redbull_penalty_cleared') return `${flavor} The jitters clear and ${pending.driver} regains ${pending.driving_penalty} driving-skill points.`;
    if (key === 'snack_used') return `${flavor} ${pending.driver}'s fatigue drops by ${Number(pending.fatigue_reduction || 0).toFixed(1)}%. ${pending.remaining} snack${Number(pending.remaining) === 1 ? ' remains' : 's remain'}.`;
    return `${flavor} No snack was consumed.`;
  }

  function obstacleFlavor(pending) {
    const variant = Math.abs(Number(pending.flavor_variant) || 0) % 5;
    const lines = {
      weather: ['The horizon has opinions about this run.', 'The sky just changed the rules of the road.', 'Nature has entered the chat without asking.', 'The forecast has become an active participant.', 'The road ahead has acquired a weather problem.'],
      weather_clear: ['The horizon finally gives the road back.', 'Nature gets bored and moves on.', 'The windshield view returns to normal programming.', 'The weather releases its grip on the route.', 'The atmosphere signs off from this segment.'],
      crash: ['Physics wins the argument.', 'The road collects a brutal final payment.', 'One impossible moment ends the charge west.', 'The machine and the road have reached a terminal disagreement.', 'The run ends in twisted metal and immediate silence.'],
      police_jail: ['The lights stay close all the way to the shoulder.', 'The officer is not in a warning-ticket mood.', 'The roadside conversation takes a catastrophic turn.', 'The badge, the speed, and the evidence all arrive at once.', 'The crew has found the one escort that does not go to Portofino.'],
      police_release: ['The crew catches the narrowest possible break.', 'The officer chooses paperwork over handcuffs.', 'Charm, luck, or cosmic clerical error keeps the run alive.', 'The shoulder meeting ends with keys still in crew hands.', 'The flashing lights finally disappear in the mirror.'],
      police_slowdown: ['The warning arrives just early enough.', 'The crew disappears into ordinary traffic at the last second.', 'Speed drops, profiles shrink, and the patrol moves on.', 'The countermeasure earns its keep in a tense few minutes.', 'The driver trades speed for invisibility before the trap closes.'],
      police_bypass: ['The patrol never realizes what just passed.', 'The countermeasure paints a clean hole through the net.', 'The lights remain someone else\'s problem.', 'The crew slips past before suspicion becomes pursuit.', 'The police event ends before it properly begins.'],
      mechanical_end: ['The engine answers with one final metallic complaint.', 'Every warning light votes to stop immediately.', 'The machine has converted itself into stationary scenery.', 'Something expensive has become something terminal.', 'The car chooses this exact mile to resign.'],
      mechanical_repair: ['The dashboard lights up like a hostile holiday display.', 'A noise from under the hood requests immediate negotiations.', 'The machine develops an urgent roadside opinion.', 'Something mechanical attempts to leave the group project.', 'The car demands tools, patience, and several unfriendly words.'],
      flat_end: ['The final usable tire has left the chat.', 'Rubber supply reaches zero at the worst possible moment.', 'The road claims another tire, and the trunk has no answer.', 'The crew has tools but nothing round left to install.', 'The last spare is already somewhere behind the car.'],
      flat_repair: ['That sound was exactly as expensive as it seemed.', 'One tire abruptly decides it has driven enough.', 'The pressure gauge delivers a deeply unhelpful zero.', 'The road finds the weak point in the rubber.', 'A tire surrenders with theatrical timing.'],
      road: ['The road produces an unscheduled complication.', 'A fresh obstacle appears directly in the crew\'s plans.', 'The route adds another item to the problem list.', 'The highway has prepared a surprise examination.', 'The crew encounters something the itinerary forgot to mention.'],
      wildlife_end: ['The darkness moves, and there is no room left to react.', 'An animal enters the lane at the worst possible instant.', 'The headlights find wildlife one heartbeat too late.', 'The road becomes occupied before the driver can answer.', 'A shape crosses the beam and ends the run.'],
      wildlife_saved: ['The thermal image lights up before the headlights do.', 'A warm shape appears on the camera with time to react.', 'The camera catches movement hiding beyond visible range.', 'Infrared vision finds an animal before the bumper does.', 'The thermal display turns a collision into a controlled slowdown.']
    };
    const pick = key => lines[key][variant];
    if (pending.event === 'weather_event') {
      if (pending.weather === 'tail_wind') return `${pick('weather')} A tail wind improves fuel economy by 15% until conditions change.`;
      if (pending.weather === 'head_wind') return `${pick('weather')} A head wind reduces fuel economy by 15% until conditions change.`;
      return `${pick('weather')} ${String(pending.weather || 'Weather').replaceAll('_', ' ')} limits the vehicle to ${pending.speed_cap} mph until conditions improve.`;
    }
    if (pending.event === 'weather_cleared') return `${pick('weather_clear')} ${String(pending.weather || 'Weather').replaceAll('_', ' ')} clears after ${Number(pending.weather_miles || 0).toFixed(1)} miles.`;
    if (pending.event === 'crash_event') return `${pick('crash')} The run is over before Portofino.`;
    if (pending.event === 'wildlife_event') return pending.ends_run ? `${pick('wildlife_end')} The collision ends the run.` : `${pick('wildlife_saved')} The Thermal Camera limits the vehicle to 65 mph for one mile while the animal clears the road.`;
    if (pending.event === 'flat_tire') return pending.ends_run ? `${pick('flat_end')} The run is over.` : `${pick('flat_repair')} The crew needs ${duration(pending.service_seconds)} for the repair plus deceleration and acceleration. ${duration(pending.delay_seconds)} is added to the run; ${pending.spares_remaining} spare${Number(pending.spares_remaining) === 1 ? ' remains' : 's remain'}.`;
    if (pending.event === 'mechanical_failure') return pending.ends_run ? `${pick('mechanical_end')} The failure cannot be repaired on the road, so the run is over.` : `${pick('mechanical_repair')} Repairs and speed transitions add ${duration(pending.delay_seconds)} to the run.`;
    if (pending.event === 'road_event') return `${pick('road')} ${pending.mitigated ? 'The crew softens the impact, but ' : ''}${duration(pending.delay_seconds)} is added to the run.`;
    if (pending.countermeasure_outcome === 'bypass') return `${pick('police_bypass')} The jammer sends suspicion elsewhere, and no time is lost.`;
    if (pending.countermeasure_outcome === 'slowdown') { const method = pending.countermeasure === 'radio_scanner' ? 'The radio scanner catches the warning early enough to blend into traffic.' : 'The jammer creates enough uncertainty to disappear into ordinary traffic.'; return `${pick('police_slowdown')} ${method} ${duration(pending.delay_seconds)} is added to the run.`; }
    if (pending.jailed) {
      const reason = pending.police_camo ? 'The undercover disguise eliminates any chance of being released.' : pending.countermeasure === 'radar_jammer' ? 'The jammer is discovered, eliminating any chance of release.' : pending.countermeasure === 'radio_scanner' ? 'The scanner misses the warning, and the stop ends in jail instead of Portofino.' : 'The stop ends in jail instead of Portofino.';
      return `${pick('police_jail')} ${reason} The run is over.`;
    }
    return `${pick('police_release')} ${pending.countermeasure === 'radio_scanner' ? 'The scanner missed this one, but the crew is released.' : 'The crew is released.'} The stop adds 30 minutes to the run.`;
  }

  const fuelLayout = {wallet:{x:42,y:340,w:205,h:132},reader:{x:720,y:115,w:195,h:155},car:{x:265,y:300,w:330,h:145},inlet:{x:535,y:350,r:27},nozzleHome:{x:850,y:345,r:30}};
  const fuelButtons = [{value:1,x:765,y:180},{value:2,x:855,y:180},{value:3,x:765,y:240},{value:4,x:855,y:240}];
  const inside = (point, box) => point.x >= box.x && point.x <= box.x + box.w && point.y >= box.y && point.y <= box.y + box.h;
  const fuelPoint = event => { const bounds = fuelGameCanvas.getBoundingClientRect(); return {x:(event.clientX - bounds.left) * fuelGameCanvas.width / bounds.width,y:(event.clientY - bounds.top) * fuelGameCanvas.height / bounds.height}; };
  function fuelCardPosition(phase) { if (fuelDrag?.kind === 'card') return fuelDrag.position; return phase === 'return_card' ? {x:762,y:172} : {x:88,y:382}; }
  function fuelNozzlePosition(phase) {
    if (fuelDrag?.kind === 'nozzle') return fuelDrag.position;
    if (fuelPendingStep === 'nozzle_car') return {x:520,y:335};
    if (fuelPendingStep === 'nozzle_pump') return {x:835,y:323};
    return ['fueling','return_nozzle'].includes(phase) ? {x:520,y:335} : {x:835,y:323};
  }

  function drawFuelMinigame() {
    if (!fuelGameState || fuelGameCanvas.hidden) return;
    const pending = fuelGameState, phase = pending.phase, target = fuelGameContext, vehicle = latestState?.vehicle || {};
    target.clearRect(0,0,fuelGameCanvas.width,fuelGameCanvas.height);target.fillStyle='#101713';target.fillRect(0,0,fuelGameCanvas.width,fuelGameCanvas.height);
    target.fillStyle='#dce8e1';target.font='700 25px sans-serif';target.textAlign='left';target.fillText('Fuel Stop',36,42);target.fillStyle='#9fb0a7';target.font='15px sans-serif';
    const instructions={card_to_reader:'Drag the payment card from the wallet to the pump reader.',payment_prompt:'Enter the authorization sequence on the pump.',return_card:'Return the payment card to the wallet.',connect_nozzle:'Drag the fuel nozzle to the car.',fueling:'Fueling in progress...',return_nozzle:'Return the nozzle to the pump.'};target.fillText(instructions[phase]||'Complete the fuel stop.',36,68);
    target.fillStyle='#25322c';target.strokeStyle='#73857b';target.lineWidth=3;target.beginPath();target.roundRect(fuelLayout.wallet.x,fuelLayout.wallet.y,fuelLayout.wallet.w,fuelLayout.wallet.h,12);target.fill();target.stroke();target.fillStyle='#dce8e1';target.font='700 16px sans-serif';target.fillText('WALLET',fuelLayout.wallet.x+18,fuelLayout.wallet.y+28);
    target.fillStyle='#26312d';target.strokeStyle='#82948a';target.beginPath();target.roundRect(675,72,278,410,12);target.fill();target.stroke();target.fillStyle='#dce8e1';target.font='800 19px sans-serif';target.fillText('FUEL',700,104);
    target.fillStyle='#0b100e';target.strokeStyle='#65756c';target.beginPath();target.roundRect(fuelLayout.reader.x,fuelLayout.reader.y,fuelLayout.reader.w,fuelLayout.reader.h,7);target.fill();target.stroke();
    target.fillStyle='#65c99d';target.font='700 14px monospace';target.fillText(phase==='payment_prompt'?(pending.mistake?'TRY AGAIN':'ENTER SEQUENCE'):'CARD READER',fuelLayout.reader.x+15,fuelLayout.reader.y+28);
    target.fillStyle='#303b36';target.strokeStyle='#8da097';target.beginPath();target.roundRect(fuelLayout.car.x,fuelLayout.car.y,fuelLayout.car.w,fuelLayout.car.h,28);target.fill();target.stroke();
    const vehicleImage=document.getElementById('sim-vehicle-image');if(vehicleImage&&!vehicleImage.hidden&&vehicleImage.complete&&vehicleImage.naturalWidth){const scale=Math.min(250/vehicleImage.naturalWidth,125/vehicleImage.naturalHeight);const width=vehicleImage.naturalWidth*scale,height=vehicleImage.naturalHeight*scale;target.drawImage(vehicleImage,fuelLayout.car.x+(fuelLayout.car.w-width)/2,fuelLayout.car.y+(fuelLayout.car.h-height)/2,width,height);}else{target.fillStyle='#dce8e1';target.font='72px sans-serif';target.textAlign='center';target.fillText('\u{1f697}',fuelLayout.car.x+fuelLayout.car.w/2,fuelLayout.car.y+92);target.textAlign='left';}
    target.fillStyle='#101713';target.strokeStyle='#65c99d';target.lineWidth=4;target.beginPath();target.arc(fuelLayout.inlet.x,fuelLayout.inlet.y,fuelLayout.inlet.r,0,Math.PI*2);target.fill();target.stroke();
    target.strokeStyle='#596860';target.lineWidth=9;target.beginPath();target.moveTo(925,330);const nozzlePosition=fuelNozzlePosition(phase);target.bezierCurveTo(975,430,930,475,nozzlePosition.x+76,nozzlePosition.y+20);target.stroke();
    if(['card_to_reader','return_card'].includes(phase)){const card=fuelCardPosition(phase);target.fillStyle='#e9d26c';target.strokeStyle='#171c19';target.lineWidth=2;target.beginPath();target.roundRect(card.x,card.y,112,62,8);target.fill();target.stroke();target.fillStyle='#171c19';target.fillRect(card.x+14,card.y+17,24,18);target.font='700 12px sans-serif';target.fillText('CREW CARD',card.x+14,card.y+51);}
    if(phase==='payment_prompt'){const sequence=(pending.qte_sequence||[]),index=Number(pending.qte_index||0);target.textAlign='center';target.font='700 17px sans-serif';sequence.forEach((value,position)=>{target.fillStyle=position<index?'#65c99d':position===index?'#fff':'#75847c';target.fillText(String(value),785+position*28,300);});fuelButtons.forEach(button=>{target.fillStyle='#d7e0db';target.strokeStyle='#65756c';target.lineWidth=2;target.beginPath();target.arc(button.x,button.y,23,0,Math.PI*2);target.fill();target.stroke();target.fillStyle='#111713';target.font='800 16px sans-serif';target.fillText(String(button.value),button.x,button.y+5);});target.textAlign='left';}
    if(['connect_nozzle','fueling','return_nozzle'].includes(phase)){const nozzle=fuelNozzlePosition(phase);target.save();target.translate(nozzle.x,nozzle.y);target.fillStyle='#e0b52e';target.strokeStyle='#141a17';target.lineWidth=3;target.beginPath();target.roundRect(20,0,68,30,7);target.fill();target.stroke();target.fillStyle='#a7b3ad';target.fillRect(0,9,31,9);target.restore();}
    if(phase==='fueling'){const progress=Math.max(0,Math.min(1,(Date.now()-fuelingStartedAt)/6500));target.fillStyle='#27332d';target.fillRect(335,455,330,22);target.fillStyle='#65c99d';target.fillRect(335,455,330*progress,22);target.strokeStyle='#93a49b';target.strokeRect(335,455,330,22);target.fillStyle='#dce8e1';target.textAlign='center';target.font='700 14px sans-serif';target.fillText(`${(progress*100).toFixed(0)}%`,500,472);target.fillStyle='#9fb0a7';target.font='13px sans-serif';target.fillText(`${Number(pending.gallons||0).toFixed(1)} gallons at ${Number(pending.gpm||0).toFixed(1)} GPM`,500,500);target.textAlign='left';if(progress>=1&&!fuelCompletionSent){fuelCompletionSent=true;if(canControl)act('fuel_minigame',{step:'fueling_complete'});}else if(progress<1){cancelAnimationFrame(fuelAnimationFrame);fuelAnimationFrame=requestAnimationFrame(drawFuelMinigame);}}
  }

  function renderFuelMinigame(state) {
    const pending=state.pending_decision;
    if(pending?.type!=='fuel_game'){fuelGameState=null;fuelDrag=null;fuelCompletionSent=false;cancelAnimationFrame(fuelAnimationFrame);fuelGameCanvas.hidden=true;renderTireMinigame(state);return;}
    const phaseChanged=fuelGameState?.phase!==pending.phase;fuelGameState=pending;fuelGameCanvas.hidden=false;document.querySelector('.simulation-map-status').hidden=true;if(phaseChanged){fuelDrag=null;fuelCompletionSent=false;if(pending.phase==='fueling')fuelingStartedAt=Date.now();}drawFuelMinigame();renderTireMinigame(state);
  }

  async function fuelAction(step,value) { if(fuelInteractionPending)return;fuelInteractionPending=true;fuelPendingStep=step;try{await act('fuel_minigame',{step,value:value??''});}finally{fuelInteractionPending=false;fuelPendingStep=null;drawFuelMinigame();} }

  fuelGameCanvas.addEventListener('pointerdown',event=>{
    if(!canControl||!fuelGameState||fuelInteractionPending)return;const point=fuelPoint(event),phase=fuelGameState.phase;
    if(phase==='payment_prompt'){const button=fuelButtons.find(item=>Math.hypot(point.x-item.x,point.y-item.y)<=27);if(button)fuelAction('payment_button',button.value);return;}
    if(['card_to_reader','return_card'].includes(phase)){const position=fuelCardPosition(phase),box={x:position.x,y:position.y,w:112,h:62};if(inside(point,box)){fuelDrag={kind:'card',position:{...position},offset:{x:point.x-position.x,y:point.y-position.y}};fuelGameCanvas.setPointerCapture(event.pointerId);}}
    if(['connect_nozzle','return_nozzle'].includes(phase)){const position=fuelNozzlePosition(phase),box={x:position.x,y:position.y,w:90,h:40};if(inside(point,box)){fuelDrag={kind:'nozzle',position:{...position},offset:{x:point.x-position.x,y:point.y-position.y}};fuelGameCanvas.setPointerCapture(event.pointerId);}}
  });
  fuelGameCanvas.addEventListener('pointermove',event=>{if(!fuelDrag)return;const point=fuelPoint(event);fuelDrag.position={x:Math.max(0,Math.min(fuelGameCanvas.width-(fuelDrag.kind==='card'?112:90),point.x-fuelDrag.offset.x)),y:Math.max(0,Math.min(fuelGameCanvas.height-(fuelDrag.kind==='card'?62:40),point.y-fuelDrag.offset.y))};drawFuelMinigame();});
  fuelGameCanvas.addEventListener('pointerup',event=>{
    if(!fuelDrag||!fuelGameState)return;const point=fuelPoint(event),phase=fuelGameState.phase,kind=fuelDrag.kind;fuelDrag=null;if(fuelGameCanvas.hasPointerCapture(event.pointerId))fuelGameCanvas.releasePointerCapture(event.pointerId);
    if(kind==='card'&&phase==='card_to_reader'&&inside(point,fuelLayout.reader))fuelAction('card_reader');
    else if(kind==='card'&&phase==='return_card'&&inside(point,fuelLayout.wallet))fuelAction('card_wallet');
    else if(kind==='nozzle'&&phase==='connect_nozzle'&&Math.hypot(point.x-fuelLayout.inlet.x,point.y-fuelLayout.inlet.y)<=55)fuelAction('nozzle_car');
    else if(kind==='nozzle'&&phase==='return_nozzle'&&Math.hypot(point.x-fuelLayout.nozzleHome.x,point.y-fuelLayout.nozzleHome.y)<=70)fuelAction('nozzle_pump');
    drawFuelMinigame();
  });
  fuelGameCanvas.addEventListener('pointercancel',()=>{fuelDrag=null;drawFuelMinigame();});

  const tireLayout={toolbox:{x:35,y:325,w:220,h:155},jackHome:{x:80,y:390,w:72,h:48},jackCar:{x:455,y:375,w:72,h:48},drillHome:{x:175,y:380,w:62,h:42},hub:{x:660,y:342,r:54},oldDrop:{x:275,y:405,r:62},spare:{x:870,y:400,r:62}};
  const tirePoint=event=>{const bounds=tireGameCanvas.getBoundingClientRect();return{x:(event.clientX-bounds.left)*tireGameCanvas.width/bounds.width,y:(event.clientY-bounds.top)*tireGameCanvas.height/bounds.height};};
  const tireLugs=()=>Array.from({length:5},(_,index)=>{const angle=-Math.PI/2+index*Math.PI*2/5;return{x:tireLayout.hub.x+Math.cos(angle)*24,y:tireLayout.hub.y+Math.sin(angle)*24,index};});
  const tireDistance=(a,b)=>Math.hypot(a.x-b.x,a.y-b.y);
  function tireAction(step,extra={}){if(tireInteractionPending)return;tireInteractionPending=true;tirePendingStep=step;tirePendingLug=extra.lug??null;act('tire_minigame',{step,...extra}).finally(()=>{tireInteractionPending=false;tirePendingStep=null;tirePendingLug=null;drawTireMinigame();});}
  function drawTireWheel(target,x,y,label,damaged=false){target.fillStyle='#111';target.strokeStyle=damaged?'#ff4d4d':'#718078';target.lineWidth=5;target.beginPath();target.arc(x,y,54,0,Math.PI*2);target.fill();target.stroke();target.fillStyle='#8e9a94';target.beginPath();target.arc(x,y,30,0,Math.PI*2);target.fill();if(damaged){target.strokeStyle='#ff4d4d';target.lineWidth=8;target.beginPath();target.moveTo(x-35,y-39);target.lineTo(x-12,y-12);target.lineTo(x-30,y+8);target.lineTo(x+34,y+42);target.stroke();target.fillStyle='#ff4d4d';target.font='900 18px sans-serif';target.textAlign='center';target.fillText('!',x+43,y-35);}target.fillStyle=damaged?'#ff7777':'#d9e2dd';target.font='700 11px sans-serif';target.textAlign='center';target.fillText(label,x,y+76);target.textAlign='left';}
  function drawTireMinigame(){
    if(!tireGameState||tireGameCanvas.hidden)return;const pending=tireGameState,phase=pending.phase,target=tireGameContext,completed=(pending.completed_lugs||[]).map(Number);if(tirePendingLug!==null&&!completed.includes(Number(tirePendingLug)))completed.push(Number(tirePendingLug));
    target.clearRect(0,0,1000,520);target.fillStyle='#101713';target.fillRect(0,0,1000,520);target.fillStyle='#dce8e1';target.font='700 25px sans-serif';target.fillText('Roadside Tire Change',36,42);target.fillStyle='#9fb0a7';target.font='15px sans-serif';
    const instructions={jack_to_car:'Click the jack, then click beneath the car to place it.',raise_car:'Click and hold the jack to raise the car.',pickup_drill_remove:'Click the drill in the toolbox to pick it up.',remove_lugs:'Click and hold each lug nut to remove it.',return_drill_remove:'Click the toolbox to put the drill down.',remove_old_wheel:'Drag the damaged wheel away from the car.',install_new_wheel:'Drag the spare wheel onto the hub.',pickup_drill_install:'Pick up the drill again.',install_lugs:'Click and hold each lug nut to tighten it.',return_drill_install:'Click the toolbox to return the drill.',lower_car:'Click and hold the jack to lower the car.',return_jack:'Click the jack, then click the toolbox to return it.'};target.fillText(instructions[phase]||'Finish the tire change.',36,68);
    target.fillStyle='#25322c';target.strokeStyle='#73857b';target.lineWidth=3;target.beginPath();target.roundRect(tireLayout.toolbox.x,tireLayout.toolbox.y,tireLayout.toolbox.w,tireLayout.toolbox.h,12);target.fill();target.stroke();target.fillStyle='#dce8e1';target.font='700 16px sans-serif';target.fillText('TOOLBOX',55,355);
    target.fillStyle='#303b36';target.strokeStyle='#8da097';target.lineWidth=3;target.beginPath();target.roundRect(300,150,470,155,20);target.fill();target.stroke();target.fillStyle='#8da097';target.fillRect(335,285,400,18);target.fillStyle='#9fb0a7';target.font='700 14px sans-serif';target.fillText('LIFT POINT',438,326);
    const wheelRemoved=['install_new_wheel','pickup_drill_install','install_lugs','return_drill_install','lower_car','return_jack'].includes(phase)||tirePendingStep==='old_wheel_removed',wheelInstalled=['pickup_drill_install','install_lugs','return_drill_install','lower_car','return_jack'].includes(phase)||tirePendingStep==='new_wheel_installed';if(!wheelRemoved&&tireDrag?.wheel!=='old')drawTireWheel(target,tireLayout.hub.x,tireLayout.hub.y,'DAMAGED',true);else if(wheelInstalled)drawTireWheel(target,tireLayout.hub.x,tireLayout.hub.y,'SPARE');if(wheelRemoved&&tireDrag?.wheel!=='old')drawTireWheel(target,tireLayout.oldDrop.x,tireLayout.oldDrop.y,'DAMAGED',true);if(!wheelInstalled&&tireDrag?.wheel!=='new')drawTireWheel(target,tireLayout.spare.x,tireLayout.spare.y,'SPARE');
    const fullyRaised=['pickup_drill_remove','remove_lugs','return_drill_remove','remove_old_wheel','install_new_wheel','pickup_drill_install','install_lugs','return_drill_install','lower_car'].includes(phase),holdProgress=tireHold?.kind==='jack'?Math.min(1,(performance.now()-tireHold.started)/900):0,lift=phase==='raise_car'?holdProgress:(phase==='lower_car'?1-holdProgress:(fullyRaised?1:0));const jackAtCar=(phase!=='jack_to_car'&&tirePendingStep!=='jack_toolbox')||tirePendingStep==='jack_car',jack=tireCarry==='jack'?{x:tireCursor.x-36,y:tireCursor.y-20}:(jackAtCar?tireLayout.jackCar:tireLayout.jackHome),saddleY=jack.y-4-lift*48;target.fillStyle='#e0b52e';target.strokeStyle='#141a17';target.lineWidth=3;target.beginPath();target.moveTo(jack.x,jack.y+40);target.lineTo(jack.x+36,jack.y+8);target.lineTo(jack.x+72,jack.y+40);target.closePath();target.fill();target.stroke();target.fillStyle='#e0b52e';target.fillRect(jack.x+31,saddleY+8,10,jack.y+8-saddleY);target.fillStyle='#dce8e1';target.strokeStyle='#141a17';target.fillRect(jack.x+24,saddleY,24,12);target.strokeRect(jack.x+24,saddleY,24,12);
    const drillCarried=(['remove_lugs','return_drill_remove','install_lugs','return_drill_install'].includes(phase)&&tirePendingStep!=='drill_toolbox')||tirePendingStep==='drill_pickup';const drill=drillCarried?{x:tireCursor.x-30,y:tireCursor.y-18}:tireLayout.drillHome;target.fillStyle='#d75448';target.strokeStyle='#141a17';target.beginPath();target.roundRect(drill.x,drill.y,55,27,6);target.fill();target.stroke();target.fillStyle='#aeb9b3';target.fillRect(drill.x+50,drill.y+8,22,10);target.fillStyle='#202824';target.fillRect(drill.x+12,drill.y+25,15,20);
    if(['remove_lugs','install_lugs'].includes(phase)){tireLugs().forEach(lug=>{const done=completed.includes(lug.index);target.fillStyle=done?(phase==='remove_lugs'?'#303b36':'#65c99d'):'#dce8e1';target.beginPath();target.arc(lug.x,lug.y,9,0,Math.PI*2);target.fill();});}
    if(tireDrag?.kind==='wheel')drawTireWheel(target,tireDrag.position.x,tireDrag.position.y,tireDrag.wheel==='old'?'DAMAGED':'SPARE',tireDrag.wheel==='old');
    if(tireHold){const progress=Math.min(1,(performance.now()-tireHold.started)/900);target.strokeStyle='#65c99d';target.lineWidth=7;target.beginPath();target.arc(tireHold.point.x,tireHold.point.y,30,-Math.PI/2,-Math.PI/2+Math.PI*2*progress);target.stroke();if(progress>=1){const hold=tireHold;tireHold=null;const step=hold.kind==='lug'?(phase==='remove_lugs'?'lug_removed':'lug_installed'):(phase==='raise_car'?'jack_raised':'car_lowered');tireAction(step,hold.kind==='lug'?{lug:hold.lug}:{});}else{cancelAnimationFrame(tireAnimationFrame);tireAnimationFrame=requestAnimationFrame(drawTireMinigame);}}
  }
  function renderTireMinigame(state){const pending=state.pending_decision;if(pending?.type!=='tire_game'){tireGameState=null;tireDrag=null;tireCarry=null;tireHold=null;cancelAnimationFrame(tireAnimationFrame);tireGameCanvas.hidden=true;return;}const changed=tireGameState?.phase!==pending.phase;tireGameState=pending;tireGameCanvas.hidden=false;document.querySelector('.simulation-map-status').hidden=true;if(changed){tireDrag=null;tireCarry=null;tireHold=null;}drawTireMinigame();}
  tireGameCanvas.addEventListener('pointerdown',event=>{if(!canControl||!tireGameState||tireInteractionPending)return;const point=tirePoint(event),phase=tireGameState.phase;tireCursor=point;
    if(tireCarry==='jack'){if(phase==='jack_to_car'&&tireDistance(point,{x:tireLayout.jackCar.x+36,y:tireLayout.jackCar.y+20})<80){tireCarry=null;tireAction('jack_car');}else if(phase==='return_jack'&&inside(point,tireLayout.toolbox)){tireCarry=null;tireAction('jack_toolbox');}drawTireMinigame();return;}
    if(['pickup_drill_remove','pickup_drill_install'].includes(phase)&&inside(point,tireLayout.drillHome)){tireAction('drill_pickup');return;}
    if(['return_drill_remove','return_drill_install'].includes(phase)&&inside(point,tireLayout.toolbox)){tireAction('drill_toolbox');return;}
    if(['raise_car','lower_car'].includes(phase)&&inside(point,{...tireLayout.jackCar,w:72,h:55})){tireHold={kind:'jack',point:{x:tireLayout.jackCar.x+36,y:tireLayout.jackCar.y+20},started:performance.now()};tireGameCanvas.setPointerCapture(event.pointerId);drawTireMinigame();return;}
    if(['remove_lugs','install_lugs'].includes(phase)){const completed=(tireGameState.completed_lugs||[]).map(Number),lug=tireLugs().find(item=>!completed.includes(item.index)&&tireDistance(point,item)<22);if(lug){tireHold={kind:'lug',lug:lug.index,point:lug,started:performance.now()};tireGameCanvas.setPointerCapture(event.pointerId);drawTireMinigame();return;}}
    if(phase==='jack_to_car'&&inside(point,tireLayout.jackHome)){tireCarry='jack';drawTireMinigame();return;}
    else if(phase==='return_jack'&&inside(point,tireLayout.jackCar)){tireCarry='jack';drawTireMinigame();return;}
    else if(phase==='remove_old_wheel'&&tireDistance(point,tireLayout.hub)<65)tireDrag={kind:'wheel',wheel:'old',position:{x:tireLayout.hub.x,y:tireLayout.hub.y},offset:{x:point.x-tireLayout.hub.x,y:point.y-tireLayout.hub.y}};
    else if(phase==='install_new_wheel'&&tireDistance(point,tireLayout.spare)<65)tireDrag={kind:'wheel',wheel:'new',position:{x:tireLayout.spare.x,y:tireLayout.spare.y},offset:{x:point.x-tireLayout.spare.x,y:point.y-tireLayout.spare.y}};
    if(tireDrag)tireGameCanvas.setPointerCapture(event.pointerId);
  });
  tireGameCanvas.addEventListener('pointermove',event=>{const point=tirePoint(event);tireCursor=point;if(tireDrag)tireDrag.position={x:point.x-tireDrag.offset.x,y:point.y-tireDrag.offset.y};drawTireMinigame();});
  tireGameCanvas.addEventListener('pointerup',event=>{if(tireGameCanvas.hasPointerCapture(event.pointerId))tireGameCanvas.releasePointerCapture(event.pointerId);if(tireHold){tireHold=null;cancelAnimationFrame(tireAnimationFrame);drawTireMinigame();return;}if(!tireDrag||!tireGameState)return;const point=tirePoint(event),drag=tireDrag,phase=tireGameState.phase;tireDrag=null;if(drag.wheel==='old'&&phase==='remove_old_wheel'&&tireDistance(point,tireLayout.hub)>130)tireAction('old_wheel_removed');else if(drag.wheel==='new'&&phase==='install_new_wheel'&&tireDistance(point,tireLayout.hub)<70)tireAction('new_wheel_installed');drawTireMinigame();});
  tireGameCanvas.addEventListener('pointercancel',()=>{tireDrag=null;tireHold=null;cancelAnimationFrame(tireAnimationFrame);drawTireMinigame();});

  function renderDecision(state, status) {
    const dialog = document.getElementById('simulation-decision'), title = document.getElementById('decision-title'), copy = document.getElementById('decision-copy'), options = document.getElementById('decision-options'), pending = state.pending_decision || (status === 'awaiting_route' ? {type: 'route'} : null);
    if (!canControl) { if (dialog.open) dialog.close(); return; }
    if (!pending || ['fuel_game','tire_game'].includes(pending.type)) { if (dialog.open) dialog.close(); return; }
    options.innerHTML = '';
    if (pending.type === 'route') { title.textContent = `Depart from ${locationName(state.node)}`; copy.textContent = state.started ? 'The road splits ahead. Choose the next route segment and keep the run moving.' : 'You, your crew, and gear are all loaded up. Get to the party at Portofino as fast as possible!\n\nChoose the first route segment.'; options.innerHTML = (state.choices || []).map(choice => `<button type="button" data-segment="${escape(choice.name)}">${escape(choice.end_label || locationName(choice.end))} | ${Number(choice.distance_miles).toFixed(0)} mi</button>`).join(''); options.querySelectorAll('button').forEach(button => button.addEventListener('click', () => act('choose_segment', {segment: button.dataset.segment}))); }
    else if (pending.type === 'driver') { const resting = Boolean(state.roadside_rest); title.textContent = resting ? 'Roadside rest' : pending.forced ? 'Driver exhausted' : 'Change driver'; copy.textContent = resting ? 'The car is stopped and the elapsed timer is still running. Choose a driver when someone has recovered, or remain here and keep resting.' : pending.forced ? 'The previous driver must rest until fatigue falls to 50%. Choose an available replacement.' : 'Choose a replacement driver. The vehicle will stop only after a driver is selected.'; const current = state.drivers.find(driver => driver.role === 'driver'); const eligible = state.drivers.filter(driver => (!current || driver.id !== current.id) && !driver.locked_rest && driver.fatigue < 100); options.innerHTML = eligible.map(driver => `<button type="button" data-driver="${escape(driver.id)}">${escape(driver.name)} | ${driver.fatigue.toFixed(0)}% fatigue</button>`).join('') || '<p>No driver is currently rested enough.</p>'; if (pending.forced && !resting) options.insertAdjacentHTML('beforeend', '<button type="button" data-rest-roadside>Rest on side of road</button>'); if (!pending.forced) options.insertAdjacentHTML('beforeend', '<button class="decision-cancel" type="button" data-cancel-driver>Cancel</button>'); options.querySelectorAll('[data-driver]').forEach(button => button.addEventListener('click', () => act('change_driver', {driver: button.dataset.driver}))); options.querySelector('[data-rest-roadside]')?.addEventListener('click', () => act('rest_roadside')); options.querySelector('[data-cancel-driver]')?.addEventListener('click', () => act('cancel_driver_change')); }
    else if (pending.type === 'event' && pending.event === 'weather_cleared') { title.textContent = 'The road opens up'; copy.textContent = obstacleFlavor(pending); options.innerHTML = '<button class="decision-ok" type="button" data-acknowledge>OK</button>'; options.querySelector('button').addEventListener('click', () => act('acknowledge_event')); }
    else if (pending.type === 'event' && pending.event === 'wildlife_event') { title.textContent = pending.ends_run ? 'Wildlife in the road' : 'Heat signature ahead'; copy.textContent = obstacleFlavor(pending); options.innerHTML = '<button class="decision-ok" type="button" data-acknowledge>OK</button>'; options.querySelector('button').addEventListener('click', () => act('acknowledge_event')); }
    else if (pending.type === 'event') { const labels = {weather_event: 'Weather encountered', weather_cleared: 'The road opens up', invented_traffic: pending.bypassed ? 'Make way' : 'Boxed in', road_event: 'Trouble ahead', police_event: 'Lights in the mirror', crash_event: 'The run ends here', flat_tire: pending.ends_run ? 'No rubber left' : 'That sound was expensive', mechanical_failure: pending.ends_run ? 'The machine says no' : 'The machine has opinions', fuel_warning: 'Running on fumes', redbull_used: 'Wings deployed', redbull_rejected: 'Hard pass', redbull_wore_off: 'Back to gravity', redbull_penalty_cleared: 'The jitters fade', snack_used: 'Road food', snack_rejected: 'Absolutely stuffed'}, clearMessages = {fog: 'The fog finally loosened its grip. Visibility is back and the road belongs to the crew again.', rain: 'The rain has moved on. The windshield is clear and the road is ready.', ice: 'The ice is behind you. Grip has returned and the tires can breathe again.', snow: 'The snow has cleared. The lane ahead is open and calling.', tail_wind: 'The helpful tail wind has faded. Back to earning every mile.', head_wind: 'The head wind finally gave up the fight. The car can stretch its legs again.'}; title.textContent = labels[pending.event] || 'Delay event'; if (pending.event === 'redbull_used') copy.textContent = consumableFlavor(pending); else if (pending.event === 'redbull_rejected') copy.textContent = consumableFlavor(pending); else if (pending.event === 'redbull_wore_off') copy.textContent = consumableFlavor(pending); else if (pending.event === 'redbull_penalty_cleared') copy.textContent = consumableFlavor(pending); else if (pending.event === 'snack_used') copy.textContent = consumableFlavor(pending); else if (pending.event === 'snack_rejected') copy.textContent = consumableFlavor(pending); else if (['weather_event','weather_cleared','crash_event','police_event','mechanical_failure','flat_tire','road_event'].includes(pending.event)) copy.textContent = obstacleFlavor(pending); else if (pending.event === 'fuel_warning') copy.textContent = `The fuel gauge has fallen to ${pending.percent}%. Portofino is still a long way off; decide whether to press on or stop for fuel after resuming.`; else if (pending.event === 'invented_traffic' && pending.bypassed) copy.textContent = `A ${pending.blocker} was about to seal off the passing lane. The undercover lights came alive, traffic parted, and the road opened without costing the crew any speed.`; else if (pending.event === 'invented_traffic') copy.textContent = `The driver was unable to read the traffic and got stuck behind a ${pending.blocker}. The vehicle is limited to ${pending.speed_cap} mph until there is room to escape.`; else if (pending.event === 'crash_event') copy.textContent = 'The road, the speed, and one cruel moment finally collected their debt. The car will go no farther, and Portofino will have to wait for another crew. This run is over.'; else if (pending.event === 'weather_event' && pending.weather === 'tail_wind') copy.textContent = 'The road gods are smiling. A tail wind improves fuel economy by 15% until conditions change.'; else if (pending.event === 'weather_event' && pending.weather === 'head_wind') copy.textContent = 'The wind has other plans. A head wind reduces fuel economy by 15% until conditions change.'; else if (pending.event === 'weather_event') copy.textContent = `${String(pending.weather).replace(/^./, letter => letter.toUpperCase())} has swallowed the road ahead. The vehicle is limited to ${pending.speed_cap} mph until conditions improve.`; else if (pending.event === 'flat_tire' && pending.ends_run) copy.textContent = 'Another tire has surrendered, but every usable spare is already on the road behind you. The crew has tools, determination, and absolutely no remaining tire to install. The run is over.'; else if (pending.event === 'flat_tire') copy.textContent = `A tire has given up the fight. The crew needs ${duration(pending.service_seconds)} to replace it, plus controlled deceleration and acceleration. ${pending.spares_remaining ? `${pending.spares_remaining} spare remains after this repair.` : 'This is the last repairable flat; there are no spares left after this.'}\n\n${duration(pending.delay_seconds)} will be added to the run.`; else if (pending.event === 'mechanical_failure' && pending.ends_run) copy.textContent = 'The dashboard lit up like a holiday display, the engine answered with one final metallic complaint, and the shoulder became the finish line. This failure cannot be repaired on the road. The run is over.'; else if (pending.event === 'mechanical_failure') copy.textContent = `Something under the hood has demanded immediate attention. The crew gets the tools out and turns a potential disaster into a roadside repair. The work takes ${duration(pending.service_seconds)}, plus controlled deceleration and acceleration.\n\n${duration(pending.delay_seconds)} will be added to the run.`; else if (pending.event === 'police_event' && pending.jailed && pending.police_camo) copy.textContent = 'The disguise worked on traffic, but not on the officer standing beside the car. Impersonating an undercover unit erased every chance of a warning. The run ends in a jail cell.'; else if (pending.event === 'police_event' && pending.jailed && pending.countermeasure === 'radar_jammer') copy.textContent = 'The jammer drew exactly the wrong kind of attention. The crew was pulled over, the hardware was discovered, and Portofino has been replaced by a jail cell.'; else if (pending.event === 'police_event' && pending.jailed && pending.countermeasure === 'radio_scanner') copy.textContent = 'The radio scanner stayed quiet until the lights filled the mirrors. The stop went badly, and the crew is headed to jail instead of Portofino.'; else if (pending.event === 'police_event' && pending.jailed) copy.textContent = 'The lights stayed close all the way to the shoulder. This stop ends the run: the crew is headed to jail instead of Portofino.'; else if (pending.event === 'police_event' && pending.countermeasure_outcome === 'bypass') copy.textContent = 'The jammer painted a clean exit through the radio noise. The patrol never committed, and the road ahead is open.'; else if (pending.event === 'police_event' && pending.countermeasure_outcome === 'slowdown' && pending.countermeasure === 'radio_scanner') copy.textContent = `The radio scanner caught the call early. The driver tucked into traffic and let the patrol pass.\n\n${duration(pending.delay_seconds)} will be added to the run.`; else if (pending.event === 'police_event' && pending.countermeasure_outcome === 'slowdown') copy.textContent = `The jammer bought just enough uncertainty to slow down and disappear into ordinary traffic.\n\n${duration(pending.delay_seconds)} will be added to the run.`; else if (pending.event === 'police_event' && pending.countermeasure === 'radio_scanner') copy.textContent = 'The scanner missed the warning and the crew reached the shoulder. They caught a break, but the roadside conversation adds 30 minutes to the run.'; else if (pending.event === 'police_event') copy.textContent = 'The crew caught a break and was released. The roadside conversation adds 30 minutes to the run.'; else copy.textContent = `${pending.mitigated ? 'Quick work by the crew softened the blow. ' : 'The road just collected its toll. '}Expected delay: ${duration(pending.delay_seconds)}.`; options.innerHTML = '<button class="decision-ok" type="button" data-acknowledge>OK</button>'; options.querySelector('button').addEventListener('click', () => act('acknowledge_event')); }
    else if (pending.type === 'confirmation') { const driver = state.drivers.find(item => item.role === 'driver'), canChange = state.drivers.some(item => item.id !== driver?.id && !item.locked_rest && item.fatigue < 100); title.textContent = pending.title; copy.textContent = pending.message; options.innerHTML = pending.fuel_stop ? `<div class="decision-confirm-actions"><button type="button" data-acknowledge>OK</button><button type="button" data-fuel-driver ${canChange ? '' : 'disabled'}>Change Driver</button></div>` : '<button class="decision-ok" type="button" data-acknowledge>OK</button>'; options.querySelector('[data-acknowledge]').addEventListener('click', () => act('acknowledge_event')); options.querySelector('[data-fuel-driver]')?.addEventListener('click', () => act('fuel_change_driver')); }
    if (!dialog.open) dialog.showModal();
  }

  function render(simulation) {
    latestSimulation = simulation; currentStatus = simulation.status;
    statusNode.textContent = (simulation.state.roadside_rest ? 'roadside rest' : simulation.status.replaceAll('_', ' ')).replace(/\b\w/g, char => char.toUpperCase());
    const simulationDate = new Date(simulation.simulated_at), daylight = daylightStatus(simulationDate); timeNode.textContent = `${daylight.icon} ${fmt.format(simulationDate)}`; timeNode.title = daylight.label;
    const state = simulation.state; latestState = state; const vehicle = state.vehicle, segment = state.current_segment; currentVehicleTop = vehicle.top_speed === undefined ? Infinity : Number(vehicle.tuned ? vehicle.tuned_top_speed : vehicle.top_speed);
    const elapsed = (new Date(simulation.simulated_at) - new Date(simulation.departure_at)) / 1000, vehicleImage = document.getElementById('sim-vehicle-image'), vehicleFallback = document.getElementById('sim-vehicle-fallback'); document.getElementById('sim-vehicle-name').textContent = vehicle.name || 'Vehicle'; vehicleImage.hidden = !vehicle.image_path; vehicleFallback.hidden = Boolean(vehicle.image_path); if (vehicle.image_path && vehicleImage.src !== new URL(vehicle.image_path, window.location.origin).href) { vehicleImage.src = vehicle.image_path; vehicleImage.alt = vehicle.name || 'Selected vehicle'; } document.getElementById('sim-speed').textContent = `${Math.round(vehicle.current_speed)} mph`; document.getElementById('sim-fuel').textContent = `${vehicle.fuel.toFixed(1)} / ${vehicle.capacity.toFixed(1)} gal`; document.getElementById('sim-elapsed').textContent = elapsedDuration(elapsed); document.getElementById('sim-distance').textContent = `${vehicle.distance_miles.toFixed(1)} mi`; document.getElementById('sim-target').textContent = `${Math.round(vehicle.target_speed)} mph`; document.getElementById('sim-equipment').innerHTML = equipmentMarkup(state.equipment, state.consumables);
    const summaryVehicleImage = document.getElementById('summary-car-image'), summaryVehicleFallback = document.getElementById('summary-car-fallback'); summaryVehicleImage.hidden = !vehicle.image_path; summaryVehicleFallback.hidden = Boolean(vehicle.image_path); if (vehicle.image_path && summaryVehicleImage.src !== new URL(vehicle.image_path, window.location.origin).href) { summaryVehicleImage.src = vehicle.image_path; summaryVehicleImage.alt = vehicle.name || 'Selected vehicle'; }
    const speedInput = document.getElementById('speed-control'); if (document.activeElement !== speedInput) speedInput.value = Math.round(vehicle.target_speed);
    const fuelShare = 100 * vehicle.fuel / vehicle.capacity, fuelBar = document.getElementById('sim-fuel-bar'); fuelBar.style.width = fuelShare + '%'; fuelBar.style.background = fuelShare < 15 ? 'var(--red)' : fuelShare < 35 ? '#f0b429' : 'var(--green)';
    const trafficStarted = segment && (segment.traffic_started ?? true), trafficEnd = segment?.traffic_end_elapsed ?? segment?.traffic_cap_seconds, trafficActive = segment?.traffic_speed_cap && segment.traffic_speed_cap < segment.cruising_speed && trafficStarted && segment.elapsed_seconds < trafficEnd, inventedTraffic = state.active_invented_traffic || null; document.getElementById('simulation-location').textContent = segment ? `${locationName(segment.start)} to ${locationName(segment.end)}` : locationName(state.node); document.getElementById('simulation-traffic').textContent = segment ? `${segment.traffic_source} traffic${trafficActive ? ` | ${Number(segment.traffic_speed_cap).toFixed(0)} mph observed cap` : ''}${inventedTraffic ? ` | stuck behind ${inventedTraffic.blocker}` : ''} | ${Math.round(100 * (segment.progress_fraction ?? segment.elapsed_seconds / Math.max(1, segment.total_seconds)))}% of segment` : state.traffic_mode + ' traffic'; const weatherNode = document.getElementById('simulation-weather'), trafficIcon = document.getElementById('simulation-traffic-icon'), vehicleLimitIcon = document.getElementById('simulation-vehicle-limit'), weather = state.active_weather || (segment?.status_icon ? {icon: segment.status_icon, weather: segment.weather, speed_cap: segment.cruising_speed, mpg_multiplier: segment.mpg_multiplier} : null); weatherNode.hidden = !weather; weatherNode.textContent = weather?.icon || ''; weatherNode.title = weather ? `${String(weather.weather).replace('_', ' ')}${weather.speed_cap !== null ? ` | ${weather.speed_cap} mph maximum` : ` | ${Math.round(weather.mpg_multiplier * 100)}% fuel economy`}` : ''; trafficIcon.hidden = !trafficActive && !inventedTraffic; trafficIcon.textContent = inventedTraffic ? '\u{1f68c}' : '\u{1f6a6}'; trafficIcon.title = inventedTraffic ? `Stuck behind a ${inventedTraffic.blocker} | ${Number(inventedTraffic.speed_cap).toFixed(0)} mph maximum` : trafficActive ? `Observed traffic | ${Number(segment.traffic_speed_cap).toFixed(0)} mph maximum` : ''; const externalCap = (trafficActive && Number(segment.traffic_speed_cap) < currentVehicleTop) || (inventedTraffic && Number(inventedTraffic.speed_cap) < currentVehicleTop) || (weather?.speed_cap !== null && Number(weather?.speed_cap) < currentVehicleTop), vehicleLimitActive = Number.isFinite(currentVehicleTop) && Number(vehicle.target_speed) > currentVehicleTop && !externalCap; vehicleLimitIcon.hidden = !vehicleLimitActive; vehicleLimitIcon.title = vehicleLimitActive ? `${vehicle.name || 'Vehicle'} | ${currentVehicleTop} mph maximum` : ''; renderLiveMap(state); renderFuelMinigame(state);
    document.getElementById('driver-cards').innerHTML = state.drivers.map(driver => { const role = driver.role === 'driver' && state.driver_copilot ? 'driver / co-pilot' : driver.role, roleIcons = driver.role === 'rest' ? ' <span role="img" aria-label="Resting" title="Resting">\u{1f634}</span>' : `${driver.role === 'driver' ? ' <span role="img" aria-label="Driver" title="Driver">\u{1f697}</span>' : ''}${driver.role === 'copilot' || (driver.role === 'driver' && state.driver_copilot) ? ' <span role="img" aria-label="Co-pilot" title="Co-pilot">\u{1f52d}</span>' : ''}`, redbullSeconds = Number(driver.active_effects?.redbull || 0), redbullStatus = redbullSeconds > 0 ? ` | \u{1f964} ${duration(redbullSeconds)} protected` : '', degradation = driver.fatigue > 50 ? Math.min(50, driver.fatigue - 50) : 0, redbullPenalty = (driver.redbull_penalties || []).reduce((sum, penalty) => sum + Number(penalty.amount || 0), 0), effectiveDriving = (Math.max(0, Number(driver.driving) - redbullPenalty) * (1 - degradation / 100)).toFixed(0), effectiveCopilot = degradation ? (driver.copilot * (1 - degradation / 100)).toFixed(0) : driver.copilot; return `<article class="driver-card"><header><strong>${escape(driver.name)}${roleIcons}</strong><span>${escape(role.replace('_', ' '))}</span></header><div class="driver-stats"><small><span>Endurance</span><strong>${driver.endurance}</strong></small><small><span>Driving${degradation ? ` <em>Fatigue -${degradation.toFixed(0)}%</em>` : ''}${redbullPenalty ? ` <em>The jitters -${redbullPenalty.toFixed(0)} pts</em>` : ''}</span><strong>${effectiveDriving}</strong></small><small><span>Co-piloting${degradation ? ` <em>-${degradation.toFixed(0)}%</em>` : ''}</span><strong>${effectiveCopilot}</strong></small></div><div class="fatigue-track"><i style="width:${driver.fatigue}%;background:${driver.fatigue >= 75 ? 'var(--red)' : driver.fatigue >= 50 ? '#f0b429' : 'var(--green)'}"></i></div><small>${driver.fatigue.toFixed(1)}% fatigue${driver.locked_rest ? ' | REST REQUIRED' : ''}${redbullStatus}</small></article>`; }).join('');
    const copilotSelect = document.getElementById('copilot-control'), driver = state.drivers.find(item => item.role === 'driver'), copilot = state.driver_copilot ? driver : state.drivers.find(item => item.role === 'copilot'), availableAlternative = state.drivers.some(item => item.id !== driver?.id && !item.locked_rest && item.fatigue < 100); if (document.activeElement !== copilotSelect) { copilotSelect.innerHTML = '<option value="">None</option>' + state.drivers.map(item => { const driverUnavailable = item.id === driver?.id && state.drivers.length > 1 && availableAlternative; return `<option value="${escape(item.id)}" ${copilot && copilot.id === item.id ? 'selected' : ''} ${item.locked_rest || driverUnavailable ? 'disabled' : ''}>${escape(item.name)}</option>`; }).join(''); }
    const ended = ['completed', 'failed'].includes(simulation.status), decisionPending = ['event', 'confirmation', 'driver', 'fuel_game', 'tire_game'].includes(state.pending_decision?.type), pause = document.getElementById('pause-action'); pause.textContent = simulation.status === 'paused' && !decisionPending ? 'Resume' : 'Pause'; pause.disabled = !canControl || ended || decisionPending || !['running', 'paused'].includes(simulation.status); document.getElementById('driver-action').disabled = !canControl || simulation.status !== 'running' || decisionPending || !availableAlternative; document.getElementById('fuel-action').disabled = !canControl || simulation.status !== 'running' || decisionPending || Boolean(state.fuel_stop_pending); document.getElementById('consumable-action').disabled = !canControl || simulation.status !== 'running' || decisionPending || !Object.values(state.consumables || {}).some(item => Number(item.remaining) > 0); const debugFlatTire = document.getElementById('debug-flat-tire'); if (debugFlatTire) debugFlatTire.disabled = !isSuperadmin || simulation.status !== 'running' || decisionPending || !state.current_segment; const consumableDialog = document.getElementById('consumable-dialog'); if ((decisionPending || ended) && consumableDialog.open) consumableDialog.close(); document.getElementById('speed-control').disabled = !canControl || Boolean(state.roadside_rest) || decisionPending; document.getElementById('copilot-control').disabled = !canControl || Boolean(state.roadside_rest) || decisionPending;
    const summary = document.getElementById('simulation-summary'); summary.hidden = !ended; summary.classList.toggle('simulation-summary-failed', ended && simulation.status === 'failed'); document.body.classList.toggle('simulation-summary-open', ended);
    if (ended) { if (document.getElementById('simulation-decision').open) document.getElementById('simulation-decision').close(); const successful = simulation.status === 'completed' && state.node === 'portofino'; const finalElapsed = successful && Number.isFinite(Number(state.finished_elapsed_seconds)) ? Number(state.finished_elapsed_seconds) : elapsed, runMetrics = state.run_metrics || {}, fuelConsumed = Number(runMetrics.fuel_consumed) || 0, policeEncounters = runMetrics.police_encounters !== undefined ? Number(runMetrics.police_encounters) || 0 : [...(state.traveled_segments || []), ...(state.current_segment ? [state.current_segment] : [])].reduce((total, segment) => total + (segment.map_events || []).filter(event => event.type === 'police_event').length, 0); document.getElementById('summary-time').textContent = duration(finalElapsed); document.getElementById('summary-average').textContent = finalElapsed > 0 ? `${(vehicle.distance_miles / (finalElapsed / 3600)).toFixed(1)} mph` : '0.0 mph'; document.getElementById('summary-departure').textContent = newYorkFmt.format(new Date(simulation.departure_at)); document.getElementById('summary-ended').textContent = newYorkFmt.format(new Date(simulation.simulated_at)); document.getElementById('summary-distance').textContent = `${vehicle.distance_miles.toFixed(1)} mi`; document.getElementById('summary-fuel-stops').textContent = String(Number(runMetrics.fuel_stops) || 0); document.getElementById('summary-mpg').textContent = fuelConsumed > 0 ? `${(vehicle.distance_miles / fuelConsumed).toFixed(1)} mpg` : '--'; document.getElementById('summary-police-encounters').textContent = String(policeEncounters); document.getElementById('summary-stops').textContent = duration(vehicle.stopped_seconds); document.getElementById('summary-fuel').textContent = `${vehicle.fuel.toFixed(1)} gal`; document.getElementById('summary-record').hidden = !(successful && finalElapsed < 92340); document.getElementById('summary-car').textContent = vehicle.name || 'Vehicle'; const summaryEquipment = equipmentSummary(state.equipment); document.getElementById('summary-equipment').innerHTML = summaryEquipment.length ? summaryEquipment.map(item => `<li><span aria-hidden="true">${item.icon}</span>${escape(item.text)}</li>`).join('') : '<li>None</li>'; document.getElementById('summary-drivers').innerHTML = state.drivers.map(driver => { const roles = driver.role_seconds || {}, roleSeconds = { driving: Math.max(0, Number(roles.driver) || 0), copiloting: Math.max(0, Number(roles.copilot) || 0), resting: Math.max(0, Number(roles.rest) || 0) }, tracked = Object.values(roleSeconds).reduce((sum, seconds) => sum + seconds, 0), share = role => tracked > 0 ? 100 * roleSeconds[role] / tracked : null, percent = role => share(role) === null ? '--' : `${share(role).toFixed(1)}%`, width = role => share(role) === null ? '0' : share(role).toFixed(3), roleLabel = `Driving ${percent('driving')}, co-piloting ${percent('copiloting')}, resting ${percent('resting')}`; return `<article class="summary-driver"><strong>${escape(driver.name)}</strong><span>Endurance ${Number(driver.endurance).toFixed(0)} | Driving ${Number(driver.driving).toFixed(0)} | Co-piloting ${Number(driver.copilot).toFixed(0)}</span><span>Final role: ${escape(String(driver.role).replace('_', ' '))} | Fatigue ${Number(driver.fatigue).toFixed(1)}%</span><div class="summary-role-usage"><div class="summary-role-bar" role="img" aria-label="${escape(roleLabel)}"><i class="driving" style="width:${width('driving')}%"></i><i class="copiloting" style="width:${width('copiloting')}%"></i><i class="resting" style="width:${width('resting')}%"></i></div><div class="summary-role-legend"><span><i class="driving"></i>Driving ${percent('driving')}</span><span><i class="copiloting"></i>Co-piloting ${percent('copiloting')}</span><span><i class="resting"></i>Resting ${percent('resting')}</span></div></div></article>`; }).join(''); document.getElementById('summary-reason').textContent = successful ? '\u{1f389} \u{1f3c1} \u{1f37e} \u{1f942} The crew made it to Portofino Marina! \u{1f38a} \u{1f942} \u{1f37e} \u{1f3c1} \u{1f389}' : state.failure_reason || state.end_reason || 'The run ended before reaching Portofino Marina.'; drawSummaryMap(state); } else renderDecision(state, simulation.status);
    const exactElapsed=Math.max(0,...state.drivers.map(driver=>Number(driver.role_seconds?.total)||0));if(exactElapsed>0){document.getElementById('sim-elapsed').textContent=elapsedDuration(exactElapsed);if(ended){const successful=simulation.status==='completed'&&state.node==='portofino';document.getElementById('summary-time').textContent=duration(exactElapsed);document.getElementById('summary-average').textContent=`${(vehicle.distance_miles/(exactElapsed/3600)).toFixed(1)} mph`;document.getElementById('summary-record').hidden=!(successful&&exactElapsed<92340);}}
  }

  function openConsumables() {
    const dialog = document.getElementById('consumable-dialog'), options = document.getElementById('consumable-options'), state = latestState;
    if (!state) return;
    const definitions = {redbull: {name: 'RedBull', icon: '\u{1f964}', description: 'Stops fatigue gain for two in-game hours. Drinks 3 and 4 cause The jitters, temporarily reducing driving skill.'}, snack: {name: 'Snack', icon: '\u{1f37f}', description: 'Immediately restores 5% fatigue.'}};
    options.innerHTML = Object.entries(state.consumables || {}).map(([key, item]) => { const definition = definitions[key] || {name: item.name || key, icon: item.icon || '\u{1f392}', description: ''}, remaining = Number(item.remaining) || 0; return `<article class="consumable-option"><header><span aria-hidden="true">${definition.icon}</span><div><strong>${escape(definition.name)}</strong><small>${remaining} remaining</small></div></header><p>${escape(definition.description)}</p><div>${state.drivers.map(driver => { const disabled = remaining <= 0; return `<button type="button" data-consumable="${escape(key)}" data-driver="${escape(driver.id)}" ${disabled ? 'disabled' : ''}>${escape(driver.name)}</button>`; }).join('')}</div></article>`; }).join('') || '<p>No consumables are available for this run.</p>';
    options.querySelectorAll('[data-consumable]').forEach(button => button.addEventListener('click', () => { dialog.close(); act('use_consumable', {consumable: button.dataset.consumable, driver: button.dataset.driver}); }));
    dialog.showModal();
  }

  async function act(action, payload = {}) { errorNode.hidden = true; const body = new URLSearchParams({_token: config.csrf, action, ...payload}); try { const response = await fetch(`/simulator/${config.id}/action`, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body}); const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Action failed'); await refresh(); } catch (error) { errorNode.dataset.source = 'action'; errorNode.textContent = error.message; errorNode.hidden = false; } }
  async function refresh() { if (polling) return; polling = true; let terminal = false; try { const response = await fetch(`/simulator/${config.id}/status?after=${lastEvent}`, {cache: 'no-store'}); if (!response.ok) throw new Error('Simulation unavailable'); const simulation = await response.json(); terminal = ['completed', 'failed'].includes(simulation.status); if (errorNode.dataset.source === 'poll') errorNode.hidden = true; render(simulation); addEvents(simulation.events || []); pollCount += 1; if (pollCount >= 120) { performance.clearResourceTimings?.(); pollCount = 0; } } catch (error) { errorNode.dataset.source = 'poll'; errorNode.textContent = error.message; errorNode.hidden = false; } finally { polling = false; clearTimeout(pollTimer); pollTimer = terminal ? null : setTimeout(refresh, document.hidden ? 10000 : 1000); } }
  document.getElementById('speed-control').addEventListener('input', event => { clearTimeout(speedTimer); const input = event.currentTarget, speed = input.value.trim(), numericSpeed = Number(speed); input.setAttribute('aria-invalid', 'false'); if (!/^\d{2,3}$/.test(speed) || numericSpeed < 20 || numericSpeed > 250) { input.setAttribute('aria-invalid', 'true'); return; } speedTimer = setTimeout(() => act('target_speed', {speed: numericSpeed}), 1000); }); document.getElementById('copilot-control').addEventListener('change', event => act('copilot', {copilot: event.currentTarget.value})); document.getElementById('pause-action').addEventListener('click', () => act(currentStatus === 'paused' ? 'resume' : 'pause')); document.getElementById('driver-action').addEventListener('click', () => act('request_driver_change')); document.getElementById('fuel-action').addEventListener('click', () => act('fuel')); document.getElementById('consumable-action').addEventListener('click', openConsumables); document.getElementById('consumable-cancel').addEventListener('click', () => document.getElementById('consumable-dialog').close());
  document.getElementById('debug-flat-tire')?.addEventListener('click', () => act('debug_flat_tire'));
  document.getElementById('print-summary').addEventListener('click', () => window.print());
  document.getElementById('map-segment-previous').addEventListener('click', () => { if (!latestState) return; const count = (latestState.traveled_segments || []).length + (latestState.current_segment?.polyline ? 1 : 0), current = mapHistoryIndex === null ? count - 1 : mapHistoryIndex; mapHistoryIndex = Math.max(0, current - 1); if (latestSimulation) render(latestSimulation); });
  document.getElementById('map-segment-next').addEventListener('click', () => { if (!latestState) return; const latest = (latestState.traveled_segments || []).length + (latestState.current_segment?.polyline ? 1 : 0) - 1, current = mapHistoryIndex === null ? latest : mapHistoryIndex; mapHistoryIndex = current + 1 >= latest ? null : current + 1; if (latestSimulation) render(latestSimulation); });
  document.addEventListener('visibilitychange', () => { if (!document.hidden && pollTimer) { clearTimeout(pollTimer); pollTimer = setTimeout(refresh, 0); } });
  refresh();
})();
