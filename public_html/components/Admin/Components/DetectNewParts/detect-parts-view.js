const detectNewParts_template = $('script[data-template="detectNewParts_template"]').text().split(/\$\{(.+?)\}/g);

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

$(document).ready(async function() {
    const response = await getNewParts();
    const result = JSON.parse(response);
    const newPartsObj = result['newParts'];
    const editedPartsObj = result['editedParts'];
    const missingRefs = result['missingRefs'];
    $("#loadingMessage").hide();

    const allItems = { ...newPartsObj, ...editedPartsObj };
    const hasAnyItems = Object.keys(allItems).length > 0;

    if (!hasAnyItems) {
        $("#tableContainer").append(`<div class="alert alert-info" role="alert">
            Nie wykryto żadnych nowych części/zmian.
        </div>`);
        return;
    }

    const hasMissing = Object.values(missingRefs).some(arr => arr.length > 0);
    if (hasMissing) {
        let missingLines = [];
        if (missingRefs['part__group'].length > 0) {
            missingLines.push('<b>PartGroup:</b> ' + missingRefs['part__group'].join(', '));
        }
        if (missingRefs['part__type'].length > 0) {
            missingLines.push('<b>PartType:</b> ' + missingRefs['part__type'].join(', '));
        }
        if (missingRefs['part__unit'].length > 0) {
            missingLines.push('<b>JM:</b> ' + missingRefs['part__unit'].join(', '));
        }
        $("#tableContainer").append(`<div class="alert alert-warning" role="alert">
            Następujące wartości referencyjne nie istnieją w bazie i zostaną automatycznie utworzone:<br>
            ${missingLines.join('<br>')}
        </div>`);
    }
    $("#detectPartsTable, #uploadNewParts").show();
    const $TBody = $('#detectPartsTBody');

    const transformedNewParts = transformNewParts(newPartsObj);
    const transformedEditedParts = transformEditedParts(editedPartsObj);
    const allTransformed = { ...transformedNewParts, ...transformedEditedParts };

    renderTableRows($TBody, allTransformed);

    $('body').on('click', '.deleteRow', function() {
        const $row = $(this).closest('tr');
        const rowId = parseInt($row.attr('data-id'));
        const rowType = $row.attr('data-type');
        showConfirmDeleteModal($row, rowId, rowType);
    });
    $('body').on('click', '#confirmDelete', function() {
        const rowId = parseInt($(this).attr('data-id'));
        const rowType = $(this).attr('data-type');
        deleteRow(rowId, rowType, transformedNewParts, transformedEditedParts);
    });
    $('body').on('click', '.editRow', function() {
        const $row = $(this).closest('tr');
        generateEditRowFields($row);
    });
    $('body').on('click', '.applyChanges', function() {
        const $row = $(this).closest('tr');
        const rowId = parseInt($row.attr('data-id'));
        const rowType = $row.attr('data-type');
        editRowApply($row, rowId, rowType, transformedNewParts, transformedEditedParts);
        $TBody.empty();
        const allTransformed = { ...transformedNewParts, ...transformedEditedParts };
        renderTableRows($TBody, allTransformed);
    });
    $('body').on('click', '.declineChanges', function() {
        const $TBody = $('#detectPartsTBody');
        $TBody.empty();
        const allTransformed = { ...transformedNewParts, ...transformedEditedParts };
        renderTableRows($TBody, allTransformed);
    });
    $('body').on('click', 'td.cell-edited', function() {
        $(this).find('.change-content').collapse('toggle');
    });
    $("#uploadNewParts").click(function() {
        const newPartsJson = JSON.stringify(transformedNewParts);
        const editedPartsJson = JSON.stringify(transformedEditedParts);
        uploadNewParts(newPartsJson, editedPartsJson);
    });
});

function getLongestCommonPrefix(str1, str2) {
    if (!str1 || !str2) return '';
    let common = '';
    for (let i = 0; i < Math.min(str1.length, str2.length); i++) {
        if (str1[i] === str2[i]) {
            common += str1[i];
        } else {
            break;
        }
    }
    return common;
}

function renderDiffValue(oldVal, newVal) {
    const lcp = getLongestCommonPrefix(oldVal, newVal);
    if (!lcp) {
        return { oldHtml: oldVal, newHtml: newVal };
    }
    const oldDiff = oldVal.substring(lcp.length);
    const newDiff = newVal.substring(lcp.length);
    return {
        oldHtml: `<span class="text-muted">${lcp}</span><strong>${oldDiff}</strong>`,
        newHtml: `<span class="text-muted">${lcp}</span><strong>${newDiff}</strong>`
    };
}

function transformNewParts(newParts) {
    const transformed = {};
    for (const item of newParts) {
        const key = item[0];
        transformed[key] = {
            type: 'new',
            0: item[0],
            1: item[1],
            2: item[2],
            3: item[3],
            4: item[4],
            5: item[5],
            componentInfoClass: '',
            componentNameClass: '',
            componentDescClass: '',
            PartGroupClass: '',
            PartTypeClass: '',
            JMClass: '',
            nameToggle: '',
            descriptionToggle: '',
            PartGroupToggle: '',
            PartTypeToggle: '',
            JMToggle: '',
            changes_name_from: item[1],
            changes_description_from: item[2],
            changes_PartGroup_from: item[3],
            changes_PartType_from: item[4],
            changes_JM_from: item[5]
        };
    }
    return transformed;
}

