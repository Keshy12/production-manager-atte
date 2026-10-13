<?php
/**
 * Modal partial — "Potwierdź odbiór zamówienia" (PO → stan `confirmed`).
 *
 * Współdzielony widget renderowany RAZ na stronie, na której może
 * zostać otwarty. Dwa miejsca wpinania w tej roli:
 *
 *   1. /admin/purchase/documents/edit — przycisk "Potwierdź odbiór"
 *      w prawym górnym rogu dla PO w stanie `sent` (header CTA).
 *   2. /purchase/receipts — kolejka "Do przyjęcia" dla wierszy
 *      `sent` (przycisk w kolumnie Akcja).
 *
 * Endpoint docelowy: POST .../purchases/documents/document-confirm-ajax.php
 * Kontrakt we/wy jest zamrożony (patrz AGENTS.md sekcja A tego zadania):
 *
 *   in  { id, vendor_po_number?, confirmed_at? }
 *   out { success, document_number, state:'confirmed', state_label, vendor_po_number }
 *
 * Styl: Bootstrap 4 (modal-dialog modal-dialog-centered — bez modal-xl,
 * bo to mały formularz z dwoma polami + opisem). Backdrop oznaczony
 * `data-backdrop="static"` żeby przypadkowy klik obok nie zamknął
 * okna w trakcie AJAX-a. Ikonka submitującego buttonu wymieniana
 * na spinner przez JS w trakcie requestu.
 *
 * URL endpointu wstrzykujemy jako data-* na elemencie modal — JS
 * odczytuje go przy submicie, więc partial nie musi znać ścieżek.
 */
?>
<!--
    data-confirm-endpoint: pełny URL do document-confirm-ajax.php
    (asset() rozwiązuje ścieżkę, dzięki czemu partial nie musi
    zakładać BASEURL ani struktury katalogów).

    data-backdrop="static": BS4 opcja modala — backdrop nie znika
    po kliknięciu obok okna (klawisz Esc dalej zamyka, więc flow
    "Anuluj → ESC" nadal działa dla operatorów, którzy nie klikają
    przycisku).
-->
<div class="modal fade"
     id="send-confirm-po-modal"
     tabindex="-1"
     role="dialog"
     aria-labelledby="send-confirm-po-modal-title"
     aria-hidden="true"
     data-backdrop="static"
     data-keyboard="true"
     data-confirm-endpoint="<?= htmlspecialchars(asset('public_html/components/purchases/documents/document-confirm-ajax.php'), ENT_QUOTES) ?>">
    <div class="modal-dialog modal-dialog-centered" role="document">
        <div class="modal-content">

            <div class="modal-header">
                <h5 class="modal-title" id="send-confirm-po-modal-title">
                    <i class="bi bi-check2-square"></i> Potwierdź odbiór zamówienia
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Zamknij">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>

            <div class="modal-body">

                <!--
                    Kontekst — uzupełniany przez JS z data-* triggerów:
                    "<numer PO> — <nazwa dostawcy>". Bootstrapowy
                    <small class="text-muted"> poniżej jest fallbackiem,
                    gdyby trigger nie przekazał nazwy dostawcy.
                -->
                <div class="mb-3">
                    <span id="send-confirm-context">—</span>
                </div>

                <p class="text-muted small mb-3">
                    Zamówienie przechodzi ze stanu „Wysłane" do „Potwierdzone".
                    Od tego momentu można przyjmować dostawy.
                </p>

                <div class="form-group">
                    <label for="send-confirm-vendor-po-number">Numer zamówienia u dostawcy</label>
                    <input type="text"
                           class="form-control form-control-sm"
                           id="send-confirm-vendor-po-number"
                           maxlength="64"
                           autocomplete="off"
                           placeholder="opcjonalnie">
                    <small class="form-text text-muted">
                        Pozostaw puste, jeśli dostawca nie nadał własnego numeru.
                    </small>
                </div>

                <div data-toggle-confirm-backdate>
                    <i class="bi bi-calendar-event"></i> Zmień datę potwierdzenia (zaawansowane)
                </div>
                <div data-confirm-backdate-wrap>
                    <label class="small mb-1" for="send-confirm-confirmed-at">Data potwierdzenia:</label>
                    <input type="datetime-local"
                           class="form-control form-control-sm"
                           id="send-confirm-confirmed-at"
                           step="60">
                    <small class="form-text text-muted">
                        Domyślnie „teraz". Pozostaw puste, żeby użyć bieżącego czasu.
                    </small>
                </div>

                <div id="send-confirm-error" class="text-danger small" style="display:none;"></div>

            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">
                    Anuluj
                </button>
                <button type="button" class="btn btn-primary" id="send-confirm-submit">
                    <i class="bi bi-check2-square"></i> Potwierdź
                </button>
            </div>

        </div>
    </div>
</div>
