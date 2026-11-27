function toggleInsuranceNotifyDays() {
  const notifyCheckbox = document.querySelector("#insurance-notifications");
  const notifyDays = document.querySelector("#insurance-notify-days");
  notifyDays.disabled = !notifyCheckbox.checked;
}

function resetInsuranceForm() {
  const form = document.querySelector("#insurance-form-data");
  form.reset();
  document.querySelector("#insurance-id").value = "";
  setLogoPreview("");
  if (typeof resetPaperlessPanel === "function") resetPaperlessPanel();
  if (typeof resetFilesPanel === "function") resetFilesPanel();
  document.querySelector("#insurance-form-title").textContent = translate("add_insurance");
  document.querySelector("#insurance-start-date").value = new Date().toISOString().split("T")[0];
  document.querySelector("#insurance-notify-days").disabled = true;
  const deleteButton = document.querySelector("#delete-insurance");
  deleteButton.style.display = "none";
  deleteButton.removeAttribute("onClick");
  document.querySelector("#insurance-save-button").disabled = false;
  delete document.querySelector("#insurance-cancel-mode").dataset.touched;
  presetInsuranceCancelMode();
  toggleInsuranceKfz();
  document.querySelector("#insurance-claims-panel").hidden = true;
  priceHistoryLoad("insurance", 0);
  document.querySelector("#insurance-more-details").open = false;
}

function addInsurance() {
  resetInsuranceForm();
  document.getElementById("insurance-form").classList.add("is-open");
  document.querySelector("body").classList.add("no-scroll");
}

function closeInsuranceForm() {
  document.getElementById("insurance-form").classList.remove("is-open");
  document.querySelector("body").classList.remove("no-scroll");
  resetInsuranceForm();
}

function fillInsuranceForm(insurance) {
  setLogoPreview(insurance.logo || "");
  document.querySelector("#insurance-form-title").textContent = translate("edit_insurance");
  document.querySelector("#insurance-id").value = insurance.id;
  if (typeof loadPaperlessPanel === "function") loadPaperlessPanel("insurance", insurance.id);
  if (typeof loadFilesPanel === "function") loadFilesPanel("insurance", insurance.id);
  document.querySelector("#insurance-name").value = insurance.name;
  document.querySelector("#insurance-insurer").value = insurance.insurer;
  document.querySelector("#insurance-policy-number").value = insurance.policy_number;
  setInsuranceType(insurance.insurance_type);
  document.querySelector("#insurance-cancel-mode").value = insurance.cancel_mode || "cancellable";
  document.querySelector("#insurance-cancel-mode").dataset.touched = "1"; // keep what was saved
  toggleInsuranceCancelArea();
  document.querySelector("#insurance-price").value = insurance.price;
  document.querySelector("#insurance-currency").value = insurance.currency_id;
  document.querySelector("#insurance-frequency").value = insurance.frequency;
  document.querySelector("#insurance-cycle").value = insurance.cycle;
  document.querySelector("#insurance-coverage").value = insurance.coverage_amount ?? "";
  document.querySelector("#insurance-deductible").value = insurance.deductible ?? "";
  document.querySelector("#insurance-payment-method").value = insurance.payment_method_id;
  document.querySelector("#insurance-payer").value = insurance.payer_user_id;
  document.querySelector("#insurance-start-date").value = insurance.start_date;
  document.querySelector("#insurance-renewal-term").value = insurance.renewal_term_months;
  document.querySelector("#insurance-initial-term").value = insurance.initial_term_months || 12;
  const decode = (v) => new DOMParser().parseFromString(v || "", "text/html").documentElement.textContent;
  document.querySelector("#insurance-coverage-notes").value = decode(insurance.coverage_notes);
  document.querySelector("#insurance-contact-name").value = decode(insurance.contact_name);
  document.querySelector("#insurance-contact-phone").value = decode(insurance.contact_phone);
  document.querySelector("#insurance-contact-email").value = insurance.contact_email || "";
  document.querySelector("#insurance-last-review").value = insurance.last_review || "";
  document.querySelector("#insurance-kfz-sf").value = decode(insurance.kfz_sf_class);
  document.querySelector("#insurance-kfz-km").value = Number(insurance.kfz_km) || "";
  document.querySelector("#insurance-kfz-type").value = decode(insurance.kfz_type_class);
  document.querySelector("#insurance-kfz-region").value = decode(insurance.kfz_region_class);
  const insured = String(insurance.insured_persons || "").split(",").filter(Boolean);
  document.querySelectorAll('input[name="insured_persons[]"]').forEach((box) => { box.checked = insured.includes(box.value); });
  document.querySelector("#insurance-more-details").open = !!(insurance.coverage_notes || insurance.contact_name || insurance.contact_phone);
  document.querySelector("#insurance-parent").value = insurance.parent_id || "";
  [...document.querySelectorAll("#insurance-parent option")].forEach((o) => { o.hidden = o.value === String(insurance.id); });
  document.querySelector("#insurance-refund").value = Number(insurance.refund_amount) || "";
  toggleInsuranceCancelArea();
  toggleInsuranceKfz();
  loadInsuranceClaims(insurance.id);
  priceHistoryLoad("insurance", insurance.id);
  document.querySelector("#insurance-auto-renews").checked = !!insurance.auto_renews;
  document.querySelector("#insurance-tax-deductible").checked = !!insurance.tax_deductible;
  document.querySelector("#insurance-end-date").value = insurance.end_date ?? "";
  document.querySelector("#insurance-notice-months").value = insurance.notice_period_months;
  document.querySelector("#insurance-notice-days").value = insurance.notice_period_days;
  document.querySelector("#insurance-notifications").checked = !!insurance.notify;
  const notifyDays = document.querySelector("#insurance-notify-days");
  notifyDays.value = insurance.notify_days_before;
  notifyDays.disabled = !insurance.notify;
  document.querySelector("#insurance-cancellation-status").checked = !!insurance.cancellation_status;
  document.querySelector("#insurance-url").value = insurance.url;
  document.querySelector("#insurance-notes").value = insurance.notes;

  const deleteButton = document.querySelector("#delete-insurance");
  deleteButton.style.display = "block";
  deleteButton.setAttribute("onClick", `deleteInsurance(event, ${insurance.id})`);

  document.getElementById("insurance-form").classList.add("is-open");
  document.querySelector("body").classList.add("no-scroll");
}

