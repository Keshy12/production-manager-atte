<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">

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
            <div class="custom-control custom-checkbox bom-check">
                <input class="custom-control-input" type="checkbox" id="setDefaultTht">
                <label class="custom-control-label" for="setDefaultTht">
                    Ustaw jako domyślny BOM dla THT
                </label>
            </div>
            <button id="sendBom" class="btn bom-submit" type="button">
                Prześlij BOM <i class="bi bi-arrow-right"></i>
            </button>
        </div>
    </div>
</div>

<style>
#bomUpload {
    /* neutrals */
    --bom-ink:          #1d2328;
    --bom-muted:        #737f8b;
    --bom-faint:        #a3adb8;
    --bom-line:         #e3e7ec;
    --bom-line-strong:  #c9d0d8;
    --bom-head:         #eceff2;
    --bom-head-ink:     #525d68;
    --bom-band:         #e2e6ea;
    --bom-page:         #f5f6f8;

    /* changed quantity */
    --bom-qty-bg:       #fff6de;
    --bom-qty-bg-hi:    #ffe9b3;
    --bom-qty-ink:      #7d5800;
    --bom-qty-line:     #e2a900;

    /* missing in CSV */
    --bom-del-bg:       #fdeded;
    --bom-del-bg-hi:    #fadada;
    --bom-del-ink:      #9e2622;
    --bom-del-line:     #d9534f;

    /* new in CSV */
    --bom-add-bg:       #eaf7ef;
    --bom-add-bg-hi:    #d5efdf;
    --bom-add-ink:      #1c6b3c;
    --bom-add-line:     #45a06b;

    --bom-mono: 'JetBrains Mono', ui-monospace, SFMono-Regular, 'Cascadia Mono', Consolas, monospace;

    --bom-head-h: 72px;   /* measured at runtime */
    --bom-row1-h: 36px;   /* measured at runtime */

    color: var(--bom-ink);
    padding-bottom: 5rem;
}

