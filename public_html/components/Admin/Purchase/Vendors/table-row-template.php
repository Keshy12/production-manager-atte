<script type="text/template" data-template="vendorRowTemplate">
    <tr class="${rowClass}">
        <td class="text-center">${id}</td>
        <td>${name}</td>
        <td><small class="text-muted">${address}</small></td>
        <td class="text-center">${leadTimeDays}</td>
        <td><small class="text-muted">${comment}</small></td>
        <td class="text-center"><span class="badge badge-secondary">${supplierCount}</span></td>
        <td class="text-center"><span class="badge badge-secondary">${vendorPartCount}</span></td>
        <td>${statusBadge}</td>
        <td>
            <div class="btn-group" role="group">
                <button class="btn btn-sm btn-warning edit-vendor-btn" data-id="${id}">
                    <i class="bi bi-pencil"></i> Edytuj
                </button>
                <button class="btn btn-sm btn-info detail-vendor-btn" data-id="${id}" data-name="${name}">
                    <i class="bi bi-info-circle"></i> Szczegóły
                </button>
                <button class="btn btn-sm toggle-vendor-btn ${toggleBtnClass}"
                        data-id="${id}"
                        data-is-active="${isActive}"
                        data-name="${name}"
                        data-has-parts="${hasParts}">
                    <i class="bi bi-${toggleIcon}"></i> ${toggleLabel}
                </button>
            </div>
        </td>
    </tr>
</script>

<script type="text/template" data-template="supplierRowTemplate">
    <tr>
        <td>${name}</td>
        <td>${jobTitle}</td>
        <td>${phone}</td>
        <td>${email}</td>
        <td>${statusBadge}</td>
        <td>
            <div class="btn-group" role="group">
                <button class="btn btn-sm btn-warning edit-supplier-btn" data-id="${id}">
                    <i class="bi bi-pencil"></i>
                </button>
                <button class="btn btn-sm toggle-supplier-btn ${toggleBtnClass}"
                        data-id="${id}" data-is-active="${isActive}" data-name="${name}">
                    <i class="bi bi-${toggleIcon}"></i>
                </button>
            </div>
        </td>
    </tr>
</script>

<script type="text/template" data-template="vendorPartRowTemplate">
    <tr>
        <td>${producerName}</td>
        <td>${partName}</td>
        <td>${vendorPartNo}</td>
        <td>${unitName}</td>
        <td class="text-center">${fullPackQuantity}</td>
        <td>${statusBadge}</td>
        <td>
            <div class="btn-group" role="group">
                <button class="btn btn-sm toggle-vp-btn ${toggleBtnClass}"
                        data-id="${id}" data-is-active="${isActive}">
                    <i class="bi bi-${toggleIcon}"></i>
                </button>
            </div>
        </td>
    </tr>
</script>
