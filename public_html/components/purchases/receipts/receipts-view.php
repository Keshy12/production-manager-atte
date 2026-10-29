<?php
/**
 * Przyjęcia towaru — /purchase/receipts.
 *
 * Lista w dwóch zakładkach:
 *   „Do przyjęcia"     — kolejka zamówień czekających na towar
 *                        (dane z receipts-queue.php, render w JS),
 *   „Historia przyjęć" — zapisane dokumenty GRN. Wiersze NIE są już
 *                        renderowane po stronie PHP — receipts-history.php
 *                        (AJAX) zwraca jedną stronę (25 wierszy), JS
 *                        dokłada kolejne po kliknięciu w pager. Przed
 *                        refaktorem ta zakładka emitowała 3 700+
 *                        wierszy inline (~9.5 MB) przy każdym wejściu
 *                        na stronę — teraz wiersze lecą 25 na raz,
 *                        a pierwsza strona doczytuje się dopiero po
 *                        aktywacji zakładki.
 *
 * Nawigacja:
 *   - przyjęcie towaru: /purchase/receipts/receive?po_id=N
 *     (receive-page.php + receive-page.js),
 *   - szczegóły przyjęcia: /purchase/receipts/view?id=N
 *     (receipt-view.php — osobna strona, zastąpiła dawny modal),
 *   - potwierdzenie odpowiedzi dostawcy (PO w stanie `sent`):
 *     /admin/purchase/documents/respond?id=POID&type=po — operator
 *     wpisuje tam wynegocjowane ceny per pozycja, a zapis przenosi
 *     zamówienie do stanu `confirmed`,
 *   - zakładka historii ma adres /purchase/receipts#historia, więc
 *     „Wróć" ze strony szczegółów ląduje od razu w historii.
 *
 * Po zapisie formularz przyjęcia wraca tu z
 *   ?saved=<doc>&n=<items>&po=<po>&po_state=<state>
 * a receipts-view.js pokazuje komunikat sukcesu i podświetla nowy wiersz
 * historii (klasa .receipt-row-new — wiersz szukany po data-doc).
 *
 * Wygląd: receipts-ui.css (wspólny z receipt-view.php). receipts.css
 * zostaje dla formularza przyjęcia.
 */

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

require_once __DIR__ . '/receipts-helpers.php';

// Widok NIE odpytuje bazy dla zakładki historii — wiersze latają przez
// POST receipts-history.php, paginacja/filtrowanie są po stronie JS.
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

