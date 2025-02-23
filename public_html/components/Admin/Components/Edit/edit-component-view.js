let $formFields = $("#name, #description, #partGroup, #partType, #jm");

function clearForm()
{
    $("#header").text("Dodaj komponent")
    $("#name, #description, #partGroup, #partType, #jm, #file-input").val('');
    $("#partGroup, #partType, #jm").selectpicker('refresh');
    $("#circleCheckbox, #triangleCheckbox, #squareCheckbox, #autoProduceCheckbox").prop('checked', false);
    $("#list__device").val('').selectpicker('refresh');
    $("#deviceImage").attr('src', ROOT_DIR+"/public_html/assets/img/production/default.webp");
    
}

$("#deviceType").change(function(){
    $("#list__device").empty();
    let deviceType = this.value;
    $('#list__'+deviceType+'_hidden option').clone().appendTo('#list__device');
    $("#list__device").prop('disabled', false).selectpicker('refresh');
    $("#previousItem, #nextItem, #deselect").prop('disabled', false)
    $("#componentFormContainer, #cloneDevice").show();
    $("#thtAdditionalFields, #partsAdditionalFields, #saveChange, #cloneSelect, #autoProduceFields").hide();
    $("#"+deviceType+"AdditionalFields").show();
    if(deviceType === 'tht' || deviceType === 'sku') {
        $("#autoProduceFields").show();
    }
    clearForm();
    showAddFields();
    $("#partGroup, #partType, #jm").prop('required', (deviceType === 'parts'));
});

function getDeviceValues(deviceType, deviceId)
{
    let result = null;
    $.ajax({
        type: "POST",
        async: false,
        url: COMPONENTS_PATH+"/admin/components/edit/get-component-values.php",
        data: { deviceType: deviceType, deviceId: deviceId},
        success: function (data) {
            result = JSON.parse(data);
        }
    });
    return result;
}

function writeValuesToForm(values)
{
    $("#name").val(values.name);
    $("#description").val(values.description);
    selectOptionsInForm(values);
    selectCheckboxesInForm(values);
    $("#file-input").val('');
}

function selectOptionsInForm(values)
{
    $("#partGroup").val(values.PartGroup).selectpicker('refresh');
    let partType = values.PartType == null ? 0 : values.PartType;
    $("#partType").val(partType).selectpicker('refresh');
    $("#jm").val(values.JM).selectpicker('refresh');
}

function selectCheckboxesInForm(values)
{
    const triangleChecked = values.triangle_checked == true;
    const circleChecked = values.circle_checked == true;
    const squareChecked = values.square_checked == true;
    const isActive = values.isActive == true;
    const isAutoProduced = values.isAutoProduced == true;
    $("#triangleCheckbox").prop('checked', triangleChecked);
    $("#circleCheckbox").prop('checked', circleChecked);
    $("#squareCheckbox").prop('checked', squareChecked);
    $("#isActiveCheckbox").prop('checked', isActive);
    $("#autoProduceCheckbox").prop('checked', isAutoProduced);
}

$("#autoProduceCheckbox").change(function() {
    $("#autoProduceVersionField").toggle(this.checked);
});

function showEditFields()
{
    $("#addDevice, #cloneField").hide();
    $("#saveChange, #isActiveField").show();
    $("#header").text("Edytuj komponent")
}

function showAddFields()
{
    $("#addDevice, #cloneField").show();
    $("#saveChange, #isActiveField").hide();
    $("#header").text("Dodaj komponent")
}

function loadDevicePicture(deviceType, deviceId)
{
    let defaultSrc = ROOT_DIR+"/public_html/assets/img/production/default.webp";
    let toLoadSrc = ROOT_DIR+"/public_html/assets/img/production/"+deviceType+"/"+deviceId+".jpg?v=" + (new Date()).getTime();
    $("#deviceImage").attr('src', toLoadSrc)
                        .on('error', function(){
                            $(this).attr('src', defaultSrc);
                        });
}

