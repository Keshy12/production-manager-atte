<?php
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;
include('device-produced-template.php');
include('modals.php');
include($componentsDir."/Profile/DevicesProduced/modals.php");

$MsaDB = MsaDB::getInstance();

$selectRenderer = new SelectRenderer($MsaDB);

$list__submag = $MsaDB -> readIdName("magazine__list", 
                                "sub_magazine_id", 
                                "sub_magazine_name", 
                                "ORDER BY type_id, sub_magazine_id ASC");
?>

<div class="d-flex justify-content-center">
    <div id="ajaxResult" class="mt-4 position-fixed" 
    style="z-index: 100; 
    max-width: 75%;">
    </div>
</div>

<select id="list__tht_hidden" hidden>
    <?= $selectRenderer -> renderTHTSelect() ?>
</select>
<select id="list__smd_hidden" hidden>
    <?= $selectRenderer -> renderSMDSelect() ?>
</select>

<div class="d-flex justify-content-center my-4">
    <select name="" id="list__user" data-title="Wybierz profil..." data-live-search="true" class="selectpicker">
        <?php $selectRenderer -> renderUserSelect() ?>
    </select>
    <button id="createNewProfile" class="btn btn-light ml-2">Dodaj nowy</button>
</div>

<form method="POST" action="http://<?=BASEURL?>/public_html/components/admin/profiles/edit/edit-profile.php" 
                    style="max-width: 700px" 
                    class="container" id="userForm">
        <h1 class="text-center">Profil: <span id="userFullName"></span></h1>
        <input type="hidden" name="user_id" id="user_id">
        Login: <input id="login" name="login" class="form-control rounded mx-2" disabled required>
        <span id="passwordField" style="display: none;">
            Hasło: <input id="password" name="password" class="form-control rounded mx-2">
        </span>
        Imię: <input id="name" name="name" class="form-control rounded mx-2" disabled required>
        Nazwisko: <input id="surname" name="surname" class="form-control rounded mx-2" disabled>
        Email: <input id="email" name="email" class="form-control rounded mx-2" disabled>
        Magazyn: <select name="sub_magazine_id" data-style-base="form-control" 
                        data-title="Wybierz magazyn..." id="list__submag" 
                        class="selectpicker form-control rounded mx-2" disabled required>
            <?php $selectRenderer -> renderArraySelect($list__submag) ?>
        </select>
        <span id="isAdminField" style="display: none;">
            Uprawnienia:
            <div class="form-check mx-2">
                <input class="form-check-input" type="checkbox" name="isAdmin" id="isAdmin">
                <label class="form-check-label" for="isAdmin">
                    Administrator
                </label>
            </div>
        </span>
        <div class="d-flex justify-content-center my-2">
            <button type="submit" id="editProfileSubmit" class="btn btn-primary" disabled>Zapisz</button>
            <button type="submit" id="addProfileSubmit" style="display: none;"  class="btn btn-primary">Dodaj</button>
        </div>
</form>
<hr>
<div class="container d-flex align-items-center justify-content-center">
    <div class="w-75 d-flex align-items-start justify-content-around">
        <div style="width: 48%">
            <select id="list__tht" 
                    data-title="Urządzenie THT:" 
                    data-live-search="true"
                    data-actions-box="true" 
                    data-selected-text-format="count > 3"
                    class="form-control selectpicker mt-4" 
                    multiple disabled>
            </select>
            <div class="text-center">
                <button id="addTHT" class="btn btn-primary mt-2" disabled>Dodaj</button>
            </div>
            <div class="card mt-2">
                <div class="card-body">
                    <h5 class="text-center">Produkowane THT</h5>
                    <hr>
                    <div class="d-flex">
                    <input type="search" data-deviceType="tht" class="form-control rounded mr-2 my-1 filter" placeholder="Szukaj">
                        <button data-deviceType="tht" class="btn btn-sm mr-2 my-1 btn-outline-danger text-nowrap deleteAllDevices" disabled>Usuń widoczne</button>
                    </div>
                    <hr>
                    <span id="thtUsed"></span>
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
                    multiple disabled>
            </select>
            <div class="text-center">
                <button id="addSMD" class="btn btn-primary mt-2" disabled>Dodaj</button>
            </div>
            <div class="card mt-2">
                <div class="card-body">
                    <h5 class="text-center">Produkowane SMD</h5>
                    <hr>
                    <div class="d-flex">
                        <input type="search" data-deviceType="smd" class="form-control rounded mr-2 my-1 filter" placeholder="Szukaj">
                        <button data-deviceType="smd" class="btn btn-sm mr-2 my-1 btn-outline-danger text-nowrap deleteAllDevices" disabled>Usuń widoczne</button>
                    </div>
                    <hr>
                    <span id="smdUsed"></span>
                </div>
            </div>
        </div>
    </div>
</div>
<script src="http://<?=BASEURL?>/public_html/components/admin/profiles/edit/edit-profile-view.js"></script>