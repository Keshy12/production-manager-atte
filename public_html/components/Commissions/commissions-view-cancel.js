$(document).ready(function() {
    let cancellationData = null;
    let selectedCommissions = new Set();
    let selectedTransfers = new Set();

    $(document).on('click', '.cancelCommission', function(e) {
        e.preventDefault();
        const commissionId = $(this).data('id');
        const $card = $(this).closest('.card');
        const groupedIdsAttr = $card.attr('data-grouped-ids');
        const isGrouped = groupedIdsAttr && groupedIdsAttr.split(',').length > 1;

        showCancellationTypeDialog(commissionId, isGrouped, groupedIdsAttr);
    });

    function showCancellationTypeDialog(commissionId, isGrouped, groupedIds) {
        const typeText = isGrouped ? 'zgrupowanych zleceń' : 'zlecenia';

        const dialogHtml = `
            <div class="modal fade" id="cancellationTypeModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header bg-warning">
                            <h5 class="modal-title">
                                <i class="bi bi-question-circle"></i> Wybierz typ anulacji
                            </h5>
                            <button type="button" class="close" data-dismiss="modal">
                                <span>&times;</span>
                            </button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-3">Wybierz, co chcesz anulować dla ${typeText}:</p>
                            <div class="list-group">
                                <a href="#" class="list-group-item list-group-item-action" data-type="commissions">
                                    <h6 class="mb-1">
                                        <i class="bi bi-file-text"></i> Tylko zlecenia
                                    </h6>
                                    <small class="text-muted">
                                        Anuluje zlecenia bez automatycznego zwrotu komponentów
                                    </small>
                                </a>
                                <a href="#" class="list-group-item list-group-item-action" data-type="transfers">
                                    <h6 class="mb-1">
                                        <i class="bi bi-box"></i> Tylko transfery komponentów
                                    </h6>
                                    <small class="text-muted">
                                        Zwraca komponenty bez anulowania zleceń
                                    </small>
                                </a>
                                <a href="#" class="list-group-item list-group-item-action list-group-item-danger" data-type="both">
                                    <h6 class="mb-1">
                                        <i class="bi bi-exclamation-triangle"></i> Zlecenia i transfery (domyślne)
                                    </h6>
                                    <small class="text-muted">
                                        Anuluje zlecenia I zwraca komponenty
                                    </small>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        `;

        $('body').append(dialogHtml);
        const $modal = $('#cancellationTypeModal');

        $modal.find('.list-group-item').on('click', function(e) {
            e.preventDefault();
            const type = $(this).data('type');
            $modal.modal('hide');
            setTimeout(() => {
                $modal.remove();
                loadCancellationData(commissionId, isGrouped, groupedIds, type);
            }, 300);
        });

        $modal.on('hidden.bs.modal', function() {
            $(this).remove();
        });

        $modal.modal('show');
    }

    function loadCancellationData(commissionId, isGrouped, groupedIds, cancellationType) {
        $('#cancelModalOverlay').show().addClass("d-flex");
        $('#cancelCommissionModal').modal({
            backdrop: 'static',
            keyboard: false,
            show: true
        });

        $.ajax({
            type: 'POST',
            url: COMPONENTS_PATH + '/commissions/cancel-commission.php',
            data: {
                action: 'get_cancellation_data',
                commissionId: commissionId,
                isGrouped: isGrouped,
                groupedIds: groupedIds || ''
            },
            success: function(response) {
                console.log('Cancellation data received:', response);

                if (response.success) {
                    cancellationData = response;
                    cancellationData.cancellationType = cancellationType;
                    renderCancellationModal(response);
                    applyAutoSelection(cancellationType, isGrouped, commissionId);
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

    function renderCancellationModal(data) {
        const $groupsList = $('#groupsList');
        $groupsList.empty();
        selectedCommissions.clear();
        selectedTransfers.clear();

        const sortedCommissions = Object.keys(data.commissionsData).sort((a, b) => {
            return new Date(data.commissionsData[a].createdAt) - new Date(data.commissionsData[b].createdAt);
        });

        sortedCommissions.forEach(commissionId => {
            const commission = data.commissionsData[commissionId];
            const transfers = data.transfersByCommission[commissionId] || [];
            const unreturned = commission.qtyUnreturned;
            const hasUnreturned = unreturned > 0;

            const cardHtml = `
            <div class="card mb-3 commission-card" data-commission-id="${commissionId}">
                <div class="card-header bg-light">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" 
                                   class="custom-control-input commission-checkbox" 
                                   id="comm-${commissionId}"
                                   data-commission-id="${commissionId}"
                                   data-has-unreturned="${hasUnreturned}"
                                   data-unreturned-qty="${unreturned}">
                            <label class="custom-control-label font-weight-bold" for="comm-${commissionId}">
                                <i class="bi bi-file-earmark-text"></i>
                                Zlecenie #${commissionId}: ${commission.deviceName}
                                <span class="badge badge-secondary ml-2 selected-transfers-badge" data-commission-id="${commissionId}" style="display: none;">
                                    0 transferów
                                </span>
                                <span class="badge badge-danger ml-2 partial-transfers-badge" data-commission-id="${commissionId}" style="display: none;">
                                    <i class="bi bi-exclamation-triangle"></i> Nie wszystkie transfery
                                </span>
                            </label>
                        </div>
                        <button class="btn btn-sm btn-outline-secondary" 
                                type="button" 
                                data-toggle="collapse" 
                                data-target="#transfers-${commissionId}">
                            <i class="bi bi-chevron-down"></i>
                        </button>
                    </div>
                    <small class="text-muted ml-4">
                        Zlecono: ${commission.qty} | Wyprodukowano: ${commission.qtyProduced}${hasUnreturned ? ` | <span class="text-warning">Niewrócono: ${unreturned}</span>` : ''}
                    </small>
                </div>
                <div id="transfers-${commissionId}" class="collapse">
                    <div class="card-body bg-light">
                        ${transfers.length > 0 ? renderTransfersList(transfers, commissionId) : '<p class="mb-0 text-muted">Brak dostępnych transferów do anulacji</p>'}
                    </div>
                </div>
            </div>
        `;

            $groupsList.append(cardHtml);
        });

        attachEventHandlers();
        updateSummary();
    }

    function renderTransfersList(transfers, commissionId) {
        if (transfers.length === 0) {
            return '<p class="mb-0 text-muted">Brak dostępnych transferów</p>';
        }

        return `
        <div class="transfers-list">
            ${transfers.map((transfer, index) => {
            const isDisabled = transfer.qtyAvailable <= 0;
            const displayQty = Math.abs(transfer.qtyAvailable);
            const qtyClass = transfer.qtyAvailable < 0 ? 'text-danger' : 'text-success';

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

            return `
                <div class="mb-3 ${isDisabled ? 'opacity-50' : ''}">
                    <div class="custom-control custom-checkbox">
                        <input type="checkbox" 
                               class="custom-control-input transfer-checkbox" 
                               id="trans-${transfer.transferId}"
                               data-transfer-id="${transfer.transferId}"
                               data-commission-id="${commissionId}"
                               ${isDisabled ? 'disabled' : ''}>
                        <label class="custom-control-label" for="trans-${transfer.transferId}">
                            <strong>${transfer.componentName}</strong>
                        </label>
                    </div>
                    <small class="text-muted d-block ml-4">
                        Przetransferowano: ${transfer.qtyTransferred} | 
                        Użyto: ${transfer.qtyUsed} | 
                        Do zwrotu: <span class="${qtyClass}">${displayQty}</span>
                        ${isDisabled ? ' <span class="badge badge-danger">Brak do zwrotu</span>' : ''}
                    </small>
                    ${sourcesHtml}
                </div>
            `;
        }).join('')}
        </div>
    `;
    }

    function attachEventHandlers() {
        $('.commission-checkbox').off('change').on('change', function() {
            const commissionId = $(this).data('commission-id');
            const isChecked = $(this).prop('checked');

            if (isChecked) {
                selectedCommissions.add(commissionId);

                $(`.transfer-checkbox[data-commission-id="${commissionId}"]`).each(function() {
                    if (!$(this).prop('disabled')) {
                        $(this).prop('checked', true).trigger('change');
                    }
                });
            } else {
                selectedCommissions.delete(commissionId);

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

            updateCommissionBadges(commissionId);
            updateSummary();
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

        if (checkedTransfers > 0) {
            $selectedBadge.text(`${checkedTransfers} transferów`).show();

            if (checkedTransfers < totalTransfers && !$commCheckbox.prop('checked')) {
                $partialBadge.show();
            } else {
                $partialBadge.hide();
            }
        } else {
            $selectedBadge.hide();
            $partialBadge.hide();
        }
    }

    function applyAutoSelection(cancellationType, isGrouped, clickedCommissionId) {
        switch (cancellationType) {
            case 'commissions':
                if (isGrouped) {
                    $('.commission-checkbox').prop('checked', true).trigger('change');
                } else {
                    $(`.commission-checkbox[data-commission-id="${clickedCommissionId}"]`).prop('checked', true).trigger('change');
                }
                break;

            case 'transfers':
                if (isGrouped) {
                    $('.transfer-checkbox').not(':disabled').prop('checked', true).trigger('change');
                } else {
                    $(`.transfer-checkbox[data-commission-id="${clickedCommissionId}"]`).not(':disabled').prop('checked', true).trigger('change');
                }
                break;

            case 'both':
                if (isGrouped) {
                    $('.commission-checkbox').prop('checked', true).trigger('change');
                } else {
                    $(`.commission-checkbox[data-commission-id="${clickedCommissionId}"]`).prop('checked', true).trigger('change');
                }
                break;
        }
    }

    function updateSummary() {
        const $summary = $('#cancellationSummary');
        const $content = $('#summaryContent');
        const $confirmBtn = $('#confirmCancellation');

        if (selectedCommissions.size === 0 && selectedTransfers.size === 0) {
            $summary.hide();
            $confirmBtn.prop('disabled', true);
            return;
        }

        let html = '<h6 class="font-weight-bold mb-3">Wybrane elementy:</h6>';

        if (selectedCommissions.size > 0) {
            html += '<div class="mb-3"><strong class="text-danger">Zlecenia do anulacji:</strong><ul class="mt-2">';
            selectedCommissions.forEach(commId => {
                const commission = cancellationData.commissionsData[commId];
                html += `<li>Zlecenie #${commId}: ${commission.deviceName}</li>`;
            });
            html += '</ul></div>';
        }

        if (selectedTransfers.size > 0) {
            html += '<div class="mb-3"><strong class="text-warning">Komponenty do zwrotu:</strong><ul class="mt-2">';
            selectedTransfers.forEach(transId => {
                const transfer = findTransferById(transId);
                if (transfer) {
                    html += `<li>Zlecenie #${transfer.commissionId}: ${transfer.componentName} (${transfer.qtyAvailable.toFixed(2)})</li>`;
                }
            });
            html += '</ul></div>';
        }

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
                <div class="alert alert-warning">
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
        $summary.show();
        $confirmBtn.prop('disabled', false);
    }

    $('#confirmCancellation').off('click').on('click', function() {
        if (selectedCommissions.size === 0 && selectedTransfers.size === 0) {
            showErrorMessage('Nie wybrano żadnych elementów do anulacji');
            return;
        }

        const confirmHtml = `
            <div class="modal fade" id="finalConfirmModal" tabindex="-1">
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

        $confirmModal.on('hidden.bs.modal', function() {
            $(this).remove();
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
        $('#cancellationSummary').hide();
    });
});