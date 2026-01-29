<?php
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;

$MsaDB = MsaDB::getInstance();
$selectRenderer = new SelectRenderer($MsaDB);
?>

<!-- Edit Commission Modal -->
<div class="modal fade" id="editCommissionModal" tabindex="-1" role="dialog" aria-labelledby="editCommissionModalLabel" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editCommissionModalLabel">
                    <i class="bi bi-pencil"></i> Edytuj zlecenie
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label for="editPriority" class="font-weight-bold">
                        <i class="bi bi-flag"></i> Priorytet:
                    </label>
                    <select class="selectpicker form-control" id="editPriority" data-width="100%">
                        <?= $selectRenderer->renderArraySelect($list__priority) ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="editSubcontractors" class="font-weight-bold">
                        <i class="bi bi-people"></i> Zleceniobiorcy:
                    </label>
                    <select class="selectpicker form-control"
                            id="editSubcontractors"
                            multiple
                            data-selected-text-format="count > 2"
                            data-actions-box="true"
                            data-width="100%">
                    </select>
                </div>

                <div class="form-group">
                    <label for="input-groups" class="font-weight-bold">
                        <i class="bi bi-collection"></i> Grupy:
                    </label>
                    <select class="selectpicker form-control" id="input-groups" data-width="100%">
                        <option value="0" style="display: none;">Wybierz grupę</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="bi bi-x"></i> Anuluj
                </button>
                <button type="button" id="editCommissionSubmit" class="btn btn-primary">
                    <i class="bi bi-check"></i> Zmień
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Cancel Commission Modal -->
<div class="modal fade" id="cancelCommissionModal" tabindex="-1" role="dialog" aria-labelledby="cancelCommissionModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="cancelCommissionModalLabel">
                    <i class="bi bi-exclamation-triangle"></i> Anulacja zlecenia
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body">
                <div id="selectionView">
                    <!-- Instructions Card -->
                    <div class="card border-info mb-3">
                        <div class="card-header bg-info text-white py-2"
                             style="cursor: pointer;"
                             data-toggle="collapse"
                             data-target="#instructionsCollapse"
                             aria-expanded="false"
                             aria-controls="instructionsCollapse">
                            <div class="d-flex align-items-center justify-content-between">
                                <strong>
                                    <i class="bi bi-info-circle"></i> Instrukcja obsługi
                                </strong>
                                <i class="bi bi-chevron-down"></i>
                            </div>
                        </div>
                        <div id="instructionsCollapse" class="collapse">
                            <div class="card-body">
                                <h6 class="font-weight-bold mb-3">
                                    <i class="bi bi-1-circle"></i> Cel operacji
                                </h6>
                                <p class="mb-3">To narzędzie służy do wycofywania zleceń produkcyjnych oraz powiązanych z nimi transferów komponentów. Domyślnie system zaznacza wszystkie elementy w wybranym zakresie (pojedyncze zlecenie lub grupa).</p>

                                <h6 class="font-weight-bold mb-3">
                                    <i class="bi bi-2-circle"></i> Co się stanie po potwierdzeniu?
                                </h6>
                                <ul class="mb-3">
                                    <li><strong>Anulacja zlecenia:</strong> Zlecenie zmieni status na "Anulowane".</li>
                                    <li><strong>Zwrot komponentów:</strong> Przetransferowane komponenty (pomniejszone o te już zużyte w produkcji) zostaną automatycznie zwrócone do magazynów, z których zostały pobrane.</li>
                                </ul>

                                <h6 class="font-weight-bold mb-3">
                                    <i class="bi bi-3-circle"></i> Granularność (opcjonalnie)
                                </h6>
                                <ul class="mb-3">
                                    <li>Możesz odznaczyć konkretne zlecenia lub poszczególne transfery, jeśli chcesz wycofać tylko część operacji.</li>
                                    <li>Dla komponentów pobranych z wielu źródeł, możesz użyć przycisków <kbd>+</kbd> i <kbd>-</kbd> aby dostosować, do których magazynów mają wrócić sztuki.</li>
                                </ul>

                                <div class="alert alert-danger">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    <strong>Ważne:</strong> Operacja jest nieodwracalna. System automatycznie wylicza ilości do zwrotu na podstawie aktualnego stanu produkcji. Sprawdź dokładnie podsumowanie na dole przed kliknięciem "Potwierdź anulację".
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Transfer Groups List -->
                    <div id="groupsList">
                        <!-- Populated by JavaScript -->
                    </div>
                </div>

                <!-- Cancellation Summary View -->
                <div id="summaryView" style="display: none;">
                    <div id="cancellationSummary" class="card border-secondary">
                        <div class="card-header bg-light text-danger">
                            <strong>
                                <i class="bi bi-clipboard-check"></i> Podsumowanie anulacji
                            </strong>
                        </div>
                        <div class="card-body" id="summaryContent">
                            <!-- Populated by JavaScript -->
                        </div>
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-dismiss="modal" id="cancelCloseBtn">
                    <i class="bi bi-x"></i> Zamknij
                </button>
                <button type="button" class="btn btn-secondary" id="backToSelection" style="display: none;">
                    <i class="bi bi-arrow-left"></i> Wróć do wyboru
                </button>
                <button type="button" id="nextToSummary" class="btn btn-primary" disabled>
                    Dalej: Podsumowanie <i class="bi bi-arrow-right"></i>
                </button>
                <button type="button" id="confirmCancellation" class="btn btn-danger" style="display: none;">
                    <i class="bi bi-check-circle"></i> Potwierdź anulację
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div class="modal fade" id="commissionDetailsModal" tabindex="-1" role="dialog" aria-labelledby="commissionDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="commissionDetailsModalLabel">
                    <i class="bi bi-info-circle"></i> Szczegóły zlecenia i transferów
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body" id="detailsModalBody">
                <!-- Populated by JavaScript -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="bi bi-x"></i> Zamknij
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Loading Overlay -->
<div id="cancelModalOverlay"
     class="position-fixed w-100 h-100 d-flex align-items-center justify-content-center bg-white"
     style="top: 0; left: 0; z-index: 1060; opacity: 0.95; display: none !important;">
    <div class="text-center">
        <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
            <span class="sr-only">Ładowanie...</span>
        </div>
        <h5 class="text-primary">Ładowanie danych anulacji...</h5>
    </div>
