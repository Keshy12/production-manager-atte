/**
 * producer-edit-view.js
 *
 * UX helpers for the dedicated producer edit/create page.
 *
 * Three responsibilities:
 *   1. Dirty-form enable — the submit button starts disabled (rendered
 *      that way by the PHP view). Any user input — typing in a text
 *      field, toggling a radio — enables it. Once enabled, it stays
 *      enabled: the AJAX submit handler re-disables it on click and
 *      re-enables it on failure, so the operator can tweak the form
 *      further after an error.
 *   2. AJAX form submit — intercept the form's submit event, POST to
 *      producer-edit-save.php, show the response as an inline alert
 *      (success or error) in #prFormAlertHost. On a successful CREATE,
 *      the response includes `edit_url`; the JS navigates there so the
 *      operator lands on the edit page for the freshly-created
 *      producer. On a successful UPDATE, the JS stays on the page and
 *      shows the success alert (matching the vendor-parts edit flow).
 *   3. Collapsible form cards (all three, collapsed by default)
 *      - PHP renders them collapsed with a one-line read-only summary;
 *        JS keeps each summary live (the markup must stay identical to
 *        the one producer-edit-view.php renders on first paint),
 *        re-opens the card that holds a field the browser rejects, and
 *        drives the "Rozwiń/Zwiń wszystko" link.
 *
 * The form has no `action` attribute so the browser would POST to
 * the current URL on submit (the view file, which would just
 * re-render the form and lose the input). We intercept the submit
 * and POST the serialized form data to the dedicated save endpoint.
 */
