<?php
/**
 * Merged RFQ + PO edit page.
 *
 * Wired at /admin/purchase/documents/edit?id=N&type=rfq|po. Replaces
 * the per-type pages (Admin/Purchase/RFQs/rfqs-edit.php and
 * Admin/Purchase/Orders/orders-edit.php) with a single view that
 * branches on `type`:
 *
 *   - Repository + item-repository dispatch (RFQ vs PO)
 *   - Type-specific state badge map (each type has its own states)
 *   - Type-specific header fields (PO has vendor_po_number +
 *     convertedFromRfqId, RFQ has expected_reply_date)
 *   - PO-only "Odebrane" column on the items table
 *   - State guard via PurchaseActionHandler::allowedEditStates() — one
 *     place that decides which states accept item-level mutations
 *
 * Inline editing of qty/price/comment on each row, vendor-part comment
 * inline edit, and the "Dodaj pozycję" cascade picker all come from
 * the RFQ edit page; they're enabled when the doc is in an editable
 * state and disabled otherwise (no edit pencil / trash buttons
 * render for terminal states).
 *
 * Phase 4 wires this file into the router (route + fall-through from
 * the legacy /admin/purchase/rfqs/edit and /admin/purchase/orders/edit
 * URLs). Phase 5 deletes the legacy PHP files.
 */
use Atte\DB\MsaDB;
use Atte\Utils\Purchase\Order\PurchaseActionHandler;
use Atte\Utils\Purchase\Order\RFQRepository;
use Atte\Utils\Purchase\Order\RFQItemRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderRepository;
use Atte\Utils\Purchase\Order\PurchaseOrderItemRepository;
use Atte\Utils\Purchase\Master\CurrencyRepository;

require_once __DIR__ . '/../../../purchases/receipts/receipts-helpers.php';

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$type = (string)($_GET['type'] ?? '');
if (!in_array($type, ['rfq','po'], true)) {
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">Nieprawidłowy typ dokumentu (wymagane ?type=rfq lub ?type=po).</div></div>';
    return;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">Brak id dokumentu.</div></div>';
    return;
}

$MsaDB = MsaDB::getInstance();
if ($type === 'po') {
    $docRepo  = new PurchaseOrderRepository($MsaDB);
    $itemRepo = new PurchaseOrderItemRepository($MsaDB);
} else {
    $docRepo  = new RFQRepository($MsaDB);
    $itemRepo = new RFQItemRepository($MsaDB);
}

$doc = $docRepo->getById($id);
if ($doc === null) {
    $docNoun = $type === 'po' ? 'Zamówienie' : 'Zapytanie';
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">' . $docNoun . ' #' . htmlspecialchars((string)$id) . ' nie istnieje.</div></div>';
    return;
}
$items = $type === 'po' ? $itemRepo->getByPo($id) : $itemRepo->getByRfq($id);

// Per-type field aliases. The repos return different field names for
// the same conceptual fields; normalise here so the view template
// downstream reads from one set of variables.
if ($type === 'po') {
    $docTitle          = 'Zamówienie';
    $docNumber         = $doc->poNumber ?? ('#' . $doc->id);
    $expectedDateLabel = 'Planowana dostawa:';
    $expectedDate      = $doc->expectedDeliveryDate;
    $sentAt            = $doc->sentAt;
    $confirmedAt       = $doc->confirmedAt;
    $vendorPoNumber    = $doc->vendorPoNumber;
    $vendorReference   = null;
    $convertedFromRfqId     = $doc->convertedFromRfqId;
    $convertedFromRfqNumber = $doc->convertedFromRfqNumber;
} else {
    $docTitle          = 'Zapytanie ofertowe';
    $docNumber         = $doc->rfqNumber ?? ('#' . $doc->id);
    $expectedDateLabel = 'Oczekiwana odpowiedź:';
    $expectedDate      = $doc->expectedReplyDate ?? null;
    $sentAt            = $doc->sentAt;
    $confirmedAt       = null;
    $vendorPoNumber    = null;
    $vendorReference   = $doc->vendorReference;
    $convertedFromRfqId     = null;
    $convertedFromRfqNumber = null;
}

// State guard: single source of truth lives on the action handler.
$allowedEdit = in_array($doc->state, PurchaseActionHandler::allowedEditStates($type), true);

// "Wyślij dokument" / "Wyślij ponownie" / "Oznacz odpowiedź" —
// jeden button w prawym górnym rogu headera, obok "Wróć". Mapa
// stanów specyfikacji: draft → Wyślij dokument; PO sent / confirmed
// → Wyślij ponownie; RFQ sent → Oznacz odpowiedź; RFQ responded
// → Wyślij ponownie; inne stany → brak buttona.
//
// Klucz 'action' w tablicy to dyskryminator routingu: PO w stanie
// `sent` triggeruje modal "Potwierdź odbiór" (data-action="confirm-po"),
// RFQ w stanie `sent` przechodzi do dedykowanej strony
// "Oznacz odpowiedź" (data-action="respond-rfq"). Pozostałe przypadki
// prowadzą do klasycznego wizzarda "Wyślij dokument".
$sendButton = null;
if ($type === 'rfq') {
    if ($doc->state === 'draft') {
        $sendButton = ['label' => 'Wyślij dokument',  'icon' => 'bi-send'];
    } elseif ($doc->state === 'sent') {
        // sent → operator rejestruje odpowiedź dostawcy. Dedykowana
        // strona documents-respond.php (single-step) zamiast wizzarda
        // documents-send.php (cztery kroki). Dlatego 'action' ===
        // 'respond-rfq' renderuje <a> do tej strony zamiast domyślnego
        // <a> do wizzarda send.
        $sendButton = ['label' => 'Oznacz odpowiedź',  'icon' => 'bi-reply', 'action' => 'respond-rfq'];
    } elseif ($doc->state === 'responded') {
        $sendButton = ['label' => 'Wyślij ponownie',  'icon' => 'bi-arrow-clockwise'];
    }
} else { // po
    if ($doc->state === 'draft') {
        $sendButton = ['label' => 'Wyślij dokument',  'icon' => 'bi-send'];
    } elseif ($doc->state === 'sent') {
        // sent → confirmation is the natural next step. Re-send is rarely
        // useful before vendor confirmation, so make "Potwierdź odbiór"
        // the primary CTA. Click triggers the shared confirm modal
        // (see confirm-po-modal.php). The 'action' discriminator tells
        // the rendered HTML to emit a <button data-action="confirm-po">
        // instead of the wizard <a>.
        $sendButton = ['label' => 'Potwierdź odbiór', 'icon' => 'bi-check2-square', 'action' => 'confirm-po'];
    } elseif ($doc->state === 'confirmed') {
        $sendButton = ['label' => 'Wyślij ponownie',  'icon' => 'bi-arrow-clockwise'];
    }
}

