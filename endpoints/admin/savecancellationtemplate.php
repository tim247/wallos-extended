<?php
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/cancellation_letter.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
if (!empty($data['reset'])) {
    $template = cancellationDefaultTemplate();
} else {
    $template = ['subject' => mb_substr(trim((string) ($data['subject'] ?? '')), 0, 200), 'body' => mb_substr(trim((string) ($data['body'] ?? '')), 0, 5000)];
    if ($template['subject'] === '' || $template['body'] === '') {
        echo json_encode(['success' => false, 'message' => translate('fill_mandatory_fields', $i18n)]);
        exit;
    }
}

$stmt = $db->prepare("INSERT OR REPLACE INTO cancellation_template (id, subject, body) VALUES (1, :subject, :body)");
$stmt->bindValue(':subject', $template['subject'], SQLITE3_TEXT);
$stmt->bindValue(':body', $template['body'], SQLITE3_TEXT);
echo json_encode($stmt->execute()
    ? ['success' => true, 'message' => translate('cancellation_template_saved', $i18n), 'subject' => $template['subject'], 'body' => $template['body']]
    : ['success' => false, 'message' => translate('error', $i18n)]);
$db->close();
