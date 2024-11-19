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
            <button data-id="${key}" class="removeCommissionRow btn btn-light">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>
</script>


<script type="text/template" data-template="transferComponentsTableRow_template">
    <tr>
        <td class="componentInfo">
            <b>${deviceName}</b><br>
            <small>${deviceDescription}</small>
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
        <td>
            
        </td>
        <td class="align-middle">
            <button data-id="${key}" class="removeTransferRow btn btn-light">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>
</script>