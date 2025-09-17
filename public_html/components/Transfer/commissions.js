const transferCommissionTableRow_template = $('script[data-template="transferCommissionTableRow_template"]').text().split(/\$\{(.+?)\}/g);

const commissions = [];
const existingCommissions = [];

$(document).ready(function() {
    $("#list__priority").val(0).selectpicker('refresh');

    $("#addCommission").click(function() {
        const commissionValues = getCommissionRowValues();
        const $TBody = $('#commissionTBody');
        commissions.push(commissionValues);
        commissionValues['key'] = commissions.length-1;
        addCommissionRow(commissionValues, $TBody);
        clearAddCommissionFields();
    });

    let commissionToDelete = null;

    $('body').on('click', '.removeCommissionRow', function() {
        const key = $(this).data('key');
        const $commissionRow = $(this).closest('tr');

        // Check if we're in transfer mode (after commissions have been submitted)
        const isInTransferMode = $('#transferTableContainer').is(':visible');

        if (isInTransferMode) {
            // Show confirmation modal in transfer mode
            commissionToDelete = key;
            $("#deleteCommissionConfirmModal").modal('show');
            return;
        } else {
            // In creation mode, delete immediately
            delete commissions[key];
            $commissionRow.remove();
        }
    });

    // Handle confirmed deletion
    $("#confirmDeleteCommission").click(function() {
        if (commissionToDelete === null) return;

        const key = commissionToDelete;

        // Remove all components that belong to this commission
        const componentsToRemove = [];
        components.forEach((component, index) => {
            if (component && component.commissionKey == key) {
                componentsToRemove.push(index);
            }
        });

        // Remove components from array and DOM
        componentsToRemove.forEach(componentIndex => {
            delete components[componentIndex];
            $(`.commission-component[data-key="${componentIndex}"]`).remove();
        });

        // Remove commission header and related rows
        $(`.commission-header[data-commission-key="${key}"]`).remove();
        $(`.add-component-row[data-commission-key="${key}"]`).remove();

        // Update global summary
        updateGlobalSummary();

        // Remove from commissions array
        delete commissions[key];

        // Remove the commission row from the creation table (if it exists)
        $(`.removeCommissionRow[data-key="${key}"]`).closest('tr').remove();

        // Hide modal and reset
        $("#deleteCommissionConfirmModal").modal('hide');
        commissionToDelete = null;
    });
    // Reset commission to delete when modal is cancelled
    $("#deleteCommissionConfirmModal").on('hidden.bs.modal', function() {
        commissionToDelete = null;
    });

    $("#submitCommissions").click(function() {
        $("#submitCommissions, #moreOptionsCard").hide();
        $("#commissionTable").removeClass('show');
        const isNoTransfer = $(this).data('noTransfer');
        if(isNoTransfer === true) {
            $("#submitTransfer").click();
            return;
        }
        $(".commissionSubmitSpinner").show();

        setTimeout(() => {
            const transferFrom = $("#transferFrom").val();
            const transferTo = $("#transferTo").val();
            const [componentsByCommission, foundExistingCommissions] = getComponentsForCommissions(commissions, transferFrom, transferTo);

            if (foundExistingCommissions.length > 0) {
                $(".commissionSubmitSpinner").hide();
                existingCommissions.push(...foundExistingCommissions)
                const items = foundExistingCommissions
                    .map(ec => `<li>ID: ${ec[0]} - ${ec[1]} <small>(stworzono: ${ec[2]})</small></li>`)
                    .join('');
                const $alert = $(`<div class="alert-existing-commission alert alert-warning alert-dismissible fade show" role="alert">
                          <strong>Wykryto duplikacje zlecenia:</strong>
                          <ul>${items}</ul>
                          <strong>Powyższe pozycje zostaną rozszerzone o odpowiednią ilość.</strong>
                        </div>
                      `);
                $("#transferTableContainer").before($alert);
            }

            // Add bulk toggle button
            addToggleAllButton();

            // Process grouped components
            const $TBody = $('#transferTBody');
            let componentIndex = 0;

            for (const commissionKey in componentsByCommission) {
                const commissionGroup = componentsByCommission[commissionKey];
                const commission = commissions[commissionKey]; // Get original commission data

                // Add clickable commission header row
                const commissionHeaderRow = `
                        <tr class="commission-header" data-commission-key="${commissionKey}"
                            style="box-shadow: -7px 0px 0px 0px ${commissionGroup.commissionInfo.priorityColor};">
                            <td colspan="6" class="font-weight-bold">
                                <div class="commission-details">
                                    <i class="bi bi-chevron-down commission-toggle-icon mr-2"></i>
                                    Zlecenie: ${commissionGroup.commissionInfo.deviceName} - ${commissionGroup.commissionInfo.receivers}
                                    ${commission.laminate && commission.laminate !== '' ? `<br><small class="text-muted">Laminat: ${commission.laminate}</small>` : ''}
                                    ${commission.version && commission.version !== 'n/d' ? `<br><small class="text-muted">Wersja: ${commission.version}</small>` : ''}
                                    <br><small class="text-muted">Ilość: ${commission.quantity}</small>
                                    <span class="badge badge-light ml-2">${commissionGroup.components.length} komponentów</span>
                                </div>
                                <div class="commission-summary" style="display: none;">
                                    <i class="bi bi-chevron-right commission-toggle-icon mr-2"></i>
                                    Zlecenie: ${commissionGroup.commissionInfo.deviceName} - ${commissionGroup.commissionInfo.receivers}
                                    <span class="badge badge-light ml-2">${commissionGroup.components.length} komponentów</span>
                                </div>
                            </td>
                        </tr>
                        `;
                $TBody.append(commissionHeaderRow);

                // Get component values for this commission's components
                const componentValues = getComponentValues(commissionGroup.components, transferFrom, transferTo);
                components.push(...componentValues);

                // Add component rows
                componentValues.forEach(componentValue => {
                    componentValue['key'] = componentIndex;
                    componentValue['commissionKey'] = commissionKey;
                    addComponentsRow(componentValue, $TBody);
                    componentIndex++;
                });

                // Add the "add component" button for this commission
                const addButtonTemplate = $('script[data-template="addCommissionComponentRow_template"]').text().split(/\$\{(.+?)\}/g);
                const addButtonRow = addButtonTemplate.map(render({commissionKey: commissionKey})).join('');
                $TBody.append(addButtonRow);
            }

            // Add global summary ONCE after all commissions are processed
            const globalSummaryHeaderTemplate = $('script[data-template="globalSummaryHeader_template"]').text().split(/\$\{(.+?)\}/g);
            const globalSummaryHeaderRow = globalSummaryHeaderTemplate.map(render({})).join('');
            $TBody.append(globalSummaryHeaderRow);

            // Create and add global summary
            updateGlobalSummary();

            $(".commissionSubmitSpinner").hide();
            $("#transferTableContainer").show();
        }, 0);
    });

    // GLOBAL SUMMARY FUNCTIONALITY
    let $currentAddGlobalForm = null;
    window.$currentAddGlobalForm = null;

    // Handle clicking the global summary header (collapse/expand)
    $(document).on('click', '.global-summary-header', function() {
        const $header = $(this);
        const $components = $('.global-summary-component');
        const $toggleIcon = $header.find('.summary-toggle-icon');

        if ($header.hasClass('expanded')) {
            // Collapse summary
            $components.hide();
            $header.removeClass('expanded');
            $toggleIcon.removeClass('bi-chevron-down').addClass('bi-chevron-right');
        } else {
            // Expand summary and collapse ALL commissions
            $components.show();
            $header.addClass('expanded');
            $toggleIcon.removeClass('bi-chevron-right').addClass('bi-chevron-down');

            // Collapse all commissions when expanding summary
            $('.commission-header').addClass('collapsed');
            $('.commission-component:not(.manual-component)').addClass('hidden');
            $('.add-component-row').addClass('hidden');
            $('.commission-toggle-icon').removeClass('bi-chevron-down').addClass('bi-chevron-right');
            $('.commission-summary').show();
            $('.commission-details').hide();
        }
    });

    // Handle clicking the global "add component" button
    $(document).on('click', '.add-global-component', function(e) {
        e.stopPropagation(); // Prevent header click

        // Hide any existing form
        if ($currentAddGlobalForm) {
            $currentAddGlobalForm.remove();
            $currentAddGlobalForm = null;
            window.$currentAddGlobalForm = null;
        }

        // Create and show the form
        const addGlobalFormTemplate = $('script[data-template="addGlobalComponentForm_template"]').text().split(/\$\{(.+?)\}/g);
        const formRow = addGlobalFormTemplate.map(render({})).join('');
        const $formRow = $(formRow);

        // Insert the form at the end of the summary section
        $('#transferTBody').append($formRow);
        $formRow.show();
        $currentAddGlobalForm = $formRow;
        window.$currentAddGlobalForm = $formRow;

        // Expand the summary if it's collapsed
        if (!$('.global-summary-header').hasClass('expanded')) {
            $('.global-summary-header').click();
        }

        // Initialize selectpickers for the new form
        $formRow.find('.selectpicker').selectpicker();

        // Hide the add button
        $('.add-global-component').hide();
    });

    // Handle component type selection for global components
    $(document).on('change', '#globalMagazineComponent', function() {
        let option = $(this).val();
        $("#globalListComponents").empty();
        $('#list__' + option + '_hidden option').clone().appendTo('#globalListComponents');
        $("#globalListComponents").prop("disabled", false);
        $("#globalListComponents").selectpicker('refresh');
    });

    // Handle adding the component to the global summary
    $(document).on('click', '#addGlobalComponentBtn', function() {
        const componentType = $("#globalMagazineComponent").val();
        const componentId = $("#globalListComponents").val();
        const transferQty = $("#globalQtyComponent").val();

        if (!validateGlobalComponentForm(componentType, componentId, transferQty)) {
            $(this).popover({
                content: "Uzupełnij wszystkie dane",
                trigger: 'manual',
                placement: 'top'
            }).popover('show');
            setTimeout(() => $(this).popover('hide'), 2000);
            return;
        }

        // Check for duplicates in global components (commissionKey = null)
        let isDuplicate = false;
        components.forEach(component => {
            if (component &&
                component.commissionKey === null &&
                component.type === componentType &&
                component.componentId === componentId) {
                isDuplicate = true;
            }
        });

        if (isDuplicate) {
            $("#duplicateComponentModal").modal('show');
            return;
        }

        // Create the component object (no commission association)
        const component = {
            type: componentType,
            componentId: componentId,
            neededForCommissionQty: '<span class="text-light">n/d</span>',
            transferQty: transferQty,
            commissionKey: null // This indicates it's not part of a commission
        };

        // Get component values and add to the transfer table
        const transferFrom = $("#transferFrom").val();
        const transferTo = $("#transferTo").val();
        const componentValues = getComponentValues([component], transferFrom, transferTo);

        const pushedKey = components.push(componentValues[0]) - 1;
        componentValues[0]['key'] = pushedKey;
        componentValues[0]['commissionKey'] = null;

        // Add the row to the table with blue line indicator, positioned before the form row
        const $newRow = $(transferComponentsTableRow_template.map(render(componentValues[0])).join(''));
        $newRow.addClass('manual-component'); // Add class for blue line styling
        $currentAddGlobalForm.before($newRow);

        // Update the global summary
        updateGlobalSummary();

        // Hide the form and show the add button again
        cancelAddingGlobalComponent();
    });

    // Handle canceling the global component addition
    $(document).on('click', '#cancelGlobalComponentBtn', function() {
        cancelAddingGlobalComponent();
    });

    function cancelAddingGlobalComponent() {
        if ($currentAddGlobalForm) {
            $currentAddGlobalForm.remove();
            $currentAddGlobalForm = null;
            window.$currentAddGlobalForm = null;
        }
        $('.add-global-component').show();
    }

    function validateGlobalComponentForm(componentType, componentId, transferQty) {
        return componentType && componentId && transferQty && transferQty > 0;
    }

    // COMMISSION COMPONENT ADDITION FUNCTIONALITY
    let currentCommissionKey = null;
    let $currentAddComponentForm = null;

    // Handle clicking the "+" button for adding components to a commission
    $(document).on('click', '.add-commission-component', function() {
        currentCommissionKey = $(this).data('commission-key');

        // Hide any existing form
        if ($currentAddComponentForm) {
            $currentAddComponentForm.hide();
        }

        // Create and show the form for this commission
        const addComponentFormTemplate = $('script[data-template="addCommissionComponentForm_template"]').text().split(/\$\{(.+?)\}/g);
        const formRow = addComponentFormTemplate.map(render({commissionKey: currentCommissionKey})).join('');
        const $formRow = $(formRow);

        // Insert the form after the current row and show it
        $(this).closest('tr').after($formRow);
        $formRow.show();
        $currentAddComponentForm = $formRow;

        // Initialize selectpickers for the new form
        $formRow.find('.selectpicker').selectpicker();

        // Hide all add-component buttons
        $('.add-commission-component').closest('tr').hide();
    });

    // Handle component type selection for commission components
    $(document).on('change', '#commissionMagazineComponent', function() {
        let option = $(this).val();
        $("#commissionListComponents").empty();
        $('#list__' + option + '_hidden option').clone().appendTo('#commissionListComponents');
        $("#commissionListComponents").prop("disabled", false);
        $("#commissionListComponents").selectpicker('refresh');
    });

    // Handle adding the component to the commission
    $(document).on('click', '#addCommissionComponentBtn', function() {
        const componentType = $("#commissionMagazineComponent").val();
        const componentId = $("#commissionListComponents").val();
        const transferQty = $("#commissionQtyComponent").val();

        if (!validateCommissionComponentForm(componentType, componentId, transferQty)) {
            $(this).popover({
                content: "Uzupełnij wszystkie dane",
                trigger: 'manual',
                placement: 'top'
            }).popover('show');
            setTimeout(() => $(this).popover('hide'), 2000);
            return;
        }

        // Check for duplicates in the current commission
        let isDuplicate = false;
        components.forEach(component => {
            if (component &&
                component.commissionKey == currentCommissionKey &&
                component.type === componentType &&
                component.componentId === componentId) {
                isDuplicate = true;
            }
        });

        if (isDuplicate) {
            $("#duplicateComponentModal").modal('show');
            return;
        }

        // Create the component object
        const component = {
            type: componentType,
            componentId: componentId,
            neededForCommissionQty: '<span class="text-light">n/d</span>',
            transferQty: transferQty,
            commissionKey: currentCommissionKey
        };

        // Get component values and add to the transfer table
        const transferFrom = $("#transferFrom").val();
        const transferTo = $("#transferTo").val();
        const componentValues = getComponentValues([component], transferFrom, transferTo);

        const pushedKey = components.push(componentValues[0]) - 1;
        componentValues[0]['key'] = pushedKey;
        componentValues[0]['commissionKey'] = currentCommissionKey;

        // Add the row to the table with manual component styling, positioned before the form row
        const $newRow = $(transferComponentsTableRow_template.map(render(componentValues[0])).join(''));
        $newRow.addClass('manual-component'); // Add class for blue line styling and special collapse behavior
        $currentAddComponentForm.before($newRow);

        // Hide the form and show all add-component buttons again
        cancelAddingCommissionComponent();
    });

    // Handle canceling the component addition
    $(document).on('click', '#cancelCommissionComponentBtn', function() {
        cancelAddingCommissionComponent();
    });

    function cancelAddingCommissionComponent() {
        if ($currentAddComponentForm) {
            $currentAddComponentForm.remove();
            $currentAddComponentForm = null;
        }
        $('.add-component-row').show();
        currentCommissionKey = null;
    }

    function validateCommissionComponentForm(componentType, componentId, transferQty) {
        return componentType && componentId && transferQty && transferQty > 0;
    }

    // COLLAPSE/EXPAND FUNCTIONALITY
    // Custom collapse functionality
    $(document).on('click', '.commission-header', function() {
        const commissionKey = $(this).data('commission-key');
        const $header = $(this);
        const $components = $(`.commission-component[data-commission-key="${commissionKey}"]:not(.manual-component)`);
        const $addButtonRow = $(`.add-component-row[data-commission-key="${commissionKey}"]`);
        const $summary = $header.find('.commission-summary');
        const $details = $header.find('.commission-details');

        if ($header.hasClass('collapsed')) {
            // Expand this commission and collapse summary
            $components.removeClass('hidden');
            $addButtonRow.removeClass('hidden');
            $header.removeClass('collapsed');
            $summary.hide();
            $details.show();

            // Collapse the global summary when expanding any commission
            const $globalSummary = $('.global-summary-header');
            const $globalComponents = $('.global-summary-component');
            const $globalToggleIcon = $globalSummary.find('.summary-toggle-icon');

            if ($globalSummary.hasClass('expanded')) {
                $globalComponents.hide();
                $globalSummary.removeClass('expanded');
                $globalToggleIcon.removeClass('bi-chevron-down').addClass('bi-chevron-right');
            }
        } else {
            // Collapse this commission
            $components.addClass('hidden');
            $addButtonRow.addClass('hidden');
            $header.addClass('collapsed');
            $summary.show();
            $details.hide();

            // Hide any open forms for this commission
            $(`.add-component-form[data-commission-key="${commissionKey}"]`).hide();
        }
    });
});

