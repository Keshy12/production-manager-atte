<?php
/**
 * Minimal RFQ edit page (read-only for now).
 *
 * Wired at /admin/purchase/rfqs/edit?id=N. Mirrors orders-edit.php;
 * will grow into a real edit form later (vendor / dates / items /
 * state transitions).
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\RFQRepository;
use Atte\Utils\Purchase\Order\RFQItemRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">Brak id zapytania.</div></div>';
    return;
}

$MsaDB = MsaDB::getInstance();
$rfqRepo = new RFQRepository($MsaDB);
$rfiRepo = new RFQItemRepository($MsaDB);

$rfq = $rfqRepo->getById($id);
if ($rfq === null) {
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">Zapytanie #' . htmlspecialchars((string)$id) . ' nie istnieje.</div></div>';
    return;
}
$items = $rfiRepo->getByRfq($id);

$stateBadgeClass = [
    'draft'     => 'badge-secondary',
    'sent'      => 'badge-primary',
    'responded' => 'badge-info',
    'cancelled' => 'badge-danger',
    'converted' => 'badge-success',
];
$stateBadgeLabel = [
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
            Zapytanie ofertowe
            <span class="font-weight-bold"><?= htmlspecialchars($rfq->rfqNumber ?? ('#' . $rfq->id)) ?></span>
            <span class="badge <?= $stateBadgeClass[$rfq->state] ?? 'badge-secondary' ?> ml-2"><?= $stateBadgeLabel[$rfq->state] ?? $rfq->state ?></span>
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
                <dd class="col-sm-4"><?= htmlspecialchars($rfq->vendorName ?? '—') ?></dd>
                <dt class="col-sm-2">Numer RFQ:</dt>
                <dd class="col-sm-4"><?= htmlspecialchars($rfq->rfqNumber ?? '—') ?></dd>

                <dt class="col-sm-2">Oczekiwana odpowiedź:</dt>
                <dd class="col-sm-4"><?= $rfq->expectedReplyDate ? htmlspecialchars($rfq->expectedReplyDate) : '—' ?></dd>
                <dt class="col-sm-2">Wysłane:</dt>
                <dd class="col-sm-4"><?= htmlspecialchars($rfq->sentAt ?? '—') ?></dd>

                <dt class="col-sm-2">Utworzył:</dt>
                <dd class="col-sm-4"><?= htmlspecialchars($rfq->createdByName ?? ('#' . (int)$rfq->createdBy)) ?></dd>
                <dt class="col-sm-2">Utworzone:</dt>
                <dd class="col-sm-4"><?= htmlspecialchars($rfq->createdAt ?? '—') ?></dd>

                <?php if ($rfq->comment): ?>
                    <dt class="col-sm-2">Komentarz:</dt>
                    <dd class="col-sm-10"><?= nl2br(htmlspecialchars($rfq->comment)) ?></dd>
                <?php endif; ?>
            </dl>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><strong>Pozycje</strong></div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0">
                    <thead class="thead-light">
                        <tr>
                            <th>Część</th>
                            <th>Producent</th>
                            <th class="text-right">Ilość</th>
                            <th class="text-right">Cena</th>
                            <th class="text-right">Wartość</th>
                            <th>Uwagi</th>
                            <th class="text-right">Akcje</th>
                        </tr>
                    </thead>
                    <tbody id="rfq-items-tbody">
                    <?php if (empty($items)): ?>
                        <tr><td colspan="7" class="text-center text-muted">Brak pozycji.</td></tr>
                    <?php else: foreach ($items as $i): ?>
                        <tr data-rfq-item-id="<?= (int)$i->id ?>"
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
                            data-part-description="<?= htmlspecialchars((string)($i->partDescription ?? ''), ENT_QUOTES, 'UTF-8') ?>">
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
                                <div class="rfq-row-vp-comment-line">
                                    <small class="text-muted">
                                        <i class="bi bi-journal-text"></i> Komentarz: <?= $vpCmt !== '' ? htmlspecialchars($vpCmt) : '<em>Brak</em>' ?>
                                    </small>
                                    <button type="button" class="btn btn-link btn-sm p-0 ml-1 rfq-row-vp-comment-edit"
                                            data-vp-id="<?= (int)$i->vendorPartId ?>"
                                            title="<?= $vpCmt !== '' ? 'Edytuj komentarz' : 'Dodaj komentarz' ?>">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                </div>
                            </td>
                            <td><?= htmlspecialchars($i->producerName ?? '—') ?></td>
                            <td class="text-right rfq-cell-qty">
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
                                        // Strip trailing zeros / dot so 55 displays
                                        // as "55" not "55.00", while 3.5 stays "3.5".
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
                            <td class="text-right rfq-cell-price">
                                <?php if ($i->unitPrice !== null): ?>
                                    <?= htmlspecialchars(formatPrice($i->unitPrice)) ?>
                                    <div><small class="text-muted"><?= htmlspecialchars($i->currency ?? '—') ?>/<?= htmlspecialchars($i->unitName ?? '—') ?></small></div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-right rfq-cell-value">
                                <?php if ($i->unitPrice !== null): ?>
                                    <?= htmlspecialchars(formatPrice(((float)$i->unitPrice) * (float)$i->quantity)) ?>
                                    <div><small class="text-muted"><?= htmlspecialchars($i->currency ?? '—') ?></small></div>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="rfq-cell-comment"><?= $i->comment !== null && $i->comment !== '' ? nl2br(htmlspecialchars($i->comment)) : '' ?></td>
                            <td class="text-right rfq-cell-akcje" style="white-space: nowrap;">
                                <button type="button" class="btn btn-sm btn-outline-primary rfq-row-edit mr-1" title="Edytuj ilość / cenę / komentarz">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger rfq-row-delete" title="Usuń pozycję">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if (in_array($rfq->state, ['draft', 'sent', 'responded'], true)): ?>
    <?php
        // Pre-compute vendor_part_ids already on this RFQ so the picker
        // can hide them from the cascade.
        $existingVpIds = [];
        foreach ($items as $existingItem) {
            $existingVpIds[] = (int)$existingItem->vendorPartId;
        }
        $existingVpIdsJson = htmlspecialchars(json_encode(array_values(array_unique($existingVpIds))), ENT_QUOTES, 'UTF-8');

        // ---- Cascade picker data ----
        // Parts: only active parts that have at least one active VendorPart
        // for this RFQ's vendor. Keeps the part dropdown tight — no
        // "pick a part that has no variant for this vendor" dead ends.
        // (BaseDB::query() doesn't support parameter binding, so we go
        //  through $MsaDB->db->prepare() directly — same pattern cart's
        //  AJAX endpoints use.)
        $partsForVendorStmt = $MsaDB->db->prepare(
            "SELECT DISTINCT p.id, p.name, p.description
               FROM `list__parts` p
               JOIN `list__vendor_part` vp ON vp.parts_id = p.id AND vp.isActive = 1
              WHERE p.isActive = 1
                AND vp.vendor_id = ?
              ORDER BY p.name ASC"
        );
        $partsForVendorStmt->execute([$rfq->vendorId]);
        $partsForVendor = $partsForVendorStmt->fetchAll(\PDO::FETCH_ASSOC);

        // VendorParts for this vendor — full row metadata so the JS
        // cascade can render options + populate the summary without an
        // extra round-trip. Filtered to isActive=1 rows that are NOT
        // already on this RFQ (a vendor can list the same VP twice with
        // different packing, but the same VP shouldn't be added twice
        // to one RFQ).
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
        $vpsForVendorStmt->execute([$rfq->vendorId]);
        $vpsForVendor = $vpsForVendorStmt->fetchAll(\PDO::FETCH_ASSOC);

        // Pre-fetch pack sizes for all VPs of this vendor (one query).
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

        // Build the JS index: keyed by parts_id → array of VendorPart
        // summaries. Cart's VENDOR_PARTS_INDEX is keyed by
        // "vendorId:partId"; here vendor is fixed so just partId suffices.
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
<div class="rfq-add-wrapper"
         data-rfq-id="<?= (int)$rfq->id ?>"
         data-rfq-state="<?= htmlspecialchars($rfq->state, ENT_QUOTES, 'UTF-8') ?>"
         data-vendor-id="<?= (int)$rfq->vendorId ?>"
         data-vendor-name="<?= htmlspecialchars($rfq->vendorName ?? '', ENT_QUOTES, 'UTF-8') ?>"
         data-existing-vp-ids="<?= $existingVpIdsJson ?>"
         data-vps-by-part="<?= $vpsByPartJson ?>">
        <div class="text-center mb-3">
            <button type="button" class="btn btn-outline-primary btn-sm rfq-add-toggle" title="Dodaj pozycję">
                <i class="bi bi-plus-circle"></i> Dodaj pozycję
            </button>
        </div>

        <div class="card mb-3 rfq-add-card" style="display: none;">
            <div class="card-header">
                <strong><i class="bi bi-plus-circle"></i> Dodaj pozycję</strong>
            </div>
            <div class="card-body">
                <!-- Vendor line (small muted text above the part picker) -->
                <div class="rfq-add-vendor-row mb-2">
                    <small class="text-muted">
                        Dostawca: <strong id="rfq-add-vendor-name">—</strong>
                    </small>
                </div>

                <!-- Row 1: part picker (always visible when card is open) -->
                <div class="form-row">
                    <div class="form-group col-md-12 mb-2" id="rfq-add-part-cell" style="min-width: 0;">
                        <label class="small mb-1" for="rfq-add-part-select">Część:</label>
                        <select id="rfq-add-part-select"
                                class="selectpicker form-control rfq-add-part-select"
                                data-live-search="true"
                                data-width="100%"
                                data-container="#rfq-add-part-cell"
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

                <!-- Row 2: VP picker + packages + qty (revealed after part picked) -->
                <div class="form-row" id="rfq-add-vp-row" style="display: none;">
                    <div class="form-group col-md-6 mb-2" id="rfq-add-vp-cell" style="min-width: 0;">
                        <label class="small mb-1" for="rfq-add-vendor-part-select">Numer u dostawcy:</label>
                        <select id="rfq-add-vendor-part-select"
                                class="selectpicker form-control rfq-add-vendor-part-select"
                                data-live-search="true"
                                data-width="100%"
                                data-container="#rfq-add-vp-cell"
                                title="Najpierw wybierz część…">
                        </select>
                        <div id="rfq-add-vp-summary" class="small text-muted mt-1" style="display: none;"></div>
                    </div>
                    <div class="form-group col-md-3 mb-2" id="rfq-add-pack-wrap" style="display: none;">
                        <label class="small mb-1" for="rfq-add-packages">Opak.:</label>
                        <input type="number" step="any" min="0" id="rfq-add-packages" class="form-control form-control-sm" placeholder="opak.">
                        <small class="text-muted d-block rfq-add-pack-size-display mt-1" style="display:none">
                            = 10 szt./opak.
                        </small>
                        <div id="rfq-add-pack-stepper-row" class="btn-group btn-group-sm mt-1" role="group" style="display:none">
                            <button type="button" class="btn btn-outline-secondary" id="rfq-add-pack-prev" title="Poprzednia wielkość">&minus;</button>
                            <button type="button" class="btn btn-outline-secondary" id="rfq-add-pack-value" disabled>10</button>
                            <button type="button" class="btn btn-outline-secondary" id="rfq-add-pack-next" title="Następna wielkość">+</button>
                        </div>
                    </div>
                    <div class="form-group col-md-3 mb-2" id="rfq-add-qty-wrap" style="display: none;">
                        <label class="small mb-1" for="rfq-add-qty">Ilość *</label>
                        <input type="number" step="any" min="0" id="rfq-add-qty" class="form-control form-control-sm" required>
                    </div>
                </div>

                <!-- Row 3: price + currency + comment (revealed after VP picked) -->
                <div class="form-row" id="rfq-add-price-row" style="display: none;">
                    <div class="form-group col-md-3 mb-2">
                        <label class="small mb-1" for="rfq-add-price">Cena</label>
                        <input type="number" step="any" min="0" id="rfq-add-price" class="form-control form-control-sm" placeholder="opcjonalnie">
                    </div>
                    <div class="form-group col-md-2 mb-2">
                        <label class="small mb-1" for="rfq-add-currency">Waluta</label>
                        <input type="text" id="rfq-add-currency" class="form-control form-control-sm" value="PLN" maxlength="8">
                    </div>
                    <div class="form-group col-md-7 mb-2">
                        <label class="small mb-1" for="rfq-add-comment">Komentarz</label>
                        <textarea id="rfq-add-comment" class="form-control form-control-sm" rows="1" placeholder="opcjonalnie"></textarea>
                    </div>
                </div>

                <!-- Row 4: actions (revealed after VP picked) -->
                <div class="form-row" id="rfq-add-actions-row" style="display: none;">
                    <div class="col-12 text-right">
                        <button type="button" class="btn btn-success btn-sm rfq-add-save" disabled>
                            <i class="bi bi-check-lg"></i> Zapisz pozycję
                        </button>
                        <button type="button" class="btn btn-outline-secondary btn-sm rfq-add-cancel">
                            Wyczyść
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <p class="text-muted small">
        Widok minimalny — akcje (wyślij / konwertuj na PO) pojawią się w kolejnych iteracjach.
        Ilość, cena i komentarz pozycji są edytowalne inline (przycisk ołówka w kolumnie Akcje).
    </p>
</div>

<div class="modal fade" id="rfqItemDeleteModal" tabindex="-1" role="dialog" aria-labelledby="rfqItemDeleteModalTitle" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rfqItemDeleteModalTitle">Usunąć pozycję?</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Czy na pewno chcesz usunąć pozycję:</p>
                <!-- Mirror the row layout from rfqs-edit.php so the user
                     sees exactly what the line looks like in the table. -->
                <div class="border rounded p-2 mb-2 small">
                    <div class="font-weight-bold" id="rfqItemDeletePartName">—</div>
                    <div class="text-muted" id="rfqItemDeleteVendorLine" style="display:none"></div>
                    <div class="text-muted" id="rfqItemDeleteProducerLine" style="display:none"></div>
                    <div class="text-muted" id="rfqItemDeleteDescLine" style="display:none"></div>
                    <div class="text-muted" id="rfqItemDeleteCommentLine" style="display:none">
                        <i class="bi bi-journal-text"></i> Komentarz: <span id="rfqItemDeleteCommentText">—</span>
                    </div>
                </div>
                <p class="text-muted small mb-0" id="rfqItemDeleteMeta"></p>
                <p class="text-danger small mb-0 mt-2">Tej operacji nie można cofnąć.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-danger" id="rfqItemDeleteConfirm">Usuń</button>
            </div>
        </div>
    </div>
</div>

<!-- Inline `<style>` block (rather than a separate CSS file) keeps the
     .packages-uneven rule scoped to this component without adding a
     new asset. Mirrors cart's purchases.css lines 38–46. -->
<style>
    /* Non-blocking warning: qty doesn't match whole packages */
    .packages-uneven {
        border-color: #ffc107;
        background-color: #fff8e1;
    }
    .packages-uneven:focus {
        border-color: #ffb300;
        box-shadow: 0 0 0 .2rem rgba(255,193,7,.25);
    }
</style>

<script src="<?= htmlspecialchars(asset('public_html/components/Admin/Purchase/RFQs/rfqs-edit.js')) ?>"></script>
