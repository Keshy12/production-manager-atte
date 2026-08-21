const bomEditTableRow_template = $('script[data-template="bomEditTableRow_template"]').text().split(/\$\{(.+?)\}/g);

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

function formatPrice(price) {
    return parseFloat(price.toFixed(4)).toString();
}

$(document).ready(function() {
    let defaultWarehouse = $('#warehouseSelect').data('default');
    if (defaultWarehouse) {
        $('#warehouseSelect').val(defaultWarehouse);
    }
    $('#warehouseSelect').selectpicker('refresh');

    $('#editWarehouseBtn').click(function() {
        $('#warehouseReadMode').addClass('d-none').removeClass('d-flex');
        $('#warehouseEditMode').removeClass('d-none').addClass('d-flex');
        $('#warehouseSelect').selectpicker('refresh');
    });

    $('#warehouseSelect').change(function() {
        let selectedName = $('#warehouseSelect option:selected').text();
        $('#warehouseLabel').text('Magazyn: ' + selectedName);
        $('#warehouseEditMode').addClass('d-none').removeClass('d-flex');
        $('#warehouseReadMode').removeClass('d-none').addClass('d-flex');
        if ($('#list__device').val()) {
            generateBomTable();
        }
    });
});

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
    if(this.value == 'smd') {
        $("#laminateSelect").empty().append($('#list__laminate_hidden option').clone());
        $("#laminateSelect").prop('disabled', false).selectpicker('refresh');
    }
});

function showAdditionalFields(type)
{
    $("#laminateField, #versionField, #createNewBomFields, #isActiveField, #clearCascadeBtn").hide();
    $("#clearCascadeBtn").prop("disabled", true);
    if(type == "tht")
    {
        $("#versionField, #clearCascadeBtn").show();
        $("#clearCascadeBtn").prop("disabled", false);
    }
    else if(type == "smd")
    {
        $("#versionField, #laminateField, #clearCascadeBtn").show();
        $("#clearCascadeBtn").prop("disabled", false);
    }
}

$("#versionSelect").change(function(){
    $("#editBomTBody, #alerts").empty(); // Wipe BOM so user sees the new BOM load
    $("#bomTotalPriceContainer").hide(); // Hide price on version change
    generateBomTable();
});

$("#list__device").change(function(){
    $("#editBomTBody, #alerts").empty();
    $("#bomTotalPriceContainer").hide(); // Hide price on device change
    let bomType = $("#bomTypeSelect").val();
    let deviceId = $("#list__device").val();
    let jsonLaminates = $("#list__device option[value='"+deviceId+"']").data("jsonlaminates");
    if(bomType == 'smd') {
        // 3d logic goes here
        let selectedLaminateId = $("#laminateSelect").val();
        // Filter laminates to only those compatible with selected device
        $("#laminateSelect option").each(function(){
            if($(this).val() === selectedLaminateId) return;  // keep selected laminate
            let optVal = $(this).val();
            if(!jsonLaminates[optVal]) {
                $(this).remove();
            }
        });
        // If previously-selected laminate was removed, reset and re-clone from hidden
        if(selectedLaminateId && !jsonLaminates[selectedLaminateId]) {
            $("#laminateSelect").empty().append($('#list__laminate_hidden option').clone());
            let preservedLaminateId = selectedLaminateId;
            $("#laminateSelect option").each(function(){
                if($(this).val() === preservedLaminateId) return;  // keep selected laminate
                let optVal = $(this).val();
                if(!jsonLaminates[optVal]) {
                    $(this).remove();
                }
            });
            // Don't reset selectedLaminateId to null — keep the user's selection so visual+payload stay correct
            $("#laminateSelect").prop('disabled', false);
        }
        // Patch data-jsonversions for selected laminate
        if(selectedLaminateId && jsonLaminates[selectedLaminateId]) {
            let versions = jsonLaminates[selectedLaminateId].versions;
            $("#laminateSelect option[value='"+selectedLaminateId+"']").attr('data-jsonversions', JSON.stringify(versions));
            generateVersionSelect(versions, true, true);
            $("#versionSelect").prop('disabled', false).selectpicker('refresh');
        } else {
            $("#versionSelect").empty().prop('disabled', true).selectpicker('refresh');
        }
        $("#laminateSelect").selectpicker('refresh');
        // Re-apply laminate selection after refresh
        if(selectedLaminateId && jsonLaminates[selectedLaminateId]) {
            $("#laminateSelect").val(selectedLaminateId);
            $("#laminateSelect").selectpicker('render');
        }
    } else if(bomType == 'tht') {
        // existing version cascade — keep as-is
        $("#versionSelect").empty();
        let possibleVersions = $("#list__device option[value='"+deviceId+"']").data("jsonversions");
        generateVersionSelect(possibleVersions, true, true);
        $("#versionSelect").prop('disabled', false).selectpicker('refresh');
    }
    if(bomType == 'sku') generateBomTable();
});

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