function addToggleAllButton() {
    $("#transferTableButtons").show();
}

function getComponentsForCommissions(commissions, transferFrom, transferTo) {
    const data = { commissions: commissions, transferFrom: transferFrom, transferTo: transferTo };
    let result = [];
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/transfer/get-components-for-commissions.php",
        async: false,
        data: data,
        success: function (data) {
            result = JSON.parse(data);
        }
    });
    return result;
}

function getCommissionRowValues() {
    const colors = ['none', 'green', 'yellow', 'red'];
    const values = {
        receiversIds: $('#userSelect').val(),
        receivers: $('#userSelect option:selected').toArray().map(item => item.text).join(', '),
        priorityId: $('#list__priority').val(),
        priority: $('#list__priority option:selected').text(),
        priorityColor: colors[$('#list__priority').val()],
        deviceType: $('#deviceType').val(),
        deviceId: $('#list__device').val(),
        deviceName: $('#list__device option:selected').text(),
        deviceDescription: $('#list__device option:selected').attr('data-subtext'),
        version: $('#version').val(),
        quantity: $('#quantity').val()
    };

    if(values['deviceType'] === 'smd') {
        values['laminateId'] = $('#list__laminate').val();
        values['laminate'] = $('#list__laminate option:selected').text();
    }

    for (const key in values) {
        if (typeof values[key] === 'string') {
            values[key] = values[key].trim();
        }
        if (values[key] === '' || values[key] === null || values[key] === undefined) {
            throw new Error(`The field ${key} is required and cannot be empty.`);
        }
    }

    return values;
}

