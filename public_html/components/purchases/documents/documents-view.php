<?php
/**
 * Combined RFQ + PO table at /purchase/documents.
 *
 * Mirrors /archive's filter-card markup + AJAX lifecycle so the operator's
 * mental model carries over (collapsible alert-primary header, per-row red
 * Wyczyść, date range, Odśwież). The data is fetched from
 * documents-table.php; this file only renders the filter UI + empty table
 * skeleton + pagination containers.
 *
 * Filter set (matches documents-table.php exactly):
 *   Typ dokumentu (single: both/rfq/po) — default both
 *   Dostawca (multi, list__vendor.isActive = 1)
 *   Status (multi, 8 unique state labels covering RFQ + PO enums)
 *   Numer dokumentu (text — partial LIKE on rfq_number / po_number /
 *                                vendor_po_number)
 *   Data utworzenia od/do (date range; JS sets dateFrom to today-90d on
 *                            first paint so the page never lands empty)
 *
 * Row action lives in documents-view.js renderRow() — single endpoint,
 * label + icon flip on the editable flag (Edytuj vs Podgląd → both go
 * to /admin/purchase/documents/edit?id=N&type=…).
 */
use Atte\DB\MsaDB;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();

// Active vendors for the Dostawca selectpicker. Same query shape as
// cart-view.php (active-only, sorted by name).
$activeVendors = $MsaDB->query(
    "SELECT id, name
       FROM `list__vendor`
      WHERE isActive = 1
      ORDER BY name ASC"
);
?>

