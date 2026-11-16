// ── Chart instances ──────────────────────────────────────────────────────────
var loanChartInstance = null;

// ── Chart: annuity / interest-only balance over time ─────────────────────────
function loadLoanBalanceChart(container, labels, actualData, planData, todayIndex, currency, bausparData = []) {
  const planMap = {};
  planData.forEach((p) => { planMap[p.label] = p.y; });
  const allLabels = labels.slice();
  planData.forEach((p) => { if (!allLabels.includes(p.label)) allLabels.push(p.label); });
  // Months after the redemption belong to the Bausparvertrag (its loan repayment).
  const bausparMap = {};
  bausparData.forEach((p) => {
    bausparMap[p.label] = p.y;
    if (!allLabels.includes(p.label)) allLabels.push(p.label);
  });
  const actualSeries = allLabels.map((_, i) => (i < actualData.length ? actualData[i] : null));
  const planSeries = allLabels.map((label) => (label in planMap ? planMap[label] : null));
  const bausparSeries = allLabels.map((label) => (label in bausparMap ? bausparMap[label] : null));
  const money = apexMoney(currency);

  loanChartInstance = apexRender(container, (el, t) => {
    const b = apexBase(el, "line", 350);
    const series = [
      { name: translate("with_extra_payments"), data: actualSeries },
      { name: translate("plan_without_extra_payments"), data: planSeries },
    ];
    const colors = [t.main, "#999999"];
    if (bausparData.length) {
      series.push({ name: translate("bausparen_repayment_chart"), data: bausparSeries });
      colors.push(APEX_ORANGE);
    }
    return {
      ...b,
      series,
      colors,
      stroke: { curve: "smooth", width: 2, dashArray: series.map((_, i) => (i === 1 ? 6 : 0)) },
      markers: {
        size: 0,
        hover: { size: 4 },
        discrete: todayIndex >= 0 && todayIndex < allLabels.length
          ? [{ seriesIndex: 0, dataPointIndex: todayIndex, size: 5, fillColor: t.main, strokeColor: t.dark ? "#171B23" : "#FFFFFF" }]
          : [],
      },
      xaxis: { ...b.xaxis, categories: allLabels, tickAmount: 12, labels: { ...b.xaxis.labels, rotate: -45, hideOverlappingLabels: true } },
      yaxis: { ...b.yaxis, min: 0, labels: { ...b.yaxis.labels, formatter: money } },
      tooltip: { ...b.tooltip, shared: true, y: { formatter: money } },
      legend: { ...b.legend, position: "top" },
    };
  });
}

// ── Chart: Bausparvertrag savings + debt ──────────────────────────────────────
function loadBausparChart(container, labels, savingsData, debtData, currency) {
  const money = apexMoney(currency);
  loanChartInstance = apexRender(container, (el, t) => {
    const b = apexBase(el, "line", 350);
    return {
      ...b,
      series: [
        { name: translate("savings_phase"), type: "area", data: savingsData },
        { name: translate("repayment_phase"), type: "line", data: debtData },
      ],
      colors: [APEX_GREEN, t.main],
      stroke: { curve: "smooth", width: 2 },
      fill: { type: ["gradient", "solid"], gradient: { opacityFrom: 0.25, opacityTo: 0.02 } },
      markers: { size: 0, hover: { size: 4 } },
      xaxis: { ...b.xaxis, categories: labels, tickAmount: 12, labels: { ...b.xaxis.labels, rotate: -45, hideOverlappingLabels: true } },
      yaxis: { ...b.yaxis, min: 0, labels: { ...b.yaxis.labels, formatter: money } },
      tooltip: { ...b.tooltip, shared: true, y: { formatter: money } },
      legend: { ...b.legend, position: "top" },
    };
  });
}

// ── Schedule row override modal ────────────────────────────────────────────────
var _overridePeriod = 0;

function openOverrideModal(periodN, currentPayment, hasOverride) {
  _overridePeriod = periodN;
  const modal = document.getElementById("override-modal");
  if (!modal) return;
  document.getElementById("override-period-label").textContent = periodN;
  document.getElementById("override-amount").value = Math.round(currentPayment * 100) / 100;
  const deleteBtn = document.getElementById("override-delete-btn");
  if (deleteBtn) deleteBtn.style.display = hasOverride ? "inline-block" : "none";
  modal.classList.add("is-open");
  document.querySelector("body").classList.add("no-scroll");
}

