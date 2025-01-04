<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$queryResult = $MsaDB -> query("SELECT * FROM `ref__timestamp` WHERE `id` = 3", PDO::FETCH_ASSOC);
$timestamp = $queryResult[0]['last_timestamp'];
$lastReadCell = (int)$queryResult[0]['params'] - 1;

include('table-row-template.php');
?>

<div class="d-flex justify-content-center mt-4">
    <div class="card my-2">
        <div class="card-header text-center">
            Data ostatniej aktualizacji:
        </div>
        <ul class="list-group list-group-flush text-center">
            <li class="list-group-item">
                <?= $timestamp ?>
            </li>
        </ul>
    </div>
    <div class="card my-2">
        <div class="card-header text-center">
            Ostatnia zaimportowana komórka:
        </div>
        <ul class="list-group list-group-flush text-center">
            <li class="list-group-item" id="lastFoundCell">
                <?= $lastReadCell ?>
            </li>
        </ul>
    </div>
</div>

<div class="d-flex justify-content-center">
    <button id="downloadOrders" class="btn btn-secondary my-2 mx-2">Pobierz zamówienia</button>
    <button id="importOrders" class="btn btn-primary my-2 mx-2" disabled>Zaimportuj zamówienia</button>
</div>

<div class="d-flex justify-content-center my-2">
    <div class="alert alert-danger w-75" id="missingPartsAlert" style="display: none;" role="alert">
        Uwaga! Pobranie danych jest niemożliwe. Część z wykrytych parts nie istnieje w bazie danych.
        <br> <b id="missingParts"></b> 
        <br> <a href="http://<?=BASEURL?>/admin/components/detect-new-parts">Proszę dodać brakujące parts do bazy danych.</a>
    </div>
    <div class="alert alert-danger w-75" id="errorAlert" style="display: none;" role="alert"></div>
    <div class="alert alert-success w-75" id="successAlert" style="display: none;" role="alert"></div>
</div>
<div class="d-flex justify-content-center my-1">
    <button id="previousPage" class="btn btn-light" disabled=""><b>‹</b></button>
    <button id="currentPage" class="btn btn-light" disabled="">1</button>
    <button id="nextPage" class="btn btn-light" disabled=""><b>›</b></button>
</div>
<div class="d-flex justify-content-center mt-2">
    <table class="table mt-4 table-striped w-75">
        <thead class="thead-light">
            <tr>
                <th scope="col">GRN ID</th>
                <th scope="col">PO ID</th>
                <th scope="col">Part</th>
                <th scope="col">Qty</th>
                <th scope="col">Vendor JM</th>
            </tr>
        </thead>
        <tbody id="fromOrdersTBody">
        </tbody>
    </table>
</div>

<div class="d-flex justify-content-center mt-2">
    <span class="mt-1 spinnerFromOrders" style="display: none;">Ładowanie zamówień...</span>
    <div class="spinner-border mt-1 spinnerFromOrders" role="status" style="display: none;"></div>
</div>
<div class="d-flex justify-content-center">
    <small class="text-center spinnerFromOrders" style="display: none;">
        Czasem pobieranie danych z Google Spreadsheet nie działa za pierwszym razem.
        Jeśli czekasz już długo, odśwież stronę.
    </small>
</div>

<script src="http://<?=BASEURL?>/public_html/components/admin/components/fromorders/from-orders-view.js"></script>