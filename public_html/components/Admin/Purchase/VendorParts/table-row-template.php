<script type="text/template" data-template="vendorPartRowTemplate">
    <tr class="${rowClass}">
        <td class="text-center">${id}</td>
        <td>${vendorName}</td>
        <td>${producerName}</td>
        <td>${partName}<br><small class="text-muted">${vendorPartNo}</small></td>
        <td>${unitName}</td>
        <td class="text-center">${fullPackQuantity}</td>
        <td>${statusBadge}</td>
        <td>
            <div class="btn-group" role="group">
                <button class="btn btn-sm btn-warning edit-vp-btn" data-id="${id}">
                    <i class="bi bi-pencil"></i> Edytuj
                </button>
                <button class="btn btn-sm toggle-vp-btn ${toggleBtnClass}"
                        data-id="${id}" data-is-active="${isActive}">
                    <i class="bi bi-${toggleIcon}"></i> ${toggleLabel}
                </button>
            </div>
        </td>
    </tr>
</script>
