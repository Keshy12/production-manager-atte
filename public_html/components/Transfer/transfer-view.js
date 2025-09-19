const transferComponentsTableRow_template = $('script[data-template="transferComponentsTableRow_template"]').text().split(/\$\{(.+?)\}/g);

const components = [];
const transferSources = {}; // Przechowuje źródła dla każdego komponentu
let availableWarehouses = [];

let hasUnsavedSourceChanges = false;

function getTotalTransferQty(componentKey) {
    const sources = transferSources[componentKey] || [];
    return sources.reduce((sum, source) => sum + (parseInt(source.quantity) || 0), 0);
}

function updateQuantityDisplay(componentKey) {
    const totalQty = getTotalTransferQty(componentKey);
    $(`.transferQty[data-key="${componentKey}"]`).val(totalQty);

    // Keep transferQty in components array for backend compatibility
    if (components[componentKey]) {
        components[componentKey].transferQty = totalQty;
    }

    // Update global summary
    updateGlobalSummary();
}

function syncComponentQuantityFromSources(componentKey) {
    if (components[componentKey]) {
        components[componentKey].transferQty = getTotalTransferQty(componentKey);
    }
}

$(document).ready(function() {
    const currentMagazine = $("#transferFrom").attr('data-default-value');
    $("#transferFrom").val(currentMagazine);
    $('[data-toggle="popover"]').popover();
    $(".selectpicker").selectpicker('refresh');

    // Initialize tooltips
    $('[data-toggle="tooltip"]').tooltip();

    // Load available warehouses for multi-source selection
    loadAvailableWarehouses();

    // Handle help button click
    $(document).on('click', '#showHelpModal', function() {
        $("#submitExplanationModal").modal('show');
    });

    // Handle quantity changes to update summary
    $(document).on('input change', '.transferQty', function() {
        const $input = $(this);
        const key = $input.data('key');
        const newQty = parseInt($input.val()) || 0;

        // Update sources to match the manually entered quantity
        transferSources[key] = [{
            warehouseId: $("#transferFrom").val(),
            quantity: newQty
        }];

        // Sync component data and update display
        syncComponentQuantityFromSources(key);
    });

    // Handle Edit button for component sources
    $(document).on('click', '.edit-component-sources', function() {
        const key = $(this).data('key');
        const $row = $(this).closest('tr');
        const component = components[key];

        if($row.next('.source-details-row').length) {
            // If already expanded, collapse
            $row.next('.source-details-row').remove();
            $(this).html('<i class="bi bi-gear"></i>').removeClass('btn-warning').addClass('btn-light');
            hasUnsavedSourceChanges = false;
            return;
        }

        if ($('.source-details-row').length > 0) {
            // Another source editor is open - show warning modal
            $("#unsavedSourceChangesModal").modal('show');
            return;
        }

        hasUnsavedSourceChanges = true;

        // Before opening, collapse global summary if it's expanded
        const $globalSummary = $('.global-summary-header');
        if ($globalSummary.hasClass('expanded')) {
            const $globalComponents = $('.global-summary-component');
            const $sourceDetails = $('.source-details-summary');
            const $globalToggleIcon = $globalSummary.find('.summary-toggle-icon');

            $globalComponents.hide();
            $sourceDetails.hide();
            $globalSummary.removeClass('expanded');
            $globalToggleIcon.removeClass('bi-chevron-down').addClass('bi-chevron-right');
        }

        // Create source details row
        const sourceDetailsHtml = createSourceDetailsRow(key, component);
        $row.after(sourceDetailsHtml);
        $(this).html('<i class="bi bi-x"></i>').removeClass('btn-light').addClass('btn-warning');

        // Initialize selectpickers and set their values
        const $newRow = $row.next('.source-details-row');
        $newRow.find('.selectpicker').selectpicker();

        // Force refresh to ensure selected values are displayed
        $newRow.find('.selectpicker').selectpicker('refresh');
    });

    // Handle adding new source
    $(document).on('click', '.add-source', function() {
        const key = $(this).data('key');
        const $container = $(this).siblings('.sources-container');
        const newIndex = $container.find('.source-item').length;

        const sourceItemHtml = createSourceItemHtml(key, newIndex, {
            warehouseId: $("#transferFrom").val(),
            quantity: 1
        });

        $container.append(sourceItemHtml);
        $container.find('.source-item:last .selectpicker').selectpicker();
    });

    // Handle removing source
    $(document).on('click', '.remove-source', function() {
        const $sourceItem = $(this).closest('.source-item');
        const $container = $sourceItem.closest('.sources-container');
        const $row = $sourceItem.closest('.source-details-row');

        // Don't allow removing the last source
        if($container.find('.source-item').length > 1) {
            $sourceItem.remove();

            // Update total after removal
            let total = 0;
            $row.find('.source-quantity').each(function() {
                total += parseInt($(this).val()) || 0;
            });
            $row.find('.total-quantity-display').text(total);
        }
    });

    // Handle saving sources
    $(document).on('click', '.save-sources', function() {
        const key = $(this).data('key');
        const $row = $(this).closest('.source-details-row');
        const $saveButton = $(this);

        // Store the save context for the modal
        $saveButton.data('pendingSave', {key: key, $row: $row});

        const sources = [];
        let totalQty = 0;
        let hasNonMainWarehouse = false;

        // Collect data from the form
        $row.find('.source-item').each(function(index) {
            const $item = $(this);
            const warehouseId = $item.find('.source-warehouse').selectpicker('val');
            const quantityText = $item.find('.source-quantity').val();
            const quantity = parseInt(quantityText) || 0;

            if(quantity > 0 && warehouseId) {
                // Check if this warehouse has type_id = 2
                const selectedWarehouse = availableWarehouses.find(wh => wh.id == warehouseId);
                if(selectedWarehouse && selectedWarehouse.type_id == 2) {
                    hasNonMainWarehouse = true;
                }

                sources.push({
                    warehouseId: warehouseId,
                    quantity: quantity
                });
                totalQty += quantity;
            }
        });

        if(sources.length === 0) {
            alert('Musisz określić przynajmniej jedno źródło z ilością > 0');
            return;
        }

        // Store sources for potential confirmation
        $saveButton.data('pendingSources', sources);
        $saveButton.data('pendingTotalQty', totalQty);

        if(hasNonMainWarehouse) {
            // Show confirmation modal
            $("#nonMainWarehouseModal").modal('show');
            return;
        }

        // If no non-main warehouses, proceed directly
        completeSaveSources($saveButton);
        hasUnsavedSourceChanges = false;
    });

    // Handle confirmed save after modal
    $(document).on('click', '#confirmNonMainWarehouse', function() {
        const $saveButton = $('.save-sources:last'); // Get the button that triggered the save
        $("#nonMainWarehouseModal").modal('hide');
        completeSaveSources($saveButton);
    });

    // Complete the save operation
    function completeSaveSources($saveButton) {
        const saveData = $saveButton.data('pendingSave');
        const sources = $saveButton.data('pendingSources');
        const totalQty = $saveButton.data('pendingTotalQty');
        const key = saveData.key;
        const $row = saveData.$row;

        // Update transfer sources
        transferSources[key] = sources;

        // Update display and sync data
        updateQuantityDisplay(key);

        // Close the details row and reset button to gear icon
        const $editButton = $row.prev().find('.edit-component-sources');
        $row.remove();
        $editButton.html('<i class="bi bi-gear"></i>').removeClass('btn-warning').addClass('btn-light');

        // Reset unsaved changes flag
        hasUnsavedSourceChanges = false;

        // Update global summary
        updateGlobalSummary();

        // Clean up stored data
        $saveButton.removeData('pendingSave pendingSources pendingTotalQty');
    }

    // Handle source quantity changes
    $(document).on('input', '.source-quantity', function() {
        const $row = $(this).closest('.source-details-row');
        let total = 0;
        $row.find('.source-quantity').each(function() {
            total += parseInt($(this).val()) || 0;
        });
        $row.find('.total-quantity-display').text(total);

        // Get component key and update the main quantity display in real-time
        const componentKey = $row.find('.save-sources').data('key');
        if (componentKey !== undefined) {
            $(`.transferQty[data-key="${componentKey}"]`).val(total);
        }
    });

    $(document).on('click', '.toggle-source-details', function(e) {
        e.stopPropagation();
        const componentKey = $(this).data('component-key');
        const $detailsRow = $(`.source-details-summary[data-component-key="${componentKey}"]`);
        const $icon = $(this).find('i');

        if ($detailsRow.is(':visible')) {
            $detailsRow.hide();
            $icon.removeClass('bi-chevron-down').addClass('bi-list-ul');
        } else {
            $detailsRow.show();
            $icon.removeClass('bi-list-ul').addClass('bi-chevron-down');
        }
    });
});

