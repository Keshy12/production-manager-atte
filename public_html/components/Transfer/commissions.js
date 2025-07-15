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

    $('body').on('click', '.removeCommissionRow', function() {
        const key = $(this).data('key');
        delete commissions[key];
        $(this).closest('tr').remove();
    }); 

    $("#submitCommissions").click(function() {
        $("#submitCommissions, #moreOptionsCard").hide();
        $("#commissionTable").removeClass('show');
        $(".removeCommissionRow").remove();
        const isNoTransfer = $(this).data('noTransfer');
        if(isNoTransfer === true) {
            $("#submitTransfer").click();
            return;
        }
        $(".commissionSubmitSpinner").show();
        //Timeout of 0ms, to allow the DOM to update before getting the components via AJAX
        setTimeout(() => {
            const transferFrom = $("#transferFrom").val();
            const transferTo = $("#transferTo").val();
            const [commissionComponents, foundExistingCommissions] = getComponentsForCommissions(commissions, transferFrom, transferTo);
            if (foundExistingCommissions.length > 0) {
                $(".commissionSubmitSpinner").hide();
                existingCommissions.push(...foundExistingCommissions)
                const items = foundExistingCommissions
                    .map(ec => `<li>ID: ${ec[0]} - ${ec[1]} <small>(stworzono: ${ec[2]})</small></li>`)
                    .join('');
                const $alert = $(`<div class="alert alert-warning alert-dismissible fade show" role="alert">
                                      <strong>Wykryto duplikacje zlecenia:</strong>
                                      <ul>${items}</ul>
                                      <strong>Powyższe pozycje zostaną rozszerzone o odpowiednią ilość.</strong>
                                    </div>
                                  `);

                $("#transferTableContainer").before($alert);
            }
            const componentValues = getComponentValues(commissionComponents, transferFrom, transferTo);
            const $TBody = $('#transferTBody');
            components.push(...componentValues);
            for (const key in componentValues) {
                if (componentValues.hasOwnProperty(key)) {
                    componentValues[key]['key'] = key;
                    addComponentsRow(componentValues[key], $TBody);
                }
            }
            $(".commissionSubmitSpinner").hide();
            $("#transferTableContainer").show();
        }, 0);
    });
});

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

$('select#deviceType').change(function(){
    const deviceType = this.value;
    const usersSelected = $('#userSelect').val();
    const usedDevices = getUsedDevices(usersSelected, deviceType);
    // Get devices used by all selected users
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
        if(possibleVersions[0] == null)
        {
            $("#version").selectpicker('destroy');
            $("#version").html("<option value=\"n/d\" selected>n/d</option>");
            $("#version").prop('disabled', false);
            $("#version").selectpicker('refresh');
            $("#currentpage").text(1);
            return;
        }
        let version_id = Object.keys(possibleVersions)[0];
        let version = possibleVersions[version_id][0];
        let option = "<option value='"+version+"' selected>"+version+"</option>";
        $("#version").append(option);
        $("#version").selectpicker('destroy');
        $("#currentpage").text(1);
    } else {
        for (let version_id in possibleVersions) 
        {
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
        for (let laminate_id in possibleLaminates) 
        {
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

function clearAddCommissionFields()
{
    $("#userSelect, #list__device, #version, #list__laminate, #quantity, #deviceType").val('');
    $("#list__priority").val(0);
    $("#versionSelect, #laminateSelect").hide();
    $("#list__device, #version, #list__laminate").empty();
    $("#deviceType, #list__device").prop('disabled', true);
    $("#userSelect, #list__priority, #list__device, #version, #list__laminate, #deviceType").selectpicker('refresh');
}