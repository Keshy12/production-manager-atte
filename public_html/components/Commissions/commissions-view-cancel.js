/**
 * commissions-view-cancel.js
 * Handles commission cancellation logic with transfer groups
 */

class CommissionCancellation {
    constructor() {
        this.commissionId = null;
        this.transferGroups = [];
        this.selectedItems = new Map(); // Map<transferGroupId, Map<commissionId, Set<componentIds>>>
        this.init();
    }

    init() {
        this.attachEventHandlers();
    }

    /**
     * Attach event handlers
     */
    attachEventHandlers() {
        // Open cancel modal
        $(document).on('click', '.cancelCommission', (e) => {
            const commissionId = $(e.currentTarget).data('id');
            this.openCancelModal(commissionId);
        });

        // Confirm cancellation
        $(document).on('click', '#confirmCancellation', () => {
            this.confirmCancellation();
        });

        // Commission checkbox change
        $(document).on('change', '.commission-checkbox', (e) => {
            this.handleCommissionCheckboxChange(e);
        });

        // Component checkbox change
        $(document).on('change', '.component-checkbox', (e) => {
            this.handleComponentCheckboxChange(e);
            this.updateSummary();
        });

        // Transfer group header click (expand/collapse)
        $(document).on('click', '.transfer-group-header', (e) => {
            if (!$(e.target).is('input')) {
                $(e.currentTarget).find('.collapse-icon').toggleClass('fa-chevron-down fa-chevron-up');
            }
        });
    }

    /**
     * Open cancel modal and load transfer groups
     */
    async openCancelModal(commissionId) {
        this.commissionId = commissionId;
        this.selectedItems.clear();

        $('#cancelCommissionModal').modal('show');
        this.showLoading();

        try {
            const response = await $.ajax({
                type: 'POST',
                url: COMPONENTS_PATH + '/commissions/get-commission-groups.php',
                data: { commissionId: commissionId },
                dataType: 'json'  // Tell jQuery to parse JSON automatically
            });

            // Response is already parsed by jQuery
            const result = typeof response === 'string' ? JSON.parse(response) : response;

            console.log('Transfer groups response:', result); // Debug log

            if (result.success) {
                this.transferGroups = result.data;
                this.renderTransferGroups();
            } else {
                this.showError('Błąd podczas ładowania danych: ' + (result.message || 'Nieznany błąd'));
            }
        } catch (error) {
            console.error('Error loading transfer groups:', error); // Debug log
            this.showError('Błąd podczas ładowania danych transferu: ' + error.message);
        } finally {
            this.hideLoading();
        }
    }

    /**
     * Render transfer groups in modal
     */
    renderTransferGroups() {
        const container = $('#groupsList');
        container.empty();

        if (!this.transferGroups || this.transferGroups.length === 0) {
            container.html(`
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> Nie znaleziono transferów dla tego zlecenia.
                </div>
            `);
            return;
        }

        this.transferGroups.forEach((group, index) => {
            const groupHtml = this.renderTransferGroup(group, index);
            container.append(groupHtml);
        });

        // Initialize Bootstrap collapse
        $('.collapse').collapse({ toggle: false });
    }

    /**
     * Render single transfer group
     */
    renderTransferGroup(group, index) {
        const groupId = group.id;
        const timestamp = new Date(group.timestamp).toLocaleString('pl-PL');
        const isExpanded = index === 0 ? 'show' : '';

        let html = `
            <div class="card mb-2">
                <div class="card-header py-2 transfer-group-header" 
                     data-toggle="collapse" 
                     data-target="#group-${groupId}"
                     style="cursor: pointer;">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <i class="bi bi-chevron-down collapse-icon"></i>
                            <strong>Transfer #${groupId}</strong>
                            <small class="text-muted ml-2">
                                <i class="bi bi-calendar"></i> ${timestamp}
                            </small>
                        </div>
                        ${group.notes ? `<small class="text-muted">${group.notes}</small>` : ''}
                    </div>
                </div>
                <div id="group-${groupId}" class="collapse ${isExpanded}">
                    <div class="card-body p-0">
        `;

        // Render all commissions in this group
        group.allCommissions.forEach(commission => {
            html += this.renderCommission(commission, groupId);
        });

        html += `
                    </div>
                </div>
            </div>
        `;

        return html;
    }

