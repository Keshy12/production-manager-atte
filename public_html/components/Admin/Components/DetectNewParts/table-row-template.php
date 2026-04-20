<script type="text/template" data-template="detectNewParts_template">
    <tr data-id="${0}" data-type="${type}">
        <td class="align-middle id">${0}</td>
        <td class="componentInfo${componentInfoClass}">
            <b class="componentName">${1}</b><br>
            <small class="componentDescription">${2}</small>
        </td>
        <td class="PartGroup${PartGroupClass}">${3}</td>
        <td class="PartType${PartTypeClass}">${4}</td>
        <td class="JM${JMClass}">${5}</td>
        <td class="align-middle editButtons">
            <button class="editRow btn btn-light">
                <i class="bi bi-pencil-square"></i>
            </button>
            <button class="deleteRow btn btn-light">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>
</script>