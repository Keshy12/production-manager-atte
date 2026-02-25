const bomEditTableRow_template = $('script[data-template="bomEditTableRow_template"]').text().split(/\$\{(.+?)\}/g);

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

$("#bomTypeSelect").change(function(){
    $("#list__device, #versionSelect, #laminateSelect, #editBomTBody, #alerts").empty();
    $("#bomTotalPriceContainer").hide(); // Hide price on type change
    $('#list__'+this.value+'_hidden option').clone()
                                            .appendTo('#list__device');
    $('#list__device, #previousBom, #nextBom').prop("disabled", false)
                                              .selectpicker('refresh');;
    $("#versionSelect, #laminateSelect").selectpicker('val', '')
                                        .prop('disabled', true)
                                        .selectpicker('refresh');
    showAdditionalFields(this.value);
});

function showAdditionalFields(type)
{
    $("#laminateField, #versionField, #createNewBomFields, #isActiveField").hide();
    if(type == "tht")
    {
        $("#versionField").show();
    }
    else if(type == "smd")
    {
        $("#versionField, #laminateField").show();
    }
}

$("#versionSelect").change(function(){
    $("#bomTotalPriceContainer").hide(); // Hide price on version change
    generateBomTable();
});

$("#list__device").change(function(){
    $("#editBomTBody, #alerts").empty();
    $("#bomTotalPriceContainer").hide(); // Hide price on device change
    let bomType = $("#bomTypeSelect").val();
    $("#versionSelect, #laminateSelect").empty();
    generateAdditionalFields(bomType);
    if(bomType == 'sku') generateBomTable();
});

function generateAdditionalFields(type)
{
    if(type == 'smd') {
        $("#laminateSelect").prop('disabled', false);
        $("#versionSelect").prop('disabled', true);
        let possibleLaminates = $("#list__device option:selected").data("jsonlaminates");
        generateLaminateSelect(possibleLaminates);
    } else if(type == 'tht') {
        $("#versionSelect").prop('disabled', false);
        let possibleVersions = $("#list__device option:selected").data("jsonversions");
        generateVersionSelect(possibleVersions);
    } 
    $("#versionSelect, #laminateSelect").selectpicker('refresh');
}

function generateLaminateSelect(possibleLaminates){
    if(Object.keys(possibleLaminates).length == 1) {
        let laminate_id = Object.keys(possibleLaminates)[0];
        let laminate_name = possibleLaminates[laminate_id][0];
        let versions = JSON.stringify(possibleLaminates[laminate_id]['versions']);
        let option = "<option value='"+laminate_id+"' data-jsonversions='"+versions+"' selected>"+laminate_name+"</option>";
        $("#laminateSelect").append(option);
        $("#laminateSelect").selectpicker('destroy');
        $("#versionSelect").prop('disabled', false);
        generateVersionSelect(possibleLaminates[laminate_id]['versions']);
    } else {
        for (let laminate_id in possibleLaminates) 
        {
            let laminate_name = possibleLaminates[laminate_id][0];
            let versions = JSON.stringify(possibleLaminates[laminate_id]['versions']);
            let option = "<option value='"+laminate_id+"' data-jsonversions='"+versions+"'>"+laminate_name+"</option>";
            $("#laminateSelect").append(option);
        }
    }
    $("#laminateSelect").selectpicker('refresh');
}

function generateVersionSelect(possibleVersions){
    if(Object.keys(possibleVersions).length == 1) {
        if(possibleVersions[0] == null)
        {
            let version = 'n/d';
            $("#versionSelect").selectpicker('destroy');
            $("#versionSelect").html('<option value="'+version+'" selected>n/d</option>');
            $("#versionSelect").prop('disabled', false);
            $("#versionField").hide();
            $("#versionSelect").selectpicker('refresh');
            generateBomTable();
            return;
        }
        let version_id = Object.keys(possibleVersions)[0];
        let version = possibleVersions[version_id][0];
        let option = "<option value='"+version+"' selected>"+version+"</option>";
        $("#versionSelect").append(option);
        $("#versionSelect").selectpicker('destroy');
        generateBomTable();
    } else {
        for (let version_id in possibleVersions) 
        {
            let version = possibleVersions[version_id][0];
            let option = "<option value='"+version+"'>"+version+"</option>";
            $("#versionSelect").append(option);
        }
    }
    $("#versionField").show();
    $("#versionSelect").selectpicker('refresh');
}

