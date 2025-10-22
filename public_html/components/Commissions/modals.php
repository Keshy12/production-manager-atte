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
                <!-- Priority Selection -->
                <div class="form-group">
                    <label for="editPriority" class="font-weight-bold">
                        <i class="bi bi-flag"></i> Priorytet:
                    </label>
                    <select class="selectpicker form-control" id="editPriority" data-width="100%">
                        <?= $selectRenderer->renderArraySelect($list__priority) ?>
                    </select>
                </div>

                <!-- Subcontractors Selection -->
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

                <!-- Groups Selection (if needed) -->
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
                    <i class="bi bi-exclamation-triangle"></i> Anuluj zlecenie
                </h5>
                <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body">
                <!-- Instructions Collapsible Card -->
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
                            <!-- How it works -->
                            <h6 class="font-weight-bold mb-2">
                                <i class="bi bi-gear"></i> Jak działa anulacja:
                            </h6>
                            <ul class="mb-3">
                                <li><strong>Grupy transferów (Transfer #X):</strong> Każda grupa reprezentuje jeden transfer komponentów wykonany w konkretnym momencie. Kliknij nagłówek aby rozwinąć/zwinąć grupę.</li>
                                <li><strong>Zaznaczanie zleceń:</strong> Zaznaczenie zlecenia automatycznie wybiera wszystkie jego komponenty w danej grupie.</li>
                                <li><strong>Zaznaczanie komponentów:</strong> Możesz ręcznie zaznaczać pojedyncze komponenty niezależnie od zlecenia.</li>
                                <li><strong>Komponenty w wielu grupach:</strong> To samo zlecenie może pojawić się w kilku grupach, jeśli było rozszerzane. Każda grupa to osobny transfer komponentów.</li>
                            </ul>

                            <!-- Cancellation types -->
                            <div class="alert alert-light border">
                                <h6 class="font-weight-bold mb-3">
                                    <i class="bi bi-tags"></i> Typy anulacji:
                                </h6>

                                <div class="p-2 mb-2 bg-danger-light border-left border-danger border-3">
                                    <strong class="text-danger">
                                        <i class="bi bi-x-circle"></i> Całe zlecenie zostanie anulowane
                                    </strong>
                                    <br>
                                    <small class="text-muted">
                                        Wszystkie instancje tego zlecenia są wybrane. Zlecenie zostanie całkowicie anulowane w systemie.
                                    </small>
                                </div>

                                <div class="p-2 mb-2 border-left border-info border-3">
                                    <span class="badge badge-info">Wymaga wszystkich rozszerzeń</span>
                                    <br>
                                    <small class="text-muted">
                                        To zlecenie ma rozszerzenia w innych grupach. Aby je całkowicie anulować, musisz wybrać wszystkie jego części.
                                    </small>
                                </div>

                                <div class="p-2 border-left border-danger border-3">
                                    <span class="badge badge-danger">Tylko rozszerzenie</span>
                                    <br>
                                    <small class="text-muted">
                                        To jest rozszerzenie innego zlecenia. Anulowanie jego komponentów NIE anuluje głównego zlecenia.
                                    </small>
                                </div>
                            </div>

                            <!-- Process steps -->
                            <h6 class="font-weight-bold mb-2">
                                <i class="bi bi-list-ol"></i> Proces anulacji:
                            </h6>
                            <ol class="mb-3">
                                <li>Wybierz zlecenia lub komponenty do anulacji</li>
                                <li>Sprawdź podsumowanie - co zostanie anulowane i jakie transfery zostaną cofnięte</li>
                                <li>System automatycznie obliczy ilość komponentów do zwrotu:
                                    <ul class="mt-1">
                                        <li><strong>Przetransferowano:</strong> Suma zaznaczonych komponentów</li>
                                        <li><strong>Użyto:</strong> Ilość wykorzystana w produkcji (na podstawie wyprodukowanej ilości)</li>
                                        <li><strong>Do zwrotu:</strong> Różnica (przetransferowano - użyto)</li>
                                    </ul>
                                </li>
                                <li>Potwierdź anulację - operacja jest nieodwracalna</li>
                                <li>Niewykorzystane komponenty wrócą do magazynów źródłowych</li>
                            </ol>

                            <!-- Important warnings -->
                            <div class="alert alert-danger mb-2">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <strong>Ważne:</strong>
                                Jeśli ilość do zwrotu jest <span class="badge badge-danger">ujemna (czerwona)</span>,
                                oznacza to że użyto więcej komponentów niż przetransferowano. W takim przypadku
                                anulacja zostanie zablokowana. Może to wskazywać na błąd w danych lub konieczność korekty.
                            </div>

                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-info-circle-fill"></i>
                                <strong>Uwaga:</strong>
                                Anulacja jest operacją nieodwracalną. Wybrane transfery zostaną cofnięte do magazynów źródłowych.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Transfer Groups List -->
                <div id="groupsList">
                    <!-- Will be populated by JavaScript -->
                </div>

                <!-- Cancellation Summary -->
                <div id="cancellationSummary" class="card border-danger mt-3" style="display: none;">
                    <div class="card-header bg-danger text-white">
                        <strong>
                            <i class="bi bi-clipboard-check"></i> Podsumowanie anulacji
                        </strong>
                    </div>
                    <div class="card-body" id="summaryContent">
                        <!-- Content will be dynamically populated -->
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

<!-- Loading Overlay for Cancel Modal -->
<div id="cancelModalOverlay"
     class="position-fixed w-100 h-100 d-flex align-items-center justify-content-center bg-white"
     style="top: 0; left: 0; z-index: 1050; opacity: 0.95; display: none !important;">
    <div class="text-center">
        <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
            <span class="sr-only">Ładowanie...</span>
        </div>
        <h5 class="text-primary">Ładowanie danych transferu...</h5>
    </div>
</div>

<!-- Custom styles for badge borders (minimal CSS for functionality) -->
<style>
    .border-3 {
        border-width: 3px !important;
    }

    .bg-danger-light {
        background-color: #ffcdcd !important;
    }

    .border-danger.bg-light-danger {
        background-color: #ffe5e5 !important;
    }

    /* Chevron rotation on collapse */
    [data-toggle="collapse"] .bi-chevron-down {
        transition: transform 0.2s ease;
    }

    [data-toggle="collapse"][aria-expanded="true"] .bi-chevron-down {
        transform: rotate(180deg);
    }

    /* Make collapse headers hoverable */
    [data-toggle="collapse"]:hover {
        background-color: rgba(0, 0, 0, 0.02);
    }

    /* Loading overlay z-index fix */
    #cancelModalOverlay[style*="display: block"],
    #cancelModalOverlay[style*="display:block"] {
        display: flex !important;
    }
</style>