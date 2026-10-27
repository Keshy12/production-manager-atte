<?php
/**
 * Artykuły u dostawców — /admin/purchase/vendor-parts.
 *
 * Lista wariantów „który dostawca sprzedaje który artykuł". Dane pobiera
 * vendor-parts-view.js z vp-list.php (rows, total, itemsPerPage, stats).
 * Ten plik renderuje tylko szkielet: nagłówek, kafelki z licznikami, pasek
 * filtrów, linijkę wyników i pusty panel listy.
 *
 * Wygląd: ten sam język co /admin/purchase/vendors i /purchase/documents.
 * receipts-ui.css jest wczytywany jako baza (tylko do odczytu), a
 * vendor-parts-ui.css dokłada to, co jest tylko na tej stronie.
 *
 * Liczniki (kafelki) przychodzą w odpowiedzi vp-list.php jako `stats` —
 * każdy to liczba wyników przy bieżących filtrach, z nadpisanym wyłącznie
 * wymiarem tego kafelka. Klik w kafelek ustawia ten wymiar (status albo
 * opakowanie); ponowny klik w aktywny kafelek przywraca jego domyślną.
 *
 * Pickery Dostawca / Producent / Część są jednokrotnego wyboru (lista
 * opcji renderowana tu, po stronie serwera). Pierwsza opcja z pustą
 * wartością („Wszyscy dostawcy" itd.) służy do zdjęcia wyboru. Dostawca
 * i producent zawężają się nawzajem przez vp-filter-options.php.
 *
 * Filtry domyślne: tylko aktywne, reszta wyłączona.
 */
use Atte\DB\MsaDB;
use Atte\Utils\ComponentRenderer\SelectRenderer;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();
$selectRenderer = new SelectRenderer($MsaDB);

// Source lists for the filter pickers. Pulled from the same tables the
// LEFT JOINs in VendorPartRepository use, so the picker values always
// correspond to real FKs.
$vendor_list   = $MsaDB->readIdName('list__vendor',   'id', 'name', 'ORDER BY name ASC');
$producer_list = $MsaDB->readIdName('list__producer', 'id', 'name', 'ORDER BY name ASC');

// Część dropdown needs the description next to every part so each
// <option> can render a bootstrap-select `data-subtext` (the canonical
// second-line under each option label). `readIdName` only returns
// id+name, so fetch the trio explicitly.
$parts_rows = $MsaDB->db->query(
    "SELECT id, name, description FROM `list__parts` ORDER BY name ASC",
    \PDO::FETCH_ASSOC
)->fetchAll();
$part_names        = [];
$part_descriptions = [];
foreach ($parts_rows as $pr) {
    $id = (int)$pr['id'];
    $part_names[$id]        = $pr['name'];
    $part_descriptions[$id] = (string)($pr['description'] ?? '');
}

$vpAddUrl = 'http://' . BASEURL . '/admin/purchase/vendor-parts/edit';

include('table-row-template.php');
?>

<link rel="stylesheet" href="<?= asset('public_html/components/purchases/receipts/receipts-ui.css') ?>">
<link rel="stylesheet" href="<?= asset('public_html/components/Admin/Purchase/VendorParts/vendor-parts-ui.css') ?>">

