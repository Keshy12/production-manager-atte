// Modal „Utwórz zamówienie z odpowiedzi" — współdzielona logika dla
// DWÓCH wejść:
//
//   (A) /admin/purchase/documents/edit?id=N&type=rfq — header CTA na
//       RFQ w stanie `responded`. PHP renderuje <button
//       data-action="rfq-convert" data-rfq-id="…"> …
//
//   (B) /purchase/documents — lista dokumentów; wiersz RFQ w stanie
//       `responded` ma w dropdown-menu <button data-action="rfq-convert"
//       data-rfq-id="…" data-rfq-number="…" data-rfq-vendor-name="…">.
//
// Każdy trigger niesie atrybuty:
//
//   data-action="rfq-convert"          identyfikator triggera
//   data-rfq-id                        int — id RFQ
//   data-rfq-number                    string — numer RFQ (lub "#id")
//   data-rfq-vendor-name               string — nazwa dostawcy (opcjonalna)
//
// Modal ma `id="rfqConvertModal"` + `#rfqConvertConfirm` (jeden na
// stronę — ID modala jest statyczny, trigger button NIE ma id
// statycznego bo lista renderuje wiele wierszy, każdy ze swoim
// przyciskiem). Aktualny RFQ trzymany w `data-current-rfq-id`
// na samym modalu, żeby confirm nie musiał szukać triggera.
//
// Kontrakt POST: { rfq_id } →
//   { success: true, edit_url: 'http://.../admin/purchase/documents/edit?id=N&type=po' }
//
// Na sukces: setAlert + window.open(resp.edit_url, '_blank') +
// window.location.reload() (nowe PO otwieramy w osobnej karcie;
// przeładowanie bieżącej strony odświeża plakietkę stanu RFQ
// z `responded` na `converted`). Na błąd: setAlert z komunikatem
// z response, modal zostaje otwarty.
//
// Konwencje: Bootstrap 4 jQuery API ($('#…').modal('show'/'hide')),
// brak `var`, helper esc inline (brak escAttr — wystarczy esc do
// treści tekstowej; atrybuty data-* są ustawiane przez PHP, nie
// przez operatora).

$(document).ready(function () {

    // ---- DOM hooks (modale mogą nie istnieć na każdej stronie) -----

    const $modal = $('#rfqConvertModal');
    if ($modal.length === 0) return;

    const $confirmBtn = $('#rfqConvertConfirm');
    const $number     = $('#rfqConvertNumber');
    const $vendorWrap = $('#rfqConvertVendorWrap');
    const $vendor     = $('#rfqConvertVendor');

    // Endpoint: ten sam URL co w documents-edit.js (konserwatywne
    // kopiowanie ścieżki — nie polegamy na header.js ładowanym
    // wcześniej na stronie, bo ten skrypt może być wpinany także
    // w kontekstach bez header.js).
    const CONVERT_ENDPOINT = (typeof window.COMPONENTS_PATH !== 'undefined'
        ? window.COMPONENTS_PATH
        : '/atte_ms_new/public_html/components')
        + '/Admin/Purchase/Documents/document-rfq-convert-ajax.php';

    let $origConfirmHtml = null;

    function esc(str) {
        return $('<div>').text(str == null ? '' : String(str)).html();
    }

    // ---- 1) Otwarcie modala z triggera [data-action="rfq-convert"] ----

    $(document).on('click', '[data-action="rfq-convert"]', function () {
        const $trigger = $(this);
        if ($trigger.prop('disabled')) return;

        const rfqId   = parseInt($trigger.data('rfq-id'), 10);
        const number  = $trigger.data('rfq-number') || '';
        const vendor  = $trigger.data('rfq-vendor-name') || '';

        if (!rfqId || rfqId <= 0) {
            // Błąd programistyczny (trigger bez id) — nie ma sensu
            // otwierać modala, zwróć uwagę operatorowi inline.
            const err = 'Nieprawidłowe ID zapytania.';
            if (typeof window.setAlert === 'function') {
                window.setAlert(err, 'danger');
            }
            return;
        }

        // Wypełnij placeholdery modala. Numer RFQ (albo "#id" gdy
        // brak); nazwa dostawcy — wiersz ukrywamy, jeśli pusta,
        // identycznie jak oryginalny serwerowy rendering robił
        // warunkiem PHP `if (!empty($doc->vendorName))`.
        $number.text(number || ('#' + rfqId));
        if (vendor) {
            $vendor.text(vendor);
            $vendorWrap.removeClass('d-none');
        } else {
            $vendor.text('');
            $vendorWrap.addClass('d-none');
        }

        // Identyfikator RFQ zapamiętujemy w atrybucie modala, żeby
        // confirm nie musiał szukać triggera (modal może żyć dłużej
        // niż jeden trigger — po zamknięciu/otwarciu, po re-renderze
        // listy itd.).
        $modal.attr('data-current-rfq-id', rfqId);

        // Reset ewentualnego błędu z poprzedniego otwarcia.
        if ($origConfirmHtml === null) $origConfirmHtml = $confirmBtn.html();
        $confirmBtn.prop('disabled', false).html($origConfirmHtml);

        $modal.modal('show');
    });

    // ---- 2) Submit: POST → document-rfq-convert-ajax.php ---------

    $(document).on('click', '#rfqConvertConfirm', function () {
        if ($confirmBtn.prop('disabled')) return;

        const rfqId = parseInt($modal.attr('data-current-rfq-id'), 10);
        if (!rfqId || rfqId <= 0) {
            if (typeof window.setAlert === 'function') {
                window.setAlert('Nieprawidłowe ID zapytania.', 'danger');
            }
            return;
        }

        $confirmBtn.prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm mr-2"></span>Tworzenie…');

        $.ajax({
            url: CONVERT_ENDPOINT,
            method: 'POST',
            dataType: 'json',
            data: { rfq_id: rfqId }
        })
            .done(function (resp) {
                if (resp && resp.success) {
                    // Sukces — setAlert (header.js), nowe PO w osobnej
                    // karcie, potem reload bieżącej, żeby plakietka
                    // stanu się odświeżyła (responded → 'converted',
                    // button konwersji znika).
                    if (typeof window.setAlert === 'function') {
                        window.setAlert('Utworzono zamówienie', 'success');
                    }
                    if (resp.edit_url) {
                        window.open(resp.edit_url, '_blank');
                    }
                    window.location.reload();
                    return;
                }
                $confirmBtn.prop('disabled', false)
                    .html($origConfirmHtml || '');
                const msg = (resp && resp.error) || 'Nieznany błąd';
                if (typeof window.setAlert === 'function') {
                    window.setAlert(msg, 'danger');
                }
            })
            .fail(function (xhr, status) {
                $confirmBtn.prop('disabled', false)
                    .html($origConfirmHtml || '');
                const msg = (xhr && xhr.responseJSON && xhr.responseJSON.error)
                    || status || 'Błąd sieci';
                if (typeof window.setAlert === 'function') {
                    window.setAlert(msg, 'danger');
                }
            });
    });
});