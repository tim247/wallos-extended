// Filter bar for contracts and insurances (includes/module_filter_bar.php).
// Several chips in one row = OR, rows and search together = AND.
document.addEventListener("DOMContentLoaded", () => {
  const bar = document.getElementById("module-filter");
  if (!bar) return;
  const search = document.getElementById("module-search");
  const cards = [...document.querySelectorAll(".module-cards .module-card")];
  const empty = bar.querySelector(".module-filter-empty");

  const apply = () => {
    const text = search.value.trim().toLowerCase();
    const active = {};
    bar.querySelectorAll(".module-filter-chips").forEach((row) => {
      const values = [...row.querySelectorAll('[aria-pressed="true"]')].map((chip) => chip.dataset.value);
      if (values.length) active[row.dataset.filter] = values;
    });
    let shown = 0;
    cards.forEach((card) => {
      const match = (!text || card.textContent.toLowerCase().includes(text))
        && Object.entries(active).every(([key, values]) => values.includes(card.dataset[key]));
      card.hidden = !match;
      if (match) shown++;
    });
    empty.hidden = shown > 0 || cards.length === 0;
    search.parentElement.classList.toggle("has-text", text !== "");
  };

  search.addEventListener("input", apply);
  bar.addEventListener("click", (event) => {
    const chip = event.target.closest(".module-filter-chip");
    if (!chip) return;
    chip.setAttribute("aria-pressed", chip.getAttribute("aria-pressed") === "true" ? "false" : "true");
    apply();
  });
});
