$(document).ready(function() {
    // AJAX setup
    const ajaxUrl = COMPONENTS_PATH + "/admin/magazines/edit/magazine-ajax-endpoint.php";

    // Function to show alerts
    function showAlert(message, type = 'success') {
        const alertHtml = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="close" data-dismiss="alert">
                <span aria-hidden="true">&times;</span>
            </button>
        </div>
    `;
        $('#alertContainer').html(alertHtml);

        // Scroll to top to show alert
        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    // Function to send AJAX request
    function sendAjaxRequest(data, successCallback) {
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify(data),
            success: function(result) {
                const response = JSON.parse(result);
                if (response.success) {
                    showAlert(response.message, 'success');
                    if (successCallback) successCallback(response);
                } else {
                    showAlert(response.message, 'danger');
                }
            },
            error: function(xhr, status, error) {
                showAlert('Wystąpił błąd podczas komunikacji z serwerem', 'danger');
                console.error('AJAX Error:', error);
            }
        });
    }

    // Function to handle SUB MAG prefix for type 2 magazines
    function handleSubMagPrefix() {
        const typeId = $('#edit_type_id').val();
        const nameField = $('#edit_sub_magazine_name');
        const formGroup = nameField.closest('.form-group');

        if (typeId == '2') {
            const currentName = nameField.val();
            const match = currentName.match(/^(SUB MAG \d+:)\s*(.*)/);

            if (match) {
                const prefix = match[1];
                const editablePart = match[2];

                // Remove any existing input group structure
                formGroup.find('.input-group').remove();
                formGroup.find('.prefix-display').remove();

                // Create Bootstrap 4 input group with prefix
                const inputGroupHtml = `
                    <div class="input-group">
                        <div class="input-group-prepend">
                            <span class="input-group-text">${prefix}</span>
                        </div>
                        <input type="text" class="form-control" id="edit_sub_magazine_name" 
                               value="${editablePart}" placeholder="Wprowadź nazwę po prefiksie..." 
                               data-original-prefix="${prefix}" required>
                    </div>
                `;

                // Replace the original input with the input group
                nameField.replaceWith(inputGroupHtml);
            }
        } else {
            // Restore normal input for non-type-2 magazines
            const currentValue = nameField.val();
            const prefix = nameField.attr('data-original-prefix');

            if (prefix) {
                // Restore full name if it was a type 2 magazine
                nameField.val(`${prefix} ${currentValue}`);
            }

            // Remove input group and restore normal input
            const inputGroup = formGroup.find('.input-group');
            if (inputGroup.length > 0) {
                const normalInput = `
                    <input type="text" class="form-control" id="edit_sub_magazine_name" 
                           value="${nameField.val()}" required>
                `;
                inputGroup.replaceWith(normalInput);
            }
        }
    }

    // Function to restore full name before submission
    function restoreFullName() {
        const nameField = $('#edit_sub_magazine_name');
        const typeId = $('#edit_type_id').val();

        if (typeId == '2' && nameField.attr('data-original-prefix')) {
            return nameField.val().trim(); // Only the editable part
        }

        // For other types, return the full value
        return nameField.val().trim();
    }

    // Function to show inline error in modal
    function showModalError(message) {
        // Remove existing error if any
        $('#editMagazineModal .modal-error').remove();

        // Add error message at the top of modal body
        const errorHtml = `
            <div class="alert alert-warning modal-error" role="alert">
                <i class="bi bi-exclamation-triangle"></i>
                <strong>Uwaga:</strong> ${message}
            </div>
        `;

        $('#editMagazineModal .modal-body').prepend(errorHtml);

        // Scroll to top of modal
        $('#editMagazineModal .modal-body').animate({ scrollTop: 0 }, 300);
    }

    function updateTableRow(magazineId, users) {
        const tableRow = $(`.edit-magazine-btn[data-id="${magazineId}"]`).closest('tr');
        const statusCell = tableRow.find('td:nth-child(3)');
        const usersCell = tableRow.find('td:nth-child(4)');
        const actionsCell = tableRow.find('td:nth-child(5)');

        const hasUsers = users.length > 0;

        // Update status cell
        const isActive = !tableRow.hasClass('table-secondary');
        if (isActive) {
            if (hasUsers) {
                statusCell.html(''); // Remove "Pusty" badge if users are assigned
            } else {
                statusCell.html('<span class="badge badge-success">Pusty</span>');
            }
        }

        // Update users cell
        if (hasUsers) {
            let usersHtml = '<div class="d-flex flex-wrap">';
            users.forEach((user, index) => {
                usersHtml += `
                    <small class="text-muted mr-2 mb-1">
                        ${user.name} ${user.surname}
                        ${index < users.length - 1 ? ',' : ''}
                    </small>
                `;
            });
            usersHtml += '</div>';
            usersCell.html(usersHtml);
        } else {
            usersCell.html('');
        }

        // Update disable button state
        const disableBtn = actionsCell.find('.toggle-magazine-btn[data-action="disable"]');
        if (disableBtn.length > 0) {
            disableBtn.attr('data-has-users', hasUsers ? 'true' : 'false');
        }
    }

    // Load current users for magazine
    function loadCurrentUsers(magazineId) {
        return $.ajax({
            url: ajaxUrl,
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'get_magazine_users',
                magazine_id: parseInt(magazineId)
            }),
            success: function(result) {
                const response = JSON.parse(result);
                if (response.success) {
                    let usersHtml = '';
                    if (response.users && response.users.length > 0) {
                        response.users.forEach(user => {
                            usersHtml += `
                            <span class="badge badge-light mr-1 mb-1">
                                ${user.name} ${user.surname}
                                <button type="button" class="btn btn-sm p-0 ml-1 text-black unassign-user-btn"
                                        data-user-id="${user.user_id}" style="background: none; border: none;">
                                    <i class="bi bi-x"></i>
                                </button>
                            </span>
                        `;
                        });
                    } else {
                        usersHtml = '<span class="text-muted">Brak przypisanych użytkowników</span>';
                    }

                    // Force update the modal content
                    const currentUsersContainer = $('#current_users_list');
                    currentUsersContainer.html(usersHtml);

                    // Update main table row as well
                    updateTableRow(magazineId, response.users || []);
                } else {
                    showAlert('Błąd podczas ładowania użytkowników: ' + response.message, 'danger');
                }
            },
            error: function(xhr, status, error) {
                showAlert('Nie można załadować listy użytkowników', 'danger');
            }
        });
    }

// Function to show disable magazine modal (always show this one)
    function showToggleMagazineUserModal(id, name, action = 'disable') {
        $('#disable_magazine_id').val(id);
        $('#disable_magazine_name').text(name);
        $('#disable_magazine_action').val(action);

        if (action === 'enable') {
            $('#toggleMagazineUserModal .modal-title').html('<i class="bi bi-check-circle"></i> Włącz Magazyn');
            $('#toggleMagazineUserModal .btn-danger').removeClass('btn-danger').addClass('btn-success').html('<i class="bi bi-check-circle"></i> Włącz Magazyn');

            // Hide only the form controls, but keep the display sections visible
            $('.user-section .form-group, .inventory-section .form-group').hide();
        } else {
            $('#toggleMagazineUserModal .modal-title').html('<i class="bi bi-exclamation-triangle"></i> Wyłącz Magazyn z Przypisanymi Zasobami');
            $('#toggleMagazineUserModal .btn-success').removeClass('btn-success').addClass('btn-danger').html('<i class="bi bi-check-circle"></i> Wyłącz Magazyn');

            // Show all sections and form controls for disable action
            $('.user-section, .inventory-section').show();
            $('.user-section .form-group, .inventory-section .form-group').show();
        }

        // Always load available magazines for transfer (needed for both actions)
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'get_available_magazines',
                exclude_magazine_id: parseInt(id)
            }),
            success: function(result) {
                const response = JSON.parse(result);
                if (response.success && response.magazines) {
                    let optionsHtml = '<option value="">Wybierz magazyn...</option>';
                    response.magazines.forEach(magazine => {
                        const typeLabel = magazine.type_id == 1 ? ' (Główny)' : ' (Zewnętrzny)';
                        optionsHtml += `<option value="${magazine.id}">${magazine.name}${typeLabel}</option>`;
                    });
                    $('#target_magazine_select').html(optionsHtml);
                }
            },
            error: function() {
                $('#target_magazine_select').html('<option value="">Błąd podczas ładowania magazynów</option>');
            }
        });

        // Always load and display assigned users
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'get_magazine_users',
                magazine_id: parseInt(id)
            }),
            success: function(result) {
                const response = JSON.parse(result);
                if (response.success) {
                    const hasUsers = response.users && response.users.length > 0;
                    let usersHtml = '';

                    if (hasUsers) {
                        response.users.forEach(user => {
                            usersHtml += `
                            <span class="badge badge-light mr-1 mb-1">
                                ${user.name} ${user.surname} (${user.email})
                            </span>
                        `;
                        });
                    } else {
                        usersHtml = '<div class="alert alert-info mb-0"><i class="bi bi-info-circle"></i> Brak przypisanych użytkowników do tego magazynu</div>';
                    }

                    $('#assigned_users_display').html(usersHtml);

                    // Enable/disable user action controls based on whether there are users
                    const userControls = $('#user_action_controls');
                    const userInputs = userControls.find('input[name="user_assignment_action"]');
                    const userLabels = userControls.find('label');

                    if (!hasUsers) {
                        userInputs.prop('disabled', true);
                        userLabels.addClass('text-muted');
                        userControls.addClass('text-muted');
                        // Reset to default option when disabling
                        $('#keep_assigned').prop('checked', true);
                        // Hide any warnings
                        $('#disable_users_warning').hide();
                    } else {
                        userInputs.prop('disabled', false);
                        userLabels.removeClass('text-muted');
                        userControls.removeClass('text-muted');
                    }
                }
            },
            error: function() {
                $('#assigned_users_display').html('<span class="text-danger">Błąd podczas ładowania użytkowników</span>');
            }
        });

        // Load and display inventory (existing code)
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'get_magazine_inventory',
                magazine_id: parseInt(id)
            }),
            success: function(result) {
                const response = JSON.parse(result);
                if (response.success) {
                    let inventoryHtml = '';

                    if (!response.inventory || response.inventory.length === 0) {
                        inventoryHtml = '<div class="alert alert-info mb-0"><i class="bi bi-info-circle"></i> Magazyn nie zawiera żadnych produktów</div>';
                        $('#inventory_transfer, #inventory_clear').prop('disabled', true);
                        $('#inventory_transfer').parent().addClass('text-muted');
                        $('#inventory_clear').parent().addClass('text-muted');
                    } else {
                        inventoryHtml = `
                        <div class="mb-2">
                            <strong>Produkty w magazynie (${response.total_items} pozycji):</strong>
                        </div>
                        <div class="table-responsive" style="max-height: 200px; overflow-y: auto;">
                            <table class="table table-sm table-striped">
                                <thead>
                                    <tr>
                                        <th>Nazwa</th>
                                        <th>Typ</th>
                                        <th>Ilość</th>
                                    </tr>
                                </thead>
                                <tbody>
                    `;

                        response.inventory.forEach(item => {
                            const typeLabels = {
                                'parts': 'Części',
                                'smd': 'SMD',
                                'tht': 'THT',
                                'sku': 'SKU'
                            };

                            inventoryHtml += `
                            <tr>
                                <td title="${item.description || ''}">${item.name}</td>
                                <td><span class="badge badge-secondary">${typeLabels[item.type] || item.type}</span></td>
                                <td class="text-right">${parseFloat(item.total_quantity).toLocaleString()}</td>
                            </tr>
                        `;
                        });

                        inventoryHtml += `
                                </tbody>
                            </table>
                        </div>
                    `;

                        $('#inventory_transfer, #inventory_clear').prop('disabled', false);
                        $('#inventory_transfer').parent().removeClass('text-muted');
                        $('#inventory_clear').parent().removeClass('text-muted');
                    }

                    $('#magazine_inventory_display').html(inventoryHtml);
                }
            },
            error: function() {
                $('#magazine_inventory_display').html('<span class="text-danger">Błąd podczas ładowania inwentarza</span>');
            }
        });

        $('#toggleMagazineUserModal').modal('show');
    }

    // Event handler for inventory action radio buttons
    $(document).on('change', 'input[name="inventory_action"]', function() {
        const selectedAction = $(this).val();
        const targetSelection = $('#target_magazine_selection');

        if (selectedAction === 'transfer') {
            targetSelection.show();
            $('#target_magazine_select').prop('required', true);
        } else {
            targetSelection.hide();
            $('#target_magazine_select').prop('required', false);
        }
    });

    // Add magazine form submission with auto-prefix for type 2
    $('#addMagazineForm').on('submit', function(e) {
        e.preventDefault();

        const typeId = $('#type_id').val();
        let name;

        if (typeId == '2') {
            // For type 2, get only the editable part (similar to edit modal)
            const nameField = $('#sub_magazine_name');
            if (nameField.attr('data-original-prefix')) {
                name = nameField.val().trim(); // Only the editable part
            } else {
                name = nameField.val().trim(); // Fallback to full value
            }
        } else {
            name = $('#sub_magazine_name').val().trim();
        }

        if (!name || !typeId) {
            showAlert('Wszystkie pola są wymagane!', 'warning');
            return;
        }

        sendAjaxRequest({
            action: 'add_magazine',
            sub_magazine_name: name,
            type_id: typeId
        }, function() {
            // Clear form and reload page to show new magazine
            $('#addMagazineForm')[0].reset();

            // Reset the form to normal input state
            const formGroup = $('#sub_magazine_name').closest('.form-group');
            const inputGroup = formGroup.find('.input-group');
            if (inputGroup.length > 0) {
                const normalInput = `
                <input type="text" class="form-control" id="sub_magazine_name" required>
            `;
                inputGroup.replaceWith(normalInput);
            }

            setTimeout(() => location.reload(), 1500);
        });
    });

    // Edit magazine form submission
    $('#editMagazineForm').on('submit', function(e) {
        e.preventDefault();

        // Remove any existing modal errors first
        $('#editMagazineModal .modal-error').remove();

        const id = $('#edit_magazine_id').val();
        const typeId = $('#edit_type_id').val();
        const fullName = restoreFullName(); // Get the full name with prefix if needed
        const selectedUserId = $('#assign_user_select').val(); // Check if user is selected

        if (!fullName || !typeId) {
            showModalError('Wszystkie pola są wymagane!');
            return;
        }

        // Check if user has selected someone to assign but hasn't clicked assign button
        if (selectedUserId && selectedUserId !== '') {
            showModalError('Masz wybranego użytkownika do przypisania, ale nie kliknąłeś przycisku "Przypisz Użytkownika".');
            return;
        }

        // Proceed with normal save
        proceedWithSave(id, fullName, typeId);
    });

    // Extract save logic into separate function to avoid duplication
    function proceedWithSave(id, fullName, typeId) {
        sendAjaxRequest({
            action: 'edit_magazine',
            sub_magazine_id: id,
            sub_magazine_name: fullName,
            type_id: typeId
        }, function() {
            $('#editMagazineModal').modal('hide');
            setTimeout(() => location.reload(), 1500);
        });
    }

    // Form submission handler for disable magazine with user and inventory choice
    $('#disableMagazineUserForm').on('submit', function(e) {
        e.preventDefault();

        const magazineId = $('#disable_magazine_id').val();
        const action = $('#disable_magazine_action').val();

        if (action === 'enable') {
            // Simple enable action
            sendAjaxRequest({
                action: 'toggle_magazine_status',
                sub_magazine_id: magazineId,
                is_active: true
            }, function() {
                $('#toggleMagazineUserModal').modal('hide');
                setTimeout(() => location.reload(), 1500);
            });
            return;
        }

        // Existing disable logic
        const userAction = $('input[name="user_assignment_action"]:checked').val();
        const inventoryAction = $('input[name="inventory_action"]:checked').val();
        const targetMagazineId = $('#target_magazine_select').val();

        // Validation
        if (inventoryAction === 'transfer' && !targetMagazineId) {
            showAlert('Wybierz magazyn docelowy dla transferu inwentarza', 'warning');
            return;
        }

        // Show confirmation for destructive inventory actions
        if (inventoryAction === 'clear') {
            if (!confirm('Czy na pewno chcesz wyczyścić cały inwentarz? Ta operacja jest nieodwracalna!')) {
                return;
            }
        }

        const requestData = {
            action: 'disable_magazine_with_inventory_choice',
            sub_magazine_id: magazineId,
            user_action: userAction,
            inventory_action: inventoryAction
        };

        // Add target magazine ID only if transfer is selected
        if (inventoryAction === 'transfer') {
            requestData.target_magazine_id = parseInt(targetMagazineId);
        }

        sendAjaxRequest(requestData, function() {
            $('#toggleMagazineUserModal').modal('hide');
            setTimeout(() => location.reload(), 1500);
        });
    });

    $(document).on('change', 'input[name="user_assignment_action"]', function() {
        const selectedAction = $(this).val();
        const warningDiv = $('#disable_users_warning');

        if (selectedAction === 'disable') {
            warningDiv.slideDown();
        } else {
            warningDiv.slideUp();
        }
    });

    $(document).on('change', 'input[name="inventory_action"]', function() {
        const selectedAction = $(this).val();
        const warningDiv = $('#clear_inventory_warning');
        const targetSelection = $('#target_magazine_selection');

        if (selectedAction === 'clear') {
            warningDiv.slideDown();
            targetSelection.hide();
            $('#target_magazine_select').prop('required', false);
        } else if (selectedAction === 'transfer') {
            warningDiv.slideUp();
            targetSelection.show();
            $('#target_magazine_select').prop('required', true);
        } else {
            warningDiv.slideUp();
            targetSelection.hide();
            $('#target_magazine_select').prop('required', false);
        }
    });

    $('#toggleMagazineUserModal').on('hidden.bs.modal', function() {
        // Reset radio buttons to default
        $('#keep_assigned').prop('checked', true);
        $('#inventory_nothing').prop('checked', true);

        // Hide warnings and target selection
        $('#disable_users_warning').hide();
        $('#clear_inventory_warning').hide();
        $('#target_magazine_selection').hide();
        $('#target_magazine_select').prop('required', false);

        // Reset user controls
        const userControls = $('#user_action_controls');
        const userInputs = userControls.find('input[name="user_assignment_action"]');
        const userLabels = userControls.find('label');

        userInputs.prop('disabled', false);
        userLabels.removeClass('text-muted');
        userControls.removeClass('text-muted');

        // Reset inventory controls
        const inventoryInputs = $('input[name="inventory_action"]');
        const inventoryLabels = inventoryInputs.closest('.form-check').find('label');

        inventoryInputs.prop('disabled', false);
        inventoryLabels.removeClass('text-muted');

        // Clear content
        $('#assigned_users_display').empty();
        $('#magazine_inventory_display').html('<div class="text-center"><i class="bi bi-hourglass-split"></i> Ładowanie inwentarza...</div>');
    });

    // Toggle magazine form submission (for simple enable/disable without user handling)
    $('#toggleMagazineForm').on('submit', function(e) {
        e.preventDefault();

        const id = $('#toggle_magazine_id').val();
        const action = $('#toggle_action').val();
        const isActive = action === 'enable';

        sendAjaxRequest({
            action: 'toggle_magazine_status',
            sub_magazine_id: id,
            is_active: isActive
        }, function() {
            $('#toggleMagazineModal').modal('hide');
            setTimeout(() => location.reload(), 1500);
        });
    });

    // Edit magazine button click handler (using event delegation)
    $(document).on('click', '.edit-magazine-btn', function() {
        const id = $(this).data('id');
        const name = $(this).data('name');
        const typeId = $(this).data('type-id');

        $('#edit_magazine_id').val(id);
        $('#edit_sub_magazine_name').val(name);
        $('#edit_type_id').val(typeId);

        // Handle SUB MAG prefix for type 2 magazines
        handleSubMagPrefix();

        // Load current users for this magazine before showing modal
        loadCurrentUsers(id).done(function() {
            $('#editMagazineModal').modal('show');

            // Initialize bootstrap-select after modal is shown
            $('#editMagazineModal').on('shown.bs.modal', function() {
                $('.selectpicker').selectpicker('refresh');
            });
        }).fail(function() {
            // Show modal even if user loading fails
            $('#editMagazineModal').modal('show');

            // Initialize bootstrap-select after modal is shown
            $('#editMagazineModal').on('shown.bs.modal', function() {
                $('.selectpicker').selectpicker('refresh');
            });
        });
    });

    $('#editMagazineModal').on('hidden.bs.modal', function() {
        $('#editMagazineForm')[0].reset();
        // Clean up input group and restore normal input structure
        const formGroup = $('#edit_sub_magazine_name').closest('.form-group');
        const inputGroup = formGroup.find('.input-group');
        if (inputGroup.length > 0) {
            const normalInput = `
            <input type="text" class="form-control" id="edit_sub_magazine_name" required>
        `;
            inputGroup.replaceWith(normalInput);
        }

        // Refresh selectpicker and remove any modal errors
        $('.selectpicker').selectpicker('refresh');
        $('#editMagazineModal .modal-error').remove();
    });

    // Toggle magazine button click handler (using event delegation)
    $(document).on('click', '.toggle-magazine-btn', function() {
        const id = $(this).data('id');
        const name = $(this).data('name');
        const action = $(this).data('action');

        console.log('Toggle button clicked:', { id, name, action }); // Debug log

        // Always show the same modal for both enable and disable
        showToggleMagazineUserModal(id, name, action);
    });

    $('#assign_user_btn').on('click', function() {
        const userId = $('#assign_user_select').val();
        const magazineId = $('#edit_magazine_id').val();

        // Remove any existing modal errors
        $('#editMagazineModal .modal-error').remove();

        if (!userId) {
            showModalError('Wybierz użytkownika do przypisania');
            return;
        }

        // Get the selected user's current magazine before assignment
        const selectedOption = $('#assign_user_select option:selected');
        const currentMagazineId = selectedOption.data('current-magazine');

        sendAjaxRequest({
            action: 'assign_user',
            user_id: parseInt(userId),
            magazine_id: parseInt(magazineId)
        }, function(response) {
            $('#assign_user_select').val('');
            $('.selectpicker').selectpicker('refresh');

            // Add a small delay to ensure database operation completed
            setTimeout(() => {
                // Refresh current magazine users
                loadCurrentUsers(magazineId);

                // If user was previously assigned to another magazine, refresh that table row too
                if (currentMagazineId && currentMagazineId != magazineId) {
                    updateTableRowAfterUserTransfer(currentMagazineId, userId);
                }

                // IMPORTANT: Also refresh ALL other visible magazine rows to ensure consistency
                // This is needed because the user might have been in a different magazine
                // that's not tracked by the select option's data-current-magazine
                refreshAllTableRows();
            }, 100);
        });
    });

    // Function to handle SUB MAG prefix for add magazine form
    function handleAddMagazinePrefix() {
        const typeId = $('#type_id').val();
        const nameField = $('#sub_magazine_name');
        const formGroup = nameField.closest('.form-group');

        if (typeId == '2') {
            // Get next SUB MAG number via AJAX
            $.ajax({
                url: ajaxUrl,
                type: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    action: 'get_next_submag_number'
                }),
                success: function(result) {
                    const response = JSON.parse(result);
                    if (response.success) {
                        const prefix = `SUB MAG ${response.next_number}:`;

                        // Remove any existing input group structure
                        formGroup.find('.input-group').remove();

                        // Create Bootstrap 4 input group with prefix
                        const inputGroupHtml = `
                        <div class="input-group">
                            <div class="input-group-prepend">
                                <span class="input-group-text">${prefix}</span>
                            </div>
                            <input type="text" class="form-control" id="sub_magazine_name" 
                                   placeholder="Wprowadź nazwę po prefiksie..." 
                                   data-original-prefix="${prefix}" required>
                        </div>
                    `;

                        // Replace the original input with the input group
                        nameField.replaceWith(inputGroupHtml);
                    }
                },
                error: function() {
                    console.error('Failed to get next SUB MAG number');
                }
            });
        } else {
            // Restore normal input for non-type-2 magazines
            const inputGroup = formGroup.find('.input-group');
            if (inputGroup.length > 0) {
                const normalInput = `
                <input type="text" class="form-control" id="sub_magazine_name" required>
            `;
                inputGroup.replaceWith(normalInput);
            }
        }
    }

    // Add event listener for type selection change
    $('#type_id').on('change', function() {
        handleAddMagazinePrefix();
    });

    // Function to refresh all visible magazine rows
    function refreshAllTableRows() {
        // Find all edit buttons and refresh their corresponding rows
        $('.edit-magazine-btn').each(function() {
            const magazineId = $(this).data('id');
            // Skip the currently open magazine since we already updated it
            const currentMagazineId = $('#edit_magazine_id').val();

            if (magazineId != currentMagazineId) {
                // Refresh this magazine's user data silently
                $.ajax({
                    url: ajaxUrl,
                    type: 'POST',
                    contentType: 'application/json',
                    data: JSON.stringify({
                        action: 'get_magazine_users',
                        magazine_id: parseInt(magazineId)
                    }),
                    success: function(result) {
                        const response = JSON.parse(result);
                        if (response.success) {
                            updateTableRow(magazineId, response.users || []);
                        }
                    },
                    error: function() {
                        // Fail silently for background updates
                    }
                });
            }
        });
    }

    function updateTableRowAfterUserTransfer(magazineId, transferredUserId) {
        // Find the table row for the magazine
        const tableRow = $(`.edit-magazine-btn[data-id="${magazineId}"]`).closest('tr');
        const usersCell = tableRow.find('td:nth-child(4)');
        const statusCell = tableRow.find('td:nth-child(3)');
        const actionsCell = tableRow.find('td:nth-child(5)');

        // Get current users display and remove the transferred user
        const currentUsersHtml = usersCell.html();

        // Make an AJAX call to get fresh user data for this magazine
        $.ajax({
            url: ajaxUrl,
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                action: 'get_magazine_users',
                magazine_id: parseInt(magazineId)
            }),
            success: function(result) {
                const response = JSON.parse(result);
                if (response.success) {
                    updateTableRow(magazineId, response.users || []);
                }
            },
            error: function() {
                console.error('Failed to refresh magazine users after transfer');
            }
        });
    }

    // Unassign user button click handler (delegated event)
    $(document).on('click', '.unassign-user-btn', function() {
        const userId = $(this).data('user-id');
        const magazineId = $('#edit_magazine_id').val();

        sendAjaxRequest({
            action: 'unassign_user',
            user_id: parseInt(userId)
        }, function(response) {
            // Add a small delay to ensure database operation completed
            setTimeout(() => {
                loadCurrentUsers(magazineId);
            }, 100);
        });
    });

    // Clear user select button click handler
    $('#clearUserSelect').on('click', function() {
        $('#assign_user_select').val('');
        $('.selectpicker').selectpicker('refresh');

        // Remove any existing modal errors
        $('#editMagazineModal .modal-error').remove();
    });

    // Clear forms when modals are hidden
    $('#editMagazineModal').on('hidden.bs.modal', function() {
        $('#editMagazineForm')[0].reset();
        // Clean up input group and restore normal input structure
        const formGroup = $('#edit_sub_magazine_name').closest('.form-group');
        const inputGroup = formGroup.find('.input-group');
        if (inputGroup.length > 0) {
            const normalInput = `
                <input type="text" class="form-control" id="edit_sub_magazine_name" required>
            `;
            inputGroup.replaceWith(normalInput);
        }
    });

    $('#toggleMagazineModal').on('hidden.bs.modal', function() {
        $('#toggle_warnings').hide();
        $('#confirmToggleBtn').prop('disabled', false).removeClass('disabled');
    });
});