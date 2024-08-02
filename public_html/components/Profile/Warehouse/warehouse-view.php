<?php
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;
use Atte\Utils\UserRepository;
include('warehouse-table-item.php');

$MsaDB = MsaDB::getInstance();
$selectRenderer = new SelectRenderer($MsaDB);
$userRepository = new UserRepository($MsaDB);

$user = $userRepository -> getUserById($_SESSION["userid"]);
$userInfo = $user -> getUserInfo();
$userSubmagazineId = $userInfo["sub_magazine_id"];

$available__sku = $MsaDB -> query("SELECT sku_id FROM `inventory__sku` WHERE sub_magazine_id = $userSubmagazineId GROUP BY sku_id", PDO::FETCH_COLUMN);
$available__tht = $MsaDB -> query("SELECT tht_id FROM `inventory__tht` WHERE sub_magazine_id = $userSubmagazineId GROUP BY tht_id", PDO::FETCH_COLUMN);
$available__smd = $MsaDB -> query("SELECT smd_id FROM `inventory__smd` WHERE sub_magazine_id = $userSubmagazineId GROUP BY smd_id", PDO::FETCH_COLUMN);
$available__parts = $MsaDB -> query("SELECT parts_id FROM `inventory__parts` WHERE sub_magazine_id = $userSubmagazineId GROUP BY parts_id", PDO::FETCH_COLUMN);


?>
<select id="list__sku" hidden><!--sku list-->
    <?= $selectRenderer -> renderSKUSelect('', $available__sku) ?>
</select>
<select id="list__tht" hidden><!--tht list-->
    <?= $selectRenderer -> renderTHTSelect('', $available__tht) ?>
</select>
<select id="list__smd" hidden><!--smd list-->
    <?= $selectRenderer -> renderSMDSelect('', $available__smd) ?>
</select>
<select id="list__parts" hidden><!--parts list-->
    <?= $selectRenderer -> renderPartsSelect('', $available__parts) ?>
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

