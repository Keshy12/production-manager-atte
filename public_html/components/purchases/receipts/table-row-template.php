<?php
/**
 * Szablon wiersza historii przyjęć — używany przez
 * receipts-view.js → prependHistoryRow(), żeby świeżo zapisane przyjęcie
 * pojawiło się na górze tabeli bez przeładowania strony.
 *
 * Kolumny MUSZĄ odpowiadać nagłówkowi #receiptsTable w receipts-view.php
 * (8 kolumn: Numer dokumentu / Zamówienie / Dostawca / Pozycje / Ilość
 * łącznie / Przyjął / Data / Akcje). Placeholdery ${...} podstawia
 * renderTemplate() i każdy z nich jest escapowany.
 *
 * data-received-at zasila filtr zakresu dat (ta sama rola co w wierszach
 * renderowanych po stronie PHP).
 */
?>
<script type="text/template" data-template="receiptRowTemplate">
    <tr class="receipt-row" data-id="${id}" data-received-at="${receivedDate}">
        <td>
            <div class="font-weight-bold">${documentNumber}</div>
            <small class="text-muted">ID ${id}</small>
        </td>
        <td>${poNumber}</td>
        <td>${vendorName}</td>
        <td class="text-right"><span class="badge badge-secondary">${itemCount}</span></td>
        <td class="text-right">${totalQty}</td>
        <td>${receivedByName}</td>
        <td><small class="text-muted">${receivedAt}</small></td>
        <td class="text-right">
            <button class="btn btn-sm btn-outline-info view-receipt-btn" data-id="${id}" onclick="event.stopPropagation();">
                <i class="bi bi-eye"></i> Zobacz
            </button>
        </td>
    </tr>
</script>
