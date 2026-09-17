<?php
/**
 * Wizard "Wyślij dokument" — pełnostronicowy (nie modal) kreator,
 * który prowadzi operatora przez cztery kroki:
 *
 *   1. PODSUMOWANIE      read-only nagłówek + pozycje + total per waluta
 *      + opcjonalny backdate `sent_at` (rzadko używany).
 *   2. KONTAKTY          checkbox cards: aktywne osoby kontaktowe
 *      dostawcy (z odznaczeniem głównego), filtr substringiem, link
 *      do zarządzania listą kontaktów.
 *   3. PDF               radio Tak/Nie z placeholder-preview. Tylko
 *      Toggle; prawdziwy PDF pojawi się w przyszłości.
 *   4. POTWIERDZENIE     recapa + sticky action bar z dwoma przyciskami
 *      (Wróć do dokumentu / Wyślij…). Jedyny moment, w którym stan
 *      dokumentu się zmienia — POST do document-send-ajax.php albo
 *      document-rfq-respond-ajax.php (dla rfq+sent), potem redirect.
 *
 * Kontrakt GET-ender (loader snapshotu dokumentu) jest w
 * public_html/components/purchases/documents/documents-send-get.php.
 *
 * Routing: /admin/purchase/documents/send?id=N&type=rfq|po.
 * Przy ?id=0 / ?type= inny niż rfq|po → błąd walidacji inline.
 */
use Atte\DB\MsaDB;

if (!isset($_SESSION['isAdmin']) || $_SESSION['isAdmin'] !== true) {
    header("Location: http://" . BASEURL . "/");
    exit();
}

$type = (string)($_GET['type'] ?? '');
if (!in_array($type, ['rfq', 'po'], true)) {
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">Nieprawidłowy typ dokumentu (wymagane ?type=rfq lub ?type=po).</div></div>';
    return;
}
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo '<div class="container-fluid w-75 mt-3"><div class="alert alert-danger">Brak id dokumentu.</div></div>';
    return;
}

