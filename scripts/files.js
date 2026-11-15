// Documents uploaded directly to a contract, insurance or loan.
const filesState = { type: null, itemId: null };

function filesPost(endpoint, body) {
  const isForm = body instanceof FormData;
  return fetch(`endpoints/files/${endpoint}.php`, {
    method: "POST",
    headers: isForm ? { "X-CSRF-Token": window.csrfToken } : { "Content-Type": "application/json", "X-CSRF-Token": window.csrfToken },
    body: isForm ? body : JSON.stringify(body),
  }).then((response) => response.json());
}

function filesRefresh() {
  const panel = document.querySelector(".files-panel");
  filesPost("list", { type: filesState.type, item_id: filesState.itemId }).then((data) => {
    const list = panel.querySelector(".files-list");
    list.replaceChildren();
    (data.files || []).forEach((file) => {
      const li = document.createElement("li");
      li.className = "paperless-doc";
      const link = document.createElement("a");
      link.href = `endpoints/files/download.php?id=${file.id}`;
      link.target = "_blank";
      link.rel = "noopener";
      link.textContent = file.filename;
      const meta = document.createElement("small");
      meta.textContent = ` ${(file.size / 1024).toFixed(0)} KB · ${file.uploaded_at.substring(0, 10)}`;
      const del = document.createElement("button");
      del.type = "button";
      del.className = "image-button";
      del.title = translate("delete");
      del.innerHTML = '<i class="fa-solid fa-trash-can"></i>';
      del.onclick = () => {
        if (!confirm(translate("delete") + ": " + file.filename + "?")) return;
        filesPost("delete", { id: file.id }).then(filesRefresh);
      };
      // Optional expiry (warranty): a reminder is sent 30 days before.
      const expiry = document.createElement("input");
      expiry.type = "date";
      expiry.className = "files-expiry";
      expiry.title = translate("file_expires");
      expiry.value = file.expires_at || "";
      expiry.onchange = () => filesPost("expiry", { id: file.id, expires_at: expiry.value }).then((data) => {
        if (!data.success) showErrorMessage(data.message || translate("unknown_error"));
      });
      li.append(link, meta, expiry, del);
      list.append(li);
    });
  });
}

function resetFilesPanel() {
  const panel = document.querySelector(".files-panel");
  if (!panel) return;
  filesState.itemId = null;
  panel.querySelector(".files-hint").hidden = false;
  panel.querySelector(".files-body").hidden = true;
  panel.querySelector(".files-list").replaceChildren();
}

function loadFilesPanel(type, itemId) {
  const panel = document.querySelector(".files-panel");
  if (!panel) return;
  resetFilesPanel();
  if (!itemId) return;
  filesState.type = type;
  filesState.itemId = itemId;
  panel.querySelector(".files-hint").hidden = true;
  panel.querySelector(".files-body").hidden = false;
  filesRefresh();
}

document.addEventListener("DOMContentLoaded", () => {
  const input = document.querySelector(".files-panel .files-input");
  if (!input) return;
  input.addEventListener("change", async () => {
    for (const file of input.files) {
      const form = new FormData();
      form.append("type", filesState.type);
      form.append("item_id", filesState.itemId);
      form.append("file", file);
      const data = await filesPost("upload", form).catch(() => ({ success: false }));
      if (!data.success) showErrorMessage(`${file.name}: ${data.message || translate("unknown_error")}`);
    }
    input.value = "";
    filesRefresh();
  });
});
