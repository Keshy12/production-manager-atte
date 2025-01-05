const detectNewParts_template = $('script[data-template="detectNewParts_template"]').text().split(/\$\{(.+?)\}/g);

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

$(document).ready(async function() {
    const newParts = await getNewParts();
    const newPartsObj = parseNewPartsJSON(newParts);
    $("#loadingMessage").hide();
    if(newPartsObj === null) return;
    if(Object.keys(newPartsObj).length === 0) {
        $("#tableContainer").append(`<div class="alert alert-info" role="alert">
            Nie wykryto żadnych nowych części.
        </div>`);
        return;
    }
    $("#detectPartsTable, #uploadNewParts").show();
    const $TBody = $('#detectPartsTBody');
    renderTableRows($TBody, newPartsObj);
    $('body').on('click', '.deleteRow', function() {
        const $row = $(this).closest('tr');
        showConfirmDeleteModal($row);
    });
    $('body').on('click', '#confirmDelete', function() {
        const rowId = $(this).attr('data-id');
        deleteRow(rowId, newPartsObj);
    });
    $('body').on('click', '.editRow', function() {
        const $row = $(this).closest('tr');
        generateEditRowFields($row);
    });
    $('body').on('click', '.applyChanges', function() {
        const $row = $(this).closest('tr');
        editRowApply($row, newPartsObj);
        $TBody.empty();
        renderTableRows($TBody, newPartsObj);
    });
    $('body').on('click', '.declineChanges', function() {
        const $row = $(this).closest('tr');
        $TBody.empty();
        renderTableRows($TBody, newPartsObj);
    });
    $("#uploadNewParts").click(function() {
        const newPartsJson = JSON.stringify(newPartsObj);
        uploadNewParts(newPartsJson);
    });
});

function uploadNewParts(newPartsJson) {
    $.ajax({
        url: COMPONENTS_PATH + "/admin/components/detectnewparts/upload-new-parts.php",
        type: 'POST',
        data: {newParts: newPartsJson},
        success: function(response) {
            const result = JSON.parse(response);
            const wasSuccessful = result['wasSuccessful'];
            const errorMessage = result['errorMessage'];

            const resultMessage = wasSuccessful ? 
                        "Edytowanie danych powiodło się." : 
                        "Coś poszło nie tak.<br> Error: "+errorMessage;
            const resultAlertType = wasSuccessful ? 
                        "alert-success" :
                        "alert-danger";

            const resultAlert = `<div class="alert `+resultAlertType+`" role="alert">
                `+resultMessage+`
            </div>`;
            $("#tableContainer").empty().append(resultAlert);
        }
    });
}

function editRowApply($row, newPartsObj) {
    const rowId = parseInt($row.attr('data-id'));
    const componentName = $row.find('.componentNameInput').val();
    const componentDescription = $row.find('.componentDescriptionInput').val();
    const partGroup = $row.find('.PartGroupSelect option:selected').text();
    const partType = $row.find('.PartTypeSelect option:selected').text();
    const partUnit = $row.find('.JMSelect option:selected').text();
    const newData = {
        0: rowId,
        1: componentName,
        2: componentDescription,
        3: partGroup,
        4: partType == "Brak" ? "" : partType,
        5: partUnit
    }
    newPartsObj[rowId] = newData;
}

function generateEditRowFields($row) {
    generateComponentInfoEditFields($row);
    generatePartGroupSelect($row);
    generatePartTypeSelect($row);
    generatePartUnitSelect($row);
    generateSaveCancelButtons($row);
}

function generateSaveCancelButtons($row)
{
    let $editButtons = $row.find(".editButtons");
    $editButtons.empty();

    let $applyChangesButton = $(`<button class="btn mr-1 btn-outline-success applyChanges">
            <i class="bi bi-check-lg"></i>
        </button>`);
    let $declineChangesButton = $(`<button class="btn btn-outline-danger declineChanges">
        <i class="bi bi-x"></i>
    </button>`);

    $editButtons.append($applyChangesButton).append($declineChangesButton);    
}