function loadAvailableWarehouses() {
    // Get warehouses from the transfer selects including type information
    availableWarehouses = [];
    $("#transferFrom option").each(function() {
        if($(this).val()) {
            availableWarehouses.push({
                id: $(this).val(),
                name: $(this).text(),
                type_id: $(this).data('type-id') || 1 // Default to 2` if not specified
            });
        }
    });
}

function createSourceDetailsRow(key, component) {
    const currentSources = transferSources[key] || [{
        warehouseId: $("#transferFrom").val(),
        quantity: component.transferQty
    }];

    let sourcesHtml = '';
    currentSources.forEach((source, index) => {
        sourcesHtml += createSourceItemHtml(key, index, source);
    });

    const totalQty = currentSources.reduce((sum, source) => sum + source.quantity, 0);

    return `
        <tr class="source-details-row">
            <td colspan="6" class="p-3" style="background-color: #f8f9fa; border-left: 3px solid #007bff;">
                <div class="row">
                    <div class="col-md-8">
                        <h6><i class="bi bi-gear"></i> Źródła transferu</h6>
                        <div class="sources-container mt-3">${sourcesHtml}</div>
                        <button class="btn btn-success btn-sm add-source mt-2" data-key="${key}">
                            <i class="bi bi-plus"></i> Dodaj źródło
                        </button>
                    </div>
                    <div class="col-md-4">
                        <div class="card">
                            <div class="card-body">
                                <h6 class="card-title">Podsumowanie</h6>
                                <p class="card-text">
                                    Łączna ilość: <span class="total-quantity-display font-weight-bold">${totalQty}</span><br>
                                    <small class="text-muted">Wymagane: ${component.neededForCommissionQty}</small>
                                </p>
                                <button class="btn btn-primary btn-sm save-sources" data-key="${key}">
                                    <i class="bi bi-check"></i> Zapisz
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </td>
        </tr>
    `;
}

