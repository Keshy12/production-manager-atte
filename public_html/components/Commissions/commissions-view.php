<?php 
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;
include('modals.php');
include('commissions-card-template.php');

$MsaDB = MsaDB::getInstance();

$selectRenderer = new SelectRenderer($MsaDB);

$list__state = $MsaDB -> readIdName("commission__state");
$users_name = $MsaDB -> readIdName('user', 'user_id', 'name');
$users_surname = $MsaDB -> readIdName('user', 'user_id', 'surname');
$users_submag = $MsaDB -> readIdName('user', 'user_id', 'sub_magazine_id');
$currentUser = isset($_SESSION["userid"]) ? $_SESSION["userid"] : "";

$submag_list = $MsaDB -> readIdName("magazine__list", "sub_magazine_id", "sub_magazine_name", "ORDER BY type_id ASC");

?>

<select id="list__users" hidden><!--users list-->
    <?= $selectRenderer -> renderUserSelect() ?>
</select>
<select id="list__sku" hidden><!--sku list-->
    <?= $selectRenderer -> renderSKUBomSelect() ?>
</select>
<select id="list__tht" hidden><!--tht list-->
    <?= $selectRenderer -> renderTHTBomSelect() ?>
</select>
<select id="list__smd" hidden><!--smd list-->
    <?= $selectRenderer -> renderSMDBomSelect() ?>
</select>

<div class="d-flex flex-column justify-content-center align-items-center">
    <div class="card mt-4 p-2">
        <div class="d-flex justify-content-center">    
            <select id="transferFrom" data-title="Zlecenie z:" class="selectpicker mx-1">
                <?= $selectRenderer -> renderArraySelect($submag_list) ?>
            </select>     
            <select id="transferTo" data-title="Zlecenie do:" class="selectpicker mx-1">
                <?= $selectRenderer -> renderArraySelect($submag_list) ?>
            </select>
            <button type="button" id="clearMagazine" class="close" aria-label="Close">
                <span aria-hidden="true">×</span>
            </button>
        </div>
        <div class="d-flex justify-content-center mt-2">    
            <select id="type" data-width="auto" data-title="Typ:" class="selectpicker mx-1">
                <option value="sku">SKU</option>
                <option value="tht">THT</option>
                <option value="smd">SMD</option>
            </select>
            <select id="list__device" data-title="Urządzenie:" data-live-search="true" class="selectpicker mx-1" disabled></select>
            <div id="list__laminate" style="display: none;"><select id="laminate" data-width="auto" data-title="Lam:" class="selectpicker mx-1" disabled></select></div>
            <select id="version" data-width="auto" data-title="Ver:" class="selectpicker mx-1" disabled></select>
            <button type="button" id="clearDevice" class="close" aria-label="Close">
                <span aria-hidden="true">×</span>
            </button>
        </div>
        <div class="d-flex justify-content-center mt-2">    
            <select class="selectpicker" id="user" title="Zlecono dla:" multiple data-selected-text-format="count > 2" data-actions-box="true">
            <?php foreach($users_name as $id => $user){
                echo "<option data-submag=\"$users_submag[$id]\" value=\"$id\">".$user." ".$users_surname[$id]."</option>";
            } ?>
            </select>
        </div>
        <div class="d-flex justify-content-center mt-2">    
            <select class="selectpicker mx-1" id="state" title="Status:" data-selected-text-format="count > 1" multiple>
                <?= $selectRenderer -> renderArraySelect($list__state) ?>
            </select>
            <select class="selectpicker mx-1" id="priority" title="Priorytet:" data-selected-text-format="count > 1" multiple>
                <?= $selectRenderer -> renderArraySelect($list__priority) ?>
            </select>
        </div>
        <div class="d-flex justify-content-center mt-2">    
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="showCancelled">
                <label class="form-check-label" for="showCancelled">
                    Pokaż anulowane
                </label>
            </div>
        </div>
    </div>
    <div class="d-flex justify-content-center mt-4">
        <button id="previouspage" class="btn btn-light"><b>‹</b></button>
        <button id="currentpage" class="btn btn-light" disabled="">1</button>
        <button id="nextpage" class="btn btn-light"><b>›</b></button>
    </div>
    <div class="d-flex justify-content-center mt-2">
        <div style="display: none;" id="transferSpinner" class="spinner-border mt-2"></div>
    </div>
    <div class="w-75 d-flex justify-content-center mt-2">
        <div class="d-flex flex-wrap justify-content-center" id="container"></div>
    </div>
</div>

<script src="http://<?=BASEURL?>/public_html/components/commissions/commissions-view.js"></script>