function closeOverrideModal() {
  const modal = document.getElementById("override-modal");
  if (modal) modal.classList.remove("is-open");
  document.querySelector("body").classList.remove("no-scroll");
}

function saveOverride() {
  const amount = parseFloat(document.getElementById("override-amount").value);
  if (isNaN(amount) || amount <= 0) return;
  const fd = new FormData();
  fd.append("loan_id", window.loanDetailId);
  fd.append("period_n", _overridePeriod);
  fd.append("payment_amount", amount);
  fetch("endpoints/financing/add_override.php", {
    method: "POST",
    headers: { "X-CSRF-Token": window.csrfToken },
    body: fd,
  })
    .then((r) => r.json())
    .then((data) => {
      if (data.success) { closeOverrideModal(); window.location.reload(); }
      else showErrorMessage(data.message || translate("unknown_error"));
    })
    .catch(() => showErrorMessage(translate("unknown_error")));
}

function deleteOverride() {
  const fd = new FormData();
  fd.append("loan_id", window.loanDetailId);
  fd.append("period_n", _overridePeriod);
  fetch("endpoints/financing/delete_override.php", {
    method: "POST",
    headers: { "X-CSRF-Token": window.csrfToken },
    body: fd,
  })
    .then((r) => r.json())
    .then((data) => {
      if (data.success) { closeOverrideModal(); window.location.reload(); }
      else showErrorMessage(data.message || translate("unknown_error"));
    })
    .catch(() => showErrorMessage(translate("unknown_error")));
}

// ── Form: loan type field visibility ─────────────────────────────────────────
function toggleLoanTypeFields(type) {
  document.querySelectorAll(".loan-type-fields").forEach((el) => el.classList.remove("visible"));

  const lrLabel = document.querySelector("#label-interest-rate");
  const mrLabel = document.querySelector("#label-monthly-rate");
  const piField = document.querySelector("#loan-principal");

  if (type === "bausparen") {
    document.querySelector("#fields-bausparen").classList.add("visible");
    // Reuse interest_rate field via hidden bridge; hide the annuity panel
    // principal = Bausparsumme, interest_rate handled via bausparen-savings-interest
    if (piField) piField.placeholder = translate("bausparen_target");
    document.querySelector("#field-term-months").style.display = "none";
    document.querySelector("#field-fixed-interest-end").style.display = "none";
    document.querySelector("#field-replaced-by").style.display = "none";
  } else {
    const replacedBy = document.querySelector("#field-replaced-by");
    replacedBy.style.display = replacedBy.dataset.empty ? "none" : "";
    document.querySelector("#fields-annuity").classList.add("visible");
    if (type === "interest_only") {
      document.querySelector("#fields-interest-only").classList.add("visible");
    }
    if (piField) piField.placeholder = translate("principal");
    if (lrLabel) lrLabel.textContent = translate("interest_rate_pa");
    if (mrLabel) mrLabel.textContent = translate("monthly_rate");
    if (document.querySelector("#field-term-months")) document.querySelector("#field-term-months").style.display = "";
    if (document.querySelector("#field-fixed-interest-end")) document.querySelector("#field-fixed-interest-end").style.display = "";
  }
}

// ── Amortization table collapse ───────────────────────────────────────────────
function toggleScheduleYear(year) {
  document.querySelectorAll(".schedule-year-" + year).forEach((row) => {
    row.classList.toggle("collapsed");
  });
}

// ── Loan form ─────────────────────────────────────────────────────────────────
function resetLoanForm() {
  const form = document.querySelector("#loan-form-data");
  form.reset();
  document.querySelector("#loan-id").value = "";
  if (typeof resetPaperlessPanel === "function") resetPaperlessPanel();
  if (typeof resetFilesPanel === "function") resetFilesPanel();
  document.querySelector("#loan-form-title").textContent = translate("add_loan");
  document.querySelector("#loan-start-date").value = new Date().toISOString().split("T")[0];
  const deleteButton = document.querySelector("#delete-loan");
  deleteButton.style.display = "none";
  deleteButton.removeAttribute("onClick");
  document.querySelector("#loan-save-button").disabled = false;
  toggleLoanTypeFields("annuity");
  document.querySelector("#loan-type").value = "annuity";
}

function addLoan() {
  resetLoanForm();
  document.getElementById("loan-form").classList.add("is-open");
  document.querySelector("body").classList.add("no-scroll");
}

function closeLoanForm() {
  document.getElementById("loan-form").classList.remove("is-open");
  document.querySelector("body").classList.remove("no-scroll");
  resetLoanForm();
}

