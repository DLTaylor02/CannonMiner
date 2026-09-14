(() => {
  const containers = document.querySelectorAll('[data-analysis-queue]');
  if (!containers.length) return;

  const refresh = async () => {
    try {
      const response = await fetch('/analysis-queue/status', {cache: 'no-store'});
      if (!response.ok) throw new Error('Queue status unavailable');
      const status = await response.json();
      const queued = Math.max(0, Number(status.automated_queued) || 0);
      const running = Math.max(0, Number(status.automated_running) || 0);
      const active = queued + running;
      containers.forEach(container => {
        const count = container.querySelector('[data-queue-count]');
        const label = container.querySelector('[data-queue-status]');
        const spinner = container.querySelector('[data-queue-spinner]');
        if (count) count.textContent = String(active);
        if (label) label.textContent = running
          ? `${queued} automated ${queued === 1 ? 'calculation' : 'calculations'} queued; automation running`
          : `${queued} automated ${queued === 1 ? 'calculation' : 'calculations'} queued`;
        if (spinner) {
          spinner.hidden = running === 0;
          spinner.classList.toggle('queue-spinner', running > 0);
        }
      });
    } catch {
      containers.forEach(container => {
        const label = container.querySelector('[data-queue-status]');
        if (label) label.textContent = 'Queue status unavailable';
      });
    }
  };

  refresh();
  setInterval(refresh, 60000);
})();
