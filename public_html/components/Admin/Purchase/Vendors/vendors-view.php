<?php
/**
 * Dostawcy — /admin/purchase/vendors.
 *
 * Katalog dostawców. Dane pobiera vendors-view.js z vendor-list.php
 * (rows, total, itemsPerPage, stats). Ten plik renderuje tylko szkielet:
 * nagłówek, kafelki z licznikami, pasek filtrów, linijkę wyników i pusty
 * panel listy.
 *
 * Wygląd: ten sam język co /purchase/documents. receipts-ui.css jest
 * wczytywany jako baza (tylko do odczytu), a vendors-ui.css dokłada to,
 * co jest tylko na tej stronie (wiersze dostawców, komentarz pod nazwą).
 *
 * Liczniki (kafelki pod nagłówkiem) przychodzą w odpowiedzi vendor-list.php
 * jako `stats` — każdy to liczba wyników przy bieżących filtrach, z
 * nadpisanym wyłącznie wymiarem tego kafelka. Klik w kafelek ustawia ten
 * wymiar filtra (status / artykuły / osoby kontaktowe); ponowny klik
 * w aktywny kafelek przywraca jego wartość domyślną.
 *
 * Filtry domyślne: tylko aktywni dostawcy, reszta wyłączona.
 */
use Atte\DB\MsaDB;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

// MsaDB is required by the new AJAX endpoint shape; instantiated here
// so the page-load path stays symmetric with vendor-parts / producers
// (even though the listing is now fully AJAX-driven and reads no DB
// on initial render).
$MsaDB = MsaDB::getInstance();

include('edit/table-row-template.php');

$vrAddUrl = 'http://' . BASEURL . '/admin/purchase/vendors/edit';
?>

<link rel="stylesheet" href="<?= asset('public_html/components/purchases/receipts/receipts-ui.css') ?>">
<link rel="stylesheet" href="<?= asset('public_html/components/Admin/Purchase/Vendors/vendors-ui.css') ?>">

