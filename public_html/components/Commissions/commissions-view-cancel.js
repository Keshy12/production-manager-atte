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

            const cardHtml = `
            <div class="card mb-3 commission-card" data-commission-id="${commissionId}">
                <div class="card-header bg-light">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" 
                                   class="custom-control-input commission-checkbox" 
                                   id="comm-${commissionId}"
                                   data-commission-id="${commissionId}">
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
                        Zlecono: ${commission.qty} | Wyprodukowano: ${commission.qtyProduced}
                    </small>
                </div>
                <div id="transfers-${commissionId}" class="collapse">
                    <div class="card-body transfers-list">
                        ${renderTransfers(transfers, commissionId)}
                    </div>
                </div>
            </div>
        `;

            $groupsList.append(cardHtml);
        });

        attachCancellationHandlers();
    }

    function renderTransfers(transfers, commissionId) {
        if (!transfers || transfers.length === 0) {
            return '<p class="text-muted mb-0">Brak transferów do anulacji</p>';
        }

        let html = '';

        transfers.forEach(transfer => {
            const isInvalid = transfer.qtyAvailable < 0;
            const invalidClass = isInvalid ? 'border-danger' : '';
            const disabledAttr = isInvalid ? 'disabled' : '';

            html += `
                <div class="card mb-2 ${invalidClass}" data-transfer-id="${transfer.transferId}">
                    <div class="card-body p-2">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" 
                                   class="custom-control-input transfer-checkbox" 
                                   id="trans-${transfer.transferId}"
                                   data-transfer-id="${transfer.transferId}"
                                   data-commission-id="${commissionId}"
                                   ${disabledAttr}>
                            <label class="custom-control-label" for="trans-${transfer.transferId}">
                                <strong>${transfer.componentName}</strong>
                            </label>
                        </div>
                        <div class="ml-4 mt-2">
                            <small class="d-block">
                                Przetransferowano: <strong>${transfer.qtyTransferred}</strong> | 
                                Użyto: <strong>${transfer.qtyUsed}</strong> | 
                                Do zwrotu: <strong class="${isInvalid ? 'text-danger' : ''}">${transfer.qtyAvailable}</strong>
                            </small>
                            ${isInvalid ? '<small class="text-danger d-block"><i class="bi bi-exclamation-triangle"></i> Użyto więcej niż przetransferowano - nie można anulować</small>' : ''}
                            
                            ${!isInvalid && transfer.sources.length > 1 ? renderSourcesDistribution(transfer) : ''}
                        </div>
                    </div>
                </div>
            `;
        });

        return html;
    }

    function renderSourcesDistribution(transfer) {
        let html = `
            <div class="sources-distribution mt-2" data-transfer-id="${transfer.transferId}">
                <small class="font-weight-bold d-block mb-2">
                    <i class="bi bi-distribute-vertical"></i> Rozkład zwrotu między źródła:
                </small>
        `;

        transfer.sources.forEach((source, index) => {
            const badgeClass = source.isMainWarehouse ? 'badge-primary' : 'badge-info';
            const showButtons = !source.isMainWarehouse;
            const maxQty = source.originalQty || source.quantity;

            html += `
            <div class="d-flex align-items-center justify-content-between mb-2" data-source-index="${index}">
                <div>
                    <span class="badge ${badgeClass}">${source.warehouseName}</span>
                    <span class="text-muted ml-1">(z ${maxQty})</span>
                </div>
                <div class="input-group input-group-sm" style="width: 140px;">
                    ${showButtons ? `
                    <div class="input-group-prepend">
                        <button class="btn btn-outline-secondary source-qty-decrease" type="button">
                            <i class="bi bi-dash"></i>
                        </button>
                    </div>
                    ` : ''}
                    <input type="number" 
                           class="form-control text-center source-qty-input" 
                           value="${source.quantity}"
                           data-source-index="${index}"
                           data-is-main="${source.isMainWarehouse}"
                           data-max-qty="${maxQty}"
                           step="0.01"
                           min="0"
                           max="${maxQty}"
                           ${source.isMainWarehouse ? 'readonly' : ''}>
                    ${showButtons ? `
                    <div class="input-group-append">
                        <button class="btn btn-outline-secondary source-qty-increase" type="button">
                            <i class="bi bi-plus"></i>
                        </button>
                    </div>
                    ` : ''}
                </div>
            </div>
        `;
        });

        html += `
            <div class="alert alert-danger mt-2 sources-error" style="display: none; padding: 0.5rem;">
                <small>
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>Błąd:</strong> Suma źródeł przekracza ilość do zwrotu!
                </small>
            </div>
        </div>
    `;
        return html;
    }

    function applyAutoSelection(cancellationType, isGrouped, clickedCommissionId) {
        if (cancellationType === 'commissions') {
            const commissionsToCheck = [];

            if (isGrouped) {
                $('.commission-checkbox').each(function() {
                    const commissionId = $(this).data('commission-id');
                    commissionsToCheck.push(commissionId);
                });
            } else {
                commissionsToCheck.push(parseInt(clickedCommissionId));
            }

            checkUnreturnedProducts(commissionsToCheck, function(returnMap) {
                commissionsToCheck.forEach(commissionId => {
                    if (isGrouped) {
                        $('.commission-checkbox').prop('checked', true);
                    } else {
                        $(`#comm-${commissionId}`).prop('checked', true);
                    }
                    selectedCommissions.add(parseInt(commissionId));
                });

                if (returnMap && Object.keys(returnMap).length > 0) {
                    window.returnCompletedAsReturned = returnMap;
                }

                $('.collapse').collapse('hide');
                updateTransferBadges();
                updateSummary();
            });

        } else if (cancellationType === 'transfers') {
            if (isGrouped) {
                $('.transfer-checkbox:not(:disabled)').prop('checked', true).each(function() {
                    selectedTransfers.add($(this).data('transfer-id'));
                });
                $('.collapse').collapse('show');
            } else {
                $(`[data-commission-id="${clickedCommissionId}"] .transfer-checkbox:not(:disabled)`).prop('checked', true).each(function() {
                    selectedTransfers.add($(this).data('transfer-id'));
                });
                $(`#transfers-${clickedCommissionId}`).collapse('show');
            }

            updateTransferBadges();
            updateSummary();

        } else {
            if (isGrouped) {
                $('.commission-checkbox').prop('checked', true).each(function() {
                    const commissionId = $(this).data('commission-id');
                    selectedCommissions.add(parseInt(commissionId));
                });
                $('.transfer-checkbox:not(:disabled)').prop('checked', true).each(function() {
                    selectedTransfers.add($(this).data('transfer-id'));
                });
            } else {
                $(`#comm-${clickedCommissionId}`).prop('checked', true);
                selectedCommissions.add(parseInt(clickedCommissionId));
                $(`[data-commission-id="${clickedCommissionId}"] .transfer-checkbox:not(:disabled)`).prop('checked', true).each(function() {
                    selectedTransfers.add($(this).data('transfer-id'));
                });
            }

            updateTransferBadges();
            updateSummary();
        }
    }

    function checkUnreturnedProducts(commissionIds, callback) {
        const unreturnedCommissions = [];

        commissionIds.forEach(commId => {
            const commission = cancellationData.commissionsData[commId];
            if (commission && commission.qtyUnreturned > 0) {
                unreturnedCommissions.push({
                    id: commId,
                    deviceName: commission.deviceName,
                    qtyUnreturned: commission.qtyUnreturned
                });
            }
        });

        if (unreturnedCommissions.length === 0) {
            callback(null);
            return;
        }

        let listHtml = '<ul class="mb-0">';
        unreturnedCommissions.forEach(comm => {
            listHtml += `<li><strong>Zlecenie #${comm.id}</strong> (${comm.deviceName}): <span class="badge badge-warning">${comm.qtyUnreturned} szt.</span></li>`;
        });
        listHtml += '</ul>';

        const dialogHtml = `
        <div class="modal fade" id="unreturnedProductsModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header bg-warning">
                        <h5 class="modal-title">
                            <i class="bi bi-box-seam"></i> Niewrócone produkty
                        </h5>
                    </div>
                    <div class="modal-body">
                        <p class="mb-2">Następujące zlecenia mają wyprodukowane, ale niewrócone produkty:</p>
                        ${listHtml}
                        <p class="mt-3 mb-0"><strong>Czy chcesz automatycznie zwrócić te produkty do magazynu docelowego?</strong></p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-action="no">
                            Nie, anuluj bez zwrotu
                        </button>
                        <button type="button" class="btn btn-primary" data-action="yes">
                            Tak, zwróć produkty
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;

        $('body').append(dialogHtml);
        const $modal = $('#unreturnedProductsModal');

        $modal.find('button[data-action]').on('click', function() {
            const action = $(this).data('action');
            const returnMap = {};

            if (action === 'yes') {
                unreturnedCommissions.forEach(comm => {
                    returnMap[comm.id] = true;
                });
            }

            $modal.modal('hide');
            setTimeout(() => {
                $modal.remove();
                callback(returnMap);
            }, 300);
        });

        $modal.on('hidden.bs.modal', function() {
            $(this).remove();
        });

        $modal.modal('show');
    }

    function attachCancellationHandlers() {
        $('.commission-checkbox').off('change').on('change', function() {
            const commissionId = $(this).data('commission-id');
            const isChecked = $(this).prop('checked');

            if (isChecked) {
                selectedCommissions.add(commissionId);
                $(`[data-commission-id="${commissionId}"] .transfer-checkbox:not(:disabled)`).prop('checked', true).each(function() {
                    selectedTransfers.add($(this).data('transfer-id'));
                });
            } else {
                selectedCommissions.delete(commissionId);
            }

            updateTransferBadges();
            updateSummary();
        });

        $('.transfer-checkbox').off('change').on('change', function() {
            const transferId = $(this).data('transfer-id');
            const isChecked = $(this).prop('checked');

            if (isChecked) {
                selectedTransfers.add(transferId);
            } else {
                selectedTransfers.delete(transferId);
            }

            updateTransferBadges();
            updateSummary();
        });

        $('.source-qty-input').off('input').on('input', function() {
            const maxQty = parseFloat($(this).data('max-qty'));
            const currentVal = parseFloat($(this).val()) || 0;

            if (currentVal > maxQty) {
                $(this).val(maxQty);
            }

            const transferId = $(this).closest('.sources-distribution').data('transfer-id');
            redistributeQuantities(transferId);
            updateSummary();
        });

        $('.source-qty-increase').off('click').on('click', function() {
            const $input = $(this).closest('.input-group').find('.source-qty-input');
            const currentVal = parseFloat($input.val()) || 0;
            const maxVal = parseFloat($input.data('max-qty'));
            $input.val(Math.min(currentVal + 1, maxVal)).trigger('input');
        });

        $('.source-qty-decrease').off('click').on('click', function() {
            const $input = $(this).closest('.input-group').find('.source-qty-input');
            const currentVal = parseFloat($input.val()) || 0;
            $input.val(Math.max(currentVal - 1, 0)).trigger('input');
        });
    }


    function updateTransferBadges() {
        $('.selected-transfers-badge, .partial-transfers-badge').hide();

        $('.commission-card').each(function() {
            const commissionId = $(this).data('commission-id');
            const isCommissionSelected = selectedCommissions.has(parseInt(commissionId));

            const $allTransferCheckboxes = $(`[data-commission-id="${commissionId}"] .transfer-checkbox:not(:disabled)`);
            const $checkedTransferCheckboxes = $(`[data-commission-id="${commissionId}"] .transfer-checkbox:checked`);

            const totalTransfers = $allTransferCheckboxes.length;
            const selectedCount = $checkedTransferCheckboxes.length;

            const $selectedBadge = $(`.selected-transfers-badge[data-commission-id="${commissionId}"]`);
            const $partialBadge = $(`.partial-transfers-badge[data-commission-id="${commissionId}"]`);

            if (isCommissionSelected) {
                if (selectedCount < totalTransfers && totalTransfers > 0) {
                    $partialBadge.text(`${selectedCount}/${totalTransfers} transferów`).show();
                }
            } else {
                if (selectedCount > 0) {
                    $selectedBadge.text(`${selectedCount} ${selectedCount === 1 ? 'transfer' : 'transferów'}`).show();
                }
            }
        });
    }

    function redistributeQuantities(transferId) {
        const $distribution = $(`.sources-distribution[data-transfer-id="${transferId}"]`);
        const $inputs = $distribution.find('.source-qty-input');
        const $errorAlert = $distribution.find('.sources-error');

        const transfer = findTransferById(transferId);
        if (!transfer) return;

        const totalAvailable = transfer.qtyAvailable;
        let externalTotal = 0;

        $inputs.filter('[data-is-main="false"]').each(function() {
            externalTotal += parseFloat($(this).val()) || 0;
        });

        const mainWarehouseQty = totalAvailable - externalTotal;
        $inputs.filter('[data-is-main="true"]').val(Math.max(0, mainWarehouseQty).toFixed(2));

        let allSourcesTotal = 0;
        $inputs.each(function() {
            allSourcesTotal += parseFloat($(this).val()) || 0;
        });

        if (Math.abs(allSourcesTotal - totalAvailable) > 0.01) {
            $errorAlert.show();
            $inputs.addClass('is-invalid');
        } else {
            $errorAlert.hide();
            $inputs.removeClass('is-invalid');
        }
    }

    function findTransferById(transferId) {
        for (let commissionId in cancellationData.transfersByCommission) {
            const transfers = cancellationData.transfersByCommission[commissionId];
            const transfer = transfers.find(t => t.transferId == transferId);
            if (transfer) return transfer;
        }
        return null;
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

        let html = '';

        if (selectedCommissions.size > 0) {
            html += `
            <h6 class="font-weight-bold mb-2">
                <i class="bi bi-file-earmark-x"></i> Zlecenia do anulacji (${selectedCommissions.size}):
            </h6>
            <ul class="mb-3">
        `;
            selectedCommissions.forEach(commId => {
                const commission = cancellationData.commissionsData[commId];
                html += `<li>Zlecenie #${commId}: ${commission.deviceName}</li>`;
            });
            html += '</ul>';
        }

        if (selectedTransfers.size > 0) {
            const groupedTransfers = {};
            const transfersByCommission = {};

            selectedTransfers.forEach(transId => {
                const transfer = findTransferById(transId);
                if (!transfer) return;

                const commissionId = transfer.commissionId;
                const isCommissionSelected = selectedCommissions.has(commissionId);

                const $distribution = $(`.sources-distribution[data-transfer-id="${transId}"]`);

                if ($distribution.length > 0) {
                    $distribution.find('.source-qty-input').each(function(index) {
                        const qty = parseFloat($(this).val()) || 0;
                        if (qty > 0) {
                            const source = transfer.sources[index];
                            const key = `${transfer.componentType}_${transfer.componentId}_${source.warehouseId}`;

                            if (!groupedTransfers[key]) {
                                groupedTransfers[key] = {
                                    componentName: transfer.componentName,
                                    warehouseName: source.warehouseName,
                                    quantity: 0,
                                    isMainWarehouse: source.isMainWarehouse,
                                    commissions: new Set()
                                };
                            }
                            groupedTransfers[key].quantity += qty;
                            if (!isCommissionSelected) {
                                groupedTransfers[key].commissions.add(commissionId);
                            }
                        }
                    });
                } else {
                    transfer.sources.forEach(source => {
                        if (source.quantity > 0) {
                            const key = `${transfer.componentType}_${transfer.componentId}_${source.warehouseId}`;

                            if (!groupedTransfers[key]) {
                                groupedTransfers[key] = {
                                    componentName: transfer.componentName,
                                    warehouseName: source.warehouseName,
                                    quantity: 0,
                                    isMainWarehouse: source.isMainWarehouse,
                                    commissions: new Set()
                                };
                            }
                            groupedTransfers[key].quantity += source.quantity;
                            if (!isCommissionSelected) {
                                groupedTransfers[key].commissions.add(commissionId);
                            }
                        }
                    });
                }
            });

            if (Object.keys(groupedTransfers).length > 0) {
                html += `
                <h6 class="font-weight-bold mb-2">
                    <i class="bi bi-box-arrow-left"></i> Transfery do zwrotu (${Object.keys(groupedTransfers).length}):
                </h6>
                <ul class="mb-0">
            `;

                for (let key in groupedTransfers) {
                    const transfer = groupedTransfers[key];
                    const badgeClass = transfer.isMainWarehouse ? 'badge-primary' : 'badge-info';

                    let commissionIndicator = '';
                    if (transfer.commissions.size > 0) {
                        const commIds = Array.from(transfer.commissions).join(', #');
                        commissionIndicator = ` <span class="badge badge-warning ml-1" title="Z innych zleceń: #${commIds}">
                        <i class="bi bi-link-45deg"></i> #${commIds}
                    </span>`;
                    }

                    html += `
                    <li>
                        <strong>${transfer.componentName}</strong>: 
                        ${transfer.quantity.toFixed(2)} → 
                        <span class="badge ${badgeClass}">${transfer.warehouseName}</span>${commissionIndicator}
                    </li>
                `;
                }

                html += '</ul>';
            }
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

        $.ajax({
            type: 'POST',
            url: COMPONENTS_PATH + '/commissions/cancel-commission.php',
            data: {
                action: 'submit_cancellation',
                selectedCommissions: JSON.stringify([...selectedCommissions]),
                selectedTransfers: JSON.stringify(transfersToSubmit)
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

    $('#cancelCommissionModal').on('hidden.bs.modal', function() {
        cancellationData = null;
        selectedCommissions.clear();
        selectedTransfers.clear();
        $('#groupsList').empty();
        $('#cancellationSummary').hide();
    });
});