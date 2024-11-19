$(document).ready(function() {
    const currentMagazine = $("#transferFrom").attr('data-default-value');
    $("#transferFrom").val(currentMagazine);
    $('[data-toggle="popover"]').popover();
    $(".selectpicker").selectpicker('refresh');
});

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

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