function createSourceItemHtml(key, index, source) {

    const warehouseOptions = availableWarehouses.map(wh =>
        `<option value="${wh.id}" ${wh.id == source.warehouseId ? 'selected' : ''}>${wh.name}</option>`
    ).join('');

    return `
        <div class="source-item mb-2">
            <div class="row align-items-center">
                <div class="col-md-5">
                    <select class="form-control selectpicker source-warehouse" data-key="${key}" data-index="${index}" data-live-search="true">
                        ${warehouseOptions}
                    </select>
                </div>
                <div class="col-md-3">
                    <input type="number" class="form-control source-quantity" data-key="${key}" data-index="${index}" 
                           value="${source.quantity}" min="0" placeholder="Ilość">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-danger btn-sm remove-source" type="button">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                <div class="col-md-2">
                    <small class="text-muted">szt.</small>
                </div>
            </div>
        </div>
    `;
}

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

function addNoCommissionAddButton() {
    const addButtonHtml = `
        <tr id="noCommissionAddRow">
            <td colspan="6" class="text-center py-3">
                <button class="btn btn-outline-primary" id="addNoCommissionComponent">
                    <i class="bi bi-plus"></i> Dodaj komponent do transferu
                </button>
            </td>
        </tr>
    `;
    $('#transferTBody').append(addButtonHtml);
}

