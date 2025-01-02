const componentResultTableRow_template = $('script[data-template="componentResultTableRow_template"]').text().split(/\$\{(.+?)\}/g);

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

function submitTransfer(transferFrom, transferTo, components, commissions) {
    const data = {
        components: components,
        commissions: commissions,
        transferFrom: transferFrom,
        transferTo: transferTo
    };
    let result = [];
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/transfer/transfer-components.php",
        async: false,
        data: data,
        success: function (data) {
            result = JSON.parse(data);
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
    // const transferResult = submitTransfer(transferFrom, transferTo, components, commissions);
    if(!getTransferedQty()) { 
        $(this).popover('show');
        return ;
    };
    $("#transferTableContainer, #commissionTableContainer").hide();
    $(".transferSubmitSpinner").show();
    console.log(commissions);
    console.log(components);
    return;
    //Timeout of 0ms, to allow the DOM to update before getting the components via AJAX
    setTimeout(() => {
        const result = submitTransfer(transferFrom, transferTo, components, commissions);
        $(".transferSubmitSpinner").hide();
        $("#transferResult").show();
    }, 0);

});