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
  let speedTimer = null;
  const fmt = new Intl.DateTimeFormat(undefined, {weekday: 'short', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', second: '2-digit', timeZoneName: 'short'});
  const escape = value => String(value).replace(/[&<>"']/g, char => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[char]));
  const duration = seconds => { seconds = Math.max(0, Math.round(Number(seconds) || 0)); const hours = Math.floor(seconds / 3600), minutes = Math.floor(seconds % 3600 / 60), rest = seconds % 60; return `${hours ? hours + 'h ' : ''}${minutes ? minutes + 'm ' : ''}${rest}s`; };
  const elapsedDuration = seconds => { seconds = Math.max(0, Math.round(Number(seconds) || 0)); return `${Math.floor(seconds / 3600)}h ${Math.floor(seconds % 3600 / 60)}m ${seconds % 60}s`; };
  const locations = {redball: 'Redball Garage, NY', portofino: 'Portofino Marina, CA', bar: 'Barstow, CA', big: 'Big Springs, NE', cole: 'Columbus East, OH', coln: 'Columbus North, OH', cov: 'Cove Fort, UT', den: 'Denver, CO', elr: 'El Reno, OK', har: 'Harrisburg, PA', nash: 'Nashville, TN', stl: 'St. Louis, IL', you: 'Youngstown, OH'};
  const locationName = node => locations[node] || String(node).replaceAll('_', ' ');
  const routeName = name => String(name).split('_to_').map(locationName).join(' to ');

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

  const eventText = (type, payload) => ({simulation_created: `Simulation created in ${payload.mode} mode`, segment_entered: `Entered ${routeName(payload.segment)}`, traffic_delay: `Traffic encountered; speed limited to ${payload.speed_cap} mph`, traffic_cap_lifted: `Traffic cleared; returning to ${payload.speed} mph`, weather_event: `${String(payload.weather || 'weather').replace(/^./, letter => letter.toUpperCase())}; speed limited to ${payload.speed_cap} mph`, police_event: payload.jailed ? 'Police stop ended the run' : 'Police stop added 30 minutes', road_event: `Road obstacle${payload.mitigated ? ' mitigated' : ''} +${duration(payload.delay_seconds)}`, flat_tire: 'Flat tire: controlled stop and 15-minute repair', segment_completed: `Arrived at ${locationName(payload.node)}`, route_choice_required: `Route choice required at ${locationName(payload.node)}`, fatigue_threshold: `${payload.driver} fatigue reached ${payload.fatigue}%`, driver_exhausted: `${payload.driver} reached full fatigue`, copilot_exhausted: `${payload.driver} must rest`, driver_change_requested: `Decelerated to change drivers (${duration(payload.seconds)})`, driver_changed: 'New driver took the wheel', driver_change_complete: `Returned to speed in ${duration(payload.seconds)}`, copilot_changed: 'Co-pilot assignment changed', target_speed_changed: `Target speed set to ${payload.speed} mph`, fuel_warning: `Fuel below ${payload.percent}%`, fuel_stop: `Fuel stop: ${payload.gallons} gal, ${duration(payload.seconds)}`, paused: 'Simulation paused', resumed: 'Simulation resumed', event_acknowledged: 'Crew responded to obstacle', crew_jailed: 'Crew taken to jail. Run ended.', out_of_fuel: 'Fuel exhausted. Run ended.', traffic_unavailable: `Run ended: ${payload.message}`, arrived_portofino: 'Arrived at Portofino Marina, CA'}[type] || String(type).replaceAll('_', ' '));

  function addEvents(events) {
    const list = document.getElementById('event-log');
    events.forEach(event => { lastEvent = Math.max(lastEvent, Number(event.id)); if (seenEvents.has(event.id)) return; seenEvents.add(event.id); const row = document.createElement('li'), time = document.createElement('time'), text = document.createElement('span'); time.textContent = fmt.format(new Date(event.simulated_at)); text.textContent = eventText(event.type, event.payload || {}); row.append(time, text); list.prepend(row); });
  }

  function renderDecision(state, status) {
    const dialog = document.getElementById('simulation-decision'), title = document.getElementById('decision-title'), copy = document.getElementById('decision-copy'), options = document.getElementById('decision-options'), pending = state.pending_decision || (status === 'awaiting_route' ? {type: 'route'} : null);
    if (!pending) { if (dialog.open) dialog.close(); return; }
    options.innerHTML = '';
    if (pending.type === 'route') { title.textContent = `Depart from ${locationName(state.node)}`; copy.textContent = 'Choose the next route segment.'; options.innerHTML = (state.choices || []).map(choice => `<button type="button" data-segment="${escape(choice.name)}">${escape(choice.end_label || locationName(choice.end))} | ${Number(choice.distance_miles).toFixed(0)} mi</button>`).join(''); options.querySelectorAll('button').forEach(button => button.addEventListener('click', () => act('choose_segment', {segment: button.dataset.segment}))); }
    else if (pending.type === 'driver') { title.textContent = pending.forced ? 'Driver exhausted' : 'Change driver'; copy.textContent = pending.forced ? 'The previous driver must rest until fatigue falls to 50%. Choose an available replacement.' : 'The vehicle has stopped. Choose a new driver to continue immediately.'; const current = state.drivers.find(driver => driver.role === 'driver'); const eligible = state.drivers.filter(driver => (!current || driver.id !== current.id) && !driver.locked_rest && driver.fatigue < 100); options.innerHTML = eligible.map(driver => `<button type="button" data-driver="${escape(driver.id)}">${escape(driver.name)} | ${driver.fatigue.toFixed(0)}% fatigue</button>`).join('') || '<p>No driver is currently rested enough. Rest recovery continues while this window is open.</p>'; options.querySelectorAll('button').forEach(button => button.addEventListener('click', () => act('change_driver', {driver: button.dataset.driver}))); }
    else if (pending.type === 'event') { const labels = {weather_event: 'Weather encountered', road_event: 'Road delay', police_event: 'Police stop', flat_tire: 'Flat tire'}; title.textContent = labels[pending.event] || 'Delay event'; if (pending.event === 'weather_event') copy.textContent = `${String(pending.weather).replace(/^./, letter => letter.toUpperCase())} limits the vehicle to ${pending.speed_cap} mph for this segment.`; else if (pending.event === 'flat_tire') copy.textContent = 'The vehicle will slow to a stop, spend 15 minutes replacing the tire, and accelerate back to speed.'; else if (pending.event === 'police_event') copy.textContent = 'The crew was released. The traffic stop adds 30 minutes to the run.'; else copy.textContent = `${pending.mitigated ? 'The crew reduced the impact. ' : ''}Expected delay: ${duration(pending.delay_seconds)}.`; options.innerHTML = '<button class="decision-ok" type="button" data-acknowledge>OK</button>'; options.querySelector('button').addEventListener('click', () => act('acknowledge_event')); }
    else if (pending.type === 'confirmation') { title.textContent = pending.title; copy.textContent = pending.message; options.innerHTML = '<button class="decision-ok" type="button" data-acknowledge>OK</button>'; options.querySelector('button').addEventListener('click', () => act('acknowledge_event')); }
    if (!dialog.open) dialog.showModal();
  }

  function render(simulation) {
    currentStatus = simulation.status;
    statusNode.textContent = simulation.status.replaceAll('_', ' ').replace(/\b\w/g, char => char.toUpperCase());
    timeNode.textContent = fmt.format(new Date(simulation.simulated_at));
    const state = simulation.state, vehicle = state.vehicle, segment = state.current_segment;
    const elapsed = (new Date(simulation.simulated_at) - new Date(simulation.departure_at)) / 1000; document.getElementById('sim-speed').textContent = `${Math.round(vehicle.current_speed)} mph`; document.getElementById('sim-fuel').textContent = `${vehicle.fuel.toFixed(1)} / ${vehicle.capacity.toFixed(1)} gal`; document.getElementById('sim-elapsed').textContent = elapsedDuration(elapsed); document.getElementById('sim-distance').textContent = `${vehicle.distance_miles.toFixed(1)} mi`; document.getElementById('sim-target').textContent = `${Math.round(vehicle.target_speed)} mph`;
    const speedInput = document.getElementById('speed-control'); if (document.activeElement !== speedInput) speedInput.value = Math.round(vehicle.target_speed);
    const fuelShare = 100 * vehicle.fuel / vehicle.capacity, fuelBar = document.getElementById('sim-fuel-bar'); fuelBar.style.width = fuelShare + '%'; fuelBar.style.background = fuelShare < 15 ? 'var(--red)' : fuelShare < 35 ? '#f0b429' : 'var(--green)';
    const trafficActive = segment?.traffic_speed_cap && segment.traffic_speed_cap < segment.cruising_speed && segment.elapsed_seconds < segment.traffic_cap_seconds; document.getElementById('simulation-location').textContent = segment ? `${locationName(segment.start)} to ${locationName(segment.end)}` : locationName(state.node); document.getElementById('simulation-traffic').textContent = segment ? `${segment.traffic_source} traffic${trafficActive ? ` | ${Number(segment.traffic_speed_cap).toFixed(0)} mph cap` : ''} | ${Math.round(100 * segment.elapsed_seconds / Math.max(1, segment.total_seconds))}% of segment` : state.traffic_mode + ' traffic'; const weatherNode = document.getElementById('simulation-weather'), trafficIcon = document.getElementById('simulation-traffic-icon'); weatherNode.hidden = !segment?.status_icon; weatherNode.textContent = segment?.status_icon || ''; weatherNode.title = segment?.weather ? `${segment.weather} | ${segment.cruising_speed} mph maximum` : ''; trafficIcon.hidden = !trafficActive; trafficIcon.title = trafficActive ? `Traffic | ${Number(segment.traffic_speed_cap).toFixed(0)} mph maximum` : ''; drawMap(segment);
    document.getElementById('driver-cards').innerHTML = state.drivers.map(driver => { const role = driver.role === 'driver' && state.driver_copilot ? 'driver / co-pilot' : driver.role; return `<article class="driver-card"><header><strong>${escape(driver.name)}</strong><span>${escape(role.replace('_', ' '))}</span></header><div class="driver-stats"><small><span>Endurance</span><strong>${driver.endurance}</strong></small><small><span>Driving</span><strong>${driver.driving}</strong></small><small><span>Co-piloting</span><strong>${driver.copilot}</strong></small></div><div class="fatigue-track"><i style="width:${driver.fatigue}%;background:${driver.fatigue >= 75 ? 'var(--red)' : driver.fatigue >= 50 ? '#f0b429' : 'var(--green)'}"></i></div><small>${driver.fatigue.toFixed(1)}% fatigue${driver.locked_rest ? ' | REST REQUIRED' : ''}</small></article>`; }).join('');
    const copilotSelect = document.getElementById('copilot-control'), driver = state.drivers.find(item => item.role === 'driver'), copilot = state.driver_copilot ? driver : state.drivers.find(item => item.role === 'copilot'), otherAvailable = state.drivers.some(item => item.id !== driver?.id && !item.locked_rest && item.fatigue < 100); if (document.activeElement !== copilotSelect) { copilotSelect.innerHTML = '<option value="">None</option>' + state.drivers.map(item => { const driverUnavailable = item.id === driver?.id && state.drivers.length > 1 && otherAvailable; return `<option value="${escape(item.id)}" ${copilot && copilot.id === item.id ? 'selected' : ''} ${item.locked_rest || driverUnavailable ? 'disabled' : ''}>${escape(item.name)} | ${item.fatigue.toFixed(0)}%</option>`; }).join(''); }
    const ended = ['completed', 'failed'].includes(simulation.status), decisionPending = ['event', 'confirmation'].includes(state.pending_decision?.type), pause = document.getElementById('pause-action'); pause.textContent = simulation.status === 'paused' && !decisionPending ? 'Resume' : 'Pause'; pause.disabled = ended || decisionPending || !['running', 'paused'].includes(simulation.status); document.getElementById('driver-action').disabled = simulation.status !== 'running' || decisionPending || state.drivers.length < 2; document.getElementById('fuel-action').disabled = simulation.status !== 'running' || decisionPending;
    const summary = document.getElementById('simulation-summary'); summary.hidden = !ended;
    if (ended) { if (document.getElementById('simulation-decision').open) document.getElementById('simulation-decision').close(); document.getElementById('summary-time').textContent = duration(elapsed); document.getElementById('summary-average').textContent = elapsed > 0 ? `${(vehicle.distance_miles / (elapsed / 3600)).toFixed(1)} mph` : '0.0 mph'; document.getElementById('summary-distance').textContent = `${vehicle.distance_miles.toFixed(1)} mi`; document.getElementById('summary-stops').textContent = duration(vehicle.stopped_seconds); document.getElementById('summary-fuel').textContent = `${vehicle.fuel.toFixed(1)} gal`; document.getElementById('summary-reason').textContent = state.failure_reason || state.end_reason || (simulation.status === 'completed' ? 'The crew reached Portofino Marina, CA.' : 'The run ended before reaching Portofino Marina.'); document.getElementById('summary-route').textContent = (state.route || []).map(routeName).join(' / '); } else renderDecision(state, simulation.status);
  }

  async function act(action, payload = {}) { errorNode.hidden = true; const body = new URLSearchParams({_token: config.csrf, action, ...payload}); try { const response = await fetch(`/simulator/${config.id}/action`, {method: 'POST', headers: {'Content-Type': 'application/x-www-form-urlencoded'}, body}); const result = await response.json(); if (!response.ok) throw new Error(result.error || 'Action failed'); await refresh(); } catch (error) { errorNode.dataset.source = 'action'; errorNode.textContent = error.message; errorNode.hidden = false; } }
  async function refresh() { if (polling) return; polling = true; try { const response = await fetch(`/simulator/${config.id}/status?after=${lastEvent}`, {cache: 'no-store'}); if (!response.ok) throw new Error('Simulation unavailable'); const simulation = await response.json(); if (errorNode.dataset.source === 'poll') errorNode.hidden = true; render(simulation); addEvents(simulation.events || []); } catch (error) { errorNode.dataset.source = 'poll'; errorNode.textContent = error.message; errorNode.hidden = false; } finally { polling = false; clearTimeout(pollTimer); pollTimer = setTimeout(refresh, 1000); } }
  document.getElementById('speed-control').addEventListener('input', event => { clearTimeout(speedTimer); const speed = event.currentTarget.value; if (speed === '') return; speedTimer = setTimeout(() => act('target_speed', {speed}), 350); }); document.getElementById('copilot-control').addEventListener('change', event => act('copilot', {copilot: event.currentTarget.value})); document.getElementById('pause-action').addEventListener('click', () => act(currentStatus === 'paused' ? 'resume' : 'pause')); document.getElementById('driver-action').addEventListener('click', () => act('request_driver_change')); document.getElementById('fuel-action').addEventListener('click', () => act('fuel'));
  refresh();
})();