(function() {
    'use strict';

    // ============================================================
    // Inline alert helper
    // ============================================================
    // Shows the server response message in #prFormAlertHost. Used
    // for both error (red) and success (green) replies. Idempotent:
    // if the alert already exists we just swap its body + kind.
    // Success / info / warning alerts auto-dismiss after a few seconds
    // so the operator can keep working without an extra click. Error
    // alerts stay until the operator dismisses them — the message is
    // action-required.
    function prFlash(message, kind) {
        kind = kind || 'danger';
        let $alert = $('#prFormAlert');
        if ($alert.length === 0) {
            $alert = $(
                '<div id="prFormAlert" class="alert alert-dismissible fade show" role="alert" style="display:none">'
                + '<i class="bi bi-exclamation-triangle"></i> '
                + '<span class="pr-form-alert-text"></span>'
                + '<button type="button" class="close" data-dismiss="alert" aria-label="Zamknij">'
                +   '<span aria-hidden="true">&times;</span>'
                + '</button>'
                + '</div>'
            );
        }
        // .prepend() przenosi istniejący element, więc alert zawsze
        // ląduje w hoście (nawet jeśli Bootstrap go wcześniej odpiął).
        $('#prFormAlertHost').prepend($alert);

        $alert.removeClass('alert-danger alert-success alert-warning alert-info')
              .addClass('alert-' + kind)
              .show();
        $alert.find('.pr-form-alert-text').text(message);

        if (prFlash._dismissTimer) {
            clearTimeout(prFlash._dismissTimer);
            prFlash._dismissTimer = null;
        }
        if (kind === 'success' || kind === 'info' || kind === 'warning') {
            prFlash._dismissTimer = setTimeout(function() {
                $alert.fadeOut(200, function() { $(this).remove(); });
            }, 3000);
        }
    }

    // Escapes text for HTML (same as the vendor page's helper).
    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    // ============================================================
    // Dirty-form enable + AJAX submit
    // ============================================================
    $(document).ready(function() {
        const $submitBtn = $('#prSubmitBtn');
        function enableSubmit() { $submitBtn.prop('disabled', false); }
        $('#prEditForm').on('input change', 'input, select, textarea', enableSubmit);

        // Ścieżka z COMPONENTS_PATH (header.js) — jak na stronie
        // dostawcy; zapas to dawna wartość na sztywno, gdyby stała
        // nie była zdefiniowana.
        const componentsPath = (typeof COMPONENTS_PATH !== 'undefined')
            ? COMPONENTS_PATH
            : window.location.origin + '/atte_ms_new/public_html/components';
        const saveUrl = componentsPath + '/Admin/Purchase/Producers/edit/producer-edit-save.php';

        $('#prEditForm').on('submit', function(e) {
            e.preventDefault();
            const $form = $(this);
            const $submit = $form.find('button[type="submit"]');
            $submit.prop('disabled', true);

            // Wszystkie pola leżą wewnątrz #prEditForm (także w
            // zwiniętych kartach), więc serialize() wystarcza.
            $.ajax({
                url: saveUrl,
                type: 'POST',
                data: $form.serialize(),
                dataType: 'json',
            })
            .done(function(r) {
                if (r && r.success) {
                    // On CREATE the save endpoint returns edit_url — navigate
                    // there so the operator lands on the edit page for the
                    // freshly-created producer (mirrors the cart-view.js
                    // "open in new tab" pattern, just in same-tab).
                    if (r.edit_url) {
                        // We can't reach the listing's history from here,
                        // so just navigate; the listing reloads on visit.
                        window.location.href = r.edit_url + '&created=1';
                        return;
                    }
                    // On UPDATE — stay on the page, show success alert.
                    prFlash(r.message || 'Zapisano zmiany.', 'success');
                    // Podsumowania zwiniętych kart odzwierciedlają zapis.
                    $form.trigger('pr:saved');
                    // Keep the submit button disabled — the form is now
                    // clean against the DB. Any further input re-enables
                    // it via the dirty-form handlers above.
                    $(window).scrollTop(0);
                } else {
                    const msg = (r && r.error) ? r.error : 'Nieznany błąd.';
                    prFlash(msg, 'danger');
                    $submit.prop('disabled', false);
                    $(window).scrollTop(0);
                }
            })
            .fail(function(xhr) {
                let msg = 'Błąd komunikacji z serwerem.';
                // The endpoint always returns JSON with {success, error}
                // — try to surface its message even on HTTP-level failures
                // (e.g. 500 from a hard crash that bypassed the catch).
                try {
                    const r = JSON.parse(xhr.responseText);
                    if (r && r.error) { msg = r.error; }
                } catch (e) { /* leave default */ }
                prFlash(msg, 'danger');
                $submit.prop('disabled', false);
                $(window).scrollTop(0);
            });
        });
    });

    // ============================================================
    // Zwijane karty formularza (wszystkie trzy)
    // ============================================================
    // Karty startują zwinięte (PHP: `collapsed` na przycisku, bez `show`
    // na ciele) i pokazują jednolinijkowe podsumowanie wartości. Zwinięcie
    // to tylko CSS (Bootstrap collapse, display:none) — pola zostają w DOM
    // i w #prEditForm, więc $form.serialize() wysyła je także ze zwiniętej
    // karty. Tutaj trzy rzeczy:
    //   1. podsumowania żyją razem z polami (i po zapisie, `pr:saved`) —
    //      format musi być identyczny z tym z producer-edit-view.php,
    //   2. jeśli przeglądarka odrzuca pole w zwiniętej karcie (wymagana
    //      nazwa), karta się rozwija i walidacja jest pokazana ponownie —
    //      bez tego submit po cichu nic nie robi, bo przeglądarka nie
    //      może ustawić fokusu na ukrytym polu,
    //   3. „Rozwiń wszystko" / „Zwiń wszystko" + klik w podsumowanie.
    $(document).ready(function() {
        const form = document.getElementById('prEditForm');
        if (!form) return;   // „Producent nie istnieje" — brak formularza

        const $form = $(form);
        const $bodies = $form.find('.pre-card > .collapse');

        // Tekst z pola, bez białych znaków na brzegach.
        function val(selector) {
            return String($(selector).val() == null ? '' : $(selector).val()).trim();
        }

        // Wartość do jednej linii (kolejne białe znaki → jedna spacja).
        function oneLine(s) {
            return String(s).replace(/\s+/g, ' ').trim();
        }

        // Ustawia linijkę podsumowania: html (już zabezpieczony przez
        // esc()) + title. Bez podanego `title` jest nim sam tekst linijki.
        function setSummary(id, html, title) {
            const $el = $('#' + id);
            $el.html(html);
            $el.attr('title', title === undefined ? $el.text() : title);
        }

        // Pole bez wartości: sama nazwa pola w stylu „brak".
        function missing(name) {
            return '<span class="pre-sum-missing">' + esc(name) + '</span>';
        }

        // ---- Dane podstawowe: nazwa ----
        function refreshBasicSummary() {
            const name = val('#pr_edit_name');
            setSummary('prBasicSummary',
                name !== '' ? '<strong>' + esc(name) + '</strong>' : missing('nazwa'),
                'nazwa: ' + (name !== '' ? oneLine(name) : '—'));
        }

        // ---- Status ----
        function refreshStatusSummary() {
            const active = $form.find('input[name="isActive"]:checked').val() === '1';
            setSummary('prStatusSummary',
                '<span class="pre-sum-dot ' + (active ? 'is-on' : 'is-off') + '" aria-hidden="true"></span>'
                + (active ? 'Aktywny' : 'Nieaktywny'));
        }

        // ---- Więcej: komentarz ----
        function refreshMoreSummary() {
            const comment = oneLine(val('#pr_edit_comment'));
            setSummary('prMoreSummary',
                comment !== '' ? esc(comment) : missing('komentarz'),
                'komentarz: ' + (comment !== '' ? comment : '—'));
        }

        function refreshSummaries() {
            refreshBasicSummary();
            refreshStatusSummary();
            refreshMoreSummary();
        }
        $form.on('input change', 'input, select, textarea', refreshSummaries);
        // Po udanym zapisie podsumowania odzwierciedlają to, co zapisano.
        $form.on('pr:saved', refreshSummaries);

        // ---- Walidacja pola w zwiniętej karcie ----
        // `invalid` nie bąbelkuje — nasłuch w fazie przechwytywania.
        // Przeglądarka wysyła `invalid` dla KAŻDEGO błędnego pola, więc
        // licznik czeka, aż otworzą się wszystkie potrzebne karty, i
        // dopiero wtedy raz wywołuje reportValidity() (dymek ląduje na
        // pierwszym błędnym, już widocznym polu).
        let opening = 0;
        form.addEventListener('invalid', function(e) {
            const $body = $(e.target).closest('.pre-card > .collapse');
            if ($body.length === 0) return;
            if ($body.hasClass('show') || $body.hasClass('collapsing')) return;
            opening++;
            $body.one('shown.bs.collapse', function() {
                opening--;
                if (opening === 0) { form.reportValidity(); }
            });
            $body.collapse('show');
        }, true);

        // ---- Klik w podsumowanie rozwija kartę ----
        // Cała zwinięta karta jest jednym celem. Klawiaturą i czytnikiem
        // ekranu służy przycisk nagłówka (podsumowanie nie jest w
        // kolejności tabulacji). Zaznaczanie tekstu nie rozwija.
        $form.on('click', '.pre-card-summary', function() {
            const sel = window.getSelection ? String(window.getSelection()) : '';
            if (sel !== '') return;
            $(this).closest('.pre-card').find('> .pre-card-toggle-h > .pre-card-head--toggle')[0].click();
        });

        // ---- Rozwiń wszystko / Zwiń wszystko ----
        const $toggleAll = $('#prToggleAllCards');

        function syncToggleAll() {
            const anyClosed = $bodies.not('.show').length > 0;
            $toggleAll.find('.pre-tools-label').text(anyClosed ? 'Rozwiń wszystko' : 'Zwiń wszystko');
            $toggleAll.find('.bi')
                .toggleClass('bi-arrows-expand', anyClosed)
                .toggleClass('bi-arrows-collapse', !anyClosed);
        }
        $bodies.on('shown.bs.collapse hidden.bs.collapse', syncToggleAll);

        $toggleAll.on('click', function() {
            const anyClosed = $bodies.not('.show').length > 0;
            // Tylko karty, które nie są w trakcie animacji.
            $bodies.not('.collapsing').collapse(anyClosed ? 'show' : 'hide');
        });
        syncToggleAll();
    });
})();
