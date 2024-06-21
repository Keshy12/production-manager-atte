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
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Usuń zlecenie</h5>
                <button type="button" class="close" data-dismiss="modal">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                Czy na pewno chcesz usunąć zlecenie?
            </div>
            <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
            <button type="button" id="cancelCommissionSubmit" class="btn btn-primary" >Tak</button>
            </div>
        </div>
    </div>
</div>