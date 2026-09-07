(() => {
  const policy = window.CannonMinerPasswordPolicy || {minimum_length: 12, minimum_strength: 'strong'};
  const labels = {weak: 'Weak', fair: 'Fair', strong: 'Strong', very_strong: 'Very strong'};
  document.querySelectorAll('[data-password-meter]').forEach((input) => {
    let meter = input.closest('form').querySelector('.password-meter');
    if (!meter || meter.dataset.bound) {
      meter = document.createElement('div'); meter.className = 'password-meter'; meter.innerHTML = '<div><i></i></div><span>Weak</span>';
      input.parentElement.after(meter);
    }
    meter.dataset.bound = 'true';
    const update = () => {
      const value = input.value, classes = [/[A-Z]/, /[a-z]/, /[0-9]/, /[^A-Za-z0-9]/].filter((rule) => rule.test(value)).length;
      let strength = 'weak';
      if (value.length >= Math.max(16, Number(policy.minimum_length) + 4) && classes === 4) strength = 'very_strong';
      else if (value.length >= Number(policy.minimum_length) && classes === 4) strength = 'strong';
      else if (value.length >= Math.max(8, Number(policy.minimum_length) - 2) && classes >= 3) strength = 'fair';
      meter.dataset.strength = strength; meter.querySelector('span').textContent = labels[strength];
    };
    input.addEventListener('input', update); update();
  });
})();
