<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

$queryResult = $MsaDB -> query("SELECT * FROM `ref__timestamp` WHERE `id` = 3", PDO::FETCH_ASSOC);
$timestamp = $queryResult[0]['last_timestamp'];
$lastReadCell = (int)$queryResult[0]['params'] - 1;
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
            Ostatnia pobrana komórka:
        </div>
        <ul class="list-group list-group-flush text-center">
            <li class="list-group-item">
                <?= $lastReadCell ?>
            </li>
        </ul>
    </div>
</div>

<div class="d-flex justify-content-center my-2">
    <div class="alert alert-danger w-75" id="errorAlert" style="display: none;" role="alert">
        Uwaga! Pobranie danych jest niemożliwe. Część z wykrytych parts nie istnieje w bazie danych.
        <br> <b id="missingParts"></b> 
        <br> <a href="http://<?=BASEURL?>/admin/components/detect-new-parts">Proszę dodać brakujące parts do bazy danych.</a>
    </div>
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
        <tbody id="fromOrdersTable">

        </tbody>
    </table>
</div>

<script src="http://<?=BASEURL?>/public_html/components/admin/components/fromorders/from-orders-view.js"></script>