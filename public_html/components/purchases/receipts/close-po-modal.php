<?php
/**
 * Współdzielony modal „Zamknij zamówienie" + opcjonalny „Anuluj zamówienie".
 *
 * Renderowany RAZ na stronie, na której może zostać otwarty. Dwa miejsca
 * wpinania w tej roli:
 *
 *   1. /purchase/receipts/receive — samodzielna strona formularza
 *      przyjęcia. Operator klika „Zamknij zamówienie" w pasku akcji
 *      (#receiveClosePoBtn), otwiera się modal z wyborem stanu
 *      wynikowego (received / cancelled).
 *
 *   2. /purchase/receipts — kolejka „Do przyjęcia". Operator klika
 *      mały przycisk-kosz w kolumnie Akcja. Dwie odmiany w zależności
 *      od stanu PO:
 *        - `sent` → otwiera prosty modal #receiveCancelPoModal
 *          (anulowanie przez documents/document-cancel-ajax.php),
 *        - `confirmed`/`in_progress`/`partially_received` → otwiera
 *          ten sam modal #receiveClosePoModal co formularz przyjęcia,
 *          a JS wywołuje receipts/receipt-close-po.php z wybranym
 *          stanem (received lub cancelled).
 *
 * UWAGA o cyklu życia: ten partial emituje DWA identyfikatory modali
 * (gdy dołączający ustawił $withCancel). Na stronie MOŻE istnieć co
 * najwyżej jedna kopia tego pliku — kolejne `include` nadpisałoby
 * identyfikatory i Bootstrap 4 nie znalazłby poprawnie okna do
 * otwarcia. Dlatego dołączający musi sam pilnować, żeby include
 * wystąpił najwyżej raz na render.
 *
 * Endpointy:
 *   POST .../purchases/receipts/receipt-close-po.php        (close-with-choice)
 *   POST .../purchases/documents/document-cancel-ajax.php    (cancel-only)
 *
 * Style: `.receipt-close-*` — zdefiniowane w receipts-ui.css, bo ten
 * stylesheet jest ładowany zarówno na /purchase/receipts, jak i na
 * /purchase/receipts/receive. Snippet .receipt-actionbar.receipt-
 * step-locked #receiveClosePoBtn (re-enable przycisku w pasku akcji
 * formularza) zostaje w receipts.css, bo dotyczy tylko formularza.
 *
 * Parametry:
 *   $withCancel  bool  (opcjonalny, domyślnie false) — gdy true,
 *                       emitowany jest dodatkowy modal
 *                       #receiveCancelPoModal (prosty, do kolejkowych
 *                       wierszy `sent`, gdzie jedynym legalnym wynikiem
 *                       jest anulowanie).
 *
 * Znaczniki docelowe po stronie JS (window.ReceiptClosePo):
 *   #receiveClosePoSummary          string z numerem PO + „przyjęto X z Y"
 *   #receiveClosePoHeading          wrapper nagłówka + listy (ukrywany,
 *                                   gdy items jest puste — kolejka)
 *   #receiveClosePoList             <ul> z listą nieodebranych pozycji
 *   #receiveClosePoError            alert-danger na błędy serwera
 *   #receiveClosePoConfirmBtn       submit close-with-choice
 *   #receiveCancelPoSummary         string z numerem PO
 *   #receiveCancelPoError           alert-danger na błędy serwera
 *   #receiveCancelPoConfirmBtn      submit cancel-only
 */
