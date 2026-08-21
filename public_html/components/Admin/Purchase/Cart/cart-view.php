<?php
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Master\VendorRepository;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();
$vendorRepository = new VendorRepository($MsaDB);
$vendors = $vendorRepository->getAll(true);   // active only
?>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <h2><i class="bi bi-cart3"></i> Koszyk zakupowy</h2>
            <p class="text-muted">
                Utwórz nowe zapytanie ofertowe lub zamówienie. Najpierw wybierz dostawcę i podaj parametry,
                potem dodaj artykuły do koszyka.
                Koszyk jest tymczasowy &mdash; po odświeżeniu strony zostaje wyczyszczony (docelowo będzie zapisywany w bazie).
            </p>
            <div id="alertContainer"></div>
        </div>
    </div>

    <!-- ===== Step 1: Parametry dokumentu (compact form) ===== -->
    <div class="row" id="step1ParamsCard">
        <div class="col-12">
            <div class="card mb-3">
                <div class="card-header py-2"><h6 class="mb-0">1. Parametry dokumentu</h6></div>
                <div class="card-body py-3">
                    <div class="form-row">
                        <div class="form-group col-md-6 mb-2">
                            <label class="small mb-1" for="cartVendor">Dostawca</label>
                            <select id="cartVendor" class="selectpicker form-control form-control-sm" data-live-search="true" data-width="100%">
                                <option value="">Wybierz...</option>
                                <?php foreach ($vendors as $v): ?>
                                    <option value="<?= $v->id ?>"><?= htmlspecialchars($v->name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group col-md-3 mb-2">
                            <label class="small mb-1" for="cartDate">Termin</label>
                            <input type="date" id="cartDate" class="form-control form-control-sm">
                        </div>
                        <div class="form-group col-md-3 mb-2 d-flex align-items-end">
                            <button id="nextBtn" class="btn btn-primary btn-sm btn-block">
                                Dalej <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                    </div>
                    <div class="form-group mb-0">
                        <label class="small mb-1" for="cartComment">Komentarz (opcjonalnie)</label>
                        <input type="text" id="cartComment" class="form-control form-control-sm" placeholder="Wpisz komentarz...">
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Step 2: collapsed summary line ===== -->
    <div id="step2SummaryCard" style="display:none">
        <div class="alert alert-light border py-2 px-3 mb-3 d-flex justify-content-between align-items-center flex-wrap">
            <div class="small flex-grow-1 mr-3" style="min-width: 0;">
                <strong>Dostawca:</strong> <span id="summaryVendor">—</span>
                <span class="mx-2 text-muted">|</span>
                <strong>Termin:</strong> <span id="summaryDate">—</span>
                <span class="mx-2 text-muted">|</span>
                <strong>Komentarz:</strong> <span id="summaryComment">—</span>
            </div>
            <div class="small">
                <a href="#" id="editParamsBtn"><i class="bi bi-pencil"></i> Edytuj</a>
                <span class="mx-2 text-muted">|</span>
                <a href="#" id="backBtn"><i class="bi bi-arrow-left"></i> Zmień dostawcę</a>
            </div>
        </div>
    </div>

    <!-- ===== Step 2: expanded edit form (hidden by default) ===== -->
    <div id="step2EditCard" style="display:none">
        <div class="card mb-3">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h6 class="mb-0">1. Parametry dokumentu</h6>
                <a href="#" id="cancelEditBtn" class="small">Anuluj</a>
            </div>
            <div class="card-body py-3">
                <div class="form-row">
                    <div class="form-group col-md-5 mb-2">
                        <label class="small mb-1">Dostawca</label>
                        <p class="form-control-plaintext mb-0"><strong id="editVendorDisplay">—</strong></p>
                    </div>
                    <div class="form-group col-md-3 mb-2">
                        <label class="small mb-1" for="cartDateEdit">Termin</label>
                        <input type="date" id="cartDateEdit" class="form-control form-control-sm">
                    </div>
                    <div class="form-group col-md-3 mb-2">
                        <label class="small mb-1" for="cartCommentEdit">Komentarz</label>
                        <input type="text" id="cartCommentEdit" class="form-control form-control-sm">
                    </div>
                    <div class="form-group col-md-1 mb-2 d-flex align-items-end">
                        <button id="saveEditBtn" class="btn btn-primary btn-sm btn-block">Zapisz</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Step 2: cart (Dodaj artykuł + Pozycje + Akcje końcowe) ===== -->
    <div id="step2Content" style="display:none">

        <div class="row mt-3" id="addItemRow">
            <div class="col-12">
                <div class="card">
                    <div class="card-header"><h5>2. Dodaj artykuł</h5></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <div class="form-group">
                                    <label for="addVendorPart">Artykuł u dostawcy:</label>
                                    <select id="addVendorPart" class="selectpicker form-control" data-live-search="true" data-width="100%">
                                        <option value="">Wybierz artykuł...</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="addQty">Ilość:</label>
                                    <input type="number" step="0.0001" min="0.0001" id="addQty" class="form-control">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="addPrice">Cena jednostkowa (opcjonalnie):</label>
                                    <input type="number" step="0.0001" min="0" id="addPrice" class="form-control">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="form-group">
                                    <label for="addCurrency">Waluta:</label>
                                    <select id="addCurrency" class="form-control">
                                        <option value="PLN" selected>PLN</option>
                                        <option value="EUR">EUR</option>
                                        <option value="USD">USD</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <button type="button" id="addItemBtn" class="btn btn-success btn-block">
                                    <i class="bi bi-plus-circle"></i> Dodaj do koszyka
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-3" id="cartItemsRow" style="display:none">
            <div class="col-12">
                <div class="card">
                    <div class="card-header">
                        <h5>
                            <i class="bi bi-list-ul"></i> Pozycje w koszyku
                            <span class="badge badge-info" id="cartCount">0</span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead class="thead-light">
                                    <tr>
                                        <th>VendorPartNo</th>
                                        <th>Part</th>
                                        <th>Producent</th>
                                        <th>JM</th>
                                        <th>Ilość</th>
                                        <th>Cena</th>
                                        <th>Waluta</th>
                                        <th>Akcje</th>
                                    </tr>
                                </thead>
                                <tbody id="cartItemsBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-3" id="finalActionsRow" style="display:none">
            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <button type="button" id="createRfqBtn" class="btn btn-primary">
                            <i class="bi bi-file-earmark-text"></i> Utwórz zapytanie
                        </button>
                        <button type="button" id="createPoBtn" class="btn btn-success">
                            <i class="bi bi-bag-check"></i> Utwórz zamówienie
                        </button>
                        <button type="button" id="clearCartBtn" class="btn btn-outline-secondary float-right">
                            <i class="bi bi-trash"></i> Wyczyść koszyk
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    var PURCHASE_CART_BASE = <?php echo json_encode(
        (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
        . '://' . BASEURL . '/admin/purchase/cart'
    ); ?>;
</script>
<script src="<?= asset('public_html/components/Admin/Purchase/Cart/cart-view.js') ?>"></script>