function generateVersionSelect(possibleVersions, autoSelect = false, autoLoadBom = false){
    $("#versionSelect").empty();
    if(Object.keys(possibleVersions).length == 1) {
        if(possibleVersions[0] == null)
        {
            let version = 'n/d';
            let option = "<option value='"+version+"'"+(autoSelect ? ' selected' : '')+">n/d</option>";
            $("#versionSelect").append(option);
            $("#versionSelect").prop('disabled', false);
            $("#versionField").hide();
            $("#versionSelect").selectpicker('refresh');
            if (autoSelect) $("#versionSelect").selectpicker('val', version);
            if (autoLoadBom) generateBomTable();
            return;
        }
        let version_id = Object.keys(possibleVersions)[0];
        let version = possibleVersions[version_id][0];
        let option = "<option value='"+version+"'"+(autoSelect ? ' selected' : '')+">"+version+"</option>";
        $("#versionSelect").append(option);
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
    if (autoSelect && Object.keys(possibleVersions).length == 1) {
        let version_id = Object.keys(possibleVersions)[0];
        let version = possibleVersions[version_id][0];
        $("#versionSelect").selectpicker('val', version);
    }
    if (autoLoadBom && Object.keys(possibleVersions).length == 1) generateBomTable();
}

$("#laminateSelect").change(function(){
    let selectedLaminateId = $(this).val();
    let deviceId = $("#list__device").val();
    let bomType = $("#bomTypeSelect").val();
    $("#editBomTBody, #alerts").empty();
    $("#bomTotalPriceContainer").hide();
    // Re-populate device list from hidden source before applying filter
    // (this ensures the filter starts from the full list, not a previously-filtered one)
    if(bomType == 'smd') {
        $("#list__device").empty().append($('#list__smd_hidden option').clone());
        $("#list__device").selectpicker('refresh');
    }
    // Filter devices: keep only those whose data-jsonLaminates contains the selected laminate id
    $("#list__device option").each(function(){
        if($(this).val() === deviceId) return;  // keep selected device
        let laminates = $(this).data("jsonlaminates") || {};
        if(!laminates[selectedLaminateId]) {
            $(this).remove();
        }
    });
    // Preserve previously selected device
    if(deviceId && $("#list__device option[value='"+deviceId+"']").length) {
        $("#list__device").val(deviceId);
        $("#list__device").selectpicker('render');
    }
    // If a device is selected, re-patch its selected laminate's data-jsonversions and populate version
    let selectedDevice = $("#list__device option[value='"+deviceId+"']");
    // Check if both device and laminate are selected
    if (deviceId && selectedLaminateId && selectedDevice.length) {
        // Defensive: ensure device value is set right before generateBomTable() reads it
        if($("#list__device option[value='"+deviceId+"']").length) {
            $("#list__device").val(deviceId);
            $("#list__device").selectpicker('render');
        }
        let laminates = selectedDevice.data("jsonlaminates") || {};
        if (laminates[selectedLaminateId]) {
            let versions = laminates[selectedLaminateId].versions;
            $("#laminateSelect option[value='"+selectedLaminateId+"']").attr('data-jsonversions', JSON.stringify(versions));
            generateVersionSelect(versions, true, true);
            $("#versionSelect").prop('disabled', false).selectpicker('refresh');
        } else {
            $("#versionSelect").empty().prop('disabled', true).selectpicker('refresh');
        }
    } else {
        $("#versionSelect").empty().prop('disabled', true).selectpicker('refresh');
    }
    $("#list__device").selectpicker('refresh');
    // Re-apply device selection after refresh
    if(deviceId && $("#list__device option[value='"+deviceId+"']").length) {
        $("#list__device").val(deviceId);
        $("#list__device").selectpicker('render');
    }
});

let bomTableXhr = null;

function generateBomTable()
{
    let isEditable = true;
    $TBody = $("#editBomTBody");
    $TBody.empty();
    $("#bomLoadingRow").show();
    let bomType = $("#bomTypeSelect").val();
    let deviceId = $("#list__device").val();
    let version = $("#versionSelect").val();
    let laminate = $("#laminateSelect").val();
    let warehouseId = $("#warehouseSelect").val();
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
    // Cancel any in-flight BOM request so stale responses don't overwrite the latest
    if(bomTableXhr && bomTableXhr.readyState !== 4) {
        bomTableXhr.abort();
    }
    bomTableXhr = $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/admin/bom/edit/get-bom-components.php",
        data: {bomType: bomType, bomValues: bomValues, createNewBom: createNewBom, warehouseId: warehouseId},
        beforeSend: function() {
            $("#bomLoadingRow").show();
        },
        success: function (data) {
            let result = data;
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
                $("#createNewBomFields, #cloneBomBtn, #isActiveField, #bomTotalPriceContainer").hide();
                // $("#editButtonsCol").hide(); // Remove this line
                $("#alerts").append(resultAlert);
                return;
            }
            $("#createNewBomFields").attr('data-bom-id', bomId);
            $("#isActive").prop('checked', isActive);
            
            $("#bomTotalPrice").text(formatPrice(bomTotalPrice));
            $("#bomTotalPriceContainer").show();

            if (isEditable) {
                $("#createNewBomFields, #cloneBomBtn, #isActiveField").show();
            } else {
                $("#createNewBomFields, #cloneBomBtn, #isActiveField").hide();
            }
            
            let hasMissingDefault = false;
            
            if(outSmdPrice !== null) {
                let smdItem = {
                    rowId: 'out_smd',
                    type: '',
                    componentName: 'OUT_SMD',
                    componentDescription: `<small class="text-muted">Koszt produkcji elementu SMD (ilosc komponentow*cena polozenia komponentu przez maszyne)</small>`,
                    quantity: `<span class="qty-value">`+outSmdQty+`</span>` + `<br><span class="text-muted small">`+ formatPrice(outSmdPricePerItem) + ` PLN/szt</span>`,
                    componentId: '',
                    stockQty: '-',
                    price: `<b>`+outSmdPrice.toFixed(2)+`PLN</b>`
                };
                let renderedSmdItem = bomEditTableRow_template.map(render(smdItem)).join('');
                let $renderedSmdItem = $(renderedSmdItem);
                $renderedSmdItem.find('.actionButtons').empty();
                $TBody.append($renderedSmdItem);
            }

            if(bomType == 'tht') {
                let thtItem = {
                    rowId: 'out_tht',
                    type: '',
                    componentName: 'OUT_THT',
                    componentDescription: `<small class="text-muted">Koszt produkcji elementu THT (ilosc komponentow wyprodukowanych w ciagu godziny/stawka godzinowa pracownika)</small>`,
                    quantity: `<span class="qty-value">`+outThtQuantity+`</span>` + `<br><span class="text-muted small">`+ formatPrice(outThtPricePerItem) + ` PLN/szt</span>`,
                    componentId: '',
                    stockQty: '-',
                    price: `<b>`+outThtPrice.toFixed(2)+`PLN</b>`
                };
                let renderedThtItem = bomEditTableRow_template.map(render(thtItem)).join('');
                $TBody.append(renderedThtItem);
            }
            
            for(const [key, item] of Object.entries(components))
            {
                let hasError = false;
                let errorMessage = '';
                
                if (item.missing_default == 1) {
                    hasError = true;
                    item.componentName = `<span class="text-danger">` + item.componentName + `</span>`;
                    errorMessage = `Brak domyślnej wersji BOM dla tego komponentu!`;
                }
                else if (item.hasMissingPrice) {
                    hasError = true;
                    item.componentName = `<span class="text-danger">` + item.componentName + `</span>`;
                    errorMessage = `Brak ceny dla tego komponentu!`;
                }
                else if (item.hasNestedMissingPrices) {
                    hasError = true;
                    item.componentName = `<span class="text-danger">` + item.componentName + `</span>`;
                    const missingComponents = Array.isArray(item.nestedMissingComponents) ? item.nestedMissingComponents : [];
                    const missingList = missingComponents.join(', ');
                    errorMessage = `Podzespoły brakuje ceny: ` + missingList;
                }
                
                if (hasError) {
                    hasMissingDefault = true;
                    item.componentDescription = `<span class="text-danger small font-weight-bold">` + errorMessage + `</span><br>` + item.componentDescription;
                    item.price = `<b class="text-danger">` + item.totalPrice.toFixed(2) + `PLN</b>`;
                } else {
                    item.price = `<b>` + item.totalPrice.toFixed(2) + `PLN</b>`;
                }

                item.quantity = `<span class="qty-value">`+item.quantity+`</span>` + `<br><span class="text-muted small">`+ formatPrice(item.pricePerItem) + ` PLN/` + item.unitName + `</span>`;
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
        },
        error: function(jqXHR, textStatus, errorThrown) {
            if(textStatus !== 'abort') {
                console.error('BOM load failed:', textStatus, errorThrown);
            }
        },
        complete: function() {
            $("#bomLoadingRow").hide();
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
        // Keep OUT_THT name and description visible while editing
        $row.find('.componentInfo').html(`<b>OUT_THT</b><br><small class="text-muted">Koszt produkcji elementu THT (ilosc komponentow wyprodukowanych w ciagu godziny/stawka godzinowa pracownika)</small>`);
    }

    generateSaveCancelButtons($row, rowId);
});

