$(document).ready(function() {
    // State tracking for modal views
    let currentEditCommissionId = null;

    // Helper: switch between selection and form views in edit modal
    function switchEditStep(step) {
        if (step === 'selection') {
            $('#editSelectionView').show();
            $('#editFormView').hide();
        } else {
            $('#editSelectionView').hide();
            $('#editFormView').show();
        }
    }

    // Helper: pre-fill edit form with commission data
    function prefillEditForm(commission) {
        const quantity = commission.qty || commission.quantity || commission.quantityOriginal || 0;
        const qtyProduced = commission.qtyProduced || commission.qty_produced || 0;
        const priority = commission.priority || 'standard';
        const submagId = commission.submagId || commission.sub_magazine_id || 0;
        const receivers = commission.receivers || '';

        // Store commission ID on submit button
        $("#editCommissionSubmit").data('commission-id', commission.id);
        currentEditCommissionId = commission.id;

        // Populate quantity field
        $("#editQuantity").val(quantity);
        // Set min attribute to qty_produced so validation works
        $("#editQuantity").attr('min', qtyProduced);

        // Populate priority — use selectpicker('val') + refresh
        $("#editPriority").selectpicker('val', priority);
        $("#editPriority").selectpicker('refresh');

        // Populate subcontractors (receivers) dropdown
        $("#editSubcontractors").empty();
        $("#editSubcontractors").append('<option value="">Wybierz...</option>');
        $("#user option").each(function() {
            if ($(this).attr("data-submag-id") == submagId) {
                $(this).clone().appendTo('#editSubcontractors');
            }
        });

        // Refresh so the newly-added options are rendered
        $("#editSubcontractors").selectpicker('refresh');

        // Set selected receivers — split by comma if string
        let receiversArray = [];
        if (receivers && typeof receivers === 'string' && receivers.trim() !== '') {
            receiversArray = receivers.split(',').map(s => s.trim()).filter(s => s !== '');
        }

        $("#editSubcontractors").selectpicker('val', receiversArray);
        $("#editSubcontractors").selectpicker('refresh');

        // Clear any previous validation state
        $("#editQuantity").removeClass('is-invalid');
    }

    // Helper: render state badge
    function getStateBadgeHtml(state, isCancelled) {
        if (isCancelled || state === 'cancelled') {
            return '<span class="badge badge-danger"><i class="bi bi-x-circle"></i> Anulowane</span>';
        }
        switch (state) {
            case 'completed':
                return '<span class="badge badge-success"><i class="bi bi-check-circle"></i> Zakończone</span>';
            case 'returned':
                return '<span class="badge badge-warning"><i class="bi bi-arrow-return-left"></i> Zwrócone</span>';
            case 'active':
                return '<span class="badge badge-primary"><i class="bi bi-play-circle"></i> Aktywne</span>';
            default:
                return '<span class="badge badge-secondary">' + (state || 'Nieznany') + '</span>';
        }
    }

    // Helper: render priority badge
    function getPriorityBadgeHtml(priority) {
        switch (priority) {
            case 'critical':
                return '<span class="badge badge-danger"><i class="bi bi-exclamation-triangle-fill"></i> Krytyczny</span>';
            case 'urgent':
                return '<span class="badge badge-warning"><i class="bi bi-lightning-fill"></i> Pilny</span>';
            case 'standard':
                return '<span class="badge badge-success"><i class="bi bi-check-circle-fill"></i> Standardowy</span>';
            default:
                return '<span class="badge badge-secondary"><i class="bi bi-dash-circle"></i> Brak</span>';
        }
    }

    // Helper: render a single commission row in the selection list
    function renderSelectionItem(commission) {
        const isCancelled = commission.isCancelled || commission.is_cancelled || false;
        const qty = commission.qty || commission.quantity || 0;
        const qtyProduced = commission.qtyProduced || commission.qty_produced || 0;
        const deviceName = commission.deviceName || commission.device_name || '—';
        const itemClass = isCancelled ? 'list-group-item-secondary text-muted' : '';
        const rowOpacity = isCancelled ? 'opacity-60' : '';
        const commissionJson = JSON.stringify(commission).replace(/'/g, "&#39;");

        return `
            <li class="list-group-item d-flex align-items-center justify-content-between py-2 ${itemClass} ${rowOpacity}">
                <div class="mr-3">
                    <strong>#${commission.id}</strong>
                    <span class="text-muted ml-1">${deviceName}</span>
                </div>
                <div class="text-muted small mr-3">
                    Ilość: <strong class="text-body">${qty}</strong>
                    <span class="mx-1">·</span>
                    Wyprodukowano: <strong class="text-body">${qtyProduced}</strong>
                </div>
                <button type="button"
                        class="btn btn-sm btn-outline-primary editSelectOneBtn"
                        data-commission='${commissionJson}'
                        title="Edytuj to zlecenie">
                    <i class="bi bi-pencil"></i>
                </button>
            </li>
        `;
    }

    // Handle editCommission button click
    $(document).on('click', '.editCommission', function(e) {
        e.preventDefault();

        const $btn = $(this);
        const $card = $btn.closest('.card');
        const commissionId = $btn.data('id');
        const potentialCount = parseInt($btn.data('potential-count')) || 1;
        const groupedIdsAttr = $card.attr('data-grouped-ids');

        // Determine if this is a grouped commission
        const hasGroupedIds = groupedIdsAttr && groupedIdsAttr.trim() !== '';
        const groupedCount = hasGroupedIds ? groupedIdsAttr.split(',').filter(id => id.trim() !== '').length : 0;
        const isGrouped = hasGroupedIds && groupedCount > 1;

        if (!isGrouped) {
            // Single commission — direct edit flow (existing behavior)
            const quantity = $btn.data('quantity');
            const qtyProduced = $btn.data('qty-produced');
            const priority = $btn.data('priority');
            const submagId = $btn.data('submag-id');
            // Use .attr() (raw string) — jQuery .data() auto-parses "5" -> Number 5
            const receivers = $btn.attr('data-receivers') || '';

            const commissionData = {
                id: commissionId,
                qty: quantity,
                qtyProduced: qtyProduced,
                priority: priority,
                submagId: submagId,
                receivers: receivers
            };

            // Reset to form view
            switchEditStep('form');
            // Pre-fill and open
            prefillEditForm(commissionData);
            $("#editCommissionModal").modal('show');
        } else {
            // Grouped commission — show selection view first
            switchEditStep('selection');
            $("#editCommissionModal").modal('show');

            // Show loading state
            $('#editSelectionList').html(`
                <div class="text-center py-4">
                    <div class="spinner-border text-primary" role="status" style="width: 2rem; height: 2rem;">
                        <span class="sr-only">Ładowanie...</span>
                    </div>
                    <p class="text-muted mt-2 mb-0">Ładowanie zleceń...</p>
                </div>
            `);

            // Fetch grouped commissions data
            $.ajax({
                type: "POST",
                url: COMPONENTS_PATH + '/commissions/get-commission-data.php',
                data: {
                    action: 'get_edit_data',
                    groupedIds: groupedIdsAttr
                },
                success: function(response) {
                    // Normalize response — handle both { commissions: [...] } and { success: true, commissions: [...] }
                    let commissions = [];
                    if (Array.isArray(response)) {
                        commissions = response;
                    } else if (response && Array.isArray(response.commissions)) {
                        commissions = response.commissions;
                    } else if (response && response.success && Array.isArray(response.data)) {
                        // Handle alternative shape
                        commissions = response.data;
                    } else if (response && typeof response === 'object') {
                        // Try to find any array property that looks like commissions
                        for (const key in response) {
                            if (Array.isArray(response[key])) {
                                commissions = response[key];
                                break;
                            }
                        }
                    }

                    if (commissions.length === 0) {
                        $('#editSelectionList').html(`
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i> Nie znaleziono zleceń do edycji.
                            </div>
                        `);
                        return;
                    }

                    let listHtml = '';
                    commissions.forEach(function(commission) {
                        listHtml += renderSelectionItem(commission);
                    });

                    $('#editSelectionList').html(listHtml);
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    $('#editSelectionList').html(`
                        <div class="alert alert-danger">
                            <i class="bi bi-x-circle"></i> Błąd podczas ładowania zleceń. Spróbuj ponownie.
                        </div>
                    `);
                    setTimeout(function() {
                        $("#editCommissionModal").modal('hide');
                    }, 2000);
                }
            });
        }
    });

    // Handle click on "Edytuj to" button in selection list (delegated)
    $(document).on('click', '.editSelectOneBtn', function(e) {
        e.preventDefault();
        e.stopPropagation();

        // jQuery's .data() auto-parses JSON-looking strings, so commissionJson
        // may already be an object. Handle both string and object shapes.
        let commission = $(this).data('commission');
        if (typeof commission === 'string') {
            try {
                commission = JSON.parse(commission.replace(/&#39;/g, '"'));
            } catch (err) {
                console.error('Failed to parse commission data:', err);
                showErrorMessage('Błąd: nie udało się wczytać danych zlecenia');
                return;
            }
        }
        if (!commission || typeof commission !== 'object') {
            showErrorMessage('Błąd: nie udało się wczytać danych zlecenia');
            return;
        }

        // Switch to form view and pre-fill
        switchEditStep('form');
        prefillEditForm(commission);
    });

    // Reset to form view when modal is closed (so next open starts fresh)
    $("#editCommissionModal").on('hidden.bs.modal', function() {
        switchEditStep('form');
        currentEditCommissionId = null;
    });

    // Handle editCommissionSubmit click — send AJAX request
    $("#editCommissionSubmit").click(function() {
        const commissionId = $(this).data('commission-id');
        const quantity = parseInt($("#editQuantity").val(), 10);
        const qtyProduced = parseInt($("#editQuantity").attr('min'), 10) || 0;
        const priority = $("#editPriority").val();
        const subcontractors = $("#editSubcontractors").val() || [];

        // Validate quantity
        const $quantityInput = $("#editQuantity");
        $quantityInput.removeClass('is-invalid');

        if (isNaN(quantity) || quantity < 1) {
            $quantityInput.addClass('is-invalid');
            return;
        }

        if (quantity < qtyProduced) {
            $quantityInput.addClass('is-invalid');
            return;
        }

        // Check if quantity is a valid integer
        if (!Number.isInteger(quantity)) {
            $quantityInput.addClass('is-invalid');
            return;
        }

        // Validate at least one receiver
        if (!Array.isArray(subcontractors) || subcontractors.length === 0) {
            showErrorMessage('Wybierz co najmniej jednego zleceniobiorcę.');
            $("#editSubcontractors").closest('.form-group').addClass('has-error');
            return;
        }
        $("#editSubcontractors").closest('.form-group').removeClass('has-error');

        // Build request data
        const requestData = {
            id: commissionId,
            quantity: quantity,
            priority: priority,
            receivers: subcontractors.join(',')
        };

        $.ajax({
            type: "POST",
            url: COMPONENTS_PATH + '/commissions/edit-commission.php',
            data: requestData,
            success: function(response) {
                // Backend returns JSON_FORCE_OBJECT of [$wasSuccessful, $errorMessage]
                // -> {"0": true|false, "1": "message"}
                if (response && response[0] === true) {
                    $("#editCommissionModal").modal('hide');
                    showSuccessMessage('Zlecenie zostało zaktualizowane');
                    if (typeof refreshCommissions === 'function') {
                        refreshCommissions();
                    }
                } else {
                    const errorMsg = (response && response[1]) ? response[1] : 'Nie udało się zaktualizować zlecenia';
                    showErrorMessage('Błąd: ' + errorMsg);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', error);
                showErrorMessage('Błąd podczas aktualizacji zlecenia');
            }
        });
    });

    // Clear validation state when quantity input changes
    $("#editQuantity").on('input', function() {
        $(this).removeClass('is-invalid');
    });
});
