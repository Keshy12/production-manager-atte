<?php
/**
 * Formularz przyjęcia towaru — markup only.
 *
 * Fragment dołączany z public_html/components/purchases/receipts/receive-page.php.
 * Wcześniej był OSOBNYM kontenerem najwyższego poziomu (rodzeństwo
 * #receiptsListView), przełączanym klasą `d-none` — miał własną
 * szerokość (`px-lg-4`), własny pasek akcji i zero paddingu
 * „container → row → col → tab-content → tab-pane".
 *
 * Teraz jest renderowany na dedykowanej stronie
 * /purchase/receipts/receive — zewnętrzny wrapper
 * `.container-fluid.px-lg-4.mt-3#receiptsReceiveView` (z atrybutami
 * data-po-id i data-current-user) żyje w receive-page.php, ten plik
 * to czysty fragment HTML: pasek nagłówka .receipt-pagehead,
 * alert-host, wiersz dwóch kart, karta pozycji, pasek akcji.
 *
 * UKŁAD (redesign):
 *   1. Pasek nagłówka .receipt-pagehead — okruszki, numer PO na pierwszym
 *      planie, plakietka stanu, „Wróć do listy" po prawej.
 *   2. Pasek kroków .receipt-stepper (#receiptStepper) — ① Dokument
 *      przyjęcia → ② Pozycje do przyjęcia → ③ Zapisz.
 *   3. Wiersz dwóch kart: wąska „Zamówienie" (dane tylko do odczytu,
 *      pionowa lista etykieta-nad-wartością) + szeroka „Dokument
 *      przyjęcia" (pola do wypełnienia).
 *   4. Karta „Pozycje do przyjęcia" — 9 kolumn zgrupowanych nagłówkiem
 *      nadrzędnym (Pozycja / Stan realizacji / Ile przyjmujesz /
 *      Magazyn i uwagi), żeby kolumny wejściowe odcinały się od danych
 *      do odczytu.
 *   5. Pasek akcji przyklejony do dołu okna.
 *
 * KREATOR (kroki):
 *   Karta „Dokument przyjęcia" to KROK 1 i ma dwa stany w jednym
 *   kontenerze #receiptDocumentCard:
 *     #receiptDocumentEdit    — pola + przycisk „Akceptuję"
 *                               (#receiptAcceptBtn),
 *     #receiptDocumentSummary — podsumowanie tylko do odczytu
 *                               (#receiptSummaryMagazine / VendorDoc /
 *                               Date / Comment), z „Edytuj"
 *                               (#receiptDocumentEditBtn) w nagłówku karty.
 *   Karta pozycji (#receiptLinesCard) to KROK 2 — startuje z klasą
 *   .receipt-step-locked i odblokowuje się po akceptacji kroku 1.
 *   Pasek akcji to KROK 3 — sam przycisk zapisu, bez dodatkowej treści.
 *   Całym przełączaniem steruje receive-page.js (setStep /
 *   showDocumentSummary / showDocumentEdit); markup wnosi tylko stany
 *   początkowe.
 *
 * WYRÓWNANIE PÓL (karta 2) — o to była reklamacja:
 *   każde pole to sztywny trójpak .receipt-field:
 *      .receipt-field-head  (etykieta + ewentualny link akcji, min-height)
 *      kontrolka            (zawsze .form-control / picker o tej samej wysokości)
 *      .receipt-field-hint  (podpowiedź, min-height)
 *   Dzięki min-height na nagłówku i podpowiedzi kontrolki w każdej
 *   kolumnie startują i kończą się na tej samej wysokości, niezależnie
 *   od długości podpowiedzi. Kolumna „Data przyjęcia" nie ma już pustego
 *   miejsca po linku: link przeniósł się do wiersza etykiety, a w miejscu
 *   kontrolki stoi podgląd dzisiejszej daty (.receipt-date-today), który
 *   CSS chowa dokładnie wtedy, gdy JS odsłoni #receivedAtWrap.
 *
 * KONTRAKT z receive-page.js jest nienaruszony — wszystkie ID i klasy
 * (#receiptMagazineSelect jako .selectpicker w #receiptMagazineCell,
 * #toggleReceivedAt/#receivedAtWrap/#receivedAt/#receivedAtHint,
 * #receiveLinesTable z 10 kolumnami — JS renderuje colspan="10" — itd.)
 * zostają bez zmian.
 *
 * Oczekuje $magazines — wiersze z magazine__list (isActive = 1) na
 * picker w nagłówku. Listy przy pozycjach klonuje z nich JS (20+
 * widgetów bootstrap-select w jednej tabeli to niepotrzebny koszt,
 * więc wiersze dostają zwykłe .custom-select).
 */