$("#list__device").change(function(){
    if(this.value == '') {
        clearForm();
        showAddFields();
        return;
    }
    let deviceType = $("#deviceType").val();
    let deviceId = this.value;
    let deviceValues = getDeviceValues(deviceType, deviceId);
    writeValuesToForm(deviceValues);
    showEditFields();
    generateAdditionalFields(deviceType);
    loadDevicePicture(deviceType, deviceId);
});

function generateAdditionalFields(type)
{
    if(type === 'sku' || type === "tht") {
        let possibleVersions = $("#list__device option:selected").data("jsonversions");
        generateVersionSelect(possibleVersions);
    }
}

$("#previousItem").click(function(){
    let $selectedOption = $("#list__device option:selected");
    $selectedOption.prop('selected', false)
                    .prev().prop('selected', true);
    $("#list__device").selectpicker('refresh').change();
});

$("#nextItem").click(function(){
    let $selectedOption = $("#list__device option:selected");
    $selectedOption.prop('selected', false)
                    .next()
                    .prop('selected', true);
    $("#list__device").selectpicker('refresh').change();
});

$("#deselect").click(function(){
    clearForm();
    showAddFields();
});

function getAlertString(alertType, alertMessage)
{
    return `<div class="alert `+alertType+` alert-dismissible fade show" role="alert">
                `+alertMessage+`
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>`;
}

$("#saveChange").click(function(e){
    if(!$('#componentForm')[0].checkValidity()) {
        $('#componentForm')[0].reportValidity();
        return;
    }
    e.preventDefault();
    $formFields.attr('readonly', true);

    let $form = $("#componentForm");
    let actionUrl = $form.attr('action');
    let formData = new FormData($form[0]);
    let deviceType = $("#deviceType").val();
    let deviceId = $("#list__device").val();
    formData.append("deviceType", deviceType);
    formData.append("deviceId", deviceId);
    $.ajax({
        type: "POST",
        url: actionUrl,
        data: formData, // serializes the form's elements.
        cache: false,
        contentType: false,
        processData: false,
        success: function(data)
        {
            $("#ajaxResult").empty();
            $formFields.attr('readonly', false);
            let result = JSON.parse(data);
            let editResultMessage = result[0];
            let editAlertType = result[1] ? "alert-success" : "alert-danger";
            let editAlert = getAlertString(editAlertType, editResultMessage);
            $("#ajaxResult").append(editAlert);

            let fileResultMessage = result[2];
            if(fileResultMessage !== null)
            {
                let fileAlertType = result[3] ? "alert-success" : "alert-danger";
                let fileAlert = getAlertString(fileAlertType, fileResultMessage);
                $("#ajaxResult").append(fileAlert);
            }

            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);
            let deviceName = $("#name").val();
            let deviceDescription = $("#description").val();
            let $option = $('#list__device option[value="'+deviceId+'"]');
            let $hiddenOption = $('#list__'+deviceType+'_hidden option[value="'+deviceId+'"]');
            $option.html(deviceName)
                    .attr("data-subtext", deviceDescription)
                    .attr("data-tokens", deviceName+" "+deviceDescription);
            $hiddenOption.html(deviceName)
                    .attr("data-subtext", deviceDescription)
                    .attr("data-tokens", deviceName+" "+deviceDescription);
            // Without firing change(), the subtext doesn't get updated
            $('#list__device').selectpicker('refresh').change();
        }
    });
});

function renderInputImage(input) {
    if (input.files && input.files[0]) {
        var reader = new FileReader();

        reader.onload = function (e) {
            $('#deviceImage').attr('src', e.target.result);
        }

        reader.readAsDataURL(input.files[0]);
    }
}

$("#file-input").change(function(){
    renderInputImage(this);
});

