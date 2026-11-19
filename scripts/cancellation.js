// Cancellation dialog: letter PDF + status "sent / confirmed".

const cancellationState = { type: null, id: null };

function cancellationPost(endpoint, body) {
  return fetch(`endpoints/cancellation/${endpoint}.php`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
    body: JSON.stringify(body),
  });
}

function cancellationField(id) {
  return document.getElementById(`cancellation-${id}`);
}

function renderCancellationStatus(data) {
  const el = cancellationField("status");
  const fmt = (d) => new Date(d).toLocaleDateString();
  let text = translate("cancellation_status_none");
  if (data.confirmed_date) {
    text = translate("cancellation_status_confirmed").replace("%s", fmt(data.confirmed_date));
  } else if (data.sent_date) {
    text = translate("cancellation_status_sent").replace("%s", fmt(data.sent_date));
  } else if (data.cancelled) {
    text = translate("cancellation_status_cancelled");
  }
  el.textContent = text;
  el.className = "cancellation-status" + (data.confirmed_date ? " confirmed" : data.sent_date ? " sent" : "");
}

function openCancellation(event, type, id) {
  event.stopPropagation();
  cancellationPost("get", { type, id })
    .then((response) => response.json())
    .then((data) => {
      if (!data.success) return showErrorMessage(data.message);
      cancellationState.type = type;
      cancellationState.id = id;
      cancellationField("mail").hidden = true;
      cancellationField("mail-text").value = "";
      const today = new Date().toISOString().split("T")[0];
      document.getElementById("cancellation-title").textContent = `${translate("cancellation_letter")}: ${data.name}`;
      cancellationField("sender-name").value = data.sender_name;
      cancellationField("sender-address").value = data.sender_address;
      cancellationField("place").value = data.place;
      cancellationField("date").value = today;
      cancellationField("recipient-address").value = data.recipient_address;
      cancellationField("terminate-on").value = data.terminate_on;
      cancellationField("status-date").value = today;
      cancellationField("deadline-hint").textContent = data.deadline
        ? translate("cancellation_deadline_hint").replace("%s", new Date(data.deadline).toLocaleDateString())
        : "";
      renderCancellationStatus(data);
      document.getElementById("cancellation-form").classList.add("is-open");
      document.querySelector("body").classList.add("no-scroll");
    })
    .catch(() => showErrorMessage(translate("unknown_error")));
}

function closeCancellation() {
  document.getElementById("cancellation-form").classList.remove("is-open");
  document.querySelector("body").classList.remove("no-scroll");
}

function generateCancellationLetter() {
  const button = cancellationField("generate");
  button.disabled = true;
  cancellationPost("generate", {
    type: cancellationState.type,
    id: cancellationState.id,
    sender_name: cancellationField("sender-name").value,
    sender_address: cancellationField("sender-address").value,
    place: cancellationField("place").value,
    date: cancellationField("date").value,
    recipient_address: cancellationField("recipient-address").value,
    terminate_on: cancellationField("terminate-on").value,
  })
    .then(async (response) => {
      if ((response.headers.get("content-type") || "").includes("application/json")) {
        const data = await response.json();
        throw new Error(data.message);
      }
      const disposition = response.headers.get("content-disposition") || "";
      const utf8 = /filename\*=UTF-8''([^;]+)/.exec(disposition);
      const match = utf8 ? [null, decodeURIComponent(utf8[1])] : /filename="([^"]+)"/.exec(disposition);
      const letter = response.headers.get("x-letter-text");
      if (letter) {
        const text = JSON.parse(new TextDecoder().decode(Uint8Array.from(atob(letter), (c) => c.charCodeAt(0))));
        cancellationField("mail-text").value = text.body;
        cancellationField("mail").hidden = false;
      }
      const url = URL.createObjectURL(await response.blob());
      const link = document.createElement("a");
      link.href = url;
      link.download = match ? match[1] : "Kuendigung.pdf";
      link.click();
      setTimeout(() => URL.revokeObjectURL(url), 5000);
    })
    .catch((error) => showErrorMessage(error.message || translate("unknown_error")))
    .finally(() => (button.disabled = false));
}

function copyCancellationText(button) {
  const text = cancellationField("mail-text");
  navigator.clipboard.writeText(text.value).then(() => {
    const label = button.innerHTML;
    button.innerHTML = `<i class="fa-solid fa-check"></i> ${button.dataset.done}`;
    setTimeout(() => (button.innerHTML = label), 2000);
  }, () => {
    text.select();
    document.execCommand("copy");
  });
}

function setCancellationStatus(action) {
  cancellationPost("status", {
    type: cancellationState.type,
    id: cancellationState.id,
    action,
    date: cancellationField("status-date").value,
  })
    .then((response) => response.json())
    .then((data) => {
      if (!data.success) return showErrorMessage(data.message);
      showSuccessMessage(data.message);
      window.location.reload();
    })
    .catch(() => showErrorMessage(translate("unknown_error")));
}