?>

    <!-- ===== Pasek nagłówka: kontekst zamówienia + powrót ===== -->
    <div class="receipt-pagehead d-flex flex-wrap align-items-center mb-3">
        <div class="mr-auto">
            <div class="receipt-crumbs small text-muted">
                <i class="bi bi-box-arrow-in-down"></i> Przyjęcia
                <i class="bi bi-chevron-right receipt-crumbs-sep"></i>
                Nowe przyjęcie
            </div>
            <h2 class="receipt-pagetitle mb-0">
                Przyjęcie towaru
                <span class="receipt-po-number" id="receivePoNumber"></span>
                <span id="receivePoState"></span>
            </h2>
        </div>
        <button type="button" class="btn btn-outline-secondary" id="receiveBackBtn">
            <i class="bi bi-arrow-left"></i> Wróć do listy
        </button>
    </div>

    <div class="alert-host" id="receiveAlertContainer"></div>

    <!-- ===== Kroki ==================================================
         Formularz prowadzi operatora w trzech krokach zamiast wywalać
         wszystko naraz: 1. dokument przyjęcia → 2. pozycje → 3. zapis.
         <ol> (nie <div>), żeby czytnik ekranu podał „lista, 3 elementy"
         i numer bieżącego kroku. Numer w kółku jest treścią .receipt-step-num
         — po zaakceptowaniu kroku JS podmienia go na ikonę ptaszka
         (receive-page.js → setStep()), więc CSS odpowiada tylko za kolory.
         `aria-current="step"` przestawia JS razem z klasami. -->
    <ol class="receipt-stepper" id="receiptStepper">
        <li class="receipt-step receipt-step-active" data-step="1" aria-current="step">
            <span class="receipt-step-num">1</span>
            <span class="receipt-step-label">Dokument przyjęcia</span>
        </li>
        <li class="receipt-step" data-step="2">
            <span class="receipt-step-num">2</span>
            <span class="receipt-step-label">Pozycje do przyjęcia</span>
        </li>
        <li class="receipt-step" data-step="3">
            <span class="receipt-step-num">3</span>
            <span class="receipt-step-label">Zapisz</span>
        </li>
    </ol>

    <div class="row">

        <!-- ===== Karta 1: dane zamówienia (read-only) ===== -->
        <div class="col-xl-3 col-lg-4 col-12 mb-3">
            <div class="card h-100">
                <div class="card-header py-2">
                    <strong><i class="bi bi-file-earmark-text"></i> Zamówienie</strong>
                </div>
                <div class="card-body py-2">
                    <!-- Pionowa lista etykieta-nad-wartością zamiast siatki
                         col-sm-2 / col-sm-4: w wąskiej kolumnie wartości nie
                         łamią się w połowie, a etykiety czytają się jako
                         podpisy, nie jako druga kolumna danych. -->
                    <dl class="receipt-meta mb-0">
                        <dt>Dostawca</dt>
                        <dd id="receiveVendorName">—</dd>

                        <dt>Nr u dostawcy</dt>
                        <dd id="receiveVendorPoNumber">—</dd>

                        <dt>Oczekiwana dostawa</dt>
                        <dd id="receiveExpectedDelivery">—</dd>

                        <!-- Tylko przy dostawie częściowej: skąd wzięły się
                             przepisane dane w kroku 1. Bez tego operator
                             widzi wypełnioną kartę i nie wie, kto ją
                             wypełnił. <div> w <dl> jest legalny w HTML5. -->
                        <div id="receiveLastReceiptWrap" class="d-none">
                            <dt>Ostatnie przyjęcie</dt>
                            <dd id="receiveLastReceipt">—</dd>
                        </div>

                        <dt>Pozycje</dt>
                        <dd class="mb-0">
                            <span class="badge badge-info" id="receiveLineCount">0</span>
                        </dd>
                    </dl>
                    <!-- Komentarz z zamówienia — bywa tam informacja od kupca
                         („dostawa dzielona", „sprawdzić datę produkcji"), a
                         operator czyta to w momencie przyjmowania towaru. -->
                    <div id="receivePoCommentWrap" class="d-none mt-3">
                        <div class="receipt-po-comment small">
                            <div class="font-weight-bold mb-1">
                                <i class="bi bi-chat-left-text"></i> Komentarz do zamówienia
                            </div>
                            <span id="receivePoComment"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ===== Karta 2: dane dokumentu przyjęcia (KROK 1) =====
             Dwa stany w jednej karcie, przełączane klasą `d-none` przez
             receive-page.js:
               #receiptDocumentEdit    — pola do wypełnienia + „Akceptuję",
               #receiptDocumentSummary — zwięzłe podsumowanie do odczytu.
             Przycisk „Edytuj" w nagłówku karty istnieje tylko w stanie
             podsumowania (startuje z `d-none`). Przy dostawie częściowej
             JS od razu wypełnia pola z ostatniego przyjęcia i pokazuje
             podsumowanie — krok 1 jest wtedy zaliczony z marszu. -->
        <div class="col-xl-9 col-lg-8 col-12 mb-3">
            <div class="card h-100" id="receiptDocumentCard">
                <div class="card-header py-2 d-flex align-items-center">
                    <strong class="mr-auto"><i class="bi bi-journal-plus"></i> Dokument przyjęcia</strong>
                    <button type="button" class="btn btn-sm btn-outline-secondary d-none" id="receiptDocumentEditBtn">
                        <i class="bi bi-pencil"></i> Edytuj
                    </button>
                </div>
                <div class="card-body pb-1" id="receiptDocumentEdit">
                    <div class="form-row">

                        <div class="form-group receipt-field col-xl-4 col-md-6" id="receiptMagazineCell">
                            <div class="receipt-field-head">
                                <label for="receiptMagazineSelect" class="receipt-field-label mb-0">
                                    Magazyn docelowy
                                </label>
                            </div>
                            <!--
                                data-width="100%" + data-container przypięty do tej
                                kolumny to standard repo dla bootstrap-select
                                (AGENTS.md) — picker wypełnia kolumnę, a długie
                                nazwy magazynów przycinają się zamiast ją rozpychać.
                            -->
                            <select id="receiptMagazineSelect" class="selectpicker form-control"
                                    data-live-search="true"
                                    data-width="100%"
                                    data-container="#receiptMagazineCell"
                                    title="Wybierz magazyn...">
                                <?php foreach ($magazines as $m): ?>
                                    <option value="<?= (int)$m['sub_magazine_id'] ?>">
                                        <?= htmlspecialchars($m['sub_magazine_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted receipt-field-hint">
                                Ustawia magazyn w pozycjach, w których nie wybrano innego.
                            </small>
                        </div>

                        <div class="form-group receipt-field col-xl-4 col-md-6">
                            <div class="receipt-field-head">
                                <label for="vendorDocumentNumber" class="receipt-field-label mb-0">
                                    Nr dokumentu dostawcy
                                </label>
                            </div>
                            <input type="text" class="form-control" id="vendorDocumentNumber"
                                   maxlength="100" placeholder="Opcjonalnie" autocomplete="off">
                            <small class="form-text text-muted receipt-field-hint">
                                <i class="bi bi-info-circle"></i> Numer PZ zostanie nadany automatycznie.
                            </small>
                        </div>

                        <!-- Data przyjęcia: pole edycji jest schowane, bo w
                             normalnym obiegu jest to zawsze dzisiaj. Link
                             (#toggleReceivedAt) siedzi w wierszu etykiety, a
                             w miejscu kontrolki stoi podgląd dzisiejszej daty
                             — dzięki temu ta kolumna ma dokładnie tę samą
                             geometrię co dwie poprzednie. Gdy JS zdejmie
                             `d-none` z #receivedAtWrap, CSS chowa podgląd
                             (reguła `#receivedAtWrap:not(.d-none) + …`). -->
                        <div class="form-group receipt-field col-xl-4 col-md-6">
                            <div class="receipt-field-head">
                                <label class="receipt-field-label mb-0">Data przyjęcia</label>
                                <a href="#" id="toggleReceivedAt" class="receipt-field-action small">
                                    <i class="bi bi-calendar-event"></i> Zmień datę przyjęcia
                                </a>
                            </div>
                            <div id="receivedAtWrap" class="d-none">
                                <input type="date" class="form-control" id="receivedAt"
                                       value="<?= date('Y-m-d') ?>">
                            </div>
                            <div class="form-control receipt-date-today">
                                <i class="bi bi-calendar-check"></i> <?= date('Y-m-d') ?>
                            </div>
                            <small class="form-text text-muted receipt-field-hint" id="receivedAtHint">
                                Domyślnie dzisiejsza data.
                            </small>
                        </div>

                        <div class="form-group receipt-field col-12 mb-2">
                            <div class="receipt-field-head">
                                <label for="receiptComment" class="receipt-field-label mb-0">Komentarz</label>
                            </div>
                            <textarea class="form-control" id="receiptComment" rows="2"
                                      placeholder="Opcjonalnie — uwagi do całej dostawy"></textarea>
                        </div>

                    </div>

                    <!-- Domknięcie kroku 1. Dopóki operator tego nie kliknie,
                         karta pozycji jest zablokowana (.receipt-step-locked),
                         a „Zapisz przyjęcie" nieaktywne. -->
                    <div class="receipt-step-accept text-right">
                        <button type="button" class="btn btn-primary" id="receiptAcceptBtn">
                            <i class="bi bi-check2"></i> Akceptuję
                            <i class="bi bi-arrow-right ml-1"></i>
                        </button>
                    </div>
                </div>

                <!-- Stan podsumowania — wartości wstawia JS (showDocumentSummary). -->
                <div class="card-body pb-2 d-none" id="receiptDocumentSummary">
                    <dl class="receipt-summary row mb-0">
                        <dt class="col-sm-3">Magazyn docelowy</dt>
                        <dd class="col-sm-9 mb-1" id="receiptSummaryMagazine">—</dd>

                        <dt class="col-sm-3">Nr dokumentu dostawcy</dt>
                        <dd class="col-sm-9 mb-1" id="receiptSummaryVendorDoc">—</dd>

                        <dt class="col-sm-3">Data przyjęcia</dt>
                        <dd class="col-sm-9 mb-1" id="receiptSummaryDate">—</dd>

                        <dt class="col-sm-3">Komentarz</dt>
                        <dd class="col-sm-9 mb-0" id="receiptSummaryComment">—</dd>
                    </dl>
                    <!-- Widoczne wyłącznie wtedy, gdy krok 1 zaliczył się
                         sam (dostawa częściowa) — dane są przepisane, nie
                         wpisane przez tego operatora. -->
                    <div class="receipt-summary-inherited small d-none mt-2" id="receiptSummaryInherited">
                        <i class="bi bi-info-circle"></i>
                        Dane przepisane z poprzedniego przyjęcia — sprawdź je i w razie potrzeby popraw przyciskiem „Edytuj".
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Karta 3: pozycje (KROK 2) =====
         Startuje zablokowana (.receipt-step-locked — pointer-events: none
         + wygaszenie). Klasę zdejmuje JS w momencie akceptacji kroku 1
         (albo od razu przy dostawie częściowej). -->
    <div class="card receipt-step-locked" id="receiptLinesCard">
        <div class="card-header d-flex flex-wrap align-items-center py-2">
            <strong class="mr-auto">
                <i class="bi bi-list-check"></i> Pozycje do przyjęcia
            </strong>
            <button type="button" class="btn btn-sm btn-outline-primary mr-2" id="receiveFillAllBtn">
                <i class="bi bi-arrow-clockwise"></i> Przyjmij pozostałe
            </button>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="receiveClearAllBtn">
                <i class="bi bi-eraser"></i> Wyczyść wszystko
            </button>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 receipt-lines-table" id="receiveLinesTable">
                    <thead class="thead-light">
                    <!-- Nagłówek nadrzędny: rozdziela 10 kolumn na pięć
                         stref. Pierwsza strefa „Przyjmujesz" to checkbox
                         wyłączający pozycję z tego przyjęcia. Kolumny do
                         wpisywania (Opak./Ilość) przestają wyglądać jak
                         kolejne dane do odczytu. Liczba kolumn danych (10)
                         się NIE zmienia — JS renderuje wiersze i komunikaty
                         z colspan="10". -->
                    <tr class="receipt-group-row">
                        <th scope="colgroup" colspan="1" class="receipt-group receipt-col-toggle" title="Zaznacz pozycje, które chcesz przyjąć w tej dostawie">Przyjmujesz</th>
                        <th scope="colgroup" colspan="2" class="receipt-group">Pozycja</th>
                        <th scope="colgroup" colspan="3" class="receipt-group text-right">Stan realizacji</th>
                        <th scope="colgroup" colspan="2" class="receipt-group receipt-group-input">
                            <i class="bi bi-pencil-square"></i> Ile przyjmujesz
                        </th>
                        <th scope="colgroup" colspan="2" class="receipt-group">Magazyn i uwagi</th>
                    </tr>
                    <tr>
                        <th scope="col" class="receipt-col-toggle"></th>
                        <th scope="col" class="receipt-col-num">Nr dostawcy</th>
                        <th scope="col">Nazwa</th>
                        <th scope="col" class="text-right">Zamówiono</th>
                        <th scope="col" class="text-right">Przyjęto już</th>
                        <th scope="col" class="text-right">Pozostało</th>
                        <th scope="col" class="receipt-col-qty">Opak.</th>
                        <th scope="col" class="receipt-col-qty">Ilość</th>
                        <th scope="col" class="receipt-col-mag">Magazyn</th>
                        <th scope="col" class="receipt-col-note">Uwagi do przyjęcia</th>
                    </tr>
                    </thead>
                    <tbody id="receiveLinesBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ===== Pasek akcji (przyklejony do dołu okna) ===== -->
    <div class="receipt-actionbar bg-white border border-top-0 shadow-sm py-2 px-3 d-flex flex-wrap align-items-center">
        <div class="mr-auto py-1 d-flex align-items-center">
            <i class="bi bi-box-seam receipt-actionbar-icon text-primary mr-2"></i>
            <div>
                <span class="font-weight-bold d-block" id="receiveSummary">Przyjmujesz 0 z 0 pozycji</span>
                <small class="text-muted d-block receipt-actionbar-hint" id="receiveHint"></small>
            </div>
        </div>
        <button type="button" class="btn btn-outline-secondary mr-2" id="receiveCancelBtn">Anuluj</button>
        <button type="button" class="btn btn-success px-4" id="receiveSubmitBtn" disabled>
            <i class="bi bi-check2-circle"></i> Zapisz przyjęcie
        </button>
    </div>
