<?php
// Emergency dossier ("in case of emergency"): everything a partner or relative needs to find,
// take over or cancel contracts, insurances, loans and accounts. Print it or export it as an
// encrypted HTML file (encrypted in the browser, the password never reaches the server).
require_once 'includes/header.php';
require_once 'includes/getdbkeys.php';
$readOnlyView = !empty($isReadOnlyAccount);
ob_start();
require 'includes/emergency_dossier.php'; // loads the data and prints the dossier
$dossierHtml = ob_get_clean();
?>

<section class="contain life">
  <div class="split-header">
    <h2><?= translate('emergency', $i18n) ?></h2>
    <div class="no-print">
      <button class="button thin secondary-button" onclick="window.print()"><i class="fa-solid fa-print"></i> <?= translate('report_print', $i18n) ?></button>
      <button class="button thin" onclick="document.getElementById('emergency-export').showModal()"><i class="fa-solid fa-lock"></i> <?= translate('emergency_export', $i18n) ?></button>
    </div>
  </div>
  <p class="settings-notes no-print"><?= translate('emergency_intro', $i18n) ?></p>

  <?php include 'includes/deadman_settings.php'; ?>

  <?php if (!$readOnlyView): ?>
    <div class="no-print">
      <h3><i class="fa-solid fa-pen"></i> <?= translate('emergency_notes', $i18n) ?></h3>
      <p class="settings-notes"><?= translate('emergency_notes_info', $i18n) ?></p>
      <textarea id="emergency-notes" rows="8" class="emergency-notes"><?= $h($notes['notes']) ?></textarea>
      <div class="buttons"><button class="button thin" onclick="lifeRequest({kind: 'notes', notes: document.getElementById('emergency-notes').value})"><?= translate('save', $i18n) ?></button></div>
    </div>
  <?php endif; ?>

  <?= $dossierHtml ?>

  <dialog id="emergency-export" class="finance-dialog">
    <form method="dialog" onsubmit="return exportDossier(this)">
      <h3><i class="fa-solid fa-lock"></i> <?= translate('emergency_export', $i18n) ?></h3>
      <p class="settings-notes"><?= translate('emergency_export_info', $i18n) ?></p>
      <label><?= translate('password', $i18n) ?><input type="password" name="password" minlength="12" required autocomplete="new-password"></label>
      <label><?= translate('emergency_password_repeat', $i18n) ?><input type="password" name="repeat" minlength="12" required autocomplete="new-password"></label>
      <p id="emergency-export-error" class="error" hidden></p>
      <div class="buttons">
        <button type="button" class="secondary-button thin" onclick="this.closest('dialog').close()"><?= translate('cancel', $i18n) ?></button>
        <input type="submit" class="thin" value="<?= translate('emergency_export_button', $i18n) ?>">
      </div>
    </form>
  </dialog>
</section>

<script src="scripts/life.js?<?= $assetVersion ?>"></script>
<script>
  const dossierText = <?= json_encode([
    'mismatch' => translate('emergency_password_mismatch', $i18n),
    'insecure' => translate('emergency_needs_https', $i18n),
    'title' => translate('emergency', $i18n),
    'prompt' => translate('emergency_open_prompt', $i18n),
    'open' => translate('emergency_open_button', $i18n),
    'wrong' => translate('emergency_wrong_password', $i18n),
    'lang' => $lang,
  ], JSON_HEX_TAG | JSON_HEX_AMP) ?>;

  const toBase64 = bytes => btoa(String.fromCharCode(...new Uint8Array(bytes)));

  async function exportDossier(form) {
    const error = document.getElementById('emergency-export-error');
    error.hidden = true;
    if (form.password.value !== form.repeat.value) {
      error.textContent = dossierText.mismatch;
      error.hidden = false;
      return false;
    }
    if (!window.crypto?.subtle) {
      error.textContent = dossierText.insecure; // WebCrypto exists only on https or localhost
      error.hidden = false;
      return false;
    }
    // AES-256-GCM, key from the password via PBKDF2-SHA256 (600,000 rounds). Nothing leaves the browser.
    const iterations = 600000;
    const salt = crypto.getRandomValues(new Uint8Array(16));
    const iv = crypto.getRandomValues(new Uint8Array(12));
    const base = await crypto.subtle.importKey('raw', new TextEncoder().encode(form.password.value), 'PBKDF2', false, ['deriveKey']);
    const key = await crypto.subtle.deriveKey({ name: 'PBKDF2', salt, iterations, hash: 'SHA-256' }, base, { name: 'AES-GCM', length: 256 }, false, ['encrypt']);
    const styles = [...document.styleSheets].flatMap(sheet => { try { return [...sheet.cssRules].map(rule => rule.cssText); } catch (e) { return []; } })
      .filter(rule => /bank-table|dossier/.test(rule)).join('\n');
    const content = new TextEncoder().encode(document.getElementById('dossier').outerHTML);
    const data = await crypto.subtle.encrypt({ name: 'AES-GCM', iv }, key, content);
    const payload = JSON.stringify({ v: 1, iterations, salt: toBase64(salt), iv: toBase64(iv), data: toBase64(data) });
    const file = `<!DOCTYPE html><html lang="${dossierText.lang}"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>${dossierText.title}</title><style>body{font-family:system-ui,sans-serif;max-width:960px;margin:24px auto;padding:0 16px;color:#222}
table{border-collapse:collapse;width:100%;margin-bottom:16px}td,th{border-bottom:1px solid #ddd;padding:6px;text-align:left;vertical-align:top}
#lock{max-width:360px;margin:15vh auto;text-align:center}#lock input{width:100%;padding:10px;font-size:16px;margin:8px 0}
#lock button{padding:10px 20px;font-size:16px}#err{color:#c00}@media print{#lock{display:none}}${styles}</style></head><body>
<div id="lock"><h1>&#128274; ${dossierText.title}</h1><p>${dossierText.prompt}</p><input type="password" id="pw" autofocus>
<button id="go">${dossierText.open}</button><p id="err"></p></div><div id="out"></div>
<script>const P=${payload};const b=s=>Uint8Array.from(atob(s),c=>c.charCodeAt(0));
async function open(){try{const k=await crypto.subtle.importKey('raw',new TextEncoder().encode(pw.value),'PBKDF2',false,['deriveKey']);
const key=await crypto.subtle.deriveKey({name:'PBKDF2',salt:b(P.salt),iterations:P.iterations,hash:'SHA-256'},k,{name:'AES-GCM',length:256},false,['decrypt']);
const html=new TextDecoder().decode(await crypto.subtle.decrypt({name:'AES-GCM',iv:b(P.iv)},key,b(P.data)));
lock.remove();out.innerHTML=html;}catch(e){err.textContent=${JSON.stringify(dossierText.wrong)};}}
go.onclick=open;pw.onkeydown=e=>{if(e.key==='Enter')open();};<\/script></body></html>`;
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([file], { type: 'text/html' }));
    link.download = 'notfall-dossier-' + new Date().toISOString().slice(0, 10) + '.html';
    link.click();
    URL.revokeObjectURL(link.href);
    form.reset();
    form.closest('dialog').close();
    return false;
  }
</script>
<?php require_once 'includes/footer.php'; ?>
