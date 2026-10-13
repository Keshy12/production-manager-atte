<?php
/**
 * Modal partial — „Utworzyć zamówienie z odpowiedzi?" (RFQ → PO).
 *
 * Współdzielony widget renderowany RAZ na stronie, na której może
 * zostać otwarty. Trzy miejsca wpinania w tej roli:
 *
 *   1. /admin/purchase/documents/edit?id=N&type=rfq — header CTA na
 *      RFQ w stanie `responded` (przycisk "Utwórz zamówienie z
 *      odpowiedzi").
 *   2. /purchase/documents — lista dokumentów; przycisk "Utwórz
 *      zamówienie z odpowiedzi" w dropdown-menu przy wierszu RFQ
 *      w stanie `responded`.
 *
 * Bootstrap 4 jQuery API (`$('#…').modal('show'/'hide')).
 *
 * Identyfikatory modala są statyczne (jeden egzemplarz na stronę):
 *   #rfqConvertModal        kontener
 *   #rfqConvertModalTitle   <h5> (aria-labelledby)
 *   #rfqConvertNumber       placeholder na numer RFQ (wypełnia JS)
 *   #rfqConvertVendorWrap   wiersz „dla dostawcy …" (ukrywany, gdy
 *                           nazwa dostawcy pusta)
 *   #rfqConvertVendor       placeholder na nazwę dostawcy
 *   #rfqConvertConfirm      submit button — przyjmuje dane z
 *                           data-current-rfq-id ustawianego przez JS
 *
 * Na liście dokumentów wiersz renderowany jest RAZ per dokument,
 * więc identyfikator trigger buttona (np. „rfq-convert-trigger")
 * musi być jednorazowy per wiersz — używamy selektora
 * `data-action="rfq-convert"` zamiast stałego id. Modal jest
 * pojedynczy dla całej strony.
 *
 * Kontrakt POST: { rfq_id: int } →
 *   { success: true, edit_url: 'http://.../admin/purchase/documents/edit?id=N&type=po' }
 * Endpoint: document-rfq-convert-ajax.php (Admin/Purchase/Documents/).
 *
 * Stan: styl `.dx-modal` jest zdefiniowany w documents-edit-ui.css
 * (ten sam CSS jest ładowany na stronie edycji; list page
 * documents-view.php ładuje documents-ui.css, który ma te same klasy
 * zgodnie z istniejącym designem — jeśli nie, wystarczy inline
 * fallback przez BS4 .modal/.modal-dialog).
 */
?>
<!--
    data-backdrop="static": backdrop nie znika po kliknięciu obok
    okna (klawisz Esc nadal zamyka, więc flow "Anuluj → ESC" działa).
    Klasy .dx-modal (pochodzą z documents-edit-ui.css na stronie
    edycji; na liście /purchase/documents ten sam wygląd daje BS4
    .modal bez dodatkowych klas).
-->
<div class="modal fade dx-modal"
     id="rfqConvertModal"
     tabindex="-1"
     role="dialog"
     aria-labelledby="rfqConvertModalTitle"
     aria-hidden="true"
     data-backdrop="static">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="rfqConvertModalTitle">Utworzyć zamówienie z odpowiedzi?</h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <p class="mb-2">Na podstawie zapytania <strong id="rfqConvertNumber">…</strong>
                    zostanie utworzone nowe zamówienie
                    <span id="rfqConvertVendorWrap" class="d-none">dla dostawcy <strong id="rfqConvertVendor"></strong></span>.
                </p>
                <ul class="small text-muted mb-0 pl-3">
                    <li>Pozycje zostaną skopiowane wraz z cenami z odpowiedzi dostawcy.</li>
                    <li>Zapytanie przejdzie w stan „Skonwertowane na PO” i nie będzie można go już zmieniać.</li>
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                <button type="button" class="btn btn-success" id="rfqConvertConfirm">
                    <i class="bi bi-cart-check"></i> Utwórz zamówienie
                </button>
            </div>
        </div>
    </div>
</div>