function updateGlobalSummary() {
    // Check if the global summary header exists, if not, don't update
    if ($('.global-summary-header').length === 0) {
        return;
    }

    // Check if summary is currently expanded
    const isExpanded = $('.global-summary-header').hasClass('expanded');

    // Remove existing summary rows (including source details)
    $('.global-summary-component:not(.add-global-component-form)').remove();
    $('.source-details-summary').remove();

    // Create new summary based on all current components
    const allComponents = components.filter(c => c); // Filter out deleted components
    const globalSummary = createGlobalSummary(allComponents);

    // Update component count
    $('#totalComponentsCount').text(`${globalSummary.length} komponentów`);

    // If no commissions exist, show all components as individual rows (no grouping)
    const hasCommissions = allComponents.some(component => component.commissionKey !== null);

    if (!hasCommissions && allComponents.length > 0) {
        // For no-commission transfers, show each component individually
        allComponents.forEach(component => {
            if (component) {
                const summaryComponent = {
                    componentName: component.componentName,
                    componentDescription: component.componentDescription,
                    warehouseFromQty: component.warehouseFromQty,
                    warehouseFromReserved: component.warehouseFromReserved,
                    warehouseToQty: component.warehouseToQty,
                    warehouseToReserved: component.warehouseToReserved,
                    totalNeeded: component.neededForCommissionQty,
                    totalTransferQty: component.transferQty,
                    multiSourceIndicator: '<span class="text-muted">-</span>',
                    multiSourceDetails: ''
                };

                const summaryRowTemplate = $('script[data-template="globalSummaryComponentRow_template"]').text().split(/\$\{(.+?)\}/g);
                const summaryComponentRow = summaryRowTemplate.map(render(summaryComponent)).join('');
                const $summaryRow = $(summaryComponentRow);

                // Insert before the add form if it exists, otherwise at the end
                if (window.$currentAddGlobalForm) {
                    window.$currentAddGlobalForm.before($summaryRow);
                } else {
                    $('#transferTBody').append($summaryRow);
                }

                // Preserve the visibility state
                if (isExpanded) {
                    $summaryRow.show();
                } else {
                    $summaryRow.hide();
                }
            }
        });
    } else {
        // Normal commission-based summary
        globalSummary.forEach(summaryComponent => {
            const summaryRowTemplate = $('script[data-template="globalSummaryComponentRow_template"]').text().split(/\$\{(.+?)\}/g);
            const summaryComponentRow = summaryRowTemplate.map(render(summaryComponent)).join('');
            const $summaryRow = $(summaryComponentRow);

            // Insert before the add form if it exists, otherwise at the end
            if (window.$currentAddGlobalForm) {
                window.$currentAddGlobalForm.before($summaryRow);
            } else {
                $('#transferTBody').append($summaryRow);
            }

            // Preserve the visibility state
            if (isExpanded) {
                $summaryRow.show();
                // Auto-expand multi-source details if summary is expanded
                $summaryRow.filter('.source-details-summary').show();
            } else {
                $summaryRow.hide();
            }
        });
    }
}

