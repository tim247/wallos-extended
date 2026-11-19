function toggleContractRenewalFields() {
  const autoRenews = document.querySelector("#contract-auto-renews");
  const renewalGroup = document.querySelector("#contract-renewal-group");
  const endDateGroup = document.querySelector("#contract-end-date-group");
  if (autoRenews.checked) {
    renewalGroup.classList.remove("hide");
    endDateGroup.classList.add("hide");
  } else {
    renewalGroup.classList.add("hide");
    endDateGroup.classList.remove("hide");
  }
}

function toggleContractNotifyDays() {
  const notifyCheckbox = document.querySelector("#contract-notifications");
  const notifyDays = document.querySelector("#contract-notify-days");
  notifyDays.disabled = !notifyCheckbox.checked;
}

function resetContractForm() {
  const form = document.querySelector("#contract-form-data");
  form.reset();
  document.querySelector("#contract-id").value = "";
  setLogoPreview("");
  if (typeof resetPaperlessPanel === "function") resetPaperlessPanel();
  if (typeof resetFilesPanel === "function") resetFilesPanel();
  document.querySelector("#contract-form-title").textContent = translate("add_contract");
  document.querySelector("#contract-start-date").value = new Date().toISOString().split("T")[0];
  document.querySelector("#contract-notify-days").disabled = true;
  const deleteButton = document.querySelector("#delete-contract");
  deleteButton.style.display = "none";
  deleteButton.removeAttribute("onClick");
  document.querySelector("#contract-save-button").disabled = false;
  document.querySelector("#contract-switch-panel").hidden = true;
  toggleContractType();
  toggleContractRenewalFields();
}

function addContract() {
  resetContractForm();
  priceHistoryLoad("contract", 0);
  document.getElementById("contract-form").classList.add("is-open");
  document.querySelector("body").classList.add("no-scroll");
}

function closeContractForm() {
  document.getElementById("contract-form").classList.remove("is-open");
  document.querySelector("body").classList.remove("no-scroll");
  resetContractForm();
}