function generateQuantityInput($row, quantity)
{
    let $quantity = $row.find('.quantity');
    $quantity.empty();

    let rowId = $row.find('.editBomRow').attr('data-id'); // Assuming editBomRow exists and has data-id
    let readOnlyAttr = (rowId === 'out_smd') ? 'readonly' : '';

    let $quantityInput = $(`<input type="text" class="form-control text-center quantityInput" value="`+quantity+`" `+readOnlyAttr+`>`);
    $quantity.append($quantityInput);

    // Add real-time comma-to-dot conversion
    $quantityInput.on('input', function() {
        let val = $(this).val();
        val = val.replace(',', '.');
        $(this).val(val);
    });
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
                let result = data;
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
            let wasSuccessful = data;
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
            let wasSuccessful = data;
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
            let wasSuccessful = data;
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
            let wasSuccessful = data;
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

$("#clearCascadeBtn").click(function(){
    let bomType = $("#bomTypeSelect").val();
    if(bomType !== 'smd' && bomType !== 'tht') return;

    $("#editBomTBody, #alerts").empty();
    $("#bomTotalPriceContainer").hide();

    $("#list__device").empty().append($('#list__'+bomType+'_hidden option').clone());
    $("#list__device, #previousBom, #nextBom").prop("disabled", false).selectpicker('refresh');

    $("#versionSelect").empty().prop('disabled', true).selectpicker('refresh');

    if(bomType == 'smd') {
        $("#laminateSelect").empty().append($('#list__laminate_hidden option').clone());
        $("#laminateSelect").prop('disabled', false).selectpicker('refresh');
    }
});


// ---------- Clone BOM ----------
function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];
    });
}

