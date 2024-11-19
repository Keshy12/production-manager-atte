const transferCommissionTableRow_template = $('script[data-template="transferCommissionTableRow_template"]').text().split(/\$\{(.+?)\}/g);

$(document).ready(function() {
    $("#list__priority").val(0).selectpicker('refresh');

    const commissions = [];
    $("#addCommission").click(function() {
        const commissionValues = getCommissionRowValues();
        const $TBody = $('#commissionTBody');
        commissions.push(commissionValues);
        commissionValues['key'] = commissions.length-1;
        addCommissionRow(commissionValues, $TBody);
        clearAddCommissionFields();
    });

    $('body').on('click', '.removeCommissionRow', function() {
        const key = $(this).data('id');
        delete commissions[key];
        $(this).closest('tr').remove();
    });

    $("#submitCommissions").click(function() {
        const transferFrom = $("#transferFrom").val();
        const transferTo = $("#transferTo").val();
        getComponentsForCommissions(commissions, transferFrom, transferTo);
    });
});

function getComponentsForCommissions(commissions, transferFrom, transferTo) {
    const data = { commissions: commissions, transferFrom: transferFrom, transferTo: transferTo };
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/transfer/get-components-for-commissions.php",
        async: false,
        data: data,
        success: function (data) {
            console.log(data);
        }
    });
}

function getCommissionRowValues() {
    const colors = ['none', 'green', 'yellow', 'red'];
    return {
        receiversIds: $('#userSelect').val(),
        receivers: $('#userSelect option:selected').toArray().map(item => item.text).join(', '),
        priorityId: $('#list__priority').val(),
        priority: $('#list__priority option:selected').text().trim(),
        priorityColor: colors[$('#list__priority').val()],
        deviceType: $('#deviceType').val(),
        deviceId: $('#list__device').val(),
        deviceName: $('#list__device option:selected').text().trim(),
        deviceDescription: $('#list__device option:selected').attr('data-subtext').trim(),
        version: $('#version').val(),
        laminateId: $('#list__laminate').val(),
        laminate: $('#list__laminate option:selected').text().trim(),
        quantity: $('#quantity').val()
    };
}

function addCommissionRow(commissionValues, $TBody)
{
    const $tr = $(transferCommissionTableRow_template.map(render(commissionValues)).join(''));
    $TBody.append($tr);
}

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
    $("#deviceType, #list__device").selectpicker('refresh');
});

function clearAddCommissionFields()
{
    $("#userSelect #list__device, #version, #list__laminate, #quantity, #deviceType").val('');
    $("#list__priority").val(0);
    $("#versionSelect, #laminateSelect").hide();
    $("#list__device, #version, #list__laminate").empty();
    $("#deviceType, #list__device").prop('disabled', true);
    $("#userSelect, #list__priority, #list__device, #version, #list__laminate, #deviceType").selectpicker('refresh');
}