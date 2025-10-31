const componentResultTableRow_template = $('script[data-template="resultComponentTableRow_template"]').text().split(/\$\{(.+?)\}/g);
const commissionResultTableRow_template = $('script[data-template="resultCommissionTableRow_template"]').text().split(/\$\{(.+?)\}/g);

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

function submitTransfer(transferFrom, transferTo, components, commissions, componentSources) {
    const data = {
        components: components,
        commissions: commissions,
        transferFrom: transferFrom,
        transferTo: transferTo,
        componentSources: componentSources
    };

    return new Promise((resolve, reject) => {
        $.ajax({
            type: "POST",
            url: COMPONENTS_PATH+"/transfer/transfer-components.php",
            data: data,
            dataType: 'json',
            success: function (response) {
                if (response.success) {
                    resolve(response.data);
                } else {
                    reject(new Error(response.message || 'Unknown error occurred'));
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', {xhr, status, error});
                reject(new Error('Failed to submit transfer: ' + error));
            }
        });
    });
}

function getTransferedQty() {
    let success = true;
    $(".transferQty").each(function(){
        const $this = $(this);
        const key = $this.data('key');
        const qty = $this.val();
        if(!qty || qty <= 0) {
            success = false;
            return false;
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
        setTimeout(() => {
            $(this).popover('hide');
        }, 3000);
        return;
    }

    if (hasUnsavedSourceChanges) {
        $("#unsavedSourceChangesModal").modal('show');
        return;
    }

    const isCommissionWithoutTransfer = transferFrom === transferTo;
    const hasCommissions = components.some(c => c && c.commissionKey !== null);

    if (hasCommissions && $('.global-summary-header').length > 0) {
        const isSummaryExpanded = !$('.collapse-global-summary').hasClass('show');
        if (isSummaryExpanded && !isCommissionWithoutTransfer) {
            $("#summaryCollapsedModal").modal('show');
            return;
        }
    }

    const anyExpanded = $('.commission-header:not(.collapsed)').length > 0;

    $('.commission-header').addClass('collapsed');
    $('.commission-component:not(.manual-component)').addClass('hidden');
    $('.add-component-row').addClass('hidden');
    $('.commission-toggle-icon').removeClass('bi-chevron-down').addClass('bi-chevron-right');
    $('.commission-summary').show();
    $('.commission-details').hide();

    if (anyExpanded && !explanationShown && !isCommissionWithoutTransfer) {
        explanationShown = true;
        $("#submitExplanationModal").modal('show');
        $("#submitExplanationModal").on('hidden.bs.modal', function() {
            $(this).off('hidden.bs.modal');
            proceedWithSubmit();
        });
        return;
    }

    proceedWithSubmit();

    function proceedWithSubmit() {
        $("#transferTableContainer, #commissionTableContainer").hide();
        $(".transferSubmitSpinner").show();

        submitTransfer(transferFrom, transferTo, components, commissions, transferSources)
            .then(result => {
                const [commissionResult, componentResult] = result;
                $(".transferSubmitSpinner").hide();

                if (commissionResult && commissionResult.length > 0) {
                    $("#commissionResultTableContainer").show();

                    commissionResult.forEach(commission => {
                        const displayCommission = {
                            ...commission,
                            quantityDisplay: `<b>${commission.quantity}</b>`,
                            statusText: "Nowe",
                            statusBadgeClass: "badge-success"
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
            })
            .catch(error => {
                $(".transferSubmitSpinner").hide();
                console.error('Transfer submission error:', error);

                const errorHtml = `
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <strong>Błąd!</strong> ${error.message || 'Wystąpił błąd podczas przesyłania transferu.'}
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                    </div>
                `;

                $("#transferTableContainer").before(errorHtml);
                $("#transferTableContainer, #commissionTableContainer").show();
            });
    }
});