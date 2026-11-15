// Deadlines & maintenance, inventory and emergency notes: one dialog per page, one endpoint.
function lifeRequest(body) {
  return fetch('endpoints/life/save.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.csrfToken },
    body: JSON.stringify(body),
  }).then((r) => r.json()).then((data) => {
    if (data.success) {
      location.reload();
    } else {
      showErrorMessage(data.message);
    }
    return data;
  }).catch(() => showErrorMessage(translate('unknown_error')));
}

function lifeEdit(item) {
  const dialog = document.getElementById('life-dialog');
  const form = dialog.querySelector('form');
  form.reset();
  form.id.value = item.id || '';
  for (const element of form.elements) {
    if (element.name && element.name !== 'kind' && item[element.name] !== undefined && item[element.name] !== null) {
      element.value = item[element.name];
    }
  }
  if (typeof loadFilesPanel === 'function') {
    loadFilesPanel(form.kind.value, item.id || 0);
  }
  dialog.showModal();
}

function lifeSave(form) {
  lifeRequest(Object.fromEntries(new FormData(form)));
  return false;
}

function lifeDelete(kind, id, question) {
  if (confirm(question)) lifeRequest({ kind, id, action: 'delete' });
}

function lifeDone(id) {
  lifeRequest({ kind: 'reminder', id, action: 'done' });
}