function addCommissionRow(commissionValues, $TBody) {
    const $tr = $(transferCommissionTableRow_template.map(render(commissionValues)).join(''));
    $TBody.append($tr);
}

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

// Modal handlers
$("#dontCreateCommission").click(function() {
    $("#transferWithoutCommissionModal").modal('show');
});

$("#createCommission").click(function() {
    const transferFrom = $("#transferFrom").val();
    const transferTo = $("#transferTo").val();
    if(transferFrom === transferTo) {
        $("#commissionWithoutTransferModal").modal('show');
        return;
    }
    $("#moreOptionsCard, #commissionTableContainer").show();
    $("#transferFrom, #transferTo").prop('disabled', true).selectpicker('refresh');
    $("#createCommissionCard").hide();
});

$("#commissionNoTransfer").click(function() {
    $("#commissionWithoutTransferModal").modal('hide');
    $("#transferFrom, #transferTo").prop('disabled', true).selectpicker('refresh');
    $("#createCommissionCard").hide();
    $("#moreOptionsCard, #commissionTableContainer").show();
    $("#submitCommissions").data('noTransfer', true);
});

$("#transferNoCommission").click(function() {
    $("#transferWithoutCommissionModal").modal('hide');
    $("#transferFrom, #transferTo").prop('disabled', true).selectpicker('refresh');
    $("#createCommissionCard").hide();
    $("#transferTableContainer").show();
});