function transformEditedParts(editedParts) {
    const transformed = {};
    for (const item of editedParts) {
        const key = item.data[0];
        const changes = item.changes || {};
        const nameChanged = !!changes['name'];
        const descChanged = !!changes['description'];
        const id = item.data[0];

        let nameToggle = '';
        if (nameChanged) {
            const nameFrom = changes['name']?.from || '';
            const nameTo = changes['name']?.to || '';
            const nameDiff = renderDiffValue(nameFrom, nameTo);
            nameToggle = `<div class="collapse change-content text-left mt-1 border border-dark rounded bg-white p-1"><strong>Było:</strong> ${nameDiff.oldHtml}<br><strong>Jest:</strong> ${nameDiff.newHtml}</div>`;
        }

        let descriptionToggle = '';
        if (descChanged) {
            const descFrom = changes['description']?.from || '';
            const descTo = changes['description']?.to || '';
            const descDiff = renderDiffValue(descFrom, descTo);
            descriptionToggle = `<div class="collapse change-content text-left mt-1 border border-dark rounded bg-white p-1"><strong>Było:</strong> ${descDiff.oldHtml}<br><strong>Jest:</strong> ${descDiff.newHtml}</div>`;
        }

        let PartGroupToggle = '';
        if (changes['PartGroup']) {
            const from = changes['PartGroup'].from || '';
            const to = changes['PartGroup'].to || '';
            PartGroupToggle = `<div class="collapse change-content text-left mt-1 border border-dark rounded bg-white p-1"><strong>Było:</strong> ${from}<br><strong>Jest:</strong> ${to}</div>`;
        }

        let PartTypeToggle = '';
        if (changes['PartType']) {
            const from = changes['PartType'].from || '';
            const to = changes['PartType'].to || '';
            PartTypeToggle = `<div class="collapse change-content text-left mt-1 border border-dark rounded bg-white p-1"><strong>Było:</strong> ${from}<br><strong>Jest:</strong> ${to}</div>`;
        }

        let JMToggle = '';
        if (changes['JM']) {
            const from = changes['JM'].from || '';
            const to = changes['JM'].to || '';
            JMToggle = `<div class="collapse change-content text-left mt-1 border border-dark rounded bg-white p-1"><strong>Było:</strong> ${from}<br><strong>Jest:</strong> ${to}</div>`;
        }

        transformed[key] = {
            type: 'edited',
            0: item.data[0],
            1: item.data[1],
            2: item.data[2],
            3: item.data[3],
            4: item.data[4],
            5: item.data[5],
            componentInfoClass: (nameChanged || descChanged) ? ' cell-edited' : '',
            componentNameClass: nameChanged ? ' cell-edited' : '',
            componentDescClass: descChanged ? ' cell-edited' : '',
            PartGroupClass: !!changes['PartGroup'] ? ' cell-edited' : '',
            PartTypeClass: !!changes['PartType'] ? ' cell-edited' : '',
            JMClass: !!changes['JM'] ? ' cell-edited' : '',
            nameToggle: nameToggle,
            descriptionToggle: descriptionToggle,
            PartGroupToggle: PartGroupToggle,
            PartTypeToggle: PartTypeToggle,
            JMToggle: JMToggle,
            changes_name_from: changes['name']?.from || '',
            changes_description_from: changes['description']?.from || '',
            changes_PartGroup_from: changes['PartGroup']?.from || '',
            changes_PartType_from: changes['PartType']?.from || '',
            changes_JM_from: changes['JM']?.from || ''
        };
    }
    return transformed;
}

