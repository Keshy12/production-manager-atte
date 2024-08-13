<div id="uploadBomFields" class="mt-4 d-flex justify-content-center">
    <form id="uploadBomForm" method="post" enctype="multipart/form-data"
          action="http://<?=BASEURL?>/public_html/components/admin/bom/upload/upload-csv.php" >
        <div class="input-group">
            <div class="custom-file" style="width: 500px;">
                <input type="file" class="custom-file-input" name="BomCsv" id="uploadBomInput">
                <label data-browse="Przeglądaj" class="custom-file-label" for="customFile" id="uploadBomLabel">
                    Wybierz plik...
                </label>
            </div>
        </div>
    </form>
</div>
<div class="d-flex justify-content-center mt-4">
    <div id="ajaxResult" class="mt-4 position-fixed" 
        style="z-index: 100; 
        max-width: 75%;">
    </div>
    <div id="errorsContainer"></div>
</div>

<div class="d-flex flex-column align-items-center" style="visibility:hidden;" id="tableContainer">
    <h4 class="my-4"><span id="thtName"></span><small> ver. </small><span id="thtVersion"></span></h4>
    <table style="max-width: 1300px" class="table table-sm table-hover table-bordered my-2">
        <thead>
            <tr class="text-center">
                <th class="w-50" colspan="2" scope="col">Dane w bazie MSA.</th>  
                <th class="w-50" colspan="2" scope="col">Dane z pliku CSV.</th>
            </tr>
            <tr class="text-center">
                <th scope="col">Nazwa</th>  
                <th scope="col">Ilość</th>
                <th scope="col">Nazwa</th>  
                <th scope="col">Ilość</th>
            </tr>
        </thead>
        <tbody id="thtTBody">
        </tbody>
    </table>

    <h4 class="my-4"><span id="smdName"></span><small> Lam. </small><span id="smdLaminate"></span><small> Ver. </small><span id="smdVersion"></span></h4>
    <table style="max-width: 1300px" class="table table-sm table-hover table-bordered my-2">
        <thead>
            <tr class="text-center">
                <th class="w-50" colspan="2" scope="col">Dane w bazie MSA.</th>  
                <th class="w-50" colspan="2" scope="col">Dane z pliku CSV.</th>
            </tr>
            <tr class="text-center">
                <th scope="col">Nazwa</th>  
                <th scope="col">Ilość</th>
                <th scope="col">Nazwa</th>  
                <th scope="col">Ilość</th>
            </tr>
        </thead>
        <tbody id="smdTBody">
        </tbody>
    </table>
    <button id="sendBom" class="btn btn-primary my-4">Prześlij BOM</button>
</div>
<script src="http://<?=BASEURL?>/public_html/components/admin/bom/upload/upload-bom-view.js"></script>
