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

<div class="d-flex flex-column align-items-center mt-4">
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
        Wyślij nowe komponenty
    </button>
</div>

<script src="http://<?=BASEURL?>/public_html/components/admin/components/detectnewparts/detect-parts-view.js"></script>
