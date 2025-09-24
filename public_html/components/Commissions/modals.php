<?php
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;

$MsaDB = MsaDB::getInstance();

$selectRenderer = new SelectRenderer($MsaDB);

$list__priority = array_reverse($MsaDB -> readIdName("commission__priority"), true);


?>

<style>
    .modal-overlay {
        position: absolute;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(255, 255, 255, 0.9);
        z-index: 1050;
    }

    .cancel-step {
        min-height: 200px;
    }

    .rollback-sources .input-group {
        margin-bottom: 0.5rem;
    }

    .rollback-sources .input-group-text {
        font-size: 0.875rem;
        text-overflow: ellipsis;
        overflow: hidden;
        white-space: nowrap;
    }

    .btn-group-vertical .btn {
        margin-bottom: 10px;
    }

    .btn-group-vertical .btn:last-child {
        margin-bottom: 0;
    }

    #transferAccordion .card {
        border: 1px solid #dee2e6;
    }

    #transferAccordion .card-header {
        background-color: #f8f9fa;
    }

    .table-responsive {
        max-height: 300px;
        overflow-y: auto;
    }

    .badge {
        font-size: 0.9em;
    }
</style>

<div class="modal fade" id="editCommissionModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edytuj zlecenie</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text" id="">Priorytet: </span>
                    </div>
                    <select class="selectpicker" id='editPriority'>
                        <?= $selectRenderer -> renderArraySelect($list__priority) ?>
                    </select>
                </div>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text" id="">Subkontraktorzy: </span>
                    </div>
                    <select class="selectpicker" id='editSubcontractors' multiple data-selected-text-format="count > 2" data-actions-box="true"></select>
                </div>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text" id="">Grupy: </span>
                    </div>
                    <select class="selectpicker" id='input-groups'>
                        <option style="display: none;" value="0">Wybierz grupę</option>

                    </select>
                </div>
            </div>
            <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
            <button type="button" id="editCommissionSubmit" class="btn btn-primary" >Zmień</button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="cancelCommissionModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Anuluj zlecenie</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <strong>Instrukcja:</strong>
                    <ul class="mb-0 mt-2">
                        <li>Kliknij nagłówek transferu aby go rozwinąć/zwinąć (domyślnie zwinięte)</li>
                        <li>Domyślnie zaznaczone jest tylko anulowane zlecenie i wszystkie jego komponenty</li>
                        <li>Zaznaczenie zlecenia automatycznie wybiera wszystkie jego komponenty</li>
                        <li>Kliknij "Transferowane komponenty" aby rozwinąć listę komponentów (domyślnie zwinięte)</li>
                        <li><strong>Uwaga:</strong> Zaznaczenie dowolnego komponentu ze zlecenia spowoduje anulowanie całego zlecenia</li>
                        <li><strong>Tylko rozszerzenie:</strong> Pozycje z czerwonym znacznikiem reprezentują część innego zlecenia - anulowanie ich komponentów NIE anuluje całego zlecenia</li>
                        <li><strong>Wymaga wszystkich rozszerzeń:</strong> Pozycje z niebieskim znacznikiem to zlecenia z rozszerzeniami - anulowanie nastąpi tylko jeśli wybierzesz wszystkie rozszerzenia tego zlecenia</li>
                    </ul>
                </div>

                <div id="groupsList">
                    <!-- Will be populated by JavaScript -->
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="confirmCancellation" class="btn btn-danger" disabled>
                    <i class="fas fa-check"></i> Potwierdź anulację
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Loading overlay for modal -->
<div id="cancelModalOverlay" class="modal-overlay" style="display: none;">
    <div class="d-flex align-items-center justify-content-center h-100">
        <div class="text-center">
            <div class="spinner-border text-primary" role="status"></div>
            <div class="mt-2">Ładowanie danych transferu...</div>
        </div>
    </div>
</div>