</div>

<style>
    .border-3 {
        border-width: 3px !important;
    }

    [data-toggle="collapse"] .bi-chevron-down {
        transition: transform 0.2s ease;
    }

    [data-toggle="collapse"][aria-expanded="true"] .bi-chevron-down {
        transform: rotate(180deg);
    }

    [data-toggle="collapse"]:hover {
        background-color: rgba(0, 0, 0, 0.02);
    }

    #cancelModalOverlay[style*="display: block"],
    #cancelModalOverlay[style*="display:block"] {
        display: flex !important;
    }

    .source-qty-input::-webkit-outer-spin-button,
    .source-qty-input::-webkit-inner-spin-button {
        -webkit-appearance: none;
        margin: 0;
    }

    .source-qty-input[type=number] {
        -moz-appearance: textfield;
    }

    .transfers-list {
        background-color: #f8f9fa;
        border-left: 3px solid #dee2e6;
        padding: 10px;
    }

    .sources-distribution {
        background-color: #fff;
        border: 1px solid #dee2e6;
        border-radius: 4px;
        padding: 10px;
        margin-top: 5px;
        margin-bottom: 10px;
    }

    .sources-distribution small {
        font-size: 0.85rem;
    }

    .sources-distribution .input-group {
        width: 140px !important;
    }

    .sources-distribution .btn {
        padding: 0.25rem 0.5rem;
        font-size: 0.875rem;
    }

    .sources-distribution .form-control {
        font-size: 0.875rem;
        padding: 0.25rem 0.5rem;

    }

    .main-source-container {
        background-color: #e3f2fd;
        border: 2px solid rgba(33, 150, 243, 0.16);
        border-radius: 4px;
        padding: 8px;
        margin-bottom: 8px;
    }

    .external-source-container {
        background-color: #fff;
        border: 1px solid #dee2e6;
        border-radius: 4px;
        padding: 8px;
    }

    /* Commission card chevron animation */
    .collapse-icon {
        transition: transform 0.2s ease;
    }

    [aria-expanded="true"] .collapse-icon.bi-chevron-right {
        transform: rotate(90deg);
    }

    /* Warehouse grouping styles */
    .warehouse-header-row {
        background-color: #e9ecef !important;
        font-weight: 600;
    }

    .warehouse-header-row:hover {
        background-color: #dee2e6 !important;
    }

    .warehouse-component-row {
        background-color: #ffffff;
    }

    .warehouse-component-row td:first-child {
        padding-left: 2rem; /* Indent component names */
    }

    .warehouse-chevron {
        transition: transform 0.2s ease;
        display: inline-block;
    }

    .warehouse-header-row[aria-expanded="true"] .warehouse-chevron {
        transform: rotate(90deg);
    }
</style>