function fillLoanForm(loan) {
  document.querySelector("#loan-form-title").textContent = translate("edit_loan");
  document.querySelector("#loan-id").value = loan.id;
  if (typeof loadPaperlessPanel === "function") loadPaperlessPanel("loan", loan.id);
  if (typeof loadFilesPanel === "function") loadFilesPanel("loan", loan.id);

  const loanType = loan.loan_type || "annuity";
  document.querySelector("#loan-type").value = loanType;
  toggleLoanTypeFields(loanType);

  document.querySelector("#loan-name").value    = loan.name;
  document.querySelector("#loan-lender").value  = loan.lender || "";
  document.querySelector("#loan-principal").value = loan.principal;
  document.querySelector("#loan-currency").value  = loan.currency_id;
  document.querySelector("#loan-start-date").value = loan.start_date;
  document.querySelector("#loan-term-months").value = loan.term_months ?? "";
  document.querySelector("#loan-fixed-interest-end").value = loan.fixed_interest_end ?? "";
  document.querySelector("#loan-replaced-by").value = loan.replaced_by_loan_id ?? "";
  document.querySelector("#loan-notes").value   = loan.notes || "";

  if (loanType === "bausparen") {
    document.querySelector("#loan-bausparen-sparrate").value         = loan.monthly_rate || "";
    document.querySelector("#loan-bausparen-savings-interest").value = loan.interest_rate || "";
    document.querySelector("#loan-bausparen-min-pct").value          = loan.bausparen_min_savings_pct || 40;
    document.querySelector("#loan-bausparen-loan-rate").value        = loan.bausparen_loan_rate || "";
    document.querySelector("#loan-bausparen-loan-monthly").value     = loan.bausparen_loan_monthly || "";
    document.querySelector("#loan-bausparen-fee").value              = loan.bausparen_fee || "";
  } else {
    document.querySelector("#loan-interest-rate").value  = loan.interest_rate || "";
    document.querySelector("#loan-monthly-rate").value   = loan.monthly_rate || "";
    if (loanType === "interest_only") {
      document.querySelector("#loan-io-months").value = loan.interest_only_months || "";
    }
  }

  const deleteButton = document.querySelector("#delete-loan");
  deleteButton.style.display = "block";
  deleteButton.setAttribute("onClick", `deleteLoan(event, ${loan.id})`);

  document.getElementById("loan-form").classList.add("is-open");
  document.querySelector("body").classList.add("no-scroll");
}

function openEditLoan(event, id) {
  event.stopPropagation();
  fetch(`endpoints/financing/get.php?id=${id}`)
    .then((r) => r.json())
    .then((data) => {
      if (data.error) showErrorMessage(data.error);
      else fillLoanForm(data);
    })
    .catch(() => showErrorMessage(translate("unknown_error")));
}

function deleteLoan(event, id) {
  event.stopPropagation();
  if (!confirm(translate("confirm_delete"))) return;
  fetch("endpoints/financing/delete.php", {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
    body: JSON.stringify({ id }),
  })
    .then((r) => r.json())
    .then((data) => {
      if (data.success) { showSuccessMessage(data.message); window.location = "financing.php"; }
      else showErrorMessage(data.message || translate("unknown_error"));
    })
    .catch(() => showErrorMessage(translate("unknown_error")));
}

function archiveLoan(event, id, archived) {
  event.stopPropagation();
  fetch("endpoints/financing/archive.php", {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
    body: JSON.stringify({ id, archived }),
  })
    .then((r) => r.json())
    .then((data) => {
      if (data.success) { showSuccessMessage(data.message); window.location = "financing.php"; }
      else showErrorMessage(data.message || translate("unknown_error"));
    })
    .catch(() => showErrorMessage(translate("unknown_error")));
}

function deleteExtraPayment(event, id) {
  event.stopPropagation();
  fetch("endpoints/financing/delete_extra_payment.php", {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
    body: JSON.stringify({ id }),
  })
    .then((r) => r.json())
    .then((data) => {
      if (data.success) { showSuccessMessage(data.message); window.location.reload(); }
      else showErrorMessage(data.message || translate("unknown_error"));
    })
    .catch(() => showErrorMessage(translate("unknown_error")));
}