// Device selection logic
$('select#deviceType').change(function(){
    const deviceType = this.value;
    const usersSelected = $('#userSelect').val();
    const usedDevices = getUsedDevices(usersSelected, deviceType);
    const usedDevicesCommon = getCommonElements(usedDevices);
    let $deviceList = $("#list__device");
    $deviceList.empty();
    usedDevicesCommon.forEach(function(id) {
        $('#list__'+deviceType+'_hidden option[value="' + id + '"]').clone().appendTo($deviceList);
    });
    $deviceList.prop('disabled', false);
    $("#version, #list__laminate").empty();
    $('#version, #list__laminate, #list__device').selectpicker('refresh');

    $("#laminateSelect").hide();
    $("#versionSelect").show();
    if(deviceType === 'smd') {
        $("#laminateSelect").show();
    } else if(deviceType === 'sku') {
        $("#versionSelect").hide();
    }
});

function getUsedDevices(usersSelected, deviceType) {
    let usedDevices = [];
    usersSelected.forEach(function(userId) {
        const usedDevicesUser = JSON.parse($('#userSelect option[value="' + userId + '"]').attr('data-used-'+deviceType));
        usedDevices.push(usedDevicesUser);
    });
    return usedDevices;
}

function getCommonElements(arrays) {
    if (arrays.length === 0) return [];
    return arrays[0].filter(item =>
        arrays.every(array => array.includes(item))
    );
}

