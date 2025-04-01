<?php 
include('modals.php');
function printCommission($commissionDeviceType, $valuesToPrint) { ?>
<div class="card card<?=$valuesToPrint['id']?> w-25 text-center m-4 <?=$valuesToPrint['cardClass']?>"
    style="box-shadow: -7px 0px 0px 0px <?=$valuesToPrint['color']?>; min-width: 360px;">
    <div class="card-header">
        <button type="button" 
                style="float: left; <?=$valuesToPrint['hideButton']?>;" 
                class="close receivers" tabindex="0" role="button"
                data-toggle="popover" data-trigger="focus" 
                data-html="true" 
                data-content="<?=implode('<br>', $valuesToPrint['receivers'])?>" 
                data-original-title="Kontraktorzy:">
            <img src="http://<?=BASEURL?>/public_html/assets/img/index/subcontractors.svg" style="width: 20px;">
        </button>
        <button type="button" class="close" id="dropdownMenuButton" data-toggle="dropdown">
            <svg style="width: 20px;" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 448 512">
                 <!--! Font Awesome Pro 6.2.0 by @fontawesome - https://fontawesome.com License - https://fontawesome.com/license (Commercial License) Copyright 2022 Fonticons, Inc. -->
                <path
                    d="M0 96C0 78.3 14.3 64 32 64H416c17.7 0 32 14.3 32 32s-14.3 32-32 32H32C14.3 128 0 113.7 0 96zM0 256c0-17.7 14.3-32 32-32H416c17.7 0 32 14.3 32 32s-14.3 32-32 32H32c-17.7 0-32-14.3-32-32zM448 416c0 17.7-14.3 32-32 32H32c-17.7 0-32-14.3-32-32s14.3-32 32-32H416c17.7 0 32 14.3 32 32z">
                </path>
            </svg>
        </button>
        <div class="dropdown-menu">
            <a class="dropdown-item editCommission" 
                data-id="<?=$valuesToPrint['id']?>" 
                data-submagazine="<?=$valuesToPrint['magazineTo']?>" 
                data-receivers="<?=implode(',', array_keys($valuesToPrint['receivers']))?>"
                data-priority="<?=$valuesToPrint['priority']?>">
                Edytuj zlecenie
            </a>
            <a class="dropdown-item cancelCommission" 
                data-id="<?=$valuesToPrint['id']?>">
                Anuluj zlecenie
            </a>
        </div>
        <a data-toggle="popover" 
            style="font-size: 1.25rem;" 
            data-placement="top"
            data-content="<?=$valuesToPrint['deviceDescription']?>">
            <?=$valuesToPrint['deviceName']?>
        </a>

        <br>
        <small> 
            <?php if(!empty($valuesToPrint['deviceLaminate'])){ ?>
            Laminat: <b><?=$valuesToPrint['deviceLaminate']?></b> 
            <?php } 
            if(!empty($valuesToPrint['deviceVersion'])){ ?>
            Wersja: <b><?=$valuesToPrint['deviceVersion']?></b> 
            <?php } ?>
            
        </small>
        <br>
    </div>
    <div class="card-body">
        <table style="table-layout: fixed" class="table table-active table-bordered table-sm">
            <thead>
                <tr class="<?=$valuesToPrint['tableClass']?>">
                    <th>Zlecono</th>
                    <th>Wyprodukowano</th>
                </tr>
            </thead>
            <tbody>
                <tr class="<?=$valuesToPrint['tableClass']?>">
                    <td class="quantity">
                        <?=$valuesToPrint['quantity']?>
                    </td>
                    <td class="quantityProduced">
                        <?=$valuesToPrint['quantityProduced']?>
                    </td>
                </tr>
                <?php if($valuesToPrint['stateId'] == 1 && $commissionDeviceType != 'sku') { ?>
                    <tr>
                        <td colspan="2" class="table-light">
                            <form class="clickhere" 
                                  method="POST" 
                                  action="http://<?=BASEURL?>/public_html/components/production/<?=$commissionDeviceType?>/<?=$commissionDeviceType?>-view.php?redirect=true"
                                  target="_blank">
                                <input type="hidden" 
                                       name="device_id" 
                                       value="<?= $valuesToPrint['deviceBomId'] ?>">
                                <a href="#" onclick="$(this).parent().submit()">
                                    Kliknij tutaj aby przejść do produkcji.
                                </a> 
                            </form>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
        <table class="table table-active table-bordered table-sm">
            <thead>
                <tr class="<?=$valuesToPrint['tableClass']?>">
                    <th>Dostarczono:</th>
                </tr>
            </thead>
            <tbody>
                <tr class="<?=$valuesToPrint['tableClass']?>">
                    <td class="quantityReturned">
                        <?=$valuesToPrint['quantityReturned']?>
                    </td>
                </tr>
                <?php if ($valuesToPrint['quantityReturned'] < $valuesToPrint['quantityProduced']) { ?>
                <tr>
                    <td class="p-0">
                        <input type="number"
                        class="return form-control rounded-0 border-left-0 border-right-0 text-center"
                        placeholder="Wpisz zwracaną ilość" onkeydown="return event.keyCode !== 69">
                    </td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php if ($valuesToPrint['quantityReturned'] < $valuesToPrint['quantityProduced']) { ?>
        <button data-id="<?=$valuesToPrint['id']?>" data-type="<?=$commissionDeviceType?>" 
            data-device_id="<?=$valuesToPrint['deviceId']?>"
            class="submitToCommission btn btn-primary mx-auto instantPop" 
            data-toggle="popover" data-trigger="manual"
            data-content="Zwracana ilość jest większa od produkcji">
            Wyślij
        </button>
        <?php } ?>
    </div>
    <div class="card-footer text-muted">
        Data zlecenia:
        <?=$valuesToPrint['timestampCreated']?>
    </div>
</div>

<?php }
echo '<div id="content" class="mt-4 container text-center">';
echo '<div class="d-flex flex-wrap justify-content-center">';

$MsaDB = Atte\DB\MsaDB::getInstance();

$usersName = $MsaDB -> query("SELECT name, surname FROM user ORDER BY user_id ASC", PDO::FETCH_ASSOC);
$usersId = $MsaDB -> query("SELECT user_id FROM user ORDER BY user_id ASC", PDO::FETCH_COLUMN);
$users = array_combine($usersId, $usersName);


$userRepository = new Atte\Utils\UserRepository($MsaDB);
$currentUser = $userRepository -> getUserById($_SESSION["userid"]);

$bomRepository = new Atte\Utils\BomRepository($MsaDB);

$commissions = $currentUser -> getActiveCommissions();

$list__sku = $MsaDB -> readIdName("list__sku");
$list__sku_desc = $MsaDB -> readIdName("list__sku", "id", "description");
$list__laminate = $MsaDB -> readIdName("list__laminate");
$list__tht = $MsaDB -> readIdName("list__tht");
$list__tht_desc = $MsaDB -> readIdName("list__tht", "id", "description");
$list__smd = $MsaDB -> readIdName("list__smd");
$list__smd_desc = $MsaDB -> readIdName("list__smd", "id", "description");


$colors = ["none", "green", "yellow", "red"];
foreach($commissions as $commission) {
    $commissionDeviceType = $commission->deviceType;
    $commissionValues = $commission->commissionValues;
    $valuesToPrint = [];
    $valuesToPrint['id'] = $commissionValues['id'];
    $valuesToPrint['color'] = $colors[$commissionValues["priority"]];
    $valuesToPrint['stateId'] = $commissionValues['state_id'];
    $valuesToPrint['cardClass'] = $commissionValues['state_id'] == 1 ? '' : 'list-group-item-secondary';
    $valuesToPrint['tableClass'] = $commissionValues['state_id'] == 2 ? 'table-light' : '';
    $receivers = $commission -> getReceivers();
    $valuesToPrint['hideButton'] = count($receivers) == 1 ? "visibility: hidden;" : '';
    foreach($receivers as $receiver) {
        $valuesToPrint['receivers'][$receiver] = $users[$receiver]['name']." ".$users[$receiver]['surname'];
    }
    $valuesToPrint['magazineTo'] = $commissionValues['magazine_to'];
    $valuesToPrint['priority'] = $commissionValues['priority'];
    $valuesToPrint['deviceBomId'] = $commissionValues['deviceBomId'];
    $bom = $bomRepository -> getBomById($commissionDeviceType, $valuesToPrint['deviceBomId']);
    $bom -> getNameAndDescription();
    $valuesToPrint['deviceId'] = $bom -> deviceId;
    $valuesToPrint['deviceName'] = $bom -> name;
    $valuesToPrint['deviceDescription'] = $bom -> description;
    $valuesToPrint['deviceLaminate'] =  $bom -> laminateName ?? '';
    $valuesToPrint['deviceVersion'] =  $bom -> version;
    $valuesToPrint['quantity'] = $commissionValues['quantity'];
    $valuesToPrint['quantityProduced'] = $commissionValues['quantity_produced'];
    $valuesToPrint['quantityReturned'] = $commissionValues['quantity_returned'];
    $valuesToPrint['timestampCreated'] = $commissionValues['timestamp_created'];
    printCommission($commissionDeviceType, $valuesToPrint);
} 

echo '</div>';
echo '</div>';

?>
<script src="http://<?=BASEURL?>/public_html/components/index/active-commissions-view.js"></script>
