<?php
/**
 * Zapytania i zamówienia — /purchase/documents.
 *
 * Wspólna lista zapytań ofertowych (RFQ) i zamówień (PO). Dane pobiera
 * documents-view.js z documents-table.php (kontrakt endpointu zamrożony:
 * type, vendor_ids[], states[], number, date_from, date_to, page,
 * items_per_page, mode=data|count, snapshot_ts). Ten plik renderuje tylko
 * szkielet: nagłówek, liczniki, pasek filtrów i pusty panel listy.
 *
 * Wygląd: ten sam język co /purchase/receipts. receipts-ui.css jest
 * wczytywany jako baza (tylko do odczytu — pasek filtrów, kafelki,
 * plakietki, stany puste, paginacja), a documents-ui.css dokłada to,
 * co jest tylko na tej stronie (wiersze dokumentów, znacznik RFQ/PO).
 *
 * Liczniki (kafelki pod nagłówkiem) liczy JS przez documents-table.php
 * w trybie mode=count — po jednym zapytaniu na kafelek, z bieżącymi
 * filtrami poza statusem. Klik w kafelek ustawia filtr statusu.
 *
 * Akcje w wierszu:
 *   - kosz („x") → anulowanie/zamknięcie dokumentu (PO wszystkie
 *     nieterminalne stany: draft, sent, confirmed, in_progress,
 *     partially_received; RFQ: draft, sent, responded). Terminalne
 *     stany (PO received/cancelled, RFQ converted/cancelled) kosza
 *     nie mają. Pattern jest ten sam co w kolejce /purchase/receipts
 *     — patrz documents-view.js → closePoBtnHtml(),
 *   - Edytuj / Podgląd jako split-button: etykieta jest linkiem do
 *     dokumentu, a caret otwiera menu z kolejnymi krokami:
 *       szkic                         → Wyślij
 *       RFQ wysłane                   → Oznacz odpowiedź
 *       PO wysłane                    → Potwierdź odpowiedź (strona
 *                                       /admin/purchase/documents/respond?id=N&type=po)
 *       PO potwierdzone               → Potwierdź odbiór (formularz przyjęcia)
 *       PO w trakcie przyjęcia        → Dokończ przyjęcie
 *       PO częściowo odebrane         → Przyjmij towar
 *     Wiersz terminalny (bez pozycji w menu) dostaje zwykły link,
 *     bez careta.
 *
 * Świadomie BEZ samodzielnych przycisków obok siebie — kosz + split
 * Edytuj/Podgląd, nic więcej.
 *
 * Przepływ PO jest trzykrokowy i każdy krok jest niezależnie
 * osiągalny:
 *   sent → „Potwierdź odpowiedź" (dedykowana strona odpowiedzi) → confirmed
 *   confirmed → „Potwierdź odbiór" (formularz przyjęcia) → in_progress
 *   in_progress → „Dokończ przyjęcie" → partially_received / received
 * Strona potwierdzenia odpowiedzi NIE przenosi operatora do formularza
 * przyjęcia — to osobny, późniejszy krok.
 *
 * Domyślny zakres dat: ostatnie 90 dni — widoczny w pasku (przycisk
 * „90 dni" zaznaczony) i w linijce podsumowania.
 */
use Atte\DB\MsaDB;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();

// Aktywni dostawcy do filtra „Dostawca" (jak w cart-view.php).
$activeVendors = $MsaDB->query(
    "SELECT id, name
       FROM `list__vendor`
      WHERE isActive = 1
      ORDER BY name ASC"
);

// Współdzielony modal „Zamknij zamówienie" + (na tej stronie) prosty
// modal „Anuluj zamówienie" — ten sam partial co /purchase/receipts,
// zaimportowany z ../receipts/close-po-modal.php. Przyciski-kosze w
// wierszach (patrz documents-view.js → closePoBtnHtml) otwierają jeden
// z tych modali. close-po.js na podstawie data-back-url odsyła po
// sukcesie z powrotem na /purchase/documents; data-doc-type="rfq"
// przełącza cancelPo() na cancelRfq() i zmienia tekst okna.
//
// $withCancel = true → partial emituje DRUGI modal #receiveCancelPoModal
// przeznaczony dla wierszy PO w stanie `draft`/`sent` oraz RFQ w stanach
// `draft`/`sent`/`responded`.
$withCancel = true;
include __DIR__ . '/../receipts/close-po-modal.php';