$("#laminateSelect").change(function(){
    $("#versionSelect").empty();
    $("#bomTotalPriceContainer").hide(); // Hide price on laminate change
    let possibleVersions = $("#laminateSelect option:selected").data("jsonversions");
    generateVersionSelect(possibleVersions);
    $("#versionSelect").prop('disabled', false);
    $("#versionSelect").selectpicker('refresh');
})

function generateBomTable()
{
    let isEditable = true;
    $("#createNewBomFields, #isActiveField").show();
    $TBody = $("#editBomTBody");
    $TBody.empty();
    let bomType = $("#bomTypeSelect").val();
    let deviceId = $("#list__device").val();
    let version = $("#versionSelect").val();
    let laminate = $("#laminateSelect").val();
    const bomValues = [deviceId];
    let createNewBom = false;

    if (!isEditable) {
        $("#editButtonsCol").hide();
    } else {
        $("#editButtonsCol").show();
    }
    switch (bomType) {
        case "sku": {
            const bomIds = $("#list__device option:selected").data("bomids");
            createNewBom = bomIds[0] === null;
            bomValues.push(version === "" ? "n/d" : version);
            console.log(version);
            break;
        }
        case "tht": {
            let deviceName = $("#list__device option:selected").text().trim();
            const bomIds = $("#list__device option:selected").data("bomids");
            isEditable = !deviceName.startsWith("THT.");
            createNewBom = bomIds[0] === null && isEditable;
            bomValues.push(version);
            break;
        }
        case "smd": {
            isEditable = false;
            bomValues.push(laminate, version);
            break;
        }
    }
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/admin/bom/edit/get-bom-components.php",
        async: false,
        data: {bomType: bomType, bomValues: bomValues},
        success: function (data) {
            let result = JSON.parse(data);
            let components = result[0];
            let bomId = result[1];
            let isActive = result[2];
            let wasSuccessful = result[3];
            let errorMessage = result[4];
            let outThtQuantity = result[5];
            let outThtPrice = result[6];
            let outSmdPrice = result[7];
            let outSmdQty = result[8];
            let outSmdPricePerItem = result[9];
            let outThtPricePerItem = result[10];
            let bomTotalPrice = result[11];
            if(!wasSuccessful) {
                let resultAlert = `<tr>
                <td colspan="3"><div class="alert alert-danger" role="alert">
                    `+errorMessage+`
                </div></td>
                </tr>`;
                $("#createNewBomFields, #isActiveField, #bomTotalPriceContainer").hide();
                // $("#editButtonsCol").hide(); // Remove this line
                $("#alerts").append(resultAlert);
                return;
            }
            $("#createNewBomFields").attr('data-bom-id', bomId);
            $("#isActive").prop('checked', isActive);
            
            $("#bomTotalPrice").text(bomTotalPrice.toFixed(2));
            $("#bomTotalPriceContainer").show();

            if (isEditable) {
                $("#createNewBomFields, #isActiveField").show();
            } else {
                $("#createNewBomFields, #isActiveField").hide();
            }
            
            let hasMissingDefault = false;
            
            if(outSmdPrice !== null) {
                let smdItem = {
                    rowId: 'out_smd', // Special ID to identify this row
                    type: '', // Custom type for calculated fields, set to empty to disable edit button attributes
                    componentName: 'OUT_SMD',
                    componentDescription: ``,
                    quantity: `<span class="qty-value">`+outSmdQty+`</span>` + `<br><span class="text-muted small">`+ outSmdPricePerItem.toFixed(2) + `PLN/szt</span>`, // Display outSmdQty and price per item
                    componentId: '', // Set to empty to disable edit button attributes
                    price: `<b>`+outSmdPrice.toFixed(2)+`PLN</b>` // Only total price
                };
                let renderedSmdItem = bomEditTableRow_template.map(render(smdItem)).join('');
                let $renderedSmdItem = $(renderedSmdItem);
                $renderedSmdItem.find('.actionButtons').empty(); // Remove all action buttons for OUT_SMD
                $TBody.append($renderedSmdItem);
            }

            if(bomType == 'tht') {
                let thtItem = {
                    rowId: 'out_tht', // Special ID to identify this row
                    type: '', // Custom type for calculated fields, set to empty to disable edit button attributes
                    componentName: 'OUT_THT',
                    componentDescription: '',
                    quantity: `<span class="qty-value">`+outThtQuantity+`</span>` + `<br><span class="text-muted small">`+ outThtPricePerItem.toFixed(2) + `PLN/szt</span>`,
                    componentId: '', // Set to empty to disable edit button attributes
                    price: `<b>`+outThtPrice.toFixed(2)+`PLN</b>`
                };
                let renderedThtItem = bomEditTableRow_template.map(render(thtItem)).join('');
                $TBody.append(renderedThtItem);
            }
            
            for(const [key, item] of Object.entries(components))
            {
                // Highlight missing defaults in red
                if (item.missing_default == 1) {
                    hasMissingDefault = true;
                    item.componentName = `<span class="text-danger">` + item.componentName + `</span>`;
                    item.componentDescription = `<span class="text-danger small font-weight-bold">Brak domyślnej wersji BOM dla tego komponentu!</span><br>` + item.componentDescription;
                    item.price = `<b class="text-danger">` + item.totalPrice.toFixed(2) + `PLN</b>`;
                } else {
                    item.price = `<b>` + item.totalPrice.toFixed(2) + `PLN</b>`;
                }

                item.quantity = `<span class="qty-value">`+item.quantity+`</span>` + `<br><span class="text-muted small">`+ item.pricePerItem.toFixed(2) + `PLN/szt</span>`;
                let renderedItem = bomEditTableRow_template.map(render(item)).join('');
                let $renderedItem = $(renderedItem);
                
                // If the entire BOM is not editable, hide edit/delete buttons for all rows
                if(!isEditable) {
                    $renderedItem.find('.editBomRow').remove(); // Remove edit button
                    $renderedItem.find('.removeBomRow').remove(); // Remove delete button
                }

                // Always remove delete button for OUT_SMD and OUT_THT rows
                if (item.rowId == 'out_smd' || item.rowId == 'out_tht') {
                    $renderedItem.find('.removeBomRow').remove(); // Remove delete button
                    // Additionally, if it's OUT_SMD, it's not editable so remove its edit button
                    if (item.rowId == 'out_smd') {
                        $renderedItem.find('.editBomRow').remove(); // Remove edit button for OUT_SMD
                    }
                }
                
                $TBody.append($renderedItem);
            }

            if (hasMissingDefault) {
                $("#bomTotalPriceContainer").addClass("text-danger").removeClass("text-muted");
            } else {
                $("#bomTotalPriceContainer").addClass("text-muted").removeClass("text-danger");
            }
        }
    });
}

