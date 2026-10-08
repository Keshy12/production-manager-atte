/**
 * email-draft-edit-view.js
 *
 * UX strony edycji / tworzenia globalnego szablonu wiadomości e-mail.
 * Pięć spraw:
 *
 *   1. Dirty-form enable + AJAX submit
 *      - Przycisk „Zapisz szablon" startuje wyłączony (renderuje go
 *        tak PHP). Dowolna zmiana w formularzu go włącza, podświetla
 *        delikatnym pierścieniem i pokazuje „niezapisane zmiany"
 *        w stopce. Udany zapis gasi jedno i drugie.
 *      - Submit POST-uje serializowany formularz do
 *        email-draft-edit-save.php. Przy CREATE odpowiedź niesie
 *        `edit_url` — przechodzimy tam, żeby operator wylądował na
 *        stronie edycji świeżo utworzonego szablonu. Przy UPDATE
 *        zostajemy na stronie i pokazujemy alert sukcesu.
 *
 *   2. Chipy ze zmiennymi
 *      - Klik wstawia token {zmienna} w miejscu kursora w polu
 *        wskazanym przez data-target (temat albo treść).
 *      - Pigułki treści siedzą w zwijanym panelu, więc klik musi
 *        zatrzymać propagację, żeby nie złożył katalogu.
 *
 *   5. Zwijany katalog zmiennych
 *      - edWireChevron() podmienia bi-chevron-down / bi-chevron-up
 *        na zdarzeniach shown/hidden.bs.collapse — Bootstrap 4 sam
 *        tego nie robi.
 *
 *   3. Podgląd na żywo
 *      - Renderuje temat + treść na danych przykładowych.
 *      - Zwinięty domyślnie; przelicza się przy każdym `input`.
 *
 *   4. Licznik znaków treści
 *      - Czerwony powyżej 1500 znaków — patrz limit długości linku
 *        mailto: w refreshMailtoHref() w documents-send.js.
 *
 * Parser (ED_TEMPLATE_VAR_RE + edApplyTemplate) i dane przykładowe
 * mieszkały wcześniej w vendor-edit-view.js; przeniesione tutaj razem
 * z całym edytorem szablonów.
 */
