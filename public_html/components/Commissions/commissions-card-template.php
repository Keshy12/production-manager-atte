<script type="text/template" data-template="commissionCard">
    <div class="card text-center m-2 ${class}"
         style="min-width: 360px; max-width: 400px; box-shadow: -5px 0px 0px 0px ${color};"
         ${showGroupBadge}data-grouped-ids="${groupedIds}">
        <div class="card-header py-2">
            <button type="button"
                    style="float: left;"
                    class="btn btn-sm btn-link p-0 commission-info-btn"
                    tabindex="0"
                    role="button"
                    data-toggle="popover"
                    data-trigger="click"
                    data-placement="bottom"
                    title="Informacje o zleceniu"
                    data-html="true"
                    data-content="<strong>Z magazynu:</strong> ${warehouseFrom}<br><strong>Do magazynu:</strong> ${warehouseTo}">
                <i class="bi bi-info-circle" style="font-size: 1.2rem;"></i>
            </button>

            <span ${isHidden}>
            <div class="dropdown" style="float: right;">
                <button type="button"
                        class="btn btn-sm btn-link p-0"
                        data-toggle="dropdown"
                        aria-expanded="false">
                    <i class="bi bi-three-dots-vertical" style="font-size: 1.2rem;"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-item editCommission"
                       data-id="${id}"
                       data-submag-id="${warehouseToId}"
                       data-receivers="${receivers}"
                       data-priority="${priority}">
                        <i class="bi bi-pencil"></i> Edytuj zlecenie
                    </a>
                    <div class="dropdown-divider"></div>
                    <a class="dropdown-item text-danger cancelCommission"
                       data-id="${id}">
                        <i class="bi bi-x-circle"></i> Anuluj zlecenie
                    </a>
                </div>
            </div>
        </span>

            <div class="mt-2">
                <div style="font-size: 1.1rem; font-weight: 600;">
                    ${deviceName}
                    <button class="btn btn-sm btn-link p-0 ml-1"
                            type="button"
                            data-toggle="collapse"
                            data-target="#deviceDesc-${id}"
                            aria-expanded="false"
                            aria-controls="deviceDesc-${id}">
                        <i class="bi bi-chevron-down" style="font-size: 0.9rem;"></i>
                    </button>
                </div>
                <div class="collapse mt-2" id="deviceDesc-${id}">
                    <small class="text-muted">
                        ${deviceDescription}
                    </small>
                </div>
                <small class="text-muted">${deviceLaminateAndVersion}</small>
            </div>

            <div class="mt-1 ${class2}">
                <small>
                    <i class="bi bi-person"></i>
                    <strong>${receiversName}</strong>
                </small>
            </div>
        </div>

        <div class="card-body p-3">
            <div ${showGroupBadge} class="mb-2">
                <span class="badge badge-warning">
                    <i class="bi bi-layers"></i> Zgrupowane: ${groupedCount} zleceń
                </span>
            </div>

            <div ${showPotentialGroupBadge} class="mb-2">
                <span class="badge badge-secondary">
                    <i class="bi bi-stack"></i> Możliwe do zgrupowania: ${potentialGroupCount} zleceń
                </span>
            </div>

            <table class="table table-bordered table-sm mb-2">
                <thead class="thead-light">
                <tr class="${class3}">
                    <th>Zlecono</th>
                    <th>Wyprodukowano</th>
                </tr>
                </thead>
                <tbody>
                <tr class="${class3}">
                    <td class="quantity">
                        <strong>${quantity}</strong>
                    </td>
                    <td class="quantityProduced">
                        <strong>${quantityProduced}</strong>
                    </td>
                </tr>
                </tbody>
            </table>

            <table class="table table-bordered table-sm mb-0">
                <thead class="thead-light">
                <tr class="${class3}">
                    <th>Dostarczono</th>
                </tr>
                </thead>
                <tbody>
                <tr class="${class3}">
                    <td class="quantityReturned">
                        <strong>${quantityReturned}</strong>
                    </td>
                </tr>
                </tbody>
            </table>
        </div>

        <div class="card-footer ${class2} py-2">
            <small>
                <i class="bi bi-calendar"></i>
                ${timestampCreated}
            </small>
        </div>
    </div>
</script>