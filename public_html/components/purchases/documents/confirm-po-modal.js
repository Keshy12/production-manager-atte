/**
 * Modal "Potwierdź odbiór PO" — współdzielona logika dla DWÓCH wejść:
 *
 *   (A) /admin/purchase/documents/edit — header CTA na PO w stanie
 *       `sent`. PHP renderuje <button data-action="confirm-po" …>
 *       obok "Wróć".
 *
 *   (B) /purchase/receipts — kolejka "Do przyjęcia" dla wierszy
 *       `sent`. JS generuje <button data-action="confirm-po" …>
 *       w queueRowHtml().
 *
 * Każdy trigger niesie atrybuty:
 *
 *   data-action="confirm-po"          identyfikator triggera
 *   data-po-id                        int — id zamówienia
 *   data-po-number                    string — numer PO (lub "#id")
 *   data-vendor-name                  string — nazwa dostawcy
 *   data-vendor-po-number             string — opcjonalny, do pre-fill
 *
 * Modal ma `data-confirm-endpoint="…"`, skąd JS czyta URL endpointu.
 *
 * Kontrakt POST: { id, vendor_po_number?, confirmed_at? } →
 *   { success, document_number, state:'confirmed', state_label,
 *     vendor_po_number }
 *
 * Na sukces: setAlert + location.reload() po 1.2 s (operator widzi
 * nowy stan w UI zanim strona się przeładuje). Na błąd: inline
 * #send-confirm-error + ponowne włączenie submitu.
 *
 * Konwencje: Bootstrap 4 jQuery API ($('#…').modal('show'/'hide')),
 * brak `var`, brak `setAlert`-zależności (fallback na własny banner
 * w modal-body, gdyby globalna setAlert nie istniała na stronie).
 */
