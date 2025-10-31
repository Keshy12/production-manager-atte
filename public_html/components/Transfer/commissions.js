const transferCommissionTableRow_template = $('script[data-template="transferCommissionTableRow_template"]').text().split(/\$\{(.+?)\}/g);

const commissions = [];

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

        const isInTransferMode = $('#transferTableContainer').is(':visible');

        if (isInTransferMode) {
            commissionToDelete = key;
            $("#deleteCommissionConfirmModal").modal('show');
            return;
        } else {
            delete commissions[key];
            $commissionRow.remove();
        }
    });

    $("#confirmDeleteCommission").click(function() {
        if (commissionToDelete === null) return;

        const key = commissionToDelete;

        const componentsToRemove = [];
        components.forEach((component, index) => {
            if (component && component.commissionKey == key) {
                componentsToRemove.push(index);
            }
        });

        componentsToRemove.forEach(componentIndex => {
            delete components[componentIndex];
            delete transferSources[componentIndex];
            $(`.commission-component[data-key="${componentIndex}"]`).remove();
        });

        $(`.commission-header[data-commission-key="${key}"]`).remove();
        $(`.add-component-row[data-commission-key="${key}"]`).remove();
        $(`.collapse-commission-${key}`).remove();

        updateGlobalSummary();

        delete commissions[key];

        $(`.removeCommissionRow[data-key="${key}"]`).closest('tr').remove();

        $("#deleteCommissionConfirmModal").modal('hide');
        commissionToDelete = null;
    });

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
                const items = foundExistingCommissions
                    .map(ec => `<li><strong>${ec[1]}</strong> - ID: ${ec[0]} <small class="text-muted">(utworzono: ${ec[2]})</small></li>`)
                    .join('');
                const $alert = $(`<div class="alert-existing-commission alert alert-info alert-dismissible fade show" role="alert">
                          <h5 class="alert-heading"><i class="bi bi-info-circle"></i> Wykryto istniejące zlecenia</h5>
                          <p>Poniższe zlecenia są już aktywne dla wybranego magazynu i odbiorców. Nowe zlecenie zostanie utworzone jako osobny wpis:</p>
                          <ul class="mb-2">${items}</ul>
                          <hr>
                          <p class="mb-0"><small>Zlecenia z tymi samymi parametrami będą automatycznie zgrupowane w systemie podczas przetwarzania.</small></p>
                          <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                          </button>
                        </div>
                      `);
                $("#transferTableContainer").before($alert);
            }


            const $TBody = $('#transferTBody');
            let componentIndex = 0;

            for (const commissionKey in componentsByCommission) {
                const commissionGroup = componentsByCommission[commissionKey];
                const commission = commissions[commissionKey];

                const commissionHeaderRow = `
                    <tr class="commission-header" data-commission-key="${commissionKey}" data-toggle="collapse" 
                        data-target=".collapse-commission-${commissionKey}" 
                        style="box-shadow: -7px 0px 0px 0px ${commissionGroup.commissionInfo.priorityColor};">
                        <td colspan="6" class="font-weight-bold">
                            <i class="bi bi-chevron-down commission-toggle-icon mr-2"></i>
                            Zlecenie: ${commissionGroup.commissionInfo.deviceName} - ${commissionGroup.commissionInfo.receivers}
                            ${commission.laminate && commission.laminate !== '' ? `<br><small class="text-muted">Laminat: ${commission.laminate}</small>` : ''}
                            ${commission.version && commission.version !== 'n/d' ? `<br><small class="text-muted">Wersja: ${commission.version}</small>` : ''}
                            <br><small class="text-muted">Ilość: ${commission.quantity}</small>
                            <span class="badge badge-light ml-2">${commissionGroup.components.length} komponentów</span>
                        </td>
                    </tr>
                    `;
                $TBody.append(commissionHeaderRow);

                const componentValues = getComponentValues(commissionGroup.components, transferFrom, transferTo);
                components.push(...componentValues);

                componentValues.forEach(componentValue => {
                    componentValue['key'] = componentIndex;
                    componentValue['commissionKey'] = commissionKey;

                    transferSources[componentIndex] = [{
                        warehouseId: transferFrom,
                        quantity: getTotalTransferQty(componentIndex) || componentValue.transferQty
                    }];

                    syncComponentQuantityFromSources(componentIndex);

                    const $row = $(transferComponentsTableRow_template.map(render(componentValue)).join(''));
                    if(componentValue['neededForCommissionQty'] == '<span class="text-light">n/d</span>') {
                        $row.find('.insertDifference').remove();
                    }
                    $row.addClass('commission-component collapse show collapse-commission-' + commissionKey);
                    $TBody.append($row);

                    componentIndex++;
                });

                const addButtonTemplate = $('script[data-template="addCommissionComponentRow_template"]').text().split(/\$\{(.+?)\}/g);
                const addButtonRow = addButtonTemplate.map(render({commissionKey: commissionKey})).join('');
                const $addRow = $(addButtonRow);
                $addRow.addClass('collapse show collapse-commission-' + commissionKey);
                $TBody.append($addRow);
            }

            if ($('.global-summary-header').length === 0) {
                const globalSummaryHeaderTemplate = $('script[data-template="globalSummaryHeader_template"]').text().split(/\$\{(.+?)\}/g);
                const globalSummaryHeaderRow = globalSummaryHeaderTemplate.map(render({})).join('');
                $TBody.append(globalSummaryHeaderRow);
            }

            const noCommissionAddRow = `
                <tr id="noCommissionAddRow" class="collapse collapse-global-summary" style="background-color: #f8f9fa;">
                    <td colspan="6" class="text-center py-2">
                        <button id="addNoCommissionComponent" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-plus-circle"></i> Dodaj komponent bez zlecenia
                        </button>
                    </td>
                </tr>
            `;
            $TBody.append(noCommissionAddRow);

            updateGlobalSummary();

            $('.collapse-commission-' + Object.keys(componentsByCommission)[0]).on('show.bs.collapse', function() {
                $('.collapse-global-summary').collapse('hide');
            });

            $('.collapse-global-summary').on('show.bs.collapse', function() {
                for (const key in componentsByCommission) {
                    $('.collapse-commission-' + key).collapse('hide');
                }
            });

            $('.commission-header').on('click', function() {
                const $icon = $(this).find('.commission-toggle-icon');
                setTimeout(() => {
                    const isExpanded = $($(this).data('target')).hasClass('show');
                    if (isExpanded) {
                        $icon.removeClass('bi-chevron-right').addClass('bi-chevron-down');
                    } else {
                        $icon.removeClass('bi-chevron-down').addClass('bi-chevron-right');
                    }
                }, 50);
            });

            $('.global-summary-header').on('click', function() {
                const $icon = $(this).find('.summary-toggle-icon');
                setTimeout(() => {
                    const isExpanded = $('.collapse-global-summary').hasClass('show');
                    if (isExpanded) {
                        $icon.removeClass('bi-chevron-right').addClass('bi-chevron-down');
                    } else {
                        $icon.removeClass('bi-chevron-down').addClass('bi-chevron-right');
                    }
                }, 50);
            });

            $(".commissionSubmitSpinner").hide();
            $("#transferTableContainer, #transferTableButtons").show();
        }, 50);
    });
});