$('body').on('click', '.editBomRow', function(){
    $(".editBomRow, .removeBomRow").prop("disabled", true);
    let componentType = $(this).attr('data-component-type');
    let componentId = $(this).attr('data-component-id');
    
    let rowId = $(this).attr('data-id');
    let $row = $(this).closest('tr');
    
    let quantity = parseFloat($row.find('.quantity .qty-value').text().trim());

    generateQuantityInput($row, quantity);
    
    if (rowId !== 'out_tht') {
        generateComponentSelect($row, componentType, componentId);
    } else {
        $row.find('.componentInfo').empty();
    }

    generateSaveCancelButtons($row, rowId);
});

function generateQuantityInput($row, quantity)
{
    let $quantity = $row.find('.quantity');
    $quantity.empty();

    let rowId = $row.find('.editBomRow').attr('data-id'); // Assuming editBomRow exists and has data-id
    let readOnlyAttr = (rowId === 'out_smd') ? 'readonly' : '';

    let $quantityInput = $(`<input type="number" class="form-control text-center quantityInput" value="`+quantity+`" min="0" step="any" `+readOnlyAttr+`>`);
    $quantity.append($quantityInput);
}

function generateSaveCancelButtons($row, rowId)
{
    let $actionButtons = $row.find(".actionButtons");
    $actionButtons.empty();

    const acceptClass = rowId == '' ? 'createNewRow' : 'applyChanges';

    let $applyChangesButton = $(`<button class="btn mr-1 btn-outline-success `+acceptClass+`">
            <i class="bi bi-check-lg"></i>
        </button>`);
    let $declineChangesButton = $(`<button class="btn btn-outline-danger declineChanges">
        <i class="bi bi-x"></i>
    </button>`);

    $applyChangesButton.attr("data-id", rowId);
    $actionButtons.append($applyChangesButton).append($declineChangesButton);    
}