(function() {
    'use strict';

    // ============================================================
    // Inline alert helper
    // ============================================================
    // Dismissible alert Bootstrapa wstrzykiwany na górę kontenera.
    // Idempotentny: kolejne wywołanie podmienia treść istniejącego
    // alertu zamiast dokładać drugi. Sukces / info / warning znikają
    // same po 3 s, błędy zostają do ręcznego zamknięcia.

    // Ikona niesie ten sam komunikat co kolor — operator rozpoznaje
    // „zapisane" po zielonym ptaszku szybciej niż po czytaniu tekstu.
    const ED_ALERT_ICONS = {
        success: 'bi-check-circle-fill',
        info:    'bi-info-circle-fill',
        warning: 'bi-exclamation-triangle-fill',
        danger:  'bi-exclamation-octagon-fill',
    };

    function edFlash(message, kind) {
        kind = kind || 'danger';
        let $alert = $('#edFormAlert');
        if ($alert.length === 0) {
            $alert = $(
                '<div id="edFormAlert" class="alert alert-dismissible fade show d-flex align-items-center" role="alert" style="display:none">'
                + '<i class="bi ed-form-alert-icon mr-2" aria-hidden="true"></i>'
                + '<span class="ed-form-alert-text"></span>'
                + '<button type="button" class="close" data-dismiss="alert" aria-label="Zamknij">'
                +   '<span aria-hidden="true">&times;</span>'
                + '</button>'
                + '</div>'
            );
            $('.container-fluid.w-75').first().prepend($alert);
        }
        $alert.removeClass('alert-danger alert-success alert-warning alert-info')
              .addClass('alert-' + kind)
              .show();
        $alert.find('.ed-form-alert-icon')
              .removeClass(Object.keys(ED_ALERT_ICONS).map(function(k) {
                  return ED_ALERT_ICONS[k];
              }).join(' '))
              .addClass(ED_ALERT_ICONS[kind] || ED_ALERT_ICONS.danger);
        $alert.find('.ed-form-alert-text').text(message);

        if (edFlash._dismissTimer) {
            clearTimeout(edFlash._dismissTimer);
            edFlash._dismissTimer = null;
        }
        if (kind === 'success' || kind === 'info' || kind === 'warning') {
            edFlash._dismissTimer = setTimeout(function() {
                $alert.fadeOut(200, function() { $(this).remove(); });
            }, 3000);
        }
    }

    // ============================================================
    // Parser szablonu
    // ============================================================
    //
    // Składnia zmiennych: {nazwa} — pojedyncze klamry, ściśle małe
    // litery, bez tolerancji na spacje, identyfikator
    // [a-z][a-z0-9_]{0,31}. Nieznana zmienna zostaje w tekście jako
    // literał; znana, ale pusta renderuje się jako pusty string.
    //
    // Ten regex i edApplyTemplate() są ŚWIADOMIE zduplikowane z
    // public_html/components/Admin/Purchase/Documents/documents-send.js.
    // Projekt nie ma systemu modułów, a oba pliki ładują się na
    // rozłącznych stronach (edytor szablonów vs. kreator wysyłki), więc
    // ~15 linii duplikatu jest tańsze niż nowy współdzielony plik i
    // dodatkowy <script> w headerze. Przy zmianie składni trzeba
    // ruszyć OBA miejsca — stąd ten komentarz w obu plikach.
    const ED_TEMPLATE_VAR_RE = /\{([a-z][a-z0-9_]{0,31})\}/g;

    function edApplyTemplate(tpl, vars) {
        if (tpl === null || tpl === undefined) return '';
        return String(tpl).replace(ED_TEMPLATE_VAR_RE, function (token, name) {
            // hasOwnProperty, NIE `name in vars` — `in` łapie też
            // prototyp Object, więc {constructor} czy {tostring}
            // podstawiłyby śmieci zamiast zostać literałem.
            if (!Object.prototype.hasOwnProperty.call(vars, name)) return token;
            const v = vars[name];
            return (v === null || v === undefined) ? '' : String(v);
        });
    }

    // Dane przykładowe do podglądu. `items` jest renderowane osobno
    // przez AtteItemsFormat.plainText(sampleLines), żeby podgląd nie
    // kłamał o wyglądzie listy pozycji (identyczne źródło prawdy jak
    // kreator wysyłki w documents-send.js).
    // {vendor_address} celowo nie ma tu wpisu — kreator wysyłki nie
    // podstawia już adresu dostawcy, więc podgląd musi pokazać ten
    // token dosłownie, tak jak zrobi to prawdziwa wysyłka.
    const ED_SAMPLE_VARS = {
        vendor_name:            'Przykładowy Dostawca Sp. z o.o.',
        vendor_contact_name:    'Anna Kowalska',
        vendor_contact_email:   'a.kowalska@example.com',
        document_type:          'zapytanie',
        document_number:        'RFQ/2026/0001',
        // Numer u dostawcy. Podgląd pokazuje wartość RÓŻNĄ od
        // document_number, żeby operator widział, że to dwa niezależne
        // pola. Gdyby tu wstać ten sam tekst, nie odróżniłby fallbacku
        // (numeru naszego) od faktycznego numeru od dostawcy.
        vendor_document_number: 'RFQ/DOST/2026/077',
        // Podgląd pokazuje kontekst PO powstałego z konwersji — oba
        // tokeny puste dla RFQ i dla PO bez powiązanego zapytania.
        rfq_number:                 'RFQ/2026/0001',
        rfq_vendor_document_number: 'RFQ/DOST/2026/077',
        expected_reply_date:    '2026-10-15',
        expected_delivery_date: '2026-11-02',
        document_comment:       'Pilne — linia SMD czeka na komplet.',
        created_by_name:        'Jan Nowak',
        created_at:             '2026-09-28 14:32',
        item_count:             '2',
    };

    // Próg ostrzeżenia dla treści. Powyżej ~1500 znaków link mailto:
    // w kreatorze wysyłki przestaje mieścić treść (patrz
    // refreshMailtoHref w documents-send.js) i operator musi wkleić ją
    // ze schowka — warto to zasygnalizować już w edytorze.
    const ED_BODY_SOFT_LIMIT = 1500;

    /**
     * Przewija element do widoku tylko wtedy, gdy wystaje poza okno —
     * inaczej klik w pigułkę szarpałby stroną przy każdym wstawieniu.
     */
    function edScrollIntoViewIfNeeded(el) {
        if (!el || typeof el.getBoundingClientRect !== 'function') return;
        const rect = el.getBoundingClientRect();
        const vh   = window.innerHeight || document.documentElement.clientHeight;
        // 80 px zapasu na sticky header projektu.
        if (rect.top < 80 || rect.bottom > vh) {
            $('html, body').animate({
                scrollTop: $(el).offset().top - 120,
            }, 200);
        }
    }

    /**
     * Wstawia `text` w miejscu kursora w polu $field. Gdy jest
     * zaznaczenie — podmienia je. Po wstawieniu ustawia kursor za
     * wstawionym tokenem, żeby można było pisać dalej, i wraca
     * fokusem do pola (operator wstawia token i pisze dalej, nie
     * zostaje z fokusem na pigułce).
     */
    function insertAtCursor($field, text) {
        const el = $field.get(0);
        if (!el) return;
        const value = String($field.val() || '');
        // selectionStart/End są null dla typów, które ich nie wspierają;
        // wtedy dopisujemy na koniec.
        const hasSel = (typeof el.selectionStart === 'number'
                     && typeof el.selectionEnd === 'number');
        const start = hasSel ? el.selectionStart : value.length;
        const end   = hasSel ? el.selectionEnd   : value.length;

        $field.val(value.slice(0, start) + text + value.slice(end));
        const caret = start + text.length;
        edScrollIntoViewIfNeeded(el);
        el.focus();
        if (hasSel) { el.setSelectionRange(caret, caret); }
        // `input` ręcznie — .val() go nie odpala, a od niego zależą
        // licznik znaków, podgląd i włączenie przycisku „Zapisz”.
        $field.trigger('input');
    }

    /**
     * Podmienia chevron w nagłówku zwijanej sekcji na zgodny ze
     * stanem. Bootstrap 4 nie robi tego sam — nagłówek zostaje
     * z „w dół" nawet przy rozwiniętym panelu.
     */
    function edWireChevron(collapseSel, headerSel) {
        const $collapse = $(collapseSel);
        const $icon     = $(headerSel).find('.bi-chevron-down, .bi-chevron-up');
        if ($collapse.length === 0 || $icon.length === 0) return;

        function setIcon(isOpen) {
            $icon.toggleClass('bi-chevron-up', isOpen)
                 .toggleClass('bi-chevron-down', !isOpen);
        }
        setIcon($collapse.hasClass('show'));
        $collapse.on('shown.bs.collapse',  function() { setIcon(true);  });
        $collapse.on('hidden.bs.collapse', function() { setIcon(false); });
    }

    /**
     * Zbiera wartości formularza do payloadu POST. Wszystkie pola leżą
     * wewnątrz #edEditForm, więc moglibyśmy użyć serialize() — robimy
     * to jednak jawnie, żeby trim() nazwy i tematu działał po stronie
     * klienta tak samo jak po stronie serwera (operator nie zobaczy
     * „zapisane", a potem innej wartości po odświeżeniu).
     */
    function edTemplatePostData() {
        const $form = $('#edEditForm');
        return {
            id:       String($form.find('input[name="id"]').val() || '0'),
            name:     String($('#ed_name').val() || '').trim(),
            subject:  String($('#ed_subject').val() || '').trim(),
            body:     String($('#ed_body').val() || '').trim(),
            isActive: String($form.find('input[name="isActive"]:checked').val() || '1'),
            // Klucz snake_case, bo tego oczekuje email-draft-edit-save.php
            // ($_POST['applies_to']). Fallback 'both' pilnuje przypadku,
            // w którym żadne radio nie jest zaznaczone (ręcznie zepsuty
            // DOM) — serwer odrzuca wszystko spoza rfq/po/both, więc
            // pusty string dałby tam błąd zamiast sensownego domyślnego.
            applies_to: String($form.find('input[name="applies_to"]:checked').val() || 'both'),
            // Globalne flagi „Domyślny dla RFQ / PO”. Odznaczony checkbox
            // nie wchodzi do zwykłego POST-a, więc wysyłamy jawnie '1' / '0'
            // — serwer widzi wtedy odznaczenie i czyści flagę w bazie.
            isDefaultRfq: $form.find('input[name="isDefaultRfq"]').is(':checked') ? '1' : '0',
            isDefaultPo:  $form.find('input[name="isDefaultPo"]').is(':checked')  ? '1' : '0',
        };
    }

    $(document).ready(function() {
        const $form = $('#edEditForm');
        if ($form.length === 0) return;   // np. widok „szablon nie istnieje"

        const $submitBtn   = $('#edSubmitBtn');
        const $name        = $('#ed_name');
        const $subject     = $('#ed_subject');
        const $body        = $('#ed_body');
        const $counter     = $('#edTemplateCounter');
        const $previewWrap = $('#edPreviewWrap');
        const $previewSubj = $('#edPreviewSubject');
        const $previewBody = $('#edPreviewBody');
        const $previewTgl  = $('#edPreviewToggle');

        const saveUrl = COMPONENTS_PATH
            + '/Admin/Purchase/EmailDrafts/edit/email-draft-edit-save.php';

        // ---- Dirty-form enable ----
        // Checkbox podglądu nie jest częścią zapisu — nie brudzi formularza.
        const $dirtyHint = $('#edDirtyHint');
        function edMarkDirty() {
            if ($submitBtn.prop('disabled') === false && $submitBtn.hasClass('ed-dirty')) {
                return;   // już zasygnalizowane — nie restartuj animacji
            }
            $submitBtn.prop('disabled', false).addClass('ed-dirty');
            $dirtyHint.removeClass('d-none');
        }
        function edClearDirty() {
            $submitBtn.removeClass('ed-dirty');
            $dirtyHint.addClass('d-none');
        }
        $form.on('input change', 'input, textarea', function() {
            if (this.id === 'edPreviewToggle') return;
            edMarkDirty();
        });

        // ---- Chipy: wstaw {zmienną} w miejscu kursora ----
        // Delegacja z formularza, bo grup jest kilka i renderuje je PHP.
        // stopPropagation, bo pigułki treści siedzą w zwijanym panelu —
        // bez tego klik w token złożyłby cały katalog zmiennych.
        $form.on('click', '.ed-chip', function(e) {
            e.preventDefault();
            e.stopPropagation();
            const $btn   = $(this);
            const token  = $btn.attr('data-var')    || '';
            const target = $btn.attr('data-target') || '';
            if (!token || !target) return;
            const $field = $(target);
            if ($field.length === 0) return;
            insertAtCursor($field, token);
        });

        // ---- Chevron zwijanego katalogu zmiennych ----
        edWireChevron('#edVarsCollapse', '.ed-vars-header');

        // ---- Licznik znaków treści ----
        function refreshCounter() {
            const len = String($body.val() || '').length;
            $counter.text(len + ' znaków');
            $counter.toggleClass('is-over', len > ED_BODY_SOFT_LIMIT);
            $counter.attr(
                'title',
                len > ED_BODY_SOFT_LIMIT
                    ? 'Powyżej ' + ED_BODY_SOFT_LIMIT + ' znaków treść nie zmieści się '
                      + 'w linku „Otwórz w mailu” — operator wklei ją ze schowka.'
                    : ''
            );
        }

        // ---- Podgląd na żywo ----
        // HYBRYDOWE podejście do ucieczki HTML:
        //   - wszystko poza {items} → escapeHtml, .html() (zwykły tekst,
        //     „Müller & Co” musi zostać „Müller & Co”)
        //   - {items} → raw HTML z AtteItemsFormat.htmlTable(), bo w
        //     kroku 4 wysyłki ciało maila po „Kopiuj pozycje" w Gmailu
        //     renderuje się jako prawdziwa tabela HTML (mirror kanonu
        //     z documents-send.js).
        //
        // Split + escape + wklejenie <table> robi teraz wspólny
        // AtteItemsFormat.previewBody() (współdzielony z podglądem
        // w kroku 4 wysyłki — documents-send.js). Tu wołamy go z
        // domyślnym markerem '{items}': ED_SAMPLE_VARS nie ma klucza
        // `items`, więc edApplyTemplate (który podstawia {vendor_name}
        // itd.) zostawia {items} nietknięte — jedyne miejsce w pliku,
        // gdzie ten token przeżywa jako literał do podziału.
        function renderPreview() {
            if (!$previewTgl.is(':checked')) return;
            const itemsHtml = AtteItemsFormat.htmlTable(
                AtteItemsFormat.sampleLines,
                { documentNumber: ED_SAMPLE_VARS.document_number || '' }
            );
            const previewBodyText = edApplyTemplate($body.val() || '', ED_SAMPLE_VARS);
            $previewSubj.text(edApplyTemplate($subject.val() || '', ED_SAMPLE_VARS));
            $previewBody.html(AtteItemsFormat.previewBody(previewBodyText, itemsHtml));
        }

        $previewTgl.on('change', function() {
            $previewWrap.collapse($(this).is(':checked') ? 'show' : 'hide');
            renderPreview();
        });

        $subject.on('input', renderPreview);
        $body.on('input', function() {
            refreshCounter();
            renderPreview();
        });

        refreshCounter();

        // ---- Submit ----
        $form.on('submit', function(e) {
            e.preventDefault();

            const data = edTemplatePostData();

            // Walidacja po stronie klienta tylko po to, żeby nie robić
            // round-tripu na oczywistych brakach. Serwer waliduje to
            // samo — on jest źródłem prawdy.
            if (data.name === '') {
                edFlash('Nazwa szablonu jest wymagana.', 'warning');
                $name.trigger('focus');
                return;
            }
            if (data.subject === '') {
                edFlash('Temat szablonu jest wymagany.', 'warning');
                $subject.trigger('focus');
                return;
            }
            if (data.body === '') {
                edFlash('Treść szablonu jest wymagana.', 'warning');
                $body.trigger('focus');
                return;
            }

            $submitBtn.prop('disabled', true);

            $.ajax({
                url: saveUrl,
                type: 'POST',
                data: data,
                dataType: 'json',
            })
            .done(function(r) {
                if (r && r.success) {
                    if (r.edit_url) {
                        // CREATE — przechodzimy na stronę edycji świeżo
                        // utworzonego szablonu, żeby operator od razu
                        // widział jego id i mógł dalej poprawiać.
                        window.location = r.edit_url;
                        return;
                    }
                    edFlash(r.message || 'Zapisano.', 'success');
                    // Zapisane = czyste: przycisk gaśnie razem z
                    // podpowiedzią „niezapisane zmiany".
                    $submitBtn.prop('disabled', true);
                    edClearDirty();
                    $(window).scrollTop(0);
                } else {
                    edFlash((r && r.error) ? r.error : 'Nieznany błąd.', 'danger');
                    $submitBtn.prop('disabled', false);
                    $(window).scrollTop(0);
                }
            })
            .fail(function(xhr, status) {
                let msg = 'Błąd komunikacji z serwerem.';
                if (xhr && xhr.responseJSON && xhr.responseJSON.error) {
                    msg = xhr.responseJSON.error;
                } else if (status) {
                    msg = String(status);
                }
                edFlash(msg, 'danger');
                $submitBtn.prop('disabled', false);
                $(window).scrollTop(0);
            });
        });
    });
})();
