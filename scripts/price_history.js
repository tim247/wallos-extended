// Price history panel of the contract and insurance forms (includes/price_history_panel.php).

function priceHistoryRequest(panel, body) {
  return fetch("endpoints/pricehistory/save.php", {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
    body: JSON.stringify({ type: panel.dataset.type, item_id: Number(panel.dataset.item), ...body }),
  }).then((r) => r.json()).then((data) => {
    if (!data.success) {
      showErrorMessage(data.message);
      return false;
    }
    priceHistoryRender(panel, data);
    // The list may have changed the current price: keep the form's price field in step, or saving it would undo that.
    const field = panel.dataset.priceField ? document.querySelector(panel.dataset.priceField) : null;
    if (field && typeof data.current === "number") field.value = data.current;
    return true;
  });
}

function priceHistoryRender(panel, data) {
  const L = JSON.parse(panel.dataset.labels);
  const num = (n) => n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  const pct = (a, b) => (b > a ? "+" : "") + ((b / a - 1) * 100).toLocaleString(undefined, { maximumFractionDigits: 1 }) + " %";
  const entries = data.entries;
  if (entries.length === 0) {
    panel.querySelector(".price-history-list").innerHTML = `<p class="settings-notes">${L.empty}</p>`;
    return;
  }
  // The total runs up to today's price; a planned change is shown but not counted.
  const applied = entries.filter((e) => !e.pending);
  const first = applied[0].price;
  const last = applied[applied.length - 1].price;
  const label = (e) => e.pending ? `${L.planned} ${new Date(e.date).toLocaleDateString()}`
    : e.current ? L.current : e.date ? new Date(e.date).toLocaleDateString() : L.before;
  const action = (e) => e.pending ? "delete_pending" : e.start_of ? "delete_start" : e.id ? "delete" : null;
  panel.querySelector(".price-history-list").innerHTML = `
    <div class="table-scroll"><table class="schedule-table">
      <thead><tr><th>${L.since}</th><th>${L.price}</th><th>${L.change}</th><th></th></tr></thead>
      <tbody>${entries.map((e, i) => {
        const prev = i > 0 ? entries[i - 1].price : null;
        const change = prev && prev !== e.price ? `${e.price > prev ? "+" : ""}${num(e.price - prev)} (${pct(prev, e.price)})` : "—";
        return `<tr class="${e.pending ? "price-planned" : ""}"><td>${label(e)}</td><td>${num(e.price)}</td>
          <td class="${prev && e.price > prev ? "negative" : ""}">${change}</td>
          <td class="reading-actions">${action(e) ? `<button type="button" class="image-button" title="${L.delete}" onclick="priceHistoryDelete(this, ${e.id || e.start_of || 0}, '${action(e)}')"><i class="fa-solid fa-trash"></i></button>` : ""}</td></tr>`;
      }).join("")}</tbody></table></div>
    ${applied.length > 1 && first !== last ? `<p><strong>${L.total}: ${pct(first, last)}</strong></p>` : ""}`;
}

function priceHistoryLoad(type, itemId) {
  const panel = document.getElementById(`${type}-price-history`);
  panel.dataset.item = itemId;
  panel.hidden = !itemId;
  panel.querySelector(".price-history-list").innerHTML = "";
  if (itemId) {
    priceHistoryRequest(panel, { action: "list" });
  }
}

function priceHistoryAdd(button) {
  const panel = button.closest(".price-history");
  const date = panel.querySelector(".price-history-date");
  const price = panel.querySelector(".price-history-price");
  priceHistoryRequest(panel, { action: "add", date: date.value, price: price.value })
    .then((ok) => {
    if (!ok) return;
    date.value = "";
    price.value = "";
  });
}

// action: delete (an entry), delete_start (the starting price), delete_pending (the planned change)
function priceHistoryDelete(button, id, action = "delete") {
  priceHistoryRequest(button.closest(".price-history"), { action, id });
}
