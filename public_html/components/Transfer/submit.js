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

let explanationShown = false;

$("#submitTransfer").click(function() {
    const transferFrom = $("#transferFrom").val();
    const transferTo = $("#transferTo").val();

    if(!getTransferedQty()) {
        $(this).popover('show');
        return;
    }

    // Check if summary is collapsed - if so, don't allow submit
    const isSummaryExpanded = $('.global-summary-header').hasClass('expanded');
    if (!isSummaryExpanded) {
        $("#summaryCollapsedModal").modal('show');
        return; // Stop execution, don't proceed with transfer
    }

    // Check if any commissions are expanded (not collapsed)
    const anyExpanded = $('.commission-header:not(.collapsed)').length > 0;

    // Collapse all commissions before submit
    $('.commission-header').addClass('collapsed');
    $('.commission-component:not(.manual-component)').addClass('hidden');
    $('.add-component-row').addClass('hidden');
    $('.commission-toggle-icon').removeClass('bi-chevron-down').addClass('bi-chevron-right');
    $('.commission-summary').show();
    $('.commission-details').hide();

    // Show explanation modal if commissions were expanded and explanation hasn't been shown yet
    if (anyExpanded && !explanationShown) {
        explanationShown = true;
        $("#submitExplanationModal").modal('show');

        // Continue with submit after modal is closed
        $("#submitExplanationModal").on('hidden.bs.modal', function() {
            // Remove the event handler to prevent multiple bindings
            $(this).off('hidden.bs.modal');
            proceedWithSubmit();
        });

        return;
    }

    // If no modal needed, proceed directly
    proceedWithSubmit();

    function proceedWithSubmit() {
        $("#transferTableContainer, #commissionTableContainer").hide();
        $(".transferSubmitSpinner").show();

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
    }
});