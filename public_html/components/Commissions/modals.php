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
                                <i class="bi bi-1-circle"></i> Struktura anulacji
                            </h6>
                            <p class="mb-3">System wyświetla zlecenia pogrupowane według <strong>transferów</strong>. Każda grupa transferu reprezentuje jeden moment w czasie, kiedy komponenty zostały przetransferowane.</p>

                            <div class="alert alert-light border mb-3">
                                <strong>Elementy do zaznaczenia:</strong>
                                <ul class="mb-0 mt-2">
                                    <li><strong>Zlecenia</strong> - zaznaczenie anuluje całe zlecenie w systemie</li>
                                    <li><strong>Transfery komponentów</strong> - zaznaczenie zwraca komponenty do magazynów źródłowych</li>
                                </ul>
                            </div>

                            <h6 class="font-weight-bold mb-3">
                                <i class="bi bi-2-circle"></i> Logika zaznaczania
                            </h6>
                            <ul class="mb-3">
                                <li><strong>Zaznaczenie zlecenia</strong> automatycznie zaznacza wszystkie jego komponenty w tej grupie transferu</li>
                                <li><strong>Zaznaczenie komponentu</strong> nie zaznacza zlecenia - możesz zwrócić komponenty bez anulowania zlecenia</li>
                                <li>Możesz ręcznie odznaczać i zaznaczać dowolne elementy według potrzeb</li>
                            </ul>

                            <h6 class="font-weight-bold mb-3">
                                <i class="bi bi-3-circle"></i> Obliczanie ilości do zwrotu
                            </h6>
                            <div class="bg-light p-3 rounded mb-3">
                                <code>Ilość do zwrotu = Przetransferowano - (Wyprodukowano × Komponenty na jednostkę)</code>
                            </div>
                            <p class="mb-3">System automatycznie odejmuje komponenty użyte w produkcji od ilości przetransferowanej.</p>

                            <h6 class="font-weight-bold mb-3">
                                <i class="bi bi-4-circle"></i> Rozkład zwrotu między źródła
                            </h6>
                            <p class="mb-2">Gdy komponenty pochodzą z wielu magazynów, możesz kontrolować rozkład zwrotu:</p>
                            <ul class="mb-3">
                                <li>Domyślnie: zwrot priorytetyzuje magazyny zewnętrzne</li>
                                <li>Użyj przycisków <kbd>+</kbd> i <kbd>-</kbd> aby ręcznie dostosować ilości</li>
                                <li>System automatycznie przelicza pozostałe magazyny</li>
                            </ul>

                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <strong>Ważne ostrzeżenia:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>Jeśli <span class="badge badge-danger">ilość do zwrotu jest ujemna</span>, oznacza to że użyto więcej komponentów niż przetransferowano. Takie transfery są zablokowane do anulacji.</li>
                                    <li>Anulacja jest operacją <strong>nieodwracalną</strong></li>
                                    <li>Komponenty zostaną zwrócone do magazynów źródłowych według wybranego rozkładu</li>
                                </ul>
                            </div>

                            <h6 class="font-weight-bold mb-3 mt-3">
                                <i class="bi bi-5-circle"></i> Proces anulacji krok po kroku
                            </h6>
                            <ol class="mb-0">
                                <li>Wybierz zlecenia i/lub komponenty do anulacji</li>
                                <li>Dla komponentów z wieloma źródłami - dostosuj rozkład zwrotu (opcjonalnie)</li>
                                <li>Sprawdź podsumowanie anulacji</li>
                                <li>Potwierdź operację przyciskiem "Potwierdź anulację"</li>
                                <li>System automatycznie wykona wszystkie operacje magazynowe</li>
                            </ol>
                        </div>
                    </div>
                </div>

                <!-- Transfer Groups List -->
                <div id="groupsList">
                    <!-- Populated by JavaScript -->
                </div>

                <!-- Cancellation Summary -->
                <div id="cancellationSummary" class="card border-danger mt-3" style="display: none;">
                    <div class="card-header bg-danger text-white">
                        <strong>
                            <i class="bi bi-clipboard-check"></i> Podsumowanie anulacji
                        </strong>
                    </div>
                    <div class="card-body" id="summaryContent">
                        <!-- Populated by JavaScript -->
                    </div>
                </div>
            </div>

            <div class="modal-footer bg-light">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">
                    <i class="bi bi-x"></i> Zamknij
                </button>
                <button type="button" id="confirmCancellation" class="btn btn-danger" disabled>
                    <i class="bi bi-check-circle"></i> Potwierdź anulację
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
</style>