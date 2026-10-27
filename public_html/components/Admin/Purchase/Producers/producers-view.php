<?php
/**
 * Producenci — /admin/purchase/producers.
 *
 * Katalog producentów komponentów. Dane pobiera producers-view.js
 * z producer-list.php (rows, total, itemsPerPage, stats). Ten plik
 * renderuje tylko szkielet: nagłówek, kafelki z licznikami, pasek
 * filtrów, linijkę wyników i pusty panel listy.
 *
 * Wygląd: ten sam język co /admin/purchase/vendors. receipts-ui.css jest
 * wczytywany jako baza (tylko do odczytu), a producers-ui.css dokłada to,
 * co jest tylko na tej stronie (wiersze producentów, komentarz pod nazwą).
 *
 * Liczniki (kafelki pod nagłówkiem) przychodzą w odpowiedzi
 * producer-list.php jako `stats` — każdy to liczba wyników przy bieżących
 * filtrach, z nadpisanym wyłącznie wymiarem tego kafelka. Klik w kafelek
 * ustawia ten wymiar filtra (status / artykuły); ponowny klik w aktywny
 * kafelek przywraca jego wartość domyślną.
 *
 * Filtry domyślne: tylko aktywni producenci, reszta wyłączona.
 */
use Atte\DB\MsaDB;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

// MsaDB is required by the new AJAX endpoint shape; instantiated here
// so the page-load path stays symmetric with vendor-parts (even though
// the listing is now fully AJAX-driven and reads no DB on initial render).
$MsaDB = MsaDB::getInstance();

include('edit/table-row-template.php');

$prAddUrl = 'http://' . BASEURL . '/admin/purchase/producers/edit';
?>

<link rel="stylesheet" href="<?= asset('public_html/components/purchases/receipts/receipts-ui.css') ?>">
<link rel="stylesheet" href="<?= asset('public_html/components/Admin/Purchase/Producers/producers-ui.css') ?>">

<div class="rx-page prx-page" id="producersView">

    <!-- ===== Nagłówek strony ===== -->
    <header class="rx-head">
        <div class="rx-head-main">
            <div class="rx-eyebrow"><i class="bi bi-building"></i> Zamówienia komponentów</div>
            <h1 class="rx-title">Producenci</h1>
            <p class="rx-lead">
                Katalog producentów komponentów, do których przypisujesz artykuły u&nbsp;dostawców.
                Nowych producentów dodasz tutaj, a&nbsp;dane istniejących zmienisz w&nbsp;ich karcie.
            </p>
        </div>
        <div class="prx-head-links">
            <a href="<?= htmlspecialchars($prAddUrl, ENT_QUOTES) ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-circle"></i> Dodaj producenta
            </a>
        </div>
    </header>

    <div id="alertContainer" class="rx-alerts"></div>

    <!-- ===== Liczniki = skróty do filtrów =====
         Wartości wypełnia JS (pole `stats` w odpowiedzi producer-list.php). -->
    <div class="prx-stats" id="prStats" role="group" aria-label="Producenci według statusu i artykułów">
        <button type="button" class="rx-stat prx-stat--all" data-stat="all"
                data-dim="status" data-value="all" aria-pressed="false"
                title="Wszyscy producenci — aktywni i nieaktywni">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Wszyscy</span>
        </button>
        <button type="button" class="rx-stat prx-stat--active" data-stat="active"
                data-dim="status" data-value="active" aria-pressed="false"
                title="Aktywni producenci">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Aktywni</span>
        </button>
        <button type="button" class="rx-stat prx-stat--inactive" data-stat="inactive"
                data-dim="status" data-value="inactive" aria-pressed="false"
                title="Nieaktywni producenci">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Nieaktywni</span>
        </button>
        <button type="button" class="rx-stat prx-stat--noparts" data-stat="noArticles"
                data-dim="hasArticles" data-value="unused" aria-pressed="false"
                title="Producenci niewykorzystani w żadnym artykule u dostawcy — przydatne do porządków w katalogu">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Bez artykułów</span>
        </button>
    </div>

    <!-- ===== Pasek filtrów ===== -->
    <div class="rx-toolbar prx-toolbar" role="search">
        <div class="rx-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" id="prFilterSearch" class="form-control form-control-sm"
                   placeholder="Szukaj: nazwa, komentarz"
                   title="Szuka w nazwie producenta i komentarzu"
                   aria-label="Szukaj producenta" autocomplete="off">
        </div>

        <div class="prx-group">
            <span class="prx-group-label" aria-hidden="true">Status</span>
            <div class="rx-seg" role="group" aria-label="Status producenta" id="prSegStatus">
                <button type="button" data-dim="status" data-value="all" aria-pressed="false">Wszystkie</button>
                <button type="button" data-dim="status" data-value="active" class="is-active" aria-pressed="true">Aktywne</button>
                <button type="button" data-dim="status" data-value="inactive" aria-pressed="false">Nieaktywne</button>
            </div>
        </div>

        <div class="prx-group">
            <span class="prx-group-label" aria-hidden="true">Artykuły</span>
            <div class="rx-seg" role="group" aria-label="Artykuły u dostawców" id="prSegArticles">
                <button type="button" data-dim="hasArticles" data-value="all" class="is-active" aria-pressed="true">Wszystkie</button>
                <button type="button" data-dim="hasArticles" data-value="used" aria-pressed="false"
                        title="Producent przypisany do co najmniej jednego artykułu u dostawcy">Używane</button>
                <button type="button" data-dim="hasArticles" data-value="unused" aria-pressed="false"
                        title="Brak przypisań — przydatne do porządków w katalogu">Nieużywane</button>
            </div>
        </div>

        <div class="rx-toolbar-end">
            <button type="button" id="prRefresh" class="btn btn-sm rx-btn-quiet" title="Wczytaj listę ponownie">
                <i class="bi bi-arrow-clockwise rx-spin-icon"></i>
                <span class="d-none d-sm-inline">Odśwież</span>
            </button>
        </div>
    </div>

    <!-- Linijka wyników + paginacja górna -->
    <div class="rx-filterbar prx-filterbar">
        <div class="prx-summary">
            <span id="prSummary" aria-live="polite">Wczytywanie producentów…</span>
            <button type="button" class="rx-link-btn d-none" id="prClear"
                    title="Przywróć domyślne filtry (tylko aktywni producenci)">
                <i class="bi bi-x-circle"></i> Wyczyść filtry
            </button>
        </div>
        <div id="prPaginationTop"></div>
    </div>

    <!-- ===== Panel z listą ===== -->
    <div class="rx-panel prx-panel" id="prPanel">
        <div id="prStatus" aria-live="polite" hidden></div>

        <div class="table-responsive prx-table-wrap" id="prTableWrap">
            <table class="table rx-table mb-0" id="prTable">
                <thead>
                    <tr>
                        <th class="pr-col-id">ID</th>
                        <th class="pr-col-name">Producent</th>
                        <th class="pr-col-count">Artykuły</th>
                        <th class="pr-col-status">Status</th>
                        <th class="pr-col-actions">Akcje</th>
                    </tr>
                </thead>
                <tbody id="prTableBody"></tbody>
            </table>
        </div>
    </div>

    <div id="prPaginationBottom"></div>
</div>

<script>
    // Adres strony dodawania — używany w pustym stanie renderowanym przez JS.
    window.PR_ADD_URL = <?= json_encode($prAddUrl) ?>;
</script>
<script src="<?= asset('public_html/components/Admin/Purchase/Producers/producers-view.js') ?>"></script>
