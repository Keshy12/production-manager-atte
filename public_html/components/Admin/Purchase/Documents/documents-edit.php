<?php
/**
 * Merged RFQ + PO edit page.
 *
 * Wired at /admin/purchase/documents/edit?id=N&type=rfq|po. Replaces
 * the per-type pages (Admin/Purchase/RFQs/rfqs-edit.php and
 * Admin/Purchase/Orders/orders-edit.php) with a single view that
 * branches on `type`:
 *
 *   - Repository + item-repository dispatch (RFQ vs PO)
 *   - Type-specific state badge map (each type has its own states)
 *   - Type-specific header fields (PO has vendor_po_number +
 *     convertedFromRfqId, RFQ has expected_reply_date)
 *   - PO-only "Odebrane" column on the items table
 *   - State guard via PurchaseActionHandler::allowedEditStates() — one
 *     place that decides which states accept item-level mutations
 *
 * Inline editing of qty/price/comment on each row, vendor-part comment
 * inline edit, and the "Dodaj pozycję" cascade picker all come from
 * the RFQ edit page; they're enabled when the doc is in an editable
 * state and disabled otherwise (no edit pencil / trash buttons
 * render for terminal states).
 *
 * Phase 4 wires this file into the router (route + fall-through from
 * the legacy /admin/purchase/rfqs/edit and /admin/purchase/orders/edit
 * URLs). Phase 5 deletes the legacy PHP files.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseActionHandler;
use Atte\Utils\Purchase\Order\RFQRepository;
use Atte\Utils\Purchase\Order\RFQItemRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderItemRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$type = (string)($_GET['type'] ?? '');
if (!in_array($type, ['rfq','po'], true)) {
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">Nieprawidłowy typ dokumentu (wymagane ?type=rfq lub ?type=po).</div></div>';
    return;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">Brak id dokumentu.</div></div>';
    return;
}

$MsaDB = MsaDB::getInstance();
if ($type === 'po') {
    $docRepo  = new PurchaseOrderRepository($MsaDB);
    $itemRepo = new PurchaseOrderItemRepository($MsaDB);
} else {
    $docRepo  = new RFQRepository($MsaDB);
    $itemRepo = new RFQItemRepository($MsaDB);
}

$doc = $docRepo->getById($id);
if ($doc === null) {
    $docNoun = $type === 'po' ? 'Zamówienie' : 'Zapytanie';
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">' . $docNoun . ' #' . htmlspecialchars((string)$id) . ' nie istnieje.</div></div>';
    return;
}
$items = $type === 'po' ? $itemRepo->getByPo($id) : $itemRepo->getByRfq($id);

// Per-type field aliases. The repos return different field names for
// the same conceptual fields; normalise here so the view template
// downstream reads from one set of variables.
if ($type === 'po') {
    $docTitle          = 'Zamówienie';
    $docNumber         = $doc->poNumber ?? ('#' . $doc->id);
    $expectedDateLabel = 'Planowana dostawa:';
    $expectedDate      = $doc->expectedDeliveryDate;
    $sentAt            = $doc->sentAt;
    $confirmedAt       = $doc->confirmedAt;
    $vendorPoNumber    = $doc->vendorPoNumber;
    $convertedFromRfqId     = $doc->convertedFromRfqId;
    $convertedFromRfqNumber = $doc->convertedFromRfqNumber;
} else {
    $docTitle          = 'Zapytanie ofertowe';
    $docNumber         = $doc->rfqNumber ?? ('#' . $doc->id);
    $expectedDateLabel = 'Oczekiwana odpowiedź:';
    $expectedDate      = $doc->expectedReplyDate ?? null;
    $sentAt            = $doc->sentAt;
    $confirmedAt       = null;
    $vendorPoNumber    = null;
    $convertedFromRfqId     = null;
    $convertedFromRfqNumber = null;
}

// State guard: single source of truth lives on the action handler.
$allowedEdit = in_array($doc->state, PurchaseActionHandler::allowedEditStates($type), true);

// State badge map. Each type has its own state set; keeping both
// maps in one file avoids drift between the two legacy pages.
$stateBadgeClass = $type === 'po' ? [
    'draft'              => 'badge-secondary',
    'sent'               => 'badge-primary',
    'confirmed'          => 'badge-info',
    'partially_received' => 'badge-warning',
    'received'           => 'badge-success',
    'cancelled'          => 'badge-danger',
] : [
    'draft'     => 'badge-secondary',
    'sent'      => 'badge-primary',
    'responded' => 'badge-info',
    'cancelled' => 'badge-danger',
    'converted' => 'badge-success',
];
$stateBadgeLabel = $type === 'po' ? [
    'draft'              => 'szkic',
    'sent'               => 'wysłane',
    'confirmed'          => 'potwierdzone',
    'partially_received' => 'częściowo odebrane',
    'received'           => 'odebrane',
    'cancelled'          => 'anulowane',
] : [
    'draft'     => 'szkic',
    'sent'      => 'wysłane',
    'responded' => 'odpowiedź',
    'cancelled' => 'anulowane',
    'converted' => 'skonwertowane na PO',
];

function formatQty($v) {
    if ($v === null || $v === '') return '—';
    return rtrim(rtrim(number_format((float)$v, 4, '.', ' '), '0'), '.');
}
function formatPrice($v) {
    if ($v === null || $v === '') return '—';
    return rtrim(rtrim(number_format((float)$v, 4, '.', ' '), '0'), '.');
}
?>
<div class="container-fluid w-75 mt-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">
            <i class="bi bi-file-earmark-text"></i>
            <?= htmlspecialchars($docTitle) ?>
            <span class="font-weight-bold"><?= htmlspecialchars($docNumber) ?></span>
            <span class="badge <?= $stateBadgeClass[$doc->state] ?? 'badge-secondary' ?> ml-2"><?= htmlspecialchars($stateBadgeLabel[$doc->state] ?? $doc->state) ?></span>
        </h2>
        <a href="http://<?= BASEURL ?>/purchase/cart" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left"></i> Wróć do koszyka
        </a>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Nagłówek</strong></div>
        <div class="card-body py-2">
            <dl class="row mb-0">
                <dt class="col-sm-2">Dostawca:</dt>
                <dd class="col-sm-4"><?= htmlspecialchars($doc->vendorName ?? '—') ?></dd>
                <dt class="col-sm-2"><?= htmlspecialchars($type === 'po' ? 'Numer PO:' : 'Numer RFQ:') ?></dt>
                <dd class="col-sm-4"><?= htmlspecialchars($docNumber !== ('#' . $doc->id) ? $docNumber : '—') ?></dd>

                <dt class="col-sm-2"><?= htmlspecialchars($expectedDateLabel) ?></dt>
                <dd class="col-sm-4"><?= $expectedDate ? htmlspecialchars($expectedDate) : '—' ?></dd>
                <dt class="col-sm-2">Wysłane:</dt>
                <dd class="col-sm-4"><?= htmlspecialchars($sentAt ?? '—') ?></dd>

                <?php if ($type === 'po'): ?>
                    <dt class="col-sm-2">Numer u dostawcy:</dt>
                    <dd class="col-sm-4"><?= htmlspecialchars($vendorPoNumber ?? '—') ?></dd>
                    <?php if ($confirmedAt !== null): ?>
                        <dt class="col-sm-2">Potwierdzone:</dt>
                        <dd class="col-sm-4"><?= htmlspecialchars($confirmedAt) ?></dd>
                    <?php else: ?>
                        <dt class="col-sm-2">&nbsp;</dt>
                        <dd class="col-sm-4">&nbsp;</dd>
                    <?php endif; ?>

                    <?php if ($convertedFromRfqId): ?>
                        <dt class="col-sm-2">Z zapytania:</dt>
                        <dd class="col-sm-4">
                            <a href="http://<?= BASEURL ?>/admin/purchase/documents/edit?id=<?= (int)$convertedFromRfqId ?>&amp;type=rfq">
                                <?= htmlspecialchars($convertedFromRfqNumber ?? ('RFQ #' . $convertedFromRfqId)) ?>
                            </a>
                        </dd>
                    <?php else: ?>
                        <dt class="col-sm-2">&nbsp;</dt>
                        <dd class="col-sm-4">&nbsp;</dd>
                    <?php endif; ?>
                <?php endif; ?>

                <dt class="col-sm-2">Utworzył:</dt>
                <dd class="col-sm-4"><?= htmlspecialchars($doc->createdByName ?? ('#' . (int)$doc->createdBy)) ?></dd>
                <dt class="col-sm-2">Utworzone:</dt>
                <dd class="col-sm-4"><?= htmlspecialchars($doc->createdAt ?? '—') ?></dd>

                <?php if (!empty($doc->comment)): ?>
                    <dt class="col-sm-2">Komentarz:</dt>
                    <dd class="col-sm-10"><?= nl2br(htmlspecialchars($doc->comment)) ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong>Pozycje</strong>
            <?php if ($type === 'po'): ?>
                <span class="text-muted">Łącznie: <?= htmlspecialchars(formatQty($itemRepo->sumQuantityReceivedByPo($id))) ?> / <?= htmlspecialchars(formatQty(array_sum(array_map(fn($i) => (float)$i->quantity, $items)))) ?> szt (odebrane / zamówione)</span>
            <?php else: ?>
                <span class="text-muted">Łącznie: <?= count($items) ?> <?= count($items) === 1 ? 'pozycja' : 'pozycji' ?></span>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>Część</th>
                            <th>Producent</th>
                            <th class="text-right">Ilość</th>
                            <th class="text-right">Cena</th>
                            <th>Waluta</th>
                            <th class="text-right">Wartość</th>
                            <?php if ($type === 'po'): ?>
                                <th class="text-right">Odebrane</th>
                            <?php endif; ?>
                            <th>Komentarz pozycji:</th>
                            <?php if ($allowedEdit): ?>
                                <th class="text-right">Akcje</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <?php
                        // Column count for the empty-state row (and for
                        // the JS append-empty-row fallback after delete).
                        // RFQ: 8 with edit, 7 without. PO: 9 with edit,
                        // 8 without (extra Odebrane column).
                        $colspan = $type === 'po'
                            ? ($allowedEdit ? 9 : 8)
                            : ($allowedEdit ? 8 : 7);
                    ?>
                    <tbody id="doc-items-tbody" data-colspan="<?= $colspan ?>">
                    <?php if (empty($items)): ?>
                        <tr><td colspan="<?= $colspan ?>" class="text-center text-muted">Brak pozycji.</td></tr>
                    <?php else: foreach ($items as $i): ?>
                        <tr data-doc-item-id="<?= (int)$i->id ?>"
                            data-vendor-part-id="<?= (int)$i->vendorPartId ?>"
                            data-vp-comment="<?= htmlspecialchars((string)($i->vendorPartComment ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            data-quantity-unit-id="<?= (int)$i->quantityUnitId ?>"
                            data-quantity="<?= htmlspecialchars((string)$i->quantity) ?>"
                            data-unit-price="<?= $i->unitPrice === null ? '' : htmlspecialchars((string)$i->unitPrice) ?>"
                            data-currency="<?= htmlspecialchars((string)($i->currency ?? 'PLN')) ?>"
                            data-unit-name="<?= htmlspecialchars((string)($i->unitName ?? '')) ?>"
                            data-picked-pack-size="<?= $i->pickedPackSize === null ? '' : htmlspecialchars((string)$i->pickedPackSize) ?>"
                            data-comment="<?= htmlspecialchars((string)($i->comment ?? '')) ?>"
                            data-part-name="<?= htmlspecialchars((string)($i->partName ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            data-vendor-name="<?= htmlspecialchars((string)($i->vendorName ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            data-vendor-part-no="<?= htmlspecialchars((string)($i->vendorPartNo ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            data-producer-part-no="<?= htmlspecialchars((string)($i->producerPartNo ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            data-part-description="<?= htmlspecialchars((string)($i->partDescription ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                            <?php if ($type === 'po'): ?>
                            data-quantity-received="<?= htmlspecialchars((string)$i->quantityReceived) ?>"
                            <?php endif; ?>>
                            <td>
                                <?= htmlspecialchars($i->partName ?? '—') ?>
                                <?php
                                    $vn = $i->vendorPartNo ?? '';
                                    $pn = $i->producerPartNo ?? '';
                                    if ($vn && $pn && $vn === $pn): ?>
                                    <div><small class="text-muted">Nr dost./prod.: <span class="font-weight-bold"><?= htmlspecialchars($vn) ?></span></small></div>
                                    <?php else: ?>
                                    <?php if ($vn): ?>
                                        <div><small class="text-muted">Nr dost.: <span class="font-weight-bold"><?= htmlspecialchars($vn) ?></span></small></div>
                                    <?php endif; ?>
                                    <?php if ($pn): ?>
                                        <div><small class="text-muted">Nr prod.: <span class="font-weight-bold"><?= htmlspecialchars($pn) ?></span></small></div>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                <?php if (!empty($i->partDescription)): ?>
                                    <div><small class="text-muted"><?= htmlspecialchars($i->partDescription) ?></small></div>
                                <?php endif; ?>
                                <?php $vpCmt = $i->vendorPartComment ?? ''; ?>
                                <div class="doc-row-vp-comment-line">
                                    <small class="text-muted">
                                        <i class="bi bi-journal-text"></i> Uwagi do artykułu: <?= $vpCmt !== '' ? htmlspecialchars($vpCmt) : '<em>Brak</em>' ?>
                                    </small>
                                    <button type="button" class="btn btn-link btn-sm p-0 ml-1 doc-row-vp-comment-edit"
                                            data-vp-id="<?= (int)$i->vendorPartId ?>"
                                            title="<?= $vpCmt !== '' ? 'Edytuj uwagi' : 'Dodaj uwagi' ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </div>
                            </td>
                            <td>
                                <?= htmlspecialchars($i->producerName ?? '—') ?>
                                <?php if (!empty($i->producerPartNo)): ?>
                                    <div><small class="text-muted"><?= htmlspecialchars($i->producerPartNo) ?></small></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-right doc-cell-qty">
                                <?= htmlspecialchars(formatQty($i->quantity)) ?>
                                <?php
                                    // Pack sub-line + badge when pickedPackSize
                                    // is set. Mirrors the cart's read-mode
                                    // treatment so users see the package
                                    // breakdown without entering edit mode.
                                    $pickedPack = isset($i->pickedPackSize) ? (float)$i->pickedPackSize : null;
                                    if ($pickedPack !== null && $pickedPack > 0):
                                        $qtyVal  = (float)$i->quantity;
                                        $pkgs    = $qtyVal / $pickedPack;
                                        $evenPkgs = abs($pkgs - round($pkgs)) < 1e-9;
                                        $pkgsStr = rtrim(rtrim(number_format($pkgs, 2, '.', ''), '0'), '.');
                                ?>
                                    <div>
                                        <small class="<?= $evenPkgs ? 'text-muted' : 'text-warning' ?>">
                                            <?= htmlspecialchars($pkgsStr) ?> opak.
                                        </small>
                                        <span class="badge badge-light border text-monospace ml-1" title="Wybrana wielkość opakowania">
                                            opak. <?= htmlspecialchars(formatQty($pickedPack)) ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($i->unitName)): ?>
                                    <div><small class="text-muted"><?= htmlspecialchars($i->unitName) ?></small></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-right doc-cell-price">
                                <?php if ($i->unitPrice !== null): ?>
                                    <?= htmlspecialchars(formatPrice($i->unitPrice)) ?>
                                    <div><small class="text-muted">/ <?= htmlspecialchars($i->unitName ?? '—') ?></small></div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($i->currency ?? '—') ?></td>
                            <td class="text-right doc-cell-value">
                                <?php if ($i->unitPrice !== null): ?>
                                    <?= htmlspecialchars(formatPrice(((float)$i->unitPrice) * (float)$i->quantity)) ?>
                                    <div><small class="text-muted"><?= htmlspecialchars($i->currency ?? '—') ?></small></div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($type === 'po'): ?>
                                <td class="text-right doc-cell-received">
                                    <?= htmlspecialchars(formatQty($i->quantityReceived ?? 0)) ?>
                                    <?php if (!empty($i->unitName)): ?>
                                        <div><small class="text-muted"><?= htmlspecialchars($i->unitName) ?></small></div>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                            <td class="doc-cell-comment"><?= $i->comment !== null && $i->comment !== '' ? nl2br(htmlspecialchars($i->comment)) : '' ?></td>
                            <?php if ($allowedEdit): ?>
                                <td class="text-right doc-cell-akcje" style="white-space: nowrap;">
                                    <button type="button" class="btn btn-sm btn-outline-primary doc-row-edit mr-1" title="Edytuj ilość / cenę / komentarz">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger doc-row-delete" title="Usuń pozycję">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($allowedEdit): ?>
    <?php
        // Pre-compute vendor_part_ids already on this document so the
        // cascade picker can hide them.
        $existingVpIds = [];
        foreach ($items as $existingItem) {
            $existingVpIds[] = (int)$existingItem->vendorPartId;
        }
        $existingVpIdsJson = htmlspecialchars(json_encode(array_values(array_unique($existingVpIds))), ENT_QUOTES, 'UTF-8');

        // ---- Cascade picker data ----
        // Parts: only active parts that have at least one active
        // VendorPart for this document's vendor. Mirrors rfqs-edit.php.
        $partsForVendorStmt = $MsaDB->db->prepare(
            "SELECT DISTINCT p.id, p.name, p.description
               FROM `list__parts` p
               JOIN `list__vendor_part` vp ON vp.parts_id = p.id AND vp.isActive = 1
              WHERE p.isActive = 1
                AND vp.vendor_id = ?
              ORDER BY p.name ASC"
        );
        $partsForVendorStmt->execute([$doc->vendorId]);
        $partsForVendor = $partsForVendorStmt->fetchAll(\PDO::FETCH_ASSOC);

        $vpsForVendorStmt = $MsaDB->db->prepare(
            "SELECT vp.id, vp.parts_id, vp.vendor_part_no, vp.producer_part_no,
                    vp.vendor_jm_id, vp.comment AS private_comment,
                    lp.name AS part_name, lp.description AS part_description,
                    pr.name AS producer_name, u.name AS unit_name
               FROM `list__vendor_part` vp
               JOIN `list__parts`    lp ON lp.id = vp.parts_id
               JOIN `part__unit`     u  ON u.id  = vp.vendor_jm_id
               LEFT JOIN `list__producer` pr ON pr.id = vp.producer_id
              WHERE vp.isActive = 1
                AND vp.vendor_id = ?
              ORDER BY vp.vendor_part_no ASC"
        );
        $vpsForVendorStmt->execute([$doc->vendorId]);
        $vpsForVendor = $vpsForVendorStmt->fetchAll(\PDO::FETCH_ASSOC);

        $packsByVpId = [];
        $vpIdsForPacks = array_map(fn($r) => (int)$r['id'], $vpsForVendor);
        if (!empty($vpIdsForPacks)) {
            $placeholders = implode(',', array_fill(0, count($vpIdsForPacks), '?'));
            $packStmt = $MsaDB->db->prepare(
                "SELECT vendor_part_id,
                        MIN(full_pack_quantity) AS min_pack,
                        GROUP_CONCAT(full_pack_quantity ORDER BY full_pack_quantity ASC) AS packs_csv
                   FROM `list__vendor_part_pack`
                  WHERE vendor_part_id IN ($placeholders)
                  GROUP BY vendor_part_id"
            );
            $packStmt->execute($vpIdsForPacks);
            foreach ($packStmt->fetchAll(\PDO::FETCH_ASSOC) as $pr) {
                $packsByVpId[(int)$pr['vendor_part_id']] = [
                        'packs'   => $pr['packs_csv'] === null ? [] : array_map('floatval', explode(',', $pr['packs_csv'])),
                        'minPack' => $pr['min_pack'] === null ? null : (float)$pr['min_pack'],
                    ];
            }
        }

        $vpsByPart = [];
        foreach ($vpsForVendor as $vp) {
            $pid = (int)$vp['parts_id'];
            $pack = $packsByVpId[(int)$vp['id']] ?? ['packs' => [], 'minPack' => null];
            $vpsByPart[$pid][] = [
                'id'                 => (int)$vp['id'],
                'parts_id'           => $pid,
                'vendor_part_no'     => $vp['vendor_part_no'] ?? '',
                'producer_part_no'   => $vp['producer_part_no'] ?? null,
                'vendor_jm_id'       => (int)$vp['vendor_jm_id'],
                'unit_name'          => $vp['unit_name'] ?? '',
                'part_name'          => $vp['part_name'] ?? '',
                'part_description'   => $vp['part_description'] ?? '',
                'producer_name'      => $vp['producer_name'] ?? null,
                'private_comment'    => $vp['private_comment'] ?? null,
                'pack_quantities'    => $pack['packs'],
                'min_pack'           => $pack['minPack'],
            ];
        }
        $vpsByPartJson = htmlspecialchars(json_encode($vpsByPart), ENT_QUOTES, 'UTF-8');
    ?>
<div class="doc-add-wrapper"
         data-doc-type="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
         data-doc-id="<?= (int)$doc->id ?>"
         data-vendor-id="<?= (int)$doc->vendorId ?>"
         data-vendor-name="<?= htmlspecialchars($doc->vendorName ?? '', ENT_QUOTES, 'UTF-8') ?>"
         data-existing-vp-ids="<?= $existingVpIdsJson ?>"
         data-vps-by-part="<?= $vpsByPartJson ?>"
         data-add-url="document-item-add.php"
         data-update-url="document-item-update.php"
         data-delete-url="document-item-delete.php">
        <div class="text-center mb-3">
            <button type="button" class="btn btn-outline-primary btn-sm doc-add-toggle" title="Dodaj pozycję">
                <i class="bi bi-plus-circle"></i> Dodaj pozycję
            </button>
        </div>

        <div class="card mb-3 doc-add-card" style="display: none;">
            <div class="card-header">
                <strong><i class="bi bi-plus-circle"></i> Dodaj pozycję</strong>
            </div>
            <div class="card-body">
                <div class="doc-add-vendor-row mb-2">
                    <small class="text-muted">
                        Dostawca: <strong id="doc-add-vendor-name">—</strong>
                    </small>
                </div>

                <div class="form-row">
                    <div class="form-group col-md-12 mb-2" id="doc-add-part-cell" style="min-width: 0;">
                        <label class="small mb-1" for="doc-add-part-select">Część:</label>
                        <select id="doc-add-part-select"
                                class="selectpicker form-control doc-add-part-select"
                                data-live-search="true"
                                data-width="100%"
                                data-container="#doc-add-part-cell"
                                title="Wybierz część…">
                            <?php foreach ($partsForVendor as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"
                                        data-subtext="<?= htmlspecialchars($p['description']) ?>">
                                    <?= htmlspecialchars($p['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row" id="doc-add-vp-row" style="display: none;">
                    <div class="form-group col-md-6 mb-2" id="doc-add-vp-cell" style="min-width: 0;">
                        <label class="small mb-1" for="doc-add-vendor-part-select">Numer u dostawcy:</label>
                        <select id="doc-add-vendor-part-select"
                                class="selectpicker form-control doc-add-vendor-part-select"
                                data-live-search="true"
                                data-width="100%"
                                data-container="#doc-add-vp-cell"
                                title="Najpierw wybierz część…">
                        </select>
                        <div id="doc-add-vp-summary" class="small text-muted mt-1" style="display: none;"></div>
                    </div>
                    <div class="form-group col-md-3 mb-2" id="doc-add-pack-wrap" style="display: none;">
                        <label class="small mb-1" for="doc-add-packages">Opak.:</label>
                        <input type="number" step="any" min="0" id="doc-add-packages" class="form-control form-control-sm" placeholder="opak.">
                        <small class="text-muted d-block doc-add-pack-size-display mt-1" style="display:none">
                            = 10 szt./opak.
                        </small>
                        <div id="doc-add-pack-stepper-row" class="btn-group btn-group-sm mt-1" role="group" style="display:none">
                            <button type="button" class="btn btn-outline-secondary" id="doc-add-pack-prev" title="Poprzednia wielkość">&minus;</button>
                            <button type="button" class="btn btn-outline-secondary" id="doc-add-pack-value" disabled>10</button>
                            <button type="button" class="btn btn-outline-secondary" id="doc-add-pack-next" title="Następna wielkość">+</button>
                        </div>
                    </div>
                    <div class="form-group col-md-3 mb-2" id="doc-add-qty-wrap" style="display: none;">
                        <label class="small mb-1" for="doc-add-qty">Ilość *</label>
                        <input type="number" step="any" min="0" id="doc-add-qty" class="form-control form-control-sm" required>
                    </div>
                </div>

                <div class="form-row" id="doc-add-price-row" style="display: none;">
                    <div class="form-group col-md-3 mb-2">
                        <label class="small mb-1" for="doc-add-price">Cena</label>
                        <input type="number" step="any" min="0" id="doc-add-price" class="form-control form-control-sm" placeholder="opcjonalnie">
                    </div>
                    <div class="form-group col-md-2 mb-2">
                        <label class="small mb-1" for="doc-add-currency">Waluta</label>
                        <input type="text" id="doc-add-currency" class="form-control form-control-sm" value="PLN" maxlength="8">
                    </div>
                    <div class="form-group col-md-7 mb-2">
                        <label class="small mb-1" for="doc-add-comment">Komentarz</label>
                        <textarea id="doc-add-comment" class="form-control form-control-sm" rows="1" placeholder="opcjonalnie"></textarea>
                    </div>
                </div>

                <div class="form-row" id="doc-add-actions-row" style="display: none;">
                    <div class="col-12 text-right">
                        <button type="button" class="btn btn-success btn-sm doc-add-save" disabled>
                            <i class="bi bi-check-lg"></i> Zapisz pozycję
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm doc-add-cancel">
                            Wyczyść
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <p class="text-muted small">
        <?php if ($allowedEdit): ?>
            Ilość, cena i komentarz pozycji są edytowalne inline (przycisk ołówka w kolumnie Akcje).
        <?php else: ?>
            Dokument w stanie <code><?= htmlspecialchars($doc->state) ?></code> — pozycje są tylko do odczytu.
        <?php endif; ?>
    </p>
</div>

<div class="modal fade" id="docItemDeleteModal" tabindex="-1" role="dialog" aria-labelledby="docItemDeleteModalTitle" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="docItemDeleteModalTitle">Usunąć pozycję?</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Czy na pewno chcesz usunąć pozycję:</p>
                <div class="border rounded p-2 mb-2 small">
                    <div class="font-weight-bold" id="docItemDeletePartName">—</div>
                    <div class="text-muted" id="docItemDeleteVendorLine" style="display:none"></div>
                    <div class="text-muted" id="docItemDeleteProducerLine" style="display:none"></div>
                    <div class="text-muted" id="docItemDeleteDescLine" style="display:none"></div>
                    <div class="text-muted" id="docItemDeleteCommentLine" style="display:none">
                        <i class="bi bi-journal-text"></i> Komentarz: <span id="docItemDeleteCommentText">—</span>
                    </div>
                </div>
                <p class="text-muted small mb-0" id="docItemDeleteMeta"></p>
                <p class="text-danger small mb-0 mt-2">Tej operacji nie można cofnąć.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-danger" id="docItemDeleteConfirm">Usuń</button>
            </div>
        </div>
    </div>
</div>

<!-- Inline `<style>` block keeps the .packages-uneven rule scoped to
     this component without adding a new asset. Mirrors cart's
     purchases.css lines 38–46 and the legacy rfqs-edit.php. -->
<style>
    .packages-uneven {
        border-color: #ffc107;
        background-color: #fff8e1;
    }
    .packages-uneven:focus {
        border-color: #ffb300;
        box-shadow: 0 0 0 .2rem rgba(255,193,7,.25);
    }
</style>

<script src="<?= htmlspecialchars(asset('public_html/components/Admin/Purchase/Documents/documents-edit.js')) ?>"></script>
