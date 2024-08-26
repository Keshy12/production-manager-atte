<script type="text/template" data-template="ref__valuepackage_template">
    <tr>
        <td class="align-middle dictionaryValue">${ValuePackage}</td>
        <td class="componentInfo">
            <b>${componentName}</b><br>
            <small>${componentDescription}</small>
        </td>
        <td class="align-middle editButtons">
            <button data-id="${id}" data-component-type="${componentType}" 
                    data-component-id="${componentId}" class="editValuePackage btn btn-light">
                <i class="bi bi-pencil-square"></i>
            </button>
            <button data-id="${id}" class="removeDictionaryItem btn btn-light">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>
</script>


<script type="text/template" data-template="ref__package_exclude_template">
    <tr>
        <td class="align-middle p-2 dictionaryValue">${name}</td>
        <td class="align-middle editButtons">
            <button data-id="${id}" class="editPackageExclude btn btn-light">
                <i class="bi bi-pencil-square"></i>
            </button>
            <button data-id="${id}" class="removeDictionaryItem btn btn-light">
                <i class="bi bi-trash"></i>
            </button>
        </td>
    </tr>
</script>