function fillContractForm(contract) {
  setLogoPreview(contract.logo || "");
  document.querySelector("#contract-form-title").textContent = translate("edit_contract");
  document.querySelector("#contract-id").value = contract.id;
  if (typeof loadPaperlessPanel === "function") loadPaperlessPanel("contract", contract.id);
  if (typeof loadFilesPanel === "function") loadFilesPanel("contract", contract.id);
  document.querySelector("#contract-name").value = contract.name;
  document.querySelector("#contract-provider").value = contract.provider;
  document.querySelector("#contract-number").value = contract.contract_number;
  document.querySelector("#contract-price").value = contract.price;
  document.querySelector("#contract-pending-price").value = contract.pending_price ?? "";
  document.querySelector("#contract-pending-date").value = contract.pending_price_date ?? "";
  document.querySelector("#contract-pending-details").open = !!contract.pending_price_date;
  document.querySelector("#contract-currency").value = contract.currency_id;
  document.querySelector("#contract-frequency").value = contract.frequency;
  document.querySelector("#contract-cycle").value = contract.cycle;
  document.querySelector("#contract-payment-method").value = contract.payment_method_id;
  document.querySelector("#contract-payer").value = contract.payer_user_id;
  document.querySelector("#contract-category").value = contract.category_id;
  document.querySelector("#contract-start-date").value = contract.start_date;
  document.querySelector("#contract-initial-term").value = contract.initial_term_months;
  document.querySelector("#contract-auto-renews").checked = !!contract.auto_renews;
  document.querySelector("#contract-tax-deductible").checked = !!contract.tax_deductible;
  document.querySelector("#contract-renewal-term").value = contract.renewal_term_months;
  document.querySelector("#contract-end-date").value = contract.end_date ?? "";
  document.querySelector("#contract-notice-months").value = contract.notice_period_months;
  document.querySelector("#contract-notice-days").value = contract.notice_period_days;
  document.querySelector("#contract-notifications").checked = !!contract.notify;
  const notifyDays = document.querySelector("#contract-notify-days");
  notifyDays.value = contract.notify_days_before;
  notifyDays.disabled = !contract.notify;
  document.querySelector("#contract-cancellation-status").checked = !!contract.cancellation_status;
  document.querySelector("#contract-url").value = contract.url;
  document.querySelector("#contract-notes").value = contract.notes;
  priceHistoryLoad("contract", contract.id);
  document.querySelector("#contract-type").value = contract.contract_type || "other";
  const details = (() => { try { return JSON.parse(contract.details || "{}") || {}; } catch (e) { return {}; } })();
  document.querySelectorAll('[name^="detail_"]').forEach((input) => {
    const value = details[input.name.slice(7)];
    // Older entries were free text ("250 Mbit"): a number field takes the number out of it.
    if (input.type === "number" && value !== undefined) { const n = parseFloat(String(value).replace(",", ".")); input.value = isNaN(n) ? "" : n; return; }
    input.value = value === undefined ? "" : new DOMParser().parseFromString(String(value), "text/html").documentElement.textContent;
  });
  const panel = document.querySelector("#contract-switch-panel");
  panel.hidden = false;
  panel.dataset.contract = contract.id;
  panel.dataset.monthly = document.querySelector(`.module-card[data-id="${contract.id}"]`)?.dataset.price || "0";
  panel.dataset.deadline = document.querySelector(`.module-card[data-id="${contract.id}"]`)?.dataset.deadlineText || "";
  document.querySelector("#switch-result").textContent = "";
  toggleContractType();
  toggleContractRenewalFields();

  const deleteButton = document.querySelector("#delete-contract");
  deleteButton.style.display = "block";
  deleteButton.setAttribute("onClick", `deleteContract(event, ${contract.id})`);

  document.getElementById("contract-form").classList.add("is-open");
  document.querySelector("body").classList.add("no-scroll");
}

function openEditContract(event, id) {
  event.stopPropagation();
  fetch(`endpoints/contracts/get.php?id=${id}`)
    .then((response) => response.json())
    .then((data) => {
      if (data.error) {
        showErrorMessage(data.error);
      } else {
        fillContractForm(data);
      }
    })
    .catch(() => showErrorMessage(translate("unknown_error")));
}

function deleteContract(event, id) {
  event.stopPropagation();
  if (!confirm(translate("confirm_delete"))) {
    return;
  }
  fetch("endpoints/contracts/delete.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": window.csrfToken,
    },
    body: JSON.stringify({ id: id }),
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message);
        window.location.reload();
      } else {
        showErrorMessage(data.message || translate("unknown_error"));
      }
    })
    .catch(() => showErrorMessage(translate("unknown_error")));
}

function archiveContract(event, id, archived) {
  event.stopPropagation();
  fetch("endpoints/contracts/archive.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/json",
      "X-CSRF-Token": window.csrfToken,
    },
    body: JSON.stringify({ id: id, archived: archived }),
  })
    .then((response) => response.json())
    .then((data) => {
      if (data.success) {
        showSuccessMessage(data.message);
        window.location.reload();
      } else {
        showErrorMessage(data.message || translate("unknown_error"));
      }
    })
    .catch(() => showErrorMessage(translate("unknown_error")));
}

document.addEventListener("DOMContentLoaded", function () {
  const form = document.querySelector("#contract-form-data");
  if (form) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      const submitButton = document.querySelector("#contract-save-button");
      submitButton.disabled = true;
      fetch(form.action, {
        method: "POST",
        headers: {
          "X-CSRF-Token": window.csrfToken,
        },
        body: new FormData(form),
      })
        .then((response) => response.json())
        .then((data) => {
          if (data.success) {
            showSuccessMessage(data.message);
            window.location.reload();
          } else {
            showErrorMessage(data.message || translate("unknown_error"));
          }
        })
        .catch(() => showErrorMessage(translate("unknown_error")))
        .finally(() => {
          submitButton.disabled = false;
        });
    });
  }
});

