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
    $TBody.append($tr);
}

function addOrSubtractComponentsReserved(checked, tableCellClass){
    $(tableCellClass).each(function() {
        const reserved = $(this).data('reserved');
        let value = parseInt($(this).text(), 10);
        if (!checked) {
            value += reserved;
            $(this).text(value);
            return true;
        }
        value -= reserved;
        $(this).text(value);
    });
}


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

$('body').on('click', '.removeTransferRow', function() {
    const key = $(this).data('key');
    delete components[key];
    $(this).closest('tr').remove();
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

$("#createCommission, #dontCreateCommission").click(function() {
    $("#transferFrom, #transferTo").prop('disabled', true).selectpicker('refresh');
    $("#createCommissionCard").hide();
});

$("#createCommission").click(function() {
    $("#moreOptionsCard, #commissionTableContainer").show();
});