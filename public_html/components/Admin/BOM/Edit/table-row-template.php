<script type="text/template" data-template="bomEditTableRow_template">
    <tr>
        <td class="componentInfo">
            <b>${componentName}</b><br>
            <small>${componentDescription}</small>
        </td>
        <td class="quantity">
            ${quantity}
        </td>
        <td class="align-middle editButtons">
            <button data-component-type="${type}" data-id="${rowId}" 
                    data-component-id="${componentId}" class="editBomRow btn btn-light">
                <i class="bi bi-pencil-square"></i>
            </button>
            <button data-component-type="${type}" data-id="${rowId}" 
                    data-component-id="${componentId}" class="removeBomRow btn btn-light">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>
</script>