<?php
/**
 * Przyjęcia towaru — /purchase/receipts.
 *
 * Lista w trzech zakładkach (w kolejności na ekranie; domyślna jest
 * pierwsza — „Robocze"):
 *   „Robocze"          — szkice przyjęć (delivery-list.php?state=draft),
 *                        „Kontynuuj" wznawia je na stronie przyjęcia.
 *                        To zakładka domyślna — pracownik, który przerwał
 *                        odbiór, wraca tu w pierwszej kolejności.
 *   „Do przyjęcia"     — kolejka POZYCJI czekających na towar,
 *                        pogrupowana wg DOSTAWCY. Karta dostawcy jest
 *                        domyślnie zwinięta i pokazuje tylko nazwę,
 *                        sygnał spóźnienia, liczbę otwartych pozycji i
 *                        postęp; po rozwinięciu — pasek zamówień
 *                        (chipy z menu) i płaską tabelę wszystkich
 *                        otwartych pozycji wg terminu. Jedna akcja na
 *                        dostawcę: „Przyjmij dostawę"
 *                        (/purchase/receipts/new?vendor_id=N —
 *                        delivery-page.php). Dane z receipts-queue.php.
 *                        Na górze zakładki: karty filtrujące (status
 *                        zamówienia) — każda karta to przełącznik filtra
 *                        z widocznym przełącznikiem wł./wył., kilka kart
 *                        może być włączonych naraz. Ten sam wzorzec co
 *                        /purchase/documents (patrz documents-view.js →
 *                        toggleTile).
 *   „Historia przyjęć" — zatwierdzone dostawy (delivery-list.php?
 *                        state=posted). Wiersze renderuje JS po
 *                        pobraniu listy (AJAX, POST), filtr (szukajka
 *                        + zakres dat) działa po stronie klienta.
 *                        Starsze przyjęcia „per zamówienie" (bez
 *                        dostawy) zostały podłączone do dostaw
 *                        (purchase__delivery), więc osobna tabela
 *                        z serwerową paginacją (receipts-history.php)
 *                        nie jest już potrzebna.
 *
 * Nawigacja:
 *   - przyjęcie dostawy: /purchase/receipts/new[?vendor_id=N|?delivery_id=N]
 *     (delivery-page.php + delivery-page.js),
 *   - szczegóły przyjęcia: /purchase/receipts/view?id=N
 *     (receipt-view.php — osobna strona, zastąpiła dawny modal),
 *   - potwierdzenie odpowiedzi dostawcy (PO w stanie `sent`):
 *     /admin/purchase/documents/respond?id=POID&type=po — operator
 *     wpisuje tam wynegocjowane ceny per pozycja, a zapis przenosi
 *     zamówienie do stanu `confirmed`,
 *   - zakładka historii ma adres /purchase/receipts#historia, więc
 *     „Wróć" ze strony szczegółów ląduje od razu w historii.
 *
 * Wygląd: receipts-ui.css (wspólny z receipt-view.php). receipts.css
 * zostaje dla formularza przyjęcia.
 */

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

require_once __DIR__ . '/receipts-helpers.php';

// Widok NIE odpytuje bazy dla zakładki historii — wiersze lecą przez
// POST delivery-list.php (stan „posted"), filtrowanie jest po stronie JS.
// (Klasy OrderReceipt / OrderReceiptRepository ładuje sam endpoint AJAX.)

// Współdzielony modal „Zamknij zamówienie" + (na tej stronie) prosty
// modal „Anuluj zamówienie" — otwierane z kolejki przyciskami-koszami:
//   - data-action="close-po" → #receiveClosePoModal (received/cancelled)
//   - data-action="cancel-po" → #receiveCancelPoModal (cancelled only)
//
// $withCancel = true → partial emituje DRUGI modal #receiveCancelPoModal
// przeznaczony dla wierszy w stanie `sent` (dla których jedynym legalnym
// wynikiem jest anulowanie przez documents/document-cancel-ajax.php).
$withCancel = true;
include __DIR__ . '/close-po-modal.php';

