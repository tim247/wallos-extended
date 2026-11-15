<?php
// Inactivity guard ("dead man's switch").
//
// 1. Every page view of the owner stores today's date (user.last_seen).
// 2. 14, 7, 3, 2 and 1 day(s) before the deadline the owner gets a reminder (notification channels).
// 3. After the deadline the trusted person gets an e-mail with a personal link (30 days valid).
//    The dossier only opens with an access code the owner handed over in person, so a
//    forwarded or intercepted e-mail alone is not enough. 10 wrong codes lock the link.
// 4. As soon as the owner is active again, the link stops working.

const DEADMAN_WARN_DAYS = [14, 7, 3, 2, 1];
const DEADMAN_LINK_DAYS = 30;
const DEADMAN_MAX_ATTEMPTS = 10;

/** Settings of a user, with defaults when none are stored yet. */
function deadmanSettings(SQLite3 $db, int $userId): array
{
    $stmt = @$db->prepare('SELECT * FROM deadman_settings WHERE user_id = :userId');
    if (!$stmt) {
        return ['enabled' => 0, 'days' => 90, 'trusted_name' => '', 'trusted_email' => '', 'message' => '', 'access_code_hash' => null, 'triggered_at' => null];
    }
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    return $stmt->execute()->fetchArray(SQLITE3_ASSOC)
        ?: ['enabled' => 0, 'days' => 90, 'trusted_name' => '', 'trusted_email' => '', 'message' => '', 'access_code_hash' => null, 'triggered_at' => null];
}

/** Days until the trusted person is contacted (negative = overdue), null when not armed. */
function deadmanDaysLeft(array $settings, ?string $lastSeen, string $today): ?int
{
    if (empty($settings['enabled']) || empty($lastSeen)) {
        return null;
    }
    $deadline = strtotime($lastSeen . ' +' . (int) $settings['days'] . ' days');
    return (int) round(($deadline - strtotime($today)) / 86400);
}

/**
 * Called on every page view of a normal (not read-only) account: remembers the day and
 * withdraws a link that went out while the owner was away.
 */
function deadmanTouch(SQLite3 $db, int $userId, ?string $lastSeen): void
{
    $today = date('Y-m-d');
    if ($lastSeen === $today) {
        return;
    }
    $stmt = @$db->prepare('UPDATE user SET last_seen = :today WHERE id = :userId');
    if (!$stmt) {
        return; // before the migration
    }
    $stmt->bindValue(':today', $today);
    $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $stmt->execute();
    $stmt = @$db->prepare('UPDATE deadman_settings SET triggered_at = NULL, access_token = NULL, token_expires = NULL,
        failed_attempts = 0, last_warning = NULL WHERE user_id = :userId');
    if ($stmt) {
        $stmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
        $stmt->execute();
    }
}

/** Settings row for a valid, unexpired link, or null. */
function deadmanByToken(SQLite3 $db, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $stmt = $db->prepare("SELECT * FROM deadman_settings WHERE access_token = :token AND enabled = 1
        AND triggered_at IS NOT NULL AND token_expires >= date('now')");
    $stmt->bindValue(':token', $token);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    return $row ?: null;
}
