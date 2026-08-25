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
// producer_part_no, full_pack_quantity, vendor_jm_id, unit_name, vendor_name, …}
// (consumed by cart-view.js loadSelectionDocs()).
$vpRows = $MsaDB->query(
    "SELECT vp.id, vp.vendor_id, vp.parts_id, vp.vendor_part_no,
            vp.producer_part_no, vp.vendor_jm_id, vp.full_pack_quantity,
            v.name AS vendor_name, u.name AS unit_name, p.name AS part_name,
            pr.name AS producer_name
       FROM `list__vendor_part` vp
       JOIN `list__vendor` v ON vp.vendor_id = v.id
       JOIN `part__unit`    u ON vp.vendor_jm_id = u.id
       JOIN `list__parts`   p ON vp.parts_id = p.id
       LEFT JOIN `list__producer` pr ON pr.id = vp.producer_id
      WHERE vp.is_active = 1 AND v.is_active = 1"
);
$vendorPartsIndex = [];
foreach ($vpRows as $r) {
    $entry = [
        'id'                 => (int)$r['id'],
        'vendor_id'          => (int)$r['vendor_id'],
        'parts_id'           => (int)$r['parts_id'],
        'vendor_part_no'     => $r['vendor_part_no'],
        'producer_part_no'   => $r['producer_part_no'],
        'producer_name'      => $r['producer_name'],
        'vendor_jm_id'       => (int)$r['vendor_jm_id'],
        'unit_name'          => $r['unit_name'],
        'full_pack_quantity' => (float)$r['full_pack_quantity'],
        'vendor_name'        => $r['vendor_name'],
        'part_name'          => $r['part_name'],
    ];
    $vendorPartsIndex[$r['vendor_id'] . ':' . $r['parts_id']][] = $entry;
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
                    <select id="vendorSelect" class="selectpicker form-control" data-live-search="true" data-width="100%" title="Wybierz dostawcę...">
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
                    <select id="partSelect" class="selectpicker form-control" data-live-search="true" data-width="100%" title="Wybierz część...">
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
            <div class="row mt-3" id="vendorPartRow">
                <div class="col-md-4">
                    <label for="vendorPartNoSelect">Numer u dostawcy:</label>
                    <div class="d-flex align-items-start">
                        <div class="flex-grow-1 mr-1">
                            <select id="vendorPartNoSelect" class="selectpicker form-control" data-live-search="true" data-width="100%" title="Wybierz numer u dostawcy...">
                                <!-- populated by JS once vendor and/or part are picked;
                                     catalog-wide lookup lives in the search modal -->
                            </select>
                        </div>
                        <a href="#" id="vpSearchBtn" class="text-muted clear-picker-link ml-1" title="Szukaj po numerze dostawcy / producenta / części"><i class="bi bi-search"></i></a>
                    </div>
                </div>
                <div class="col-md-2">
                    <label for="cartPackages">Opak.:</label>
                    <input type="number" id="cartPackages" class="form-control" min="0" step="1" placeholder="opak." title="Ilość = opakowania × ilość w opakowaniu">
                </div>
                <div class="col-md-2">
                    <label for="cartQty">Ilość:</label>
                    <input type="number" id="cartQty" class="form-control" min="0.0001" step="0.0001" placeholder="szt.">
                </div>
                <div class="col-md-2">
                    <label for="cartPrice">Cena/Szt.:</label>
                    <input type="number" id="cartPrice" class="form-control" min="0" step="0.0001" placeholder="opcjonalna">
                </div>
                <div class="col-md-2">
                    <label for="cartCurrency">Waluta:</label>
                    <select id="cartCurrency" class="form-control">
                        <option value="PLN" selected>PLN</option>
                        <option value="EUR">EUR</option>
                        <option value="USD">USD</option>
                    </select>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-12 text-right">
                    <button type="button" id="clearSelectionBtn" class="btn btn-danger mr-1" title="Wyczyść wybór dostawcy i części">
                        Wyczyść
                    </button>
                    <button type="button" id="addToCartBtn" class="btn btn-success" disabled>
                        <i class="bi bi-plus-circle"></i> Dodaj
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Modal: szukaj numeru u dostawcy ===== -->
    <div class="modal fade" id="vpSearchModal" tabindex="-1" role="dialog" aria-hidden="true">
        <div class="modal-dialog modal-lg" role="document">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h5 class="modal-title"><i class="bi bi-search"></i> Szukaj numeru u dostawcy</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Zamknij"><span>&times;</span></button>
                </div>
                <div class="modal-body">
                    <input type="text" id="vpSearchInput" class="form-control" placeholder="Min. 2 znaki — numer dostawcy, numer producenta, część lub dostawca…" autocomplete="off">
                    <div id="vpSearchStatus" class="text-muted mt-2" style="display:none"></div>
                    <div class="table-responsive mt-2" style="max-height:50vh; overflow-y:auto;">
                        <table class="table table-sm table-striped mb-0">
                            <thead class="thead-light">
                                <tr><th>Numer u dostawcy</th><th>Nr producenta</th><th>Producent</th><th>Dostawca</th><th>Część</th><th>JM / opak.</th><th></th></tr>
                            </thead>
                            <tbody id="vpSearchResults"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Aktywne dokumenty dla wybranej pozycji ===== -->
    <style>
        #selectionDocsHeader { cursor: pointer; user-select: none; }
        #selectionDocsHeader:hover { background-color: rgba(0,0,0,.04); }
        /* <i> is inline by default — transform needs inline-block to apply */
        #selectionDocsHeader .bi-chevron-right {
            display: inline-block;
            transition: transform .15s ease;
        }
        #selectionDocsHeader[aria-expanded="true"] .bi-chevron-right,
        #selectionDocsHeader.is-open .bi-chevron-right { transform: rotate(90deg); }
        .clear-picker-link {
            font-size: 1.3rem;
            line-height: 38px;
            text-decoration: none;
        }
        .clear-picker-link:hover { color: #dc3545 !important; text-decoration: none; }
        /* Inline "select this vendor" icon next to the group name */
        .select-vendor-link { font-size: 1.1rem; text-decoration: none; }
        .select-vendor-link:hover { color: #007bff !important; text-decoration: none; }
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
    <div class="card mb-3" id="selectionDocsCard" style="display:none">
        <div class="card-header py-2" id="selectionDocsHeader" role="button" data-toggle="collapse"
                data-target="#selectionDocsBody" aria-expanded="false" aria-controls="selectionDocsBody">
            <i class="bi bi-chevron-right text-muted mr-1"></i>
            Aktywne dokumenty dla wybranej pozycji
            <span class="badge badge-pill badge-warning align-middle ml-1" id="selectionDocsCount" style="display:none">0</span>
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