$withCancel = isset($withCancel) ? (bool)$withCancel : false;
?>
<!--
    Modal „Zamknij zamówienie" z wyborem stanu (received / cancelled).
    Identyfikator (#receiveClosePoModal) musi być UNIKALNY na stronie,
    bo Bootstrap 4 trzyma okno w singleton-stanie po pierwszym
    $("#receiveClosePoModal").modal('show').

    data-backdrop="static": backdrop nie znika po kliknięciu obok
    okna (klawisz Esc nadal zamyka, więc flow "Anuluj → ESC" działa).
-->
<div class="modal fade"
     id="receiveClosePoModal"
     tabindex="-1"
     role="dialog"
     aria-labelledby="receiveClosePoTitle"
     aria-hidden="true"
     data-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-lg" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="receiveClosePoTitle">Zamknij zamówienie</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Anuluj">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">

                <p class="receipt-close-summary mb-3" id="receiveClosePoSummary">
                    <!-- Wypełnia JS: numer PO + „przyjęto X z Y". -->
                </p>

                <!-- Wrapper ukrywany przez JS, gdy caller nie przekazuje
                     items (kolejka nie zna pozycji per-PO — ma tylko
                     agregaty). Bez items chowamy i nagłówek, i listę,
                     żeby nie wyświetlać "Wszystkie pozycje są już w
                     pełni odebrane.", które byłoby mylące. -->
                <div id="receiveClosePoHeading">
                    <div class="receipt-close-heading">Pozycje, których jeszcze nie przyjęto</div>
                    <ul class="receipt-close-list list-unstyled mb-3" id="receiveClosePoList">
                        <!-- Wypełnia JS — max 5 pozycji, +N więcej. -->
                    </ul>
                </div>

                <p class="mb-2 font-weight-bold">W jakim stanie ma zostać zamówienie?</p>
                <div class="receipt-close-options">
                    <label class="receipt-close-option mb-2 is-selected" data-close-target="received">
                        <input type="radio" name="receiveCloseTarget" value="received" checked>
                        <span class="receipt-close-option-body">
                            <span class="receipt-close-option-label">Odebrane (zamknięte)</span>
                            <small class="text-muted d-block">
                                Zamówienie zostaje zamknięte jako w pełni odebrane. Nie będzie już można przyjmować towaru ani usuwać zapisanych przyjęć.
                            </small>
                        </span>
                    </label>
                    <label class="receipt-close-option mb-2" data-close-target="cancelled">
                        <input type="radio" name="receiveCloseTarget" value="cancelled">
                        <span class="receipt-close-option-body">
                            <span class="receipt-close-option-label">Anulowane</span>
                            <small class="text-muted d-block">
                                Zamówienie zostaje oznaczone jako anulowane — dostawa się nie odbędzie. Dotyczy też sytuacji, gdy nic nie zostało przyjęte.
                            </small>
                        </span>
                    </label>
                </div>

                <div class="alert alert-warning mt-3 mb-0">
                    <i class="bi bi-exclamation-triangle"></i>
                    Operacja jest <strong>nieodwracalna</strong> — po zamknięciu zamówienia nie da się go wznowić z tej strony.
                </div>

                <div class="alert alert-danger d-none mt-3 mb-0" id="receiveClosePoError" role="alert"></div>

            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-warning" id="receiveClosePoConfirmBtn">
                    <i class="bi bi-check2-square"></i> Zamknij zamówienie
                </button>
            </div>
        </div>
    </div>
</div>

<?php if ($withCancel): ?>
<!--
    Modal „Anuluj zamówienie" — prosty wariant dla wierszy `sent`
    w kolejce. closePoFromReceive() odrzuca stan `sent`, więc jedynym
    legalnym wynikiem jest anulowanie przez PurchaseActionHandler::
    cancelPo() za pośrednictwem document-cancel-ajax.php. Brak radia,
    brak listy pozycji — operator tylko potwierdza intencję.
-->
<div class="modal fade"
     id="receiveCancelPoModal"
     tabindex="-1"
     role="dialog"
     aria-labelledby="receiveCancelPoTitle"
     aria-hidden="true"
     data-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="receiveCancelPoTitle">Anuluj zamówienie</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Anuluj">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p class="receipt-cancel-summary mb-3" id="receiveCancelPoSummary">
                    <!-- Wypełnia JS: numer PO + zdanie o anulowaniu. -->
                </p>
                <div class="alert alert-warning mb-0">
                    <i class="bi bi-exclamation-triangle"></i>
                    Operacja jest <strong>nieodwracalna</strong> — po anulowaniu zamówienia nie da się go wznowić z tej strony.
                </div>
                <div class="alert alert-danger d-none mt-3 mb-0" id="receiveCancelPoError" role="alert"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-danger" id="receiveCancelPoConfirmBtn">
                    <i class="bi bi-trash"></i> Anuluj zamówienie
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>