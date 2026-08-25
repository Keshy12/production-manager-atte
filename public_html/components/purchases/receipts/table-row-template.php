<script type="text/template" data-template="receiptRowTemplate">
    <tr class="receipt-row" data-id="${id}" style="cursor: pointer;">
        <td class="text-center">${id}</td>
        <td>${documentNumber}</td>
        <td>${poNumber}</td>
        <td>${vendorName}</td>
        <td class="text-center"><span class="badge badge-secondary">${itemCount}</span></td>
        <td class="text-right">${totalQty}</td>
        <td>${receivedByName}</td>
        <td><small class="text-muted">${receivedAt}</small></td>
        <td>
            <button class="btn btn-sm btn-info view-receipt-btn" data-id="${id}" onclick="event.stopPropagation();">
                <i class="bi bi-eye"></i> Zobacz
            </button>
        </td>
    </tr>
</script>