/* ---------------------------------------------------------- Upload card */
.bom-uploader {
    max-width: 660px;
    margin: 2.5rem auto 0;
    background: #fff;
    border: 1px solid var(--bom-line);
    border-radius: 14px;
    padding: 1.5rem 1.75rem 1.75rem;
    box-shadow: 0 1px 2px rgba(20,28,38,.05), 0 18px 40px -28px rgba(20,28,38,.45);
}
.bom-uploader__head {
    display: flex;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 1.25rem;
}
.bom-uploader__icon {
    flex: none;
    width: 44px; height: 44px;
    display: grid; place-items: center;
    border-radius: 11px;
    background: var(--bom-head);
    color: var(--bom-head-ink);
    font-size: 1.35rem;
    border: 1px solid var(--bom-line-strong);
}
.bom-uploader__title {
    font-size: 1.3rem;
    font-weight: 600;
    letter-spacing: -.015em;
    margin: .1rem 0 .2rem;
}
.bom-uploader__sub {
    margin: 0;
    font-size: .875rem;
    color: var(--bom-muted);
    line-height: 1.45;
}
.bom-file .custom-file-input,
.bom-file .custom-file-label { height: calc(2.5rem + 2px); }
.bom-file .custom-file-label {
    line-height: 1.75;
    border-color: var(--bom-line-strong);
    border-radius: 9px;
    color: var(--bom-muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    padding-right: 7rem;
}
.bom-file .custom-file-label::after {
    height: 2.5rem;
    line-height: 1.75;
    background: var(--bom-head);
    color: var(--bom-head-ink);
    font-weight: 500;
    border-left-color: var(--bom-line-strong);
    border-radius: 0 8px 8px 0;
}
.bom-file .custom-file-input:focus ~ .custom-file-label {
    border-color: #8a97a5;
    box-shadow: 0 0 0 3px rgba(110,125,140,.18);
}

/* ---------------------------------------------------------- Alerts */
.bom-errors {
    max-width: 1080px;
    margin: 1.5rem auto 0;
}
.bom-errors:empty { margin: 0; }
.bom-errors .alert {
    border-radius: 10px;
    font-size: .9rem;
    border: 1px solid transparent;
}
.bom-toast {
    position: fixed;
    top: 84px; left: 50%;
    transform: translateX(-50%);
    z-index: 1080;
    width: min(680px, 90vw);
}
.bom-toast .alert {
    border-radius: 10px;
    box-shadow: 0 12px 32px -12px rgba(20,28,38,.4);
}

/* ---------------------------------------------------------- Result shell */
.bom-result {
    max-width: 1180px;
    margin: 2.25rem auto 0;
}
.bom-result[hidden] { display: none; }

/* ---------------------------------------------------------- Toolbar */
.bom-toolbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: .75rem 1.25rem;
    padding: .8rem 1.1rem;
    background: var(--bom-head);
    border: 1px solid var(--bom-line-strong);
    border-bottom: 0;
    border-radius: 12px 12px 0 0;
}
.bom-legend {
    display: flex;
    flex-wrap: wrap;
    gap: .4rem;
}
.bom-chip {
    display: inline-flex;
    align-items: baseline;
    gap: .4rem;
    padding: .28rem .6rem;
    border-radius: 999px;
    font-size: .76rem;
    line-height: 1.3;
    border: 1px solid transparent;
    white-space: nowrap;
}
.bom-chip i { font-size: .72rem; align-self: center; }
.bom-chip b {
    font-family: var(--bom-mono);
    font-weight: 700;
    font-size: .82rem;
    font-variant-numeric: tabular-nums;
}
.bom-chip span { opacity: .82; }
.bom-chip--qty     { background: var(--bom-qty-bg); color: var(--bom-qty-ink); border-color: #ecd08f; }
.bom-chip--added   { background: var(--bom-add-bg); color: var(--bom-add-ink); border-color: #a9d8bd; }
.bom-chip--removed { background: var(--bom-del-bg); color: var(--bom-del-ink); border-color: #eeb5b3; }
.bom-chip--same    { background: #fff;              color: var(--bom-muted);  border-color: var(--bom-line-strong); }
.bom-chip.is-zero  { opacity: .45; }

/* Segmented filter */
.bom-filter { box-shadow: none; }
.bom-filter__btn {
    display: inline-flex;
    align-items: center;
    gap: .4rem;
    font-size: .8rem;
    font-weight: 500;
    padding: .35rem .8rem;
    border: 1px solid var(--bom-line-strong);
    background: #fff;
    color: var(--bom-head-ink);
    cursor: pointer;
    transition: background .15s ease, color .15s ease;
}
.bom-filter__btn:first-child  { border-radius: 8px 0 0 8px; }
.bom-filter__btn:last-child   { border-radius: 0 8px 8px 0; border-left: 0; }
.bom-filter__btn:hover        { background: #f7f8fa; }
.bom-filter__btn:focus        { outline: none; box-shadow: 0 0 0 3px rgba(110,125,140,.22); position: relative; z-index: 1; }
.bom-filter__btn.is-active {
    background: #4a545e;
    border-color: #4a545e;
    color: #fff;
}
.bom-filter__btn.is-active + .bom-filter__btn { border-left: 0; }

/* ---------------------------------------------------------- Table */
.bom-table-wrap {
    background: #fff;
    border: 1px solid var(--bom-line-strong);
    border-top: 0;
    border-bottom: 0;
}
.bom-table {
    width: 100%;
    border-collapse: separate;
    border-spacing: 0;
    font-size: .875rem;
    table-layout: fixed;
}
.bom-table th, .bom-table td { border: 0; }
.bom-table col.bom-col-qty    { width: 96px; }
.bom-table col.bom-col-status { width: 74px; }

/* --- head --- */
.bom-table thead th {
    position: sticky;
    z-index: 5;
    border: 0;                      /* beats Bootstrap's .table thead th border-bottom */
    background-color: var(--bom-head);
    color: var(--bom-head-ink);
    font-weight: 600;
    vertical-align: middle;
    padding: .55rem .85rem;
}
.bom-table thead tr.bom-thead-group th { top: 0; }
.bom-table thead tr.bom-thead-cols  th { top: var(--bom-row1-h); }

.bom-table .bom-thead-group th {
    font-size: .8rem;
    letter-spacing: .03em;
    text-transform: uppercase;
    box-shadow: inset 0 -1px 0 var(--bom-line-strong);
}
.bom-table .bom-thead-group th i { opacity: .55; margin-right: .3rem; }
.bom-table .bom-thead-group th:first-child { padding-left: 1.1rem; }

.bom-table .bom-thead-cols th {
    font-size: .68rem;
    letter-spacing: .09em;
    text-transform: uppercase;
    color: #78838e;
    font-weight: 700;
    padding-top: .4rem;
    padding-bottom: .45rem;
    box-shadow: inset 0 -2px 0 var(--bom-line-strong);
}
.bom-table .bom-thead-cols th:first-child { padding-left: 1.1rem; }

/* the status column doubles as the rule separating DB side from CSV side */
.bom-table th.bom-col-status,
.bom-table tbody td.bom-cell-status {
    text-align: center;
    padding-left: .25rem;
    padding-right: .25rem;
}
.bom-table th.bom-col-qty,
.bom-table tbody td.bom-cell-qty { text-align: right; padding-right: 1.1rem; }

/* --- section separator row --- */
.bom-section > td {
    position: sticky;
    top: var(--bom-head-h);
    z-index: 4;
    padding: 0;
    background-color: var(--bom-band);
    box-shadow: inset 0 -1px 0 var(--bom-line-strong);
}
.bom-section--split > td {
    box-shadow: inset 0 4px 0 #9aa5b1, inset 0 -1px 0 var(--bom-line-strong);
}
.bom-section__inner {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: .5rem .75rem;
    padding: .62rem 1.1rem;
    border-left: 4px solid var(--bom-section-accent, #64717e);
}
.bom-section--split .bom-section__inner { padding-top: 1.05rem; padding-bottom: .7rem; }
.bom-section__tag {
    font-family: var(--bom-mono);
    font-size: .68rem;
    font-weight: 700;
    letter-spacing: .1em;
    padding: .2rem .5rem;
    border-radius: 5px;
    color: #fff;
    background: var(--bom-section-accent, #64717e);
}
.bom-section__title {
    font-size: .95rem;
    font-weight: 600;
    letter-spacing: -.01em;
    color: #2b333b;
}
.bom-section__meta {
    font-size: .76rem;
    color: #6d7883;
}
.bom-section__meta b {
    font-family: var(--bom-mono);
    font-weight: 500;
    color: #3d464f;
}
.bom-section__stats {
    margin-left: auto;
    display: flex;
    gap: .35rem;
    flex-wrap: wrap;
}
.bom-section__stat {
    font-size: .7rem;
    font-family: var(--bom-mono);
    font-weight: 500;
    padding: .14rem .45rem;
    border-radius: 999px;
    border: 1px solid;
}
.bom-section__stat--qty     { background: var(--bom-qty-bg); color: var(--bom-qty-ink); border-color: #e6c882; }
.bom-section__stat--added   { background: var(--bom-add-bg); color: var(--bom-add-ink); border-color: #a9d8bd; }
.bom-section__stat--removed { background: var(--bom-del-bg); color: var(--bom-del-ink); border-color: #eeb5b3; }
.bom-section__stat--clean   { background: #fff; color: #5d6872; border-color: var(--bom-line-strong); }

/* --- body rows --- */
.bom-row > td {
    padding: .5rem .85rem;
    vertical-align: middle;
    box-shadow: inset 0 -1px 0 var(--bom-line);
    background-color: #fff;
    transition: background-color .12s ease;
}
.bom-row > td:first-child { padding-left: 1.1rem; }
.bom-row:hover > td { background-color: #f7f9fb; }

.bom-code {
    display: block;
    font-family: var(--bom-mono);
    font-size: .83rem;
    font-weight: 500;
    letter-spacing: -.01em;
    color: var(--bom-ink);
    word-break: break-word;
}
.bom-desc {
    display: block;
    font-size: .74rem;
    line-height: 1.35;
    color: var(--bom-muted);
    margin-top: .1rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.bom-cell-qty {
    font-family: var(--bom-mono);
    font-size: .9rem;
    font-weight: 500;
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
.bom-void { color: var(--bom-faint); font-family: var(--bom-mono); }
.bom-delta {
    display: inline-block;
    margin-left: .35rem;
    font-size: .68rem;
    font-weight: 700;
    padding: .05rem .3rem;
    border-radius: 4px;
    vertical-align: .08em;
}
.bom-delta--up   { background: var(--bom-add-bg-hi); color: var(--bom-add-ink); }
.bom-delta--down { background: var(--bom-del-bg-hi); color: var(--bom-del-ink); }

/* status marker */
.bom-mark {
    display: inline-grid;
    place-items: center;
    width: 24px; height: 24px;
    border-radius: 7px;
    font-size: .72rem;
}
.bom-mark--same    { width: 6px; height: 6px; border-radius: 50%; background: #d3d9df; }
.bom-mark--qty     { background: var(--bom-qty-bg-hi); color: var(--bom-qty-ink); }
.bom-mark--added   { background: var(--bom-add-bg-hi); color: var(--bom-add-ink); }
.bom-mark--removed { background: var(--bom-del-bg-hi); color: var(--bom-del-ink); }

/* row tints — the whole row for add/remove, only the numbers for a qty change */
.bom-row--qty > td           { background-color: var(--bom-qty-bg); }
.bom-row--qty:hover > td     { background-color: #fff1cf; }
.bom-row--added > td         { background-color: var(--bom-add-bg); }
.bom-row--added:hover > td   { background-color: #dff2e7; }
.bom-row--removed > td       { background-color: var(--bom-del-bg); }
.bom-row--removed:hover > td { background-color: #fbe2e2; }

.bom-row--qty > td.bom-cell-qty {
    background-color: var(--bom-qty-bg-hi);
    color: var(--bom-qty-ink);
    font-weight: 700;
}

/* left accent bar marks a changed row at a glance */
.bom-row--qty > td:first-child     { box-shadow: inset 4px 0 0 var(--bom-qty-line), inset 0 -1px 0 var(--bom-line); }
.bom-row--added > td:first-child   { box-shadow: inset 4px 0 0 var(--bom-add-line), inset 0 -1px 0 var(--bom-line); }
.bom-row--removed > td:first-child { box-shadow: inset 4px 0 0 var(--bom-del-line), inset 0 -1px 0 var(--bom-line); }

/* vertical rules around the status column keep the two sides visually apart */
.bom-table thead th.bom-col-status {
    box-shadow: inset 1px 0 0 var(--bom-line-strong),
                inset -1px 0 0 var(--bom-line-strong),
                inset 0 -2px 0 var(--bom-line-strong);
}
.bom-table tbody td.bom-cell-status {
    box-shadow: inset 1px 0 0 var(--bom-line-strong),
                inset -1px 0 0 var(--bom-line-strong),
                inset 0 -1px 0 var(--bom-line);
}
.bom-table .bom-thead-group th.bom-col-status {
    box-shadow: inset 1px 0 0 var(--bom-line-strong),
                inset -1px 0 0 var(--bom-line-strong),
                inset 0 -1px 0 var(--bom-line-strong);
}

/* collapsed = only differences */
.bom-collapsed .bom-row--same { display: none; }
.bom-empty-diff { display: none; }
.bom-collapsed .bom-empty-diff { display: table-row; }

.bom-empty > td {
    padding: 1.15rem 1.1rem;
    text-align: center;
    font-size: .82rem;
    color: var(--bom-muted);
    background-color: #fcfdfd;
    box-shadow: inset 0 -1px 0 var(--bom-line);
}
.bom-empty i { color: var(--bom-add-line); margin-right: .35rem; }

/* ---------------------------------------------------------- Footer */
.bom-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 1rem;
    padding: 1rem 1.1rem;
    background: var(--bom-head);
    border: 1px solid var(--bom-line-strong);
    border-radius: 0 0 12px 12px;
}
.bom-check .custom-control-label {
    font-size: .85rem;
    color: #3f4a4f;
    cursor: pointer;
    padding-top: .1rem;
}
.bom-submit {
    display: inline-flex;
    align-items: center;
    gap: .5rem;
    background: #2f3a45;
    border: 1px solid #2f3a45;
    color: #fff;
    font-weight: 500;
    font-size: .92rem;
    padding: .55rem 1.35rem;
    border-radius: 9px;
    transition: background .15s ease, transform .1s ease, box-shadow .15s ease;
}
.bom-submit:hover {
    background: #1f272f;
    color: #fff;
    box-shadow: 0 8px 18px -10px rgba(20,28,38,.8);
}
.bom-submit:active { transform: translateY(1px); }
.bom-submit i { transition: transform .18s ease; }
.bom-submit:hover i { transform: translateX(3px); }

/* ---------------------------------------------------------- Responsive */
@media (max-width: 991.98px) {
    .bom-table-wrap { overflow-x: auto; }
    .bom-table { table-layout: auto; min-width: 780px; }
    .bom-table thead th,
    .bom-section > td { position: static; }
    .bom-desc { white-space: normal; }
}
@media (max-width: 575.98px) {
    .bom-uploader { margin-left: 1rem; margin-right: 1rem; padding: 1.25rem; }
    .bom-toolbar { flex-direction: column; align-items: stretch; }
    .bom-filter { width: 100%; }
    .bom-filter__btn { flex: 1; justify-content: center; }
}
</style>

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
                <span id="setDefaultSmdMessage"></span>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Nie</button>
                <button type="button" class="btn btn-primary" id="setDefaultSmdYes">Tak</button>
            </div>
        </div>
    </div>
</div>
<script src="<?= asset('public_html/components/admin/bom/upload/upload-bom-view.js') ?>"></script>
