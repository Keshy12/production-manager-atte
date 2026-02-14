$(document).ready(function() {
    let cancellationData = null;
    let selectedCommissions = new Set();
    let selectedTransfers = new Set();

    const PRIORITY_CONFIG = {
        'critical': { label: 'Krytyczny', class: 'badge-danger', icon: 'bi-exclamation-triangle-fill', color: 'red' },
        'urgent': { label: 'Pilny', class: 'badge-warning', icon: 'bi-lightning-fill', color: 'yellow' },
        'standard': { label: 'Standardowy', class: 'badge-success', icon: 'bi-check-circle-fill', color: 'green' },
        'none': { label: 'Brak', class: 'badge-secondary', icon: 'bi-dash-circle', color: 'transparent' }
    };

    $(document).on('click', '.cancelCommission', function(e) {
        e.preventDefault();
        const commissionId = $(this).data('id');
        const state = $(this).data('state');
        const potentialCount = parseInt($(this).data('potential-count')) || 0;
        const $card = $(this).closest('.card');
        const groupedIdsAttr = $card.attr('data-grouped-ids');
        const isGroupedMode = $("#groupTogether").prop('checked');
        const isActuallyGrouped = groupedIdsAttr && groupedIdsAttr.split(',').length > 1;

        // Condition for showing the prompt:
        // 1. We are NOT in grouped mode (if we were, the whole group is already selected)
        // 2. The commission is not actually a part of a displayed group (isActuallyGrouped)
        // 3. AND either the state is completed/returned OR there are other commissions to group with
        const showPrompt = !isGroupedMode && !isActuallyGrouped && (state === 'completed' || state === 'returned' || potentialCount > 1);

        if (isGroupedMode || isActuallyGrouped) {
            loadCancellationData(commissionId, true, groupedIdsAttr, 'both');
        } else if (showPrompt) {
            showCancellationScopeDialog(commissionId, groupedIdsAttr);
        } else {
            // Only one commission, no need to ask
            loadCancellationData(commissionId, false, groupedIdsAttr, 'both');
        }
    });

    $(document).on('click', '.viewDetails', function(e) {
        e.preventDefault();
        const commissionId = $(this).data('id');
        const $card = $(this).closest('.card');
        const groupedIdsAttr = $card.attr('data-grouped-ids');
        const isGroupedMode = $("#groupTogether").prop('checked');

        loadDetailsData(commissionId, isGroupedMode, groupedIdsAttr);
    });

    function showCancellationScopeDialog(commissionId, groupedIds) {
        const dialogHtml = `
            <div class="modal fade" id="cancellationScopeModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-warning">
                            <h5 class="modal-title">
                                <i class="bi bi-question-circle"></i> Wybierz zakres anulacji
                            </h5>
                            <button type="button" class="close" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-3">Czy chcesz anulować tylko to zlecenie, czy całą grupę zleceń?</p>
                            <div class="list-group">
                                <a href="#" class="list-group-item list-group-item-action" data-scope="single">
                                    <h6 class="mb-1">
                                        <i class="bi bi-file-text"></i> Tylko to zlecenie (#${commissionId})
                                    </h6>
                                    <small class="text-muted">
                                        Anulowanie pojedynczego wybranego zlecenia
                                    </small>
                                </a>
                                <a href="#" class="list-group-item list-group-item-action list-group-item-danger" data-scope="group">
                                    <h6 class="mb-1">
                                        <i class="bi bi-collection"></i> Cała grupa zleceń
                                    </h6>
                                    <small class="text-muted">
                                        Pokaż wszystkie aktywne zlecenia o tych samych parametrach
                                    </small>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        $('body').append(dialogHtml);
        const $modal = $('#cancellationScopeModal');

        $modal.find('.list-group-item').on('click', function(e) {
            e.preventDefault();
            const scope = $(this).data('scope');
            const isGrouped = (scope === 'group');
            $modal.modal('hide');
            setTimeout(() => {
                $modal.remove();
                loadCancellationData(commissionId, isGrouped, groupedIds, 'both');
            }, 300);
        });

        $modal.on('hidden.bs.modal', function() {
            $(this).remove();
        });

        $modal.modal('show');
    }

    function loadCancellationData(commissionId, isGrouped, groupedIds, cancellationType) {
        $('#cancelModalOverlay').show().addClass("d-flex");
        switchStep(1);
        $('#cancelCommissionModal').modal({
            backdrop: 'static',
            keyboard: false,
            show: true
        });

        // Get current filters from the page
        const currentFilters = {
            transferFrom: $("#transferFrom").val(),
            transferTo: $("#transferTo").val(),
            device: [
                $("#type").val(),
                $("#list__device").val(),
                $("#laminate").val(),
                $("#version").val()
            ],
            receivers: $("#user").val(),
            state_id: $("#state").val(),
            priority_id: $("#priority").val(),
            showCancelled: $("#showCancelled").prop('checked'),
            groupTogether: $("#groupTogether").prop('checked')
        };

        $.ajax({
            type: "POST",
            url: COMPONENTS_PATH + '/commissions/get-commission-data.php',
            data: {
                action: 'get_cancellation_data',
                commissionId: commissionId,
                isGrouped: isGrouped ? 'true' : 'false',
                groupedIds: groupedIds || '',
                filters: JSON.stringify(currentFilters)
            },
            success: function(response) {
                console.log('Cancellation data received:', response);

                if (response.success) {
                    cancellationData = response;
                    cancellationData.cancellationType = 'both'; // Force 'both' as default
                    cancellationData.groupedIds = groupedIds;
                    cancellationData.isGrouped = isGrouped;
                    renderCancellationModal(response, isGrouped, commissionId);
                    applyAutoSelection('both', isGrouped, commissionId);
                    $('#cancelModalOverlay').hide().removeClass("d-flex");
                } else {
                    showErrorMessage('Błąd: ' + response.message);
                    $('#cancelCommissionModal').modal('hide');
                    $('#cancelModalOverlay').hide().removeClass("d-flex");
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                console.log('Response text:', xhr.responseText);
                showErrorMessage('Błąd podczas ładowania danych anulacji');
                $('#cancelCommissionModal').modal('hide');
                $('#cancelModalOverlay').hide().removeClass("d-flex");
            }
        });
    }

    function renderCancellationModal(data, isGrouped, clickedCommissionId) {
        const $groupsList = $('#groupsList');
        $groupsList.empty();
        selectedCommissions.clear();
        selectedTransfers.clear();

        const isGroupedFilterChecked = $("#groupTogether").prop('checked');

        const sortedCommissions = Object.keys(data.commissionsData).sort((a, b) => {
            return new Date(data.commissionsData[a].createdAt) - new Date(data.commissionsData[b].createdAt);
        });

        sortedCommissions.forEach(commissionId => {
            const commission = data.commissionsData[commissionId];
            const transfers = data.transfersByCommission[commissionId] || [];
            const unreturned = commission.qtyUnreturned;
            const hasUnreturned = unreturned > 0;
            // Use the actual commission's cancellation status
            const isCancelled = commission.isCancelled;

            // Determine expansion state
            let isExpanded = false;
            if (!isGrouped) {
                // Case 1: Single scope
                isExpanded = true;
            } else if (isGroupedFilterChecked) {
                // Case 2: Grouped mode (filter checked) - all collapsed
                isExpanded = false;
            } else if (commissionId == clickedCommissionId) {
                // Case 3: Manual group (filter unchecked) - expand clicked one
                isExpanded = true;
            }

            // Check if commission has any cancelled transfers
            const hasAnyCancelledTransfers = transfers.some(t => t.isCancelled);
            const isPartiallyCancelled = !isCancelled && hasAnyCancelledTransfers;

            // Count cancelled transfers
            const cancelledTransfersCount = transfers.filter(t => t.isCancelled).length;

            // Determine color coding
            let headerClass, borderColor, cardOpacity;
            const priorityColor = (PRIORITY_CONFIG[commission.priority] || PRIORITY_CONFIG['none']).color;

            if (isCancelled) {
                // Commission itself is cancelled - RED
                headerClass = 'alert-danger';
                borderColor = '#dc3545'; // red
                cardOpacity = '0.7';
            } else if (isPartiallyCancelled) {
                // Only transfers cancelled - YELLOW
                headerClass = 'alert-warning';
                borderColor = '#ffc107'; // yellow
                cardOpacity = '1';
            } else {
                // Normal - LIGHT
                headerClass = 'bg-light';
                borderColor = '';
                cardOpacity = '1';
            }

            const cardHtml = `
            <div class="card mb-3 commission-card ${isCancelled ? 'commission-cancelled-in-modal' : ''}"
                 data-commission-id="${commissionId}"
                 style="opacity: ${cardOpacity}; ${borderColor ? `border-color: ${borderColor} !important;` : ''} box-shadow: -5px 0px 0px 0px ${priorityColor};">
                <div class="card-header ${headerClass}">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox"
                                   class="custom-control-input commission-checkbox"
                                   id="comm-${commissionId}"
                                   data-commission-id="${commissionId}"
                                   data-has-unreturned="${hasUnreturned}"
                                   data-unreturned-qty="${unreturned}"
                                   ${isCancelled ? 'disabled' : ''}>
                            <label class="custom-control-label font-weight-bold" for="comm-${commissionId}">
                                 <i class="bi bi-file-earmark-text"></i>
                                 Zlecenie #${commissionId}: ${commission.deviceName}
                                 ${isCancelled ? '<span class="badge badge-light ml-2"><i class="bi bi-x-circle"></i> ANULOWANE</span>' : ''}
                                <span class="badge badge-secondary ml-2 selected-transfers-badge" data-commission-id="${commissionId}" style="display: none;">
                                    0 transferów
                                </span>
                                <span class="badge badge-danger ml-2 partial-transfers-badge" data-commission-id="${commissionId}" style="display: none;">
                                    <i class="bi bi-exclamation-triangle"></i> Nie wszystkie transfery
                                </span>
                            </label>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary ${isExpanded ? '' : 'collapsed'}"
                                type="button"
                                data-toggle="collapse"
                                data-target="#transfers-${commissionId}"
                                aria-expanded="${isExpanded}">
                            <i class="bi bi-chevron-down"></i>
                        </button>
                    </div>
                    <small class="ml-4">
                        Zlecono: ${commission.qty} | Wyprodukowano: ${commission.qtyProduced}${hasUnreturned ? ` | <span class="${isCancelled ? 'text-white' : 'text-warning'}">Niewrócono: ${unreturned}</span>` : ''}
                    </small>
                    <small class="ml-4 text-muted d-block mt-1">
                        <i class="bi bi-calendar3"></i> ${commission.createdAt || 'Brak daty'} |
                        <i class="bi bi-info-circle"></i> Status: ${commission.state || 'Nieznany'}${cancelledTransfersCount > 0 ? ` | <i class="bi bi-x-circle"></i> Anulowane: ${cancelledTransfersCount} ${cancelledTransfersCount === 1 ? 'transfer' : 'transfery'}` : ''}
                    </small>
                </div>
                <div id="transfers-${commissionId}" class="collapse ${isExpanded ? 'show' : ''}">
                    <div class="card-body bg-light">
                        ${transfers.length > 0 ? renderTransfersList(transfers, commissionId, isCancelled) : '<p class="mb-0 text-muted">Brak dostępnych transferów do anulacji</p>'}
                    </div>
                </div>
            </div>
        `;

            $groupsList.append(cardHtml);
        });

        attachEventHandlers();
        updateSummary();
    }

    function renderTransfersList(transfers, commissionId, commissionIsCancelled) {
        if (transfers.length === 0) {
            return '<p class="mb-0 text-muted">Brak dostępnych transferów</p>';
        }

        // Separate regular transfers from cancellation transfers
        const regularTransfers = transfers.filter(t => !t.isCancellationGroup);
        const cancellationTransfers = transfers.filter(t => t.isCancellationGroup);

        // Check if all regular transfers are cancelled
        const allRegularTransfersCancelled = regularTransfers.length > 0 &&
                                             regularTransfers.every(t => t.isCancelled);

        // If all regular transfers are cancelled AND we have cancellation transfers,
        // hide the regular transfers (they're redundant with the cancellation section)
        const shouldHideRegularTransfers = allRegularTransfersCancelled && cancellationTransfers.length > 0;
        const transfersToDisplay = shouldHideRegularTransfers ? [] : regularTransfers;

        let html = '<div class="transfers-list">';

        // Add select/unselect all button (only if showing regular transfers)
        if (transfersToDisplay.length > 1 && !commissionIsCancelled) {
            html += `
                <div class="mb-2 d-flex justify-content-end">
                    <button class="btn btn-sm btn-outline-primary select-all-transfers-btn"
                            data-commission-id="${commissionId}"
                            type="button">
                        <i class="bi bi-check-square"></i> Zaznacz wszystkie transfery
                    </button>
                </div>
            `;
        }

        // Render the transfers we want to display
        html += transfersToDisplay.map((transfer, index) => renderSingleTransfer(transfer, commissionId, commissionIsCancelled)).join('');

        // Render cancellation transfers in a collapsible section
        if (cancellationTransfers.length > 0) {
            const collapseId = `cancellation-transfers-${commissionId}`;
            html += `
                <div class="mt-3 border-left border-secondary pl-3" style="border-left-width: 2px !important;">
                    <div style="cursor: pointer;" data-toggle="collapse" data-target="#${collapseId}">
                        <small class="text-muted">
                            <i class="bi bi-arrow-counterclockwise"></i>
                            <span class="text-decoration-underline">Zwroty z anulacji (${cancellationTransfers.length})</span>
                            <i class="bi bi-chevron-down" style="font-size: 0.8em;"></i>
                        </small>
                    </div>
                    <div id="${collapseId}" class="collapse mt-2">
                        ${renderCancellationTransfersSummary(cancellationTransfers)}
                    </div>
                </div>
            `;
        }

        html += '</div>';
        return html;
    }

    function renderCancellationTransfersSummary(cancellationTransfers) {
        let html = '<div class="small">';

        cancellationTransfers.forEach(transfer => {
            const componentName = transfer.componentName;
            const notes = transfer.transferGroupNotes || '';

            html += `
                <div class="mb-2 pb-2 border-bottom">
                    <div class="text-muted">
                        <strong>${componentName}</strong>
                        ${notes ? `<span class="ml-2" style="font-size: 0.85em; font-style: italic;" title="${notes}">
                            <i class="bi bi-info-circle-fill"></i>
                        </span>` : ''}
                    </div>
            `;

            // Show where the quantities were returned to
            if (transfer.sources && transfer.sources.length > 0) {
                html += '<div class="ml-3 mt-1">';
                transfer.sources.forEach(source => {
                    if (source.quantity > 0) {
                        html += `
                            <div style="font-size: 0.9em;">
                                <i class="bi bi-arrow-return-left text-secondary"></i>
                                <strong>${source.quantity}</strong> zwrócono do <strong>${source.warehouseName}</strong>
                            </div>
                        `;
                    }
                });
                html += '</div>';
            }

            html += '</div>';
        });

        html += '</div>';
        return html;
    }

    function renderSingleTransfer(transfer, commissionId, commissionIsCancelled) {
        const isDisabled = transfer.qtyAvailable <= 0 || transfer.isCancelled || commissionIsCancelled;
        const displayQty = Math.abs(transfer.qtyAvailable);
        const qtyClass = transfer.qtyAvailable < 0 ? 'text-danger' : 'text-success';
        const isCancelled = transfer.isCancelled;

        let sourcesHtml = '';
        if (transfer.sources && transfer.sources.length > 1) {
            sourcesHtml = `
                <div class="sources-distribution mt-2" data-transfer-id="${transfer.transferId}">
                    <small class="text-muted font-weight-bold d-block mb-2">
                        <i class="bi bi-diagram-3"></i> Rozkład zwrotu:
                    </small>
                    <div class="d-flex flex-wrap gap-2">
                    ${transfer.sources.map((source, srcIndex) => `
                        <div class="d-inline-flex align-items-center mr-3 mb-2 ${source.isMainWarehouse ? 'main-source-container' : 'external-source-container'}">
                            <small class="mr-2" style="min-width: 180px;">
                                ${source.warehouseName}<br>
                                <span class="text-muted" style="font-size: 0.8em;">przetransf: ${source.originalQty}</span>
                            </small>
                            <div class="input-group" style="width: 140px;">
                                ${!source.isMainWarehouse ? `
                                <div class="input-group-prepend">
                                    <button class="btn btn-outline-secondary btn-sm source-qty-minus"
                                            type="button"
                                            data-transfer-id="${transfer.transferId}"
                                            data-source-index="${srcIndex}"
                                            ${isDisabled ? 'disabled' : ''}>-</button>
                                </div>
                                ` : ''}
                                <input type="number"
                                       class="form-control form-control-sm text-center source-qty-input"
                                       value="${source.quantity}"
                                       min="0"
                                       data-transfer-id="${transfer.transferId}"
                                       data-source-index="${srcIndex}"
                                       ${source.isMainWarehouse ? 'readonly' : ''}
                                       ${isDisabled ? 'disabled' : ''}>
                                ${!source.isMainWarehouse ? `
                                <div class="input-group-append">
                                    <button class="btn btn-outline-secondary btn-sm source-qty-plus"
                                            type="button"
                                            data-transfer-id="${transfer.transferId}"
                                            data-source-index="${srcIndex}"
                                            ${isDisabled ? 'disabled' : ''}>+</button>
                                </div>
                                ` : ''}
                            </div>
                        </div>
                    `).join('')}
                    </div>
                    <div class="mt-2">
                        <small class="source-sum-indicator" data-transfer-id="${transfer.transferId}"></small>
                    </div>
                </div>
            `;
        }

        // Build collapsible transfer details section
        const collapseId = `transfer-details-${transfer.transferId}`;
        let transferDetailsHtml = '';
        if (transfer.transferDetails && transfer.transferDetails.length > 0) {
            transferDetailsHtml = `
                <div style="float: right;">
                    <div style="cursor: pointer;" data-toggle="collapse" data-target="#${collapseId}">
                        <small class="text-muted">
                            <i class="bi bi-info-circle"></i>
                            <span class="text-decoration-underline">Szczegóły transferu</span>
                            <i class="bi bi-chevron-down" style="font-size: 0.8em;"></i>
                        </small>
                    </div>
                </div>
                <div id="${collapseId}" class="collapse mt-2 ml-2">
                    <small>
                        ${renderTransferDetails(transfer)}
                    </small>
                </div>
            `;
        }

        // Add styling for cancelled transfers
        let containerStyle = '';
        if (isCancelled) {
            containerStyle = 'background-color: #f8d7da; padding: 8px; border-radius: 4px; border: 1px solid #dc3545;';
        }

        return `
            <div class="mb-3 ${isDisabled ? 'opacity-50' : ''}" style="${containerStyle}">
                <div class="custom-control custom-checkbox">
                    <input type="checkbox"
                           class="custom-control-input transfer-checkbox"
                           id="trans-${transfer.transferId}"
                           data-transfer-id="${transfer.transferId}"
                           data-commission-id="${commissionId}"
                           ${isDisabled ? 'disabled' : ''}>
                    <label class="custom-control-label" for="trans-${transfer.transferId}">
                        <strong>${transfer.componentName}</strong>
                        ${isCancelled ? ' <span class="badge badge-danger ml-2"><i class="bi bi-x-circle"></i> ANULOWANY</span>' : ''}
                    </label>
                </div>
                <small class="${isCancelled ? 'text-danger' : 'text-muted'} d-block ml-4">
                    Przetransferowano: ${transfer.qtyTransferred} |
                    Użyto: ${transfer.qtyUsed} |
                    Do zwrotu: <span class="${qtyClass}">${displayQty}</span>
                    ${!isCancelled && isDisabled ? ' <span class="badge badge-danger">Brak do zwrotu</span>' : ''}
                    ${transferDetailsHtml}
                </small>
                ${sourcesHtml}
            </div>
        `;
    }

    function renderTransferDetails(transfer) {
        if (!transfer.transferDetails || transfer.transferDetails.length === 0) {
            return '<span class="text-muted">Brak szczegółów transferu</span>';
        }

        let html = '<div class="border-left border-secondary pl-2" style="border-left-width: 2px !important;">';

        transfer.transferDetails.forEach(detail => {
            // Determine if this is a source (negative) or target (positive) entry
            const isSource = detail.originalQty < 0;
            const absQty = Math.abs(detail.originalQty);

            if (isSource) {
                html += `
                    <div class="mb-1">
                        <i class="bi bi-arrow-up-right text-danger"></i>
                        <span class="text-muted">Z magazynu:</span>
                        <strong>${detail.warehouseName}</strong>
                        <span class="text-danger">(${absQty})</span>
                    </div>
                `;
            } else {
                html += `
                    <div class="mb-1">
                        <i class="bi bi-arrow-down-right text-success"></i>
                        <span class="text-muted">Do magazynu:</span>
                        <strong>${detail.warehouseName}</strong>
                        <span class="text-success">(${absQty})</span>
                    </div>
                `;
            }
        });

        html += '</div>';
        return html;
    }

    function attachEventHandlers() {
        $('.commission-checkbox').off('change').on('change', function() {
            const commissionId = $(this).data('commission-id');
            const isChecked = $(this).prop('checked');

            if (isChecked) {
                selectedCommissions.add(commissionId);

                // Zaznacz transfery tylko wtedy, gdy tryb anulacji to 'both' lub 'transfers'
                if (cancellationData && (cancellationData.cancellationType === 'both' || cancellationData.cancellationType === 'transfers')) {
                    $(`.transfer-checkbox[data-commission-id="${commissionId}"]`).each(function() {
                        if (!$(this).prop('disabled')) {
                            $(this).prop('checked', true).trigger('change');
                        }
                    });
                }
            } else {
                selectedCommissions.delete(commissionId);

                // Zawsze odznaczaj transfery, jeśli odznaczasz zlecenie
                $(`.transfer-checkbox[data-commission-id="${commissionId}"]`).each(function() {
                    $(this).prop('checked', false).trigger('change');
                });
            }

            updateCommissionBadges(commissionId);
            updateSummary();
        });

        $('.transfer-checkbox').off('change').on('change', function() {
            const transferId = $(this).data('transfer-id');
            const commissionId = $(this).data('commission-id');
            const isChecked = $(this).prop('checked');

            if (isChecked) {
                selectedTransfers.add(transferId);
                updateSourceSumIndicator(transferId);
            } else {
                selectedTransfers.delete(transferId);
            }

            // ### KRYTYCZNA ZMIANA ###
            // Logika, która odznaczała rodzica, została USUNIĘTA.
            // Teraz odznaczenie dziecka nie wpływa na rodzica.

            // Po prostu zaktualizuj badge i podsumowanie
            updateCommissionBadges(commissionId);
            updateSelectAllButtonState(commissionId);
            updateSummary();
        });

        $('.select-all-transfers-btn').off('click').on('click', function() {
            const commissionId = $(this).data('commission-id');
            const $button = $(this);

            const $transferCheckboxes = $(`.transfer-checkbox[data-commission-id="${commissionId}"]`).not(':disabled');

            const allChecked = $transferCheckboxes.length > 0 &&
                               $transferCheckboxes.filter(':checked').length === $transferCheckboxes.length;

            if (allChecked) {
                // Unselect all
                $transferCheckboxes.prop('checked', false).trigger('change');
                $button.html('<i class="bi bi-check-square"></i> Zaznacz wszystkie transfery');
            } else {
                // Select all
                $transferCheckboxes.prop('checked', true).trigger('change');
                $button.html('<i class="bi bi-square"></i> Odznacz wszystkie transfery');
            }
        });

        $('.source-qty-input').off('input').on('input', function() {
            const transferId = $(this).data('transfer-id');
            const sourceIndex = $(this).data('source-index');
            const transfer = findTransferById(transferId);

            if (!transfer || transfer.sources[sourceIndex].isMainWarehouse) return;

            const newValue = parseFloat($(this).val()) || 0;
            const oldValue = transfer.sources[sourceIndex].quantity;
            const diff = newValue - oldValue;

            const mainIndex = transfer.sources.findIndex(s => s.isMainWarehouse);
            const $mainInput = $(`.source-qty-input[data-transfer-id="${transferId}"][data-source-index="${mainIndex}"]`);
            const currentMainValue = parseFloat($mainInput.val()) || 0;
            $mainInput.val(currentMainValue - diff);

            transfer.sources[sourceIndex].quantity = newValue;
            transfer.sources[mainIndex].quantity = currentMainValue - diff;

            updateSourceSumIndicator(transferId);
            updateSummary(); // Aktualizacja na żywo
        });

        $('.source-qty-plus').off('click').on('click', function() {
            const transferId = $(this).data('transfer-id');
            const sourceIndex = $(this).data('source-index');
            const transfer = findTransferById(transferId);

            if (!transfer) return;

            const mainIndex = transfer.sources.findIndex(s => s.isMainWarehouse);
            const $mainInput = $(`.source-qty-input[data-transfer-id="${transferId}"][data-source-index="${mainIndex}"]`);
            const currentMainValue = parseFloat($mainInput.val()) || 0;

            if (currentMainValue > 0) {
                const $input = $(`.source-qty-input[data-transfer-id="${transferId}"][data-source-index="${sourceIndex}"]`);
                const currentValue = parseFloat($input.val()) || 0;

                $input.val(currentValue + 1);
                $mainInput.val(currentMainValue - 1);

                transfer.sources[sourceIndex].quantity = currentValue + 1;
                transfer.sources[mainIndex].quantity = currentMainValue - 1;

                updateSourceSumIndicator(transferId);
                updateSummary(); // Aktualizacja na żywo
            }
        });

        $('.source-qty-minus').off('click').on('click', function() {
            const transferId = $(this).data('transfer-id');
            const sourceIndex = $(this).data('source-index');
            const transfer = findTransferById(transferId);

            if (!transfer) return;

            const $input = $(`.source-qty-input[data-transfer-id="${transferId}"][data-source-index="${sourceIndex}"]`);
            const currentValue = parseFloat($input.val()) || 0;

            if (currentValue > 0) {
                const mainIndex = transfer.sources.findIndex(s => s.isMainWarehouse);
                const $mainInput = $(`.source-qty-input[data-transfer-id="${transferId}"][data-source-index="${mainIndex}"]`);
                const currentMainValue = parseFloat($mainInput.val()) || 0;

                $input.val(currentValue - 1);
                $mainInput.val(currentMainValue + 1);

                transfer.sources[sourceIndex].quantity = currentValue - 1;
                transfer.sources[mainIndex].quantity = currentMainValue + 1;

                updateSourceSumIndicator(transferId);
                updateSummary(); // Aktualizacja na żywo
            }
        });
    }

    function getCurrentSourceTotal(transferId) {
        let total = 0;
        $(`.source-qty-input[data-transfer-id="${transferId}"]`).each(function() {
            total += parseFloat($(this).val()) || 0;
        });
        return total;
    }

    function redistributeFromOtherSources(transferId, excludeIndex, amount) {
        updateSourceSumIndicator(transferId);
    }

    function redistributeToOtherSources(transferId, excludeIndex, amount) {
        updateSourceSumIndicator(transferId);
    }

    function updateSourceSumIndicator(transferId) {
        const transfer = findTransferById(transferId);
        if (!transfer) return;

        const currentTotal = getCurrentSourceTotal(transferId);
        const expectedTotal = transfer.qtyAvailable;
        const $indicator = $(`.source-sum-indicator[data-transfer-id="${transferId}"]`);

        if (Math.abs(currentTotal - expectedTotal) >= 0.01) {
            $indicator.html(`<span class="text-danger"><i class="bi bi-exclamation-triangle"></i> Suma: ${currentTotal.toFixed(2)} (oczekiwano: ${expectedTotal.toFixed(2)})</span>`);
        } else {
            $indicator.html('');
        }
    }

    function updateCommissionBadges(commissionId) {
        const $commCheckbox = $(`.commission-checkbox[data-commission-id="${commissionId}"]`);
        const $transferCheckboxes = $(`.transfer-checkbox[data-commission-id="${commissionId}"]`).not(':disabled');
        const totalTransfers = $transferCheckboxes.length;
        const checkedTransfers = $transferCheckboxes.filter(':checked').length;

        const $selectedBadge = $(`.selected-transfers-badge[data-commission-id="${commissionId}"]`);
        const $partialBadge = $(`.partial-transfers-badge[data-commission-id="${commissionId}"]`);

        // --- Logika badge'a "X transferów" ---
        if (checkedTransfers > 0) {
            $selectedBadge.text(`${checkedTransfers} transferów`).show();
        } else {
            $selectedBadge.hide();
        }

        if ($commCheckbox.prop('checked') && checkedTransfers < totalTransfers) {
            $partialBadge.show();
        } else {
            $partialBadge.hide();
        }
    }

    function updateSelectAllButtonState(commissionId) {
        const $button = $(`.select-all-transfers-btn[data-commission-id="${commissionId}"]`);
        if ($button.length === 0) return;

        const $transferCheckboxes = $(`.transfer-checkbox[data-commission-id="${commissionId}"]`).not(':disabled');
        const checkedCount = $transferCheckboxes.filter(':checked').length;
        const totalCount = $transferCheckboxes.length;

        if (checkedCount === totalCount && totalCount > 0) {
            $button.html('<i class="bi bi-square"></i> Odznacz wszystkie transfery');
        } else {
            $button.html('<i class="bi bi-check-square"></i> Zaznacz wszystkie transfery');
        }
    }

    function applyAutoSelection(cancellationType, isGrouped, clickedCommissionId) {
        // In the new flow, we always default to selecting everything in the visible scope
        if (isGrouped) {
            $('.commission-checkbox').not(':disabled').prop('checked', true).trigger('change');
            $('.transfer-checkbox').not(':disabled').prop('checked', true).trigger('change');
        } else {
            $(`.commission-checkbox[data-commission-id="${clickedCommissionId}"]`).not(':disabled').prop('checked', true).trigger('change');
            $(`.transfer-checkbox[data-commission-id="${clickedCommissionId}"]`).not(':disabled').prop('checked', true).trigger('change');
        }
    }

    function getSelectedTransfersForCommission(commissionId) {
        const selectedTransfersList = [];
        selectedTransfers.forEach(transId => {
            const transfer = findTransferById(transId);
            if (transfer && transfer.commissionId == commissionId) {
                selectedTransfersList.push(transfer);
            }
        });
        return selectedTransfersList;
    }

    function reorganizeByWarehouse(componentAggregator, negativeAggregator) {
        const warehouseData = {};

        // Process returns TO warehouses
        for (const componentName in componentAggregator) {
            for (const warehouseName in componentAggregator[componentName]) {
                if (!warehouseData[warehouseName]) warehouseData[warehouseName] = [];
                warehouseData[warehouseName].push({
                    componentName: componentName,
                    quantity: componentAggregator[componentName][warehouseName],
                    badge: 'badge-success',
                    badgeText: 'Zwrot do',
                    quantityClass: 'text-success',
                    quantityPrefix: '+'
                });
            }
        }

        // Process cancellations FROM warehouses
        for (const componentName in negativeAggregator) {
            for (const warehouseName in negativeAggregator[componentName]) {
                if (!warehouseData[warehouseName]) warehouseData[warehouseName] = [];
                warehouseData[warehouseName].push({
                    componentName: componentName,
                    quantity: negativeAggregator[componentName][warehouseName],
                    badge: 'badge-danger',
                    badgeText: 'Anulowanie z',
                    quantityClass: 'text-danger',
                    quantityPrefix: '-'
                });
            }
        }

        return warehouseData;
    }

    function updateSummary() {
        const $summary = $('#cancellationSummary');
        const $content = $('#summaryContent');
        const $nextBtn = $('#nextToSummary');

        const hasSelection = selectedCommissions.size > 0 || selectedTransfers.size > 0;
        $nextBtn.prop('disabled', !hasSelection);

        if (!hasSelection) {
            $content.empty();
            return;
        }

        let html = '<h6 class="font-weight-bold mb-3 text-muted">Wybrane elementy do przeglądu:</h6>';

        // === SEKCJA ZLECEŃ ===
        if (selectedCommissions.size > 0) {
            html += `<div class="mb-3">
                <strong class="text-secondary"><i class="bi bi-file-text"></i> Zlecenia do anulacji:</strong>
                <div class="mt-2">`;

            const commissionArray = Array.from(selectedCommissions).sort((a, b) => a - b);

            commissionArray.forEach((commId) => {
                const commission = cancellationData.commissionsData[commId];
                const priorityColor = (PRIORITY_CONFIG[commission.priority] || PRIORITY_CONFIG['none']).color;
                const selectedTransfersList = getSelectedTransfersForCommission(commId);
                const transferCount = selectedTransfersList.length;
                
                // Check for partial selection
                const availableTransfers = (cancellationData.transfersByCommission[commId] || []).filter(t => t.qtyAvailable > 0 && !t.isCancelled);
                const isPartialSelection = transferCount < availableTransfers.length;

                const collapseId = `summary-comm-${commId}`;
                const createdDate = commission.createdAt || 'Brak daty';
                const qtyText = `Zlecono: ${commission.qty} | Wyprodukowano: ${commission.qtyProduced}`;
                const unreturnedText = commission.qtyUnreturned > 0
                    ? ` | <span class="text-warning">Niewrócono: ${commission.qtyUnreturned}</span>`
                    : '';

                html += `
                    <div class="card mb-2" style="box-shadow: -5px 0px 0px 0px ${priorityColor};">
                        <div class="card-header bg-light py-2"
                             style="cursor: pointer;"
                             data-toggle="collapse"
                             data-target="#${collapseId}"
                             aria-expanded="false">
                            <div class="d-flex align-items-center justify-content-between">
                                 <div>
                                     <i class="bi bi-chevron-right collapse-icon"></i>
                                     <strong>Zlecenie #${commId}: ${commission.deviceName}</strong>
                                     ${isPartialSelection ? '<span class="badge badge-danger ml-2"><i class="bi bi-exclamation-triangle"></i> Nie wszystkie transfery</span>' : ''}
                                     ${transferCount > 0 ? `<span class="badge badge-info ml-2">${transferCount} ${transferCount === 1 ? 'transfer' : 'transfery'}</span>` : ''}
                                 </div>
                                 <div class="text-right">
                                     <span class="badge badge-secondary mr-2">${commission.state || 'Nieznany'}</span>
                                     <small class="text-muted"><i class="bi bi-calendar3"></i> ${createdDate}</small>
                                 </div>
                            </div>
                        </div>
                        <div id="${collapseId}" class="collapse">
                            <div class="card-body py-2 small bg-white">
                                <div class="mb-2"><i class="bi bi-box-seam"></i> <strong>Ilości:</strong> ${qtyText}${unreturnedText}</div>
                                ${transferCount > 0 ? `
                                     <div class="mt-3">
                                         <strong class="text-muted"><i class="bi bi-arrow-repeat"></i> Wybrane transfery (${transferCount}):</strong>
                                         <ul class="mt-2 mb-0" style="font-size: 0.9em;">
                                             ${selectedTransfersList.map(t => `
                                                 <li><strong>${t.componentName}</strong> (${t.componentType}) - Do zwrotu: <span class="text-success">${Math.abs(t.qtyAvailable).toFixed(2)}</span></li>
                                             `).join('')}
                                         </ul>
                                     </div>
                                 ` : '<div class="text-muted">Brak wybranych transferów</div>'}
                            </div>
                        </div>
                    </div>
                `;
            });

            html += '</div></div>';
        }

        // === COMPONENT AGGREGATION FOR SUMMARY ===
        const componentAggregator = {}; // Positive values (returns TO warehouses)
        const negativeAggregator = {}; // Negative values (cancellations FROM warehouses)

        selectedTransfers.forEach(transId => {
            const transfer = findTransferById(transId);
            if (!transfer) return;

            const componentName = transfer.componentName;

            // Extract target warehouse for cancellation aggregator
            let targetWarehouse = null;
            if (transfer.transferDetails && transfer.transferDetails.length > 0) {
                const targetDetail = transfer.transferDetails.find(detail => detail.originalQty > 0);
                if (targetDetail) {
                    targetWarehouse = targetDetail.warehouseName;
                }
            }

            // Check if multi-source distribution exists
            const $distribution = $(`.sources-distribution[data-transfer-id="${transfer.transferId}"]`);
            if ($distribution.length > 0) {
                // Multi-source: Read current values from inputs
                $distribution.find('.source-qty-input').each(function(index) {
                    const qty = parseFloat($(this).val()) || 0;
                    if (qty > 0) {
                        const source = transfer.sources[index];
                        const warehouseName = source.warehouseName;

                        // Add to positive aggregator
                        if (!componentAggregator[componentName]) { componentAggregator[componentName] = {}; }
                        if (!componentAggregator[componentName][warehouseName]) { componentAggregator[componentName][warehouseName] = 0; }
                        componentAggregator[componentName][warehouseName] += qty;

                        // Add to negative aggregator (using target warehouse)
                        if (source.originalQty < 0 && targetWarehouse) {
                            if (!negativeAggregator[componentName]) { negativeAggregator[componentName] = {}; }
                            if (!negativeAggregator[componentName][targetWarehouse]) { negativeAggregator[componentName][targetWarehouse] = 0; }
                            negativeAggregator[componentName][targetWarehouse] += Math.abs(source.originalQty);
                        }
                    }
                });
            } else {
                // Single-source: Use data from object
                transfer.sources.forEach(source => {
                    const warehouseName = source.warehouseName;

                    if (source.quantity > 0) {
                        const qty = source.quantity;

                        // Add to positive aggregator
                        if (!componentAggregator[componentName]) { componentAggregator[componentName] = {}; }
                        if (!componentAggregator[componentName][warehouseName]) { componentAggregator[componentName][warehouseName] = 0; }
                        componentAggregator[componentName][warehouseName] += qty;
                    }

                    // Add to negative aggregator (using target warehouse)
                    if (source.originalQty < 0 && targetWarehouse) {
                        if (!negativeAggregator[componentName]) { negativeAggregator[componentName] = {}; }
                        if (!negativeAggregator[componentName][targetWarehouse]) { negativeAggregator[componentName][targetWarehouse] = 0; }
                        negativeAggregator[componentName][targetWarehouse] += Math.abs(source.originalQty);
                    }
                });
            }
        });

        // === RENDER COMPONENT AGGREGATION TABLE ===
        html += `
            <div class="mt-4">
                <strong class="text-info">
                    <i class="bi bi-calculator"></i> Podsumowanie komponentów (wg magazynu):
                </strong>
        `;

        if (Object.keys(componentAggregator).length > 0 || Object.keys(negativeAggregator).length > 0) {
            // Reorganize data by warehouse
            const warehouseData = reorganizeByWarehouse(componentAggregator, negativeAggregator);
            const warehouseNames = Object.keys(warehouseData).sort();

            html += `
                <table class="table table-sm table-bordered table-hover mt-2" style="font-size: 0.9em;">
                    <thead class="thead-light">
                        <tr>
                            <th style="width: 40%;">Komponent</th>
                            <th style="width: 30%;">Magazyn</th>
                            <th style="width: 15%;">Typ operacji</th>
                            <th class="text-right" style="width: 15%;">Łączna ilość</th>
                        </tr>
                    </thead>
                    <tbody>
            `;

            // Render warehouse groups
            warehouseNames.forEach((warehouseName, warehouseIndex) => {
                const components = warehouseData[warehouseName];
                const totalComponents = components.length;
                const collapseId = `warehouse-group-${warehouseIndex}`;

                // Warehouse header row
                html += `
                    <tr class="table-active warehouse-header-row"
                        style="cursor: pointer;"
                        data-toggle="collapse"
                        data-target=".${collapseId}"
                        aria-expanded="false">
                        <td colspan="4">
                            <div class="d-flex align-items-center justify-content-between">
                                <div>
                                    <i class="bi bi-chevron-right warehouse-chevron"></i>
                                    <i class="bi bi-building"></i>
                                    <strong>${warehouseName}</strong>
                                    <span class="badge badge-secondary ml-2">${totalComponents} ${totalComponents === 1 ? 'komponent' : 'komponenty'}</span>
                                </div>
                            </div>
                        </td>
                    </tr>
                `;

                // Component rows (collapsed by default)
                components.forEach(component => {
                    html += `
                        <tr class="collapse ${collapseId} warehouse-component-row">
                            <td>${component.componentName}</td>
                            <td class="text-muted">${warehouseName}</td>
                            <td><span class="badge ${component.badge}">${component.badgeText}</span></td>
                            <td class="text-right ${component.quantityClass}">
                                <strong>${component.quantityPrefix}${component.quantity.toFixed(5)}</strong>
                            </td>
                        </tr>
                    `;
                });
            });

            html += '</tbody></table>';
        } else {
            html += `
                <div class="alert alert-light border mt-2 py-3 text-center text-muted">
                    <i class="bi bi-info-circle"></i> Brak wybranych transferów do zwrotu - nie zostaną wykonane żadne ruchy magazynowe.
                </div>
            `;
        }

        html += '</div>';

        // === SEKCJA NIEWRÓCONYCH SZTUK (bez zmian) ===
        const commissionsWithUnreturned = {};
        selectedCommissions.forEach(commId => {
            const commission = cancellationData.commissionsData[commId];
            if (commission.qtyUnreturned > 0) {
                commissionsWithUnreturned[commId] = {
                    name: commission.deviceName,
                    qty: commission.qtyUnreturned
                };
            }
        });

        if (Object.keys(commissionsWithUnreturned).length > 0) {
            html += `
                <div class="alert alert-warning mt-4">
                    <h6 class="font-weight-bold">
                        <i class="bi bi-exclamation-triangle"></i> Wykryto niewrócone sztuki
                    </h6>
                    <p class="mb-2">Następujące zlecenia mają wyprodukowane, ale niewrócone sztuki. Zaznacz checkbox aby automatycznie zwrócić je do magazynu docelowego:</p>
                    ${Object.keys(commissionsWithUnreturned).map(commissionId => {
                const unreturnedQty = commissionsWithUnreturned[commissionId].qty;

                return `
                            <div class="card mb-2">
                                <div class="card-body py-2">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <span><strong>Zlecenie #${commissionId}</strong> - Niewrócono: <span class="text-warning">${unreturnedQty} szt.</span></span>
                                        <div class="custom-control custom-checkbox">
                                            <input type="checkbox" 
                                                   class="custom-control-input return-completed-checkbox" 
                                                   id="return-completed-${commissionId}" 
                                                   data-commission-id="${commissionId}">
                                            <label class="custom-control-label" for="return-completed-${commissionId}">
                                                Zwróć automatycznie
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        `;
            }).join('')}
                </div>
            `;
        }

        $content.html(html);

        // Handle collapse events for commission cards
        $('[data-toggle="collapse"]').on('show.bs.collapse', function() {
            $(this).attr('aria-expanded', 'true');
        }).on('hide.bs.collapse', function() {
            $(this).attr('aria-expanded', 'false');
        });

        // Handle warehouse group collapse events
        $('.warehouse-header-row').on('click', function() {
            const isExpanded = $(this).attr('aria-expanded') === 'true';
            $(this).attr('aria-expanded', !isExpanded);
        });
    }

    function switchStep(step) {
        if (step === 1) {
            $('#selectionView').show();
            $('#summaryView').hide();
            $('#backToSelection').hide();
            $('#cancelCloseBtn').show();
            $('#nextToSummary').show();
            $('#confirmCancellation').hide();
            $('#cancelCommissionModal .modal-title').html('<i class="bi bi-exclamation-triangle"></i> Wybór elementów do anulacji');
        } else {
            $('#selectionView').hide();
            $('#summaryView').show();
            $('#backToSelection').show();
            $('#cancelCloseBtn').hide();
            $('#nextToSummary').hide();
            $('#confirmCancellation').show();
            $('#cancelCommissionModal .modal-title').html('<i class="bi bi-clipboard-check"></i> Podsumowanie i potwierdzenie');
            $('#cancelCommissionModal .modal-body').scrollTop(0);
        }
    }

    $('#nextToSummary').on('click', function() {
        updateSummary();
        switchStep(2);
    });

    $('#backToSelection').on('click', function() {
        switchStep(1);
    });

    $('#confirmCancellation').off('click').on('click', function() {
        if (selectedCommissions.size === 0 && selectedTransfers.size === 0) {
            showErrorMessage('Nie wybrano żadnych elementów do anulacji');
            return;
        }

        const confirmHtml = `
            <div class="modal fade" id="finalConfirmModal" style="z-index: 1060;" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title">
                                <i class="bi bi-exclamation-triangle"></i> Ostateczne potwierdzenie
                            </h5>
                        </div>
                        <div class="modal-body">
                            <p class="font-weight-bold">Czy na pewno chcesz wykonać tę operację?</p>
                            <p class="text-danger">Ta operacja jest nieodwracalna!</p>
                            <ul>
                                ${selectedCommissions.size > 0 ? `<li>Zostanie anulowanych: <strong>${selectedCommissions.size}</strong> zleceń</li>` : ''}
                                ${selectedTransfers.size > 0 ? `<li>Zostanie zwróconych: <strong>${selectedTransfers.size}</strong> transferów komponentów</li>` : ''}
                            </ul>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-dismiss="modal">Anuluj</button>
                            <button type="button" class="btn btn-danger" id="finalConfirmBtn">Tak, anuluj</button>
                        </div>
                    </div>
                </div>
            </div>
        `;

        $('body').append(confirmHtml);
        const $confirmModal = $('#finalConfirmModal');

        $confirmModal.find('#finalConfirmBtn').on('click', function() {
            $confirmModal.modal('hide');
            submitCancellation();
        });

        $confirmModal.on('shown.bs.modal', function() {
            // Create dark overlay over the parent cancellation modal
            const overlayHtml = '<div id="modalDimOverlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background-color: rgba(0, 0, 0, 0.5); z-index: 1055;"></div>';
            $('body').append(overlayHtml);
        });

        $confirmModal.on('hidden.bs.modal', function() {
            $(this).remove();
            // Remove the dark overlay
            $('#modalDimOverlay').remove();
            // Restore modal-open class for parent modal to maintain proper scroll behavior
            if ($('#cancelCommissionModal').hasClass('show')) {
                $('body').addClass('modal-open');
                // Restore scrollbar padding if needed
                const scrollbarWidth = window.innerWidth - document.documentElement.clientWidth;
                if (scrollbarWidth > 0) {
                    $('body').css('padding-right', scrollbarWidth + 'px');
                }
            }
        });

        $confirmModal.modal('show');
    });

    function submitCancellation() {
        const transfersToSubmit = [];
        const returnCompletedAsReturned = {};

        selectedTransfers.forEach(transId => {
            const transfer = findTransferById(transId);
            if (!transfer) return;

            const $distribution = $(`.sources-distribution[data-transfer-id="${transId}"]`);
            const sources = [];

            if ($distribution.length > 0) {
                $distribution.find('.source-qty-input').each(function(index) {
                    const qty = parseFloat($(this).val()) || 0;
                    if (qty > 0) {
                        sources.push({
                            warehouseId: transfer.sources[index].warehouseId,
                            quantity: qty
                        });
                    }
                });
            } else {
                transfer.sources.forEach(source => {
                    if (source.quantity > 0) {
                        sources.push({
                            warehouseId: source.warehouseId,
                            quantity: source.quantity
                        });
                    }
                });
            }

            transfersToSubmit.push({
                transferId: transfer.transferId,
                commissionId: transfer.commissionId,
                componentType: transfer.componentType,
                componentId: transfer.componentId,
                qtyToReturn: transfer.qtyAvailable,
                sources: sources
            });
        });

        $('.return-completed-checkbox:checked').each(function() {
            const commissionId = $(this).data('commission-id');
            returnCompletedAsReturned[commissionId] = true;
        });

        $.ajax({
            type: 'POST',
            url: COMPONENTS_PATH + '/commissions/cancel-commission.php',
            data: {
                action: 'submit_cancellation',
                selectedCommissions: JSON.stringify([...selectedCommissions]),
                selectedTransfers: JSON.stringify(transfersToSubmit),
                returnCompletedAsReturned: JSON.stringify(returnCompletedAsReturned)
            },
            success: function(response) {
                if (response.success) {
                    showSuccessMessage(response.message);
                    $('#cancelCommissionModal').modal('hide');
                    if (typeof refreshCommissions === 'function') {
                        refreshCommissions();
                    }
                } else {
                    showErrorMessage('Błąd: ' + response.message);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                showErrorMessage('Błąd podczas anulacji');
            }
        });
    }

    function findTransferById(transferId) {
        for (let commId in cancellationData.transfersByCommission) {
            const transfers = cancellationData.transfersByCommission[commId];
            for (let transfer of transfers) {
                if (transfer.transferId === transferId) {
                    return transfer;
                }
            }
        }
        return null;
    }

    $('#cancelCommissionModal').on('hidden.bs.modal', function() {
        cancellationData = null;
        selectedCommissions.clear();
        selectedTransfers.clear();
        $('#groupsList').empty();
        $('#summaryContent').empty();
        switchStep(1);
    });

    function loadDetailsData(commissionId, isGrouped, groupedIds) {
        $('#cancelModalOverlay').show().addClass("d-flex");
        
        $.ajax({
            type: 'POST',
            url: COMPONENTS_PATH + '/commissions/get-commission-data.php',
            data: {
                action: 'get_details_data',
                commissionId: commissionId,
                isGrouped: isGrouped ? 'true' : 'false',
                groupedIds: groupedIds || ''
            },
            success: function(response) {
                if (response.success) {
                    renderDetailsModal(response.details);
                    $('#commissionDetailsModal').modal('show');
                } else {
                    showErrorMessage('Błąd: ' + response.message);
                }
                $('#cancelModalOverlay').hide().removeClass("d-flex");
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                showErrorMessage('Błąd podczas ładowania szczegółów');
                $('#cancelModalOverlay').hide().removeClass("d-flex");
            }
        });
    }

    function renderDetailsModal(details) {
        let html = `
            <style>
                .group-header {
                    cursor: pointer;
                    background-color: #f8f9fa;
                }
                .group-header:hover {
                    background-color: #e9ecef;
                }
                .group-header .bi-chevron-right {
                    transition: transform 0.2s;
                }
                .group-header:not(.collapsed) .bi-chevron-right {
                    transform: rotate(90deg);
                }
                .badge-sku { background-color: #007bff; color: white; }
                .badge-tht { background-color: #28a745; color: white; }
                .badge-smd { background-color: #17a2b8; color: white; }
                .badge-parts { background-color: #ffc107; color: #212529; }
            </style>
        `;
        
        const getBadgeClass = (type) => {
            const map = {
                'sku': 'badge-sku',
                'tht': 'badge-tht',
                'smd': 'badge-smd',
                'parts': 'badge-parts'
            };
            return map[type.toLowerCase()] || 'badge-secondary';
        };

        for (let cId in details) {
            const commission = details[cId];
            const targetComponentId = commission.targetComponentId;

            // Group movements by component
            const groups = {};
            commission.movements.forEach(m => {
                const key = `${m.type}_${m.component_id}`;
                if (!groups[key]) {
                    groups[key] = {
                        name: m.component_name,
                        type: m.type,
                        displayType: m.displayType || m.type,
                        compId: m.component_id,
                        movements: [],
                        isTarget: m.isProduced
                    };
                }
                groups[key].movements.push(m);
            });

            // Convert to array and sort: target component first
            const sortedGroups = Object.values(groups).sort((a, b) => {
                if (a.isTarget && !b.isTarget) return -1;
                if (!a.isTarget && b.isTarget) return 1;
                return a.name.localeCompare(b.name);
            });

            html += `
                <div class="card mb-4 border-info">
                    <div class="card-header bg-light d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">
                            <span class="badge badge-info mr-2">#${cId}</span>
                            ${commission.deviceName}
                        </h5>
                        <div>
                            <span class="badge badge-secondary">${commission.state}</span>
                            <small class="text-muted ml-2">${commission.createdAt}</small>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width: 250px;">Komponent</th>
                                        <th>Magazyn</th>
                                        <th class="text-right">Ilość</th>
                                        <th>Data</th>
                                        <th>Komentarz</th>
                                        <th class="text-center">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
            `;

            if (sortedGroups.length === 0) {
                html += '<tr><td colspan="6" class="text-center py-3 text-muted">Brak zarejestrowanych ruchów magazynowych</td></tr>';
            } else {
                sortedGroups.forEach((group, gIndex) => {
                    const groupId = `group-${cId}-${gIndex}`;
                    
                    // Sort movements within group: produced first, then by date
                    group.movements.sort((a, b) => {
                        if (a.isProduced && !b.isProduced) return -1;
                        if (!a.isProduced && b.isProduced) return 1;
                        return new Date(a.timestamp) - new Date(b.timestamp);
                    });

                    // Group header row
                    html += `
                        <tr class="group-header collapsed" data-toggle="collapse" data-target=".${groupId}">
                            <td colspan="6" class="py-2">
                                <i class="bi bi-chevron-right mr-2"></i>
                                <strong>${group.isTarget ? '<i class="bi bi-cpu"></i> ' : ''}${group.name}</strong>
                                <span class="badge ${getBadgeClass(group.displayType)} ml-2">${group.displayType.toUpperCase()}</span>
                            </td>
                        </tr>
                    `;

                    group.movements.forEach((row) => {
                        html += `
                            <tr class="collapse ${groupId} ${row.isCancelled ? 'table-danger text-muted' : ''}">
                                <td class="pl-4">
                                    <small class="text-muted">${row.isProduced ? 'Produkcja' : 'Komponent'}</small>
                                </td>
                                <td>${row.warehouseName}</td>
                                <td class="text-right font-weight-bold ${row.qty < 0 ? 'text-danger' : 'text-success'}">
                                    ${row.qty > 0 ? '+' : ''}${row.qty}
                                </td>
                                <td><small>${row.timestamp}</small></td>
                                <td><small>${row.comment || '-'}</small></td>
                                <td class="text-center">
                                    ${row.isCancelled ? 
                                        '<span class="badge badge-danger"><i class="bi bi-x-circle"></i> Anulowano</span>' : 
                                        ''}
                                </td>
                            </tr>
                        `;
                    });
                });
            }

            html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            `;
        }
        
        $('#detailsModalBody').html(html);
    }
});