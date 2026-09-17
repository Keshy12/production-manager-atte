<?php
/**
 * Formularz przyjęcia towaru — markup only.
 *
 * To OSOBNY kontener najwyższego poziomu (rodzeństwo #receiptsListView),
 * nie panel wewnątrz zakładki. Dzięki temu:
 *   - deklaruje własną szerokość (`px-lg-4` — praktycznie pełna, bo
 *     tabela ma 9 kolumn, z czego 4 to pola do wpisywania),
 *   - nie siedzi w paddingu container → row → col → tab-content →
 *     tab-pane, więc nie trzeba na czas edycji zdejmować `w-75`
 *     z kontenera strony (poprzednia wersja to robiła),
 *   - przełączanie widoków to samo `d-none` na dwóch elementach.
 *
 * Nadal panel „w miejscu", nie modal: dostawa regularnie ma 20+ pozycji,
 * a modal dokłada drugi pasek przewijania nad przewijaniem strony.
 * Tutaj strona przewija się raz, a pasek akcji trzyma się dołu okna
 * (position: sticky, receipts.css).
 *
 * Nagłówek powtarza słownictwo /admin/purchase/documents/edit: h2 z
 * numerem dokumentu i plakietką stanu po lewej, akcje po prawej.
 *
 * Oczekuje $magazines — wiersze z magazine__list (isActive = 1) na
 * picker w nagłówku. Listy przy pozycjach klonuje z nich JS (20+
 * widgetów bootstrap-select w jednej tabeli to niepotrzebny koszt,
 * więc wiersze dostają zwykłe .custom-select).
 */
?>
<div class="container-fluid px-lg-4 mt-3 d-none" id="receiptsReceiveView">

    <!-- ===== Nagłówek: kontekst zamówienia + powrót ===== -->
    <div class="d-flex justify-content-between align-items-center flex-wrap mb-3">
        <h2 class="mb-0">
            <i class="bi bi-box-arrow-in-down"></i>
            Przyjęcie towaru
            <span class="font-weight-bold" id="receivePoNumber"></span>
            <span id="receivePoState"></span>
        </h2>
        <button type="button" class="btn btn-outline-secondary btn-sm" id="receiveBackBtn">
            <i class="bi bi-arrow-left"></i> Wróć do listy
        </button>
    </div>

    <div class="alert-host" id="receiveAlertContainer"></div>

    <!-- ===== Karta 1: dane zamówienia (read-only) + dane dokumentu ===== -->
    <div class="card mb-3">
        <div class="card-header">
            <strong><i class="bi bi-file-earmark-text"></i> Zamówienie</strong>
        </div>
        <div class="card-body py-2">
            <dl class="row mb-0 small">
                <dt class="col-sm-2">Dostawca:</dt>
                <dd class="col-sm-4 mb-1" id="receiveVendorName">—</dd>
                <dt class="col-sm-2">Nr u dostawcy:</dt>
                <dd class="col-sm-4 mb-1" id="receiveVendorPoNumber">—</dd>

                <dt class="col-sm-2">Oczekiwana dostawa:</dt>
                <dd class="col-sm-4 mb-1" id="receiveExpectedDelivery">—</dd>
                <dt class="col-sm-2">Pozycje:</dt>
                <dd class="col-sm-4 mb-1">
                    <span class="badge badge-info" id="receiveLineCount">0</span>
                </dd>
            </dl>
            <!-- Komentarz z zamówienia — bywa tam informacja od kupca
                 („dostawa dzielona", „sprawdzić datę produkcji"), a
                 operator czyta to w momencie przyjmowania towaru. -->
            <div id="receivePoCommentWrap" class="d-none mt-2">
                <div class="alert alert-light border mb-0 py-2 small">
                    <strong><i class="bi bi-chat-left-text"></i> Komentarz do zamówienia:</strong>
                    <span id="receivePoComment"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Karta 2: dane dokumentu przyjęcia ===== -->
    <div class="card mb-3">
        <div class="card-header">
            <strong><i class="bi bi-journal-plus"></i> Dokument przyjęcia</strong>
        </div>
        <div class="card-body pb-2">
            <div class="form-row">
                <div class="form-group col-lg-4" id="receiptMagazineCell">
                    <label for="receiptMagazineSelect" class="mb-1">Magazyn docelowy</label>
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
                    <small class="form-text text-muted">
                        Ustawia magazyn w pozycjach, w których nie wybrano innego.
                    </small>
                </div>

                <div class="form-group col-lg-4">
                    <label for="vendorDocumentNumber" class="mb-1">Nr dokumentu dostawcy</label>
                    <input type="text" class="form-control" id="vendorDocumentNumber"
                           maxlength="100" placeholder="Opcjonalnie" autocomplete="off">
                    <small class="form-text text-muted">
                        <i class="bi bi-info-circle"></i> Numer PZ zostanie nadany automatycznie.
                    </small>
                </div>

                <!-- Data przyjęcia: schowana, bo w normalnym obiegu jest to
                     zawsze dzisiaj. Link odsłania pole tylko wtedy, gdy
                     ktoś naprawdę wpisuje dostawę wstecz. -->
                <div class="form-group col-lg-4">
                    <label class="mb-1 d-block">Data przyjęcia</label>
                    <a href="#" id="toggleReceivedAt" class="small text-muted d-inline-block mb-1">
                        <i class="bi bi-calendar-event"></i> Zmień datę przyjęcia
                    </a>
                    <div id="receivedAtWrap" class="d-none">
                        <input type="date" class="form-control" id="receivedAt"
                               value="<?= date('Y-m-d') ?>">
                    </div>
                    <small class="form-text text-muted" id="receivedAtHint">
                        Domyślnie dzisiejsza data.
                    </small>
                </div>
            </div>

            <div class="form-group mb-2">
                <label for="receiptComment" class="mb-1">Komentarz</label>
                <textarea class="form-control" id="receiptComment" rows="2"
                          placeholder="Opcjonalnie — uwagi do całej dostawy"></textarea>
            </div>
        </div>
    </div>

    <!-- ===== Karta 3: pozycje ===== -->
    <div class="card">
        <div class="card-header d-flex flex-wrap align-items-center">
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
                    <tr>
                        <th scope="col" class="receipt-col-num">Nr dostawcy</th>
                        <th scope="col">Nazwa</th>
                        <th scope="col" class="text-right">Zamówiono</th>
                        <th scope="col" class="text-right">Przyjęto już</th>
                        <th scope="col" class="text-right">Pozostało</th>
                        <th scope="col" class="receipt-col-qty">Opak.</th>
                        <th scope="col" class="receipt-col-qty">Ilość</th>
                        <th scope="col" class="receipt-col-mag">Magazyn</th>
                        <th scope="col" class="receipt-col-note">Uwagi</th>
                    </tr>
                    </thead>
                    <tbody id="receiveLinesBody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ===== Pasek akcji (przyklejony do dołu okna) ===== -->
    <div class="receipt-actionbar bg-white border border-top-0 shadow-sm py-2 px-3 d-flex flex-wrap align-items-center">
        <div class="mr-auto py-1">
            <span class="font-weight-bold" id="receiveSummary">Przyjmujesz 0 z 0 pozycji</span>
            <div><small class="text-muted" id="receiveHint"></small></div>
        </div>
        <button type="button" class="btn btn-secondary mr-2" id="receiveCancelBtn">Anuluj</button>
        <button type="button" class="btn btn-success" id="receiveSubmitBtn" disabled>
            <i class="bi bi-check2-circle"></i> Zapisz przyjęcie
        </button>
    </div>
</div>
