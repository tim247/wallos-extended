<?php
// Sample PDF of the (unsaved) template with made-up data.
require_once '../../includes/connect_endpoint.php';
require_once '../../includes/validate_endpoint_admin.php';
require_once '../../includes/cancellation_letter.php';

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$template = ['subject' => (string) ($data['subject'] ?? ''), 'body' => (string) ($data['body'] ?? '')];

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="Kuendigung-Vorschau.pdf"');
echo cancellationLetterPdf($template, [
    'sender_name' => 'Max Mustermann', 'sender_address' => "Musterstraße 1\n12345 Musterstadt",
    'provider' => 'Beispiel Versicherung AG', 'recipient_address' => "Kündigungsservice\nPostfach 100\n54321 Beispielstadt",
    'item_name' => 'Hausrat', 'number' => 'HR-4711', 'number_label' => 'Versicherungsscheinnummer',
    'terminate_on' => date('Y-m-d', strtotime('+3 months')), 'deadline' => date('Y-m-d', strtotime('+1 month')),
    'date' => date('Y-m-d'), 'place' => 'Musterstadt',
]);