$("#list__laminate").change(function(){
    let possibleVersions = $("#list__laminate option:selected").data("jsonversions");
    generateVersionSelect(possibleVersions);
    $("#version").prop('disabled', false);
    $("#version").selectpicker('refresh');
});

$("#list__device").change(function(){
    $("#version").empty();
    $("#list__laminate").empty();
    if($("#deviceType").val() == 'smd') {
        $("#list__laminate").prop('disabled', false);
        $("#version").prop('disabled', true);
        let possibleLaminates = $("#list__device option:selected").data("jsonlaminates");
        generateLaminateSelect(possibleLaminates);
    } else if($("#deviceType").val() == 'tht') {
        $("#version").prop('disabled', false);
        let possibleVersions = $("#list__device option:selected").data("jsonversions");
        generateVersionSelect(possibleVersions);
    } else {
        $("#version").selectpicker('destroy');
        $("#version").html("<option value=\"n/d\" selected>n/d</option>");
        $("#version").prop('disabled', false);
    }
    $("#version, #list__laminate").selectpicker('refresh');
});

function generateVersionSelect(possibleVersions){
    $("#version").empty();
    if(Object.keys(possibleVersions).length == 1) {
        if(possibleVersions[0] == null) {
            $("#version").selectpicker('destroy');
            $("#version").html("<option value=\"n/d\" selected>n/d</option>");
            $("#version").prop('disabled', false);
            $("#version").selectpicker('refresh');
            return;
        }
        let version_id = Object.keys(possibleVersions)[0];
        let version = possibleVersions[version_id][0];
        let option = "<option value='"+version+"' selected>"+version+"</option>";
        $("#version").append(option);
        $("#version").selectpicker('destroy');
    } else {
        for (let version_id in possibleVersions) {
            let version = possibleVersions[version_id][0];
            let option = "<option value='"+version+"'>"+version+"</option>";
            $("#version").append(option);
        }
    }
    $("#version").selectpicker('refresh');
}

