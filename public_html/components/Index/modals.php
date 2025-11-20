<?php
$MsaDB = Atte\DB\MsaDB::getInstance();

// Priority options directly from the ENUM
$list__priority = [
    'none' => 'Brak',
    'standard' => 'Standardowy',
    'urgent' => 'Pilny',
    'critical' => 'Krytyczny'
];

$users_name = $MsaDB -> readIdName('user', 'user_id', 'name');
$users_surname = $MsaDB -> readIdName('user', 'user_id', 'surname');
$users_submag = $MsaDB -> readIdName('user', 'user_id', 'sub_magazine_id');
?>

<select name="" id="list__users" hidden>
    <!--users list-->
    <?php foreach ($users_name as $id => $user) {
        echo "<option data-submag=\"$users_submag[$id]\" value=\"$id\">" . $user . " " . $users_surname[$id] . "</option>";
    } ?>
</select>

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
                        <?php foreach ($list__priority as $key => $priority) {
                            echo "<option value=\"" . $key . "\">" . $priority . "</option>";
                        } ?>
                    </select>
                </div>
                <div class="input-group">
                    <div class="input-group-prepend">
                        <span class="input-group-text" id="">Subkontraktorzy: </span>
                    </div>
                    <select class="selectpicker" id='editSubcontractors' multiple
                            data-selected-text-format="count > 2"
                            data-actions-box="true">
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="editCommissionSubmit" class="btn btn-primary">Zmień</button>
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
                Czy na pewno chcesz anulować zlecenie?
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" id="cancelCommissionSubmit" class="btn btn-primary">Tak</button>
            </div>
        </div>
    </div>
</div>