function resetCloneModal(bomType) {
    $("#cloneBomModal").attr('data-bom-type', bomType);

    // Source device is populated from the same hidden list as the target selector
    let $device = $("#cloneSourceDevice");
    $device.empty().append($('#list__' + bomType + '_hidden option').clone());
    $device.selectpicker('refresh').selectpicker('val', '');

    // Reset cascade
    $("#cloneSourceLaminate").empty().prop('disabled', true).selectpicker('refresh').selectpicker('val', '');
    $("#cloneSourceVersion").empty().prop('disabled', true).selectpicker('refresh').selectpicker('val', '');
    $("#cloneSourceLaminateGroup, #cloneSourceVersionGroup").hide();

    // Reset preview / state
    $("#cloneSourcePreview").hide().empty();
    $("#cloneTargetWarning").hide().empty();
    $("#cloneModalAlert").empty();
    $("#cloneSourceBomId").val('');
    $("#cloneBomConfirmBtn").prop('disabled', true);

    // Show controls appropriate to the bom type
    if (bomType === 'tht' || bomType === 'sku') {
        $("#cloneSourceVersionGroup").show();
    } else if (bomType === 'smd') {
        $("#cloneSourceLaminateGroup, #cloneSourceVersionGroup").show();
        $("#cloneSourceLaminate").empty().append($('#list__laminate_hidden option').clone());
        $("#cloneSourceLaminate").prop('disabled', false).selectpicker('refresh').selectpicker('val', '');
    }
}