function generateComponentSelect($row, componentType, componentId)
{
    let $componentInfo = $row.find('.componentInfo');
    $componentInfo.empty();

    let $componentInfoTypeSelect = $(`<select data-width="20%" class="selectpicker componentTypeSelect">
        <option value="sku">SKU</option>
        <option value="tht">THT</option>
        <option value="smd">SMD</option>
        <option value="parts">Parts</option>
    </select>`);

    let $componentInfoDeviceSelect = $(`<select data-title="Wybierz urządzenie..." data-live-search="true" data-width="80%" class="selectpicker componentDeviceSelect">
        </select>`);

    $('#list__'+componentType+'_hidden option').clone().appendTo($componentInfoDeviceSelect);
    $componentInfo.append($componentInfoTypeSelect).append($componentInfoDeviceSelect);
    $('.selectpicker').selectpicker('refresh');
    $componentInfoTypeSelect.selectpicker('val', componentType);
    $componentInfoDeviceSelect.selectpicker('val', componentId);
}

$('body').on('change', 'select.componentTypeSelect', function(){
    let componentType = this.value;
    let $componentDeviceSelect = $(this).parent().parent().find('select.componentDeviceSelect');
    $componentDeviceSelect.val('');
    $componentDeviceSelect.empty();
    $('#list__'+componentType+'_hidden option').clone().appendTo($componentDeviceSelect);
    $componentDeviceSelect.selectpicker('refresh');
});


$('body').on('click', '.declineChanges', generateBomTable);

$('body').on('click', '.applyChanges', function(){
    let rowId = $(this).attr('data-id');
    let $row = $(this).closest('tr');
    let quantity = $row.find('.quantityInput').val();

    if (rowId === 'out_tht') {
        let bomId = $("#createNewBomFields").attr('data-bom-id');
        const data = {bomId: bomId, quantity: quantity};
        $.ajax({
            type: "POST",
            url: COMPONENTS_PATH+"/admin/bom/edit/update-tht-quantity.php",
            data: data,
            success: function (data) {
                let result = JSON.parse(data);
                let wasSuccessful = result[0];
                let errorMessage = result[1];
                if(!wasSuccessful) {
                    let resultAlert = `<div class="alert alert-danger alert-dismissible fade show" role="alert">
                        `+errorMessage+`
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>`;
                    $("#alerts").append(resultAlert);
                     setTimeout(function() {
                         $(".alert-danger").alert('close');
                     }, 2000);
                 }
                generateBomTable();
             }
         });
     } else {
         let componentType = $row.find('select.componentTypeSelect').val();
         let componentId = $row.find('select.componentDeviceSelect').val();
         const data = {rowId: rowId, componentType: componentType, componentId: componentId, quantity: quantity};
         editBomRow(data);
        generateBomTable();
     }
});

function editBomRow(data)
{
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/admin/bom/edit/edit-row.php",
        async: false,
        data: data,
        success: function (data) {
            let wasSuccessful = JSON.parse(data);
            let resultMessage = wasSuccessful ? 
                        "Edytowanie danych powiodło się." : 
                        "Coś poszło nie tak, dane nie zostały edytowane";
            let resultAlertType = wasSuccessful ? 
                        "alert-success" : 
                        "alert-danger";

            let resultAlert = `<div class="alert `+resultAlertType+` alert-dismissible fade show" role="alert">
                `+resultMessage+`
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>`;
            $("#ajaxResult").append(resultAlert);
            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);
        }
    });
}

