<?php
/**
 * Shared helpers for the delivery-*.php JSON endpoints
 * (docs/procurement/RECEIPT_INVOICE_FLOW_PLAN.md §7).
 *
 * Not an endpoint: requiring this file only defines functions. Each
 * endpoint calls delivery_guard() first, then delivery_run().
 *
 * Contract (AGENTS.md "AJAX endpoints"):
 *   - 403 {success:false,error} when not admin
 *   - 405 when the HTTP method is wrong
 *   - validation / business errors → HTTP 200 {success:false,error}
 *   - unexpected Throwable → 500 {success:false,error:'Błąd serwera'}
 */

use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\DeliveryMatcher;
use Atte\Utils\Purchase\Order\PurchaseActionHandler;

/** Emits exactly one JSON document and terminates. */
function delivery_json(array $payload, int $httpCode = 200): void {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

/** JSON header + admin gate (403) + method gate (405). Returns user id. */
function delivery_guard(string $method): int {
    header('Content-Type: application/json; charset=utf-8');

    if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
        delivery_json(['success' => false, 'error' => 'Brak uprawnień'], 403);
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $method) {
        delivery_json(['success' => false, 'error' => 'Metoda nieobsługiwana'], 405);
    }
    return (int)($_SESSION['user_id'] ?? 0);
}

/** Like delivery_guard(), but also requires a logged-in user id. */
function delivery_guard_user(string $method): int {
    $userId = delivery_guard($method);
    if ($userId <= 0) {
        delivery_json(['success' => false, 'error' => 'Brak zalogowanego użytkownika.']);
    }
    return $userId;
}

/**
 * Runs $fn(MsaDB, PurchaseActionHandler) and maps exceptions to JSON.
 * $fn must return the array to be JSON-encoded (the response body).
 */
function delivery_run(callable $fn): void {
    $MsaDB = MsaDB::getInstance();
    try {
        $payload = $fn($MsaDB, new PurchaseActionHandler($MsaDB));
        delivery_json($payload);
    } catch (\InvalidArgumentException | \LogicException $e) {
        if ($MsaDB->db->inTransaction()) { $MsaDB->db->rollBack(); }
        delivery_json(['success' => false, 'error' => $e->getMessage()]);
    } catch (\Throwable $e) {
        if ($MsaDB->db->inTransaction()) { $MsaDB->db->rollBack(); }
        error_log(basename($_SERVER['SCRIPT_NAME'] ?? 'delivery') . ' error: ' . $e->getMessage());
        delivery_json(['success' => false, 'error' => 'Błąd serwera'], 500);
    }
}

/** Positive int from $_POST / $_GET or 0. */
function delivery_int($value): int {
    return is_scalar($value) ? (int)$value : 0;
}

/**
 * Int albo null — dla identyfikatorów, gdzie 0 jest PRAWDZIWĄ wartością
 * (np. magazine__list.sub_magazine_id = 0 → „Produkcja - Cegielskiego”).
 * Brak klucza / null / pusty ciąg → null („nie ustawiono”); liczba całkowita
 * (w tym "0") → int. Tekst nienumeryczny kończy żądanie błędem JSON (HTTP 200),
 * żeby nie zamienić go po cichu na magazyn 0.
 */
function delivery_int_or_null($value, string $label = 'Identyfikator'): ?int {
    if ($value === null) return null;
    if (is_int($value)) return $value;
    if (!is_scalar($value)) return null;
    $s = trim((string)$value);
    if ($s === '') return null;
    $i = filter_var($s, FILTER_VALIDATE_INT);
    if ($i === false) {
        delivery_json(['success' => false, 'error' => "{$label}: nieprawidłowa wartość."]);
    }
    return $i;
}

/** Trimmed string or null (also null for ''). */
function delivery_str($value): ?string {
    if ($value === null || !is_scalar($value)) return null;
    $s = trim((string)$value);
    return $s === '' ? null : $s;
}

/**
 * Header fields present in $src → array for the handler. Only keys that
 * were actually sent are included (handler treats absent = unchanged).
 * Empty document_type / currency are dropped (create → defaults,
 * save → unchanged). received_at 'Y-m-d' → 'Y-m-d 00:00:00'.
 */
function delivery_header_from(array $src, bool $withReceivedAt): array {
    $keys = ['document_type', 'vendor_document_number', 'document_date', 'currency', 'default_sub_magazine_id', 'comment'];
    if ($withReceivedAt) $keys[] = 'received_at';

    $header = [];
    foreach ($keys as $k) {
        if (!array_key_exists($k, $src)) continue;
        $v = delivery_str($src[$k]);
        if (($k === 'document_type' || $k === 'currency') && $v === null) continue;
        if ($k === 'received_at' && $v !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            $v .= ' 00:00:00';
        }
        $header[$k] = $v;
    }
    return $header;
}

/**
 * Open PO lines of a vendor (states confirmed/in_progress/partially_received,
 * remaining > 0) in DeliveryMatcher candidate order.
 *
 * @return array<int, array<string, mixed>>
 */
function delivery_open_items(\PDO $db, int $vendorId): array {
    $states = "'" . implode("','", DeliveryMatcher::OPEN_PO_STATES) . "'";
    $stmt = $db->prepare(
        "SELECT i.id AS po_item_id, i.po_id, po.po_number, po.state AS po_state,
                i.vendor_part_id, vp.vendor_part_no, vp.producer_part_no, lp.name AS part_name,
                lp.description AS part_description, u.name AS unit,
                i.quantity, i.quantity_received, i.unit_price, i.currency,
                COALESCE(i.expected_delivery_date, po.expected_delivery_date) AS due_date
           FROM `purchase__order_item` i
           JOIN `purchase__order` po        ON i.po_id = po.id
           LEFT JOIN `list__vendor_part` vp ON i.vendor_part_id = vp.id
           LEFT JOIN `list__parts` lp       ON vp.parts_id = lp.id
           LEFT JOIN `part__unit` u         ON vp.vendor_jm_id = u.id
          WHERE po.vendor_id = ? AND po.state IN ({$states})
            AND i.quantity - i.quantity_received > 0.000001
          ORDER BY (COALESCE(i.expected_delivery_date, po.expected_delivery_date) IS NULL) ASC,
                   COALESCE(i.expected_delivery_date, po.expected_delivery_date) ASC,
                   (po.confirmed_at IS NULL) ASC, po.confirmed_at ASC,
                   po.id ASC, i.id ASC"
    );
    $stmt->execute([$vendorId]);

    $items = [];
    foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
        $qty = (float)$r['quantity'];
        $rec = (float)$r['quantity_received'];
        $items[] = [
            'po_item_id'       => (int)$r['po_item_id'],
            'po_id'            => (int)$r['po_id'],
            'po_number'        => $r['po_number'] ?? ('#' . $r['po_id']),
            'po_state'         => $r['po_state'],
            'vendor_part_id'   => (int)$r['vendor_part_id'],
            'vendor_part_no'   => $r['vendor_part_no'],
            'producer_part_no' => $r['producer_part_no'],
            'part_name'        => $r['part_name'],
            'part_description' => (string)($r['part_description'] ?? ''),
            'unit'             => $r['unit'] ?? null,
            'quantity'         => $qty,
            'quantity_received'=> $rec,
            'remaining'        => round($qty - $rec, 10),
            'unit_price'       => $r['unit_price'] !== null ? (float)$r['unit_price'] : null,
            'currency'         => $r['currency'],
            'due_date'         => $r['due_date'],
        ];
    }
    return $items;
}
