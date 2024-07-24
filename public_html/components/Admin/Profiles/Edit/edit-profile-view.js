let $formFields = $("#login, #name, #surname, #email, #list__submag");
let $usedDevicesFields = $("#list__tht, #list__smd, .deleteAllDevices, #addSMD, #addTHT");

let deviceProducedTemplate = $('script[data-template="deviceProduced"]').text().split(/\$\{(.+?)\}/g);

function render(props) {
    return function(tok, i) { return (i % 2) ? props[tok] : tok; };
}

function fillForm(userInfo)
{
    $formFields.prop('disabled', false);
    $("#editProfileSubmit").prop('disabled', false).show();
    $("#user_id").val(userInfo['user_id']);
    $("#login").val(userInfo['login']);
    $("#name").val(userInfo['name']);
    $("#surname").val(userInfo['surname']);
    $("#email").val(userInfo['email']);
    $("#list__submag").val(userInfo['sub_magazine_id']).selectpicker('refresh');
}

function renderDevicesProduced(devicesUsed, deviceType)
{
    $usedDevicesFields.prop('disabled', false);
    $("#list__"+deviceType).empty();

    // Create a set of device IDs to be removed
    const deviceIdsToRemove = new Set(
        Object.values(devicesUsed).map(device => String(device[0]))
    );

    let $list__options = $("#list__"+deviceType+"_hidden option").clone();

    $list__options = $list__options.filter(function() {
        return !deviceIdsToRemove.has($(this).val());
    });

    for(const [key, deviceUsed] of Object.entries(devicesUsed))
    {
        let item = {
            deviceType: deviceType,
            deviceId: deviceUsed[0],
            deviceName: deviceUsed[1],
            deviceDescription: deviceUsed[2]
        }
        let renderedItem = deviceProducedTemplate.map(render(item)).join('');
        $("#"+deviceType+"Used").append(renderedItem);
    }
    $("#list__"+deviceType).append($list__options).selectpicker('refresh');
}

function addProfile()
{
    $formFields.attr('readonly', true);
    $("#password").attr('readonly', true);
    var $form = $("#userForm");
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/admin/profiles/edit/create-profile.php",
        data: $form.serialize(), // serializes the form's elements.
        success: function(data)
        {
            let result = JSON.parse(data);
            let resultMessage = result[0];
            let wasSuccessful = result[1];
            let alertType = wasSuccessful ? "alert-success" : "alert-danger";
            let alert = `
            <div class="alert `+alertType+` alert-dismissible fade show" role="alert">
                `+resultMessage+`
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>`;
            $("#ajaxResult").html(alert);
            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);
            if(wasSuccessful) {
                let insertedId = result[2];
                let fullName = $("#name").val()+" "+$("#surname").val();
                $formFields.attr('readonly', false);
                $("#password").attr('readonly', false);
                $("#password").attr('required', false);
                let option = '<option value="'+insertedId+'">'+fullName+'</option>';
                $("#list__user").append(option).selectpicker("refresh").val(insertedId).selectpicker("refresh");
                $("#passwordField, #isAdminField, #addProfileSubmit").hide();
                $("#editProfileSubmit").prop('disabled', false).show();
                $('#list__tht_hidden option').clone().appendTo('#list__tht');
                $('#list__smd_hidden option').clone().appendTo('#list__smd');
                $("#list__tht, #list__smd").prop('disabled', false).selectpicker('refresh');
                $("#addSMD, #addTHT, .deleteAllDevices").prop('disabled', false);
            }
        }
    });
}

$("#list__user").change(function(){
    let userid = this.value;
    $("#passwordField, #isAdminField, #addProfileSubmit").hide();
    $("#password").attr('required', false);
    $("#thtUsed, #smdUsed").empty();
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/admin/profiles/edit/get-user-info.php",
        data: { userid: userid},
        success: function (data) {
            let result = JSON.parse(data);
            let userInfo = result[0];
            let THTUsed = result[1];
            let SMDUsed = result[2];
            fillForm(userInfo);
            renderDevicesProduced(THTUsed, "tht");
            renderDevicesProduced(SMDUsed, "smd");
        }
    });
})

$("#editProfileSubmit").click(function(e){
    if(!$('#userForm')[0].checkValidity()) {
        $('#userForm')[0].reportValidity();
        return;
    }
    e.preventDefault();
    $formFields.attr('readonly', true);
    var $form = $("#userForm");
    var actionUrl = $form.attr('action');
    $.ajax({
        type: "POST",
        url: actionUrl,
        data: $form.serialize(), // serializes the form's elements.
        success: function(data)
        {
            let userId = $("#user_id").val();
            $formFields.attr('readonly', false);
            let result = JSON.parse(data);
            let resultMessage = result[0];
            let alertType = result[1] ? "alert-success" : "alert-danger";
            let alert = `
            <div class="alert `+alertType+` alert-dismissible fade show" role="alert">
                `+resultMessage+`
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>`;
            $("#ajaxResult").html(alert);
            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);
            let fullName = $("#name").val()+" "+$("#surname").val();
            $('#list__user option[value="'+userId+'"]').html(fullName);
            $('#list__user').selectpicker('refresh');
        }
    });
});

$("#createNewProfile").click(function(){
    $formFields.prop('disabled', false);
    $formFields.val('');
    $("#list__tht, #list__smd").prop('disabled', true).selectpicker('refresh');
    $("#addSMD, #addTHT, .deleteAllDevices").prop('disabled', true);
    $("#thtUsed, #smdUsed").empty();
    $("#list__user").val('');
    $("#editProfileSubmit").hide();
    $("#passwordField, #isAdminField, #addProfileSubmit").show();
    $("#password").prop('required', true);
    $("#list__submag, #list__user").selectpicker('refresh');
});

