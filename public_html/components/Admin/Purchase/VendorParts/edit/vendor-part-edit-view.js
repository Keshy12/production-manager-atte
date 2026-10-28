/**
 * vendor-part-edit-view.js
 *
 * UX helpers for the dedicated vendor-part edit/create page.
 *
 * Four responsibilities:
 *   1. Multi-pack row UX — append an empty row on "Dodaj opakowanie",
 *      remove on ×, no enforced minimum (parts that aren't packaged
 *      are allowed to have zero pack sizes).
 *   2. Dirty-form enable — the submit button starts disabled (rendered
 *      that way by the PHP view). Any user input — typing, toggling a
 *      radio, picking in a bootstrap-select, adding/removing a pack row —
 *      enables it. Once enabled, it stays enabled.
 *   3. AJAX form submit — intercept the form's submit event, POST to
 *      vendor-part-edit-save.php, show the response as an inline
 *      alert (success or error) in #vpFormAlertHost. On a successful
 *      CREATE the response includes `edit_url`; the JS navigates there so
 *      the operator lands on the edit page for the freshly-created
 *      vendor-part (mirrors the cart-view.js "open in new tab" pattern).
 *      On a successful UPDATE the JS stays on the page and shows an
 *      inline success alert.
 *   4. Collapsible form cards (all five, collapsed by default)
 *      - PHP renders them collapsed with a one-line read-only summary;
 *        JS keeps each summary live (the markup must stay identical to
 *        the one vendor-part-edit-view.php renders on first paint),
 *        re-opens the card that holds a field the browser rejects, and
 *        drives the "Rozwiń/Zwiń wszystko" link.
 *
 * The number inputs use `name="pack_quantities[]"`, which PHP
 * parses into $_POST['pack_quantities'] as an array — standard
 * form serialization, no client-side magic needed.
 */
