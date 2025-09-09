<?php
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;

$MsaDB = MsaDB::getInstance();

$selectRenderer = new SelectRenderer($MsaDB);

$list__priority = array_reverse($MsaDB -> readIdName("commission__priority"), true);


?>

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
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form id="cancelCommissionForm">
                <input type="hidden" id="cancel_commission_id">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-exclamation-triangle"></i> Anuluj Zlecenie
                    </h5>
                    <button type="button" class="close" data-dismiss="modal">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <p><strong>Czy na pewno chcesz anulować to zlecenie?</strong></p>

                    <!-- Commission Details Section -->
                    <div class="mb-4">
                        <h6><i class="bi bi-info-circle"></i> Szczegóły Zlecenia</h6>
                        <div id="commission_details_display" class="mb-3 p-2 border rounded bg-light">
                            <!-- Commission details will be loaded here -->
                        </div>
                    </div>

                    <!-- Unreturned Products Section -->
                    <div class="mb-4" id="unreturned_products_section" style="display: none;">
                        <h6><i class="bi bi-box-arrow-right"></i> Niewrócone Produkty</h6>
                        <div id="unreturned_products_display" class="mb-3 p-2 border rounded bg-light">
                            <!-- Unreturned products info will be loaded here -->
                        </div>

                        <div class="form-group">
                            <label><strong>Co zrobić z niewróconymi produktami?</strong></label>

                            <div class="form-check mt-2">
                                <input class="form-check-input" type="radio" name="unreturnedOption"
                                       id="unreturnedTransfer" value="transfer" checked>
                                <label class="form-check-label" for="unreturnedTransfer">
                                    <strong>Przenieś</strong> do magazynu docelowego
                                    <small class="text-muted d-block">Automatycznie przenieś produkty z magazynu podwykonawcy do magazynu docelowego</small>
                                </label>
                            </div>

                            <div class="form-check mt-2">
                                <input class="form-check-input" type="radio" name="unreturnedOption"
                                       id="unreturnedKeep" value="keep">
                                <label class="form-check-label" for="unreturnedKeep">
                                    <strong>Zostaw</strong> w magazynie podwykonawcy
                                    <small class="text-muted d-block">Produkty pozostaną w magazynie podwykonawcy</small>
                                </label>
                            </div>

                            <div class="form-check mt-2">
                                <input class="form-check-input" type="radio" name="unreturnedOption"
                                       id="unreturnedRemove" value="remove">
                                <label class="form-check-label" for="unreturnedRemove">
                                    <strong>Usuń</strong> produkty
                                    <small class="text-muted d-block">Usuń produkty z magazynu podwykonawcy (nieodwracalne)</small>
                                </label>
                            </div>
                        </div>

                        <!-- Warning for remove option -->
                        <div id="unreturned_remove_warning" class="alert alert-warning mt-2" style="display: none;">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Uwaga!</strong> Usunięcie produktów jest nieodwracalne.
                        </div>
                    </div>

                    <!-- Inventory Transfer Section -->
                    <div class="mb-4">
                        <h6><i class="bi bi-box-seam"></i> Przeniesione Komponenty</h6>
                        <div id="transferred_items_display" class="mb-3 p-2 border rounded bg-light">
                            <div class="text-center">
                                <i class="bi bi-hourglass-split"></i> Ładowanie przedmiotów...
                            </div>
                        </div>

                        <div class="form-group">
                            <label><strong>Co zrobić z pozostałymi komponentami?</strong></label>

                            <div class="form-check mt-2">
                                <input class="form-check-input" type="radio" name="rollbackOption"
                                       id="rollbackNone" value="none" checked>
                                <label class="form-check-label" for="rollbackNone">
                                    <strong>Nie rób</strong> nic więcej
                                    <small class="text-muted d-block">Tylko anuluj zlecenie, pozostaw przedmioty w magazynie docelowym</small>
                                </label>
                            </div>

                            <div class="form-check mt-2">
                                <input class="form-check-input" type="radio" name="rollbackOption"
                                       id="rollbackRemaining" value="remaining">
                                <label class="form-check-label" for="rollbackRemaining">
                                    <strong>Cofnij wszystkie pozostałe</strong> przedmioty
                                    <small class="text-muted d-block">Przenieś z powrotem wszystkie pozostałe przedmioty do magazynu źródłowego</small>
                                </label>
                            </div>

                            <div class="form-check mt-2">
                                <input class="form-check-input" type="radio" name="rollbackOption"
                                       id="deleteRemaining" value="delete">
                                <label class="form-check-label" for="deleteRemaining">
                                    <strong>Usuń wszystkie pozostałe</strong> przedmioty
                                    <small class="text-muted d-block">Usuń wszystkie pozostałe przedmioty z magazynu (nie cofaj do magazynu źródłowego)</small>
                                </label>
                            </div>

                            <!-- Rollback Details (shown when rollback is selected) -->
                            <div id="rollback_details" class="mt-3" style="display: none;">
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i>
                                    <strong>Szczegóły operacji:</strong>
                                    <div id="rollback_summary"></div>
                                </div>
                            </div>

                            <!-- Warning for delete option -->
                            <div id="delete_warning" class="alert alert-warning mt-2" style="display: none;">
                                <i class="bi bi-exclamation-triangle"></i>
                                <strong>Uwaga!</strong> Usunięcie przedmiotów jest nieodwracalne.
                                Upewnij się, że to właściwa akcja.
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">
                        <i class="bi bi-x-circle"></i> Anuluj
                    </button>
                    <button type="submit" class="btn btn-danger" id="cancelCommissionSubmit">
                        <i class="bi bi-check-circle"></i> Anuluj Zlecenie
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>