function clearClonePreview() {
    $("#cloneSourcePreview").hide().empty();
    $("#cloneSourceBomId").val('');
    $("#cloneBomConfirmBtn").prop('disabled', true);
}

function countTargetRows() {
    return $("#editBomTBody tr").filter(function() {
        let ct = $(this).find('button[data-component-type]').first().attr('data-component-type');
        // Truthy filter — OUT_SMD / OUT_THT rows are rendered with data-component-type=""
        return !!ct;
    }).length;
}

$("#cloneBomBtn").click(function(){
    let targetBomId = $("#createNewBomFields").attr('data-bom-id');
    let bomType = $("#bomTypeSelect").val();
    if (!targetBomId) {
        $("#alerts").html(`<tr><td colspan="3"><div class="alert alert-danger" role="alert">
            Najpierw wybierz docelowy BOM.
        </div></td></tr>`);
        return;
    }
    if (!bomType) {
        $("#alerts").html(`<tr><td colspan="3"><div class="alert alert-danger" role="alert">
            Najpierw wybierz typ BOM.
        </div></td></tr>`);
        return;
    }
    if (bomType === 'smd') {
        // SMD rows are not editable on this page; nothing to clone into.
        return;
    }

    resetCloneModal(bomType);
    $("#cloneBomModal").attr('data-target-bom-id', targetBomId);

    let targetRowCount = countTargetRows();
    if (targetRowCount > 0) {
        $("#cloneTargetWarning").html(
            '<div class="alert alert-warning mb-0">Uwaga: aktualny BOM zostanie nadpisany (ma '
            + targetRowCount + ' pozycji).</div>'
        ).show();
    }

    $("#cloneBomModal").modal('show');
});

$("#cloneSourceDevice").change(function(){
    clearClonePreview();
    let bomType = $("#cloneBomModal").attr('data-bom-type');
    let deviceId = $(this).val();
    if (!deviceId) {
        $("#cloneSourceVersion").empty().prop('disabled', true).selectpicker('refresh');
        return;
    }

    if (bomType === 'tht' || bomType === 'sku') {
        let possibleVersions = $("#cloneSourceDevice option[value='" + deviceId + "']").data("jsonversions") || {};
        let $ver = $("#cloneSourceVersion");
        $ver.empty();
        let keys = Object.keys(possibleVersions);
        if (keys.length === 1 && possibleVersions[keys[0]] == null) {
            $ver.append('<option value="n/d">n/d</option>');
        } else if (keys.length > 0) {
            for (let vid in possibleVersions) {
                if (!possibleVersions.hasOwnProperty(vid)) continue;
                let vname = possibleVersions[vid][0];
                $ver.append('<option value="' + vname + '">' + vname + '</option>');
            }
        } else {
            $ver.append('<option value="n/d">n/d</option>');
        }
        $ver.prop('disabled', false).selectpicker('refresh').selectpicker('val', '');
    } else if (bomType === 'smd') {
        let jsonLaminates = $("#cloneSourceDevice option[value='" + deviceId + "']").data("jsonlaminates") || {};
        let $lam = $("#cloneSourceLaminate");
        $lam.empty();
        for (let lamId in jsonLaminates) {
            if (!jsonLaminates.hasOwnProperty(lamId)) continue;
            let lamName = jsonLaminates[lamId][0];
            let versions = JSON.stringify(jsonLaminates[lamId].versions || {});
            $lam.append('<option value="' + lamId + '" data-jsonversions=\'' + versions + '\'>' + lamName + '</option>');
        }
        $lam.prop('disabled', false).selectpicker('refresh').selectpicker('val', '');
        $("#cloneSourceVersion").empty().prop('disabled', true).selectpicker('refresh');
    }
});

$("#cloneSourceLaminate").change(function(){
    clearClonePreview();
    let bomType = $("#cloneBomModal").attr('data-bom-type');
    if (bomType !== 'smd') return;
    let selectedLaminateId = $(this).val();
    if (!selectedLaminateId) {
        $("#cloneSourceVersion").empty().prop('disabled', true).selectpicker('refresh');
        return;
    }
    let versions = $("#cloneSourceLaminate option[value='" + selectedLaminateId + "']").data("jsonversions") || {};
    let $ver = $("#cloneSourceVersion");
    $ver.empty();
    for (let vid in versions) {
        if (!versions.hasOwnProperty(vid)) continue;
        let vname = versions[vid][0];
        $ver.append('<option value="' + vname + '">' + vname + '</option>');
    }
    $ver.prop('disabled', false).selectpicker('refresh').selectpicker('val', '');
});

