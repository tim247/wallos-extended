// Toolbar for contracts and insurances (includes/module_filter_bar.php).
// Filters: several items in one group = OR, groups and search together = AND.
document.addEventListener("DOMContentLoaded", () => {
  const list = document.querySelector(".module-cards");
  const search = document.getElementById("module-search");
  if (!list || !search) return;
  const cards = [...list.querySelectorAll(".module-card")];
  const filterMenu = document.getElementById("module-filter");
  const sortMenu = document.getElementById("module-sort");
  const page = location.pathname.split("/").pop() || "page";
  const store = (key, value) => { try { localStorage.setItem(`${page}:${key}`, value); } catch (e) {} };
  const stored = (key) => { try { return localStorage.getItem(`${page}:${key}`); } catch (e) { return null; } };

  const empty = document.createElement("p");
  empty.className = "module-filter-empty";
  empty.hidden = true;
  empty.textContent = translate("no_matching_entries");
  list.after(empty);

  const apply = () => {
    const text = search.value.trim().toLowerCase();
    const active = {};
    filterMenu?.querySelectorAll(".filtermenu-submenu").forEach((group) => {
      const values = [...group.querySelectorAll(".filter-item.selected")].map((item) => item.dataset.value);
      if (values.length) active[group.dataset.filter] = values;
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
    document.getElementById("module-filter-button")?.classList.toggle("active", Object.keys(active).length > 0);
  };

  const sortBy = (key) => {
    const value = (card) => key === "price" ? -parseFloat(card.dataset.price || 0)
      : key === "deadline" ? (card.dataset.deadline || "9999")
      : key === "member" ? (card.dataset.memberName || "") + card.dataset.name
      : card.dataset.name;
    cards.sort((a, b) => {
      const x = value(a), y = value(b);
      return typeof x === "number" ? x - y : x.localeCompare(y, undefined, { sensitivity: "base" });
    }).forEach((card) => list.appendChild(card));
    sortMenu.querySelectorAll("li").forEach((li) => li.classList.toggle("selected", li.dataset.sort === key));
    store("sort", key);
  };

  const setView = (view) => {
    list.classList.toggle("grid", view === "grid");
    document.querySelectorAll("#module-view-toggle .view-toggle-button")
      .forEach((button) => button.classList.toggle("selected", button.dataset.view === view));
    store("view", view);
  };

  search.addEventListener("input", apply);
  document.getElementById("module-search-clear").addEventListener("click", () => { search.value = ""; apply(); search.focus(); });

  document.getElementById("module-filter-button")?.addEventListener("click", () => filterMenu.classList.toggle("is-open"));
  filterMenu?.addEventListener("click", (event) => {
    const item = event.target.closest(".filter-item");
    if (!item) return;
    item.classList.toggle("selected");
    apply();
  });

  document.getElementById("module-sort-button").addEventListener("click", () => sortMenu.classList.toggle("is-open"));
  sortMenu.addEventListener("click", (event) => {
    const li = event.target.closest("li");
    if (!li) return;
    sortBy(li.dataset.sort);
    sortMenu.classList.remove("is-open");
  });

  document.getElementById("module-view-toggle").addEventListener("click", (event) => {
    const button = event.target.closest(".view-toggle-button");
    if (button) setView(button.dataset.view);
  });

  // Close the menus on a click elsewhere.
  document.addEventListener("click", (event) => {
    if (filterMenu && !event.target.closest(".filtermenu")) filterMenu.classList.remove("is-open");
    if (!event.target.closest(".sort-container")) sortMenu.classList.remove("is-open");
  });

  sortBy(stored("sort") || "name");
  setView(stored("view") || "list");
});

// Phones: the search field folds out over the toolbar, like on the subscriptions page.
function openModuleSearch() {
  const box = document.querySelector(".top-actions .search");
  box.classList.add("mobile-expanded");
  const input = document.getElementById("module-search");
  input.focus();
  input.addEventListener("blur", () => { if (!input.value) box.classList.remove("mobile-expanded"); }, { once: true });
}