$("#addDevice").click(function(e){
    if(!$('#componentForm')[0].checkValidity()) {
        $('#componentForm')[0].reportValidity();
        return;
    }
    e.preventDefault();
    $formFields.attr('readonly', true);

    let $form = $("#componentForm");
    let formData = new FormData($form[0]);
    let deviceType = $("#deviceType").val();
    formData.append("deviceType", deviceType);
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/admin/components/edit/create-component.php",
        data: formData, // serializes the form's elements.
        cache: false,
        contentType: false,
        processData: false,
        success: function(data)
        {
            $("#ajaxResult").empty();
            $formFields.attr('readonly', false);
            let result = JSON.parse(data);
            let editResultMessage = result[0];
            let editAlertType = result[1] ? "alert-success" : "alert-danger";
            let editAlert = getAlertString(editAlertType, editResultMessage);
            $("#ajaxResult").append(editAlert);
            if(!result[1]) return;
            let fileResultMessage = result[2];
            if(fileResultMessage !== null)
            {
                let fileAlertType = result[3] ? "alert-success" : "alert-danger";
                let fileAlert = getAlertString(fileAlertType, fileResultMessage);
                $("#ajaxResult").append(fileAlert);
            }

            setTimeout(function() {
                $(".alert-success").alert('close');
            }, 2000);

            let insertedId = result[4];
            let deviceName = $("#name").val();
            let deviceDescription = $("#description").val();
            let $selectFields = $('#list__device, #list__'+deviceType+'_hidden');
            $selectFields.append(`
                <option data-subtext="`+deviceDescription+`"
                data-tokens="`+deviceName+` `+deviceDescription+`"
                value="`+insertedId+`" selected>`+deviceName+`</option>
            `)
            // Without firing change(), the subtext doesn't get updated
            $('#list__device').selectpicker('refresh').change();
        }
    });
});

$("#cloneDevice").click(function(){
    let deviceType = $("#deviceType").val();
    $(this).hide();
    $("#list__device").prop('disabled', true).selectpicker('refresh');
    $("#previousItem, #nextItem, #deselect").prop('disabled', true)
    $("#list__device_to_clone").empty();
    $('#list__'+deviceType+'_hidden option').clone().appendTo('#list__device_to_clone');
    $("#cloneSelect").show();
    $("#list__device_to_clone").selectpicker('refresh');
});

$("#hideCloneSelect").click(function(){
    clearForm();
    $("#cloneSelect").hide();
    $("#list__device").prop('disabled', false).selectpicker('refresh');
    $("#previousItem, #nextItem, #deselect").prop('disabled', false)
    $("#cloneDevice").show();
})

$("#list__device_to_clone").change(function(){
    $("#cloneSelect").hide();
    $("#list__device").prop('disabled', false).selectpicker('refresh');
    $("#previousItem, #nextItem, #deselect").prop('disabled', false)
    $("#cloneDevice").show();
    let deviceType = $("#deviceType").val();
    let deviceId = this.value;
    let deviceValues = getDeviceValues(deviceType, deviceId); 
    writeValuesToForm(deviceValues);
});

function generateVersionSelect(possibleVersions){
    let $versionSelect = $("#autoProduceVersionSelect");
    $versionSelect.empty();
    if(Object.keys(possibleVersions).length == 1) {
        if(possibleVersions[0] == null)
        {
            $versionSelect.selectpicker('destroy');
            $versionSelect.html("<option value=\"n/d\" selected>n/d</option>");
            $versionSelect.selectpicker('refresh');
            return;
        }
        let version_id = Object.keys(possibleVersions)[0];
        let version = possibleVersions[version_id][0];
        let option = "<option value='"+version+"' selected>"+version+"</option>";
        $versionSelect.append(option);
        $versionSelect.selectpicker('destroy');
    } else {
        for (let version_id in possibleVersions)
        {
            let version = possibleVersions[version_id][0];
            let option = "<option value='"+version+"'>"+version+"</option>";
            $versionSelect.append(option);
        }
    }
    $versionSelect.selectpicker('refresh');
}