$(document).ready(function () {

    // ---- DOM hooks (wewnątrz modala, instancja może nie istnieć) ----

    const $modal          = $('#send-confirm-po-modal');
    if ($modal.length === 0) return;

    const $context        = $('#send-confirm-context');
    const $vendorPoInput  = $('#send-confirm-vendor-po-number');
    const $backdateToggle = $modal.find('[data-toggle-confirm-backdate]');
    const $backdateWrap   = $modal.find('[data-confirm-backdate-wrap]');
    const $backdateInput  = $('#send-confirm-confirmed-at');
    const $errorBlock     = $('#send-confirm-error');
    const $submitBtn      = $('#send-confirm-submit');

    // URL endpointu z data-* atrybutu na modalu. asset() w PHP
    // rozwiązuje go do pełnej ścieżki, więc używamy go wprost — ten
    // sam wzorzec co wizard ("data-supplier-create-url" w documents-send.js).
    const CONFIRM_ENDPOINT = $modal.attr('data-confirm-endpoint') || '';

    // Cache oryginalnego HTML submit buttonu — w trakcie requestu
    // podmieniamy ikonę na spinner; po error/fail przywracamy ten
    // markup (analogicznie do commit buttonu w wizardzie Wyślij).
    let $submitOrigHtml = null;

    // Lokalny stan ostatnio otwartego PO — żeby submit wiedział, co
    // wysłać. Nie trzymamy w DOM, bo po drodze formularz ma tylko
    // dwa inputy i można je sczytać bezpośrednio.
    let currentPoId = null;

    // ---- Helpers ---------------------------------------------------

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function showInlineError(msg) {
        $errorBlock.text(msg || '').show();
    }

    function hideInlineError() {
        $errorBlock.text('').hide();
    }

    function resetBackdate() {
        // Zamknij wrap i wyczyść input; fokus wraca na vendor_po_number
        // po otwarciu (nie na toggle). Toggle znika, bo .is-open na nim
        // == display:none (per CSS).
        $backdateWrap.removeClass('is-open');
        $backdateToggle.removeClass('is-open');
        $backdateInput.val('');
    }

    function restoreSubmitBtn() {
        if ($submitOrigHtml === null) return;
        $submitBtn.html($submitOrigHtml);
        // (Cached $submitOrigHtml trzyma ten sam węzeł <i> który
        // był oryginalnie — nie ma tu detached-node problemu jak
        // w wizardzie, bo nic innego nie nadpisuje tego buttonu
        // poza naszym spinnerem.)
    }

    function setSubmitting(isSubmitting) {
        if (isSubmitting) {
            if ($submitOrigHtml === null) {
                $submitOrigHtml = $submitBtn.html();
            }
            $submitBtn.prop('disabled', true);
            $submitBtn.html('<span class="spinner-border spinner-border-sm mr-2"></span>Wysyłanie…');
        } else {
            $submitBtn.prop('disabled', false);
            restoreSubmitBtn();
        }
    }

    function announceSuccess(msg) {
        // Wspólny helper sukcesu: preferuj globalną setAlert (gdy
        // istnieje), w ostateczności inline w modalu. Modal sam się
        // nie zamyka — czeka na location.reload().
        if (typeof setAlert === 'function') {
            try { setAlert(msg, 'success'); return; } catch (e) { /* fall through */ }
        }
        // Brak globalnej setAlert → inline banner wewnątrz body
        // modala. Nadpisujemy ewentualny inline-error (mają inne
        // kolory BS4 — tu używamy alert-success).
        $errorBlock
            .removeClass('text-danger')
            .addClass('text-success')
            .html(
                '<i class="bi bi-check-circle-fill"></i> '
                + escapeHtml(msg)
            )
            .show();
    }

    // ---- 1) Otwarcie modala z triggera [data-action="confirm-po"] ----

    $(document).on('click', '[data-action="confirm-po"]', function (e) {
        e.preventDefault();
        // Stop-propagation jest ważny w receipts-view: tam wiersz
        // <tr class="queue-row queue-row-pending"> ma klik-handler
        // otwierający formularz przyjęcia (e-stop jest już w nim
        // dla pending, ale defensywnie też tu blokujemy bąbelkowanie,
        // gdyby ktoś kiedyś zmienił logikę pending).
        e.stopPropagation();

        const $trigger = $(this);
        const poId   = parseInt($trigger.attr('data-po-id'), 10);
        const number = $trigger.attr('data-po-number') || '';
        const vendor = $trigger.attr('data-vendor-name') || '';
        const existingVendorPo = $trigger.attr('data-vendor-po-number') || '';

        if (!poId || poId <= 0) {
            // Błąd programistyczny (trigger bez id) — nie ma sensu
            // otwierać modala, zwróć uwagę operatorowi inline w
            // istniejącym modalu (jeśli był otwarty) + alert globalny.
            showInlineError('Brak identyfikatora zamówienia na przycisku.');
            if (typeof setAlert === 'function') {
                setAlert('Brak identyfikatora zamówienia.', 'danger');
            }
            return;
        }

        currentPoId = poId;

        // Kontekst: "PO/2026/0042 — Farnell". Separator " — " (em dash
        // + spacje) spójny ze stylem wizarda. Pusta nazwa dostawcy →
        // fallback "—".
        const contextText = (number || ('#' + poId))
            + (vendor ? ' — ' + vendor : '');
        $context.text(contextText);

        // Vendor PO number: pre-fill jeśli trigger niesie istniejącą
        // wartość (PO edit page); w przeciwnym razie czyść.
        $vendorPoInput.val(existingVendorPo || '');

        // Reset backdate toggle + input.
        resetBackdate();

        // Reset erroru (klasy + treść; toggle text-danger/text-success
        // jest istotny po announceSuccess, gdzie dodajemy text-success).
        $errorBlock.removeClass('text-success').addClass('text-danger');
        hideInlineError();

        // Submit button w stanie spoczynkowym.
        $submitOrigHtml = null;
        restoreSubmitBtn();
        setSubmitting(false);

        $modal.modal('show');

        // Fokus na vendor_po_number po animacji modala, żeby od razu
        // można było pisać. Bez setTimeout animacja BS4 by go zjadła.
        setTimeout(function () {
            $vendorPoInput.trigger('focus');
        }, 300);
    });

    // ---- 2) Backdate toggle ----------------------------------------

    $(document).on('click', '[data-toggle-confirm-backdate]', function (e) {
        e.preventDefault();
        e.stopPropagation();
        const opening = !$backdateWrap.hasClass('is-open');
        $backdateWrap.toggleClass('is-open', opening);
        $(this).toggleClass('is-open', opening);
        if (opening) {
            // Pierwsze otwarcie → ustaw "teraz" w datetime-local
            // formacie 'YYYY-MM-DDTHH:MM'. Puste pole traktowane
            // przez endpoint jako "użyj NOW()".
            if (!$backdateInput.val()) {
                const now = new Date();
                const pad = (n) => String(n).padStart(2, '0');
                $backdateInput.val(
                    now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate())
                    + 'T' + pad(now.getHours()) + ':' + pad(now.getMinutes())
                );
            }
            setTimeout(function () { $backdateInput.trigger('focus'); }, 0);
        }
    });

    // ---- 3) Submit (POST → document-confirm-ajax.php) --------------

    $submitBtn.on('click', function () {
        if (currentPoId === null || currentPoId <= 0) {
            showInlineError('Nie wybrano zamówienia.');
            return;
        }
        if (!CONFIRM_ENDPOINT) {
            showInlineError('Brak URL endpointu (data-confirm-endpoint na modalu).');
            return;
        }
        // Backdate input → 'Y-m-d H:i:s' (handler wymaga tego formatu).
        // Puste → wysyłamy pusty string, serwer znormalizuje na null.
        let confirmedAt = '';
        const rawBackdate = ($backdateInput.val() || '').toString().trim();
        if (rawBackdate !== '') {
            const norm = rawBackdate.replace('T', ' ');
            confirmedAt = (norm.length === 16) ? norm + ':00' : norm;
        }
        const vendorPoNumber = ($vendorPoInput.val() || '').toString().trim();

        hideInlineError();
        setSubmitting(true);

        $.ajax({
            url: CONFIRM_ENDPOINT,
            method: 'POST',
            dataType: 'json',
            data: {
                id:               currentPoId,
                vendor_po_number: vendorPoNumber,
                confirmed_at:     confirmedAt
            }
        })
        .done(function (r) {
            if (!r || !r.success) {
                setSubmitting(false);
                showInlineError((r && r.error) || 'Nie udało się potwierdzić zamówienia.');
                return;
            }
            // Sukces — pokaż banner + przeładuj stronę po 1.2 s, żeby
            // operator zobaczył nowy stan (np. PO w /receipts queue
            // zniknie z kolejki, w /edit badge zmieni się na
            // "potwierdzone").
            const docNumber = r.document_number || ('#' + currentPoId);
            announceSuccess('Potwierdzono odbiór ' + docNumber + '. Stan: potwierdzone.');
            setTimeout(function () {
                window.location.reload();
            }, 1200);
        })
        .fail(function (xhr, status) {
            setSubmitting(false);
            const msg = (xhr && xhr.responseJSON && xhr.responseJSON.error)
                || status
                || 'Błąd sieci.';
            showInlineError(msg);
        });
    });

    // ---- 4) Cleanup po zamknięciu modala ---------------------------

    // Gdy modal zostanie schowany (przycisk Anuluj, X, ESC, klik w
    // static-backdrop nie zadziała), zerujemy stan i usuwamy
    // success-banner (żeby kolejne otwarcie nie pokazało starej
    // wiadomości).
    $modal.on('hidden.bs.modal', function () {
        currentPoId = null;
        hideInlineError();
        $errorBlock.removeClass('text-success').addClass('text-danger');
        resetBackdate();
        setSubmitting(false);
    });

});
