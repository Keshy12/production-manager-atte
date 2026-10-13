<?php
/**
 * AJAX: strona historii przyjęć (paginacja + filtry).
 *
 * POST params (wszystkie opcjonalne):
 *   page       int    — 1-indexed numer strony (domyślnie 1)
 *   per_page   int    — rozmiar strony, cap 100 (domyślnie 25)
 *   search     string — fragment nazwy/dokumentu/komentarza (LIKE)
 *   date_from  string — Y-m-d, filtr na DATE(received_at) >=
 *   date_to    string — Y-m-d, filtr na DATE(received_at) <=
 *
 * Zasila zakładkę „Historia przyjęć" na /purchase/receipts. Poprzednio
 * cały ~9.5 MB HTML renderował się serwerowo dla 3 700+ wierszy;
 * teraz strona ładuje po 25 rekordów i dokleja kolejne po kliknięciu
 * w pager (receipts-view.js → loadHistoryPage()).
 *
 * Filtry (search + date_from + date_to) przeniesione z klienta na serwer
 * — poprzedni filtr po stronie JS przeszukiwał tekst wyrenderowanych
 * wierszy, a przy stronicowaniu w DOM siedzi tylko 25 z nich, więc
 * filtr musi działać na SQL.
 *
 * Response:
 *   {success: true,
 *    page: N, per_page: 25, total: T, pages: P,
 *    receipts: [
 *      {id, poId, poNumber, documentNumber, receivedBy, receivedByName,
 *       vendorName, comment, receivedAt, itemCount, totalQty}, ...
 *    ]}
 *
 * Sortowanie: r.received_at DESC, r.id DESC — identyczne jak
 * OrderReceiptRepository::getPage()/getAll(), żeby strony się nie
 * „przeskakiwały" między wywołaniami.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\OrderReceipt;
use Atte\Utils\Purchase\Order\OrderReceiptRepository;

header('Content-Type: application/json; charset=utf-8');

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Brak uprawnień']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metoda nieobsługiwana.']);
    exit();
}

try {
    $MsaDB = MsaDB::getInstance();
    $repo  = new OrderReceiptRepository($MsaDB);

    $page    = max(1, (int)($_POST['page'] ?? 1));
    $perPage = max(1, min(100, (int)($_POST['per_page'] ?? 25)));

    $search   = trim((string)($_POST['search']    ?? ''));
    $dateFrom = trim((string)($_POST['date_from'] ?? ''));
    $dateTo   = trim((string)($_POST['date_to']   ?? ''));
    $filtersActive = ($search !== '' || $dateFrom !== '' || $dateTo !== '');

    if (!$filtersActive) {
        $total    = $repo->countAll(true);
        $receipts = $repo->getPage(true, $page, $perPage);
    } else {
        // Budujemy WHERE ręcznie, bo getPage() nie przyjmuje filtrów
        // (API celowo wąskie — endpoint współdzieli wzorzec WHERE z
        // countAll i SQL jest tu jedynym konsumentem). Klauzule bindowane
        // po nazwie, ten sam zestaw parametrów w count i page.
        $clauses = ["po.state != 'cancelled'"];
        $params  = [];
        if ($search !== '') {
            // Zbiór pól pokrywa „hay" liczone w receipts-view.js →
            // applyHistoryView() (doc number, PO number, vendor, przyjmujący,
            // komentarz). ID nie dorzucamy — wyszukiwanie „ID 123" to
            // obejście, lepiej użyć pagera.
            $clauses[] = "(r.document_number LIKE :s "
                       . "OR r.comment LIKE :s "
                       . "OR po.po_number LIKE :s "
                       . "OR v.name LIKE :s "
                       . "OR CONCAT(u.name, ' ', u.surname) LIKE :s)";
            $params[':s'] = '%' . $search . '%';
        }
        if ($dateFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $clauses[] = 'DATE(r.received_at) >= :df';
            $params[':df'] = $dateFrom;
        }
        if ($dateTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $clauses[] = 'DATE(r.received_at) <= :dt';
            $params[':dt'] = $dateTo;
        }
        $where = 'WHERE ' . implode(' AND ', $clauses);

        // Total z identycznym WHERE — LIMIT/OFFSET tylko w drugim zapytaniu.
        $countSql = "SELECT COUNT(*)
                     FROM `purchase__order_receipt` r
                     LEFT JOIN `purchase__order` po ON r.po_id = po.id
                     LEFT JOIN `list__vendor` v    ON po.vendor_id = v.id
                     LEFT JOIN `user` u             ON r.received_by = u.user_id
                     {$where}";
        $countStmt = $MsaDB->db->prepare($countSql);
        foreach ($params as $k => $v) {
            $countStmt->bindValue($k, $v);
        }
        $countStmt->execute();
        $total = (int)$countStmt->fetchColumn();

        // Strona — inline'owane LIMIT/OFFSET po walidacji int (bez bindValue,
        // bo MySQL i tak akceptuje integer w tych klauzulach prepared stmt).
        $offset  = ($page - 1) * $perPage;
        $pageSql = $repo->buildSelectJoin()
                   . " {$where}"
                   . " ORDER BY r.received_at DESC, r.id DESC"
                   . " LIMIT {$perPage} OFFSET {$offset}";
        $pageStmt = $MsaDB->db->prepare($pageSql);
        foreach ($params as $k => $v) {
            $pageStmt->bindValue($k, $v);
        }
        $pageStmt->execute();
        $rows = $pageStmt->fetchAll(\PDO::FETCH_ASSOC);

        $receipts = [];
        foreach ($rows as $row) {
            $receipts[] = new OrderReceipt($row);
        }
    }

    // Wyliczamy pozycje/ilości tylko dla wierszy aktualnej strony (do 25).
    // Osobne zapytanie grupujące po receipt_id (jak w receipts-view.php
    // przed refaktorem) — left join w jednym SQL psują wynik SELECT-em
    // 1:N per receipt, więc zostaje GROUP BY.
    $countsByReceipt = [];
    if (!empty($receipts)) {
        $ids = array_map(static fn($r) => (int)$r->id, $receipts);
        $stmt = $MsaDB->db->prepare(
            "SELECT receipt_id,
                    COUNT(*)                       AS item_count,
                    COALESCE(SUM(quantity_received), 0) AS total_qty
             FROM `purchase__order_receipt_item`
             WHERE receipt_id IN (" . implode(',', array_map('intval', $ids)) . ")
             GROUP BY receipt_id"
        );
        $stmt->execute();
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $countsByReceipt[(int)$row['receipt_id']] = [
                'itemCount' => (int)$row['item_count'],
                'totalQty'  => (float)$row['total_qty'],
            ];
        }
    }

    // Serializacja do tablicy asocjacyjnej — pola dobrane pod szablon
    // wiersza po stronie JS (receipts-view.js → historyRowHtml). Zbędne
    // JOIN-y zostawiamy po stronie bazy, klient dostaje dokładnie to,
    // czego potrzebuje.
    $out = [];
    foreach ($receipts as $r) {
        $c = $countsByReceipt[$r->id] ?? ['itemCount' => 0, 'totalQty' => 0.0];
        $out[] = [
            'id'             => (int)$r->id,
            'poId'           => (int)$r->poId,
            'poNumber'       => $r->poNumber,
            'documentNumber' => $r->documentNumber,
            'receivedBy'     => (int)$r->receivedBy,
            'receivedByName' => $r->receivedByName,
            'vendorName'     => $r->vendorName,
            'comment'        => $r->comment,
            // Surowy ISO Y-m-d H:i:s — JS sam formatuje pod d.m.Y,
            // „dzisiaj/wczoraj" i godzinę.
            'receivedAt'     => $r->receivedAt,
            'itemCount'      => $c['itemCount'],
            'totalQty'       => $c['totalQty'],
        ];
    }

    $pages = $total > 0 ? (int)ceil($total / $perPage) : 1;
    // Klamrujemy $page do zakresu, żeby klient mógł bezpiecznie zażądać
    // strony spoza zakresu (np. po usunięciu filtrów) i dostać ostatnią
    // stronę zamiast pustej odpowiedzi.
    if ($page > $pages) $page = $pages;
    if ($page < 1) $page = 1;

    echo json_encode([
        'success'  => true,
        'page'     => $page,
        'per_page' => $perPage,
        'total'    => $total,
        'pages'    => $pages,
        'receipts' => $out,
    ]);
} catch (\Throwable $e) {
    error_log('receipts-history error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Błąd ładowania historii przyjęć.']);
}
exit;
