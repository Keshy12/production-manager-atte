<script type="text/template" data-template="producerRowTemplate">
    <tr class="${rowClass}">
        <td class="text-center">${id}</td>
        <td>${name}</td>
        <td><small class="text-muted">${comment}</small></td>
        <td>${statusBadge}</td>
        <td>
            <div class="btn-group" role="group">
                <button class="btn btn-sm btn-warning edit-producer-btn"
                        data-id="${id}"
                        data-name="${name}"
                        data-comment="${comment}">
                    <i class="bi bi-pencil"></i> Edytuj
                </button>
                <button class="btn btn-sm toggle-producer-btn ${toggleBtnClass}"
                        data-id="${id}"
                        data-is-active="${isActive}"
                        data-name="${name}">
                    <i class="bi bi-${toggleIcon}"></i> ${toggleLabel}
                </button>
            </div>
        </td>
    </tr>
</script>
