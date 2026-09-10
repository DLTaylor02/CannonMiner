(() => {
  document.querySelectorAll('.table-wrap table').forEach(table => {
    const headerCells = Array.from(table.querySelectorAll('thead th'));
    const headings = headerCells.map((heading, index) => {
      const directText = Array.from(heading.childNodes)
        .filter(node => node.nodeType === Node.TEXT_NODE)
        .map(node => node.textContent.trim())
        .filter(Boolean)
        .join(' ');
      return directText || (index === headerCells.length - 1 ? 'Actions' : 'Details');
    });

    table.classList.add('responsive-table');
    table.querySelectorAll('tbody tr').forEach(row => {
      const cells = Array.from(row.children);
      if (cells.length === 1 && Number(cells[0].getAttribute('colspan') || 1) > 1) {
        cells[0].classList.add('table-empty');
        return;
      }
      cells.forEach((cell, index) => {
        if (!cell.textContent.trim() && !cell.querySelector('form, button, a, input, select')) {
          cell.classList.add('table-cell-empty');
          return;
        }
        const label = document.createElement('span');
        label.className = 'mobile-table-label';
        label.textContent = headings[index] || 'Details';
        const sort = headerCells[index]?.querySelector('.sort-arrow');
        if (sort) label.append(' ', sort.cloneNode(true));
        cell.prepend(label);
      });
    });
  });
})();