<div class="container-fluid w-75 mt-3">
    <div class="row">
        <div class="col-12 my-2">
            <h2><i class="bi bi-file-earmark-text"></i> Zapytania i zamówienia</h2>
            <p class="text-muted">
                Wspólna tabela zapytań ofertowych (RFQ) i zamówień (PO).
                Domyślnie wyświetlone są dokumenty z ostatnich 90 dni
                (ze względu na optymalizację) — zakres zmienisz w filtrach.
            </p>
            <div id="alertContainer"></div>
        </div>
    </div>

    <!-- ===== Filter card (collapsible, mirrors /archive) ===== -->
    <div class="card mt-3 mb-3">
        <div class="card-header alert-primary" style="cursor: pointer;" data-toggle="collapse" data-target="#filterCollapse">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-funnel"></i> Filtry</h6>
                <i class="bi bi-chevron-down"></i>
            </div>
        </div>
        <div id="filterCollapse" class="collapse alert-info">
            <div class="card-body p-3">

                <!-- Row 1: Typ dokumentu -->
                <div class="form-row mb-2">
                    <div class="col-md-10 col-12 mb-2">
                        <label class="small mb-1" for="type"><strong>Typ dokumentu:</strong></label>
                        <select id="type" class="selectpicker form-control form-control-sm" data-width="100%">
                            <option value="both" selected>Oba (RFQ + PO)</option>
                            <option value="rfq">Tylko zapytania (RFQ)</option>
                            <option value="po">Tylko zamówienia (PO)</option>
                        </select>
                    </div>
                    <div class="col-md-2 col-12 d-flex align-items-end">
                        <button type="button" id="clearType" class="btn btn-danger btn-sm btn-block mb-2">Wyczyść</button>
                    </div>
                </div>

                <hr class="my-2">

                <!-- Row 2: Dostawca (multi) -->
                <div class="form-row mb-2">
                    <div class="col-md-10 col-12 mb-2">
                        <label class="small mb-1" for="vendor"><strong>Dostawca:</strong></label>
                        <select id="vendor"
                                class="selectpicker form-control form-control-sm"
                                data-live-search="true"
                                data-actions-box="true"
                                data-selected-text-format="count > 2"
                                data-title="Wybierz dostawców…"
                                multiple
                                data-width="100%">
                            <?php foreach ($activeVendors as $v): ?>
                                <option value="<?= (int)$v['id'] ?>">
                                    <?= htmlspecialchars($v['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 col-12 d-flex align-items-end">
                        <button type="button" id="clearVendor" class="btn btn-danger btn-sm btn-block mb-2">Wyczyść</button>
                    </div>
                </div>

                <hr class="my-2">

                <!-- Row 3: Status (multi, 8 unique combined states) -->
                <div class="form-row mb-2">
                    <div class="col-md-10 col-12 mb-2">
                        <label class="small mb-1" for="states"><strong>Status:</strong></label>
                        <select id="states"
                                class="selectpicker form-control form-control-sm"
                                data-actions-box="true"
                                data-selected-text-format="count > 2"
                                data-title="Wybierz statusy…"
                                multiple
                                data-width="100%">
                            <optgroup label="Wspólne (RFQ + PO)">
                                <option value="draft">Szkic</option>
                                <option value="sent">Wysłane</option>
                                <option value="cancelled">Anulowane</option>
                            </optgroup>
                            <optgroup label="Zapytanie (RFQ)">
                                <option value="responded">Odpowiedź</option>
                                <option value="converted">Przekonwertowane</option>
                            </optgroup>
                            <optgroup label="Zamówienie (PO)">
                                <option value="confirmed">Potwierdzone</option>
                                <option value="partially_received">Częściowo odebrane</option>
                                <option value="received">Odebrane</option>
                            </optgroup>
                        </select>
                    </div>
                    <div class="col-md-2 col-12 d-flex align-items-end">
                        <button type="button" id="clearStates" class="btn btn-danger btn-sm btn-block mb-2">Wyczyść</button>
                    </div>
                </div>

                <hr class="my-2">

                <!-- Row 4: Numer dokumentu (text) -->
                <div class="form-row mb-2">
                    <div class="col-md-10 col-12 mb-2">
                        <label class="small mb-1" for="number"><strong>Numer dokumentu:</strong></label>
                        <input type="text" id="number" class="form-control form-control-sm"
                               placeholder="np. 2025/01, RFQ-y/...">
                    </div>
                    <div class="col-md-2 col-12 d-flex align-items-end">
                        <button type="button" id="clearNumber" class="btn btn-danger btn-sm btn-block mb-2">Wyczyść</button>
                    </div>
                </div>

                <hr class="my-2">

                <!-- Row 5: Data utworzenia -->
                <div class="form-row mb-2">
                    <div class="col-md-5 col-12 mb-2">
                        <label class="small mb-1" for="dateFrom"><strong>Data od:</strong></label>
                        <input type="date" id="dateFrom" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-5 col-12 mb-2">
                        <label class="small mb-1" for="dateTo"><strong>Data do:</strong></label>
                        <input type="date" id="dateTo" class="form-control form-control-sm">
                    </div>
                    <div class="col-md-2 col-12 d-flex align-items-end">
                        <button type="button" id="clearDates" class="btn btn-danger btn-sm btn-block mb-2">Wyczyść daty</button>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Pagination top -->
    <div id="paginationTop" class="mb-3"></div>

    <!-- Spinner -->
    <div class="d-flex justify-content-center mb-3">
        <div style="display: none;" id="docsSpinner" class="spinner-border text-primary" role="status">
            <span class="sr-only">Ładowanie...</span>
        </div>
    </div>

    <!-- Quick controls (mirror /archive's "Szybkie sterowanie" card).
         Footer carries both the info text and the Odśwież button on a
         single row (justified between) so the button isn't stranded on
         its own line above the hint. -->
    <div class="card mb-3">
        <div class="card-footer py-1 small text-muted d-flex align-items-center justify-content-between">
            <div>
                <i class="bi bi-info-circle"></i> Domyślnie wyświetlone dokumenty z ostatnich 90 dni. Aby zobaczyć starsze, wyczyść daty w filtrach.
            </div>
            <button type="button" id="refreshDocs" class="btn btn-primary btn-sm">
                <i class="bi bi-arrow-clockwise"></i> Odśwież
            </button>
        </div>
    </div>

    <!-- Documents table (8 cells — Number, Type, Vendor, State, Items, Created, Value, Action) -->
    <div class="d-flex justify-content-center">
        <table class="table w-100" id="docsTable">
            <thead class="thead-light">
                <tr>
                    <th scope="col" class="text-center" style="width: 70px;">Typ</th>
                    <th scope="col">Numer</th>
                    <th scope="col">Dostawca</th>
                    <th scope="col" class="text-right">Pozycje</th>
                    <th scope="col">Utworzony</th>
                    <th scope="col" class="text-right">Wartość</th>
                    <th scope="col">Status</th>
                    <th scope="col" class="text-right" style="width: 130px;">Akcja</th>
                </tr>
            </thead>
            <tbody id="docsTableBody">
                <tr>
                    <td colspan="8" class="text-center text-muted">
                        Ładowanie dokumentów…
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <!-- Pagination bottom -->
    <div id="paginationBottom" class="mt-3"></div>
</div>

<script src="<?= asset('public_html/components/purchases/documents/documents-view.js') ?>"></script>
