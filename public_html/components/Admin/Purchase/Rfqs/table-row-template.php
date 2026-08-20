<script type="text/template" data-template="rfqRowTemplate">
    <tr>
        <td class="text-center">${id}</td>
        <td>${rfqNumber}</td>
        <td>${vendorName}</td>
        <td class="text-center"><span class="badge badge-secondary">${itemCount}</span></td>
        <td><span class="badge ${stateClass}">${stateLabel}</span></td>
        <td><small class="text-muted">${createdAt}</small></td>
        <td>
            <div class="btn-group" role="group">
                <a class="btn btn-sm btn-warning edit-link" href="http://<?= BASEURL ?>/admin/purchase/rfqs/edit?id=${id}">
                    <i class="bi bi-pencil"></i> Edytuj
                </a>
                ${sendBtn}
                ${cancelBtn}
            </div>
        </td>
    </tr>
</script>
