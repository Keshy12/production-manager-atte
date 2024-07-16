<script type="text/template" data-template="warehouseTableItem">
<tr class="tablerowcollapse" data-toggle="collapse" data-target=".collapse${key}" href=".collapse${key}">
    <td style="width: 70%"> ${componentName} <br/>
        <small class="text-wrap">
            ${componentDescription}
        </small>
    </td>
    <td style="width: 30%">
        ${sumQuantity}
    </td>
</tr>
<tr>
    <td class="tdcollapse"></td>
    <td class="tdcollapse">
        <div class="collapse${key} collapse mx-1">
            <div class="d-flex justify-content-center">
                <input type="number" class="form-control my-2 w-25 text-center quantityInput" value="${sumQuantity}" />
                <button data-type="${deviceType}" data-device_id="${key}" data-previous_quantity="${sumQuantity}" class="btn btn-primary my-2 mx-1 userMagazineCorrection">Korekta</button>
            </div>
        </div>
    </td>
</tr>
</script>