// Vendor's preferred currency — pre-selects the Waluta picker in the
// "Dodaj pozycję" form. Mirrors the cart pattern (cart-view.php
// lines 13–19 / 47–49): active-currency list comes from list__currency,
// per-vendor default is JOINed from list__vendor.default_currency.
// `getActiveEnsuring()` guarantees the vendor's code is present in the
// dropdown even if it was deactivated — see CurrencyRepository.
$vendorDefaultCurStmt = $MsaDB->db->prepare(
    "SELECT c.code
       FROM `list__vendor` v
  LEFT JOIN `list__currency` c ON c.id = v.default_currency
      WHERE v.id = ?
      LIMIT 1"
);
$vendorDefaultCurStmt->execute([$doc->vendorId]);
$vendorDefaultCurrencyCode = (string)($vendorDefaultCurStmt->fetchColumn() ?: 'PLN');
$currencies = (new CurrencyRepository($MsaDB))->getActiveEnsuring($vendorDefaultCurrencyCode);

// State badge map. Each type has its own state set; keeping both
// maps in one file avoids drift between the two legacy pages.
// Tones map onto the rx-badge--* colours from receipts-ui.css; they
// match rx_state_meta() in receipts-helpers.php so a PO looks the same
// here and on the receipts pages. Labels stay local (see below).
$stateBadgeTone = $type === 'po' ? [
    'draft'              => 'neutral',
    'sent'               => 'violet',
    'confirmed'          => 'blue',
    'partially_received' => 'amber',
    'received'           => 'green',
    'cancelled'          => 'red',
] : [
    'draft'     => 'neutral',
    'sent'      => 'violet',
    'responded' => 'cyan',
    'cancelled' => 'red',
    'converted' => 'green',
];
$stateBadgeLabel = $type === 'po' ? [
    'draft'              => 'szkic',
    'sent'               => 'wysłane',
    'confirmed'          => 'potwierdzone',
    'partially_received' => 'częściowo odebrane',
    'received'           => 'odebrane',
    'cancelled'          => 'anulowane',
] : [
    'draft'     => 'szkic',
    'sent'      => 'wysłane',
    'responded' => 'odpowiedź',
    'cancelled' => 'anulowane',
    'converted' => 'skonwertowane na PO',
];

// State badge in the rx style. Uses this page's own labels (the RFQ set
// differs from rx_state_meta), only the look comes from the rx system.
function dx_state_badge($state, array $tones, array $labels): string {
    return '<span class="rx-badge rx-badge--' . rx_e($tones[$state] ?? 'neutral') . '">'
         . rx_e($labels[$state] ?? $state) . '</span>';
}
function formatQty($v) {
    if ($v === null || $v === '') return '—';
    return rtrim(rtrim(number_format((float)$v, 4, '.', ' '), '0'), '.');
}
function formatPrice($v) {
    if ($v === null || $v === '') return '—';
    return rtrim(rtrim(number_format((float)$v, 4, '.', ' '), '0'), '.');
}
?>
<link rel="stylesheet" href="<?= htmlspecialchars(asset('public_html/components/purchases/receipts/receipts-ui.css'), ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(asset('public_html/components/Admin/Purchase/Documents/documents-edit-ui.css'), ENT_QUOTES) ?>">