$("#transferNoCommission").click(function() {
    $("#transferWithoutCommissionModal").modal('hide');
    $("#createCommissionCard, #selectWarehouses").hide();
    $("#transferTableContainer, #transferTableButtons").show();

    if ($('.global-summary-header').length === 0) {
        const $TBody = $('#transferTBody');
        const globalSummaryHeaderTemplate = $('script[data-template="globalSummaryHeader_template"]').text().split(/\$\{(.+?)\}/g);
        const globalSummaryHeaderRow = globalSummaryHeaderTemplate.map(render({})).join('');
        $TBody.append(globalSummaryHeaderRow);

        const noCommissionAddRow = `
            <tr id="noCommissionAddRow" class="collapse collapse-global-summary" style="background-color: #f8f9fa;">
                <td colspan="6" class="text-center py-2">
                    <button id="addNoCommissionComponent" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-plus-circle"></i> Dodaj komponent bez zlecenia
                    </button>
                </td>
            </tr>
        `;
        $TBody.append(noCommissionAddRow);

        $('.global-summary-header').on('click', function() {
            const $icon = $(this).find('.summary-toggle-icon');
            setTimeout(() => {
                const isExpanded = $('.collapse-global-summary').hasClass('show');
                if (isExpanded) {
                    $icon.removeClass('bi-chevron-right').addClass('bi-chevron-down');
                } else {
                    $icon.removeClass('bi-chevron-down').addClass('bi-chevron-right');
                }
            }, 50);
        });
    }

    updateGlobalSummary();
});

function getComponentsForCommissions(commissionsToGet, transferFrom, transferTo) {
    let result;
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/transfer/get-components-for-commissions.php",
        data: {commissions: commissionsToGet, transferFrom: transferFrom, transferTo: transferTo},
        dataType: 'json',
        async: false,
        success: function(response) {
            result = response;
        }
    });
    return result;
}

function getComponentValues(components, transferFrom, transferTo) {
    let result;
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/transfer/get-components-values.php",
        data: {components: components, transferFrom: transferFrom, transferTo: transferTo},
        dataType: 'json',
        async: false,
        success: function(response) {
            result = response;
        }
    });
    return result;
}

function getCommissionRowValues() {
    const deviceType = $("#deviceType").val();
    return {
        deviceType: deviceType,
        deviceId: $("#list__device").val(),
        deviceName: $("#list__device option:selected").text(),
        receiversIds: $("#userSelect").val(),
        receivers: $("#userSelect option:selected").map(function(){ return $(this).text(); }).get().join(', '),
        priorityId: $("#list__priority").val(),
        priorityColor: getPriorityColor(parseInt($("#list__priority").val())),
        quantity: $("#quantity").val(),
        version: $("#version").val(),
        laminateId: deviceType === 'smd' ? $("#list__laminate").val() : null,
        laminate: deviceType === 'smd' ? $("#list__laminate option:selected").text() : null,
    }
}

function getPriorityColor(priorityId) {
    const colors = ["none", "green", "rgb(255, 219, 88)", "red"];
    return colors[priorityId];
}

function addCommissionRow(commissionValues, $TBody) {
    const $tr = $(transferCommissionTableRow_template.map(render(commissionValues)).join(''));
    $TBody.append($tr);
}

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

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