function createGlobalSummary(allComponentValues) {
    // Check if we're in commission mode or no-commission mode
    const hasCommissions = allComponentValues.some(component => component.commissionKey !== null);

    // Group components by type and componentId
    const componentSummary = {};

    allComponentValues.forEach((component, componentIndex) => {
        // In commission mode: skip manually added components (commissionKey = null)
        // In no-commission mode: include all components
        if (hasCommissions && component.commissionKey === null) {
            return;
        }

        const key = `${component.type}-${component.componentId}`;
        if (!componentSummary[key]) {
            componentSummary[key] = {
                type: component.type,
                componentId: component.componentId,
                componentName: component.componentName,
                componentDescription: component.componentDescription,
                warehouseFromQty: component.warehouseFromQty,
                warehouseFromReserved: component.warehouseFromReserved,
                warehouseToQty: component.warehouseToQty,
                warehouseToReserved: component.warehouseToReserved,
                totalNeeded: 0,
                totalTransferQty: 0,
                sources: {}, // Track sources by warehouse
                componentKeys: [] // Track which component keys contribute to this summary
            };
        }

        // Sum up the quantities
        const needed = parseInt(component.neededForCommissionQty.replace(/<[^>]*>/g, '')) || 0;
        componentSummary[key].totalNeeded += needed;
        componentSummary[key].totalTransferQty += parseInt(component.transferQty) || 0;
        componentSummary[key].componentKeys.push(componentIndex);

        // Track sources for this component
        const sources = transferSources[componentIndex] || [{
            warehouseId: $("#transferFrom").val(),
            quantity: component.transferQty
        }];

        sources.forEach(source => {
            const warehouseName = availableWarehouses.find(wh => wh.id == source.warehouseId)?.name || 'Unknown';
            if (!componentSummary[key].sources[warehouseName]) {
                componentSummary[key].sources[warehouseName] = 0;
            }
            componentSummary[key].sources[warehouseName] += parseInt(source.quantity) || 0;
        });
    });

    // Convert to array and add multi-source indicators
    return Object.values(componentSummary).map(summary => {
        const sourceCount = Object.keys(summary.sources).length;
        const isMultiSource = sourceCount > 1;

        let multiSourceIndicator = '<span class="text-muted">-</span>';
        let multiSourceDetails = '';

        if (isMultiSource) {
            multiSourceIndicator = `<button class="btn btn-sm btn-outline-info toggle-source-details" data-component-key="${summary.type}-${summary.componentId}">
                <i class="bi bi-list-ul"></i>
                <small>${sourceCount} źródeł</small>
            </button>`;

            const sourcesList = Object.entries(summary.sources)
                .map(([warehouse, qty]) => `<li class="mb-1"><i class="bi bi-arrow-right text-muted mr-1"></i>${warehouse}: <strong>${qty} szt.</strong></li>`)
                .join('');

            multiSourceDetails = `
                <tr class="source-details-summary" data-component-key="${summary.type}-${summary.componentId}" style="display: none; background-color: #f1f3f4;">
                    <td colspan="6" class="py-2 px-3">
                        <div class="d-flex align-items-start">
                            <i class="bi bi-diagram-3 text-info mr-2 mt-1"></i>
                            <div>
                                <strong class="d-block mb-2">Źródła transferu:</strong>
                                <ul class="list-unstyled text-left mb-0">
                                    ${sourcesList}
                                </ul>
                            </div>
                        </div>
                    </td>
                </tr>
            `;
        }

        return {
            ...summary,
            multiSourceIndicator,
            multiSourceDetails
        };
    });
}

function getComponentValues(components, transferFrom, transferTo) {
    const data = { components: components, transferFrom: transferFrom, transferTo: transferTo };
    let result = [];
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/transfer/get-components-values.php",
        async: false,
        data: data,
        success: function (data) {
            result = JSON.parse(data);
        }
    });
    return result;
}

function addComponentsRow(componentValues, $TBody) {
    const template = transferComponentsTableRow_template.map(render(componentValues)).join('');
    const $tr = $(template);

    // Initialize default transfer source
    if(!transferSources[componentValues.key]) {
        transferSources[componentValues.key] = [{
            warehouseId: $("#transferFrom").val(),
            quantity: componentValues.transferQty
        }];
    }

    if(componentValues['neededForCommissionQty'] == '<span class="text-light">n/d</span>') {
        $tr.find('.insertDifference').remove();
    }
    $TBody.append($tr);
}

function addOrSubtractComponentsReserved(checked, tableCellClass){
    $(tableCellClass).each(function() {
        const reserved = $(this).data('reserved');
        let value = parseFloat($(this).text(), 10);
        if (!checked) {
            value += reserved;
            $(this).text(value);
            return true;
        }
        value -= reserved;
        $(this).text(value);
    });
}

