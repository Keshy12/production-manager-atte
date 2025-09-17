const transferComponentsTableRow_template = $('script[data-template="transferComponentsTableRow_template"]').text().split(/\$\{(.+?)\}/g);

const components = [];

$(document).ready(function() {
    const currentMagazine = $("#transferFrom").attr('data-default-value');
    $("#transferFrom").val(currentMagazine);
    $('[data-toggle="popover"]').popover();
    $(".selectpicker").selectpicker('refresh');

    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();

    // Handle help button click
    $(document).on('click', '#showHelpModal', function() {
        $("#submitExplanationModal").modal('show');
    });

    // Handle quantity changes to update summary
    $(document).on('input change', '.transferQty', function() {
        const $input = $(this);
        const key = $input.data('key');
        const newQty = parseInt($input.val()) || 0;

        // Update the component in the components array
        if (components[key]) {
            components[key].transferQty = newQty;
        }

        // Update the global summary
        updateGlobalSummary();
    });
});

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

function updateGlobalSummary() {
    // Check if the global summary header exists, if not, don't update
    if ($('.global-summary-header').length === 0) {
        return;
    }

    // Check if summary is currently expanded
    const isExpanded = $('.global-summary-header').hasClass('expanded');

    // Remove existing summary rows
    $('.global-summary-component:not(.add-global-component-form)').remove();

    // Create new summary based on all current components
    const allComponents = components.filter(c => c); // Filter out deleted components
    const globalSummary = createGlobalSummary(allComponents);

    // Update component count
    $('#totalComponentsCount').text(`${globalSummary.length} komponentów`);

    // Add summary rows
    globalSummary.forEach(summaryComponent => {
        const summaryRowTemplate = $('script[data-template="globalSummaryComponentRow_template"]').text().split(/\$\{(.+?)\}/g);
        const summaryComponentRow = summaryRowTemplate.map(render(summaryComponent)).join('');
        const $summaryRow = $(summaryComponentRow);

        // Insert before the add form if it exists, otherwise at the end
        if (window.$currentAddGlobalForm) {
            window.$currentAddGlobalForm.before($summaryRow);
        } else {
            $('#transferTBody').append($summaryRow);
        }

        // Preserve the visibility state
        if (isExpanded) {
            $summaryRow.show();
        } else {
            $summaryRow.hide();
        }
    });
}

function createGlobalSummary(allComponentValues) {
    // Group components by type and componentId, but EXCLUDE manually added components (those with commissionKey = null)
    const componentSummary = {};

    allComponentValues.forEach(component => {
        // Skip manually added components (they have commissionKey = null and blue line)
        if (component.commissionKey === null) {
            return;
        }

        const key = `${component.type}-${component.componentId}`;
        if (!componentSummary[key]) {
            componentSummary[key] = {
                type: component.type,
                componentId: component.componentId,
                componentName: component.componentName,
                componentDescription: component.componentDescription,
                warehouseFromQty: component.warehouseFromQty,
                warehouseFromReserved: component.warehouseFromReserved,
                warehouseToQty: component.warehouseToQty,
                warehouseToReserved: component.warehouseToReserved,
                totalNeeded: 0,
                totalTransferQty: 0
            };
        }

        // Sum up the quantities
        const needed = parseInt(component.neededForCommissionQty.replace(/<[^>]*>/g, '')) || 0;
        componentSummary[key].totalNeeded += needed;
        componentSummary[key].totalTransferQty += parseInt(component.transferQty) || 0;
    });

    return Object.values(componentSummary);
}

function getComponentValues(components, transferFrom, transferTo) {
    const data = { components: components, transferFrom: transferFrom, transferTo: transferTo };
    let result = [];
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/transfer/get-components-values.php",
        async: false,
        data: data,
        success: function (data) {
            result = JSON.parse(data);
        }
    });
    return result;
}

function addComponentsRow(componentValues, $TBody) {
    const template = transferComponentsTableRow_template.map(render(componentValues)).join('');
    const $tr = $(template);

    if(componentValues['neededForCommissionQty'] == '<span class="text-light">n/d</span>') {
        $tr.find('.insertDifference').remove();
    }
    $TBody.append($tr);
}