<div class="rx-page vx-page" id="vendorsView">

    <!-- ===== Nagłówek strony ===== -->
    <header class="rx-head">
        <div class="rx-head-main">
            <div class="rx-eyebrow"><i class="bi bi-truck"></i> Zamówienia komponentów</div>
            <h1 class="rx-title">Dostawcy</h1>
            <p class="rx-lead">
                Katalog dostawców z osobami kontaktowymi i&nbsp;powiązaniami z artykułami.
                Nowych dostawców dodasz tutaj, a&nbsp;dane istniejących zmienisz w&nbsp;ich karcie.
            </p>
        </div>
        <div class="vx-head-links">
            <a href="<?= htmlspecialchars($vrAddUrl, ENT_QUOTES) ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-circle"></i> Dodaj dostawcę
            </a>
        </div>
    </header>

    <div id="alertContainer" class="rx-alerts"></div>

    <!-- ===== Liczniki = skróty do filtrów =====
         Wartości wypełnia JS (pole `stats` w odpowiedzi vendor-list.php). -->
    <div class="vx-stats" id="vrStats" role="group" aria-label="Dostawcy według statusu i powiązań">
        <button type="button" class="rx-stat vx-stat--all" data-stat="all"
                data-dim="status" data-value="all" aria-pressed="false"
                title="Wszyscy dostawcy — aktywni i nieaktywni">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Wszyscy</span>
        </button>
        <button type="button" class="rx-stat vx-stat--active" data-stat="active"
                data-dim="status" data-value="active" aria-pressed="false"
                title="Aktywni dostawcy">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Aktywni</span>
        </button>
        <button type="button" class="rx-stat vx-stat--inactive" data-stat="inactive"
                data-dim="status" data-value="inactive" aria-pressed="false"
                title="Nieaktywni dostawcy, w tym wyłączeni automatycznie">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Nieaktywni</span>
        </button>
        <button type="button" class="rx-stat vx-stat--noparts" data-stat="noArticles"
                data-dim="hasArticles" data-value="unused" aria-pressed="false"
                title="Dostawcy bez przypisanych artykułów — przydatne do porządków w katalogu">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Bez artykułów</span>
        </button>
        <button type="button" class="rx-stat vx-stat--nocontact" data-stat="noSuppliers"
                data-dim="hasSuppliers" data-value="unused" aria-pressed="false"
                title="Dostawcy bez żadnej osoby kontaktowej">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Bez osób kontaktowych</span>
        </button>
    </div>

    <!-- ===== Pasek filtrów ===== -->
    <div class="rx-toolbar vx-toolbar" role="search">
        <div class="rx-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" id="vrFilterSearch" class="form-control form-control-sm"
                   placeholder="Szukaj: nazwa, adres, komentarz"
                   title="Szuka w nazwie dostawcy, adresie i komentarzu"
                   aria-label="Szukaj dostawcy" autocomplete="off">
        </div>

        <div class="vx-group">
            <span class="vx-group-label" aria-hidden="true">Status</span>
            <div class="rx-seg" role="group" aria-label="Status dostawcy" id="vrSegStatus">
                <button type="button" data-dim="status" data-value="all" aria-pressed="false">Wszystkie</button>
                <button type="button" data-dim="status" data-value="active" class="is-active" aria-pressed="true">Aktywne</button>
                <button type="button" data-dim="status" data-value="inactive" aria-pressed="false">Nieaktywne</button>
            </div>
        </div>

        <div class="vx-group">
            <span class="vx-group-label" aria-hidden="true">Artykuły</span>
            <div class="rx-seg" role="group" aria-label="Artykuły u dostawcy" id="vrSegArticles">
                <button type="button" data-dim="hasArticles" data-value="all" class="is-active" aria-pressed="true">Wszystkie</button>
                <button type="button" data-dim="hasArticles" data-value="used" aria-pressed="false"
                        title="Dostawca przypisany do co najmniej jednego artykułu">Używane</button>
                <button type="button" data-dim="hasArticles" data-value="unused" aria-pressed="false"
                        title="Brak przypisań — przydatne do porządków w katalogu">Nieużywane</button>
            </div>
        </div>

        <div class="vx-group">
            <span class="vx-group-label" aria-hidden="true">Osoby</span>
            <div class="rx-seg" role="group" aria-label="Osoby kontaktowe" id="vrSegSuppliers">
                <button type="button" data-dim="hasSuppliers" data-value="all" class="is-active" aria-pressed="true">Wszystkie</button>
                <button type="button" data-dim="hasSuppliers" data-value="used" aria-pressed="false"
                        title="Dostawca ma co najmniej jedną osobę kontaktową">Z osobami</button>
                <button type="button" data-dim="hasSuppliers" data-value="unused" aria-pressed="false"
                        title="Brak przypisanych osób kontaktowych">Bez osób</button>
            </div>
        </div>

        <div class="rx-daterange" id="vrDateRange" title="Data dodania dostawcy">
            <i class="bi bi-calendar3" aria-hidden="true"></i>
            <input type="date" id="vrFilterDateFrom" aria-label="Dodano od">
            <span class="rx-daterange-sep">–</span>
            <input type="date" id="vrFilterDateTo" aria-label="Dodano do">
        </div>

        <div class="rx-toolbar-end">
            <button type="button" id="vrRefresh" class="btn btn-sm rx-btn-quiet" title="Wczytaj listę ponownie">
                <i class="bi bi-arrow-clockwise rx-spin-icon"></i>
                <span class="d-none d-sm-inline">Odśwież</span>
            </button>
        </div>
    </div>

    <!-- Linijka wyników + paginacja górna -->
    <div class="rx-filterbar vx-filterbar">
        <div class="vx-summary">
            <span id="vrSummary" aria-live="polite">Wczytywanie dostawców…</span>
            <button type="button" class="rx-link-btn d-none" id="vrClear"
                    title="Przywróć domyślne filtry (tylko aktywni dostawcy)">
                <i class="bi bi-x-circle"></i> Wyczyść filtry
            </button>
        </div>
        <div id="vrPaginationTop"></div>
    </div>

    <!-- ===== Panel z listą ===== -->
    <div class="rx-panel vx-panel" id="vrPanel">
        <div id="vrStatus" aria-live="polite" hidden></div>

        <div class="table-responsive vx-table-wrap" id="vrTableWrap">
            <table class="table rx-table mb-0" id="vrTable">
                <thead>
                    <tr>
                        <th class="vr-col-id">ID</th>
                        <th class="vr-col-name">Dostawca</th>
                        <th class="vr-col-lead">Lead&nbsp;time</th>
                        <th class="vr-col-links">Powiązania</th>
                        <th class="vr-col-status">Status</th>
                        <th class="vr-col-actions">Akcje</th>
                    </tr>
                </thead>
                <tbody id="vrTableBody"></tbody>
            </table>
        </div>
    </div>

    <div id="vrPaginationBottom"></div>
</div>

<script src="<?= asset('public_html/components/Admin/Purchase/Vendors/vendors-view.js') ?>"></script>