function validateAddComponentForm(magazineComponent, listComponents, transferQty) {
    return magazineComponent && listComponents && transferQty && transferQty > 0;
}

function clearAddComponentForm(){
    $("#magazineComponent, #list__components, #qtyComponent").val('');
    $("#magazineComponent, #list__components").selectpicker('refresh');
}

$("#addTransferComponent").click(function() {
    const componentType = $("#magazineComponent").val();
    const listComponentsVal = $("#list__components").val();
    const transferQty = $("#qtyComponent").val();

    if(!validateAddComponentForm(componentType, listComponentsVal, transferQty)) {
        $("#addTransferComponent").popover('show');
        return;
    }

    clearAddComponentForm();

    const component = {
        type: componentType,
        componentId: listComponentsVal,
        neededForCommissionQty: '<span class="text-light">n/d</span>',
        transferQty: transferQty,
        commissionKey: null  // No commission association
    }

    const componentValues = getComponentValues([component], $("#transferFrom").val(), $("#transferTo").val());
    const pushedKey = components.push(componentValues[0]) - 1;
    componentValues[0]['key'] = pushedKey;
    componentValues[0]['commissionKey'] = null;

    // Initialize default transfer source
    transferSources[pushedKey] = [{
        warehouseId: $("#transferFrom").val(),
        quantity: parseInt(transferQty)
    }];

    syncComponentQuantityFromSources(pushedKey);

    // Add to transfer table (but it's hidden until finish is clicked)
    addComponentsRow(componentValues[0], $("#transferTBody"));
});

$(document).on('click', '#addNoCommissionComponent', function() {
    // Hide the add button and show the form
    $('#noCommissionAddRow').hide();

    const addFormHtml = `
        <tr id="noCommissionAddForm">
            <td colspan="6" class="py-3" style="background-color: #f8f9fa;">
                <div class="d-flex justify-content-center align-items-center">
                    <small class="text-muted mr-3">Dodaj komponent:</small>
                    <select id="noCommissionMagazineComponent" data-width="10%" data-title="Typ:" class="form-control selectpicker mr-2" style="width: 100px;">
                        <option value="sku">SKU</option>
                        <option value="tht">THT</option>
                        <option value="smd">SMD</option>
                        <option value="parts">Parts</option>
                    </select>
                    <select id="noCommissionListComponents" data-title="Komponent:" data-live-search="true"
                            class="form-control selectpicker mr-2" style="width: 300px;" disabled></select>
                    <input type="number" style="width: 75px; padding: 3px; text-align: center;"
                           class="form-control mr-2" id="noCommissionQtyComponent" placeholder="Ilość">
                    <button id="addNoCommissionComponentBtn" class="btn btn-success btn-sm mr-1">
                        <i class="bi bi-check"></i>
                    </button>
                    <button id="cancelNoCommissionComponentBtn" class="btn btn-secondary btn-sm">
                        <i class="bi bi-x"></i>
                    </button>
                </div>
            </td>
        </tr>
    `;

    $('#transferTBody').append(addFormHtml);

    // Initialize selectpickers
    $('#noCommissionAddForm .selectpicker').selectpicker();
});

// Handle component type selection
$(document).on('change', '#noCommissionMagazineComponent', function() {
    let option = $(this).val();
    $("#noCommissionListComponents").empty();
    $('#list__' + option + '_hidden option').clone().appendTo('#noCommissionListComponents');
    $("#noCommissionListComponents").prop("disabled", false);
    $("#noCommissionListComponents").selectpicker('refresh');
});

