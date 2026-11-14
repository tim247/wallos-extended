<?php
// Documents uploaded directly to a contract, insurance or loan (no Paperless needed).
// Stored under db/files/ (persistent volume, blocked by nginx) with a random name.

const ITEM_FILE_TYPES = ['contract' => 'contracts', 'insurance' => 'insurances', 'loan' => 'loans'];
const ITEM_FILE_MAX_BYTES = 20 * 1024 * 1024;
const ITEM_FILE_EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'txt', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'eml', 'msg'];

function itemFilesDir(): string
{
    return __DIR__ . '/../db/files';
}

/** True when $itemId of $type belongs to $userId. */
function itemFileOwns(SQLite3 $db, int $userId, string $type, int $itemId): bool
{
    if (!isset(ITEM_FILE_TYPES[$type])) {
        return false;
    }
    $stmt = $db->prepare('SELECT 1 FROM ' . ITEM_FILE_TYPES[$type] . ' WHERE id = :id AND user_id = :userId');
    $stmt->bindValue(':id', $itemId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    return (bool) $stmt->execute()->fetchArray();
}

function itemFileGet(SQLite3 $db, int $userId, int $fileId): ?array
{
    $stmt = $db->prepare('SELECT * FROM item_files WHERE id = :id AND user_id = :userId');
    $stmt->bindValue(':id', $fileId, SQLITE3_INTEGER);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC) ?: null;
}

/** Removes all uploaded documents of a deleted item. */
function itemFilesDeleteFor(SQLite3 $db, int $userId, string $type, int $itemId): void
{
    $stmt = $db->prepare('SELECT stored_name FROM item_files WHERE user_id = :userId AND item_type = :type AND item_id = :itemId');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':type', $type);
    $stmt->bindValue(':itemId', $itemId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        @unlink(itemFilesDir() . '/' . basename($row['stored_name']));
    }
    $stmt = $db->prepare('DELETE FROM item_files WHERE user_id = :userId AND item_type = :type AND item_id = :itemId');
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->bindValue(':type', $type);
    $stmt->bindValue(':itemId', $itemId, SQLITE3_INTEGER);
    $stmt->execute();
}
