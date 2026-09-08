(() => {
  const policy = window.CannonMinerPasswordPolicy || {minimum_length: 12, minimum_strength: 'strong'};
  const labels = {weak: 'Weak', fair: 'Fair', strong: 'Strong', very_strong: 'Very strong'};
  const requiredLength = policy.minimum_strength === 'very_strong' ? Math.max(16, Number(policy.minimum_length) + 4) : Number(policy.minimum_length);
  document.querySelectorAll('[data-password-meter]').forEach((input) => {
    const form = input.closest('form');
    let meter = form.querySelector('.password-meter');
    if (!meter || meter.dataset.bound) {
      meter = document.createElement('div'); meter.className = 'password-meter'; meter.innerHTML = '<div><i></i></div><span>Weak</span>';
      form.append(meter);
    }
    meter.dataset.bound = 'true';
    let hints = meter.nextElementSibling;
    if (!hints || !hints.classList.contains('password-hints')) {
      hints = document.createElement('div'); hints.className = 'password-hints';
      hints.innerHTML = '<span data-length></span><span data-types></span>';
      meter.after(hints);
    }
    const update = () => {
      const value = input.value, classes = [/[A-Z]/, /[a-z]/, /[0-9]/, /[^A-Za-z0-9]/].filter((rule) => rule.test(value)).length;
      let strength = 'weak';
      if (value.length >= Math.max(16, Number(policy.minimum_length) + 4) && classes === 4) strength = 'very_strong';
      else if (value.length >= Number(policy.minimum_length) && classes === 4) strength = 'strong';
      else if (value.length >= Math.max(8, Number(policy.minimum_length) - 2) && classes >= 3) strength = 'fair';
      meter.dataset.strength = strength; meter.querySelector('span').textContent = labels[strength];
      const shownLength = Math.min(value.length, requiredLength);
      hints.querySelector('[data-length]').textContent = `${shownLength} / ${requiredLength} characters`;
      hints.querySelector('[data-types]').textContent = `${classes} / 4 character types`;
      hints.querySelector('[data-length]').classList.toggle('met', value.length >= requiredLength);
      hints.querySelector('[data-types]').classList.toggle('met', classes === 4);
    };
    input.addEventListener('input', update); update();
  });
})();
