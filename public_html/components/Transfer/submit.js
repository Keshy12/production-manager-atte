const componentResultTableRow_template = $('script[data-template="resultComponentTableRow_template"]').text().split(/\$\{(.+?)\}/g);
const commissionResultTableRow_template = $('script[data-template="resultCommissionTableRow_template"]').text().split(/\$\{(.+?)\}/g);

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

function submitTransfer(transferFrom, transferTo, components, commissions, existingCommissions, componentSources) {
    const data = {
        components: components,
        commissions: commissions,
        existingCommissions: existingCommissions,
        transferFrom: transferFrom,
        transferTo: transferTo,
        componentSources: componentSources
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
        syncComponentQuantityFromSources(key);
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

    if (hasUnsavedSourceChanges) {
        $("#unsavedSourceChangesModal").modal('show');
        return;
    }

    // Check if this is a commission without transfer (same warehouse)
    const isCommissionWithoutTransfer = transferFrom === transferTo;

    // Check if we're in no-commission mode
    const hasCommissions = components.some(c => c && c.commissionKey !== null);
    // Check if summary is collapsed - only for commission mode
    if (hasCommissions && $('.global-summary-header').length > 0) {
        const isSummaryExpanded = $('.global-summary-header').hasClass('expanded');
        if (!isSummaryExpanded && !isCommissionWithoutTransfer) {
            $("#summaryCollapsedModal").modal('show');
            return;
        }
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
    if (anyExpanded && !explanationShown && !isCommissionWithoutTransfer) {
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
            const result = submitTransfer(transferFrom, transferTo, components, commissions, existingCommissions, transferSources);
            const [commissionResult, componentResult] = result;
            $(".transferSubmitSpinner").hide();

            if (commissionResult.length > 0) {
                $("#commissionResultTableContainer").show();

                commissionResult.forEach(commission => {
                    // Prepare display data based on whether commission was expanded
                    let quantityDisplay, statusText, statusBadgeClass;

                    if (commission.isExpanded) {
                        quantityDisplay = `<small class="text-muted">${commission.initialQuantity} + </small><b>${commission.addedQuantity}</b><small class="text-muted"> = ${commission.quantity}</small>`;
                        statusText = "Rozszerzone";
                        statusBadgeClass = "badge-warning";
                    } else {
                        quantityDisplay = `<b>${commission.quantity}</b>`;
                        statusText = "Nowe";
                        statusBadgeClass = "badge-success";
                    }

                    // Add display properties to commission object
                    const displayCommission = {
                        ...commission,
                        quantityDisplay: quantityDisplay,
                        statusText: statusText,
                        statusBadgeClass: statusBadgeClass
                    };

                    const row = commissionResultTableRow_template.map(render(displayCommission)).join('');
                    $("#commissionResultTBody").append(row);
                });
            } else {
                $("#commissionResultTableContainer").hide();
            }

            componentResult.forEach(component => {
                let sourcesDisplay = '';

                if (component.showSources) {
                    if (component.sources.length > 1) {
                        sourcesDisplay = '<small class="text-muted">Źródła:</small><br>';
                        component.sources.forEach(source => {
                            sourcesDisplay += `<span class="badge badge-light mr-1">${source.warehouseName}: ${source.quantity}</span><br>`;
                        });
                    } else {
                        sourcesDisplay = `<span class="badge badge-info">${component.sources[0].warehouseName}</span>`;
                    }
                } else {
                    sourcesDisplay = '<span class="text-muted">-</span>';
                }

                const displayComponent = {
                    ...component,
                    sourcesDisplay: sourcesDisplay
                };

                const row = componentResultTableRow_template.map(render(displayComponent)).join('');
                $("#componentResultTBody").append(row);
            });

            $('.alert-existing-commission').remove();
            $("#transferResult").show();
        }, 0);
    }
});