// $receipts już nie istnieje — licznik w badge'u zakładki historii
// wypełnia JS po pierwszym doczytaniu strony.
?>

<link rel="stylesheet" href="<?= asset('public_html/components/purchases/cart/purchases.css') ?>">
<link rel="stylesheet" href="<?= asset('public_html/components/purchases/receipts/receipts-ui.css') ?>">
<link rel="stylesheet" href="<?= asset('public_html/components/purchases/receipts/delivery-ui.css') ?>">

<div class="rx-page w-75" id="receiptsListView" data-new-url="<?= htmlspecialchars('http://' . BASEURL . '/purchase/receipts/new', ENT_QUOTES) ?>">

    <!-- ===== Nagłówek strony ===== -->
    <header class="rx-head">
        <div class="rx-head-main">
            <div class="rx-eyebrow"><i class="bi bi-box-arrow-in-down"></i> Zamówienia komponentów</div>
            <h1 class="rx-title">Przyjęcia towaru</h1>
            <p class="rx-lead">
                Dostawy przyjmujesz wg faktury lub WZ dostawcy — jedna dostawa może
                obejmować kilka zamówień. Numer GRN nadawany jest automatycznie przy zatwierdzeniu.
            </p>
            <div class="dv-actions-top">
                <a class="btn btn-primary dv-new-btn" id="newDeliveryBtn"
                   href="<?= htmlspecialchars('http://' . BASEURL . '/purchase/receipts/new', ENT_QUOTES) ?>">
                    <i class="bi bi-plus-lg"></i> Nowe przyjęcie
                </a>
            </div>
        </div>

        <!-- Liczniki kolejki NIE są tutaj — karty filtrujące (przełączniki
             statusu) stoją w zakładce „Do przyjęcia", tuż nad paskiem
             filtrów. Liczbę zamówień w kolejce widać z każdej zakładki
             w badge'u „Do przyjęcia". -->
    </header>

    <div id="alertContainer" class="rx-alerts"></div>

    <!-- ===== Zakładki ===== -->
    <ul class="nav rx-tabs" id="receiptsTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="draftsTabLink" data-toggle="tab"
               href="#draftsPane" role="tab" aria-controls="draftsPane" aria-selected="true">
                <i class="bi bi-pencil-square"></i> Robocze
                <span class="rx-count" id="draftsCountBadge">…</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="queueTabLink" data-toggle="tab"
               href="#queuePane" role="tab" aria-controls="queuePane" aria-selected="false">
                <i class="bi bi-inbox"></i> Do przyjęcia
                <span class="rx-count" id="queueCountBadge">…</span>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" id="historyTabLink" data-toggle="tab"
               href="#historyPane" role="tab" aria-controls="historyPane" aria-selected="false">
                <i class="bi bi-clock-history"></i> Historia przyjęć
                <span class="rx-count" id="historyCountBadge">…</span>
            </a>
        </li>
    </ul>

    <div class="tab-content">

        <!-- ================= ZAKŁADKA 1: robocze ================= -->
        <div class="tab-pane fade show active" id="draftsPane" role="tabpanel" aria-labelledby="draftsTabLink">

            <div class="rx-filterbar">
                <span id="draftsSummary">Wczytywanie szkiców…</span>
                <a class="btn btn-sm btn-primary dv-new-btn" href="<?= htmlspecialchars('http://' . BASEURL . '/purchase/receipts/new', ENT_QUOTES) ?>">
                    <i class="bi bi-plus-lg"></i> Nowe przyjęcie
                </a>
            </div>

            <div class="rx-panel">
                <div id="draftsStatus" aria-live="polite"></div>
                <div class="table-responsive d-none" id="draftsTableWrap">
                    <table class="table rx-table mb-0" id="draftsTable">
                        <thead>
                        <tr>
                            <th scope="col">Dokument dostawcy</th>
                            <th scope="col">Dostawca</th>
                            <th scope="col">Zamówienia</th>
                            <th scope="col" class="text-right">Pozycje</th>
                            <th scope="col" class="d-none d-lg-table-cell">Założył</th>
                            <th scope="col" class="text-right"><span class="sr-only">Akcje</span></th>
                        </tr>
                        </thead>
                        <tbody id="draftsBody"></tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ================= ZAKŁADKA 2: kolejka ================= -->
        <div class="tab-pane fade" id="queuePane" role="tabpanel" aria-labelledby="queueTabLink">

            <!-- ===== Karty filtrujące =====
                 Dwie grupy, bo to dwa różne wymiary filtra — ten sam
                 wzorzec co dwie grupy kafelków na /admin/purchase/vendors
                 (tam: „Status" z zasadą „co najmniej jeden" i osobna
                 grupa niezależnych kafelków).

                   1. Status zamówienia — Wysłane / Potwierdzone /
                      Przyjęcie w toku: karta to PRZEŁĄCZNIK filtra
                      statusu z widocznym przełącznikiem w prawym górnym
                      rogu (rysowany w CSS). Kilka kart może być
                      włączonych naraz — filtr statusu jest wtedy sumą
                      ich statusów, wybór pozostałych kart zostaje.
                   2. Termin — Po terminie: OSOBNA oś filtra, WYŁĄCZNA
                      z kartami statusu. Włączenie czyści wybór statusów
                      i blokuje karty statusu (przygaszone + tooltip) —
                      dlatego podpis grupy mówi wprost, że są wyłączone.

                 Karta „Po terminie" steruje tym samym przełącznikiem
                 „Tylko po terminie" co pasek filtrów (oba to jedno i to
                 samo wejście).

                 Liczniki liczy JS z pobranej kolejki (receipts-queue.php):
                 każda karta pokazuje, ile zamówień zostało widać po
                 włączeniu TEJ karty (pozostałe filtry — dostawca,
                 szukanie — działają normalnie). -->
            <div class="rx-stat-groups">

                <div class="rx-stat-group rx-stat-group--status">
                    <div class="rx-stat-group-head">
                        <span class="rx-stat-group-label">Status zamówienia</span>
                        <span class="rx-stat-group-hint" data-status-hint>włącz, aby filtrować</span>
                    </div>

                    <div class="rx-stats rx-stats--filter" role="group" aria-label="Filtr statusu zamówienia">
                        <button type="button" class="rx-stat rx-stat--sent" data-stat="sent" aria-pressed="false"
                                title="Zamówienia wysłane do dostawcy — czekają na potwierdzenie">
                            <span class="rx-stat-value" data-stat-value>–</span>
                            <span class="rx-stat-label">Wysłane</span>
                            <span class="rx-stat-sub">czeka na potwierdzenie</span>
                        </button>
                        <button type="button" class="rx-stat rx-stat--confirmed" data-stat="confirmed" aria-pressed="false"
                                title="Zamówienia potwierdzone przez dostawcę — gotowe do przyjęcia">
                            <span class="rx-stat-value" data-stat-value>–</span>
                            <span class="rx-stat-label">Potwierdzone</span>
                            <span class="rx-stat-sub">gotowe do przyjęcia</span>
                        </button>
                        <button type="button" class="rx-stat rx-stat--in_progress" data-stat="in_progress" aria-pressed="false"
                                title="Zamówienia z odbiorem w toku — część towaru już przyjęta">
                            <span class="rx-stat-value" data-stat-value>–</span>
                            <span class="rx-stat-label">Przyjęcie w toku</span>
                            <span class="rx-stat-sub">częściowo odebrane</span>
                        </button>
                    </div>
                </div>

                <div class="rx-stat-group rx-stat-group--late">
                    <div class="rx-stat-group-head">
                        <span class="rx-stat-group-label">Termin</span>
                        <span class="rx-stat-group-hint">wyłącza karty statusu</span>
                    </div>

                    <div class="rx-stats rx-stats--filter" role="group" aria-label="Filtr terminu dostawy">
                        <button type="button" class="rx-stat rx-stat--overdue" data-stat="overdue" aria-pressed="false"
                                title="Zamówienia z otwartymi pozycjami po terminie dostawy — włączenie wyłącza karty statusu">
                            <span class="rx-stat-value" data-stat-value>–</span>
                            <span class="rx-stat-label">Po terminie</span>
                            <span class="rx-stat-sub" data-stat-sub>&nbsp;</span>
                        </button>
                    </div>
                </div>
            </div>

            <div class="rx-toolbar" role="search">
                <div class="rx-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="queueSearch" class="form-control form-control-sm"
                           placeholder="Szukaj: symbol, nazwa, nr zamówienia, dostawca"
                           aria-label="Szukaj w kolejce" autocomplete="off">
                </div>

                <!-- AGENTS.md: data-width="100%" + data-container na komórce
                     + min-width: 0 na komórce (.rx-tool w receipts-ui.css). -->
                <div class="rx-tool" id="queueVendorCell">
                    <select id="queueVendor"
                            class="selectpicker"
                            data-live-search="true"
                            data-actions-box="true"
                            data-selected-text-format="count > 1"
                            data-title="Wszyscy dostawcy"
                            data-style="rx-picker-btn"
                            aria-label="Dostawca"
                            multiple
                            data-width="100%"
                            data-container="#queueVendorCell"></select>
                </div>

                <div class="rx-tool" id="queueStatesCell">
                    <select id="queueStates"
                            class="selectpicker"
                            data-actions-box="true"
                            data-selected-text-format="count > 1"
                            data-title="Wszystkie statusy"
                            data-style="rx-picker-btn"
                            aria-label="Status"
                            multiple
                            data-width="100%"
                            data-container="#queueStatesCell">
                        <option value="sent">Wysłane (czeka na potwierdzenie)</option>
                        <option value="confirmed">Potwierdzone</option>
                        <!-- „Częściowo przyjęte" = operator kliknął Akceptuję
                             w formularzu, ale nie zapisał jeszcze pozycji. -->
                        <option value="in_progress">Częściowo przyjęte</option>
                        <option value="partially_received">Częściowo odebrane</option>
                    </select>
                </div>

                <input type="checkbox" class="rx-chip-input" id="queueOverdueOnly">
                <label class="rx-chip rx-chip--danger" for="queueOverdueOnly"
                       title="Tylko pozycje, których termin dostawy już minął — tryb wyłączny: zdejmuje karty filtra">
                    <i class="bi bi-exclamation-triangle rx-chip-off" aria-hidden="true"></i><i class="bi bi-exclamation-triangle-fill rx-chip-on" aria-hidden="true"></i> Tylko po terminie
                </label>

                <div class="rx-toolbar-end">
                    <!-- Grupy dostawców są domyślnie ZWINIĘTE (patrz
                         receipts-view.js → vendorOpen). Ten przycisk rozwija
                         / zwija wszystkie widoczne po filtrach grupy; jego
                         etykietę przestawia syncExpandAllBtn(). -->
                    <button type="button" id="queueExpandAll" class="btn btn-sm rx-btn-quiet"
                            aria-pressed="false" title="Rozwiń wszystkich dostawców">
                        <i class="bi bi-chevron-bar-expand vq-ea-icon" aria-hidden="true"></i>
                        <span class="d-none d-sm-inline vq-ea-label">Rozwiń wszystkie</span>
                    </button>
                    <button type="button" id="queueRefreshBtn" class="btn btn-sm rx-btn-quiet"
                            title="Wczytaj kolejkę ponownie">
                        <i class="bi bi-arrow-clockwise rx-spin-icon"></i>
                        <span class="d-none d-sm-inline">Odśwież</span>
                    </button>
                </div>
            </div>

            <div class="rx-filterbar">
                <span id="queueFilterSummary">Wczytywanie kolejki…</span>
                <button type="button" class="rx-link-btn d-none js-clear-queue-filters" id="clearQueueFilters">
                    <i class="bi bi-x-circle"></i> Wyczyść filtry
                </button>
            </div>

            <div class="rx-panel">
                <div id="queueStatus" aria-live="polite"></div>

                <!-- Lista nie ma globalnego nagłówka kolumn: jednostką jest
                     POZYCJA, a kolumny opisuje nagłówek tabeli wewnątrz
                     rozwiniętej karty dostawcy (receipts-view.js →
                     itemsTableHtml). Zwinięta lista to same karty. -->
                <div id="queueTableWrap" class="d-none dv-byvendor vq-queue">
                    <div id="queueBody" class="rx-qlist dv-qlist"></div>
                </div>
            </div>
        </div>

        <!-- ================= ZAKŁADKA 3: historia ================= -->
        <div class="tab-pane fade" id="historyPane" role="tabpanel" aria-labelledby="historyTabLink">

            <div class="rx-toolbar" role="search">
                <div class="rx-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="historySearch" class="form-control form-control-sm"
                           placeholder="Szukaj: nr GRN, zamówienie, dostawca, przyjmujący"
                           aria-label="Szukaj w historii" autocomplete="off">
                </div>

                <div class="rx-daterange" id="historyDateRange">
                    <i class="bi bi-calendar3" aria-hidden="true"></i>
                    <input type="date" id="historyDateFrom" aria-label="Data od" title="Data od">
                    <span class="rx-daterange-sep">–</span>
                    <input type="date" id="historyDateTo" aria-label="Data do" title="Data do">
                </div>

                <div class="rx-seg" role="group" aria-label="Szybki zakres dat">
                    <button type="button" data-preset="0">Dziś</button>
                    <button type="button" data-preset="7">7 dni</button>
                    <button type="button" data-preset="30">30 dni</button>
                    <button type="button" data-preset="90">90 dni</button>
                </div>
            </div>

            <div class="rx-filterbar">
                <span id="historyFilterSummary"></span>
                <button type="button" class="rx-link-btn d-none js-clear-history-filters" id="clearHistoryFilters">
                    <i class="bi bi-x-circle"></i> Wyczyść filtry
                </button>
            </div>

            <!-- Zatwierdzone dostawy (jeden wiersz = jedna faktura / WZ). -->
            <div class="rx-panel" id="deliveriesPanel">
                <h2 class="dv-table-sec">Dostawy wg faktur / WZ <small id="deliveriesSecInfo"></small></h2>
                <div id="deliveriesStatus" aria-live="polite"></div>
                <div class="table-responsive d-none" id="deliveriesTableWrap">
                    <table class="table rx-table mb-0" id="deliveriesTable">
                        <thead>
                        <tr>
                            <th scope="col">GRN</th>
                            <th scope="col">Dokument dostawcy</th>
                            <th scope="col">Data przyjęcia</th>
                            <th scope="col">Dostawca</th>
                            <th scope="col" class="d-none d-md-table-cell">Zamówienia</th>
                            <th scope="col" class="text-right">Pozycje</th>
                            <th scope="col" class="d-none d-lg-table-cell">Przyjął</th>
                            <th scope="col" class="text-right"><span class="sr-only">Akcje</span></th>
                        </tr>
                        </thead>
                        <tbody id="deliveriesBody"></tbody>
                    </table>
                    <div class="dv-more d-none" id="deliveriesMore">
                        <button type="button" class="btn btn-sm rx-btn-quiet" id="deliveriesMoreBtn">Pokaż więcej</button>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<script src="<?= asset('public_html/components/purchases/receipts/receipts-view.js') ?>"></script>
<script src="<?= asset('public_html/components/purchases/receipts/close-po.js') ?>"></script>
