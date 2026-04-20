<?php
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;

$MsaDB = MsaDB::getInstance();

$selectRenderer = new SelectRenderer($MsaDB);

$part__group = $MsaDB->readIdName('part__group');
$part__type = $MsaDB->readIdName('part__type');
$part__unit = $MsaDB->readIdName('part__unit');


include('table-row-template.php');
include('modals.php');
?>

<style>
    tr.new-part-row {
        background-color: #d4edda !important;
    }
    tr.edited-part-row {
        background-color: #fff3cd !important;
    }
    td.cell-edited {
        background-color: #ffd966 !important;
        cursor: pointer;
    }
    tr.edited-part-row td.cell-edited {
        background-color: #ffd966 !important;
    }
    .change-content {
        font-size: 0.75rem;
    }
</style>

<select id="part__group_hidden" hidden>
    <?= $selectRenderer->renderArraySelect($part__group) ?>
</select>
<select id="part__type_hidden" hidden>
    <?= $selectRenderer->renderArraySelect($part__type) ?>
</select>
<select id="part__unit_hidden" hidden>
    <?= $selectRenderer->renderArraySelect($part__unit) ?>
</select>

<div id="loadingMessage">
    <div class="d-flex align-items-center justify-content-center mt-4">
        <span class="mx-1">Wykrywanie nowych komponentów parts</span>
        <div id="spinnerflowpin" class="spinner-border mt-1 text-center mx-1" role="status"></div>
    </div>
</div>

<div id="legendCollapse" class="collapse show mx-auto" style="max-width: 1300px;">
    <div class="alert alert-info mb-2" role="alert">
        <a class="text-dark" data-toggle="collapse" href="#legendContent" aria-expanded="true" aria-controls="legendContent">
            <strong>Legenda kolorów:</strong> <i class="bi bi-chevron-down"></i>
        </a>
        <div id="legendContent" class="collapse show mt-2">
            <span class="d-inline-block px-2 py-1 me-2 border border-dark rounded" style="background-color: #d4edda;">&nbsp;</span> Nowy komponent (nie istnieje w bazie)<br>
            <span class="d-inline-block px-2 py-1 me-2 border border-dark rounded" style="background-color: #fff3cd;">&nbsp;</span> Zmieniony komponent (istnieje w bazie, różnice w danych)<br>
            <span class="d-inline-block px-2 py-1 me-2 border border-dark rounded" style="background-color: #ffd966;">&nbsp;</span> Zmienione pole (kliknij, aby zobaczyć różnicę)
        </div>
    </div>
</div>

<div id="tableContainer" class="d-flex flex-column align-items-center mt-4">
    <table id="detectPartsTable" class="table table-bordered table-sm text-center" style="max-width: 1300px; display: none;">
        <thead>
            <tr>
                <th style="width:10%" scope="col">Id</th>
                <th style="width:40%" scope="col">Komponent</th>
                <th style="width:20%" scope="col">PartGroup</th>
                <th style="width:10%" scope="col">PartType</th>
                <th style="width:10%" scope="col">JM</th>
                <th style="width:10%" scope="col"></th>
            </tr>
        </thead>
        <tbody id="detectPartsTBody">

        </tbody>
    </table>

    <button class="btn btn-primary mb-3" id="uploadNewParts" style="display: none;">
        Wyślij zmiany
    </button>
</div>

<script src="<?= asset('public_html/components/admin/components/detectnewparts/detect-parts-view.js') ?>"></script>