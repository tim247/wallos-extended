// Paperless-ngx: document panel in the edit forms + connection settings.

function paperlessPost(endpoint, body) {
  return fetch(`endpoints/paperless/${endpoint}.php`, {
    method: "POST",
    headers: { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
    body: JSON.stringify(body),
  }).then((response) => response.json());
}

// ── Document panel ────────────────────────────────────────────────────────────
const paperlessState = { type: null, itemId: null, baseUrl: "", attached: new Set() };

function paperlessPanel() {
  return document.querySelector(".paperless-panel");
}

function paperlessRow(doc, actions) {
  const li = document.createElement("li");
  li.className = "paperless-doc";

  const thumb = document.createElement("img");
  thumb.loading = "lazy";
  thumb.alt = "";
  thumb.src = `endpoints/paperless/file.php?id=${doc.id}&mode=thumb`;
  li.appendChild(thumb);

  const info = document.createElement("span");
  info.className = "paperless-doc-info";
  const title = document.createElement("strong");
  title.textContent = doc.title;
  const meta = document.createElement("small");
  meta.textContent = [doc.date ? new Date(doc.date).toLocaleDateString() : "", doc.correspondent]
    .filter(Boolean)
    .join(" · ");
  info.append(title, meta);
  li.appendChild(info);

  const buttons = document.createElement("span");
  buttons.className = "paperless-doc-actions";
  actions.forEach((action) => buttons.appendChild(action));
  li.appendChild(buttons);
  return li;
}

function paperlessLink(href, icon, titleKey) {
  const a = document.createElement("a");
  a.href = href;
  a.target = "_blank";
  a.rel = "noopener";
  a.title = translate(titleKey);
  a.innerHTML = `<i class="fa-solid ${icon}"></i>`;
  return a;
}

function paperlessButton(icon, titleKey, handler) {
  const button = document.createElement("button");
  button.type = "button";
  button.title = translate(titleKey);
  button.innerHTML = `<i class="fa-solid ${icon}"></i>`;
  button.addEventListener("click", handler);
  return button;
}

function paperlessOpenActions(doc) {
  return [
    paperlessLink(`${paperlessState.baseUrl}/documents/${doc.id}/details`, "fa-arrow-up-right-from-square", "paperless_open"),
    paperlessLink(`endpoints/paperless/file.php?id=${doc.id}&mode=download`, "fa-download", "paperless_download"),
  ];
}

function paperlessFill(list, documents, makeActions) {
  list.replaceChildren(...documents.map((doc) => paperlessRow(doc, makeActions(doc))));
}

function paperlessRefreshAttached() {
  const panel = paperlessPanel();
  paperlessPost("list", { type: paperlessState.type, item_id: paperlessState.itemId }).then((data) => {
    if (!data.success) return;
    paperlessState.baseUrl = data.base_url;
    paperlessState.attached = new Set(data.documents.map((doc) => doc.id));
    paperlessFill(panel.querySelector(".paperless-attached"), data.documents, (doc) => [
      ...paperlessOpenActions(doc),
      paperlessButton("fa-link-slash", "paperless_detach", () => paperlessDetach(doc.id)),
    ]);
  });
}

function paperlessRefreshSuggestions() {
  const panel = paperlessPanel();
  const box = panel.querySelector(".paperless-suggestions");
  paperlessPost("suggest", { type: paperlessState.type, item_id: paperlessState.itemId }).then((data) => {
    box.hidden = !data.success || data.documents.length === 0;
    if (!data.success) return;
    paperlessFill(panel.querySelector(".paperless-suggestion-list"), data.documents, (doc) => [
      ...paperlessOpenActions(doc),
      paperlessButton("fa-paperclip", "paperless_attach", () => paperlessAttach(doc.id)),
    ]);
  });
}

function paperlessAttach(paperlessId) {
  paperlessPost("attach", { type: paperlessState.type, item_id: paperlessState.itemId, paperless_id: paperlessId }).then((data) => {
    if (!data.success) return showErrorMessage(data.message);
    // The document moves from suggestions/results to the attached list.
    paperlessRefreshAttached();
    paperlessRefreshSuggestions();
    paperlessPanel().querySelector(".paperless-results").replaceChildren();
  });
}

function paperlessDetach(paperlessId) {
  paperlessPost("detach", { type: paperlessState.type, item_id: paperlessState.itemId, paperless_id: paperlessId }).then((data) => {
    if (!data.success) return showErrorMessage(data.message);
    paperlessRefreshAttached();
    paperlessRefreshSuggestions();
  });
}

function paperlessSearch() {
  const panel = paperlessPanel();
  const query = panel.querySelector(".paperless-query").value.trim();
  if (query === "") return;
  paperlessPost("search", { q: query }).then((data) => {
    if (!data.success) return showErrorMessage(data.message);
    const documents = data.documents.filter((doc) => !paperlessState.attached.has(doc.id));
    paperlessFill(panel.querySelector(".paperless-results"), documents, (doc) => [
      ...paperlessOpenActions(doc),
      paperlessButton("fa-paperclip", "paperless_attach", () => paperlessAttach(doc.id)),
    ]);
  });
}

function resetPaperlessPanel() {
  const panel = paperlessPanel();
  if (!panel) return;
  paperlessState.type = null;
  paperlessState.itemId = null;
  panel.querySelector(".paperless-hint").hidden = false;
  panel.querySelector(".paperless-body").hidden = true;
  panel.querySelectorAll(".paperless-list").forEach((list) => list.replaceChildren());
  panel.querySelector(".paperless-suggestions").hidden = true;
  panel.querySelector(".paperless-query").value = "";
}

function loadPaperlessPanel(type, itemId) {
  const panel = paperlessPanel();
  if (!panel) return;
  resetPaperlessPanel();
  if (!itemId) return;
  paperlessState.type = type;
  paperlessState.itemId = itemId;
  panel.querySelector(".paperless-hint").hidden = true;
  panel.querySelector(".paperless-body").hidden = false;
  panel.querySelector(".paperless-tag-name").textContent = `wallos-${type}-${itemId}`;
  paperlessRefreshAttached();
  paperlessRefreshSuggestions();
}

document.addEventListener("DOMContentLoaded", () => {
  const panel = paperlessPanel();
  if (!panel) return;
  panel.querySelector(".paperless-search-button").addEventListener("click", paperlessSearch);
  panel.querySelector(".paperless-query").addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault(); // the panel lives inside the edit form
      paperlessSearch();
    }
  });
});

// ── Connection settings (settings page) ───────────────────────────────────────
function paperlessSettingsBody() {
  return {
    url: document.querySelector("#paperless_url").value.trim(),
    token: document.querySelector("#paperless_token").value.trim(),
    enabled: document.querySelector("#paperless_enabled").checked,
  };
}

function savePaperlessSettings() {
  paperlessPost("savesettings", paperlessSettingsBody()).then((data) =>
    data.success ? showSuccessMessage(data.message) : showErrorMessage(data.message)
  );
}

function testPaperlessSettings() {
  paperlessPost("test", paperlessSettingsBody()).then((data) =>
    data.success ? showSuccessMessage(data.message) : showErrorMessage(data.message)
  );
}
