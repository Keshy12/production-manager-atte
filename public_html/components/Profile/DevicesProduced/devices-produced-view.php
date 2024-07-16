<?php
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;
use Atte\Utils\UserRepository;
include('modals.php');

$MsaDB = MsaDB::getInstance();
$selectRenderer = new SelectRenderer($MsaDB);
$userRepository = new UserRepository($MsaDB);
$user = $userRepository ->getUserById($_SESSION["userid"]);

$thtUsed = $user -> getDevicesUsed("tht");
$smdUsed = $user -> getDevicesUsed("smd");


$list__tht = $MsaDB -> readIdName("list__tht");
$list__tht_desc = $MsaDB -> readIdName("list__tht", "id", "description");
$list__smd = $MsaDB -> readIdName("list__smd");
$list__smd_desc = $MsaDB -> readIdName("list__smd", "id", "description");

$thtUnused = array_diff(array_keys($list__tht), $thtUsed);
$smdUnused = array_diff(array_keys($list__smd), $smdUsed);

?>

<div class="d-flex justify-content-center"><div id="ajaxResult" class="mt-4 position-fixed" style="z-index: 100;"></div></div>
<div class="container d-flex align-items-center justify-content-center">
    <div class="w-75 d-flex align-items-start justify-content-around">
        <div style="width: 48%">
            <select id="list__tht" 
                    data-title="Urządzenie THT:" 
                    data-live-search="true"
                    data-actions-box="true" 
                    data-selected-text-format="count > 3"
                    class="form-control selectpicker mt-4" 
                    multiple>
                <?= $selectRenderer -> renderTHTSelect($thtUnused) ?>
            </select>
            <div class="text-center">
                <button id="addTHT" class="btn btn-primary mt-2">Dodaj</button>
            </div>
            <div class="card mt-2">
                <div class="card-body">
                    <h5 class="text-center">Produkowane THT</h5>
                    <hr>
                    <span id="thtUsed">
                        <?php foreach($thtUsed as $thtId) { ?>
                            <div class="tht-<?=$thtId?> mt-3">
                                <b><?=$list__tht[$thtId]?></b>
                                <button type="button" data-type="tht" data-id="<?=$thtId?>" class="close removeDevice" aria-label="Close">
                                    <span aria-hidden="true">×</span>
                                </button>
                                <br>
                                <small><?=$list__tht_desc[$thtId]?></small>
                                <br>
                            </div>
                        <?php } ?> 
                    </span>
                </div>
            </div>
        </div>
        <div style="width: 48%">
            <select id="list__smd" 
                    data-title="Urządzenie SMD:" 
                    data-live-search="true"
                    data-actions-box="true" 
                    data-selected-text-format="count > 3"
                    class="form-control selectpicker mt-4" 
                    multiple>
                <?= $selectRenderer -> renderSMDSelect($smdUnused) ?>
            </select>
            <div class="text-center">
                <button id="addSMD" class="btn btn-primary mt-2">Dodaj</button>
            </div>
            <div class="card mt-2">
                <div class="card-body">
                    <h5 class="text-center">Produkowane SMD</h5>
                    <hr>
                    <span id="smdUsed">
                        <?php foreach($smdUsed as $smdId) { ?>
                            <div class="smd-<?=$smdId?> mt-3">
                                <b><?=$list__smd[$smdId]?></b>
                                <button type="button" data-type="smd" data-id="<?=$smdId?>" class="close removeDevice" aria-label="Close">
                                    <span aria-hidden="true">×</span>
                                </button>
                                <br>
                                <small><?=$list__smd_desc[$smdId]?></small>
                                <br>
                            </div>
                        <?php } ?> 
                    </span>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="http://<?=BASEURL?>/public_html/components/profile/devicesproduced/devices-produced-view.js"></script>