function uploadNewParts(newPartsJson, editedPartsJson) {
    $.ajax({
        url: COMPONENTS_PATH + "/admin/components/detectnewparts/upload-new-parts.php",
        type: 'POST',
        data: {
            newParts: newPartsJson,
            editedParts: editedPartsJson
        },
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

function editRowApply($row, rowId, rowType, transformedNewParts, transformedEditedParts) {
    const componentName = $row.find('.componentNameInput').val().trim();
    const componentDescription = $row.find('.componentDescriptionInput').val().trim();
    const partGroup = $row.find('.PartGroupSelect option:selected').text();
    const partType = $row.find('.PartTypeSelect option:selected').text();
    const partUnit = $row.find('.JMSelect option:selected').text();

    const targetObj = rowType === 'new' ? transformedNewParts : transformedEditedParts;
    const numericRowId = parseInt(rowId);
    const original = targetObj[numericRowId];

    if (!original) {
        console.error(`Row ${rowId} (type: ${rowType}) not found`);
        return;
    }

    const sourceName = original.changes_name_from || original[1] || '';
    const sourceDesc = original.changes_description_from || original[2] || '';
    const sourcePg = original.changes_PartGroup_from || original[3] || '';
    const sourcePt = original.changes_PartType_from || original[4] || '';
    const sourceJm = original.changes_JM_from || original[5] || '';

    const nameChanged = componentName !== sourceName;
    const descChanged = componentDescription !== sourceDesc;
    const pgChanged = partGroup !== sourcePg;
    const ptChanged = (partType === "Brak" ? "" : partType) !== sourcePt;
    const jmChanged = partUnit !== sourceJm;

    const nameDiff = renderDiffValue(sourceName, componentName);
    const nameToggle = nameChanged ?
        `<div class="collapse change-content text-left mt-1 border border-dark rounded bg-white p-1"><strong>Było:</strong> ${nameDiff.oldHtml}<br><strong>Jest:</strong> ${nameDiff.newHtml}</div>` : '';

    const descDiff = renderDiffValue(sourceDesc, componentDescription);
    const descriptionToggle = descChanged ?
        `<div class="collapse change-content text-left mt-1 border border-dark rounded bg-white p-1"><strong>Było:</strong> ${descDiff.oldHtml}<br><strong>Jest:</strong> ${descDiff.newHtml}</div>` : '';

    const PartGroupToggle = pgChanged ?
        `<div class="collapse change-content text-left mt-1 border border-dark rounded bg-white p-1"><strong>Było:</strong> ${sourcePg}<br><strong>Jest:</strong> ${partGroup}</div>` : '';

    const PartTypeToggle = ptChanged ?
        `<div class="collapse change-content text-left mt-1 border border-dark rounded bg-white p-1"><strong>Było:</strong> ${sourcePt}<br><strong>Jest:</strong> ${partType === "Brak" ? "" : partType}</div>` : '';

    const JMToggle = jmChanged ?
        `<div class="collapse change-content text-left mt-1 border border-dark rounded bg-white p-1"><strong>Było:</strong> ${sourceJm}<br><strong>Jest:</strong> ${partUnit}</div>` : '';

    targetObj[numericRowId] = {
        type: original.type,
        componentInfoClass: (nameChanged || descChanged) ? ' cell-edited' : '',
        componentNameClass: nameChanged ? ' cell-edited' : '',
        componentDescClass: descChanged ? ' cell-edited' : '',
        PartGroupClass: pgChanged ? ' cell-edited' : '',
        PartTypeClass: ptChanged ? ' cell-edited' : '',
        JMClass: jmChanged ? ' cell-edited' : '',
        nameToggle: nameToggle,
        descriptionToggle: descriptionToggle,
        PartGroupToggle: PartGroupToggle,
        PartTypeToggle: PartTypeToggle,
        JMToggle: JMToggle,
        changes_name_from: sourceName,
        changes_description_from: sourceDesc,
        changes_PartGroup_from: sourcePg,
        changes_PartType_from: sourcePt,
        changes_JM_from: sourceJm,
        0: numericRowId,
        1: componentName,
        2: componentDescription,
        3: partGroup,
        4: partType === "Brak" ? "" : partType,
        5: partUnit
    };
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
    const partUnitText = $partUnit.clone().children().remove().end().text().trim();
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
    const partTypeText = $partType.clone().children().remove().end().text().trim();
    const partTypeDisplay = partTypeText === '' ? 'Brak' : partTypeText;
    $partType.empty();
    const $partTypeSelect = $("#part__type_hidden")
                                .clone()
                                .prop('hidden', false)
                                .addClass('selectpicker')
                                .addClass('PartTypeSelect')
                                .removeAttr('id')
                                .prepend('<option value="0">Brak</option>');
    $partTypeSelect.find('option').filter(function() {
        return $(this).text().trim() === partTypeDisplay;
    }).prop('selected', true);
    $partType.append($partTypeSelect);
    $('.PartTypeSelect').selectpicker('refresh');
}


function generatePartGroupSelect($row) {
    const $partGroup = $row.find('.PartGroup');
    const partGroupText = $partGroup.clone().children().remove().end().text().trim();
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

function deleteRow(rowId, rowType, transformedNewParts, transformedEditedParts) {
    if (rowType === 'new') {
        delete transformedNewParts[rowId];
    } else {
        delete transformedEditedParts[rowId];
    }
    $("#confirmDeleteModal").modal('hide');
    let $row = $(`tr[data-id="${rowId}"]`);
    $row.remove();
}

function showConfirmDeleteModal($row, rowId, rowType) {
    $("#confirmDeleteModal").modal('show');
    $("#confirmDelete").attr('data-id', rowId).attr('data-type', rowType);
}

function renderTableRows($TBody, allTransformed) {
    for(const [key, item] of Object.entries(allTransformed))
    {
        let renderedItem = detectNewParts_template.map(render(item)).join('');
        let $renderedItem = $(renderedItem);
        if (item.type === 'new') {
            $renderedItem.addClass('new-part-row');
        } else if (item.type === 'edited') {
            $renderedItem.addClass('edited-part-row');
        }
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