function generateLaminateSelect(possibleLaminates){
    if(Object.keys(possibleLaminates).length == 1) {
        let laminate_id = Object.keys(possibleLaminates)[0];
        let laminate_name = possibleLaminates[laminate_id][0];
        let versions = JSON.stringify(possibleLaminates[laminate_id]['versions']);
        let option = "<option value='"+laminate_id+"' data-jsonversions='"+versions+"' selected>"+laminate_name+"</option>";
        $("#list__laminate").append(option);
        $("#list__laminate").selectpicker('destroy');
        $("#version").prop('disabled', false);
        generateVersionSelect(possibleLaminates[laminate_id]['versions']);
    } else {
        for (let laminate_id in possibleLaminates) {
            let laminate_name = possibleLaminates[laminate_id][0];
            let versions = JSON.stringify(possibleLaminates[laminate_id]['versions']);
            let option = "<option value='"+laminate_id+"' data-jsonversions='"+versions+"'>"+laminate_name+"</option>";
            $("#list__laminate").append(option);
        }
    }
    $("#list__laminate").selectpicker('refresh');
}

$("#userSelect").change(function(){
    const usersSelected = $(this).val();
    $("#versionSelect, #laminateSelect").hide();
    $("#version, #list__laminate").prop('disabled', true);
    $("#list__device").empty();
    const anyUserSelected = usersSelected.length;
    $("#deviceType").val('').prop('disabled', !anyUserSelected);
    const deviceTypes = ['sku', 'tht', 'smd'];
    deviceTypes.forEach(function(deviceType) {
        const usedDevices = getUsedDevices(usersSelected, deviceType);
        const usedDevicesCommon = getCommonElements(usedDevices);
        const commonDevicesFound = usedDevicesCommon.length !== 0;
        $("#deviceType").find('option[value="'+deviceType+'"]').prop('disabled', !commonDevicesFound);
    });

    $("#deviceType, #list__device").selectpicker('refresh');
});

function clearAddCommissionFields() {
    $("#userSelect, #list__device, #version, #list__laminate, #quantity, #deviceType").val('');
    $("#list__priority").val(0);
    $("#versionSelect, #laminateSelect").hide();
    $("#list__device, #version, #list__laminate").empty();
    $("#deviceType, #list__device").prop('disabled', true);
    $("#userSelect, #list__priority, #list__device, #version, #list__laminate, #deviceType").selectpicker('refresh');
}