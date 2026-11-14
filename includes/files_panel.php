<?php
// Uploaded documents inside the contract / insurance / loan edit form. Expects $filesType.
?>
<div class="form-group files-panel" data-type="<?= $filesType ?>">
  <label><i class="fa-solid fa-file-arrow-up"></i> <?= translate('item_files', $i18n) ?></label>
  <p class="files-hint"><?= translate('paperless_save_first', $i18n) ?></p>
  <div class="files-body" hidden>
    <ul class="paperless-list files-list"></ul>
    <label class="button thin secondary-button files-upload-button">
      <i class="fa-solid fa-upload"></i> <?= translate('upload_file', $i18n) ?>
      <input type="file" class="files-input" hidden multiple
        accept=".pdf,.png,.jpg,.jpeg,.webp,.gif,.txt,.doc,.docx,.xls,.xlsx,.odt,.ods,.eml,.msg">
    </label>
  </div>
</div>
