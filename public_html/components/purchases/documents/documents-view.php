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
 *   - Edytuj / Podgląd (zależnie od flagi editable) →
 *     /admin/purchase/documents/edit?id=N&type=rfq|po (edit_url z serwera),
 *   - zamknięcie / anulowanie dokumentu (tylko PO, patrz close-po-btn
 *     w documents-view.js),
 *   - następny krok dokumentacyjny:
 *       szkic       → Wyślij            /admin/purchase/documents/send?id=N&type=…
 *       RFQ wysłane → Oznacz odpowiedź  /admin/purchase/documents/respond?id=N&type=rfq
 *
 * Świadomie NIE ma tu „Potwierdź odbiór" ani „Przyjmij towar" — oba
 * kroki dotyczą obiegu towaru i zostały na stronie edycji dokumentu
 * oraz w kolejce /purchase/receipts. Dlatego modal „Potwierdź odbiór"
 * nie jest już tu wpinany (patrz niżej).
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
// wierszach PO (patrz documents-view.js → rowHtml()) otwierają jeden
// z tych modali — closePoFromReceive() odrzuca `sent`, więc dla
// `sent` PO delegujemy anulowanie przez documents/document-cancel-ajax.php.
//
// $withCancel = true → partial emituje DRUGI modal #receiveCancelPoModal
// przeznaczony dla wierszy PO w stanie `sent`.
$withCancel = true;
include __DIR__ . '/../receipts/close-po-modal.php';

$baseUrl = 'http://' . BASEURL;
?>

<link rel="stylesheet" href="<?= asset('public_html/components/purchases/receipts/receipts-ui.css') ?>">
<link rel="stylesheet" href="<?= asset('public_html/components/purchases/documents/documents-ui.css') ?>">

<div class="rx-page dx-page" id="documentsView">

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
         Wartości wypełnia JS (mode=count, bieżące filtry bez statusu). -->
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
        <button type="button" class="rx-stat dx-stat--progress" data-stat="progress" aria-pressed="false"
                title="Zamówienia potwierdzone lub częściowo odebrane — czekają na towar">
            <span class="rx-stat-value" data-stat-value>–</span>
            <span class="rx-stat-label">Czeka na towar</span>
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
</div>

<script src="<?= htmlspecialchars(asset('public_html/components/purchases/documents/documents-view.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars(asset('public_html/components/purchases/receipts/close-po.js'), ENT_QUOTES) ?>"></script>