function addOrSubtractComponentsReserved(checked, tableCellClass){
    $(tableCellClass).each(function() {
        const reserved = $(this).data('reserved');
        let value = parseFloat($(this).text(), 10);
        if (!checked) {
            value += reserved;
            $(this).text(value);
            return true;
        }
        value -= reserved;
        $(this).text(value);
    });
}

function validateAddComponentForm(magazineComponent, listComponents, transferQty) {
    if (magazineComponent == ''
        || listComponents == ''
        || transferQty == '') {
        return false;
    }
    return true;
}

function clearAddComponentForm(){
    $("#magazineComponent, #list__components, #qtyComponent").val('');
    $("#magazineComponent, #list__components").selectpicker('refresh');
}

$("#addTransferComponent").click(function() {
    const componentType = $("#magazineComponent").val();
    const listComponentsVal = $("#list__components").val();
    const transferQty = $("#qtyComponent").val();
    if(!validateAddComponentForm(componentType, listComponentsVal, transferQty)) {
        $("#addTransferComponent").popover('show');
        return;
    }
    clearAddComponentForm();
    const component = {
        type: componentType,
        componentId: listComponentsVal,
        neededForCommissionQty: '<span class="text-light">n/d</span>',
        transferQty: transferQty
    }
    const componentValues = getComponentValues([component], $("#transferFrom").val(), $("#transferTo").val());
    const pushedKey = components.push(componentValues[0]) - 1;
    componentValues[0]['key'] = pushedKey;
    addComponentsRow(componentValues[0], $("#transferTBody"));
});

$("#magazineComponent").change(function() {
    let option = $(this).val();
    $("#list__components").empty();
    $('#list__' + option + '_hidden option').clone().appendTo('#list__components');
    $("#list__components").prop("disabled", false);
    $("#list__components").selectpicker('refresh');
});

$("#insertDifferenceAll").click(function() {
    $(".insertDifference").each(function() {
        $(this).click();
    });
})

$('body').on('click', '.insertDifference', function() {
    const $qty = $(this).closest("tr").find(".transferQty");
    const key = $qty.data('key');
    const $magazineTo = $(this).closest("tr").find(".warehouseTo");
    //availableTo is read through the DOM, because its value varies if #subtractPartsMagazineTo is checked
    const availableTo = parseInt($magazineTo.text());
    const neededForCommission = parseInt(components[key]['neededForCommissionQty']);
    const qty = neededForCommission - availableTo;
    if (isNaN(neededForCommission)) return;
    const result = qty > 0 ? qty : 0;
    $qty.val(result).change();
});

$("#subtractPartsMagazineFrom").change(function() {
    const checked = $(this).is(":checked");
    addOrSubtractComponentsReserved(checked, ".warehouseFrom");
});

$("#subtractPartsMagazineTo").change(function() {
    const checked = $(this).is(":checked");
    addOrSubtractComponentsReserved(checked, ".warehouseTo");
});

$("#deleteFromTransfer").click(function() {
    const key = $(this).data('key');
    delete components[key];
    $('.removeTransferRow[data-key="' + key + '"]').closest('tr').remove();
    updateGlobalSummary();
});

$('body').on('click', '.removeTransferRow', function() {
    $("#deleteComponentRowModal").modal('show');
    $("#deleteFromTransfer").data('key', $(this).data('key'));
});

$("#transferFrom, #transferTo").change(function() {
    if($("#transferFrom").val() == '' || $("#transferTo").val() == '') return;

    $("#createCommission, #dontCreateCommission")
        .prop('disabled', false)
        .css('pointer-events','')
        .parent().popover('dispose');
});

$("#transferTo").change(function() {
    const warehouseId = this.value;
    disableUserSelectOptions(warehouseId);
    $("#userSelect").selectpicker('refresh');
});

// Function that disables users from select, if they are not in the same warehouse as the target warehouse
function disableUserSelectOptions(warehouseId)
{
    $("#userSelect option").each(function() {
        $(this).prop("disabled", $(this).attr('data-submag') !== warehouseId);
    });
    $("#transferTo").selectpicker('refresh');
}