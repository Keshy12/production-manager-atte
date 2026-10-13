<?php
/**
 * AJAX: update the private (internal) comment on a single VendorPart.
 * Called from the Koszyk picker's inline pen-edit — saves immediately.
 *
 * POST:
 *   vp_id   (int)    — list__vendor_part.id
 *   comment (string) — trimmed, max 255 chars; empty string clears it
 *
 * Response: {success: true} | {error: "..."}
 */
use Atte\DB\MsaDB;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Brak uprawnień.']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$vpId   = (int)($_POST['vp_id'] ?? 0);
$comment = trim((string)($_POST['comment'] ?? ''));

if ($vpId <= 0) {
    echo json_encode(['error' => 'Brak identyfikatora artykułu.']);
    exit;
}
if (mb_strlen($comment) > 255) {
    echo json_encode(['error' => 'Komentarz może mieć maksymalnie 255 znaków.']);
    exit;
}

$MsaDB = MsaDB::getInstance();
$stmt = $MsaDB->db->prepare("UPDATE `list__vendor_part` SET comment = ? WHERE id = ?");
$stmt->execute([$comment !== '' ? $comment : null, $vpId]);

echo json_encode(['success' => true]);
