<?php
/**
 * Przyjęcie dostawy wg faktury / WZ — /purchase/receipts/new.
 *
 * Jedna strona obsługuje cały obieg dostawy (plan: docs/procurement/
 * RECEIPT_INVOICE_FLOW_PLAN.md §2, §8.2):
 *   - ?vendor_id=N     nowe przyjęcie z wybranym dostawcą,
 *   - ?delivery_id=N   wznowienie szkicu (stan `draft`) albo podgląd
 *                      zatwierdzonego / anulowanego przyjęcia
 *                      (tryb tylko do odczytu + „Anuluj przyjęcie").
 *
 * Szkic żyje na serwerze (decyzja Q1): każda zmiana nagłówka i każda
 * pozycja to osobne wywołanie delivery-*.php, a odpowiedź niesie PEŁNY
 * stan dostawy — delivery-page.js renderuje wszystko z tego jednego
 * obiektu i niczego nie łata lokalnie.
 *
 * PHP dostarcza tylko szkielet: listę magazynów, nazwę zalogowanego
 * i parametry z adresu. Dane dostawy, dostawców i otwartych zamówień
 * ładuje JS.
 */
use Atte\DB\MsaDB;

if(!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://".BASEURL."/");
    exit();
}

$MsaDB = MsaDB::getInstance();

$magazines = $MsaDB->query(
    "SELECT sub_magazine_id, sub_magazine_name
       FROM `magazine__list`
      WHERE isActive = 1
      ORDER BY sub_magazine_name"
);

$currentUserStmt = $MsaDB->db->prepare(
    "SELECT CONCAT(`name`, ' ', `surname`) FROM `user` WHERE `user_id` = ?"
);
$currentUserStmt->execute([(int)($_SESSION['user_id'] ?? 0)]);
$currentUserName = (string)($currentUserStmt->fetchColumn() ?: ('#' . (int)($_SESSION['user_id'] ?? 0)));

$deliveryId = max(0, (int)($_GET['delivery_id'] ?? 0));
$vendorId   = max(0, (int)($_GET['vendor_id'] ?? 0));

$listUrl = 'http://' . BASEURL . '/purchase/receipts';

$e = static function ($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); };
?>
<link rel="stylesheet" href="<?= $e(asset('public_html/components/purchases/cart/purchases.css')) ?>">
<link rel="stylesheet" href="<?= $e(asset('public_html/components/purchases/receipts/receipts-ui.css')) ?>">
<link rel="stylesheet" href="<?= $e(asset('public_html/components/purchases/receipts/delivery-ui.css')) ?>">

