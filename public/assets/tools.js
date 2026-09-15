(() => {
  const form = document.getElementById('cruise-calculator');
  if (!form) return;
  const output = id => document.getElementById(id);
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
    const acceleration = number('acceleration'), deceleration = number('deceleration');
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
  calculate();
})();
