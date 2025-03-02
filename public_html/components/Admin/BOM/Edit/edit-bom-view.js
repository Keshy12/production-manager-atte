const bomEditTableRow_template = $('script[data-template="bomEditTableRow_template"]').text().split(/\$\{(.+?)\}/g);

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

$("#bomTypeSelect").change(function(){
    $("#list__device, #versionSelect, #laminateSelect, #editBomTBody, #alerts").empty();
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
    let bomType = $("#bomTypeSelect").val();
    generateBomTable();
});

$("#list__device").change(function(){
    $("#editBomTBody, #alerts").empty();
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
    $("#versionSelect").selectpicker('refresh');
}

$("#laminateSelect").change(function(){
    $("#versionSelect").empty();
    let possibleVersions = $("#laminateSelect option:selected").data("jsonversions");
    generateVersionSelect(possibleVersions);
    $("#versionSelect").prop('disabled', false);
    $("#versionSelect").selectpicker('refresh');
})

function generateBomTable()
{
    let isEditable = true;
    $("#editButtonsCol, #createNewBomFields, #isActiveField").show();
    $TBody = $("#editBomTBody");
    $TBody.empty();
    let bomType = $("#bomTypeSelect").val();
    let deviceId = $("#list__device").val();
    let version = $("#versionSelect").val();
    let laminate = $("#laminateSelect").val();
    const bomValues = [deviceId];
    let createNewBom = false;
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
        data: {bomType: bomType, bomValues: bomValues, createBom: createNewBom},
        success: function (data) {
            let result = JSON.parse(data);
            let components = result[0];
            let bomId = result[1];
            let isActive = result[2];
            let wasSuccessful = result[3];
            let errorMessage = result[4];
            if(!wasSuccessful) {
                let resultAlert = `<tr>
                <td colspan="3"><div class="alert alert-danger" role="alert">
                    `+errorMessage+`
                </div></td>
                </tr>`;
                $("#editButtonsCol, #createNewBomFields, #isActiveField").hide();
                $("#alerts").append(resultAlert);
                return;
            }
            $("#createNewBomFields").attr('data-bom-id', bomId);
            $("#isActive").prop('checked', isActive);
            for(const [key, item] of Object.entries(components))
            {
                let renderedItem = bomEditTableRow_template.map(render(item)).join('');
                let $renderedItem = $(renderedItem);
                if(!isEditable) {
                    $("#editButtonsCol, #createNewBomFields, #isActiveField").hide();
                    $renderedItem.find('.editButtons').remove();
                }
                $TBody.append($renderedItem);
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
    
    let quantity = $row.find('.quantity').text().trim();

    generateQuantityInput($row, quantity);
    
    generateComponentSelect($row, componentType, componentId);

    generateSaveCancelButtons($row, rowId);
});

function generateQuantityInput($row, quantity)
{
    let $quantity = $row.find('.quantity');
    $quantity.empty();

    let $quantityInput = $(`<input type="text" class="form-control text-center quantityInput" value="`+quantity+`">`);
    $quantity.append($quantityInput);
}

function generateSaveCancelButtons($row, rowId)
{
    let $editButtons = $row.find(".editButtons");
    $editButtons.empty();

    const acceptClass = rowId == '' ? 'createNewRow' : 'applyChanges';

    let $applyChangesButton = $(`<button class="btn mr-1 btn-outline-success `+acceptClass+`">
            <i class="bi bi-check-lg"></i>
        </button>`);
    let $declineChangesButton = $(`<button class="btn btn-outline-danger declineChanges">
        <i class="bi bi-x"></i>
    </button>`);

    $applyChangesButton.attr("data-id", rowId);
    $editButtons.append($applyChangesButton).append($declineChangesButton);    
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
    let componentType = $row.find('select.componentTypeSelect').val();
    let componentId = $row.find('select.componentDeviceSelect').val();
    let quantity = $row.find('.quantityInput').val();
    const data = {rowId: rowId, componentType: componentType, componentId: componentId, quantity: quantity};
    editBomRow(data);
    generateBomTable();
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