<div class="rx-page dv-page dv-mode-draft" id="deliveryPage"
     data-delivery-id="<?= (int)$deliveryId ?>"
     data-vendor-id="<?= (int)$vendorId ?>"
     data-list-url="<?= $e($listUrl) ?>"
     data-current-user="<?= $e($currentUserName) ?>">

    <!-- ===== Okruszki + nagłówek ===== -->
    <nav aria-label="Ścieżka">
        <ol class="rx-crumbs">
            <li><a href="<?= $e($listUrl) ?>"><i class="bi bi-box-arrow-in-down"></i> Przyjęcia</a></li>
            <li aria-hidden="true"><i class="bi bi-chevron-right"></i></li>
            <li aria-current="page" id="dvCrumb">Nowe przyjęcie</li>
        </ol>
    </nav>

    <header class="rx-head dv-head">
        <div class="rx-head-main">
            <div class="rx-eyebrow" id="dvEyebrow"><i class="bi bi-receipt"></i> Przyjęcie wg faktury / WZ</div>
            <h1 class="rx-title" id="dvTitle">Nowe przyjęcie dostawy</h1>
            <p class="rx-lead" id="dvLead">
                Wpisuj pozycje w kolejności z dokumentu dostawcy — każda od razu
                dopasowuje się do otwartych zamówień tego dostawcy.
            </p>
        </div>
        <div class="dv-head-side">
            <span class="rx-badge rx-badge--blue" id="dvStateBadge">Szkic</span>
            <span class="dv-savestate" id="dvSaveState" aria-live="polite"></span>
        </div>
    </header>

    <div id="alertContainer" class="rx-alerts"></div>

    <!-- ===== Dokument dostawcy (nagłówek) ===== -->
    <section class="rx-panel rx-sheet dv-doc mb-3" id="dvHeaderCard">
        <div class="dv-doc-head">
            <h2 class="rx-panel-title"><i class="bi bi-file-earmark-text"></i> Dokument dostawcy</h2>
            <div class="dv-doc-grn d-none" id="dvGrnTag"></div>
        </div>

        <div class="dv-fields">

            <div class="dv-field dv-span-4" id="dvVendorCell">
                <label class="dv-label" for="dvVendor">Dostawca <span class="dv-req" title="Wymagane">*</span></label>
                <select id="dvVendor" class="selectpicker"
                        data-live-search="true"
                        data-title="Wybierz dostawcę…"
                        data-style="rx-picker-btn"
                        data-width="100%"
                        data-container="#dvVendorCell"
                        data-live-search-placeholder="Szukaj dostawcy"></select>
                <div class="dv-hint" id="dvVendorHint"></div>
            </div>

            <div class="dv-field dv-span-2">
                <span class="dv-label" id="dvDocTypeLabel">Typ dokumentu</span>
                <div class="rx-seg dv-seg" role="group" aria-labelledby="dvDocTypeLabel" id="dvDocType">
                    <button type="button" data-type="invoice" class="is-active" aria-pressed="true">Faktura</button>
                    <button type="button" data-type="wz" aria-pressed="false">WZ</button>
                </div>
            </div>

            <div class="dv-field dv-span-3">
                <label class="dv-label" for="dvDocNumber">Nr dokumentu <span class="dv-req" title="Wymagane do zatwierdzenia">*</span></label>
                <input type="text" id="dvDocNumber" class="form-control form-control-sm dv-input"
                       maxlength="100" autocomplete="off" placeholder="np. FV/123/2026">
                <div class="dv-hint" id="dvDocNumberHint"></div>
            </div>

            <div class="dv-field dv-span-3">
                <label class="dv-label" for="dvDocDate">Data dokumentu</label>
                <input type="date" id="dvDocDate" class="form-control form-control-sm dv-input">
            </div>

            <div class="dv-field dv-span-3">
                <label class="dv-label" for="dvReceivedAt">Data przyjęcia</label>
                <input type="date" id="dvReceivedAt" class="form-control form-control-sm dv-input"
                       value="<?= $e(date('Y-m-d')) ?>">
            </div>

            <div class="dv-field dv-span-4" id="dvMagazineCell">
                <label class="dv-label" for="dvMagazine">Magazyn docelowy <span class="dv-req" title="Wymagane">*</span></label>
                <select id="dvMagazine" class="selectpicker"
                        data-live-search="true"
                        data-title="Wybierz magazyn…"
                        data-style="rx-picker-btn"
                        data-width="100%"
                        data-container="#dvMagazineCell">
                    <?php foreach ($magazines as $m): ?>
                        <option value="<?= (int)$m['sub_magazine_id'] ?>"><?= $e($m['sub_magazine_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="dv-hint">Domyślny dla wszystkich pozycji; każdą można zmienić osobno.</div>
            </div>

            <div class="dv-field dv-span-2">
                <label class="dv-label" for="dvCurrency">Waluta</label>
                <select id="dvCurrency" class="custom-select custom-select-sm dv-input">
                    <option value="PLN">PLN</option>
                    <option value="EUR">EUR</option>
                    <option value="USD">USD</option>
                    <option value="GBP">GBP</option>
                    <option value="CZK">CZK</option>
                    <option value="CHF">CHF</option>
                </select>
            </div>

            <div class="dv-field dv-span-3">
                <label class="dv-label" for="dvComment">Komentarz do dostawy</label>
                <input type="text" id="dvComment" class="form-control form-control-sm dv-input"
                       maxlength="500" autocomplete="off" placeholder="Opcjonalnie">
            </div>
        </div>
    </section>

    <!-- ===== Pozycje + otwarte zamówienia ===== -->
    <div class="dv-grid">

        <section class="rx-panel dv-lines" id="dvLinesPanel" aria-labelledby="dvLinesTitle">
            <div class="rx-panel-head">
                <h2 class="rx-panel-title" id="dvLinesTitle">
                    <i class="bi bi-list-check"></i> Pozycje z dokumentu
                    <span class="rx-count" id="dvLineCount">0</span>
                </h2>
                <span class="dv-panel-note" id="dvLinesNote"></span>
            </div>

            <div class="dv-lock" id="dvLinesLock">
                <div class="rx-empty">
                    <div class="rx-empty-icon"><i class="bi bi-truck"></i></div>
                    <div class="rx-empty-title">Zacznij od wyboru dostawcy</div>
                    <div class="rx-empty-text">
                        Po wybraniu dostawcy pokażemy jego otwarte zamówienia i pozwolimy wpisywać pozycje z faktury.
                    </div>
                </div>
            </div>

            <div class="dv-table-wrap d-none" id="dvTableWrap">
                <table class="table rx-table dv-table mb-0" id="dvLinesTable">
                    <thead>
                    <tr>
                        <th scope="col" class="dv-c-no">#</th>
                        <th scope="col">Artykuł</th>
                        <th scope="col" class="text-right dv-c-qty">Ilość</th>
                        <th scope="col" class="text-right dv-c-price">Cena</th>
                        <th scope="col">Przydział do zamówień</th>
                        <th scope="col" class="text-right dv-c-act"><span class="sr-only">Akcje</span></th>
                    </tr>
                    </thead>
                    <tbody id="dvLinesBody"></tbody>
                </table>
                <div class="dv-empty-lines d-none" id="dvEmptyLines">
                    <i class="bi bi-keyboard"></i>
                    Wpisz pierwszą pozycję poniżej: artykuł → <kbd>Tab</kbd> → ilość → <kbd>Tab</kbd> → cena → <kbd>Enter</kbd>.
                </div>
            </div>

            <!-- Wiersz wpisywania — przyklejony nad paskiem akcji. -->
            <div class="dv-entry d-none" id="dvEntry">
                <div class="dv-entry-tag" id="dvEntryTag">Nowa pozycja</div>
                <div class="dv-entry-grid">
                    <div class="dv-entry-art">
                        <label class="dv-label" for="dvArt">Artykuł <span class="dv-label-sub">numer dostawcy, producenta lub nazwa</span></label>
                        <div class="dv-art-wrap">
                            <i class="bi bi-search dv-art-icon" aria-hidden="true"></i>
                            <input type="text" id="dvArt" class="form-control dv-input" autocomplete="off"
                                   role="combobox" aria-expanded="false" aria-controls="dvArtList"
                                   aria-autocomplete="list" placeholder="Szukaj w katalogu dostawcy…">
                            <button type="button" class="dv-art-clear d-none" id="dvArtClear" tabindex="-1" aria-label="Wyczyść artykuł">
                                <i class="bi bi-x-circle-fill"></i>
                            </button>
                            <div class="dv-dd" id="dvArtList" role="listbox"></div>
                        </div>
                    </div>
                    <div class="dv-entry-qty">
                        <label class="dv-label" for="dvQty">Ilość</label>
                        <input type="text" id="dvQty" class="form-control dv-input text-right" inputmode="decimal"
                               autocomplete="off" placeholder="0">
                    </div>
                    <div class="dv-entry-price">
                        <label class="dv-label" for="dvPrice">Cena netto <span class="dv-label-sub" id="dvPriceCur">PLN</span></label>
                        <input type="text" id="dvPrice" class="form-control dv-input text-right" inputmode="decimal"
                               autocomplete="off" placeholder="0,00">
                    </div>
                    <div class="dv-entry-go">
                        <button type="button" class="btn btn-primary dv-entry-btn" id="dvEntrySave">
                            <i class="bi bi-plus-lg"></i> <span id="dvEntrySaveLabel">Dodaj</span>
                        </button>
                        <button type="button" class="btn rx-btn-quiet d-none" id="dvEntryCancel" title="Anuluj edycję (Esc)">
                            <i class="bi bi-x-lg"></i>
                        </button>
                    </div>
                </div>
                <div class="dv-entry-foot">
                    <div class="dv-entry-hint" id="dvEntryHint">
                        <kbd>Tab</kbd> przechodzi dalej · <kbd>Enter</kbd> w polu ceny zapisuje pozycję · <kbd>↓</kbd><kbd>↑</kbd> wybierają z listy
                    </div>
                    <div class="dv-entry-error d-none" id="dvEntryError" role="alert"></div>
                </div>
            </div>
        </section>

        <aside class="rx-panel dv-side" id="dvSide" aria-labelledby="dvSideTitle">
            <div class="rx-panel-head">
                <h2 class="rx-panel-title" id="dvSideTitle"><i class="bi bi-bag-check"></i> <span id="dvSideTitleText">Otwarte zamówienia</span></h2>
            </div>
            <div class="dv-side-search" id="dvSideSearchWrap">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" id="dvSideSearch" class="form-control form-control-sm"
                       placeholder="Filtruj: nr zamówienia, artykuł" autocomplete="off" aria-label="Filtruj otwarte zamówienia">
            </div>
            <div class="dv-side-body" id="dvSideBody">
                <div class="dv-side-empty">Wybierz dostawcę, aby zobaczyć jego otwarte zamówienia.</div>
            </div>
        </aside>
    </div>

    <!-- ===== Pasek akcji (przyklejony do dołu okna) ===== -->
    <div class="dv-bar" id="dvBar">
        <div class="dv-blockers d-none" id="dvBlockers" role="status" aria-live="polite"></div>
        <div class="dv-bar-main">
            <div class="dv-bar-stats" id="dvBarStats" aria-live="polite">
                <span class="dv-stat"><strong id="dvStatLines">0</strong> <span id="dvStatLinesLbl">pozycji</span></span>
                <span class="dv-stat-sep">·</span>
                <span class="dv-stat"><strong id="dvStatPos">0</strong> <span id="dvStatPosLbl">zamówień</span></span>
                <span class="dv-stat-warn-wrap">
                    <span class="dv-stat-sep">·</span>
                    <span class="dv-stat" id="dvStatWarnWrap"><strong id="dvStatWarn">0</strong> <span id="dvStatWarnLbl">ostrzeżeń</span></span>
                </span>
            </div>
            <div class="dv-bar-actions" id="dvBarActions">
                <a href="<?= $e($listUrl) ?>" class="btn rx-btn-quiet d-none" id="dvBackBtn">
                    <i class="bi bi-arrow-left"></i> Wróć do listy
                </a>
                <button type="button" class="btn btn-outline-danger" id="dvDiscardBtn">
                    <i class="bi bi-trash"></i> Usuń szkic
                </button>
                <button type="button" class="btn btn-outline-danger d-none" id="dvCancelDeliveryBtn">
                    <i class="bi bi-arrow-counterclockwise"></i> Anuluj przyjęcie
                </button>
                <button type="button" class="btn btn-success dv-post-btn" id="dvPostBtn" disabled>
                    <i class="bi bi-check2-circle"></i> Zatwierdź przyjęcie
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===== Edytor przydziału (ręczny podział pozycji na zamówienia) ===== -->
<div class="modal fade" id="dvAllocModal" tabindex="-1" role="dialog" aria-labelledby="dvAllocTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="dvAllocTitle">Przydział pozycji do zamówień</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Zamknij"><span aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <div id="dvAllocIntro" class="dv-alloc-intro"></div>
                <div id="dvAllocRows" class="dv-alloc-rows"></div>
                <div class="dv-alloc-sum" id="dvAllocSum" aria-live="polite"></div>
                <div class="alert alert-danger d-none mt-3 mb-0" id="dvAllocError" role="alert"></div>
            </div>
            <div class="modal-footer dv-alloc-foot">
                <button type="button" class="btn rx-btn-quiet mr-auto" id="dvAllocAuto">
                    <i class="bi bi-arrow-counterclockwise"></i> Przywróć automatyczny
                </button>
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-primary" id="dvAllocSave">
                    <i class="bi bi-check2"></i> Zapisz przydział
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===== Podsumowanie po zatwierdzeniu ===== -->
<div class="modal fade" id="dvPostModal" tabindex="-1" role="dialog" aria-labelledby="dvPostTitle"
     aria-hidden="true" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header dv-post-head">
                <div>
                    <div class="rx-eyebrow">Przyjęcie zatwierdzone</div>
                    <h5 class="modal-title" id="dvPostTitle">GRN</h5>
                </div>
            </div>
            <div class="modal-body">
                <p class="dv-post-lead">
                    Towar jest na stanie. Zdecyduj, co dalej z zamówieniami, których dotyczyła dostawa:
                </p>
                <div id="dvPostPos" class="dv-post-pos"></div>
            </div>
            <div class="modal-footer">
                <a href="<?= $e($listUrl) ?>" class="btn rx-btn-quiet mr-auto"><i class="bi bi-list-ul"></i> Lista przyjęć</a>
                <button type="button" class="btn btn-primary" id="dvPostDone">
                    <i class="bi bi-check2"></i> Gotowe
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    window.DV_MAGAZINES = <?= json_encode(array_map(static function ($m) {
        return ['id' => (int)$m['sub_magazine_id'], 'name' => (string)$m['sub_magazine_name']];
    }, $magazines), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
</script>
<script src="<?= $e(asset('public_html/components/purchases/receipts/delivery-page.js')) ?>"></script>