    /**
     * Render single commission within a transfer group
     */
    renderCommission(commission, groupId) {
        const isCurrentCommission = commission.isCurrentCommission;
        const isManual = commission.isManualComponents || false;
        const commissionId = commission.commissionId || 'manual';

        let badgeHtml = '';
        let highlightClass = '';

        // Extension badge logic
        if (commission.extensionBadge === 'requires_all') {
            badgeHtml = '<span class="badge badge-info ml-2">Wymaga wszystkich rozszerzeń</span>';
        } else if (commission.extensionBadge === 'partial_only') {
            badgeHtml = '<span class="badge badge-danger ml-2">Tylko rozszerzenie</span>';
        }

        if (isCurrentCommission) {
            highlightClass = 'border-primary';
        }

        let html = `
            <div class="card m-2 ${highlightClass}" data-commission-id="${commissionId}" data-group-id="${groupId}">
                <div class="card-header py-2 bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <div class="custom-control custom-checkbox">
                            <input type="checkbox" 
                                   class="custom-control-input commission-checkbox" 
                                   id="comm-${groupId}-${commissionId}"
                                   data-group-id="${groupId}"
                                   data-commission-id="${commissionId}"
                                   ${isManual ? 'disabled' : ''}>
                            <label class="custom-control-label" for="comm-${groupId}-${commissionId}">
                                <strong>${commission.deviceName}</strong>
                                ${commission.version ? `<small class="text-muted ml-1">v${commission.version}</small>` : ''}
                                ${commission.laminate ? `<small class="text-muted ml-1">${commission.laminate}</small>` : ''}
                                ${badgeHtml}
                            </label>
                        </div>
                        ${isCurrentCommission ? '<span class="badge badge-primary">Bieżące zlecenie</span>' : ''}
                    </div>
                    ${commission.receivers && commission.receivers.length > 0 ? `
                        <div class="mt-1">
                            <small class="text-muted">
                                <i class="bi bi-person"></i> ${commission.receivers.join(', ')}
                            </small>
                        </div>
                    ` : ''}
                </div>
                <div class="card-body p-2">
        `;

        // Render transfers (components)
        if (commission.transfers && commission.transfers.length > 0) {
            html += '<div class="table-responsive">';
            html += '<table class="table table-sm table-hover mb-0">';
            html += `
                <thead class="thead-light">
                    <tr>
                        <th width="30"></th>
                        <th>Komponent</th>
                        <th>Ilość</th>
                        <th>Z magazynu</th>
                        <th>Do magazynu</th>
                    </tr>
                </thead>
                <tbody>
            `;

            commission.transfers.forEach((transfer, idx) => {
                const componentId = `${groupId}-${commissionId}-${idx}`;
                const sources = transfer.sources.join(', ');

                html += `
                    <tr>
                        <td>
                            <div class="custom-control custom-checkbox">
                                <input type="checkbox" 
                                       class="custom-control-input component-checkbox" 
                                       id="comp-${componentId}"
                                       data-group-id="${groupId}"
                                       data-commission-id="${commissionId}"
                                       data-component-index="${idx}"
                                       data-component-name="${transfer.componentName}"
                                       data-quantity="${transfer.quantity}">
                                <label class="custom-control-label" for="comp-${componentId}"></label>
                            </div>
                        </td>
                        <td>
                            <strong>${transfer.componentName}</strong>
                            ${transfer.componentDescription ? `<br><small class="text-muted">${transfer.componentDescription}</small>` : ''}
                        </td>
                        <td><span class="badge badge-secondary">${transfer.quantity}</span></td>
                        <td><small>${sources}</small></td>
                        <td><small>${transfer.destination}</small></td>
                    </tr>
                `;
            });

            html += '</tbody></table></div>';
        } else {
            html += '<p class="text-muted mb-0"><i>Brak komponentów do wyświetlenia</i></p>';
        }

        html += `
                </div>
            </div>
        `;

        return html;
    }