function deleteAnchor(event, id) {
  event.stopPropagation();
  fetch("endpoints/financing/delete_anchor.php", {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
    body: JSON.stringify({ id }),
  })
    .then((r) => r.json())
    .then((data) => {
      if (data.success) { showSuccessMessage(data.message); window.location.reload(); }
      else showErrorMessage(data.message || translate("unknown_error"));
    })
    .catch(() => showErrorMessage(translate("unknown_error")));
}

// ── DOMContentLoaded ──────────────────────────────────────────────────────────
document.addEventListener("DOMContentLoaded", function () {
  // Loan form submit
  const form = document.querySelector("#loan-form-data");
  if (form) {
    // Sync bausparen-savings-interest → interest_rate before submit
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      const type = document.querySelector("#loan-type").value;
      if (type === "bausparen") {
        document.querySelector("#loan-interest-rate").value =
          document.querySelector("#loan-bausparen-savings-interest").value;
        document.querySelector("#loan-monthly-rate").value =
          document.querySelector("#loan-bausparen-sparrate").value;
      }
      const submitButton = document.querySelector("#loan-save-button");
      submitButton.disabled = true;
      fetch(form.action, {
        method: "POST",
        headers: { "X-CSRF-Token": window.csrfToken },
        body: new FormData(form),
      })
        .then((r) => r.json())
        .then((data) => {
          if (data.success) { showSuccessMessage(data.message); window.location.reload(); }
          else showErrorMessage(data.message || translate("unknown_error"));
        })
        .catch(() => showErrorMessage(translate("unknown_error")))
        .finally(() => { submitButton.disabled = false; });
    });
  }

  // Bank statement balance form submit
  const anchorForm = document.querySelector("#anchor-form");
  if (anchorForm) {
    anchorForm.addEventListener("submit", function (event) {
      event.preventDefault();
      fetch("endpoints/financing/add_anchor.php", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
        body: JSON.stringify({
          loan_id: window.loanDetailId,
          anchor_date: document.querySelector("#anchor-date").value,
          balance: parseFloat(document.querySelector("#anchor-balance").value),
        }),
      })
        .then((r) => r.json())
        .then((data) => {
          if (data.success) { showSuccessMessage(data.message); window.location.reload(); }
          else showErrorMessage(data.message || translate("unknown_error"));
        })
        .catch(() => showErrorMessage(translate("unknown_error")));
    });
  }

  // Extra payment form submit
  const extraForm = document.querySelector("#extra-payment-form");
  if (extraForm) {
    extraForm.addEventListener("submit", function (event) {
      event.preventDefault();
      fetch("endpoints/financing/add_extra_payment.php", {
        method: "POST",
        headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
        body: JSON.stringify({
          loan_id: window.loanDetailId,
          payment_date: document.querySelector("#extra-payment-date").value,
          amount: parseFloat(document.querySelector("#extra-payment-amount").value),
        }),
      })
        .then((r) => r.json())
        .then((data) => {
          if (data.success) { showSuccessMessage(data.message); window.location.reload(); }
          else showErrorMessage(data.message || translate("unknown_error"));
        })
        .catch(() => showErrorMessage(translate("unknown_error")));
    });
  }
});

// ── Follow-up financing scenario (after the fixed-interest period) ────────────
// Keeps the current monthly rate and shows payoff date + interest for a new rate.
(function () {
  const box = document.getElementById("fixed-interest-box");
  if (!box) return;
  const balance = parseFloat(box.dataset.balance);
  const rate = parseFloat(box.dataset.rate);
  const input = document.getElementById("followup-rate");
  const money = new Intl.NumberFormat(navigator.language, { style: "currency", currency: box.dataset.currency });

  function update() {
    const monthlyInterest = parseFloat(input.value) / 100 / 12;
    const valid = !isNaN(monthlyInterest) && monthlyInterest >= 0 && rate > balance * monthlyInterest;
    document.getElementById("followup-error").hidden = valid;
    document.getElementById("followup-result").hidden = !valid;
    if (!valid) return;

    let remaining = balance;
    let interestTotal = 0;
    let months = 0;
    while (remaining > 0.005 && months < 1200) {
      const interest = remaining * monthlyInterest;
      interestTotal += interest;
      remaining -= Math.min(rate - interest, remaining);
      months++;
    }
    const payoff = new Date(box.dataset.end);
    payoff.setMonth(payoff.getMonth() + months);
    document.getElementById("followup-payoff").textContent =
      payoff.toLocaleDateString(navigator.language, { month: "2-digit", year: "numeric" });
    document.getElementById("followup-interest").textContent = money.format(interestTotal);
  }

  input.addEventListener("input", update);
  update();
})();
