const transferComponentsTableRow_template = $('script[data-template="transferComponentsTableRow_template"]').text().split(/\$\{(.+?)\}/g);

const components = [];

$(document).ready(function() {
    const currentMagazine = $("#transferFrom").attr('data-default-value');
    $("#transferFrom").val(currentMagazine);
    $('[data-toggle="popover"]').popover();
    $(".selectpicker").selectpicker('refresh');
});

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
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
    const $tr = $(transferComponentsTableRow_template.map(render(componentValues)).join(''));
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
    $qty.val(result);
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