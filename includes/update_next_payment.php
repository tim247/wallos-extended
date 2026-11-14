<?php
// Roll overdue auto-renewing subscriptions forward on page load, so installs
// without the updatenextpayment cron (e.g. local dev) still show correct dates.
// Mirrors endpoints/cronjobs/updatenextpayment.php, scoped to the current user.
$lazyToday = date('Y-m-d');
$lazyStmt = $db->prepare("SELECT id, next_payment, frequency, cycle FROM subscriptions WHERE user_id = :userId AND next_payment < :today AND auto_renew = 1 AND inactive = 0 AND archived = 0 AND cycle BETWEEN 1 AND 4");
$lazyStmt->bindValue(':userId', $userId, SQLITE3_INTEGER);
$lazyStmt->bindValue(':today', $lazyToday, SQLITE3_TEXT);
$lazyResult = $lazyStmt->execute();
$lazyRows = [];
while ($lazyRow = $lazyResult->fetchArray(SQLITE3_ASSOC)) {
    $lazyRows[] = $lazyRow;
}
foreach ($lazyRows as $lazyRow) {
    $lazyUnit = [1 => 'days', 2 => 'weeks', 3 => 'months', 4 => 'years'][(int) $lazyRow['cycle']];
    $lazyStep = max(1, (int) $lazyRow['frequency']);
    $lazyDate = new DateTime($lazyRow['next_payment']);
    $lazyGuard = 0;
    while ($lazyDate->format('Y-m-d') < $lazyToday && $lazyGuard++ < 6000) {
        $lazyDate->modify('+' . $lazyStep . ' ' . $lazyUnit);
    }
    $lazyUpdate = $db->prepare("UPDATE subscriptions SET next_payment = :next WHERE id = :id AND user_id = :userId");
    $lazyUpdate->bindValue(':next', $lazyDate->format('Y-m-d'), SQLITE3_TEXT);
    $lazyUpdate->bindValue(':id', $lazyRow['id'], SQLITE3_INTEGER);
    $lazyUpdate->bindValue(':userId', $userId, SQLITE3_INTEGER);
    $lazyUpdate->execute();
}
