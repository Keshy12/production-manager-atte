$("#uploadBomInput").change(function(){
    let fileName = $(this).val().split("\\").pop();
    $("#uploadBomLabel").html(fileName);
    $("#submitUploadBom").prop('disabled', false);

    let $form = $("#uploadBomForm");
    let actionUrl = $form.attr('action');
    let formData = new FormData($form[0]);
    $("#errorsContainer, #thtTBody, #smdTBody").empty();
    $("#tableContainer").css('visibility', 'hidden');
    $("#thtName, #thtVersion, #smdName, #smdLaminate, #smdVersion").empty();
    $.ajax({
        type: "POST",
        url: actionUrl,
        data: formData,
        cache: false,
        contentType: false,
        processData: false,
        success: function(data)
        {
            const $thtTBody = $('#thtTBody');
            const $smdTBody = $('#smdTBody');
            let result = JSON.parse(data);
            let fatalErrors = result[0];
            let nonFatalErrors = result[1];
            let THTBomFlat = result[2];
            let SMDBomFlat = result[3];
            $.each(fatalErrors, function(index, errorMessage) {
                let alertDiv = $('<div class="alert alert-danger alert-dismissible show fade" role="alert"></div>');
                alertDiv.append('<button type="button" class="close" data-dismiss="alert" aria-label="Close">&times;</button>');
                alertDiv.append(errorMessage);
                $('#errorsContainer').append(alertDiv);
            });
            $.each(nonFatalErrors, function(index, errorMessage) {
                let alertDiv = $('<div class="alert alert-warning alert-dismissible show fade" role="alert"></div>');
                alertDiv.append('<button type="button" class="close" data-dismiss="alert" aria-label="Close">&times;</button>');
                alertDiv.append(errorMessage);
                $('#errorsContainer').append(alertDiv);
            });
            if(Object.keys(fatalErrors).length !== 0) {
                return;
            }

            const THTInfo = {
                bomId: THTBomFlat["bomId"],
                deviceId: THTBomFlat["deviceId"],
                deviceVersion: THTBomFlat["deviceVersion"],
                bomFlat: THTBomFlat["csv"]
            };
            const SMDInfo = {
                bomId: SMDBomFlat["bomId"],
                deviceId: SMDBomFlat["deviceId"],
                laminateId: SMDBomFlat["laminateId"],
                laminateName: SMDBomFlat["laminateName"],
                deviceVersion: SMDBomFlat["deviceVersion"],
                bomFlat: SMDBomFlat["csv"]
            };
            $("#sendBom").attr("data-tht", JSON.stringify(THTInfo));
            $("#sendBom").attr("data-smd", JSON.stringify(SMDInfo));
            $("#tableContainer").css('visibility', 'visible');
            $("#thtName").text(THTBomFlat["deviceName"]);
            $("#thtVersion").text(THTBomFlat["deviceVersion"]);
            generateTableRows($thtTBody, THTBomFlat['db'], THTBomFlat['csv']);

            // Return if there is no SMD
            if(Object.keys(SMDBomFlat).length === 0) {
                return;
            }
            $("#smdName").text(SMDBomFlat["deviceName"]);
            $("#smdLaminate").text(SMDBomFlat["laminateName"]);
            $("#smdVersion").text(SMDBomFlat["deviceVersion"]);
            generateTableRows($smdTBody, SMDBomFlat['db'], SMDBomFlat['csv']);

        }
    });
});

$("#sendBom").click(function(){
    let thtData = $(this).attr("data-tht");
    let smdData = $(this).attr("data-smd");
    $.ajax({
        type: "POST",
        url: COMPONENTS_PATH+"/admin/bom/upload/upload-bom.php",
        data: {thtData: thtData, smdData: smdData}, // serializes the form's elements.
        success: function(data)
        {
            let result = JSON.parse(data);
            let resultMessage = result[0];
            let wasSuccessful = result[1];
            let resultAlertType = wasSuccessful ? "alert-success" : "alert-danger";
            let resultAlert = getAlertString(resultAlertType, resultMessage);
            $("#ajaxResult").append(resultAlert);
            if(wasSuccessful) $("#uploadBomInput").change();
        }
    });
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

function generateTableRows($tbody, dbBomFlat, csvBomFlat)
{
    // Convert object to array for easier manipulation
    let dbBomFlatArr = Object.values(dbBomFlat);
    let csvBomFlatArr = Object.values(csvBomFlat);

    dbBomFlatArr.forEach(component1 => {
        const matchingComponent2 = findAndRemoveMatchingComponent(component1, csvBomFlatArr) || {};
        const componentName1 = component1.componentName || '';
        const componentDescription1 = component1.componentDescription || '';
        const quantity1 = component1.quantity || '';
        const componentName2 = matchingComponent2.componentName || '';
        const componentDescription2 = matchingComponent2.componentDescription || '';
        const quantity2 = matchingComponent2.quantity || '';

        const row = $('<tr>').addClass('text-center');

        const td1 = $('<td>').html(`<b>${componentName1}</b><br><small>${componentDescription1}</small>`);
        const td2 = $('<td>').text(quantity1);
        const td3 = $('<td>').html(`<b>${componentName2}</b><br><small>${componentDescription2}</small>`);
        const td4 = $('<td>').text(quantity2);

        // Highlight mismatched data with red background
        if (!componentName2 || !quantity2 || componentName1 !== componentName2 || quantity1 !== quantity2) {
            td1.add(td2).add(td3).add(td4).addClass('table-danger');
        }

        row.append(td1, td2, td3, td4);
        $tbody.append(row);
    });

    // Also check for any components in object2 that weren't in object1 (and remove them)
    csvBomFlatArr.forEach(component2 => {
        const componentName1 = '';
        const componentDescription1 = '';
        const quantity1 = '';
        const componentName2 = component2.componentName || '';
        const componentDescription2 = component2.componentDescription || '';
        const quantity2 = component2.quantity || '';

        const row = $('<tr>').addClass('text-center');

        const td1 = $('<td>').html(`<b>${componentName1}</b><br><small>${componentDescription1}</small>`).addClass('table-danger');
        const td2 = $('<td>').text(quantity1).addClass('table-danger');
        const td3 = $('<td>').html(`<b>${componentName2}</b><br><small>${componentDescription2}</small>`);
        const td4 = $('<td>').text(quantity2);

        row.append(td1, td2, td3, td4);
        $tbody.append(row);
    });
}

// Helper function to find and remove a matching component in the array
function findAndRemoveMatchingComponent(component, array) {
    const index = array.findIndex(item => 
        item.type === component.type &&
        item.componentId === component.componentId &&
        item.componentName === component.componentName
    );

    if (index !== -1) {
        return array.splice(index, 1)[0];
    }

    return null;
}