let clonePreviewXhr = null;

$("#cloneSourceVersion").change(function(){
    clearClonePreview();
    let bomType  = $("#cloneBomModal").attr('data-bom-type');
    let deviceId = $("#cloneSourceDevice").val();
    let version  = $(this).val();
    let laminateId = (bomType === 'smd') ? $("#cloneSourceLaminate").val() : '';
    if (!bomType || !deviceId || version === null) {
        return;
    }

    if (clonePreviewXhr && clonePreviewXhr.readyState !== 4) {
        clonePreviewXhr.abort();
    }

    clonePreviewXhr = $.ajax({
        type: "POST",
        url: COMPONENTS_PATH + "/admin/bom/edit/get-bom-preview.php",
        data: {
            bomType: bomType,
            deviceId: deviceId,
            version: version,
            laminateId: laminateId
        },
        success: function(data) {
            if (!data.wasSuccessful) {
                $("#cloneSourcePreview").html(
                    '<div class="text-danger">' + escapeHtml(data.errorMessage || 'Nie udało się pobrać podglądu.') + '</div>'
                ).show();
                return;
            }

            $("#cloneSourceBomId").val(data.sourceBomId || '');
            $("#cloneBomConfirmBtn").prop('disabled', !(data.sourceBomId && data.sourceBomId > 0));

            let count = data.componentCount || 0;
            let $content = $('<div></div>');
            $content.append('<div><b>Ten BOM zawiera ' + count + ' pozycji</b></div>');
            if (data.components && data.components.length > 0) {
                let $list = $('<ul class="mb-0 mt-2 small"></ul>');
                let maxList = 10;
                for (let i = 0; i < Math.min(data.components.length, maxList); i++) {
                    let c = data.components[i];
                    $list.append('<li>' + escapeHtml(c.name) + ' <span class="text-muted">(' + escapeHtml(c.type) + ' &times;' + c.quantity + ')</span></li>');
                }
                if (data.components.length > maxList) {
                    $list.append('<li class="text-muted">...i ' + (data.components.length - maxList) + ' więcej</li>');
                }
                $content.append($list);
            }
            $("#cloneSourcePreview").empty().append($content).show();
        },
        error: function(jqXHR, textStatus) {
            if (textStatus === 'abort') return;
            $("#cloneSourcePreview").html(
                '<div class="text-danger">Błąd połączenia z serwerem.</div>'
            ).show();
        }
    });
});

$("#cloneBomConfirmBtn").click(function(){
    let bomType      = $("#cloneBomModal").attr('data-bom-type');
    let targetBomId  = $("#cloneBomModal").attr('data-target-bom-id');
    let sourceBomId  = $("#cloneSourceBomId").val();
    if (!bomType || !targetBomId || !sourceBomId) {
        $("#cloneModalAlert").html(
            '<div class="alert alert-danger mb-0">Nie wybrano źródłowego BOM-u.</div>'
        );
        return;
    }

    let $btn = $(this);
    $btn.prop('disabled', true);
    $("#cloneModalAlert").empty();

    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH + "/admin/bom/edit/clone-bom.php",
        data: {
            bomType: bomType,
            targetBomId: targetBomId,
            sourceBomId: sourceBomId
        },
        success: function(data) {
            if (data && data.wasSuccessful) {
                $("#cloneBomModal").modal('hide');
                let inserted = (data.insertedCount != null) ? data.insertedCount : 0;
                let resultAlert = `<div class="alert alert-success alert-dismissible fade show" role="alert">
                    Skopiowano ` + inserted + ` pozycji z BOM źródłowego.
                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>`;
                $("#ajaxResult").append(resultAlert);
                setTimeout(function() {
                    $(".alert-success").alert('close');
                }, 2000);
                generateBomTable();
            } else {
                let msg = (data && data.errorMessage) ? data.errorMessage : 'Nie udało się sklonować BOM.';
                $("#cloneModalAlert").html(
                    '<div class="alert alert-danger mb-0">' + escapeHtml(msg) + '</div>'
                );
                $btn.prop('disabled', false);
            }
        },
        error: function() {
            $("#cloneModalAlert").html(
                '<div class="alert alert-danger mb-0">Błąd połączenia z serwerem.</div>'
            );
            $btn.prop('disabled', false);
        }
    });
});



