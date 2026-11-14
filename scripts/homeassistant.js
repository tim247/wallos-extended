// Home Assistant: connection settings (settings page) and "fetch now" on a meter.

function haPost(endpoint, body) {
  return fetch(`endpoints/homeassistant/${endpoint}.php`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
    body: JSON.stringify(body),
  }).then((response) => response.json());
}

function homeAssistantSettingsBody() {
  return {
    url: document.querySelector("#ha_url").value.trim(),
    token: document.querySelector("#ha_token").value.trim(),
    enabled: document.querySelector("#ha_enabled").checked,
  };
}

function saveHomeAssistantSettings() {
  haPost("savesettings", homeAssistantSettingsBody()).then((data) =>
    data.success ? showSuccessMessage(data.message) : showErrorMessage(data.message)
  );
}

function testHomeAssistantSettings() {
  haPost("test", homeAssistantSettingsBody()).then((data) =>
    data.success ? showSuccessMessage(data.message) : showErrorMessage(data.message)
  );
}

function fetchHomeAssistantReading(event, meterId) {
  event.stopPropagation();
  haPost("fetch", { id: meterId })
    .then((data) => {
      if (!data.success) return showErrorMessage(data.message);
      showSuccessMessage(data.message);
      setTimeout(() => window.location.reload(), 1200);
    })
    .catch(() => showErrorMessage(translate("unknown_error")));
}