$("#addProfileSubmit").click(function(e){
    if(!$('#userForm')[0].checkValidity()) {
        $('#userForm')[0].reportValidity();
        return;
    }
    e.preventDefault();
    if($("#isAdmin").is(":checked")) {
        $("#addAdminProfileModal").modal('show');
        return;
    }
    addProfile();
});

$("#addAdminProfileSubmit").click(function(){
    $("#addAdminProfileModal").modal('hide');
    addProfile();
});

function addDevices(userid, devices, type)
{
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/profile/devicesproduced/add-devices-produced.php",
        data: {userid: userid, type: type, device_id: devices},
        success: function (data) {
            let result = JSON.parse(data);
            let ajaxResult = result[0];
            let addedDevices = (1 in result) ? result[1] : false;

            $("#ajaxResult").append(ajaxResult);
            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);
            if(addedDevices !== false)
            {
                Object.keys(addedDevices).map(function (device) {
                    $("#"+type+"Used").append(addedDevices[device]);
                });
            }

        }
    });
}

$("#addTHT").click(function(){
    let userid = $("#list__user").val();
    let devices = $("#list__tht").selectpicker('val');
    addDevices(userid, devices, "tht");
    $('#list__tht').find(":selected").remove();
    $("#list__tht").selectpicker('refresh');
});

$("#addSMD").click(function(){
    let userid = $("#list__user").val();
    let devices = $("#list__smd").selectpicker('val');
    addDevices(userid, devices, "smd");
    $('#list__smd').find(":selected").remove();
    $("#list__smd").selectpicker('refresh');
});

$('body').on('click', '.removeDevice', function() {
    $("#removeDeviceProducedModal").modal('show');
    $("#removeDeviceProduced").attr("data-type", $(this).attr("data-type"));
    $("#removeDeviceProduced").attr("data-id", $(this).attr("data-id"));
    let name = $(this).parent().find(".name").text();
    let description = $(this).parent().find(".description").text();
    $("#removeDeviceProduced").attr("data-name", name);
    $("#removeDeviceProduced").attr("data-description", description);
});

$("#removeDeviceProduced").click(function() {
    let userid = $("#list__user").val();
    let type = $(this).attr("data-type");
    let device_id = $(this).attr("data-id");
    let name = $(this).attr("data-name");
    let description = $(this).attr("data-description");
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/profile/devicesproduced/remove-devices-produced.php",
        data: {userid: userid, type: type, device_id: device_id},
        success: function (data) {
            let result = JSON.parse(data);
            let ajaxResult = result[0];

            $("#ajaxResult").append(ajaxResult);
            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);
            if(result[1] === true)
            {
                $('.'+type+'-'+device_id).remove();
                let insert = `<option data-subtext="`+description+`" 
                                data-tokens="`+name+" "+description+`" 
                                value="`+device_id+`">
                                `+name+
                            `</option>`;
                $("#list__"+type).append(insert);
                $("#list__"+type).selectpicker('refresh');
            }
        }
    });
    $("#removeDeviceProducedModal").modal('hide');
})

$(".filter").on('input', function(){
    let type = $(this).attr("data-deviceType");
    let search = this.value.toLowerCase();
    $("#"+type+"Used div").each(function(){
        let $item = $(this);
        let itemNameDescription = $item.find(".name").text()+" "+$item.find(".description").text();
        $item.hide();
        if(~itemNameDescription.toLowerCase().indexOf(search))
        {
            $item.show();
        }
    });
});

$(".deleteAllDevices").click(function(){
    let type = $(this).attr("data-deviceType");
    const devicesToDelete = [];
    $("#"+type+"Used div").each(function(){
        let $item = $(this);
        let itemId = $item.find(".removeDevice").attr("data-id");
        let itemName = $item.find(".name").text();
        let itemDescription = $item.find(".description").text();
        if(!$item.is(":hidden"))
        {
            devicesToDelete.push([itemId, itemName, itemDescription]);
        }
    });
    $("#removeAllDevicesProduced").attr("data-devicesToDelete", JSON.stringify(devicesToDelete));
    $("#removeAllDevicesProduced").attr("data-type", type);
    $("#removeAllDevicesProducedModal").modal('show');
});

$("#removeAllDevicesProduced").click(function(){
    let type = $(this).attr("data-type");
    let devicesToDelete = JSON.parse($(this).attr("data-devicesToDelete"));
    let idsToDelete = devicesToDelete.map( el => el[0] );
    let userid = $("#list__user").val();
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/profile/devicesproduced/remove-devices-produced.php",
        data: {userid: userid, type: type, device_id: idsToDelete},
        success: function (data) {
            let result = JSON.parse(data);
            let ajaxResult = result[0];

            $("#ajaxResult").append(ajaxResult);
            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);
            if(result[1] === true)
            {
                for (const device of devicesToDelete) {
                    let device_id = device[0];
                    let name = device[1];
                    let description = device[2];
                    $('.'+type+'-'+device_id).remove();
                    let insert = `<option data-subtext="`+description+`" 
                                    data-tokens="`+name+" "+description+`" 
                                    value="`+device_id+`">
                                    `+name+
                                `</option>`;
                    $("#list__"+type).append(insert);
                    $("#list__"+type).selectpicker('refresh');
                }
            }
        }
    });
    $("#removeAllDevicesProducedModal").modal('hide');
});