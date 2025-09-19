<script type="text/template" data-template="transferCommissionTableRow_template">
    <tr style="box-shadow: -7px 0px 0px 0px ${priorityColor}">
        <td>
            ${receivers}
        </td>
        <td class="deviceInfo">
            <b>${deviceName}</b><br>
            <small>${deviceDescription}</small>
        </td>
        <td class="laminateCell">
            ${laminate}
        </td>
        <td class="versionCell">
            ${version}
        </td>
        <td class="quantityCell">
            ${quantity}
        </td>
        <td class="align-middle">
            <button data-key="${key}" class="removeCommissionRow btn btn-light">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>
</script>

<style>
    .commission-header {
        background-color: #f8f9fa !important;
        font-weight: bold;
        cursor: pointer;
        transition: background-color 0.2s ease;
    }

    .commission-header:hover {
        background-color: #e9ecef !important;
    }

    .commission-header td {
        padding: 8px 12px !important;
        border-top: 2px solid #dee2e6 !important;
    }

    .commission-toggle-icon {
        transition: transform 0.3s ease;
        display: inline-block;
    }

    .commission-header.collapsed .commission-toggle-icon {
        transform: rotate(-90deg);
    }

    .commission-component {
        transition: opacity 0.3s ease;
    }

    .commission-component.hidden {
        display: none;
    }

    .commission-details {
        transition: all 0.3s ease;
    }

    .commission-summary {
        transition: all 0.3s ease;
    }

    .add-component-form {
        background-color: #f8f9fa;
        border-left: 3px solid #007bff;
    }

    .add-component-form.hidden {
        display: none !important;
    }

    .add-component-row.hidden {
        display: none !important;
    }

    .add-summary-component-form.hidden {
        display: none !important;
    }

    /* Blue line for manually added components */
    .manual-component .componentInfo {
        border-left: 3px solid #007bff !important;
    }

    .commission-summary-section {
        background-color: #f8f9fa !important;
    }

    .summary-component-row.hidden {
        display: none !important;
    }

    .global-summary-header {
        background-color: #e9ecef !important;
        cursor: pointer;
    }

    .global-summary-header:hover {
        background-color: #dee2e6 !important;
    }

    .global-summary-component {
        background-color: #f8f9fa !important;
    }

    .summary-toggle-icon {
        transition: transform 0.3s ease;
        display: inline-block;
    }

    .global-summary-header.expanded .summary-toggle-icon {
        transform: rotate(90deg);
    }

    /* Blue line for manually added components */
    .manual-component .componentInfo {
        border-left: 3px solid #007bff !important;
    }

    /* Manually added components should not be hidden when commission collapses */
    .manual-component.commission-component {
        display: table-row !important;
    }

    .manual-component.commission-component.hidden {
        display: table-row !important;
    }
</style>
<script type="text/template" data-template="transferComponentsTableRow_template">
    <tr data-key="${key}" class="commission-component" data-commission-key="${commissionKey}">
        <td class="componentInfo">
            <b>${componentName}</b><br>
            <small class="text-muted">${componentDescription}</small>
        </td>
        <td class="warehouseFrom" data-reserved="${warehouseFromReserved}">
            ${warehouseFromQty}
        </td>
        <td class="warehouseTo" data-reserved="${warehouseToReserved}">
            ${warehouseToQty}
        </td>
        <td>
            ${neededForCommissionQty}
        </td>
        <td class="align-items-stretch">
            <div class="d-flex flex-column">
                <input type="number" class="form-control form-control-sm text-center mx-auto w-75 transferQty"
                       data-key="${key}" data-commission-key="${commissionKey}" value="${transferQty}">
                <button class="btn btn-secondary w-75 btn-sm mx-auto insertDifference mt-1">Różnica</button>
            </div>
        </td>
        <td class="align-middle">
            <button class="btn btn-light btn-sm edit-component-sources" data-key="${key}">
                <i class="bi bi-gear"></i>
            </button>
            <button data-key="${key}" class="removeTransferRow btn btn-light btn-sm">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>
</script>