$MsaDB = MsaDB::getInstance();
?>
<div class="container-fluid w-75 mt-3"
     id="send-wizard-root"
     data-doc-type="<?= htmlspecialchars($type, ENT_QUOTES) ?>"
     data-doc-id="<?= (int)$id ?>"
     data-loader-url="<?= htmlspecialchars(asset('public_html/components/purchases/documents/documents-send-ajax-load.php'), ENT_QUOTES) ?>"
     data-send-url="<?= htmlspecialchars(asset('public_html/components/purchases/documents/document-send-ajax.php'), ENT_QUOTES) ?>"
     data-respond-url="<?= htmlspecialchars(asset('public_html/components/purchases/documents/document-rfq-respond-ajax.php'), ENT_QUOTES) ?>"
     data-cancel-url="<?= htmlspecialchars(asset('public_html/components/purchases/documents/document-cancel-ajax.php'), ENT_QUOTES) ?>"
     data-back-url="<?= htmlspecialchars('http://' . BASEURL . '/admin/purchase/documents/edit?id=' . (int)$id . '&type=' . $type, ENT_QUOTES) ?>"
     data-list-url="<?= htmlspecialchars('http://' . BASEURL . '/purchase/documents', ENT_QUOTES) ?>"
     data-suppliers-url="<?= htmlspecialchars('http://' . BASEURL . '/admin/purchase/vendor/suppliers', ENT_QUOTES) ?>"
     data-supplier-create-url="<?= htmlspecialchars(asset('public_html/components/purchases/documents/vendor-supplier-create-ajax.php'), ENT_QUOTES) ?>"
     data-supplier-update-url="<?= htmlspecialchars(asset('public_html/components/purchases/documents/vendor-supplier-update-ajax.php'), ENT_QUOTES) ?>">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="mb-0">
            <i class="bi bi-send"></i>
            <span id="send-wizard-title">Wyślij dokument</span>
            <span id="send-wizard-subtitle" class="text-muted small ml-2"></span>
        </h2>
        <a href="http://<?= BASEURL ?>/purchase/documents"
           class="btn btn-outline-secondary btn-sm"
           onclick="if (history.length > 1) { history.back(); return false; }">
            <i class="bi bi-arrow-left"></i> Wróć
        </a>
    </div>

    <!-- Błędy ładowania dokumentu (id nie istnieje, itp.). Wypełnia JS. -->
    <div id="send-wizard-error" class="alert alert-danger" style="display: none;"></div>

    <!-- Stepper (4 kroki; active z .is-active). Czysto wizualne —
         nawigacja jest linearna (Dalej / commit), bez back-button. -->
    <ol class="breadcrumb send-stepper mb-3" id="send-wizard-stepper">
        <li class="breadcrumb-item is-active" data-step="1">
            <span class="send-stepper-num">1</span> Podsumowanie
        </li>
        <li class="breadcrumb-item" data-step="2">
            <span class="send-stepper-num">2</span> Kontakty
        </li>
        <li class="breadcrumb-item" data-step="3">
            <span class="send-stepper-num">3</span> PDF
        </li>
        <li class="breadcrumb-item" data-step="4">
            <span class="send-stepper-num">4</span> Potwierdzenie
        </li>
    </ol>

    <!--
        Cztery panele. Tylko jeden .is-active naraz. Wewnątrz każdego:
        - header (tytuł + numer kroku) + spinner w prawym górnym
          - body (zawartość renderowana przez JS)
        - footer z przyciskami Dalej / Wstecz (poza panelem 4, który ma
          sticky action bar)
    -->
    <div class="send-step is-active" data-step="1" id="send-step-1">
        <div class="card send-step-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong><span class="send-step-num">1</span> Podsumowanie dokumentu</strong>
                <div class="spinner-border spinner-border-sm text-primary send-step-spinner" role="status" style="display: none;">
                    <span class="sr-only">Ładowanie…</span>
                </div>
            </div>
            <div class="card-body send-step-body" id="send-step-1-body">
                <p class="text-muted mb-0">Ładowanie dokumentu…</p>
            </div>
            <div class="card-footer d-flex justify-content-end">
                <button type="button" class="btn btn-primary send-step-next" data-go="2" disabled>
                    Dalej <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </div>
    </div>

    <div class="send-step" data-step="2" id="send-step-2" style="display: none;">
        <div class="card send-step-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong><span class="send-step-num">2</span> Wybierz osoby do wysłania</strong>
                <div class="spinner-border spinner-border-sm text-primary send-step-spinner" role="status" style="display: none;">
                    <span class="sr-only">Ładowanie…</span>
                </div>
            </div>
            <div class="card-body send-step-body" id="send-step-2-body">
                <p class="text-muted mb-0">Ładowanie kontaktów…</p>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <button type="button" class="btn btn-outline-secondary send-step-prev" data-go="1">
                    <i class="bi bi-arrow-left"></i> Wstecz
                </button>
                <span class="text-muted small" id="send-step-2-count">0 zaznaczonych</span>
                <button type="button" class="btn btn-primary send-step-next" data-go="3" disabled>
                    Dalej <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </div>
    </div>

    <div class="send-step" data-step="3" id="send-step-3" style="display: none;">
        <div class="card send-step-card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong><span class="send-step-num">3</span> Wygenerować PDF?</strong>
                <div class="spinner-border spinner-border-sm text-primary send-step-spinner" role="status" style="display: none;">
                    <span class="sr-only">Ładowanie…</span>
                </div>
            </div>
            <div class="card-body send-step-body" id="send-step-3-body">
                <p class="text-muted mb-0">Ładowanie…</p>
            </div>
            <div class="card-footer d-flex justify-content-between">
                <button type="button" class="btn btn-outline-secondary send-step-prev" data-go="2">
                    <i class="bi bi-arrow-left"></i> Wstecz
                </button>
                <button type="button" class="btn btn-primary send-step-next" data-go="4">
                    Dalej <i class="bi bi-arrow-right"></i>
                </button>
            </div>
        </div>
    </div>

    <div class="send-step" data-step="4" id="send-step-4" style="display: none;">
        <div class="card send-step-card send-step-card-final">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong><span class="send-step-num">4</span> Potwierdzenie i wysyłka</strong>
                <div class="spinner-border spinner-border-sm text-primary send-step-spinner" role="status" style="display: none;">
                    <span class="sr-only">Ładowanie…</span>
                </div>
            </div>
            <div class="card-body send-step-body" id="send-step-4-body">
                <p class="text-muted mb-0">Ładowanie…</p>
            </div>
            <!--
                Sticky action bar — wyrenderowany inline (nie jako osobny
                modal / osobny .container-fluid), bo sam panel 4 ma być
                samodzielną stroną wizualnie. position: sticky + bottom:0
                trzyma go na dole widoku po scrollu panelu.
            -->
            <div class="action-bar-sticky d-flex justify-content-between align-items-center">
                <a href="#" id="send-back-to-doc" class="btn btn-outline-secondary">
                    <i class="bi bi-arrow-left"></i> Wróć do dokumentu
                </a>
                <button type="button" class="btn btn-success" id="send-commit-btn" disabled>
                    <i class="bi bi-send"></i> <span id="send-commit-btn-label">Wyślij</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!--
    Sukces: pełnoekranowy (container-fluid) alert po commicie. Kiedy
    .send-success-wrap jest widoczny, panel wizarda chowamy (ukrywa go
    JS po sukcesie). Redirect jest w JS po 2 s.
-->
<div id="send-success-wrap" class="container-fluid w-75 mt-3" style="display: none;">
    <div class="alert alert-success send-success-alert" id="send-success-alert"></div>
</div>

<link rel="stylesheet" href="<?= htmlspecialchars(asset('public_html/components/Admin/Purchase/Documents/documents-send.css'), ENT_QUOTES) ?>">
<script src="<?= htmlspecialchars(asset('public_html/components/Admin/Purchase/Documents/documents-send.js'), ENT_QUOTES) ?>"></script>
