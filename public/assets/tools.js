(() => {
  const form = document.getElementById('cruise-calculator');
  if (!form) return;
  const output = id => document.getElementById(id);
  const routeControls = [...document.querySelectorAll('[data-shared-route]')];
  const speedControls = [...document.querySelectorAll('[data-shared-speed]')];
  const number = name => Number(form.elements[name].value);
  const time = hours => {
    const minutes = Math.max(0, Math.round(hours * 60));
    return `${Math.floor(minutes / 60)} hr ${String(minutes % 60).padStart(2, '0')} min`;
  };
  const calculate = () => {
    const option = form.elements.route.selectedOptions[0];
    const distance = Number(option?.dataset.distance);
    const average = number('average'), capacity = number('capacity'), mpg = number('mpg');
    const flow = 8, payment = 2;
    const acceleration = 5, deceleration = 5;
    const values = [distance, average, capacity, mpg, flow, acceleration, deceleration];
    if (values.some(value => !Number.isFinite(value) || value <= 0) || !Number.isFinite(payment) || payment < 0) {
      output('cruise-speed').textContent = 'Enter valid inputs';
      output('calculator-error').hidden = true;
      return;
    }

    const fuel = distance / mpg;
    const usableTank = capacity * 0.9;
    const stops = Math.max(0, Math.ceil(fuel / usableTank - 1e-10) - 1);
    const dispensed = stops * usableTank;
    const stoppedHours = stops * (usableTank / flow + payment) / 60;
    const cycles = stops + 1;
    const k = cycles * (1 / acceleration + 1 / deceleration) / 7200;
    const available = distance / average - stoppedHours;
    const discriminant = available * available - 4 * k * distance;
    const error = output('calculator-error');

    output('route-distance').textContent = `${distance.toFixed(1)} mi`;
    output('fuel-required').textContent = `${fuel.toFixed(1)} gal`;
    output('fuel-stops').textContent = String(stops);
    output('fuel-dispensed').textContent = `${dispensed.toFixed(1)} gal`;
    output('stopped-time').textContent = time(stoppedHours);
    output('target-time').textContent = time(distance / average);

    if (available <= 0 || discriminant < 0 || k <= 0) {
      output('cruise-speed').textContent = 'Not attainable';
      output('transition-time').textContent = '-';
      output('cruise-formula').textContent = `D / V + K x V + S = D / A; no real solution`;
      error.hidden = false;
      error.textContent = 'The target average cannot be reached with these stop and acceleration assumptions.';
      return;
    }
    const cruise = (available - Math.sqrt(discriminant)) / (2 * k);
    const transitionHours = cycles * cruise * (1 / acceleration + 1 / deceleration) / 3600;
    output('cruise-speed').textContent = `${cruise.toFixed(1)} mph`;
    output('transition-time').textContent = time(transitionHours);
    output('cruise-formula').textContent = `${distance.toFixed(1)} / V + ${k.toFixed(6)} x V + ${stoppedHours.toFixed(3)} = ${(distance / average).toFixed(3)} hr`;
    error.hidden = true;
  };
  form.addEventListener('input', calculate);
  form.addEventListener('change', calculate);

  const heatmap = output('departure-heatmap');
  const metric = output('heatmap-metric');
  const scale = document.querySelector('.heatmap-scale');
  const heatmapError = output('heatmap-error');
  const weekdays = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  let cells = [], request, timer;
  const duration = seconds => {
    const minutes = Math.round(seconds / 60);
    return `${Math.floor(minutes / 60)} hr ${String(minutes % 60).padStart(2, '0')} min`;
  };
  const metricValue = cell => Number(cell[metric.value]);
  const metricLabel = cell => metric.value === 'risk'
    ? `${(metricValue(cell) * 100).toFixed(1)}% risk`
    : `${duration(metricValue(cell))} ${metric.value === 'p90_seconds' ? '90th percentile' : 'expected'}`;
  const renderHeatmap = () => {
    const values = cells.map(metricValue).filter(Number.isFinite);
    const low = Math.min(...values), high = Math.max(...values), spread = high - low;
    let markup = '<span class="heatmap-corner">Hour</span>' + weekdays.map(day => `<strong class="heatmap-day">${day}</strong>`).join('');
    for (let hour = 0; hour < 24; hour++) {
      markup += `<strong class="heatmap-hour">${String(hour).padStart(2, '0')}:00</strong>`;
      for (let weekday = 1; weekday <= 7; weekday++) {
        const cell = cells.find(item => Number(item.weekday) === weekday && Number(item.hour) === hour);
        if (!cell) { markup += '<span class="heatmap-cell empty" title="No directly supported observations">-</span>'; continue; }
        const normalized = spread > 0 ? (metricValue(cell) - low) / spread : 0;
        const hue = 120 * (1 - normalized);
        markup += `<span class="heatmap-cell" style="--cell-color:hsl(${hue} 62% 42%);--cell-ink:${normalized > .58 ? '#fff' : '#111'}" title="${weekdays[weekday - 1]} ${String(hour).padStart(2, '0')}:00: ${metricLabel(cell)}; ${cell.samples} supported monthly pattern${cell.samples === 1 ? '' : 's'}">${metric.value === 'risk' ? `${Math.round(metricValue(cell) * 100)}%` : Math.round(metricValue(cell) / 60)}</span>`;
      }
    }
    heatmap.innerHTML = markup;
    scale.hidden = values.length === 0;
  };
  const loadHeatmap = async () => {
    request?.abort(); request = new AbortController();
    heatmap.innerHTML = '<p class="heatmap-loading">Calculating supported departure windows...</p>';
    heatmapError.hidden = true; scale.hidden = true;
    const route = routeControls[0].value, speed = Number(speedControls[0].value);
    if (!route || !Number.isFinite(speed) || speed <= 0) return;
    try {
      const response = await fetch(`/tools/heatmap?route=${encodeURIComponent(route)}&speed=${encodeURIComponent(speed)}`, {signal: request.signal});
      const payload = await response.json();
      if (!response.ok) throw new Error(payload.error || 'Unable to calculate the heatmap.');
      cells = payload.cells || []; renderHeatmap();
    } catch (error) {
      if (error.name === 'AbortError') return;
      heatmap.innerHTML = ''; heatmapError.textContent = error.message; heatmapError.hidden = false;
    }
  };
  const synchronize = source => {
    const controls = source.matches('[data-shared-route]') ? routeControls : speedControls;
    controls.forEach(control => { if (control !== source) control.value = source.value; });
    calculate(); clearTimeout(timer); timer = setTimeout(loadHeatmap, source.matches('select') ? 0 : 450);
  };
  [...routeControls, ...speedControls].forEach(control => {
    control.addEventListener(control.matches('select') ? 'change' : 'input', () => synchronize(control));
  });
  metric.addEventListener('change', renderHeatmap);
  calculate();
  loadHeatmap();
})();
