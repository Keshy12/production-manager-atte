<?php
use Atte\DB\MsaDB;
use Atte\Utils\UserRepository;
use Atte\Utils\ComponentRenderer\SelectRenderer;
include('warehouse-table-item.php');

$MsaDB = MsaDB::getInstance();
$selectRenderer = new SelectRenderer($MsaDB);

// $available is getting data from get-available-components-func.php
$available = [];
include('get-available-components-func.php');

$used__sku = $user -> getDevicesUsed("sku");
$used__tht = $user -> getDevicesUsed("tht"); 
$used__smd = $user -> getDevicesUsed("smd"); 


?>
<select id="list__sku" hidden><!--sku list-->
    <?= $selectRenderer -> renderSKUSelect('', $available['sku']) ?>
</select>
<select id="list__tht" hidden><!--tht list-->
    <?= $selectRenderer -> renderTHTSelect('', $available['tht']) ?>
</select>
<select id="list__smd" hidden><!--smd list-->
    <?= $selectRenderer -> renderSMDSelect('', $available['smd']) ?>
</select>
<select id="list__parts" hidden><!--parts list-->
    <?= $selectRenderer -> renderPartsSelect('', $available['parts']) ?>
</select>

<div class="d-flex flex-column align-items-center justify-content-center mt-4">
    <div class="d-flex w-75">
        <div class="btn-group" role="group">
            <button type="button" value="sku" class="magazineoption btn btn-outline-secondary">SKU</option>
            <button type="button" value="tht" class="magazineoption btn btn-outline-secondary">THT</option>
            <button type="button" value="smd" class="magazineoption btn btn-outline-secondary">SMD</option>
            <button type="button" value="parts" class="magazineoption btn btn-secondary">Parts</option>
        </div>
        <select id="magazinecomponent" 
                data-title="Typ:" 
                data-width="10%" 
                class="form-control selectpicker">
            <option value="sku">SKU</option>
            <option value="tht">THT</option>
            <option value="smd">SMD</option>
            <option value="parts" selected>Parts</option>
        </select>
        <select id="list__components" 
                data-title="Urządzenie:" 
                data-width="90%" data-live-search="true"
                data-actions-box="true" 
                data-selected-text-format="count > 3"
                class="form-control selectpicker" 
                multiple>
            <?= $selectRenderer -> renderPartsSelect($available__parts) ?>           
        </select>
    </div>
    <div class="d-flex justify-content-center mt-4">
        <button id="previouspage" class="btn btn-light" disabled><b>&lsaquo;</b></button>
        <button id="currentpage" class="btn btn-light" disabled>1</button>
        <button id="nextpage" class="btn btn-light" disabled><b>&rsaquo;</b></button>
    </div>
    <div class="w-75" id="container">
        <table class="table mt-4 table-striped text-center text-nowrap">
            <thead class="thead-light">
                <tr>
                    <th style="width: 70%" scope="col">Komponent</th>
                    <th style="width: 30%" scope="col">Ilość</th>
                </tr>
            </thead>
            <tbody id="warehouseTable">
            </tbody>
        </table>
    </div>
</div>

<script src="http://<?=BASEURL?>/public_html/components/profile/warehouse/warehouse-view.js"></script>

<

