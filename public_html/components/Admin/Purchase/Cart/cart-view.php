<?php
use Atte\DB\MsaDB;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();

// Vendors with their parts_ids (only active vendors, only active VendorParts)
$vendorsWithParts = $MsaDB->query(
    "SELECT v.id, v.name, GROUP_CONCAT(vp.parts_id) AS parts_ids
       FROM `list__vendor` v
       LEFT JOIN `list__vendor_part` vp ON vp.vendor_id = v.id AND vp.is_active = 1
      WHERE v.is_active = 1
      GROUP BY v.id, v.name
      ORDER BY v.name ASC"
);

// Parts with their vendors_ids
$partsWithVendors = $MsaDB->query(
    "SELECT p.id, p.name, p.description, GROUP_CONCAT(vp.vendor_id) AS vendors_ids
       FROM `list__parts` p
       LEFT JOIN `list__vendor_part` vp ON vp.parts_id = p.id AND vp.is_active = 1
      WHERE p.isActive = 1
      GROUP BY p.id, p.name, p.description
      ORDER BY p.name ASC"
);

// VendorParts lookup index — keyed by "vendorId:partId" → list of {id, vendor_part_no,
// producer_part_no, full_pack_quantity, vendor_jm_id, unit_name, vendor_name}
$vpRows = $MsaDB->query(
    "SELECT vp.id, vp.vendor_id, vp.parts_id, vp.vendor_part_no,
            vp.producer_part_no, vp.vendor_jm_id, vp.full_pack_quantity,
            v.name AS vendor_name, u.name AS unit_name
       FROM `list__vendor_part` vp
       JOIN `list__vendor` v ON vp.vendor_id = v.id
       JOIN `part__unit`    u ON vp.vendor_jm_id = u.id
      WHERE vp.is_active = 1 AND v.is_active = 1"
);
$vendorPartsIndex = [];
foreach ($vpRows as $r) {
    $key = $r['vendor_id'] . ':' . $r['parts_id'];
    $vendorPartsIndex[$key][] = [
        'id'                 => (int)$r['id'],
        'vendor_part_no'     => $r['vendor_part_no'],
        'producer_part_no'   => $r['producer_part_no'],
        'vendor_jm_id'       => (int)$r['vendor_jm_id'],
        'unit_name'          => $r['unit_name'],
        'full_pack_quantity' => (float)$r['full_pack_quantity'],
        'vendor_name'        => $r['vendor_name'],
    ];
}
?>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <h2><i class="bi bi-cart3"></i> Koszyk zakupowy</h2>
            <p class="text-muted">
                Wybierz <strong>dostawcę</strong> i <strong>część</strong> &mdash; selektory
                filtrują się nawzajem, więc widzisz tylko kombinacje, które istnieją w katalogu.
                Jeśli dostawca ma kilka numerów katalogowych dla tej samej części, wybierz właściwy
                z listy &bdquo;Numer u dostawcy&rdquo;. Koszyk grupuje pozycje po dostawcy
                &mdash; każde zamówienie/zapytanie obejmuje tylko jednego dostawcę. Koszyk
                jest tymczasowy &mdash; po odświeżeniu strony zostaje wyczyszczony.
            </p>
            <div id="alertContainer"></div>
        </div>
    </div>

    <!-- ===== Wybierz dostawcę i część (cascading pickers) ===== -->
    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">Wybierz dostawcę i część</h5></div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <label for="vendorSelect">Dostawca:</label>
                    <select id="vendorSelect" class="selectpicker form-control" data-live-search="true" data-width="100%">
                        <option value="">Wybierz dostawcę...</option>
                        <?php foreach ($vendorsWithParts as $v): ?>
                            <option value="<?= (int)$v['id'] ?>"
                                    data-name="<?= htmlspecialchars($v['name']) ?>"
                                    data-parts='<?= htmlspecialchars(json_encode($v['parts_ids'] === null ? [] : array_map('intval', explode(',', $v['parts_ids']))), ENT_QUOTES) ?>'>
                                <?= htmlspecialchars($v['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label for="partSelect">Część:</label>
                    <select id="partSelect" class="selectpicker form-control" data-live-search="true" data-width="100%" data-show-subtext="true">
                        <option value="">Wybierz część...</option>
                        <?php foreach ($partsWithVendors as $p): ?>
                            <option value="<?= (int)$p['id'] ?>"
                                    data-name="<?= htmlspecialchars($p['name']) ?>"
                                    data-subtext="<?= htmlspecialchars($p['description']) ?>"
                                    data-vendors='<?= htmlspecialchars(json_encode($p['vendors_ids'] === null ? [] : array_map('intval', explode(',', $p['vendors_ids']))), ENT_QUOTES) ?>'>
                                <?= htmlspecialchars($p['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mt-3" id="vendorPartRow" style="display:none">
                <div class="col-md-6">
                    <label for="vendorPartNoSelect">Numer u dostawcy:</label>
                    <select id="vendorPartNoSelect" class="selectpicker form-control" data-width="100%">
                        <!-- populated by JS once vendor + part picked -->
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="cartQty">Ilość:</label>
                    <input type="number" id="cartQty" class="form-control" min="0.0001" step="0.0001" value="1">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="button" id="addToCartBtn" class="btn btn-success btn-block" disabled>
                        <i class="bi bi-plus-circle"></i> Dodaj do koszyka
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Aktywne dokumenty dla wybranej pozycji ===== -->
    <div class="card mb-3" id="selectionDocsCard" style="display:none">
        <div class="card-header py-2">
            <button type="button" class="btn btn-sm btn-link p-0 shadow-none" data-toggle="collapse"
                    data-target="#selectionDocsBody" aria-expanded="false" aria-controls="selectionDocsBody">
                <i class="bi bi-exclamation-triangle text-warning"></i> Aktywne dokumenty dla wybranej pozycji
                <span class="badge badge-pill badge-warning align-middle ml-1" id="selectionDocsCount" style="display:none">0</span>
            </button>
        </div>
        <div class="collapse" id="selectionDocsBody">
            <div class="card-body py-2" id="selectionDocsContent"></div>
        </div>
    </div>

    <!-- ===== Koszyk (grupowany po dostawcy) ===== -->
    <div class="card mb-3" id="cartCard" style="display:none">
        <div class="card-header">
            <h5 class="mb-0">
                Koszyk
                <span class="badge badge-info" id="cartCount">0</span>
                <button type="button" id="clearCartBtn" class="btn btn-sm btn-outline-secondary float-right">
                    <i class="bi bi-trash"></i> Wyczyść koszyk
                </button>
            </h5>
        </div>
        <div class="card-body" id="cartBody"></div>
    </div>
</div>

<script>
    var PURCHASE_CART_BASE = <?php echo json_encode(
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . BASEURL . '/admin/purchase/cart'
    ); ?>;
    var VENDOR_PARTS_INDEX = <?= json_encode($vendorPartsIndex, JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= asset('public_html/components/Admin/Purchase/Cart/cart-view.js') ?>"></script>
