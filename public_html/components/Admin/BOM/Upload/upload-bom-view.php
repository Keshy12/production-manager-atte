<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= asset('public_html/components/admin/bom/upload/upload-bom.css') ?>">

<div id="bomUpload">

    <!-- ============ Upload card ============ -->
    <div class="bom-uploader">
        <div class="bom-uploader__head">
            <span class="bom-uploader__icon"><i class="bi bi-filetype-csv"></i></span>
            <div>
                <h1 class="bom-uploader__title">Wczytywanie BOM</h1>
                <p class="bom-uploader__sub">Wskaż plik CSV — porównamy go pozycja po pozycji z danymi w bazie MSA.</p>
            </div>
        </div>
        <form id="uploadBomForm" method="post" enctype="multipart/form-data"
              action="http://<?=BASEURL?>/public_html/components/admin/bom/upload/upload-csv.php">
            <div class="custom-file bom-file">
                <input type="file" class="custom-file-input" name="BomCsv" id="uploadBomInput" accept=".csv">
                <label data-browse="Przeglądaj" class="custom-file-label" for="uploadBomInput" id="uploadBomLabel">
                    Wybierz plik...
                </label>
            </div>
        </form>
    </div>

    <div id="errorsContainer" class="bom-errors"></div>
    <div id="ajaxResult" class="bom-toast"></div>

    <!-- ============ Comparison ============ -->
    <div id="tableContainer" class="bom-result" hidden>

        <div class="bom-toolbar">
            <div class="bom-legend" id="bomLegend">
                <span class="bom-chip bom-chip--qty"   data-count="changed"><i class="bi bi-arrow-left-right"></i><b>0</b><span>zmiana ilości</span></span>
                <span class="bom-chip bom-chip--added" data-count="added"><i class="bi bi-plus-lg"></i><b>0</b><span>nowe w CSV</span></span>
                <span class="bom-chip bom-chip--removed" data-count="removed"><i class="bi bi-dash-lg"></i><b>0</b><span>brak w CSV</span></span>
                <span class="bom-chip bom-chip--same"  data-count="same"><b>0</b><span>bez zmian</span></span>
            </div>
            <div class="bom-filter btn-group btn-group-sm" role="group" aria-label="Filtr pozycji">
                <button type="button" class="bom-filter__btn is-active" data-filter="diff">
                    <i class="bi bi-funnel-fill"></i> Tylko różnice
                </button>
                <button type="button" class="bom-filter__btn" data-filter="all">
                    <i class="bi bi-list-ul"></i> Wszystkie pozycje
                </button>
            </div>
        </div>

        <div class="bom-table-wrap">
            <table class="table bom-table mb-0" id="bomTable">
                <colgroup>
                    <col class="bom-col-name">
                    <col class="bom-col-qty">
                    <col class="bom-col-status">
                    <col class="bom-col-name">
                    <col class="bom-col-qty">
                </colgroup>
                <thead>
                    <tr class="bom-thead-group">
                        <th colspan="2" scope="colgroup">
                            <i class="bi bi-database"></i> Dane w bazie MSA
                        </th>
                        <th class="bom-col-status" aria-hidden="true"></th>
                        <th colspan="2" scope="colgroup">
                            <i class="bi bi-file-earmark-text"></i> Dane z pliku CSV
                        </th>
                    </tr>
                    <tr class="bom-thead-cols">
                        <th scope="col">Komponent</th>
                        <th scope="col" class="bom-col-qty">Ilość</th>
                        <th scope="col" class="bom-col-status">Zmiana</th>
                        <th scope="col">Komponent</th>
                        <th scope="col" class="bom-col-qty">Ilość</th>
                    </tr>
                </thead>
                <tbody id="bomTBody" class="bom-collapsed"></tbody>
            </table>
        </div>

        <div class="bom-footer">
            <button id="sendBom" class="btn bom-submit" type="button">
                Prześlij BOM <i class="bi bi-arrow-right"></i>
            </button>
        </div>
    </div>
</div>


<!-- SMD Default BOM Modal -->
<div class="modal fade" id="setDefaultSmdModal" tabindex="-1" role="dialog" aria-labelledby="setDefaultSmdModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="setDefaultSmdModalLabel">Ustaw jako domyślny BOM</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <div class="custom-control custom-checkbox mb-3" id="defaultThtRow">
                    <input type="checkbox" class="custom-control-input" id="setDefaultThtCheck" checked>
                    <label class="custom-control-label" for="setDefaultThtCheck">
                        <span class="bom-modal-tag" style="background: #5a6876;">THT</span>
                        <span class="bom-modal-device" id="thtDeviceName"></span>
                        <div class="bom-modal-versions">
                            <span class="bom-modal-badge bom-modal-badge--old" id="thtCurrentDefault">brak</span>
                            <i class="bi bi-arrow-right text-muted mx-2"></i>
                            <span class="bom-modal-badge bom-modal-badge--new" id="thtNewVersion"></span>
                        </div>
                    </label>
                </div>
                <div class="custom-control custom-checkbox" id="defaultSmdRow">
                    <input type="checkbox" class="custom-control-input" id="setDefaultSmdCheck" checked>
                    <label class="custom-control-label" for="setDefaultSmdCheck">
                        <span class="bom-modal-tag" style="background: #2f6f7e;">SMD</span>
                        <span class="bom-modal-device" id="smdDeviceName"></span>
                        <div class="bom-modal-versions">
                            <span class="bom-modal-badge bom-modal-badge--old" id="smdCurrentDefault">brak</span>
                            <i class="bi bi-arrow-right text-muted mx-2"></i>
                            <span class="bom-modal-badge bom-modal-badge--new" id="smdNewVersion"></span>
                        </div>
                    </label>
                </div>
            </div>
            <div class="modal-footer justify-content-end">
                <button type="button" class="btn btn-primary" id="setDefaultConfirm">Zatwierdź</button>
            </div>
        </div>
    </div>
</div>
<script src="<?= asset('public_html/components/admin/bom/upload/upload-bom-view.js') ?>"></script>
