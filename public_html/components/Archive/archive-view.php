<?php 
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;

$MsaDB = MsaDB::getInstance();

$selectRenderer = new SelectRenderer($MsaDB);

$list__input_type = $MsaDB -> readIdName("inventory__input_type");

?>

<select id="list__sku" hidden><!--sku list-->
    <?= $selectRenderer -> renderSKUSelect() ?>
</select>
<select id="list__tht" hidden><!--tht list-->
    <?= $selectRenderer -> renderTHTSelect() ?>
</select>
<select id="list__smd" hidden><!--smd list-->
    <?= $selectRenderer -> renderSMDSelect() ?>
</select>
<select id="list__parts" hidden><!--parts list-->
    <?= $selectRenderer -> renderPartsSelect() ?>
</select>

<div class="d-flex flex-wrap justify-content-center mt-4">
    <select class="selectpicker" id="magazine" data-width="10%" title="Magazyn..."><!--Which magazine to take data from-->
        <option value="sku">SKU</option> 
        <option value="tht">THT</option> 
        <option value="smd">SMD</option> 
        <option value="parts">Parts</option> 
    </select>
    <select class="selectpicker" id="user" data-width="15%" title="Użytkownik..." multiple 
                data-selected-text-format="count > 2"
                data-actions-box="true">
        <?= $selectRenderer -> renderUserSelect() ?>
    </select>
    <select class="selectpicker" id="list__device" title="Wybierz urządzenie..." data-width='35%' data-live-search="true" disabled></select><!--Devices list-->
    <button type="button" id="clearselect" class="close mx-2" disabled><!-- Clear -->
        <span aria-hidden="true">×</span>
    </button>
</div>
<div class="d-flex justify-content-center mt-4">
    <select id="input_type" class="selectpicker" title="Wybierz typ..." data-width="30%" multiple
                data-selected-text-format="count > 2"
                data-actions-box="true">
        <?= $selectRenderer -> renderArraySelect($list__input_type) ?>
    </select>
</div>

<div class="d-flex justify-content-center mt-4">
    <button id="previouspage" class="btn btn-light" disabled><b>&lsaquo;</b></button>
    <button id="currentpage" class="btn btn-light" disabled><b>1</b></button>
    <button id="nextpage" class="btn btn-light" disabled><b>&rsaquo;</b></button>
</div>

<div class="d-flex justify-content-center mt-2">
    <table class="table mt-4 table-striped w-75">
        <thead class="thead-light">
            <tr>
                <th scope="col">Użytkownik</th>
                <th scope="col">Urządzenie</th>
                <th scope="col">Ilość</th>
                <th scope="col">Data</th>
            </tr>
        </thead>
        <tbody id="archivetbody">

        </tbody>
    </table>
    <form id="limitform" class="mt-4 w-75 position-absolute">
        <input type="number" style="max-width: 3vw; margin-top: 0.4rem;" class="form-control float-right mr-2" id="limit" value="10" disabled />
    </form>
</div>