$baseUrl = 'http://' . BASEURL;
?>

<link rel="stylesheet" href="<?= asset('public_html/components/purchases/receipts/receipts-ui.css') ?>">
<link rel="stylesheet" href="<?= asset('public_html/components/purchases/documents/documents-ui.css') ?>">

<div class="rx-page dx-page w-75" id="documentsView">

    <!-- ===== Nagłówek strony ===== -->
    <header class="rx-head">
        <div class="rx-head-main">
            <div class="rx-eyebrow"><i class="bi bi-file-earmark-text"></i> Zamówienia komponentów</div>
            <h1 class="rx-title">Zapytania i zamówienia</h1>
            <p class="rx-lead">
                Zapytania ofertowe (RFQ) i zamówienia (PO) w jednym miejscu.
                Nowe dokumenty tworzysz w koszyku.
            </p>
        </div>
        <div class="dx-head-links">
            <a class="btn btn-sm rx-btn-quiet" href="<?= htmlspecialchars($baseUrl . '/purchase/cart', ENT_QUOTES) ?>">
                <i class="bi bi-cart3"></i> Koszyk
            </a>
            <a class="btn btn-sm rx-btn-quiet" href="<?= htmlspecialchars($baseUrl . '/purchase/receipts', ENT_QUOTES) ?>">
                <i class="bi bi-box-arrow-in-down"></i> Przyjęcia
            </a>
        </div>
    </header>

    <div id="alertContainer" class="rx-alerts"></div>

    <!-- ===== Liczniki = skróty do filtra statusu =====
         Wartości wypełnia JS (mode=count, bieżące filtry bez statusu).
         Wskazówka „+N" na końcu paska mówi, ile kafelków jest ukrytych
         i dlaczego — nie zmienia filtrów sama w sobie. -->
    <div class="dx-stats" role="group" aria-label="Dokumenty według statusu">
        <button type="button" class="rx-stat dx-stat--draft" data-stat="draft" aria-pressed="false"
                title="Szkice — dokumenty jeszcze niewysłane do dostawcy">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Szkice</span>
        </button>
        <button type="button" class="rx-stat dx-stat--sent" data-stat="sent" aria-pressed="false"
                title="Wysłane — czekają na odpowiedź lub potwierdzenie dostawcy">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Wysłane</span>
        </button>
        <button type="button" class="rx-stat dx-stat--responded" data-stat="responded" aria-pressed="false"
                title="Zapytania, na które dostawca odpowiedział — można z nich utworzyć zamówienie">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Z odpowiedzią</span>
        </button>
        <button type="button" class="rx-stat dx-stat--converted" data-stat="converted" aria-pressed="false"
                title="Zapytania skonwertowane na zamówienie (PO)">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Skonwertowane</span>
        </button>
        <button type="button" class="rx-stat dx-stat--confirmed" data-stat="confirmed" aria-pressed="false"
                title="Zamówienia potwierdzone przez dostawcę — czekają na odbiór towaru">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Potwierdzone</span>
        </button>
        <button type="button" class="rx-stat dx-stat--in_progress" data-stat="in_progress" aria-pressed="false"
                title="Zamówienia w trakcie przyjęcia — odbiór rozpoczęty, towar jeszcze nie w całości odebrany">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">W trakcie przyjęcia</span>
        </button>
        <button type="button" class="rx-stat dx-stat--received" data-stat="received" aria-pressed="false"
                title="Zamówienia odebrane w całości">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Odebrane</span>
        </button>
        <button type="button" class="rx-stat dx-stat--cancelled" data-stat="cancelled" aria-pressed="false"
                title="Dokumenty anulowane">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Anulowane</span>
        </button>

        <!--
            Wskazówka, że na pasku jest więcej kafelków niż widać.
            Pojawia się, gdy jakiś kafelek jest ukryty (inny typ
            dokumentu albo zakończony status przy włączonym
            „Ukryj zakończone"). Kliknięcie NIE zmienia filtrów —
            tylko mówi, co trzeba zmienić, żeby zobaczyć wszystkie.
        -->
        <button type="button" class="dx-stats-more" data-stat-more hidden
                title="Ukryte karty (0) — kliknij, aby dowiedzieć się, co zmienić">
            <i class="bi bi-plus-lg"></i>
            <span data-stat-more-count>0</span>
        </button>
    </div>

    <!-- ===== Pasek filtrów ===== -->
    <div class="rx-toolbar dx-toolbar" role="search">
        <div class="rx-seg dx-type" role="group" aria-label="Typ dokumentu" id="typeSeg">
            <button type="button" data-type="both" class="is-active" aria-pressed="true">Wszystkie</button>
            <button type="button" data-type="rfq" aria-pressed="false" title="Tylko zapytania ofertowe">Zapytania <span class="dx-seg-tag">RFQ</span></button>
            <button type="button" data-type="po" aria-pressed="false" title="Tylko zamówienia">Zamówienia <span class="dx-seg-tag">PO</span></button>
        </div>

        <div class="rx-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" id="number" class="form-control form-control-sm"
                   placeholder="Nr dokumentu lub nr u dostawcy"
                   title="Szuka w numerach zapytań i zamówień oraz w numerze zamówienia nadanym przez dostawcę"
                   aria-label="Numer dokumentu" autocomplete="off">
        </div>

        <!-- AGENTS.md: data-width="100%" + data-container na komórce
             + min-width: 0 na komórce (.rx-tool w receipts-ui.css). -->
        <div class="rx-tool" id="vendorCell">
            <select id="vendor"
                    class="selectpicker"
                    data-live-search="true"
                    data-actions-box="true"
                    data-selected-text-format="count > 1"
                    data-title="Wszyscy dostawcy"
                    data-style="rx-picker-btn"
                    aria-label="Dostawca"
                    multiple
                    data-width="100%"
                    data-container="#vendorCell">
                <?php foreach ($activeVendors as $v): ?>
                    <option value="<?= (int)$v['id'] ?>"><?= htmlspecialchars($v['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="rx-tool" id="statesCell">
            <select id="states"
                    class="selectpicker"
                    data-actions-box="true"
                    data-selected-text-format="count > 1"
                    data-title="Wszystkie statusy"
                    data-style="rx-picker-btn"
                    aria-label="Status"
                    multiple
                    data-width="100%"
                    data-container="#statesCell">
                <optgroup label="Zapytania i zamówienia">
                    <option value="draft">Szkic</option>
                    <option value="sent">Wysłane</option>
                    <option value="cancelled">Anulowane</option>
                </optgroup>
                <optgroup label="Tylko zapytania (RFQ)">
                    <option value="responded">Odpowiedź</option>
                    <option value="converted">Skonwertowane na PO</option>
                </optgroup>
                <optgroup label="Tylko zamówienia (PO)">
                    <option value="confirmed">Potwierdzone</option>
                    <option value="in_progress">W trakcie przyjęcia</option>
                    <option value="partially_received">Częściowo odebrane</option>
                    <option value="received">Odebrane</option>
                </optgroup>
            </select>
        </div>

        <div class="rx-daterange" id="dateRange" title="Data utworzenia dokumentu">
            <i class="bi bi-calendar3" aria-hidden="true"></i>
            <input type="date" id="dateFrom" aria-label="Utworzone od">
            <span class="rx-daterange-sep">–</span>
            <input type="date" id="dateTo" aria-label="Utworzone do">
        </div>

        <div class="rx-seg" role="group" aria-label="Szybki zakres dat" id="rangeSeg">
            <button type="button" data-range="30">30 dni</button>
            <button type="button" data-range="90" title="Zakres domyślny">90 dni</button>
            <button type="button" data-range="365">Rok</button>
            <button type="button" data-range="all">Wszystkie daty</button>
        </div>

        <div class="rx-toolbar-end">
            <div class="custom-control custom-switch dx-hide-done"
                 title="Ukrywa zapytania skonwertowane na PO, zamówienia odebrane w całości oraz dokumenty anulowane">
                <input type="checkbox" class="custom-control-input" id="hideFinished" checked>
                <label class="custom-control-label" for="hideFinished">Ukryj zakończone</label>
            </div>
            <button type="button" id="refreshDocs" class="btn btn-sm rx-btn-quiet" title="Wczytaj listę ponownie">
                <i class="bi bi-arrow-clockwise rx-spin-icon"></i>
                <span class="d-none d-sm-inline">Odśwież</span>
            </button>
        </div>
    </div>

    <!-- Linijka wyników + paginacja górna -->
    <div class="rx-filterbar dx-filterbar">
        <div class="dx-summary">
            <span id="docsSummary">Wczytywanie dokumentów…</span>
            <button type="button" class="rx-link-btn d-none js-clear-filters" id="clearFilters">
                <i class="bi bi-x-circle"></i> Wyczyść filtry
            </button>
        </div>
        <div id="paginationTop"></div>
    </div>

    <div class="rx-panel dx-panel" id="docsPanel">
        <div id="docsStatus" aria-live="polite"></div>

        <div id="docsListWrap" class="d-none">
            <div class="dx-head" aria-hidden="true">
                <div>Dokument</div>
                <div>Dostawca</div>
                <div>Status</div>
                <div class="text-right">Pozycje</div>
                <div class="text-right">Wartość</div>
                <div>Utworzony</div>
                <div class="text-right">Akcje</div>
            </div>
            <div id="docsTableBody" class="dx-list"></div>
        </div>
    </div>

    <div id="paginationBottom"></div>

    <!--
        Modal „Ukryte karty" — otwierany wskazówką „+N" na końcu paska
        kafelków. Sam NIE zmienia filtrów: pokazuje, które karty są
        ukryte i co trzeba zmienić. Przycisk „Pokaż wszystkie karty"
        zmienia filtry na żądanie operatora.

        Stoi WEWNĄTRZ .dx-page, żeby dziedziczyć style kart (ton, zaokrąglenie,
        znacznik RFQ/PO) — dzięki temu mini-karty w modalu wyglądają
        jak te z paska. Bootstrap 4, modal-dialog-centered; backdrop
        „static", żeby przypadkowy klik obok nie zamknął okna.
    -->
    <div class="modal fade"
         id="docsStatsMoreModal"
         tabindex="-1"
         role="dialog"
         aria-labelledby="docsStatsMoreModalTitle"
         aria-hidden="true"
         data-backdrop="static">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="docsStatsMoreModalTitle">Ukryte karty</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Zamknij">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <!-- Stan 1: informacja o ukrytych kartach -->
                    <div id="docsStatsMoreInfo">
                        <p class="mb-2">Ukryte karty na pasku:</p>
                        <div class="docs-stats-more-cards" id="docsStatsMoreCards"></div>
                        <p class="text-muted small mb-0" id="docsStatsMoreHint"></p>
                    </div>

                    <!-- Stan 2: potwierdzenie zmiany filtrów -->
                    <div id="docsStatsMoreConfirm" class="d-none">
                        <p class="mb-2">Aby pokazać wszystkie karty, zostaną zmienione filtry:</p>
                        <ul class="docs-filters-change mb-2" id="docsStatsMoreChange"></ul>
                        <p class="text-muted small mb-0">
                            Pozostałe filtry zostaną bez zmian, a lista dokumentów zostanie odświeżona.
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" id="docsStatsMoreApply">Pokaż wszystkie karty</button>
                    <button type="button" class="btn btn-outline-secondary d-none" id="docsStatsMoreCancel">Anuluj</button>
                    <button type="button" class="btn btn-primary" id="docsStatsMoreOk">Rozumiem</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= htmlspecialchars(asset('public_html/components/purchases/documents/documents-view.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars(asset('public_html/components/purchases/receipts/close-po.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars(asset('public_html/components/purchases/documents/rfq-convert.js'), ENT_QUOTES) ?>"></script>

<?php
    // Modal „Utworzyć zamówienie z odpowiedzi?" (RFQ → PO) — ten sam
    // partial jest też dołączany w /admin/purchase/documents/edit
    // (jeden egzemplarz na stronę, identyfikatory statyczne). CTA
    // „Utwórz zamówienie z odpowiedzi" jest też emitowany w dropdown-
    // menu w wierszu RFQ `responded` (patrz documents-view.js →
    // actionMenuItemsHtml). Renderowany RAZ, poza pętlą wierszy,
    // bo to singleton modal.
    include __DIR__ . '/rfq-convert-modal.php';
?>