    /**
     * Handle commission checkbox change
     */
    handleCommissionCheckboxChange(e) {
        const checkbox = $(e.currentTarget);
        const groupId = checkbox.data('group-id');
        const commissionId = checkbox.data('commission-id');
        const isChecked = checkbox.is(':checked');

        // Find all component checkboxes for this commission in this group
        const componentCheckboxes = $(`.component-checkbox[data-group-id="${groupId}"][data-commission-id="${commissionId}"]`);

        // Check/uncheck all components
        componentCheckboxes.prop('checked', isChecked);

        // Update summary
        this.updateSummary();
    }

    /**
     * Handle component checkbox change
     */
    handleComponentCheckboxChange(e) {
        const checkbox = $(e.currentTarget);
        const groupId = checkbox.data('group-id');
        const commissionId = checkbox.data('commission-id');

        // Check if all components for this commission are selected
        const allComponentCheckboxes = $(`.component-checkbox[data-group-id="${groupId}"][data-commission-id="${commissionId}"]`);
        const allChecked = allComponentCheckboxes.length > 0 &&
            allComponentCheckboxes.filter(':checked').length === allComponentCheckboxes.length;

        // Update commission checkbox
        $(`#comm-${groupId}-${commissionId}`).prop('checked', allChecked);
    }

    /**
     * Update cancellation summary
     */
    updateSummary() {
        const checkedComponents = $('.component-checkbox:checked');
        const selectedCommissions = new Set();
        const componentsByCommission = new Map();

        // Collect selected components
        checkedComponents.each((idx, checkbox) => {
            const $checkbox = $(checkbox);
            const groupId = $checkbox.data('group-id');
            const commissionId = $checkbox.data('commission-id');
            const componentName = $checkbox.data('component-name');
            const quantity = $checkbox.data('quantity');

            const key = `${commissionId}`;
            if (!componentsByCommission.has(key)) {
                componentsByCommission.set(key, []);
            }

            componentsByCommission.get(key).push({
                name: componentName,
                quantity: quantity,
                groupId: groupId
            });

            selectedCommissions.add(commissionId);
        });

        // Build summary HTML
        let summaryHtml = '';

        if (selectedCommissions.size === 0) {
            $('#cancellationSummary').hide();
            $('#confirmCancellation').prop('disabled', true);
            return;
        }

        summaryHtml += '<div class="mb-3">';
        summaryHtml += `<h6>Wybrane zlecenia: <span class="badge badge-primary">${selectedCommissions.size}</span></h6>`;
        summaryHtml += `<h6>Wybrane komponenty: <span class="badge badge-secondary">${checkedComponents.length}</span></h6>`;
        summaryHtml += '</div>';

        // Check which commissions will be completely cancelled
        const commissionsToCancel = [];
        selectedCommissions.forEach(commissionId => {
            if (commissionId === 'manual') return;

            const allInstancesSelected = this.areAllInstancesSelected(commissionId);
            if (allInstancesSelected) {
                commissionsToCancel.push(commissionId);

                // Highlight commission cards
                $(`.card[data-commission-id="${commissionId}"]`).addClass('border-danger bg-light-danger');
            } else {
                $(`.card[data-commission-id="${commissionId}"]`).removeClass('border-danger bg-light-danger');
            }
        });

        if (commissionsToCancel.length > 0) {
            summaryHtml += '<div class="alert alert-danger">';
            summaryHtml += '<strong><i class="bi bi-exclamation-triangle"></i> Następujące zlecenia zostaną całkowicie anulowane:</strong>';
            summaryHtml += '<ul class="mb-0 mt-2">';
            commissionsToCancel.forEach(commId => {
                const commission = this.findCommission(commId);
                if (commission) {
                    summaryHtml += `<li>${commission.deviceName} (ID: ${commId})</li>`;
                }
            });
            summaryHtml += '</ul></div>';
        }

        // List selected components
        summaryHtml += '<div class="mt-3"><h6>Komponenty do zwrotu:</h6>';
        summaryHtml += '<div class="table-responsive" style="max-height: 300px;">';
        summaryHtml += '<table class="table table-sm table-striped">';
        summaryHtml += '<thead><tr><th>Komponent</th><th>Ilość</th><th>Grupa</th></tr></thead><tbody>';

        componentsByCommission.forEach((components, commissionId) => {
            components.forEach(comp => {
                summaryHtml += `
                    <tr>
                        <td>${comp.name}</td>
                        <td><span class="badge badge-secondary">${comp.quantity}</span></td>
                        <td>Transfer #${comp.groupId}</td>
                    </tr>
                `;
            });
        });

        summaryHtml += '</tbody></table></div></div>';

        $('#summaryContent').html(summaryHtml);
        $('#cancellationSummary').show();
        $('#confirmCancellation').prop('disabled', false);
    }

