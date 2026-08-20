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
                Wybierz dostawcę i dodaj artykuły do koszyka. Na końcu utwórz zapytanie ofertowe albo zamówienie.
                Koszyk jest tymczasowy &mdash; po odświeżeniu strony zostaje wyczyszczony (docelowo będzie zapisywany w bazie).
            </p>
            <div id="alertContainer"></div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h5>Parametry dokumentu</h5></div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="form-group">
                                <label for="cartVendor">Dostawca:</label>
                                <select id="cartVendor" class="selectpicker form-control" data-live-search="true" data-width="100%">
                                    <option value="">Wybierz dostawcę...</option>
                                    <?php foreach ($vendors as $v): ?>
                                        <option value="<?= $v->id ?>"><?= htmlspecialchars($v->name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label for="cartDocType">Typ dokumentu:</label>
                                <select id="cartDocType" class="form-control">
                                    <option value="rfq">Zapytanie ofertowe</option>
                                    <option value="po">Zamówienie</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="form-group">
                                <label id="cartDateLabel" for="cartDate">Oczekiwana data odpowiedzi:</label>
                                <input type="date" id="cartDate" class="form-control">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="cartComment">Komentarz (opcjonalnie):</label>
                        <textarea id="cartComment" class="form-control" rows="2"></textarea>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row mt-3" id="addItemRow" style="display:none">
        <div class="col-12">
            <div class="card">
                <div class="card-header"><h5>Dodaj artykuł</h5></div>
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

<script src="<?= asset('public_html/components/Admin/Purchase/Cart/cart-view.js') ?>"></script>
