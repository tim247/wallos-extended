// Dashboard search: results unfold below the box while typing, grouped by area.
// ↑/↓ choose, Enter opens the chosen hit (or the full search page), Esc closes, "/" focuses the box.
(() => {
  const form = document.querySelector('.dashboard-search');
  if (!form) return;
  const input = form.querySelector('input');
  const panel = form.querySelector('.dashboard-search-results');
  let timer = null;
  let request = 0;
  let active = -1;

  const options = () => [...panel.querySelectorAll('[role=option]')];
  const open = (state) => {
    panel.hidden = !state;
    input.setAttribute('aria-expanded', state ? 'true' : 'false');
    if (!state) active = -1;
  };
  const highlight = (index) => {
    const list = options();
    if (!list.length) return;
    active = (index + list.length) % list.length;
    list.forEach((option, i) => option.classList.toggle('active', i === active));
    list[active].scrollIntoView({ block: 'nearest' });
    input.setAttribute('aria-activedescendant', list[active].id);
  };

  const render = (data, query) => {
    panel.replaceChildren();
    if (!data.hits.length) {
      const empty = document.createElement('div');
      empty.className = 'dashboard-search-empty';
      empty.textContent = form.dataset.empty;
      panel.append(empty);
    }
    let group = null;
    data.hits.forEach((hit, index) => {
      if (hit.area !== group) {
        group = hit.area;
        const title = document.createElement('div');
        title.className = 'dashboard-search-group';
        title.textContent = data.labels[hit.area] || hit.area;
        panel.append(title);
      }
      const link = document.createElement('a');
      link.href = hit.href;
      link.id = 'dashboard-search-hit-' + index;
      link.setAttribute('role', 'option');
      const icon = document.createElement('i');
      icon.className = 'fa-solid ' + hit.icon;
      const name = document.createElement('span');
      name.className = 'dashboard-search-name';
      name.textContent = hit.name;
      link.append(icon, name);
      if (hit.info) {
        const info = document.createElement('small');
        info.textContent = hit.info.slice(0, 60);
        link.append(info);
      }
      panel.append(link);
    });
    const all = document.createElement('a');
    all.href = 'search.php?q=' + encodeURIComponent(query);
    all.className = 'dashboard-search-all';
    all.id = 'dashboard-search-all';
    all.setAttribute('role', 'option');
    all.textContent = form.dataset.all;
    panel.append(all);
    open(true);
  };

  input.addEventListener('input', () => {
    clearTimeout(timer);
    const query = input.value.trim();
    if (query.length < 2) {
      open(false);
      return;
    }
    timer = setTimeout(() => {
      const current = ++request;
      fetch('endpoints/search/query.php?q=' + encodeURIComponent(query))
        .then((response) => response.json())
        .then((data) => {
          if (current === request && data.success) render(data, query); // ignore late answers
        })
        .catch(() => open(false));
    }, 200);
  });

  input.addEventListener('keydown', (event) => {
    if (event.key === 'ArrowDown' && !panel.hidden) {
      event.preventDefault();
      highlight(active + 1);
    } else if (event.key === 'ArrowUp' && !panel.hidden) {
      event.preventDefault();
      highlight(active - 1);
    } else if (event.key === 'Enter' && active >= 0 && !panel.hidden) {
      event.preventDefault();
      options()[active].click();
    } else if (event.key === 'Escape') {
      open(false);
    }
  });
  input.addEventListener('focus', () => {
    if (panel.childElementCount && input.value.trim().length >= 2) open(true);
  });
  document.addEventListener('click', (event) => {
    if (!form.contains(event.target)) open(false);
  });
  document.addEventListener('keydown', (event) => {
    const typing = /input|textarea|select/i.test(document.activeElement?.tagName || '') || document.activeElement?.isContentEditable;
    if (event.key === '/' && !typing) {
      event.preventDefault();
      input.focus();
    }
  });
})();