function openEditInsurance(event, id) {
  event.stopPropagation();
  fetch(`endpoints/insurance/get.php?id=${id}`)
    .then((response) => response.json())
    .then((data) => {
      if (data.error) {
        showErrorMessage(data.error);
      } else {
        fillInsuranceForm(data);
      }
    })
    .catch(() => showErrorMessage(translate("unknown_error")));
}

function deleteInsurance(event, id) {
  event.stopPropagation();
  if (!confirm(translate("confirm_delete"))) {
    return;
  }
  fetch("endpoints/insurance/delete.php", {
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

function archiveInsurance(event, id, archived) {
  event.stopPropagation();
  fetch("endpoints/insurance/archive.php", {
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
  const form = document.querySelector("#insurance-form-data");
  if (form) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      const submitButton = document.querySelector("#insurance-save-button");
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

function setInsuranceType(stored) {
  const select = document.querySelector("#insurance-type");
  // Stored text is HTML-escaped by the server; option values are the decoded text.
  const value = new DOMParser().parseFromString(stored, "text/html").documentElement.textContent;
  if (![...select.options].some((option) => option.value === value)) {
    select.insertBefore(new Option(value, value), select.querySelector('option[value="__new"]'));
  }
  select.value = value;
}

document.addEventListener("DOMContentLoaded", () => {
  const select = document.querySelector("#insurance-type");
  if (!select) return;
  let previous = select.value;
  select.addEventListener("focus", () => { previous = select.value; });
  select.addEventListener("change", () => {
    if (select.value !== "__new") { previous = select.value; return; }
    const name = (prompt(select.querySelector('option[value="__new"]').dataset.prompt) || "").trim().slice(0, 40);
    if (!name) { select.value = previous; return; }
    const existing = [...select.options].find((option) => option.text.toLowerCase() === name.toLowerCase());
    if (existing) { select.value = existing.value; return; }
    select.insertBefore(new Option(name, name), select.querySelector('option[value="__new"]'));
    select.value = name;
  });
});

// How an insurance can be ended: preselected by its type until the user picks one.
const INSURANCE_DEFAULT_CANCEL_MODE = { health: "switch_only", pension: "not_cancellable" };

function presetInsuranceCancelMode() {
  const mode = document.querySelector("#insurance-cancel-mode");
  if (!mode.dataset.touched) {
    mode.value = INSURANCE_DEFAULT_CANCEL_MODE[document.querySelector("#insurance-type").value] || "cancellable";
  }
  toggleInsuranceCancelArea();
}

function toggleInsuranceCancelArea() {
  const mode = document.querySelector("#insurance-cancel-mode").value;
  const inPolicy = !!document.querySelector("#insurance-parent")?.value;
  // A building block follows the term and notice period of its policy.
  document.querySelector("#insurance-cancel-area").classList.toggle("hide", mode !== "cancellable" || inPolicy);
  document.querySelector("#insurance-cancel-mode").closest(".form-group").classList.toggle("hide", inPolicy);
  document.querySelector("#insurance-cancel-mode-hint").textContent =
    mode === "switch_only" ? translate("cancel_mode_switch_only_hint") : mode === "not_cancellable" ? translate("cancel_mode_not_cancellable_hint") : "";
}

document.addEventListener("DOMContentLoaded", () => {
  document.querySelector("#insurance-type")?.addEventListener("change", presetInsuranceCancelMode);
});

/* ---------- motor data, claims, balance, premium development, documents ---------- */

function toggleInsuranceKfz() {
  document.querySelector("#insurance-kfz-group").classList.toggle("hide", document.querySelector("#insurance-type").value !== "car");
}

function insuranceClaimsRequest(body) {
  return fetch("endpoints/insurance/claims.php", {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
    body: JSON.stringify(body),
  }).then((r) => r.json());
}

function escapeText(value) {
  const div = document.createElement("div");
  div.textContent = value ?? "";
  return div.innerHTML;
}

function renderInsuranceClaims(data) {
  const money = new Intl.NumberFormat(navigator.language, { style: "currency", currency: data.currency });
  const percent = new Intl.NumberFormat(navigator.language, { maximumFractionDigits: 1 });
  const b = data.balance;
  const w = data.worth;
  document.querySelector("#insurance-claim-hint").textContent = w.break_even > 0 ? translate("claim_break_even").replace("%s", money.format(w.break_even)) : "";
  document.querySelector("#insurance-balance").innerHTML = `
    <p>${translate("premiums_paid")}: <strong>${money.format(b.paid)}</strong> ·
       ${translate("benefits_received")}: <strong>${money.format(b.benefits)}</strong>
       ${b.ratio !== null ? `(${percent.format(b.ratio)} %)` : ""}
       ${data.last_review ? ` · ${translate("last_review")}: ${new Date(data.last_review).toLocaleDateString()}` : ""}</p>`;
  const statuses = ["reported", "in_review", "settled", "rejected"];
  document.querySelector("#insurance-claims-list").innerHTML = data.claims.length === 0 ? "" : `
    <div class="table-scroll"><table class="schedule-table">
      <thead><tr><th>${translate("date")}</th><th>${translate("claim_description")}</th><th>${translate("amount_claimed")}</th>
        <th>${translate("status")}</th><th>${translate("amount_paid")}</th><th></th></tr></thead>
      <tbody>${data.claims.map((c) => `
        <tr data-claim="${Number(c.id)}">
          <td>${new Date(c.claim_date).toLocaleDateString()}${c.claim_number ? `<br><small>${c.claim_number}</small>` : ""}</td>
          <td>${c.description}</td>
          <td>${money.format(c.amount_claimed)}<br><small class="${c.worth_it ? "" : "negative"}">${translate("after_deductible")}: ${money.format(c.payout)}${c.worth_it ? "" : " · " + translate("claim_not_worth")}</small></td>
          <td><select class="claim-status" aria-label="${translate("status")}">${statuses.map((s) => `<option value="${s}" ${s === c.status ? "selected" : ""}>${translate("claim_status_" + s)}</option>`).join("")}</select></td>
          <td><input type="number" class="claim-paid" step="0.01" min="0" value="${Number(c.amount_paid) || ""}" aria-label="${translate("amount_paid")}"></td>
          <td class="reading-actions"><button type="button" class="image-button claim-delete" title="${translate("delete")}"><i class="fa-solid fa-trash"></i></button></td>
        </tr>`).join("")}</tbody></table></div>`;
  const dev = Object.entries(data.development);
  document.querySelector("#insurance-development").innerHTML = dev.length < 2 ? "" : `
    <h4>${translate("premium_development")}</h4>
    <div class="table-scroll"><table class="schedule-table">
      <thead><tr><th>${translate("year")}</th><th>${translate("premium_per_year")}</th><th>${translate("change")}</th><th>${translate("inflation")}</th></tr></thead>
      <tbody>${dev.map(([year, d]) => `
        <tr><td>${year}</td><td>${money.format(d.premium)}</td>
          <td class="${d.change !== null && d.inflation !== null && d.change > d.inflation ? "negative" : ""}">${d.change === null ? "—" : (d.change > 0 ? "+" : "") + percent.format(d.change) + " %"}</td>
          <td>${d.inflation === null ? "—" : percent.format(d.inflation) + " %"}</td></tr>`).join("")}</tbody></table></div>`;
}

function loadInsuranceClaims(insuranceId) {
  const panel = document.querySelector("#insurance-claims-panel");
  panel.dataset.insurance = insuranceId;
  panel.hidden = false;
  document.querySelector("#claim-date").value = new Date().toISOString().slice(0, 10);
  insuranceClaimsRequest({ action: "list", insurance_id: insuranceId }).then((data) => {
    if (data.success) {
      panel.dataset.breakEven = data.worth.break_even;
      renderInsuranceClaims(data);
    }
  });
}

function claimAction(body) {
  const insuranceId = Number(document.querySelector("#insurance-claims-panel").dataset.insurance);
  return insuranceClaimsRequest({ insurance_id: insuranceId, ...body }).then((data) => {
    if (data.success) {
      renderInsuranceClaims(data);
      if (data.message) showSuccessMessage(data.message);
    } else {
      showErrorMessage(data.message || translate("unknown_error"));
    }
    return data;
  });
}

function addClaim() {
  claimAction({
    action: "add",
    claim_date: document.querySelector("#claim-date").value,
    description: document.querySelector("#claim-description").value,
    claim_number: document.querySelector("#claim-number").value,
    amount_claimed: document.querySelector("#claim-amount").value,
  }).then((data) => {
    if (data.success) ["#claim-description", "#claim-number", "#claim-amount"].forEach((s) => { document.querySelector(s).value = ""; });
  });
}

function markInsuranceReviewed() {
  claimAction({ action: "review" }).then((data) => {
    if (data.success) document.querySelector("#insurance-last-review").value = data.last_review || "";
  });
}

function extractPolicyData() {
  const insuranceId = Number(document.querySelector("#insurance-claims-panel").dataset.insurance);
  fetch("endpoints/insurance/extract.php", {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
    body: JSON.stringify({ insurance_id: insuranceId }),
  })
    .then((r) => r.json())
    .then((data) => {
      if (!data.success) {
        showErrorMessage(data.message || translate("unknown_error"));
        return;
      }
      // Only empty fields are filled; nothing is saved until the user saves.
      const fields = { policy_number: "#insurance-policy-number", price: "#insurance-price", start_date: "#insurance-start-date", initial_term_months: "#insurance-initial-term" };
      const filled = [];
      Object.entries(fields).forEach(([key, selector]) => {
        const input = document.querySelector(selector);
        if (data.data[key] !== undefined && (!input.value || (key === "initial_term_months" && input.value === "12"))) {
          input.value = data.data[key];
          filled.push(key);
        }
      });
      (Object.keys(data.data).length ? showSuccessMessage : showErrorMessage)(data.message + (filled.length ? ` (${filled.length})` : ""));
    })
    .catch(() => showErrorMessage(translate("unknown_error")));
}

document.addEventListener("DOMContentLoaded", () => {
  document.querySelector("#insurance-type")?.addEventListener("change", toggleInsuranceKfz);
  document.querySelector("#claim-amount")?.addEventListener("input", (event) => {
    const panel = document.querySelector("#insurance-claims-panel");
    const breakEven = Number(panel.dataset.breakEven || 0);
    const amount = Number(event.target.value || 0);
    event.target.classList.toggle("below-break-even", breakEven > 0 && amount > 0 && amount <= breakEven);
  });
  const list = document.querySelector("#insurance-claims-list");
  list?.addEventListener("change", (event) => {
    const row = event.target.closest("tr[data-claim]");
    if (!row) return;
    claimAction({ action: "update", id: Number(row.dataset.claim), status: row.querySelector(".claim-status").value, amount_paid: row.querySelector(".claim-paid").value });
  });
  list?.addEventListener("click", (event) => {
    const button = event.target.closest(".claim-delete");
    if (!button || !confirm(translate("confirm_delete"))) return;
    claimAction({ action: "delete", id: Number(button.closest("tr").dataset.claim) });
  });
});