// Handle adding the component
$(document).on('click', '#addNoCommissionComponentBtn', function() {
    const componentType = $("#noCommissionMagazineComponent").val();
    const componentId = $("#noCommissionListComponents").val();
    const transferQty = $("#noCommissionQtyComponent").val();

    if (!componentType || !componentId || !transferQty || transferQty <= 0) {
        $(this).popover({
            content: "Uzupełnij wszystkie dane",
            trigger: 'manual',
            placement: 'top'
        }).popover('show');
        setTimeout(() => $(this).popover('hide'), 2000);
        return;
    }

    // Check for duplicates
    let isDuplicate = false;
    components.forEach(component => {
        if (component &&
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
        commissionKey: null
    };

    // Get component values and add to the transfer table
    const transferFrom = $("#transferFrom").val();
    const transferTo = $("#transferTo").val();
    const componentValues = getComponentValues([component], transferFrom, transferTo);

    const pushedKey = components.push(componentValues[0]) - 1;
    componentValues[0]['key'] = pushedKey;
    componentValues[0]['commissionKey'] = null;

    // Initialize default transfer source
    transferSources[pushedKey] = [{
        warehouseId: transferFrom,
        quantity: parseInt(transferQty)
    }];

    syncComponentQuantityFromSources(pushedKey);

    // Add the row to the table before the form
    const $newRow = $(transferComponentsTableRow_template.map(render(componentValues[0])).join(''));
    $newRow.addClass('manual-component');
    $('#noCommissionAddForm').before($newRow);

    // Hide the form and show the add button again
    cancelNoCommissionComponentAdd();
});

// Handle canceling
$(document).on('click', '#cancelNoCommissionComponentBtn', function() {
    cancelNoCommissionComponentAdd();
});

function cancelNoCommissionComponentAdd() {
    $('#noCommissionAddForm').remove();
    $('#noCommissionAddRow').show();
}

$("#magazineComponent").change(function() {
    let option = $(this).val();
    $("#list__components").empty();
    $('#list__' + option + '_hidden option').clone().appendTo('#list__components');
    $("#list__components").prop("disabled", false);
    $("#list__components").selectpicker('refresh');
});

$("#insertDifferenceAll").click(function() {
    $(".insertDifference").each(function() {
        $(this).click();
    });
})

$('body').on('click', '.insertDifference', function() {
    const $qty = $(this).closest("tr").find(".transferQty");
    const key = $qty.data('key');
    const $magazineTo = $(this).closest("tr").find(".warehouseTo");
    //availableTo is read through the DOM, because its value varies if #subtractPartsMagazineTo is checked
    const availableTo = parseInt($magazineTo.text());
    const neededForCommission = parseInt(components[key]['neededForCommissionQty']);
    const qty = neededForCommission - availableTo;
    if (isNaN(neededForCommission)) return;
    const result = qty > 0 ? qty : 0;
    $qty.val(result).change();
});

$("#subtractPartsMagazineFrom").change(function() {
    const checked = $(this).is(":checked");
    addOrSubtractComponentsReserved(checked, ".warehouseFrom");
});

$("#subtractPartsMagazineTo").change(function() {
    const checked = $(this).is(":checked");
    addOrSubtractComponentsReserved(checked, ".warehouseTo");
});

$("#deleteFromTransfer").click(function() {
    const key = $(this).data('key');
    delete components[key];
    delete transferSources[key];
    $('.removeTransferRow[data-key="' + key + '"]').closest('tr').remove();
    updateGlobalSummary();
});

$('body').on('click', '.removeTransferRow', function() {
    $("#deleteComponentRowModal").modal('show');
    $("#deleteFromTransfer").data('key', $(this).data('key'));
});

$("#transferFrom, #transferTo").change(function() {
    if($("#transferFrom").val() == '' || $("#transferTo").val() == '') return;

    $("#createCommission, #dontCreateCommission")
        .prop('disabled', false)
        .css('pointer-events','')
        .parent().popover('dispose');

    // Reload available warehouses when transfer settings change
    loadAvailableWarehouses();
});

$("#transferTo").change(function() {
    const warehouseId = this.value;
    disableUserSelectOptions(warehouseId);
    $("#userSelect").selectpicker('refresh');
});

// Function that disables users from select, if they are not in the same warehouse as the target warehouse
function disableUserSelectOptions(warehouseId)
{
    $("#userSelect option").each(function() {
        $(this).prop("disabled", $(this).attr('data-submag') !== warehouseId);
    });
    $("#transferTo").selectpicker('refresh');
}