(function() {
    'use strict';

    // ============================================================
    // Inline alert helper
    // ============================================================
    // Shows the server response message in #vpFormAlertHost. Used
    // for both error (red) and success (green) replies. Idempotent:
    // if the alert already exists we just swap its body + kind.
    // Success / info / warning alerts auto-dismiss after a few seconds
    // so the operator can keep working without an extra click. Error
    // alerts stay until the operator dismisses them — the message is
    // action-required.
    function vpFlash(message, kind) {
        kind = kind || 'danger';
        let $alert = $('#vpFormAlert');
        if ($alert.length === 0) {
            $alert = $(
                '<div id="vpFormAlert" class="alert alert-dismissible fade show" role="alert" style="display:none">'
                + '<i class="bi bi-exclamation-triangle"></i> '
                + '<span class="vp-form-alert-text"></span>'
                + '<button type="button" class="close" data-dismiss="alert" aria-label="Zamknij">'
                +   '<span aria-hidden="true">&times;</span>'
                + '</button>'
                + '</div>'
            );
        }
        // .prepend() przenosi istniejący element, więc alert zawsze
        // ląduje w hoście (nawet jeśli Bootstrap go wcześniej odpiął).
        $('#vpFormAlertHost').prepend($alert);

        $alert.removeClass('alert-danger alert-success alert-warning alert-info')
              .addClass('alert-' + kind)
              .show();
        $alert.find('.vp-form-alert-text').text(message);

        if (vpFlash._dismissTimer) {
            clearTimeout(vpFlash._dismissTimer);
            vpFlash._dismissTimer = null;
        }
        if (kind === 'success' || kind === 'info' || kind === 'warning') {
            vpFlash._dismissTimer = setTimeout(function() {
                $alert.fadeOut(200, function() { $(this).remove(); });
            }, 3000);
        }
    }

    // Escapes text for HTML (same as the vendor / producer pages' helper).
    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    $(document).ready(function() {
        const form = document.getElementById('vpEditForm');
        if (!form) return;   // „Artykuł nie istnieje" — brak formularza

        const $form = $(form);
        const $list = $('#vpPackList');
        const $addBtn = $('#vpAddPackBtn');
        const $submitBtn = $('#vpSubmitBtn');

        // Ścieżka z COMPONENTS_PATH (header.js) — jak na stronach
        // dostawcy i producenta; zapas to dawna wartość na sztywno,
        // gdyby stała nie była zdefiniowana.
        const componentsPath = (typeof COMPONENTS_PATH !== 'undefined')
            ? COMPONENTS_PATH
            : window.location.origin + '/atte_ms_new/public_html/components';
        const saveUrl = componentsPath + '/Admin/Purchase/VendorParts/edit/vendor-part-edit-save.php';

        // ============================================================
        // Dirty-form enable
        // ============================================================
        // Przycisk zapisu startuje wyłączony. Każda zmiana pola, wybór
        // w pickerze albo dodanie/usunięcie opakowania go włącza. Pole
        // wyszukiwania wewnątrz otwartego pickera (.bs-searchbox) leży w
        // formularzu, ale niczego nie zmienia — nie liczy się.
        function enableSubmit() { $submitBtn.prop('disabled', false); }

        function fromSearchBox(e) {
            return $(e.target).closest('.bs-searchbox').length > 0;
        }

        // ============================================================
        // Opakowania: dodawanie / usuwanie wierszy
        // ============================================================
        // Mirror the rendering shape used by vendor-part-edit-view.php so
        // a server-side row and a JS-added row are identical (this exact
        // markup is also what the page ships on first paint).
        // step="0.0001" matches the smallest DECIMAL precision the
        // project ever asks for (see list__vendor_part_pack).
        function rowHtml(value) {
            const v = (value == null) ? '' : String(value);
            return ''
                + '<div class="vp-pack-row">'
                +   '<input type="number" name="pack_quantities[]" '
                +          'class="form-control" '
                +          'step="0.0001" min="0.0001" required '
                +          'value="' + esc(v) + '" placeholder="np. 100" '
                +          'aria-label="Liczba sztuk w pełnym opakowaniu">'
                +   '<button type="button" class="btn rx-btn-quiet vp-pack-remove" '
                +           'title="Usuń opakowanie" aria-label="Usuń opakowanie">'
                +     '<i class="bi bi-x-lg"></i>'
                +   '</button>'
                + '</div>';
        }

        $addBtn.on('click', function() {
            $list.append(rowHtml(''));
            enableSubmit();
            refreshSummaries();
            // Focus the new input so the operator can type immediately.
            $list.children('.vp-pack-row').last().find('input[type="number"]').trigger('focus');
        });

        // Delegate the remove click so it works for both server-
        // rendered and JS-added rows. Zero packs is a valid state
        // (parts that aren't packaged), so × is always enabled.
        $list.on('click', '.vp-pack-remove', function() {
            $(this).closest('.vp-pack-row').remove();
            enableSubmit();
            refreshSummaries();
            // Fokus nie może zostać na usuniętym przycisku.
            $addBtn.trigger('focus');
        });

        // ============================================================
        // Zwijane karty formularza (wszystkie pięć)
        // ============================================================
        // Karty startują zwinięte (PHP: `collapsed` na przycisku, bez `show`
        // na ciele) i pokazują jednolinijkowe podsumowanie wartości. Zwinięcie
        // to tylko CSS (Bootstrap collapse, display:none) — pola zostają w DOM
        // i w #vpEditForm, więc $form.serialize() wysyła je także ze zwiniętej
        // karty (łącznie ze wszystkimi pack_quantities[]). Tutaj cztery rzeczy:
        //   1. podsumowania żyją razem z polami (i po zapisie, `vp:saved`) —
        //      format musi być identyczny z tym z vendor-part-edit-view.php,
        //   2. jeśli przeglądarka odrzuca pole w zwiniętej karcie (wymagany
        //      picker, numer u dostawcy, puste opakowanie), karta się rozwija
        //      i walidacja jest pokazana ponownie — bez tego submit po cichu
        //      nic nie robi, bo przeglądarka nie może ustawić fokusu na
        //      ukrytym polu,
        //   3. pickery w świeżo rozwiniętej karcie dostają refresh,
        //   4. „Rozwiń wszystko" / „Zwiń wszystko" + klik w podsumowanie.
        const $bodies = $form.find('.vpe-card > .collapse');
        const SEP = ' · ';

        // Picker jest inicjalizowany w ukrytym kontenerze (karta zwinięta),
        // a przed window.load (data-api bootstrap-select) natywny <select>
        // bez `selected` pokazuje pierwszą opcję. Bez wybranej opcji ma być
        // „nic" — tak jak po inicjalizacji pickera.
        $form.find('select.selectpicker').each(function() {
            if ($(this).find('option[selected]').length === 0) {
                this.selectedIndex = -1;
            }
        });

        // Tekst z pola, bez białych znaków na brzegach.
        function val(selector) {
            return String($(selector).val() == null ? '' : $(selector).val()).trim();
        }

        // Etykieta wybranej opcji pickera (nazwa części, nie jej opis).
        function pickerLabel(selector) {
            return $(selector).find('option:selected').first().text().trim();
        }

        // Wartość do jednej linii (kolejne białe znaki → jedna spacja).
        function oneLine(s) {
            return String(s).replace(/\s+/g, ' ').trim();
        }

        // Tooltip: „pole: wartość", „—" gdy puste.
        function tip(label, value) {
            return label + ': ' + (value === '' ? '—' : oneLine(value));
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
            return '<span class="vpe-sum-missing">' + esc(name) + '</span>';
        }

        // Wypełnione pole z małym kluczem przed wartością („DOSTAWCA TME");
        // ten sam znacznik co $vpKeyed w widoku.
        function keyed(key, valueHtml) {
            return '<span class="vpe-sum-key">' + esc(key) + '</span> ' + valueHtml;
        }

        // Wielkość opakowania bez zer końcowych (100.0000 → 100, 0.5 → 0.5);
        // tak samo jak $vpFmtQty w widoku.
        function fmtQty(v) {
            const f = parseFloat(v);
            if (isNaN(f)) { return ''; }
            return String(Math.round(f * 10000) / 10000);
        }

        // ---- Dane podstawowe: część · dostawca · producent · JM ----
        function refreshBasicSummary() {
            const part = pickerLabel('#vp_edit_parts_id');
            const vendor = pickerLabel('#vp_edit_vendor_id');
            const producer = pickerLabel('#vp_edit_producer_id');
            const unit = pickerLabel('#vp_edit_vendor_jm_id');
            setSummary('vpBasicSummary',
                [
                    part !== ''     ? keyed('część', '<strong>' + esc(part) + '</strong>') : missing('część'),
                    vendor !== ''   ? keyed('dostawca', esc(vendor))   : missing('dostawca'),
                    producer !== '' ? keyed('producent', esc(producer)) : missing('producent'),
                    unit !== ''     ? keyed('JM', esc(unit))           : missing('JM')
                ].join(SEP),
                [tip('część', part), tip('dostawca', vendor), tip('producent', producer), tip('JM', unit)].join(SEP));
        }

        // ---- Numery części: nr dostawcy · nr producenta ----
        function refreshNumbersSummary() {
            const vpn = val('#vp_edit_vendor_part_no');
            const ppn = val('#vp_edit_producer_part_no');
            setSummary('vpNumbersSummary',
                [
                    vpn !== '' ? keyed('nr dostawcy', '<strong>' + esc(vpn) + '</strong>') : missing('nr dostawcy'),
                    ppn !== '' ? keyed('nr producenta', esc(ppn)) : missing('nr producenta')
                ].join(SEP),
                [tip('nr dostawcy', vpn), tip('nr producenta', ppn)].join(SEP));
        }

        // ---- Opakowania: „100 · 500 · 2500" albo „opakowania" jako brak ----
        // Pusty (jeszcze niewypełniony) wiersz nie jest opakowaniem.
        function refreshPacksSummary() {
            const sizes = [];
            $list.find('input[name="pack_quantities[]"]').each(function() {
                const q = fmtQty($(this).val());
                if (q !== '') { sizes.push(q); }
            });
            const text = sizes.join(SEP);
            setSummary('vpPacksSummary',
                text !== '' ? esc(text) : missing('opakowania'),
                tip('opakowania', text));
        }

        // ---- Status ----
        function refreshStatusSummary() {
            const active = $form.find('input[name="isActive"]:checked').val() === '1';
            setSummary('vpStatusSummary',
                '<span class="vpe-sum-dot ' + (active ? 'is-on' : 'is-off') + '" aria-hidden="true"></span>'
                + (active ? 'Aktywny' : 'Nieaktywny'));
        }

        // ---- Więcej: komentarz ----
        function refreshMoreSummary() {
            const comment = oneLine(val('#vp_edit_comment'));
            setSummary('vpMoreSummary',
                comment !== '' ? esc(comment) : missing('komentarz'),
                tip('komentarz', comment));
        }

        // ---- Skróty w nagłówku: karta dostawcy / producenta ----
        // Podążają za tym, co jest teraz wybrane w pickerach (a nie tylko
        // za zapisanym stanem), żeby skrót i podsumowanie mówiły to samo.
        function refreshRelLink(linkId, selectSelector) {
            const $a = $('#' + linkId);
            if ($a.length === 0) { return; }
            const id = parseInt($(selectSelector).val(), 10) || 0;
            if (id > 0) { $a.attr('href', $a.attr('data-base') + id); }
            $a.attr('title', $a.attr('data-label') + ': ' + pickerLabel(selectSelector));
        }

        function refreshSummaries() {
            refreshBasicSummary();
            refreshNumbersSummary();
            refreshPacksSummary();
            refreshStatusSummary();
            refreshMoreSummary();
            refreshRelLink('vpVendorLink', '#vp_edit_vendor_id');
            refreshRelLink('vpProducerLink', '#vp_edit_producer_id');
        }

        // `change.bs.select` / `changed.bs.select` — bootstrap-select zgłasza
        // wybór także własnymi zdarzeniami (w tym przy selectpicker('val')).
        $form.on('input change change.bs.select changed.bs.select', 'input, select, textarea', function(e) {
            if (fromSearchBox(e)) { return; }
            enableSubmit();
            refreshSummaries();
            // Wybór w pickerze zdejmuje czerwoną obwódkę po odrzuceniu pola.
            if (e.target.tagName === 'SELECT') {
                $(e.target).closest('.vpe-picker').removeClass('is-invalid');
            }
        });
        // Po udanym zapisie podsumowania odzwierciedlają to, co zapisano.
        $form.on('vp:saved', refreshSummaries);

        // ---- Walidacja pola w zwiniętej karcie ----
        // `invalid` nie bąbelkuje — nasłuch w fazie przechwytywania.
        // Przeglądarka wysyła `invalid` dla KAŻDEGO błędnego pola, więc
        // licznik czeka, aż otworzą się wszystkie potrzebne karty, i
        // dopiero wtedy raz wywołuje reportValidity() (dymek ląduje na
        // pierwszym błędnym, już widocznym polu). Dotyczy też pól
        // dodanych przez JS po załadowaniu strony (nasłuch jest na formularzu).
        //
        // Pusty wymagany picker: bootstrap-select chowa natywny <select>
        // (opacity 0, szerokość .5px), więc fokus trzeba przekazać jego
        // przyciskowi — inaczej operator wylądowałby na niewidocznym polu.
        function focusFirstInvalid() {
            const first = form.querySelector('input:invalid, select:invalid, textarea:invalid');
            if (first && first.tagName === 'SELECT' && $(first).hasClass('selectpicker')) {
                $(first).closest('.bootstrap-select').find('> button.dropdown-toggle').first().trigger('focus');
            }
        }

        let opening = 0;
        form.addEventListener('invalid', function(e) {
            const $target = $(e.target);
            if ($target.is('select.selectpicker')) {
                $target.closest('.vpe-picker').addClass('is-invalid');
            }
            const $body = $target.closest('.vpe-card > .collapse');
            if ($body.length === 0) return;
            if ($body.hasClass('show') || $body.hasClass('collapsing')) return;
            opening++;
            $body.one('shown.bs.collapse', function() {
                opening--;
                if (opening === 0) {
                    form.reportValidity();
                    focusFirstInvalid();
                }
            });
            $body.collapse('show');
        }, true);

        // ---- Picker w świeżo rozwiniętej karcie ----
        // Szerokość to 100% z CSS-a, ale odświeżenie po rozwinięciu to
        // tania siatka bezpieczeństwa (picker był inicjalizowany w
        // ukrytym kontenerze).
        $bodies.on('shown.bs.collapse', function() {
            $(this).find('.selectpicker').selectpicker('refresh');
        });
        $(window).on('load', function() {
            $form.find('.selectpicker').selectpicker('refresh');
        });

        // ---- Klik w podsumowanie rozwija kartę ----
        // Cała zwinięta karta jest jednym celem. Klawiaturą i czytnikiem
        // ekranu służy przycisk nagłówka (podsumowanie nie jest w
        // kolejności tabulacji). Zaznaczanie tekstu nie rozwija.
        $form.on('click', '.vpe-card-summary', function() {
            const sel = window.getSelection ? String(window.getSelection()) : '';
            if (sel !== '') return;
            $(this).closest('.vpe-card').find('> .vpe-card-toggle-h > .vpe-card-head--toggle')[0].click();
        });

        // ---- Rozwiń wszystko / Zwiń wszystko ----
        const $toggleAll = $('#vpToggleAllCards');

        function syncToggleAll() {
            const anyClosed = $bodies.not('.show').length > 0;
            $toggleAll.find('.vpe-tools-label').text(anyClosed ? 'Rozwiń wszystko' : 'Zwiń wszystko');
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

        // ============================================================
        // AJAX form submit
        // ============================================================
        // The form has no `action` attribute so the browser would
        // POST to the current URL on submit (the view file, which
        // would just re-render the form and lose the input). We
        // intercept the submit, POST the serialized form data to
        // the dedicated save endpoint, and show the JSON response
        // as an inline alert.
        $form.on('submit', function(e) {
            e.preventDefault();
            const $submit = $form.find('button[type="submit"]');
            $submit.prop('disabled', true);

            // Wszystkie pola leżą wewnątrz #vpEditForm (także w
            // zwiniętych kartach), więc serialize() wystarcza.
            $.ajax({
                url: saveUrl,
                type: 'POST',
                data: $form.serialize(),
                dataType: 'json',
            })
            .done(function(r) {
                if (r && r.success) {
                    // On CREATE the save endpoint returns `edit_url` —
                    // navigate there so the operator lands on the edit
                    // page for the freshly-created vendor-part. The
                    // form fields then reflect the canonical DB state
                    // (id visible in the heading, packs pre-populated)
                    // and further submits do UPDATEs instead of a
                    // duplicate CREATE.
                    if (r.edit_url) {
                        window.location.href = r.edit_url + '&created=1';
                        return;
                    }
                    // On UPDATE — stay on the page, show success alert.
                    vpFlash(r.message || 'Zapisano zmiany.', 'success');
                    // Podsumowania zwiniętych kart odzwierciedlają zapis.
                    $form.trigger('vp:saved');
                    // Keep the submit button disabled — the form is
                    // now clean against the DB. Any further input
                    // re-enables it via the dirty-form handlers above.
                    $(window).scrollTop(0);
                } else {
                    const msg = (r && r.error) ? r.error : 'Nieznany błąd.';
                    vpFlash(msg, 'danger');
                    $submit.prop('disabled', false);
                    // Scroll to the top so the alert is visible.
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
                vpFlash(msg, 'danger');
                $submit.prop('disabled', false);
                $(window).scrollTop(0);
            });
        });
    });
})();
