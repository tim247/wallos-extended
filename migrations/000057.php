<?php
// Paperless-ngx integration: connection settings per user and document links.

$db->exec("CREATE TABLE IF NOT EXISTS paperless_settings (
    user_id INTEGER PRIMARY KEY,
    url TEXT NOT NULL DEFAULT '',
    token TEXT NOT NULL DEFAULT '',
    enabled INTEGER NOT NULL DEFAULT 1
)");

// Links a Paperless document to a contract / insurance / loan. Only the
// reference is stored here, the file itself stays in Paperless.
$db->exec("CREATE TABLE IF NOT EXISTS item_documents (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    item_type TEXT NOT NULL,
    item_id INTEGER NOT NULL,
    paperless_id INTEGER NOT NULL,
    title TEXT NOT NULL DEFAULT '',
    doc_date TEXT,
    correspondent TEXT,
    UNIQUE(item_type, item_id, paperless_id)
)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_item_documents_item ON item_documents (user_id, item_type, item_id)");
