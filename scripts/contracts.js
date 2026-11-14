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
  if (typeof resetPaperlessPanel === "function") resetPaperlessPanel();
  if (typeof resetFilesPanel === "function") resetFilesPanel();
  document.querySelector("#contract-form-title").textContent = translate("add_contract");
  document.querySelector("#contract-start-date").value = new Date().toISOString().split("T")[0];
  document.querySelector("#contract-notify-days").disabled = true;
  const deleteButton = document.querySelector("#delete-contract");
  deleteButton.style.display = "none";
  deleteButton.removeAttribute("onClick");
  document.querySelector("#contract-save-button").disabled = false;
  toggleContractRenewalFields();
}

function addContract() {
  resetContractForm();
  document.getElementById("contract-form").classList.add("is-open");
  document.querySelector("body").classList.add("no-scroll");
}

function closeContractForm() {
  document.getElementById("contract-form").classList.remove("is-open");
  document.querySelector("body").classList.remove("no-scroll");
  resetContractForm();
}

function fillContractForm(contract) {
  document.querySelector("#contract-form-title").textContent = translate("edit_contract");
  document.querySelector("#contract-id").value = contract.id;
  if (typeof loadPaperlessPanel === "function") loadPaperlessPanel("contract", contract.id);
  if (typeof loadFilesPanel === "function") loadFilesPanel("contract", contract.id);
  document.querySelector("#contract-name").value = contract.name;
  document.querySelector("#contract-provider").value = contract.provider;
  document.querySelector("#contract-number").value = contract.contract_number;
  document.querySelector("#contract-price").value = contract.price;
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
