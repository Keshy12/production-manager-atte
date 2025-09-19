let $formFields = $("#login, #name, #surname, #email, #list__submag, #isActive");
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
    $("#isActive").prop('checked', userInfo['user_isActive'] === 1);

    // Save the loaded sub_magazine_id as previous value
    $("#cancelNewMagazine").attr('data-previous-value', userInfo['sub_magazine_id'] || '');
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
                let newSubMagId = result[3]; // New sub magazine ID if created
                $formFields.attr('readonly', false);
                $("#password").attr('readonly', false);
                $("#password").attr('required', false);
                $("#isActive").prop('disabled', false);
                let option = '<option value="'+insertedId+'">'+fullName+'</option>';
                $("#list__user").append(option).selectpicker("refresh").val(insertedId).selectpicker("refresh");
                $("#passwordField, #isAdminField, #addProfileSubmit").hide();
                $("#editProfileSubmit").prop('disabled', false).show();
                $('#list__tht_hidden option').clone().appendTo('#list__tht');
                $('#list__smd_hidden option').clone().appendTo('#list__smd');
                $("#list__tht, #list__smd").prop('disabled', false).selectpicker('refresh');
                $("#addSMD, #addTHT, .deleteAllDevices").prop('disabled', false);

                // If new sub magazine was created, add it to the select and update the form
                if(newSubMagId) {
                    let newMagName = $("#new_magazine_name").val();
                    let nextNumber = $("#next_submag_number").val().toString().padStart(2, '0');
                    let fullMagName = "SUB MAG " + nextNumber + ": " + newMagName;
                    let magOption = '<option value="'+newSubMagId+'">'+fullMagName+'</option>';
                    $("#list__submag option[value='create_new']").before(magOption);
                    $("#list__submag").val(newSubMagId).selectpicker('refresh');
                    // Update the previous value to the newly created magazine
                    $("#cancelNewMagazine").attr('data-previous-value', newSubMagId);
                    hideNewMagazineInput();
                }
            }
        }
    });
}

function showNewMagazineInput() {
    $("#magazineSelectContainer").hide();
    $("#newMagazineContainer").show();
    $("#new_magazine_name").prop('required', true);
}

function hideNewMagazineInput() {
    $("#newMagazineContainer").hide();
    $("#magazineSelectContainer").show();
    $("#new_magazine_name").prop('required', false).val('');

    // Restore previous value from the cancel button
    let previousValue = $("#cancelNewMagazine").attr('data-previous-value') || '';
    $("#list__submag").val(previousValue).selectpicker('refresh');
}

// Handle magazine select change
$("#list__submag").change(function(){
    if($(this).val() === 'create_new') {
        showNewMagazineInput();
    } else {
        // Save the current selection as previous value when it's not "create_new"
        $("#cancelNewMagazine").attr('data-previous-value', $(this).val() || '');
    }
});

// Handle cancel new magazine
$("#cancelNewMagazine").click(function(){
    hideNewMagazineInput();
});

$("#list__user").change(function(){
    let userid = this.value;
    $("#passwordField, #isAdminField, #addProfileSubmit").hide();
    $("#password").attr('required', false);
    $("#thtUsed, #smdUsed").empty();
    hideNewMagazineInput(); // Reset magazine input when changing user
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
            $("#isActive").prop('disabled', false);
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

            // Handle new sub magazine creation in edit mode
            let newSubMagId = result[2];
            if(newSubMagId) {
                let newMagName = $("#new_magazine_name").val();
                let nextNumber = $("#next_submag_number").val().toString().padStart(2, '0');
                let fullMagName = "SUB MAG " + nextNumber + ": " + newMagName;
                let magOption = '<option value="'+newSubMagId+'">'+fullMagName+'</option>';
                $("#list__submag option[value='create_new']").before(magOption);
                $("#list__submag").val(newSubMagId).selectpicker('refresh');
                // Update the previous value to the newly created magazine
                $("#cancelNewMagazine").attr('data-previous-value', newSubMagId);
                hideNewMagazineInput();
            }
        }
    });
});

$("#createNewProfile").click(function(){
    $formFields.prop('disabled', false);
    $formFields.val('');
    $("#list__submag").val('').selectpicker('refresh'); // Explicitly clear and refresh submag
    $("#isActive").prop('checked', false);
    $("#list__tht, #list__smd").prop('disabled', true).selectpicker('refresh');
    $("#addSMD, #addTHT, .deleteAllDevices").prop('disabled', true);
    $("#thtUsed, #smdUsed").empty();
    $("#list__user").val('');
    $("#editProfileSubmit").hide();
    $("#passwordField, #isAdminField, #addProfileSubmit").show();
    $("#password").prop('required', true);
    $("#list__user").selectpicker('refresh');
    hideNewMagazineInput(); // Reset magazine input
    // Clear the previous value since we're creating a new user
    $("#cancelNewMagazine").removeAttr('data-previous-value');
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

    if(!$("#isActive").is(":checked")) {
        $("#addInactiveUserModal").modal('show');
        return;
    }

    addProfile();
});

$("#addInactiveUserSubmit").click(function(){
    $("#addInactiveUserModal").modal('hide');
    addProfile();
});

$("#addAdminProfileSubmit").click(function(){
    $("#addAdminProfileModal").modal('hide');
    if(!$("#isActive").is(":checked")) {
        $("#addInactiveUserModal").modal('show');
        return;
    }
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