$('body').on('click', '.removeBomRow', function() {
    let rowId = $(this).attr('data-id');
    $("#confirmDelete").attr('data-id', rowId);
    $("#confirmDeleteModal").modal('show');
});

$("#confirmDelete").click(function() {
    let rowId = $(this).attr('data-id');
    const data = {rowId: rowId};
    removeBomRow(data);
    $("#confirmDeleteModal").modal('hide');
    generateBomTable();
});

function removeBomRow(data)
{
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/admin/bom/edit/remove-row.php",
        async: false,
        data: data,
        success: function (data) {
            let wasSuccessful = JSON.parse(data);
            let resultMessage = wasSuccessful ? 
                        "Usunięcie danych powiodło się." : 
                        "Coś poszło nie tak, dane nie zostały usunięte";
            let resultAlertType = wasSuccessful ? 
                        "alert-success" : 
                        "alert-danger";

            let resultAlert = `<div class="alert `+resultAlertType+` alert-dismissible fade show" role="alert">
                `+resultMessage+`
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>`;
            $("#ajaxResult").append(resultAlert);
            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);
        }
    });
}

$("#createNewBomFields").click(function(){
    let $TBody = $("#editBomTBody");
    let renderedItem = bomEditTableRow_template.join('');
    let $renderedItem = $(renderedItem);

    generateQuantityInput($renderedItem, '');
    
    generateComponentSelect($renderedItem, '', '');

    generateSaveCancelButtons($renderedItem, '');
    $TBody.append($renderedItem);
});

$("body").on("click", '.createNewRow', function(){
    let $row = $(this).closest('tr');
    let bomId = $("#createNewBomFields").attr('data-bom-id');
    let bomType = $("#bomTypeSelect").val();
    let componentType = $row.find('select.componentTypeSelect').val();
    let componentId = $row.find('select.componentDeviceSelect').val();
    let quantity = $row.find('.quantityInput').val();
    const data = {bomId: bomId, bomType: bomType, componentType: componentType, componentId: componentId, quantity: quantity};
    createNewBomRow(data);
    generateBomTable();
});

function createNewBomRow(data)
{
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/admin/bom/edit/add-row.php",
        async: false,
        data: data,
        success: function (data) {
            let wasSuccessful = JSON.parse(data);
            let resultMessage = wasSuccessful ? 
                        "Dodawanie danych powiodło się." : 
                        "Coś poszło nie tak, dane nie zostały edytowane";
            let resultAlertType = wasSuccessful ? 
                        "alert-success" : 
                        "alert-danger";

            let resultAlert = `<div class="alert `+resultAlertType+` alert-dismissible fade show" role="alert">
                `+resultMessage+`
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>`;
            $("#ajaxResult").append(resultAlert);
            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);
        }
    });
}

$("#isActive").change(function(){
    let isActive = $(this).prop('checked');
    let bomId = $("#createNewBomFields").attr('data-bom-id');
    let bomType = $("#bomTypeSelect").val();
    const data = {bomType: bomType, bomId: bomId, isActive: isActive};
    setIsActiveBom(data);
    generateBomTable();
});

function setIsActiveBom(data)
{
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/admin/bom/edit/set-isActive.php",
        async: false,
        data: data,
        success: function (data) {
            let wasSuccessful = JSON.parse(data);
            let resultMessage = wasSuccessful ? 
                        "Dodawanie danych powiodło się." : 
                        "Coś poszło nie tak, dane nie zostały edytowane";
            let resultAlertType = wasSuccessful ? 
                        "alert-success" : 
                        "alert-danger";

            let resultAlert = `<div class="alert `+resultAlertType+` alert-dismissible fade show" role="alert">
                `+resultMessage+`
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>`;
            $("#ajaxResult").append(resultAlert);
            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);
        }
    });
}

$("#previousBom").click(function(){
    let $selectedOption = $("#list__device option:selected");
    $selectedOption.prop('selected', false)
                    .prev().prop('selected', true);
    $("#list__device").selectpicker('refresh').change();
});

$("#nextBom").click(function(){
    let $selectedOption = $("#list__device option:selected");
    $selectedOption.prop('selected', false)
                    .next()
                    .prop('selected', true);
    $("#list__device").selectpicker('refresh').change();
});