    /**
     * Check if all instances of a commission are selected
     */
    areAllInstancesSelected(commissionId) {
        let totalInstances = 0;
        let selectedInstances = 0;

        this.transferGroups.forEach(group => {
            group.allCommissions.forEach(commission => {
                if (commission.commissionId == commissionId) {
                    totalInstances++;

                    // Check if all components in this instance are selected
                    const allComponents = $(`.component-checkbox[data-group-id="${group.id}"][data-commission-id="${commissionId}"]`);
                    const selectedComponents = allComponents.filter(':checked');

                    if (allComponents.length > 0 && selectedComponents.length === allComponents.length) {
                        selectedInstances++;
                    }
                }
            });
        });

        return totalInstances > 0 && totalInstances === selectedInstances;
    }

    /**
     * Find commission by ID
     */
    findCommission(commissionId) {
        for (const group of this.transferGroups) {
            for (const commission of group.allCommissions) {
                if (commission.commissionId == commissionId) {
                    return commission;
                }
            }
        }
        return null;
    }

    /**
     * Confirm and execute cancellation
     */
    async confirmCancellation() {
        if (!confirm('Czy na pewno chcesz anulować wybrane transfery? Ta operacja jest nieodwracalna.')) {
            return;
        }

        const checkedComponents = $('.component-checkbox:checked');
        const cancellationData = [];

        checkedComponents.each((idx, checkbox) => {
            const $checkbox = $(checkbox);
            cancellationData.push({
                groupId: $checkbox.data('group-id'),
                commissionId: $checkbox.data('commission-id'),
                componentIndex: $checkbox.data('component-index'),
                componentName: $checkbox.data('component-name'),
                quantity: $checkbox.data('quantity')
            });
        });

        this.showLoading();

        try {
            const response = await $.ajax({
                type: 'POST',
                url: COMPONENTS_PATH + '/commissions/cancel-commission.php',
                data: {
                    commissionId: this.commissionId,
                    cancellationData: JSON.stringify(cancellationData)
                },
                dataType: 'json'  // Tell jQuery to parse JSON automatically
            });

            // Response is already parsed by jQuery
            const result = typeof response === 'string' ? JSON.parse(response) : response;

            console.log('Cancellation response:', result); // Debug log

            if (result.success || result[0]) {
                $('#cancelCommissionModal').modal('hide');
                showSuccessMessage('Zlecenie zostało anulowane pomyślnie');

                // Refresh the commissions view
                if (window.commissionsRenderer) {
                    window.commissionsRenderer.render();
                }
            } else {
                this.showError('Błąd podczas anulowania zlecenia: ' + (result.message || result[1] || 'Nieznany błąd'));
            }
        } catch (error) {
            console.error('Error cancelling commission:', error); // Debug log
            this.showError('Błąd podczas anulowania zlecenia: ' + error.message);
        } finally {
            this.hideLoading();
        }
    }

    /**
     * Show loading overlay
     */
    showLoading() {
        $('#cancelModalOverlay').show();
        $('#cancelModalOverlay').addClass("d-flex");
    }

    /**
     * Hide loading overlay
     */
    hideLoading() {
        $('#cancelModalOverlay').hide();
        $('#cancelModalOverlay').removeClass("d-flex");
    }

    /**
     * Show error message in modal
     */
    showError(message) {
        const alertHtml = `
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="close" data-dismiss="alert">
                    <span>&times;</span>
                </button>
            </div>
        `;

        $('#groupsList').prepend(alertHtml);

        setTimeout(() => {
            $('.alert-danger').alert('close');
        }, 5000);
    }
}

// Initialize cancellation handler
$(document).ready(() => {
    window.commissionCancellation = new CommissionCancellation();
});