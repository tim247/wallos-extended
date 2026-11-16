function toggleInsuranceRenewalFields() {
  const autoRenews = document.querySelector("#insurance-auto-renews");
  const endDateGroup = document.querySelector("#insurance-end-date-group");
  if (autoRenews.checked) {
    endDateGroup.classList.add("hide");
  } else {
    endDateGroup.classList.remove("hide");
  }
}

function toggleInsuranceNotifyDays() {
  const notifyCheckbox = document.querySelector("#insurance-notifications");
  const notifyDays = document.querySelector("#insurance-notify-days");
  notifyDays.disabled = !notifyCheckbox.checked;
}

function resetInsuranceForm() {
  const form = document.querySelector("#insurance-form-data");
  form.reset();
  document.querySelector("#insurance-id").value = "";
  if (typeof resetPaperlessPanel === "function") resetPaperlessPanel();
  if (typeof resetFilesPanel === "function") resetFilesPanel();
  document.querySelector("#insurance-form-title").textContent = translate("add_insurance");
  document.querySelector("#insurance-start-date").value = new Date().toISOString().split("T")[0];
  document.querySelector("#insurance-notify-days").disabled = true;
  const deleteButton = document.querySelector("#delete-insurance");
  deleteButton.style.display = "none";
  deleteButton.removeAttribute("onClick");
  document.querySelector("#insurance-save-button").disabled = false;
  toggleInsuranceRenewalFields();
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
  document.querySelector("#insurance-form-title").textContent = translate("edit_insurance");
  document.querySelector("#insurance-id").value = insurance.id;
  if (typeof loadPaperlessPanel === "function") loadPaperlessPanel("insurance", insurance.id);
  if (typeof loadFilesPanel === "function") loadFilesPanel("insurance", insurance.id);
  document.querySelector("#insurance-name").value = insurance.name;
  document.querySelector("#insurance-insurer").value = insurance.insurer;
  document.querySelector("#insurance-policy-number").value = insurance.policy_number;
  setInsuranceType(insurance.insurance_type);
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
  toggleInsuranceRenewalFields();

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