<div class="rx-page vpx-page" id="vendorPartsView">

    <!-- ===== Nagłówek strony ===== -->
    <header class="rx-head">
        <div class="rx-head-main">
            <div class="rx-eyebrow"><i class="bi bi-box-seam"></i> Zamówienia komponentów</div>
            <h1 class="rx-title">Artykuły u dostawców</h1>
            <p class="rx-lead">
                Który dostawca sprzedaje który artykuł, od jakiego producenta i&nbsp;w&nbsp;jakich opakowaniach.
            </p>
        </div>
        <div class="vpx-head-links">
            <a href="<?= htmlspecialchars($vpAddUrl, ENT_QUOTES) ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-circle"></i> Dodaj artykuł
            </a>
        </div>
    </header>

    <div id="alertContainer" class="rx-alerts"></div>

    <!-- ===== Liczniki = skróty do filtrów =====
         Wartości wypełnia JS (pole `stats` w odpowiedzi vp-list.php). -->
    <div class="vpx-stats" id="vpStats" role="group" aria-label="Artykuły według statusu i opakowań">
        <button type="button" class="rx-stat vpx-stat--all" data-stat="all"
                data-dim="status" data-value="all" aria-pressed="false"
                title="Wszystkie artykuły u dostawców — aktywne i nieaktywne">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Wszystkie</span>
        </button>
        <button type="button" class="rx-stat vpx-stat--active" data-stat="active"
                data-dim="status" data-value="active" aria-pressed="false"
                title="Aktywne artykuły">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Aktywne</span>
        </button>
        <button type="button" class="rx-stat vpx-stat--inactive" data-stat="inactive"
                data-dim="status" data-value="inactive" aria-pressed="false"
                title="Nieaktywne artykuły">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Nieaktywne</span>
        </button>
        <button type="button" class="rx-stat vpx-stat--nopack" data-stat="noPacks"
                data-dim="hasPacks" data-value="unused" aria-pressed="false"
                title="Artykuły bez zdefiniowanej ilości w pełnym opakowaniu. Warto uzupełnić ją w karcie artykułu, żeby koszyk liczył paczki poprawnie.">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Bez opakowania</span>
        </button>
    </div>

    <!-- ===== Pasek filtrów (dwa rzędy) ===== -->
    <div class="rx-toolbar vpx-toolbar" role="search">

        <!-- Rząd 1: wyszukiwarka + pickery -->
        <div class="vpx-toolbar-row">
            <div class="rx-search">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" id="vpFilterSearch" class="form-control form-control-sm"
                       placeholder="Szukaj: numer u dostawcy, numer producenta, producent"
                       title="Szuka w numerze artykułu u dostawcy, numerze producenta i nazwie producenta"
                       aria-label="Szukaj artykułu" autocomplete="off">
            </div>

            <!-- AGENTS.md: data-width="100%" + data-container na komórce
                 + min-width: 0 na komórce (.rx-tool w receipts-ui.css). -->
            <div class="rx-tool" id="vpVendorCell">
                <select id="vpFilterVendors"
                        class="selectpicker"
                        data-live-search="true"
                        data-size="10"
                        data-style="rx-picker-btn"
                        data-all-label="Wszyscy dostawcy"
                        aria-label="Dostawca"
                        data-width="100%"
                        data-container="#vpVendorCell">
                    <option value="" data-tokens="wszyscy">Wszyscy dostawcy</option>
                    <?= $selectRenderer->renderArraySelect($vendor_list) ?>
                </select>
            </div>

            <div class="rx-tool" id="vpProducerCell">
                <select id="vpFilterProducers"
                        class="selectpicker"
                        data-live-search="true"
                        data-size="10"
                        data-style="rx-picker-btn"
                        data-all-label="Wszyscy producenci"
                        aria-label="Producent"
                        data-width="100%"
                        data-container="#vpProducerCell">
                    <option value="" data-tokens="wszyscy">Wszyscy producenci</option>
                    <?= $selectRenderer->renderArraySelect($producer_list) ?>
                </select>
            </div>

            <div class="rx-tool" id="vpPartCell">
                <select id="vpFilterParts"
                        class="selectpicker"
                        data-live-search="true"
                        data-size="10"
                        data-style="rx-picker-btn"
                        data-all-label="Wszystkie części"
                        aria-label="Część"
                        data-width="100%"
                        data-container="#vpPartCell">
                    <option value="" data-tokens="wszystkie">Wszystkie części</option>
                    <?= $selectRenderer->renderArraySelectWithSubtext($part_names, $part_descriptions) ?>
                </select>
            </div>
        </div>

        <!-- Rząd 2: status, opakowanie, daty, odśwież -->
        <div class="vpx-toolbar-row">
            <div class="vpx-group">
                <span class="vpx-group-label" aria-hidden="true">Status</span>
                <div class="rx-seg" role="group" aria-label="Status artykułu" id="vpSegStatus">
                    <button type="button" data-dim="status" data-value="all" aria-pressed="false">Wszystkie</button>
                    <button type="button" data-dim="status" data-value="active" class="is-active" aria-pressed="true">Aktywne</button>
                    <button type="button" data-dim="status" data-value="inactive" aria-pressed="false">Nieaktywne</button>
                </div>
            </div>

            <div class="vpx-group">
                <span class="vpx-group-label" aria-hidden="true">Opakowanie</span>
                <div class="rx-seg" role="group" aria-label="Opakowanie artykułu" id="vpSegPacks">
                    <button type="button" data-dim="hasPacks" data-value="all" class="is-active" aria-pressed="true">Wszystkie</button>
                    <button type="button" data-dim="hasPacks" data-value="used" aria-pressed="false"
                            title="Artykuły z zdefiniowaną ilością w pełnym opakowaniu">Z opakowaniem</button>
                    <button type="button" data-dim="hasPacks" data-value="unused" aria-pressed="false"
                            title="Artykuły bez zdefiniowanej ilości w pełnym opakowaniu">Bez opakowania</button>
                </div>
            </div>

            <div class="rx-daterange" id="vpDateRange" title="Data dodania artykułu">
                <i class="bi bi-calendar3" aria-hidden="true"></i>
                <input type="date" id="vpFilterDateFrom" aria-label="Dodano od">
                <span class="rx-daterange-sep">–</span>
                <input type="date" id="vpFilterDateTo" aria-label="Dodano do">
            </div>

            <div class="rx-toolbar-end">
                <button type="button" id="vpRefresh" class="btn btn-sm rx-btn-quiet" title="Wczytaj listę ponownie">
                    <i class="bi bi-arrow-clockwise rx-spin-icon"></i>
                    <span class="d-none d-sm-inline">Odśwież</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Linijka wyników + paginacja górna -->
    <div class="rx-filterbar vpx-filterbar">
        <div class="vpx-summary">
            <span id="vpSummary" aria-live="polite">Wczytywanie artykułów…</span>
            <button type="button" class="rx-link-btn d-none" id="vpClear"
                    title="Przywróć domyślne filtry (tylko aktywne artykuły)">
                <i class="bi bi-x-circle"></i> Wyczyść filtry
            </button>
        </div>
        <div id="vpPaginationTop"></div>
    </div>

    <!-- ===== Panel z listą ===== -->
    <div class="rx-panel vpx-panel" id="vpPanel">
        <div id="vpStatus" aria-live="polite" hidden></div>

        <div class="table-responsive vpx-table-wrap" id="vpTableWrap">
            <table class="table rx-table mb-0" id="vpTable">
                <thead>
                    <tr>
                        <th class="vp-col-id">ID</th>
                        <th class="vp-col-item">Artykuł</th>
                        <th class="vp-col-vendor">Dostawca / Producent</th>
                        <th class="vp-col-packs">Opakowania</th>
                        <th class="vp-col-status">Status</th>
                        <th class="vp-col-actions">Akcje</th>
                    </tr>
                </thead>
                <tbody id="vpTableBody"></tbody>
            </table>
        </div>
    </div>

    <div id="vpPaginationBottom"></div>
</div>

<script src="<?= asset('public_html/components/Admin/Purchase/VendorParts/vendor-parts-view.js') ?>"></script>