/* ---------- contract types, statutory terms, switch calculator, documents ---------- */

const CONSUMER_CONTRACT_TYPES = ["electricity", "gas", "internet", "mobile", "fitness", "streaming"];

function toggleContractType() {
  const type = document.querySelector("#contract-type").value;
  document.querySelectorAll(".contract-details").forEach((group) => group.classList.toggle("hide", group.dataset.type !== type));
  updateStatutoryHint();
}

// Consumer contracts from 1 March 2022: after the minimum term monthly renewal, one month's notice.
function updateStatutoryHint() {
  const type = document.querySelector("#contract-type").value;
  const start = document.querySelector("#contract-start-date").value;
  const applies = CONSUMER_CONTRACT_TYPES.includes(type) && start >= "2022-03-01";
  const already = document.querySelector("#contract-renewal-term").value === "1" && document.querySelector("#contract-notice-months").value === "1";
  document.querySelector("#contract-statutory-hint").classList.toggle("hide", !applies || already);
}

function applyStatutoryTerms() {
  document.querySelector("#contract-auto-renews").checked = true;
  document.querySelector("#contract-renewal-term").value = 1;
  document.querySelector("#contract-notice-months").value = 1;
  document.querySelector("#contract-notice-days").value = 0;
  toggleContractRenewalFields();
  updateStatutoryHint();
}

function compareContractSwitch() {
  const panel = document.querySelector("#contract-switch-panel");
  const months = Number(document.querySelector("#switch-months").value) || 24;
  const offer = Number(document.querySelector("#switch-offer").value);
  const result = document.querySelector("#switch-result");
  if (!offer) { result.textContent = ""; return; }
  const current = Number(panel.dataset.monthly) * months;
  const total = offer * months - (Number(document.querySelector("#switch-bonus").value) || 0) + (Number(document.querySelector("#switch-fee").value) || 0);
  const money = new Intl.NumberFormat(navigator.language, { style: "currency", currency: window.mainCurrencyCode || "EUR" });
  const saving = current - total;
  result.innerHTML = "";
  const line = document.createElement("span");
  line.className = saving >= 0 ? "positive" : "negative";
  line.textContent = translate(saving >= 0 ? "contract_switch_saves" : "contract_switch_costs")
    .replace("%1", money.format(Math.abs(saving))).replace("%2", months);
  result.appendChild(line);
  if (panel.dataset.deadline) {
    result.append(" · " + translate("contract_switch_deadline").replace("%s", panel.dataset.deadline));
  }
}

function extractContractData() {
  const contractId = Number(document.querySelector("#contract-switch-panel").dataset.contract);
  fetch("endpoints/insurance/extract.php", {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
    body: JSON.stringify({ type: "contract", item_id: contractId }),
  })
    .then((r) => r.json())
    .then((data) => {
      if (!data.success) { showErrorMessage(data.message || translate("unknown_error")); return; }
      const fields = { policy_number: "#contract-number", price: "#contract-price", start_date: "#contract-start-date", initial_term_months: "#contract-initial-term" };
      Object.entries(fields).forEach(([key, selector]) => {
        const input = document.querySelector(selector);
        if (data.data[key] !== undefined && (!input.value || (key === "initial_term_months" && input.value === "12"))) input.value = data.data[key];
      });
      (Object.keys(data.data).length ? showSuccessMessage : showErrorMessage)(data.message);
    })
    .catch(() => showErrorMessage(translate("unknown_error")));
}

document.addEventListener("DOMContentLoaded", () => {
  document.querySelector("#contract-start-date")?.addEventListener("change", updateStatutoryHint);
  document.querySelector("#contract-type")?.addEventListener("change", updateStatutoryHint);
});
