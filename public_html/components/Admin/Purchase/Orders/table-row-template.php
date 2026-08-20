<script type="text/template" data-template="poRowTemplate">
    <tr>
        <td class="text-center">${id}</td>
        <td>${poNumber}</td>
        <td>${vendorName}</td>
        <td class="text-center"><span class="badge badge-secondary">${itemCount}</span></td>
        <td class="text-right">${value}</td>
        <td><span class="badge ${stateClass}">${stateLabel}</span></td>
        <td><small class="text-muted">${createdAt}</small></td>
        <td>
            <div class="btn-group" role="group">
                <a class="btn btn-sm btn-warning edit-link" href="http://<?= BASEURL ?>/admin/purchase/orders/edit?id=${id}">
                    <i class="bi bi-pencil"></i> Edytuj
                </a>
                ${sendBtn}
                ${confirmBtn}
                ${cancelBtn}
            </div>
        </td>
    </tr>
</script>