<div class="rx-page" id="receiptsListView">

    <!-- ===== Nagłówek strony + liczniki kolejki ===== -->
    <header class="rx-head">
        <div class="rx-head-main">
            <div class="rx-eyebrow"><i class="bi bi-box-arrow-in-down"></i> Zamówienia komponentów</div>
            <h1 class="rx-title">Przyjęcia towaru</h1>
            <p class="rx-lead">
                Zamówienia czekające na dostawę i historia przyjętych dokumentów.
                Numer GRN nadawany jest automatycznie przy zapisie.
            </p>
        </div>

        <!-- Liczniki są jednocześnie skrótami do filtrów kolejki.
             Wartości wypełnia JS po wczytaniu receipts-queue.php. -->
        <div class="rx-stats" role="group" aria-label="Podsumowanie kolejki">
            <button type="button" class="rx-stat rx-stat--ready" data-stat="ready"
                    title="Pokaż zamówienia gotowe do przyjęcia">
                <span class="rx-stat-value" id="statReady">–</span>
                <span class="rx-stat-label">Gotowe do przyjęcia</span>
            </button>
            <button type="button" class="rx-stat rx-stat--overdue" data-stat="overdue"
                    title="Pokaż tylko dostawy po terminie">
                <span class="rx-stat-value" id="statOverdue">–</span>
                <span class="rx-stat-label">Po terminie</span>
            </button>
            <button type="button" class="rx-stat rx-stat--pending" data-stat="pending"
                    title="Pokaż zamówienia czekające na potwierdzenie dostawcy">
                <span class="rx-stat-value" id="statPending">–</span>
                <span class="rx-stat-label">Czeka na potwierdzenie</span>
            </button>
        </div>
    </header>

    <div id="alertContainer" class="rx-alerts"></div>

    <!-- ===== Zakładki ===== -->
    <ul class="nav rx-tabs" id="receiptsTabs" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" id="queueTabLink" data-toggle="tab"
               href="#queuePane" role="tab" aria-controls="queuePane" aria-selected="true">
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

        <!-- ================= ZAKŁADKA 1: kolejka ================= -->
        <div class="tab-pane fade show active" id="queuePane" role="tabpanel" aria-labelledby="queueTabLink">

            <div class="rx-toolbar" role="search">
                <div class="rx-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="queueSearch" class="form-control form-control-sm"
                           placeholder="Szukaj: nr zamówienia, nr u dostawcy, dostawca"
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
                        <!-- „W trakcie przyjęcia" = operator kliknął Akceptuję
                             w formularzu, ale nie zapisał jeszcze pozycji. -->
                        <option value="in_progress">W trakcie przyjęcia</option>
                        <option value="partially_received">Częściowo odebrane</option>
                    </select>
                </div>

                <input type="checkbox" class="rx-chip-input" id="queueOverdueOnly">
                <label class="rx-chip rx-chip--danger" for="queueOverdueOnly">
                    <i class="bi bi-exclamation-triangle rx-chip-off" aria-hidden="true"></i><i class="bi bi-exclamation-triangle-fill rx-chip-on" aria-hidden="true"></i> Tylko po terminie
                </label>

                <div class="rx-toolbar-end">
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

                <div id="queueTableWrap" class="d-none">
                    <div class="rx-qhead" aria-hidden="true">
                        <div>Zamówienie</div>
                        <div>Dostawca</div>
                        <div>Dostawa</div>
                        <div>Postęp</div>
                        <div>Status</div>
                        <div class="text-right">Akcja</div>
                    </div>
                    <div id="queueBody" class="rx-qlist"></div>
                </div>
            </div>
        </div>

        <!-- ================= ZAKŁADKA 2: historia ================= -->
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

            <div class="rx-panel">
                <div id="receiptsEmptyAlert" class="d-none">
                    <div class="rx-empty">
                        <div class="rx-empty-icon"><i class="bi bi-archive"></i></div>
                        <div class="rx-empty-title">Brak zarejestrowanych przyjęć</div>
                        <div class="rx-empty-text">
                            Przyjęcia pojawią się tutaj po zapisaniu pierwszej dostawy
                            z zakładki „Do przyjęcia".
                        </div>
                    </div>
                </div>

                <div class="table-responsive d-none" id="receiptsTableWrap">
                    <table class="table rx-table mb-0" id="receiptsTable">
                        <thead>
                        <tr>
                            <th scope="col">Dokument</th>
                            <th scope="col">Data przyjęcia</th>
                            <th scope="col">Zamówienie</th>
                            <th scope="col">Dostawca</th>
                            <th scope="col" class="text-right">Pozycje</th>
                            <th scope="col" class="text-right d-none d-md-table-cell">Ilość</th>
                            <th scope="col" class="d-none d-lg-table-cell">Przyjął</th>
                            <th scope="col" class="text-right"><span class="sr-only">Akcje</span></th>
                        </tr>
                        </thead>
                        <tbody id="historyBody">
                        <tr class="receipt-row-receipts-placeholder" aria-hidden="true">
                            <td colspan="8" class="text-center text-muted py-4">
                                <span class="spinner-border spinner-border-sm align-middle" role="status"></span>
                                <span class="ml-2 align-middle">Ładowanie…</span>
                            </td>
                        </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="historyPagination"></div>
        </div>

    </div>
</div>

<script src="<?= asset('public_html/components/purchases/receipts/receipts-view.js') ?>"></script>
<script src="<?= asset('public_html/components/purchases/receipts/close-po.js') ?>"></script>
