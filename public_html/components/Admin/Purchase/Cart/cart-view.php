<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();
$vendorRepository = new VendorRepository($MsaDB);
$vendors = $vendorRepository->getAll(true);   // active only — used to populate the vendor-first picker
?>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <h2><i class="bi bi-cart3"></i> Koszyk zakupowy</h2>
            <p class="text-muted">
                Wybierz <strong>część</strong> i dobierz dostawcę z najlepszą ceną,
                albo <strong>dostawcę</strong> i wybierz pozycje z jego katalogu.
                Koszyk grupuje pozycje po dostawcy &mdash; każde zamówienie/zapytanie
                obejmuje tylko jednego dostawcę. Koszyk jest tymczasowy &mdash; po odświeżeniu
                strony zostaje wyczyszczony.
            </p>
            <div id="alertContainer"></div>
        </div>
    </div>

    <!-- ===== A. Tryb: wg części ===== -->
    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">A. Wybierz część</h5></div>
        <div class="card-body">
            <label for="partPicker">Część:</label>
            <select id="partPicker" class="selectpicker form-control" data-live-search="true" data-width="100%">
                <option value="">Wyszukaj część...</option>
            </select>
            <small id="partSummary" class="text-muted form-text mt-2"></small>
        </div>
    </div>

    <div class="card mb-3" id="vendorListCard" style="display:none">
        <div class="card-header"><h5 class="mb-0">Dostępni dostawcy</h5></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="thead-light">
                        <tr>
                            <th>Dostawca</th>
                            <th>Numer u dostawcy</th>
                            <th>Numer u producenta</th>
                            <th>JM</th>
                            <th>Pełne opak.</th>
                            <th>Ostatnia cena</th>
                            <th style="width: 110px;">Ilość</th>
                            <th style="width: 110px;">Akcje</th>
                        </tr>
                    </thead>
                    <tbody id="vendorListBody"></tbody>
                </table>
            </div>
            <div id="vendorListEmpty" class="alert alert-warning" style="display:none">
                Brak aktywnych dostawców dla wybranej części. Dodaj nowego dostawcę w
                <a href="/admin/purchase/vendor-parts">Admin → Zakupy → Artykuły u dostawców</a>.
            </div>
        </div>
    </div>

    <!-- ===== B. Tryb: wg dostawcy ===== -->
    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">B. Wybierz dostawcę</h5></div>
        <div class="card-body">
            <label for="vendorPicker">Dostawca:</label>
            <select id="vendorPicker" class="selectpicker form-control" data-live-search="true" data-width="100%">
                <option value="">Wybierz dostawcę...</option>
                <?php foreach ($vendors as $v): ?>
                    <option value="<?= $v->id ?>"
                        data-name="<?= htmlspecialchars($v->name) ?>"
                        data-lead-time="<?= (int)$v->leadTimeDays ?>">
                        <?= htmlspecialchars($v->name) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <small id="vendorSummary" class="text-muted form-text mt-2"></small>
        </div>
    </div>

    <div class="card mb-3" id="vendorCatalogCard" style="display:none">
        <div class="card-header"><h5 class="mb-0">Katalog dostawcy</h5></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead class="thead-light">
                        <tr>
                            <th>Część</th>
                            <th>Numer u dostawcy</th>
                            <th>Numer u producenta</th>
                            <th>JM</th>
                            <th>Pełne opak.</th>
                            <th>Ostatnia cena</th>
                            <th style="width: 110px;">Ilość</th>
                            <th style="width: 110px;">Akcje</th>
                        </tr>
                    </thead>
                    <tbody id="vendorCatalogBody"></tbody>
                </table>
            </div>
            <div id="vendorCatalogEmpty" class="alert alert-warning" style="display:none">
                Ten dostawca nie ma jeszcze żadnych artykułów w katalogu. Dodaj je w
                <a href="/admin/purchase/vendor-parts">Admin → Zakupy → Artykuły u dostawców</a>.
            </div>
        </div>
    </div>

    <!-- ===== C. Koszyk (grupowany po dostawcy) ===== -->
    <div class="card mb-3" id="cartCard" style="display:none">
        <div class="card-header">
            <h5 class="mb-0">
                C. Koszyk
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
</script>
<script src="<?= asset('public_html/components/Admin/Purchase/Cart/cart-view.js') ?>"></script>
