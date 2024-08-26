<?php
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;

$MsaDB = MsaDB::getInstance();

$selectRenderer = new SelectRenderer($MsaDB);

include('table-row-templates.php');
include('modals.php');

?>

<select id="list__tht_hidden" hidden>
    <?= $selectRenderer->renderTHTSelect() ?>
</select>
<select id="list__parts_hidden" hidden>
    <?= $selectRenderer->renderPartsSelect() ?>
</select>

<div class="d-flex justify-content-center my-4">
    <select data-title="Wybierz słownik..." data-width="500px" id="dictionarySelect" class="selectpicker">
        <option value="ref__valuepackage" data-subtext="Słownik określający, jaki komponent odpowiada wartości ValuePackage z pliku CSV podczas przesyłania BOM.">ref__ValuePackage</option>
        <option value="ref__package_exclude" data-subtext="Słownik określający na podstawie package, jakie wartości powinny być ignorowane w CSV.">ref__Package_exclude</option>
    </select>
</div>

<div class="d-flex justify-content-center">
    <div id="ajaxResult" class="my-4 position-fixed" 
        style="z-index: 100; 
        max-width: 75%;">
    </div>
</div>


<div id="tableContainer" class="d-flex flex-column align-items-center" style="visibility: hidden;">
    <div class="d-flex justify-content-center">
        <button id="previouspage" class="btn btn-light"><b>&lsaquo;</b></button>
        <button id="currentpage" class="btn btn-light" disabled>1</button>
        <button id="nextpage" class="btn btn-light"><b>&rsaquo;</b></button>
    </div>
    <div class="d-flex justify-content-center my-4">
        <button id="createNewDictionaryRow" class="btn btn-outline-secondary">Dodaj nową pozycję</button>
    </div>

    <table class="table table-bordered table-sm text-center" style="max-width: 1300px;">
        <thead>
            <tr class="text-left">
                <th colspan="3">
                    <form class="input-group" data-search="" style="width:40%" id="searchDictionaryForm">
                        <input type="text" class="form-control" id="searchDictionaryInput" placeholder="Szukaj...">
                        <div class="input-group-append">
                            <button class="btn btn-outline-primary" type="submit">
                                <i class="bi bi-search"></i>
                            </button>
                        </div>
                    </form>
                </th>
            </tr>
            <tr>
                <th style="width:40%" id="valuePackageCol" scope="col">Value Package</th>
                <th style="width:50%" id="componentCol" scope="col">Komponent</th>
                <th style="width:10%" scope="col"></th>
            </tr>
        </thead>
        <tbody id="dictionaryTBody">
        </tbody>
    </table>
</div>

<script src="http://<?=BASEURL?>/public_html/components/admin/bom/dictionary/edit-dictionary-view.js"></script>