<div class="rx-page rx-detail dx-edit dx-edit--<?= $type === 'po' ? 'po' : 'rfq' ?>" id="docEditView">

    <nav aria-label="Ścieżka">
        <ol class="rx-crumbs">
            <li><a href="http://<?= BASEURL ?>/purchase/documents"><i class="bi bi-files"></i> Dokumenty</a></li>
            <li aria-hidden="true"><i class="bi bi-chevron-right"></i></li>
            <li aria-current="page">
                <?= htmlspecialchars($docTitle) ?> <?= htmlspecialchars($docNumber) ?>
            </li>
        </ol>
    </nav>

    <div id="alertContainer" class="mb-2"></div>

    <!-- ===== Arkusz dokumentu: nagłówek, przyciski akcji, dane ===== -->
    <section class="rx-panel rx-sheet mb-3">
        <div class="rx-dochead">
            <div class="rx-dochead-main">
                <div class="rx-eyebrow dx-type-tag"><?= htmlspecialchars($docTitle) ?></div>
                <h1 class="rx-docnum"><?= htmlspecialchars($docNumber) ?></h1>
                <div class="rx-dochead-meta">
                    <?= htmlspecialchars($doc->vendorName ?? '—') ?>
                    <span class="rx-meta-sep">·</span>
                    <span class="text-faint">ID <?= (int)$doc->id ?></span>
                </div>
            </div>

            <div class="rx-dochead-side">
                <?= dx_state_badge($doc->state, $stateBadgeTone, $stateBadgeLabel) ?>
                <!--
                    Przyciski akcji — prawy górny róg arkusza (jak w widoku
                    przyjęcia). Te same przyciski i te same warunki co wcześniej:

                    "Wróć" używa history.back() kiedy jest dokąd wracać (Cart /
                    Documents / direct URL); w ostateczności ląduje na
                    /purchase/documents. Button wysyłki jest warunkowy — patrz
                    mapa stanów przy definicji $sendButton powyżej.

                    PO w stanie `sent` → "Potwierdź odbiór" otwiera współdzielony
                    modal (confirm-po-modal.php), więc 'action' === 'confirm-po'
                    renderuje <button> z data-* zamiast <a> do wizarda.

                    RFQ w stanie `sent` → "Oznacz odpowiedź" prowadzi do
                    documents-respond.php, więc 'action' === 'respond-rfq'
                    renderuje <a> do tej strony zamiast do wizarda send.
                -->
                <div class="rx-dochead-actions rx-no-print">
                    <?php
                        // Przycisk anulowania — NAJLEWIEJ w grupie akcji,
                        // przed "Wyślij"/"Potwierdź odbiór" i "Przyjmij towar".
                        // Kolejność jest tu functionalna: "Wróć" zostaje
                        // skrajnie prawa (wyjście z ekranu), operacja
                        // odwracalna w ramach sesji (zapisz/wyślij) jest
                        // obok, a nieodwracalna (anulowanie) najdalej od
                        // przycisków, po które się sięga reflexowo.
                        //
                        // Który modal? closePoFromReceive() przyjmuje
                        // confirmed/in_progress/partially_received i pozwala
                        // wybrać „Odebrane" albo „Anulowane" — czyli dokładnie
                        // pytanie, które operator ma przy częściowej dostawie.
                        // `sent` i `draft` są poza jego zasięgiem (i tak nie
                        // ma się czego „zamykać jako odebrane"), więc idą
                        // prostym oknem anulowania. Stany terminalne
                        // (received / cancelled / converted) nie dostają
                        // przycisku w ogóle — anulowanie byłoby bezprzedmiotowe.
                        //
                        // $cancelAction: 'close' (wybór stanu) albo 'cancel'.
                        $cancelAction = null;
                        if ($type === 'po') {
                            if (in_array($doc->state, ['confirmed', 'in_progress', 'partially_received'], true)) {
                                $cancelAction = 'close';
                            } elseif (in_array($doc->state, ['draft', 'sent'], true)) {
                                $cancelAction = 'cancel';
                            }
                        } else { // rfq
                            // cancelRfq() odrzuca tylko stany terminalne.
                            if (in_array($doc->state, ['draft', 'sent', 'responded'], true)) {
                                $cancelAction = 'cancel';
                            }
                        }

                        // Agregaty do okna „Zamknij zamówienie". Bez nich
                        // modal pokazałby „przyjęto 0 z 0", co przy
                        // częściowo odebranym zamówieniu jest po prostu
                        // kłamstwem. $items jest już pobrane (linia ~70).
                        $cancelOrdered = 0.0;
                        $cancelReceived = 0.0;
                        $cancelLineCount = 0;
                        if ($type === 'po') {
                            foreach ($items as $ci) {
                                $cancelOrdered   += (float)$ci->quantity;
                                $cancelReceived += (float)$ci->quantityReceived;
                                $cancelLineCount++;
                            }
                        }
                    ?>
                    <?php if ($cancelAction !== null): ?>
                        <?php
                            // Etykieta mówi dokładnie to, co się stanie.
                            // "Zamknij zamówienie" dla wariantu z wyborem
                            // stanu (Odebrane / Anulowane) — tam operator
                            // realnie cofa dokument. W wariancie prostego
                            // anulowania nie ma wyboru, więc nie ma czego
                            // "zamykać": mówimy wprost "Anuluj", a dla RFQ
                            // "Anuluj zapytanie", bo to nie jest zamówienie.
                            //
                            // Ikona jest JEDNA dla obu wariantów — ten sam
                            // gest co na kolejce przyjęć i w pasku akcji
                            // formularza. Różni się tylko etykieta.
                            $cancelLabel = 'Anuluj dokument';
                            if ($cancelAction === 'close') {
                                $cancelLabel = 'Zamknij zamówienie';
                            } elseif ($type === 'rfq') {
                                $cancelLabel = 'Anuluj zapytanie';
                            }
                            $cancelIcon = 'bi-x-square';
                        ?>
                        <button type="button"
                                class="btn btn-sm btn-outline-danger"
                                data-action="<?= $cancelAction === 'close' ? 'close-po' : 'cancel-po' ?>"
                                data-po-id="<?= (int)$doc->id ?>"
                                data-po-number="<?= htmlspecialchars($docNumber, ENT_QUOTES) ?>"
                                data-doc-type="<?= $type ?>"
                                data-back-url="<?= htmlspecialchars('http://' . BASEURL . '/purchase/documents', ENT_QUOTES) ?>"
                                data-ordered-qty="<?= htmlspecialchars((string)$cancelOrdered, ENT_QUOTES) ?>"
                                data-received-qty="<?= htmlspecialchars((string)$cancelReceived, ENT_QUOTES) ?>"
                                data-line-count="<?= (int)$cancelLineCount ?>"
                                title="<?= $cancelAction === 'close'
                                    ? 'Zamknij zamówienie — odebrane albo anulowane'
                                    : $cancelLabel ?>"
                                aria-label="<?= htmlspecialchars($cancelLabel, ENT_QUOTES) ?>">
                            <i class="bi <?= $cancelIcon ?>"></i> <?= htmlspecialchars($cancelLabel, ENT_QUOTES) ?>
                        </button>
                    <?php endif; ?>
                    <?php if ($sendButton !== null): ?>
                        <?php if (($sendButton['action'] ?? '') === 'confirm-po'): ?>
                            <button type="button"
                                    class="btn btn-primary btn-sm"
                                    data-action="confirm-po"
                                    data-po-id="<?= (int)$doc->id ?>"
                                    data-po-number="<?= htmlspecialchars((string)($doc->poNumber ?? ''), ENT_QUOTES) ?>"
                                    data-vendor-name="<?= htmlspecialchars((string)($doc->vendorName ?? ''), ENT_QUOTES) ?>"
                                    data-vendor-po-number="<?= htmlspecialchars((string)($doc->vendorPoNumber ?? ''), ENT_QUOTES) ?>"
                                    data-after-confirm-url="<?= htmlspecialchars('http://' . BASEURL . '/purchase/receipts/receive?po_id=' . (int)$doc->id, ENT_QUOTES) ?>">
                                <i class="<?= htmlspecialchars($sendButton['icon']) ?>"></i>
                                <?= htmlspecialchars($sendButton['label']) ?>
                            </button>
                        <?php elseif (($sendButton['action'] ?? '') === 'respond-rfq'): ?>
                            <a href="http://<?= BASEURL ?>/admin/purchase/documents/respond?id=<?= (int)$doc->id ?>&amp;type=rfq"
                               class="btn btn-primary btn-sm">
                                <i class="<?= htmlspecialchars($sendButton['icon']) ?>"></i>
                                <?= htmlspecialchars($sendButton['label']) ?>
                            </a>
                        <?php else: ?>
                            <a href="http://<?= BASEURL ?>/admin/purchase/documents/send?id=<?= (int)$doc->id ?>&amp;type=<?= htmlspecialchars($type, ENT_QUOTES) ?>"
                               class="btn btn-primary btn-sm">
                                <i class="<?= htmlspecialchars($sendButton['icon']) ?>"></i>
                                <?= htmlspecialchars($sendButton['label']) ?>
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php if ($type === 'po' && in_array($doc->state, ['confirmed', 'partially_received'], true)): ?>
                        <!--
                            Przyjęcie towaru: dostępne dla PO w stanie `confirmed`
                            oraz `partially_received`.
                            Klik prowadzi do /purchase/receipts/receive?po_id=N, gdzie
                            operator wpisuje faktycznie dostarczone ilości per pozycja.
                            Receive handler (PurchaseActionHandler::createReceipt)
                            akceptuje też stan `in_progress` — jeśli chcesz pokazać ten
                            button także w nim, wystarczy dopisać go tu (formularz
                            przyjęcia już dziś go przyjmuje).

                            `partially_received` wróciło tu, bo operator dostawał
                            częściowe zamówienie i ekran nie dawał mu żadnego
                            wyjścia do dokończenia odbioru — a receive handler
                            właśnie na to pozwala. Etykieta zostaje „Przyjmij towar",
                            żeby przycisk wyglądał tak samo niezależnie od stanu
                            (kolejka na /purchase/receipts używa w tym stanie
                            tej samej etykiety).
                        -->
                        <a href="http://<?= BASEURL ?>/purchase/receipts/receive?po_id=<?= (int)$doc->id ?>"
                           class="btn btn-success btn-sm">
                            <i class="bi bi-box-arrow-in-down"></i> Przyjmij towar
                        </a>
                    <?php endif; ?>
                    <?php if ($type === 'rfq' && $doc->state === 'responded'): ?>
                        <!--
                            Konwersja RFQ → PO: dostępna wyłącznie w stanie
                            `responded` (operator odnotował odpowiedź dostawcy,
                            ale jeszcze nie utworzył zamówienia). Po sukcesie
                            handler PurchaseActionHandler::createPoFromRfq()
                            przenosi RFQ do stanu terminalnego `converted`, więc
                            ten button znika po odświeżeniu strony. Ikona
                            bi-cart-check nawiązuje do istniejącego CTA
                            "Utwórz zamówienie" w koszyku (cart-view.js).
                        -->
                        <button type="button"
                                id="rfq-convert-btn"
                                class="btn btn-success btn-sm"
                                data-rfq-id="<?= (int)$id ?>">
                            <i class="bi bi-cart-check"></i> Utwórz zamówienie z odpowiedzi
                        </button>
                    <?php endif; ?>
                    <a href="http://<?= BASEURL ?>/purchase/documents"
                       class="btn btn-sm rx-btn-quiet"
                       onclick="if (history.length > 1) { history.back(); return false; }">
                        <i class="bi bi-arrow-left"></i> Wróć
                    </a>
                </div>
            </div>
        </div>

        <dl class="rx-facts">
            <div class="rx-fact">
                <dt>Dostawca</dt>
                <dd><?= htmlspecialchars($doc->vendorName ?? '—') ?></dd>
            </div>
            <div class="rx-fact">
                <dt><?= htmlspecialchars($type === 'po' ? 'Numer PO' : 'Numer RFQ') ?></dt>
                <dd><?= htmlspecialchars($docNumber !== ('#' . $doc->id) ? $docNumber : '—') ?></dd>
            </div>

            <div class="rx-fact">
                <dt><?= htmlspecialchars(rtrim($expectedDateLabel, ':')) ?></dt>
                <dd>
                    <?php if (in_array($doc->state, ['draft', 'sent'], true)): ?>
                        <!-- Saved on change via document-expected-date-update.php;
                             clearing the field clears the date. -->
                        <input type="date"
                               id="doc-expected-date"
                               class="form-control form-control-sm d-inline-block dx-datefield"
                               style="width: auto;"
                               data-doc-id="<?= (int)$doc->id ?>"
                               value="<?= $expectedDate ? htmlspecialchars(substr($expectedDate, 0, 10), ENT_QUOTES) : '' ?>">
                    <?php else: ?>
                        <?= $expectedDate ? htmlspecialchars($expectedDate) : '—' ?>
                    <?php endif; ?>
                </dd>
            </div>
            <div class="rx-fact">
                <dt>Wysłane</dt>
                <dd><?= htmlspecialchars($sentAt ?? '—') ?></dd>
            </div>

            <?php if ($type === 'po'): ?>
                <div class="rx-fact">
                    <dt>Numer u dostawcy</dt>
                    <dd><?= htmlspecialchars($vendorPoNumber ?? '—') ?></dd>
                </div>
                <?php if ($confirmedAt !== null): ?>
                    <div class="rx-fact">
                        <dt>Potwierdzone</dt>
                        <dd><?= htmlspecialchars($confirmedAt) ?></dd>
                    </div>
                <?php endif; ?>
                <?php if ($convertedFromRfqId): ?>
                    <div class="rx-fact">
                        <dt>Z zapytania</dt>
                        <dd>
                            <a href="http://<?= BASEURL ?>/admin/purchase/documents/edit?id=<?= (int)$convertedFromRfqId ?>&amp;type=rfq">
                                <?= htmlspecialchars($convertedFromRfqNumber ?? ('RFQ #' . $convertedFromRfqId)) ?>
                            </a>
                        </dd>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="rx-fact">
                <dt>Utworzył</dt>
                <dd><?= htmlspecialchars($doc->createdByName ?? ('#' . (int)$doc->createdBy)) ?></dd>
            </div>
            <div class="rx-fact">
                <dt>Utworzone</dt>
                <dd><?= htmlspecialchars($doc->createdAt ?? '—') ?></dd>
            </div>

            <?php if ($type === 'rfq'): ?>
                <!-- Numer oferty u dostawcy (alias r.vendor_reference
                     w RFQRepository::buildSelectJoin()) — pole tylko do
                     odczytu. -->
                <div class="rx-fact">
                    <dt>Numer oferty u dostawcy</dt>
                    <dd><?= htmlspecialchars($vendorReference ?? '—', ENT_QUOTES) ?></dd>
                </div>
            <?php endif; ?>
        </dl>

        <?php if (!empty($doc->comment)): ?>
            <div class="rx-note">
                <div class="rx-note-label"><i class="bi bi-chat-left-text"></i> Komentarz</div>
                <?= htmlspecialchars($doc->comment) ?>
            </div>
        <?php endif; ?>
    </section>

    <!-- ===== Pozycje ===== -->
    <section class="rx-panel mb-3">
        <div class="rx-panel-head">
            <h2 class="rx-panel-title">
                Pozycje
                <span class="rx-count"><?= count($items) ?></span>
            </h2>
            <?php if ($type === 'po'): ?>
                <span class="dx-total">Łącznie: <?= htmlspecialchars(formatQty($itemRepo->sumQuantityReceivedByPo($id))) ?> / <?= htmlspecialchars(formatQty(array_sum(array_map(fn($i) => (float)$i->quantity, $items)))) ?> szt (odebrane / zamówione)</span>
            <?php else: ?>
                <span class="dx-total">Łącznie: <?= count($items) ?> <?= count($items) === 1 ? 'pozycja' : 'pozycji' ?></span>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table rx-table rx-items dx-items mb-0">
                <thead>
                    <tr>
                        <th scope="col">Część</th>
                        <th scope="col">Producent</th>
                        <th scope="col" class="text-right">Ilość</th>
                        <th scope="col" class="text-right">Cena</th>
                        <th scope="col">Waluta</th>
                        <th scope="col" class="text-right">Wartość</th>
                        <?php if ($type === 'po'): ?>
                            <th scope="col" class="text-right">Odebrane</th>
                        <?php endif; ?>
                        <th scope="col">Komentarz pozycji:</th>
                        <?php if ($allowedEdit): ?>
                            <th scope="col" class="text-right">Akcje</th>
                        <?php endif; ?>
                    </tr>
                </thead>
                <?php
                    // Column count for the empty-state row (and for
                    // the JS append-empty-row fallback after delete).
                    // RFQ: 8 with edit, 7 without. PO: 9 with edit,
                    // 8 without (extra Odebrane column).
                    $colspan = $type === 'po'
                        ? ($allowedEdit ? 9 : 8)
                        : ($allowedEdit ? 8 : 7);
                ?>
                <tbody id="doc-items-tbody" data-colspan="<?= $colspan ?>">
                <?php if (empty($items)): ?>
                    <tr><td colspan="<?= $colspan ?>" class="text-center text-muted dx-empty-cell">
                        <div class="rx-empty">
                            <div class="rx-empty-icon"><i class="bi bi-inbox"></i></div>
                            <div class="rx-empty-title">Brak pozycji.</div>
                        </div>
                    </td></tr>
                <?php else: foreach ($items as $i): ?>
                    <tr data-doc-item-id="<?= (int)$i->id ?>"
                        data-vendor-part-id="<?= (int)$i->vendorPartId ?>"
                        data-vp-comment="<?= htmlspecialchars((string)($i->vendorPartComment ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-quantity-unit-id="<?= (int)$i->quantityUnitId ?>"
                        data-quantity="<?= htmlspecialchars((string)$i->quantity) ?>"
                        data-unit-price="<?= $i->unitPrice === null ? '' : htmlspecialchars((string)$i->unitPrice) ?>"
                        data-currency="<?= htmlspecialchars((string)($i->currency ?? 'PLN')) ?>"
                        data-unit-name="<?= htmlspecialchars((string)($i->unitName ?? '')) ?>"
                        data-picked-pack-size="<?= $i->pickedPackSize === null ? '' : htmlspecialchars((string)$i->pickedPackSize) ?>"
                        data-comment="<?= htmlspecialchars((string)($i->comment ?? '')) ?>"
                        data-part-name="<?= htmlspecialchars((string)($i->partName ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-vendor-name="<?= htmlspecialchars((string)($i->vendorName ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-vendor-part-no="<?= htmlspecialchars((string)($i->vendorPartNo ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-producer-part-no="<?= htmlspecialchars((string)($i->producerPartNo ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        data-part-description="<?= htmlspecialchars((string)($i->partDescription ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                        <?php if ($type === 'po'): ?>
                        data-quantity-received="<?= htmlspecialchars((string)$i->quantityReceived) ?>"
                        <?php endif; ?>>
                        <td>
                            <div class="rx-partname dx-partname"><?= htmlspecialchars($i->partName ?? '—') ?></div>
                            <?php
                                $vn = $i->vendorPartNo ?? '';
                                $pn = $i->producerPartNo ?? '';
                                // Nr prod. is rendered in the next (Producer) column - do not duplicate it here.
                                if ($vn): ?>
                                    <div><small class="text-muted">Nr dost.: <span class="font-weight-bold"><?= htmlspecialchars($vn) ?></span></small></div>
                                <?php endif; ?>
                            <?php if (!empty($i->partDescription)): ?>
                                <div><small class="text-muted"><?= htmlspecialchars($i->partDescription) ?></small></div>
                            <?php endif; ?>
                            <?php $vpCmt = $i->vendorPartComment ?? ''; ?>
                            <div class="doc-row-vp-comment-line">
                                <small class="text-muted">
                                    <i class="bi bi-journal-text"></i> Uwagi do artykułu: <?= $vpCmt !== '' ? htmlspecialchars($vpCmt) : '<em>Brak</em>' ?>
                                </small>
                                <button type="button" class="btn btn-link btn-sm p-0 ml-1 doc-row-vp-comment-edit"
                                        data-vp-id="<?= (int)$i->vendorPartId ?>"
                                        title="<?= $vpCmt !== '' ? 'Edytuj uwagi' : 'Dodaj uwagi' ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
                            </div>
                        </td>
                        <td>
                            <?= htmlspecialchars($i->producerName ?? '—') ?>
                            <?php if (!empty($i->producerPartNo)): ?>
                                <div><small class="text-muted"><?= htmlspecialchars($i->producerPartNo) ?></small></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-right doc-cell-qty">
                            <?= htmlspecialchars(formatQty($i->quantity)) ?>
                            <?php
                                // Pack sub-line + badge when pickedPackSize
                                // is set. Mirrors the cart's read-mode
                                // treatment so users see the package
                                // breakdown without entering edit mode.
                                $pickedPack = isset($i->pickedPackSize) ? (float)$i->pickedPackSize : null;
                                if ($pickedPack !== null && $pickedPack > 0):
                                    $qtyVal  = (float)$i->quantity;
                                    $pkgs    = $qtyVal / $pickedPack;
                                    $evenPkgs = abs($pkgs - round($pkgs)) < 1e-9;
                                    $pkgsStr = rtrim(rtrim(number_format($pkgs, 2, '.', ''), '0'), '.');
                            ?>
                                <div>
                                    <small class="<?= $evenPkgs ? 'text-muted' : 'text-warning' ?>">
                                        <?= htmlspecialchars($pkgsStr) ?> opak.
                                    </small>
                                    <span class="badge badge-light border text-monospace ml-1" title="Wybrana wielkość opakowania">
                                        opak. <?= htmlspecialchars(formatQty($pickedPack)) ?>
                                    </span>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($i->unitName)): ?>
                                <div><small class="text-muted"><?= htmlspecialchars($i->unitName) ?></small></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-right doc-cell-price">
                            <?php if ($i->unitPrice !== null): ?>
                                <?= htmlspecialchars(formatPrice($i->unitPrice)) ?>
                                <div><small class="text-muted">/ <?= htmlspecialchars($i->unitName ?? '—') ?></small></div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($i->currency ?? '—') ?></td>
                        <td class="text-right doc-cell-value">
                            <?php if ($i->unitPrice !== null): ?>
                                <?= htmlspecialchars(formatPrice(((float)$i->unitPrice) * (float)$i->quantity)) ?>
                                <div><small class="text-muted"><?= htmlspecialchars($i->currency ?? '—') ?></small></div>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <?php if ($type === 'po'): ?>
                            <td class="text-right doc-cell-received">
                                <?= htmlspecialchars(formatQty($i->quantityReceived ?? 0)) ?>
                                <?php if (!empty($i->unitName)): ?>
                                    <div><small class="text-muted"><?= htmlspecialchars($i->unitName) ?></small></div>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <td class="doc-cell-comment"><?= $i->comment !== null && $i->comment !== '' ? nl2br(htmlspecialchars($i->comment)) : '' ?></td>
                        <?php if ($allowedEdit): ?>
                            <td class="text-right doc-cell-akcje" style="white-space: nowrap;">
                                <button type="button" class="btn btn-sm btn-outline-primary doc-row-edit mr-1" title="Edytuj ilość / cenę / komentarz">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger doc-row-delete" title="Usuń pozycję">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </section>

    <?php if ($allowedEdit): ?>
    <?php
        // ---- Cascade picker data ----
        // Parts: only active parts that have at least one active
        // VendorPart for this document's vendor. Mirrors rfqs-edit.php.
        $partsForVendorStmt = $MsaDB->db->prepare(
            "SELECT DISTINCT p.id, p.name, p.description
               FROM `list__parts` p
               JOIN `list__vendor_part` vp ON vp.parts_id = p.id AND vp.isActive = 1
              WHERE p.isActive = 1
                AND vp.vendor_id = ?
              ORDER BY p.name ASC"
        );
        $partsForVendorStmt->execute([$doc->vendorId]);
        $partsForVendor = $partsForVendorStmt->fetchAll(\PDO::FETCH_ASSOC);

        $vpsForVendorStmt = $MsaDB->db->prepare(
            "SELECT vp.id, vp.parts_id, vp.vendor_part_no, vp.producer_part_no,
                    vp.vendor_jm_id, vp.comment AS private_comment,
                    lp.name AS part_name, lp.description AS part_description,
                    pr.name AS producer_name, u.name AS unit_name
               FROM `list__vendor_part` vp
               JOIN `list__parts`    lp ON lp.id = vp.parts_id
               JOIN `part__unit`     u  ON u.id  = vp.vendor_jm_id
               LEFT JOIN `list__producer` pr ON pr.id = vp.producer_id
              WHERE vp.isActive = 1
                AND vp.vendor_id = ?
              ORDER BY vp.vendor_part_no ASC"
        );
        $vpsForVendorStmt->execute([$doc->vendorId]);
        $vpsForVendor = $vpsForVendorStmt->fetchAll(\PDO::FETCH_ASSOC);

        $packsByVpId = [];
        $vpIdsForPacks = array_map(fn($r) => (int)$r['id'], $vpsForVendor);
        if (!empty($vpIdsForPacks)) {
            $placeholders = implode(',', array_fill(0, count($vpIdsForPacks), '?'));
            $packStmt = $MsaDB->db->prepare(
                "SELECT vendor_part_id,
                        MIN(full_pack_quantity) AS min_pack,
                        GROUP_CONCAT(full_pack_quantity ORDER BY full_pack_quantity ASC) AS packs_csv
                   FROM `list__vendor_part_pack`
                  WHERE vendor_part_id IN ($placeholders)
                  GROUP BY vendor_part_id"
            );
            $packStmt->execute($vpIdsForPacks);
            foreach ($packStmt->fetchAll(\PDO::FETCH_ASSOC) as $pr) {
                $packsByVpId[(int)$pr['vendor_part_id']] = [
                        'packs'   => $pr['packs_csv'] === null ? [] : array_map('floatval', explode(',', $pr['packs_csv'])),
                        'minPack' => $pr['min_pack'] === null ? null : (float)$pr['min_pack'],
                    ];
            }
        }

        $vpsByPart = [];
        foreach ($vpsForVendor as $vp) {
            $pid = (int)$vp['parts_id'];
            $pack = $packsByVpId[(int)$vp['id']] ?? ['packs' => [], 'minPack' => null];
            $vpsByPart[$pid][] = [
                'id'                 => (int)$vp['id'],
                'parts_id'           => $pid,
                'vendor_part_no'     => $vp['vendor_part_no'] ?? '',
                'producer_part_no'   => $vp['producer_part_no'] ?? null,
                'vendor_jm_id'       => (int)$vp['vendor_jm_id'],
                'unit_name'          => $vp['unit_name'] ?? '',
                'part_name'          => $vp['part_name'] ?? '',
                'part_description'   => $vp['part_description'] ?? '',
                'producer_name'      => $vp['producer_name'] ?? null,
                'private_comment'    => $vp['private_comment'] ?? null,
                'pack_quantities'    => $pack['packs'],
                'min_pack'           => $pack['minPack'],
            ];
        }
        $vpsByPartJson = htmlspecialchars(json_encode($vpsByPart), ENT_QUOTES, 'UTF-8');
    ?>
<div class="doc-add-wrapper dx-add"
         data-doc-type="<?= htmlspecialchars($type, ENT_QUOTES, 'UTF-8') ?>"
         data-doc-id="<?= (int)$doc->id ?>"
         data-vendor-id="<?= (int)$doc->vendorId ?>"
         data-vendor-name="<?= htmlspecialchars($doc->vendorName ?? '', ENT_QUOTES, 'UTF-8') ?>"
         data-default-currency="<?= htmlspecialchars($vendorDefaultCurrencyCode, ENT_QUOTES, 'UTF-8') ?>"
         data-vps-by-part="<?= $vpsByPartJson ?>"
         data-add-url="document-item-add.php"
         data-update-url="document-item-update.php"
         data-delete-url="document-item-delete.php"
         data-merge-url="document-item-merge.php">
        <div class="mb-3 rx-no-print">
            <button type="button" class="btn btn-block dx-add-toggle doc-add-toggle" title="Dodaj pozycję">
                <i class="bi bi-plus-circle"></i> Dodaj pozycję
            </button>
        </div>

        <div class="rx-panel dx-add-card mb-3 doc-add-card" style="display: none;">
            <div class="rx-panel-head">
                <h2 class="rx-panel-title"><i class="bi bi-plus-circle"></i> Dodaj pozycję</h2>
                <div class="doc-add-vendor-row">
                    <small class="text-muted">
                        Dostawca: <strong id="doc-add-vendor-name">—</strong>
                    </small>
                </div>
            </div>
            <div class="dx-add-body">
                <div class="form-row">
                    <div class="form-group col-md-12 mb-2" id="doc-add-part-cell" style="min-width: 0;">
                        <label class="small mb-1" for="doc-add-part-select">Część:</label>
                        <select id="doc-add-part-select"
                                class="selectpicker form-control doc-add-part-select"
                                data-live-search="true"
                                data-width="100%"
                                data-container="#doc-add-part-cell"
                                title="Wybierz część…">
                            <?php foreach ($partsForVendor as $p): ?>
                                <option value="<?= (int)$p['id'] ?>"
                                        data-subtext="<?= htmlspecialchars($p['description']) ?>">
                                    <?= htmlspecialchars($p['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-row" id="doc-add-vp-row" style="display: none;">
                    <div class="form-group col-md-6 mb-2" id="doc-add-vp-cell" style="min-width: 0;">
                        <label class="small mb-1" for="doc-add-vendor-part-select">Numer u dostawcy:</label>
                        <select id="doc-add-vendor-part-select"
                                class="selectpicker form-control doc-add-vendor-part-select"
                                data-live-search="true"
                                data-width="100%"
                                data-container="#doc-add-vp-cell"
                                title="Najpierw wybierz część…">
                        </select>
                        <div id="doc-add-vp-summary" class="small text-muted mt-1" style="display: none;"></div>
                    </div>
                    <div class="form-group col-md-3 mb-2" id="doc-add-pack-wrap" style="display: none;">
                        <label class="small mb-1" for="doc-add-packages">Opak.:</label>
                        <input type="number" step="any" min="0" id="doc-add-packages" class="form-control form-control-sm" placeholder="opak.">
                        <small class="text-muted d-block doc-add-pack-size-display mt-1" style="display:none">
                            = 10 szt./opak.
                        </small>
                        <div id="doc-add-pack-stepper-row" class="btn-group btn-group-sm mt-1" role="group" style="display:none">
                            <button type="button" class="btn btn-outline-secondary" id="doc-add-pack-prev" title="Poprzednia wielkość">&minus;</button>
                            <button type="button" class="btn btn-outline-secondary" id="doc-add-pack-value" disabled>10</button>
                            <button type="button" class="btn btn-outline-secondary" id="doc-add-pack-next" title="Następna wielkość">+</button>
                        </div>
                    </div>
                    <div class="form-group col-md-3 mb-2" id="doc-add-qty-wrap" style="display: none;">
                        <label class="small mb-1" for="doc-add-qty">Ilość *</label>
                        <input type="number" step="any" min="0" id="doc-add-qty" class="form-control form-control-sm" required>
                    </div>
                </div>

                <div class="form-row" id="doc-add-price-row" style="display: none;">
                    <div class="form-group col-md-3 mb-2">
                        <label class="small mb-1" for="doc-add-price">Cena</label>
                        <input type="number" step="any" min="0" id="doc-add-price" class="form-control form-control-sm" placeholder="opcjonalnie">
                    </div>
                    <div class="form-group col-md-2 mb-2" id="doc-add-currency-cell" style="min-width: 0;">
                        <label class="small mb-1" for="doc-add-currency">Waluta</label>
                        <select id="doc-add-currency" name="doc-add-currency"
                                class="selectpicker form-control"
                                data-width="100%"
                                data-container="#doc-add-currency-cell"
                                data-live-search="true"
                                title="Wybierz walutę…">
                            <?php foreach ($currencies as $cur):
                                $code = (string)$cur['code'];
                                $name = (string)$cur['name'];
                                // Initial default = vendor's preferred
                                // currency. JS reset sites (resetAddForm /
                                // refreshDocVendorPartRow / selectDocVendorPart)
                                // call setDocCurrency(addState.defaultCurrency)
                                // to re-apply on every cascade reset.
                                $isSelected = ($code === $vendorDefaultCurrencyCode);
                            ?>
                                <option value="<?= htmlspecialchars($code, ENT_QUOTES) ?>"
                                        <?= $isSelected ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($code, ENT_QUOTES) ?> — <?= htmlspecialchars($name, ENT_QUOTES) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group col-md-7 mb-2">
                        <label class="small mb-1" for="doc-add-comment">Komentarz</label>
                        <textarea id="doc-add-comment" class="form-control form-control-sm" rows="1" placeholder="opcjonalnie"></textarea>
                    </div>
                </div>

                <div class="form-row" id="doc-add-actions-row" style="display: none;">
                    <div class="col-12 text-right">
                        <button type="button" class="btn btn-success btn-sm doc-add-save" disabled>
                            <i class="bi bi-check-lg"></i> Zapisz pozycję
                        </button>
                        <button type="button" class="btn btn-sm rx-btn-quiet doc-add-cancel">
                            Wyczyść
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php
        // Przypadek nieedytowalny NIE dostaje już przypisu u dołu. Był
        // czystym szumem dla operatora: stan dokumentu i tak widać na
        // plakietce w nagłówku, brak ołówków przy pozycjach mówi o
        // read-only tak samo wyraźnie, a dla PO w trakcie przyjęcia
        // (partially_received / in_progress) tekst „pozycje są tylko do
        // odczytu" był mylący — dokument jest w trakcie, nie zablokowany.
        // Uwagi o edycji inline zostają tylko tam, gdzie faktycznie można.
    ?>
    <?php if ($allowedEdit): ?>
        <p class="dx-footnote">
            Ilość, cena i komentarz pozycji są edytowalne inline (przycisk ołówka w kolumnie Akcje).
        </p>
    <?php endif; ?>
</div>

<div class="modal fade dx-modal" id="docItemDeleteModal" tabindex="-1" role="dialog" aria-labelledby="docItemDeleteModalTitle" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="docItemDeleteModalTitle">Usunąć pozycję?</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Czy na pewno chcesz usunąć pozycję:</p>
                <div class="border rounded p-2 mb-2 small">
                    <div class="font-weight-bold" id="docItemDeletePartName">—</div>
                    <div class="text-muted" id="docItemDeleteVendorLine" style="display:none"></div>
                    <div class="text-muted" id="docItemDeleteProducerLine" style="display:none"></div>
                    <div class="text-muted" id="docItemDeleteDescLine" style="display:none"></div>
                    <div class="text-muted" id="docItemDeleteCommentLine" style="display:none">
                        <i class="bi bi-journal-text"></i> Komentarz: <span id="docItemDeleteCommentText">—</span>
                    </div>
                </div>
                <p class="text-muted small mb-0" id="docItemDeleteMeta"></p>
                <p class="text-danger small mb-0 mt-2">Tej operacji nie można cofnąć.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-danger" id="docItemDeleteConfirm">Usuń</button>
            </div>
        </div>
    </div>
</div>

<?php if ($type === 'rfq' && $doc->state === 'responded'): ?>
<!--
    Potwierdzenie konwersji RFQ → PO. Otwierane przez #rfq-convert-btn
    (documents-edit.js); dopiero [Utwórz zamówienie] wysyła POST do
    document-rfq-convert-ajax.php.
-->
<div class="modal fade dx-modal" id="rfqConvertModal" tabindex="-1" role="dialog" aria-labelledby="rfqConvertModalTitle" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rfqConvertModalTitle">Utworzyć zamówienie z odpowiedzi?</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Na podstawie zapytania <strong><?= htmlspecialchars($docNumber) ?></strong>
                    zostanie utworzone nowe zamówienie
                    <?php if (!empty($doc->vendorName)): ?>
                        dla dostawcy <strong><?= htmlspecialchars($doc->vendorName) ?></strong>
                    <?php endif; ?>.
                </p>
                <ul class="small text-muted mb-0 pl-3">
                    <li>Pozycje zostaną skopiowane wraz z cenami z odpowiedzi dostawcy.</li>
                    <li>Zapytanie przejdzie w stan „<?= htmlspecialchars($stateBadgeLabel['converted'] ?? 'skonwertowane na PO') ?>” i nie będzie można go już zmieniać.</li>
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-success" id="rfqConvertConfirm">
                    <i class="bi bi-cart-check"></i> Utwórz zamówienie
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!--
    Merge-conflict confirmation modal. Shown when the "Dodaj pozycję"
    save POST finds an exact-match duplicate row on the doc
    (same vendor_part_id / currency / quantity_unit_id / unit_price).
    Three buttons, all dispatched from documents-edit.js:
      [Anuluj]           — close the modal, leave the form alone
      [Dodaj mimo to]    — re-POST the add payload with force_insert=1
      [Połącz]           — POST to document-item-merge.php to atomically
                           add the proposed qty to the existing row
    Placeholders are filled by the populateMergeModal(resp) JS helper;
    see documents-edit.js.
-->
<div class="modal fade dx-modal" id="docMergeModal" tabindex="-1" role="dialog" aria-labelledby="docMergeModalTitle" aria-hidden="true"
     data-existing-item-id=""
     data-existing-qty=""
     data-existing-vp-no=""
     data-existing-price=""
     data-existing-currency=""
     data-proposed-qty="">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="docMergeModalTitle">Pozycja już istnieje na dokumencie</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p id="docMergeModalBody" class="mb-2"></p>
                <div class="border rounded p-2 mb-2 small">
                    <div class="font-weight-bold" id="docMergeModalPartName">—</div>
                    <div class="text-muted" id="docMergeModalPriceLine"></div>
                    <div class="text-muted" id="docMergeModalQuantityLine"></div>
                </div>
                <p class="text-muted small mb-0">
                    Połączenie sumuje ilości w jedną pozycję. Dodanie mimo to tworzy osobny wiersz z tą samą ceną.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary doc-merge-cancel" data-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-warning doc-merge-force">Dodaj mimo to</button>
                <button type="button" class="btn btn-primary doc-merge-confirm">Połącz</button>
            </div>
        </div>
    </div>
</div>

<!-- Inline `<style>` block keeps the .packages-uneven rule scoped to
     this component without adding a new asset. Mirrors cart's
     purchases.css lines 38–46 and the legacy rfqs-edit.php. -->
<style>
    .packages-uneven {
        border-color: #ffc107;
        background-color: #fff8e1;
    }
    .packages-uneven:focus {
        border-color: #ffb300;
        box-shadow: 0 0 0 .2rem rgba(255,193,7,.25);
    }
</style>

<script src="<?= htmlspecialchars(asset('public_html/assets/js/shared/items-format.js'), ENT_QUOTES) ?>"></script>
<script src="<?= htmlspecialchars(asset('public_html/components/Admin/Purchase/Documents/documents-edit.js')) ?>"></script>

<?php
    // Współdzielony modal "Potwierdź odbiór PO" — renderowany RAZ na
    // stronie, bo JS jest zdarzeniowy (delegacja na document). Dwa
    // miejsca wpinania: tu (header CTA na PO w stanie `sent`) oraz
    // /purchase/receipts (kolejka). Ścieżka liczona względem __DIR__
    // dokumentu (Admin/Purchase/Documents/) — trzy poziomy w górę do
    // public_html/components/, potem w dół do purchases/documents/.
    include __DIR__ . '/../../../purchases/documents/confirm-po-modal.php';
?>
<link rel="stylesheet" href="<?= htmlspecialchars(asset('public_html/components/purchases/documents/confirm-po-modal.css'), ENT_QUOTES) ?>">
<script src="<?= htmlspecialchars(asset('public_html/components/purchases/documents/confirm-po-modal.js'), ENT_QUOTES) ?>"></script>

<?php
    // Okna „Zamknij zamówienie" / „Anuluj dokument" — te same, co na
    // kolejce przyjęć i na liście dokumentów (jeden partial, trzy strony).
    // $withCancel = true, bo przycisk w headerze potrzebuje obu wariantów:
    // wyboru stanu dla confirmed/in_progress/partially_received oraz
    // prostego potwierdzenia dla draft/sent i dla RFQ.
    $withCancel = true;
    include __DIR__ . '/../../../purchases/receipts/close-po-modal.php';
?>
<script src="<?= htmlspecialchars(asset('public_html/components/purchases/receipts/close-po.js'), ENT_QUOTES) ?>"></script>
