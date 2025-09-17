<?php
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;
use Atte\Utils\UserRepository;

$MsaDB = MsaDB::getInstance();

$selectRenderer = new SelectRenderer($MsaDB);
$userRepository = new UserRepository($MsaDB);

$currentUser = $userRepository -> getUserById($_SESSION['userid']);
$isCurrUserAdmin = $currentUser -> isAdmin();
$currentMagazine = $isCurrUserAdmin ? '' : $currentUser -> subMagazineId;

$list__warehouse = $MsaDB -> readIdName(table: 'magazine__list', 
                                        id: 'sub_magazine_id', 
                                        name: 'sub_magazine_name', 
                                        add: 'WHERE isActive = 1 ORDER BY type_id, sub_magazine_id ASC');
$list__priority = array_reverse($MsaDB -> readIdName("commission__priority"), true);

$allUsers = $userRepository -> getAllUsers();
$magazineRepo = new Atte\Utils\MagazineRepository($MsaDB);
include('modals.php');
include('table-row-template.php');
?>

<select id="list__sku_hidden" hidden>
    <?= $selectRenderer->renderSKUBomSelect() ?>
</select>
<select id="list__tht_hidden" hidden>`
    <?= $selectRenderer->renderTHTBomSelect() ?>
</select>
<select id="list__smd_hidden" hidden>
    <?= $selectRenderer->renderSMDBomSelect() ?>
</select>
<select id="list__parts_hidden" hidden>
    <?= $selectRenderer->renderPartsSelect() ?>
</select>

<div class="d-flex flex-column align-items-center justify-content-center mt-4">
    <div class="d-flex w-75" id="selectWarehouses">
        <select id="transferFrom" data-title="Transfer z:" class="form-control selectpicker w-50 mx-2" 
                    data-default-value="<?=$currentMagazine?>" data-live-search="true" 
                    <?= $isCurrUserAdmin ? '' : 'disabled'; ?>>
            <?= $selectRenderer->renderArraySelect($list__warehouse) ?>
        </select>
        <select id="transferTo" data-title="Transfer do:" class="form-control selectpicker w-50 mx-2" 
                    data-live-search="true">
            <?= $selectRenderer->renderArraySelect($list__warehouse) ?>
        </select>
    </div>
    <div id="createCommissionCard" class="card align-items-center p-2 mt-2">
        Czy chcesz stworzyć zlecenie?
        <div class="d-flex my-2">
            <span data-toggle="popover" data-content="Wybierz magazyn docelowy."><button id="dontCreateCommission" class="btn btn-secondary mx-1" style="pointer-events: none;" disabled>Nie</button></span>
            <span data-toggle="popover" data-content="Wybierz magazyn docelowy."><button id="createCommission" class="btn btn-primary mx-1" style="pointer-events: none;" disabled>Tak</button></span>
        </div>
    </div>

    <div id="moreOptionsCard" style="display: none;">
        <div class="card d-flex justify-content-center align-items-center mt-2">
            <div class="card-header text-center w-100">
                <h4 class="text-center mt-2">Dodaj zlecenie</h4>
            </div>
            <div class="card-body p-2">
                <div class="input-group justify-content-center mt-2">
                    <div class="input-group-prepend">
                        <span class="input-group-text">Użytkownicy</span>
                    </div>
                    <select class="selectpicker" id="userSelect" title="Wybierz..." multiple
                            data-selected-text-format="count > 2" data-actions-box="true"
                            data-style-base="form-control" data-style="" data-hide-disabled="true">
                        <?php foreach($allUsers as $user){
                            $usedSKUJson = json_encode($user -> getDevicesUsed("sku"));
                            $usedTHTJson = json_encode($user -> getDevicesUsed("tht"));
                            $usedSMDJson = json_encode($user -> getDevicesUsed("smd"));
                            echo "<option data-submag='{$user -> subMagazineId}' 
                                        data-used-sku='{$usedSKUJson}'
                                        data-used-tht='{$usedTHTJson}'
                                        data-used-smd='{$usedSMDJson}'
                                        value='{$user -> userId}'>
                                        {$user -> name} {$user -> surname}
                                        </option>";
                        } ?>
                    </select>
                </div>
                <div class="input-group input-group-sm justify-content-center">
                    <div class="input-group-prepend">
                        <span class="input-group-text">Grupy</span>
                    </div>
                    <select class="selectpicker" id="groupSelect" title="Wybierz..."
                        data-style-base="form-control form-control-sm" data-style="">
                    </select>
                </div>
                <div class="input-group justify-content-center mt-3">
                    <div class="input-group-prepend">
                        <span class="input-group-text">Priorytet</span>
                    </div>
                    <select class="selectpicker" id="list__priority" title="Wybierz..."
                        data-style-base="form-control" data-style="">
                        <?php
                            $colors = ["none", "green", "rgb(255, 219, 88)", "red"];
                            foreach($list__priority as $id => $value) {
                                echo "<option data-content=\"<span style='box-shadow: -20px 0px 0px 0px {$colors[$id]}; margin-left: 7px;'>$value</span>\" value='$id'>$value</option>";
                            } 
                        ?>
                    </select>
                </div>
                <div class="input-group justify-content-center flex-nowrap d-flex mt-3">
                    <div class="input-group-prepend">
                        <span class="input-group-text">Urządzenie</span>
                    </div>
                    <select class="selectpicker" data-width="15%" id="deviceType" title="Typ..."
                            data-style-base="form-control" data-style="" disabled>
                        <option value="sku">SKU</option>
                        <option value="tht">THT</option>
                        <option value="smd">SMD</option>
                    </select>
                    <select data-width="300px" class="selectpicker" id="list__device" disabled data-live-search="true"
                            title="Wybierz urządzenie..." data-style-base="form-control" data-style="form-control-disabled">
                    </select>
                </div>
                <div class="justify-content-center d-flex justify-content-center">
                    <div id="laminateSelect" style="display: none;">
                        <select class="selectpicker" id="list__laminate" title="Laminat..." disabled
                                data-style-base="form-control" data-style="form-control-disabled">
                        </select>
                    </div>
                    <div id="versionSelect" style="display: none;">
                        <select class="selectpicker" id="version" title="Wersja..." disabled
                                data-style-base="form-control" data-style="form-control-disabled">
                        </select>
                    </div>
                </div>
            </div>
            <div class="input-group mt-2 w-50">
                <div class="input-group-prepend">
                    <span class="input-group-text">Ilość</span>
                </div>
                <input type="number" class="form-control" id="quantity">
            </div>
            <button id="addCommission" class="btn btn-secondary mt-2 mb-3">Dodaj</button>
        </div>
    </div>
</div>

<div id="commissionTableContainer" style="display: none;">
    <h4 class="text-center mt-4 mb-2">
        <button class="btn btn-lg btn-link dropdown-toggle" data-toggle="collapse" data-target="#commissionTable">Tworzone zlecenia</button>
    </h4>
    <div class="d-flex flex-column align-items-center justify-content-center">
        <table id="commissionTable" class="table table-bordered table-sm table-hover collapse show text-center w-50">
            <thead>
                <th>Odbiorca</th>
                <th>Urządzenie</th>
                <th>Laminat</th>
                <th>Wersja</th>
                <th>Ilość</th>
                <th></th>
            </thead>
            <tbody id="commissionTBody"></tbody>
        </table>
        <button id="submitCommissions" class="btn btn-primary mt-2 mb-3">Zakończ</button>
    </div>
</div>

<div class="d-flex flex-column align-items-center justify-content-center">
    <span class="commissionSubmitSpinner" style="display:none">Wczytywanie potrzebnych komponentów...</span>
    <div class="spinner-border mt-1 text-center commissionSubmitSpinner" style="display:none"></div>
</div>

<div id="transferTableContainer" style="display:none">
    <h4 class="text-center mt-4 mb-2">Przesyłane komponenty</h4>

    <!-- Centered buttons container -->
    <div class="text-center mb-3" id="transferTableButtons" style="display: none;">
        <button id="showHelpModal" class="btn btn-outline-info btn-sm" data-toggle="tooltip" title="Pomoc - jak interpretować widok">
            <i class="bi bi-question-circle"></i> Pomoc
        </button>
    </div>

    <div class="d-flex flex-column align-items-center justify-content-center">
        <table class="table table-bordered table-sm table-hover text-center w-75">
            <!-- rest of your table code stays the same -->
            <thead>
            <tr style="border: none;" class="text-center p-0 m-0">
                <td style="border: none;"></td>
                <td class="p-0" style="border: none;">
                    <input type="checkbox" id="subtractPartsMagazineFrom">
                    <br>
                    <small>
                        <label for="subtractPartsMagazineFrom">Uwzględnij aktywne zlecenia</label>
                    </small>
                </td>
                <td class="p-0" style="border: none;">
                    <input type="checkbox" id="subtractPartsMagazineTo">
                    <br>
                    <small>
                        <label for="subtractPartsMagazineTo">Uwzględnij aktywne zlecenia</label>
                    </small>
                </td>
                <td style="border: none;"></td>
                <td style="border: none;">
                    <button id="insertDifferenceAll" class="btn btn-primary w-100 btn-sm mx-auto">
                        Różnica wszystkie
                    </button>
                </td>
            </tr>
            <tr>
                <th style="width: 45%;">Komponent</th>
                <th>Dostępne na magazynie</th>
                <th>W magazynie docelowym</th>
                <th>Potrzebne do zlecenia</th>
                <th>Przekazywana ilość</th>
                <th></th>
            </tr>
            </thead>
            <tbody id="transferTBody"></tbody>
        </table>
        <button id="submitTransfer" data-toggle="popover" data-trigger="manual"
                data-content="Wpisz przekazywanÄ… iloÅ›Ä‡ dla kaÅ¼dego z komponentÃ³w" class="btn btn-primary mt-2 mb-3">
            Prześlij
        </button>
    </div>
</div>

<div class="d-flex flex-column align-items-center justify-content-center">
    <span class="transferSubmitSpinner" style="display:none">Przesyłanie komponentów...</span>
    <div class="spinner-border mt-1 text-center transferSubmitSpinner" style="display:none"></div>
</div>

<?php include('transfer-confirmation-template.php'); ?>

<script src="http://<?=BASEURL?>/public_html/components/transfer/transfer-view.js"></script>
<script src="http://<?=BASEURL?>/public_html/components/transfer/commissions.js"></script>
<script src="http://<?=BASEURL?>/public_html/components/transfer/submit.js"></script>