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
  const first = entries[0].price;
  const last = entries[entries.length - 1].price;
  panel.querySelector(".price-history-list").innerHTML = `
    <div class="table-scroll"><table class="schedule-table">
      <thead><tr><th>${L.since}</th><th>${L.price}</th><th>${L.change}</th><th></th></tr></thead>
      <tbody>${entries.map((e, i) => {
        const prev = i > 0 ? entries[i - 1].price : null;
        const change = prev && prev !== e.price ? `${e.price > prev ? "+" : ""}${num(e.price - prev)} (${pct(prev, e.price)})` : "—";
        return `<tr><td>${e.current ? L.current : e.date ? new Date(e.date).toLocaleDateString() : L.before}</td><td>${num(e.price)}</td>
          <td class="${prev && e.price > prev ? "negative" : ""}">${change}</td>
          <td class="reading-actions">${e.id ? `<button type="button" class="image-button" title="${L.delete}" onclick="priceHistoryDelete(this, ${e.id})"><i class="fa-solid fa-trash"></i></button>` : ""}</td></tr>`;
      }).join("")}</tbody></table></div>
    ${entries.length > 1 && first !== last ? `<p><strong>${L.total}: ${pct(first, last)}</strong></p>` : ""}`;
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

function priceHistoryDelete(button, id) {
  priceHistoryRequest(button.closest(".price-history"), { action: "delete", id });
}
