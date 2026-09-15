(() => {
  const config = window.CannonMinerSimulation;
  const canvas = document.getElementById('simulation-map');
  const context = canvas.getContext('2d');
  const statusNode = document.getElementById('simulation-status');
  const timeNode = document.getElementById('simulation-time');
  const errorNode = document.getElementById('simulation-error');
  const seenEvents = new Set();
  let lastEvent = 0;
  let currentStatus = '';
  let polling = false;
  let pollTimer = null;
  const fmt = new Intl.DateTimeFormat(undefined, {weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit', timeZoneName: 'short'});
  const escape = value => String(value).replace(/[&<>"']/g, char => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char]));
  const duration = seconds => { seconds = Math.max(0, Math.round(Number(seconds) || 0)); const hours = Math.floor(seconds / 3600), minutes = Math.floor(seconds % 3600 / 60), rest = seconds % 60; return `${hours ? hours + 'h ' : ''}${minutes ? minutes + 'm ' : ''}${rest}s`; };

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

  function drawMap(segment) {
    context.fillStyle = '#101713'; context.fillRect(0, 0, canvas.width, canvas.height);
    context.strokeStyle = 'rgba(255,255,255,.06)'; context.lineWidth = 1;
    for (let x = 0; x < canvas.width; x += 50) { context.beginPath(); context.moveTo(x, 0); context.lineTo(x, canvas.height); context.stroke(); }
    for (let y = 0; y < canvas.height; y += 50) { context.beginPath(); context.moveTo(0, y); context.lineTo(canvas.width, y); context.stroke(); }
    if (!segment || !segment.polyline) { context.fillStyle = '#aab8b0'; context.font = '24px sans-serif'; context.textAlign = 'center'; context.fillText(segment ? 'Route geometry unavailable' : 'Choose an onward segment', canvas.width / 2, canvas.height / 2); return; }
    const points = decodePolyline(segment.polyline);
    if (points.length < 2) return;
    const xs = points.map(point => point[0]), ys = points.map(point => point[1]);
    const minX = Math.min(...xs), maxX = Math.max(...xs), minY = Math.min(...ys), maxY = Math.max(...ys), pad = 55;
    const scale = Math.min((canvas.width - pad * 2) / Math.max(.001, maxX - minX), (canvas.height - pad * 2) / Math.max(.001, maxY - minY));
    const project = point => [pad + (point[0] - minX) * scale, canvas.height - pad - (point[1] - minY) * scale];
    context.strokeStyle = '#35a866'; context.lineWidth = 8; context.lineCap = 'round'; context.lineJoin = 'round'; context.beginPath();
    points.forEach((point, index) => { const [x, y] = project(point); index ? context.lineTo(x, y) : context.moveTo(x, y); }); context.stroke();
    const fraction = Math.max(0, Math.min(1, segment.elapsed_seconds / Math.max(1, segment.total_seconds))), position = fraction * (points.length - 1), low = Math.floor(position), share = position - low;
    const a = project(points[low]), b = project(points[Math.min(points.length - 1, low + 1)]), x = a[0] + (b[0] - a[0]) * share, y = a[1] + (b[1] - a[1]) * share;
    context.fillStyle = '#fff'; context.beginPath(); context.arc(x, y, 11, 0, Math.PI * 2); context.fill(); context.fillStyle = '#e93d32'; context.beginPath(); context.arc(x, y, 7, 0, Math.PI * 2); context.fill();
  }

  const eventText = (type, payload) => ({simulation_created: `Simulation created in ${payload.mode} mode`, segment_entered: `Entered ${String(payload.segment).replace('_to_', ' to ')}`, traffic_delay: `Traffic delay +${duration(payload.seconds)}`, weather_event: `Weather obstacle${payload.mitigated ? ' mitigated' : ''} +${duration(payload.delay_seconds)}`, police_event: `Police obstacle${payload.mitigated ? ' mitigated' : ''} +${duration(payload.delay_seconds)}`, road_event: `Road obstacle${payload.mitigated ? ' mitigated' : ''} +${duration(payload.delay_seconds)}`, segment_completed: `Arrived at ${payload.node}`, route_choice_required: `Route choice required at ${payload.node}`, fatigue_threshold: `${payload.driver} fatigue reached ${payload.fatigue}%`, driver_exhausted: `${payload.driver} reached full fatigue`, roles_changed: 'Driver roles changed', target_speed_changed: `Target speed set to ${payload.speed} mph`, fuel_warning: `Fuel below ${payload.percent}%`, fuel_stop: `Fuel stop: ${payload.gallons} gal, ${duration(payload.seconds)}`, paused: 'Vehicle stopped', resumed: 'Run resumed', out_of_fuel: 'Fuel exhausted. Run ended.', traffic_unavailable: `Run ended: ${payload.message}`, arrived_portofino: 'Arrived at Portofino'}[type] || String(type).replaceAll('_', ' '));

  function addEvents(events) {
    const list = document.getElementById('event-log');
    events.forEach(event => { lastEvent = Math.max(lastEvent, Number(event.id)); if (seenEvents.has(event.id)) return; seenEvents.add(event.id); const row = document.createElement('li'), time = document.createElement('time'), text = document.createElement('span'); time.textContent = fmt.format(new Date(event.simulated_at)); text.textContent = eventText(event.type, event.payload || {}); row.append(time, text); list.append(row); });
    if (events.length) list.scrollTop = list.scrollHeight;
  }

  function render(simulation) {
    currentStatus = simulation.status;
    statusNode.textContent = simulation.status.replaceAll('_', ' ').replace(/\b\w/g, char => char.toUpperCase());
    timeNode.textContent = fmt.format(new Date(simulation.simulated_at));
    const state = simulation.state, vehicle = state.vehicle, segment = state.current_segment;
    document.getElementById('sim-speed').textContent = `${Math.round(vehicle.current_speed)} mph`; document.getElementById('sim-fuel').textContent = `${vehicle.fuel.toFixed(1)} / ${vehicle.capacity.toFixed(1)} gal`; document.getElementById('sim-distance').textContent = `${vehicle.distance_miles.toFixed(1)} mi`; document.getElementById('sim-target').textContent = `${Math.round(vehicle.target_speed)} mph`;
    const speedInput = document.querySelector('#speed-control input'); if (document.activeElement !== speedInput) speedInput.value = Math.round(vehicle.target_speed);
    const fuelShare = 100 * vehicle.fuel / vehicle.capacity, fuelBar = document.getElementById('sim-fuel-bar'); fuelBar.style.width = fuelShare + '%'; fuelBar.style.background = fuelShare < 15 ? 'var(--red)' : fuelShare < 35 ? '#f0b429' : 'var(--green)';
    document.getElementById('simulation-location').textContent = segment ? `${segment.start} to ${segment.end}` : state.node; document.getElementById('simulation-traffic').textContent = segment ? `${segment.traffic_source} traffic | ${Math.round(100 * segment.elapsed_seconds / Math.max(1, segment.total_seconds))}% of segment` : state.traffic_mode + ' traffic'; drawMap(segment);
    const choices = document.getElementById('route-choices'); choices.innerHTML = (state.choices || []).map(choice => `<button type="button" data-segment="${escape(choice.name)}">${escape(choice.end)} | ${Number(choice.distance_miles).toFixed(0)} mi</button>`).join(''); choices.querySelectorAll('button').forEach(button => button.addEventListener('click', () => act('choose_segment', {segment: button.dataset.segment})));
    document.getElementById('driver-cards').innerHTML = state.drivers.map(driver => `<article class="driver-card"><header><strong>${escape(driver.name)}</strong><span>${escape(driver.role.replace('_', ' '))}</span></header><small>END ${driver.endurance} | DRIVE ${driver.driving} | CO-PILOT ${driver.copilot}</small><div class="fatigue-track"><i style="width:${driver.fatigue}%;background:${driver.fatigue >= 75 ? 'var(--red)' : driver.fatigue >= 50 ? '#f0b429' : 'var(--green)'}"></i></div><small>${driver.fatigue.toFixed(1)}% fatigue</small></article>`).join('');
    const roleControl = document.getElementById('role-control'), driverSelect = roleControl.querySelector('[name=driver]'), copilotSelect = roleControl.querySelector('[name=copilot]'), driver = state.drivers.find(item => item.role === 'driver'), copilot = state.drivers.find(item => item.role === 'copilot');
    if (!roleControl.contains(document.activeElement)) { driverSelect.innerHTML = state.drivers.map(item => `<option value="${escape(item.id)}" ${driver && driver.id === item.id ? 'selected' : ''}>${escape(item.name)} | ${item.fatigue.toFixed(0)}%</option>`).join(''); copilotSelect.innerHTML = '<option value="">None</option>' + state.drivers.map(item => `<option value="${escape(item.id)}" ${copilot && copilot.id === item.id ? 'selected' : ''}>${escape(item.name)} | ${item.fatigue.toFixed(0)}%</option>`).join(''); }
    const ended = ['completed', 'failed'].includes(simulation.status), pause = document.getElementById('pause-action'); pause.textContent = simulation.status === 'paused' ? 'Resume' : 'Pause'; pause.disabled = !['running', 'paused'].includes(simulation.status); document.getElementById('fuel-action').disabled = ended;
    const summary = document.getElementById('simulation-summary'); summary.hidden = !ended;
    if (ended) { document.getElementById('summary-time').textContent = duration((new Date(simulation.simulated_at) - new Date(simulation.departure_at)) / 1000); document.getElementById('summary-distance').textContent = `${vehicle.distance_miles.toFixed(1)} mi`; document.getElementById('summary-stops').textContent = duration(vehicle.stopped_seconds); document.getElementById('summary-fuel').textContent = `${vehicle.fuel.toFixed(1)} gal`; document.getElementById('summary-route').textContent = (state.route || []).map(name => name.replace('_to_', ' to ')).join(' / '); }
  }

  async function act(action, payload = {}) { errorNode.hidden = true; const body = new URLSearchParams({_token: config.csrf, action, ...payload}); try { const response = await fetch(`/simulator/${config.id}/action`, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body}); const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Action failed'); await refresh(); } catch (error) { errorNode.textContent = error.message; errorNode.hidden = false; } }
  async function refresh() { if (polling) return; polling = true; try { const response = await fetch(`/simulator/${config.id}/status?after=${lastEvent}`, {cache: 'no-store'}); if (!response.ok) throw new Error('Simulation unavailable'); const simulation = await response.json(); render(simulation); addEvents(simulation.events || []); } catch (error) { errorNode.textContent = error.message; errorNode.hidden = false; } finally { polling = false; clearTimeout(pollTimer); pollTimer = setTimeout(refresh, 1000); } }
  document.getElementById('speed-control').addEventListener('submit', event => { event.preventDefault(); act('target_speed', {speed: event.currentTarget.speed.value}); }); document.getElementById('role-control').addEventListener('submit', event => { event.preventDefault(); act('roles', {driver: event.currentTarget.driver.value, copilot: event.currentTarget.copilot.value}); }); document.getElementById('pause-action').addEventListener('click', () => act(currentStatus === 'paused' ? 'resume' : 'pause')); document.getElementById('fuel-action').addEventListener('click', () => act('fuel'));
  refresh();
})();
