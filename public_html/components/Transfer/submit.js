const componentResultTableRow_template = $('script[data-template="resultComponentTableRow_template"]').text().split(/\$\{(.+?)\}/g);
const commissionResultTableRow_template = $('script[data-template="resultCommissionTableRow_template"]').text().split(/\$\{(.+?)\}/g);

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

function submitTransfer(transferFrom, transferTo, components, commissions, existingCommissions) {
    const data = {
        components: components,
        commissions: commissions,
        existingCommissions: existingCommissions,
        transferFrom: transferFrom,
        transferTo: transferTo
    };
    const result = [];
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/transfer/transfer-components.php",
        async: false,
        data: data,
        success: function (data) {
            ajaxResult = JSON.parse(data);
            const commissionResult = ajaxResult[0];
            const componentResult = ajaxResult[1];
            result.push(commissionResult, componentResult);
        }
    });
    return result;
}

function getTransferedQty() {
    let success = true;
    $(".transferQty").each(function(){
        const $this = $(this);
        const key = $this.data('key');
        const qty = $this.val();
        if(!qty) { 
            success = false;
            return;
        }
        components[key].transferQty = qty;
    });
    return success;
}



$("#submitTransfer").click(function() {
    const transferFrom = $("#transferFrom").val();
    const transferTo = $("#transferTo").val();
    // components[] and commissions[] are arrays of objects
    // defined in public_html/components/Transfer/transfer-view.js
    if(!getTransferedQty()) { 
        $(this).popover('show');
        return;
    }
    $("#transferTableContainer, #commissionTableContainer").hide();
    $(".transferSubmitSpinner").show();
    //Timeout of 0ms, to allow the DOM to update before getting the components via AJAX
    setTimeout(() => {
        const result = submitTransfer(transferFrom, transferTo, components, commissions, existingCommissions);
        const [commissionResult, componentResult] = result;
        $(".transferSubmitSpinner").hide();
        commissionResult.forEach(commission => {
            const row = commissionResultTableRow_template.map(render(commission)).join('');
            $("#commissionResultTBody").append(row);
        });
        componentResult.forEach(commission => {
            const row = componentResultTableRow_template.map(render(commission)).join('');
            $("#componentResultTBody").append(row);
        });
        $('.alert-existing-commission').remove();
        $("#transferResult").show();
    }, 0);

});