function generatePartUnitSelect($row) {
    const $partUnit = $row.find('.JM'); 
    const partUnitText = $partUnit.text().trim();
    $partUnit.empty();
    const $partUnitSelect = $("#part__unit_hidden")
                                .clone()
                                .prop('hidden', false)
                                .addClass('selectpicker')
                                .addClass('JMSelect')
                                .removeAttr('id');
    $partUnitSelect.find('option').filter(function() {
        return $(this).text().trim() === partUnitText;
    }).prop('selected', true);
    $partUnit.append($partUnitSelect);
    $('.JMSelect').selectpicker('refresh');
}

function generatePartTypeSelect($row) {
    const $partType = $row.find('.PartType'); 
    const partTypeText = $partType.text().trim() === '' ? 'Brak' : $partType.text().trim();
    $partType.empty();
    const $partTypeSelect = $("#part__type_hidden")
                                .clone()
                                .prop('hidden', false)
                                .addClass('selectpicker')
                                .addClass('PartTypeSelect')
                                .removeAttr('id')
                                .prepend('<option value="0">Brak</option>');
    $partTypeSelect.find('option').filter(function() {
        return $(this).text().trim() === partTypeText;
    }).prop('selected', true);
    $partType.append($partTypeSelect);
    $('.PartTypeSelect').selectpicker('refresh');
}


function generatePartGroupSelect($row) {
    const $partGroup = $row.find('.PartGroup'); 
    const partGroupText = $partGroup.text().trim();
    $partGroup.empty();
    const $partGroupSelect = $("#part__group_hidden")
                                .clone()
                                .prop('hidden', false)
                                .addClass('selectpicker')
                                .addClass('PartGroupSelect')
                                .removeAttr('id');
    $partGroupSelect.find('option').filter(function() {
        return $(this).text().trim() === partGroupText;
    }).prop('selected', true);
    $partGroup.append($partGroupSelect);
    $('.PartGroupSelect').selectpicker('refresh');
}

function generateComponentInfoEditFields($row) {
    const componentName = $row.find('.componentName').text();
    const componentDescription = $row.find('.componentDescription').text();
    const $componentInfo = $row.find('.componentInfo');
    $componentInfo.empty();
    const $componentNameInput = $('<input>').attr('type', 'text')
                                .addClass('form-control w-50 form-control-lg text-center mx-auto')
                                .addClass('componentNameInput')
                                .val(componentName);
    const $componentDescriptionInput = $('<textarea>').attr('rows', '3')
                                .addClass('form-control form-control-sm text-center')
                                .addClass('componentDescriptionInput')
                                .val(componentDescription);
    const $formGroup = $('<div>').addClass('form-group d-flex flex-column justify-content-center');
    $formGroup.append($componentNameInput, $componentDescriptionInput);
    $componentInfo.append($formGroup);
}

function deleteRow(rowId, newPartsObj) {
    delete newPartsObj[rowId];
    $("#confirmDeleteModal").modal('hide');
    let $row = $(`tr[data-id="${rowId}"]`);
    $row.remove();
}

function showConfirmDeleteModal($row) {
    let rowId = $row.attr('data-id');
    $("#confirmDeleteModal").modal('show');
    $("#confirmDelete").attr('data-id', rowId);
}

function parseNewPartsJSON(newParts) {
    try {
        const newPartsJson = JSON.parse(newParts);
        return newPartsJson;
    } catch (e) {
        console.log(newParts);
        console.error("Error parsing JSON: ", e);
        return null;
    }
}


function renderTableRows($TBody, newPartsJson) {
    for(const [key, item] of Object.entries(newPartsJson))
    {
        let renderedItem = detectNewParts_template.map(render(item)).join('');
        let $renderedItem = $(renderedItem);
        $TBody.append($renderedItem);
    }
}


// Request using fetch, because Ajax causes session cookie to be sent.
// This blocks user from using the site while the request is being processed.
async function getNewParts() {
    const response = await fetch(COMPONENTS_PATH + "/admin/components/detectnewparts/get-new-parts.php", {
        method: 'POST',
        credentials: 'omit',
    });
    const result = await response.text();
    return result;
}
