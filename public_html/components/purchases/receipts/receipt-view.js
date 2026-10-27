// Szczegóły przyjęcia — /purchase/receipts/view?id=N.
//
// Strona jest renderowana w PHP (receipt-view.php); tu tylko dwa drobiazgi:
//   1. „Drukuj" → window.print() (receipts-ui.css ma style do druku),
//   2. link „Historia przyjęć" w okruszkach: jeśli operator przyszedł tu
//      z listy przyjęć, cofamy się w historii przeglądarki zamiast ładować
//      listę od nowa — wtedy zostaje jego strona paginacji i wpisane
//      filtry. W każdym innym przypadku działa zwykły link
//      /purchase/receipts#historia.
//      (Przycisk „Wróć" w nagłówku ma własny, prostszy mechanizm —
//      zawsze cofa się po historii przeglądarki, niezależnie skąd
//      przyszliśmy; patrz onclick w receipt-view.php.)

$(document).ready(function () {
    $('#receiptPrintBtn').on('click', function () {
        window.print();
    });

    const listUrl = String($('#receiptDetailView').attr('data-list-url') || '');
    let cameFromList = false;
    try {
        const ref = document.referrer ? new URL(document.referrer) : null;
        const list = listUrl ? new URL(listUrl, window.location.href) : null;
        cameFromList = !!(ref && list
            && ref.origin === window.location.origin
            && ref.pathname.replace(/\/+$/, '') === list.pathname.replace(/\/+$/, '')
            && window.history.length > 1);
    } catch (e) {
        cameFromList = false;
    }

    $('.js-back-to-history').on('click', function (e) {
        if (!cameFromList || e.ctrlKey || e.metaKey || e.shiftKey) return;
        e.preventDefault();
        window.history.back();
    });
});