<script type="text/template" data-template="addCommissionComponentForm_template">
    <tr class="add-component-form commission-component" data-commission-key="${commissionKey}" style="display: none;">
        <td colspan="6" class="py-3">
            <div class="d-flex justify-content-center align-items-center">
                <small class="text-muted mr-3">Dodaj komponent:</small>
                <select id="commissionMagazineComponent" data-width="10%" data-title="Typ:" class="form-control selectpicker mr-2" style="width: 100px;">
                    <option value="sku">SKU</option>
                    <option value="tht">THT</option>
                    <option value="smd">SMD</option>
                    <option value="parts">Parts</option>
                </select>
                <select id="commissionListComponents" data-title="Urządzenie:" data-live-search="true"
                        class="form-control selectpicker mr-2" style="width: 300px;" disabled></select>
                <input type="number" style="width: 75px; padding: 3px; text-align: center;"
                       class="form-control mr-2" id="commissionQtyComponent" placeholder="Ilość">
                <button id="addCommissionComponentBtn" class="btn btn-success btn-sm mr-1">
                    <i class="bi bi-check"></i>
                </button>
                <button id="cancelCommissionComponentBtn" class="btn btn-secondary btn-sm">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </td>
    </tr>
</script>

<script type="text/template" data-template="addCommissionComponentRow_template">
    <tr class="add-component-row" data-commission-key="${commissionKey}">
        <td colspan="6" class="text-center py-2">
            <button class="btn btn-outline-primary btn-sm add-commission-component" data-commission-key="${commissionKey}">
                <i class="bi bi-plus"></i>
            </button>
        </td>
    </tr>
</script>
<script type="text/template" data-template="globalSummaryHeader_template">
    <tr class="global-summary-header" style="background-color: #acf5a1; border-top: 5px solid #6db1ff;">
        <td colspan="6" class="py-2 font-weight-bold cursor-pointer" style="cursor: pointer;">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-chevron-right summary-toggle-icon mr-2"></i>
                    <span>Podsumowanie komponentów</span>
                    <span class="badge badge-secondary ml-2" id="totalComponentsCount">0 komponentów</span>
                </div>
                <button class="btn btn-outline-primary btn-sm add-global-component">
                    <i class="bi bi-plus"></i> Dodaj komponent do transferu
                </button>
            </div>
        </td>
    </tr>
</script>

<script type="text/template" data-template="globalSummaryComponentRow_template">
    <tr class="global-summary-component" style="display: none; background-color: #f8f9fa;">
        <td class="componentInfo" style="border-left: 3px solid #6c757d;">
            <b>${componentName}</b><br>
            <small class="text-muted">${componentDescription}</small>
        </td>
        <td class="warehouseFrom" data-reserved="${warehouseFromReserved}">
            ${warehouseFromQty}
        </td>
        <td class="warehouseTo" data-reserved="${warehouseToReserved}">
            ${warehouseToQty}
        </td>
        <td>
            ${totalNeeded}
        </td>
        <td class="align-items-stretch">
            <div class="d-flex flex-column">
                <span class="form-control form-control-sm text-center mx-auto w-75 summary-qty" style="background-color: #e9ecef; border: none;">
                    ${totalTransferQty}
                </span>
                <small class="text-muted text-center mt-1">Suma</small>
            </div>
        </td>
        <td class="align-middle">
            ${multiSourceIndicator}
        </td>
    </tr>
    ${multiSourceDetails}
</script>

<script type="text/template" data-template="addGlobalComponentForm_template">
    <tr class="add-global-component-form global-summary-component" style="display: none; background-color: #f8f9fa;">
        <td colspan="6" class="py-3">
            <div class="d-flex justify-content-center align-items-center">
                <small class="text-muted mr-3">Dodaj komponent do transferu (bez zlecenia):</small>
                <select id="globalMagazineComponent" data-width="10%" data-title="Typ:" class="form-control selectpicker mr-2" style="width: 100px;">
                    <option value="sku">SKU</option>
                    <option value="tht">THT</option>
                    <option value="smd">SMD</option>
                    <option value="parts">Parts</option>
                </select>
                <select id="globalListComponents" data-title="Urządzenie:" data-live-search="true"
                        class="form-control selectpicker mr-2" style="width: 300px;" disabled></select>
                <input type="number" style="width: 75px; padding: 3px; text-align: center;"
                       class="form-control mr-2" id="globalQtyComponent" placeholder="Ilość">
                <button id="addGlobalComponentBtn" class="btn btn-success btn-sm mr-1">
                    <i class="bi bi-check"></i>
                </button>
                <button id="cancelGlobalComponentBtn" class="btn btn-secondary btn-sm">
                    <i class="bi bi-x"></i>
                </button>
            </div>
        </td>
    </tr>
</script>