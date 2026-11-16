<?php
// Registration and "add user" ran htmlentities() on top of htmlspecialchars(), so "Jürgen" was stored as "J&uuml;rgen".
// Re-encode those fields the way the profile page stores them: htmlspecialchars only.
$rows = $db->query("SELECT id, username, firstname, lastname, email FROM user");
$fix = $db->prepare("UPDATE user SET username = :u, firstname = :f, lastname = :l, email = :e WHERE id = :id");
while ($row = $rows->fetchArray(SQLITE3_ASSOC)) {
    $clean = fn($v) => htmlspecialchars(html_entity_decode(html_entity_decode((string) $v, ENT_QUOTES | ENT_HTML5, 'UTF-8'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $new = ['u' => $clean($row['username']), 'f' => $clean($row['firstname']), 'l' => $clean($row['lastname']), 'e' => $clean($row['email'])];
    if ($new === ['u' => $row['username'], 'f' => $row['firstname'], 'l' => $row['lastname'], 'e' => $row['email']]) continue;
    foreach ($new as $k => $v) $fix->bindValue(":$k", $v, SQLITE3_TEXT);
    $fix->bindValue(':id', $row['id'], SQLITE3_INTEGER);
    $fix->execute();
    $fix->reset();
}
