// Get device type from script tag URL parameter
const urlParams = new URLSearchParams(document.currentScript.src.split('?')[1]);
const DEVICE_TYPE = urlParams.get('deviceType') || 'smd';
const IS_SMD = DEVICE_TYPE === 'smd';

function generateLastProduction(deviceId, transferGroupId){
    let data = {deviceType: DEVICE_TYPE, deviceId: deviceId};
    if (transferGroupId) {
        data.transferGroupId = transferGroupId;
    }

    // Preserve showCancelled state if checkbox exists
    var showCancelledCheckbox = $('#showCancelledCheckbox');
    if (showCancelledCheckbox.length && showCancelledCheckbox.is(':checked')) {
        data.showCancelled = '1';
    }

    // Preserve noGrouping state if checkbox exists
    var noGroupingCheckbox = $('#noGroupingCheckbox');
    if (noGroupingCheckbox.length && noGroupingCheckbox.is(':checked')) {
        data.noGrouping = '1';
    }

    $("#lastProduction").load('../public_html/components/production/last-production-table.php', data, function() {
        updateRollbackButtonState();
    });
}

function generateVersionSelect(possibleVersions){
    $("#version").empty();
    if(Object.keys(possibleVersions).length == 1) {
        let version_id = Object.keys(possibleVersions)[0];
        let version = possibleVersions[version_id];
        let option = null;
        if(version === null || version[0] === null) {
            option = "<option value='n/d' selected>n/d</option>";
        } else {
            option = "<option value='"+version[0]+"' selected>"+version[0]+"</option>";
        }
        $("#version").append(option);
        $("#version").selectpicker('destroy');
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
        $("#laminate").append(option);
        $("#laminate").selectpicker('destroy');
        $("#laminate").selectpicker('');
        generateVersionSelect(possibleLaminates[laminate_id]['versions']);
    } else {
        for (let laminate_id in possibleLaminates)
        {
            let laminate_name = possibleLaminates[laminate_id][0];
            let versions = JSON.stringify(possibleLaminates[laminate_id]['versions']);
            let option = "<option value='"+laminate_id+"' data-jsonversions='"+versions+"'>"+laminate_name+"</option>";
            $("#laminate").append(option);
        }
    }
    $("#laminate").selectpicker('refresh');
}

function generateMarking(marking){
    $("#marking").empty();
    for (let mark in marking)
    {
        mark = parseInt(mark);
        let bMarking = marking[mark];
        let fileName = (mark+1)+"off.png";
        if(bMarking) fileName = (mark+1)+"on.png";
        $("#marking").append(`<img style='width:33%;' 
                                   class='img-fluid mt-4' 
                                   src='/atte_ms_new/public_html/assets/img/production/tht/marking/`+fileName+`' 
                                   alt='oznaczenie'>`);
    }
}

function rollbackLastProduction() {
    let deviceId = $("#list__device").val();
    if (!deviceId) {
        alert("Najpierw wybierz urządzenie");
        return;
    }

    // Collect selected transfer groups and individual entries
    let selectedGroups = [];
    let selectedEntries = [];
    let groupsFullySelected = new Set();

    // Check which groups are fully selected via group checkbox
    $('.group-checkbox:checked').each(function() {
        let groupId = $(this).data('group-id');
        if (groupId) {
            selectedGroups.push(groupId);
            groupsFullySelected.add(groupId);
        }
    });

    // Check individual row checkboxes (only if their group is not fully selected)
    $('.row-checkbox:checked').each(function() {
        let rowId = $(this).data('row-id');
        let groupId = $(this).data('transfer-group-id');

        // Only include individual entries if their group isn't fully selected
        if (!groupsFullySelected.has(groupId)) {
            selectedEntries.push(rowId);
        }
    });

    if (selectedGroups.length === 0 && selectedEntries.length === 0) {
        alert("Brak zaznaczonych wpisów do cofnięcia");
        return;
    }

    let confirmMessage = "Czy na pewno chcesz cofnąć zaznaczone wpisy produkcji?";
    if (selectedGroups.length > 0 && selectedEntries.length > 0) {
        confirmMessage = `Czy na pewno chcesz cofnąć ${selectedGroups.length} grup i ${selectedEntries.length} pojedynczych wpisów?`;
    } else if (selectedGroups.length > 0) {
        confirmMessage = `Czy na pewno chcesz cofnąć ${selectedGroups.length} grup transferowych?`;
    } else {
        confirmMessage = `Czy na pewno chcesz cofnąć ${selectedEntries.length} pojedynczych wpisów?`;
    }

    if (!confirm(confirmMessage)) {
        return;
    }

    $("#rollbackBtn").html("Cofanie...").prop("disabled", true);

    $.ajax({
        type: "POST",
        url: "../public_html/components/production/rollback-production.php",
        data: {
            deviceType: DEVICE_TYPE,
            deviceId: deviceId,
            transferGroupIds: selectedGroups.join(','),
            entryIds: selectedEntries.join(',')
        },
        success: function(data) {
            const result = JSON.parse(data);
            $("#rollbackBtn").html("Cofnij zaznaczone").prop("disabled", true);

            if (result.success) {
                generateLastProduction(deviceId, result.transferGroupId);
                $("#alerts").empty();
                $("#alerts").append('<div class="alert alert-success alert-dismissible fade show" role="alert">' +
                    result.message +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');

                if (result.alerts && result.alerts.length > 0) {
                    result.alerts.forEach(function(alert) {
                        $("#alerts").append(alert);
                    });
                }
            } else {
                $("#alerts").empty();
                $("#alerts").append('<div class="alert alert-danger alert-dismissible fade show" role="alert">' +
                    result.message +
                    '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
            }
        },
        error: function() {
            $("#rollbackBtn").html("Cofnij zaznaczone").prop("disabled", true);
            $("#alerts").empty();
            $("#alerts").append('<div class="alert alert-danger alert-dismissible fade show" role="alert">' +
                'Błąd podczas cofania produkcji' +
                '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
        }
    });
}

$("#list__device").change(function(){
    if (IS_SMD) {
        $("#laminate, #version").empty();
        $("#laminate, #version").selectpicker('refresh');
        let possibleLaminates = $("#list__device option:selected").data("jsonlaminates");
        let deviceDescription = $("#list__device option:selected").data("subtext");
        $("#device_description").val(deviceDescription);
        generateLaminateSelect(possibleLaminates);
    } else {
        $("#version, #alerts").empty();
        $("#version").selectpicker('refresh');
        let possibleVersions = $("#list__device option:selected").data("jsonversions");
        let marking = $("#list__device option:selected").data("jsonmarking");
        let deviceDescription = $("#list__device option:selected").data("subtext");
        $("#device_description").val(deviceDescription);
        generateVersionSelect(possibleVersions);
        generateMarking(marking);
    }
    generateLastProduction(this.value);
});

if (IS_SMD) {
    $("#laminate").change(function(){
        let possibleVersions = $("#laminate option:selected").data("jsonversions");
        generateVersionSelect(possibleVersions);
    });
}

$("#form").submit(function(e) {
    e.preventDefault();
    if($("#quantity").val() < 0 && !$.trim($("#comment").val()))
    {
        $('#correctionModal').modal('show');
        return;
    }
    $("#send").html("Wysyłanie");
    $("#send").prop("disabled", true);
    var $form = $(this);
    var actionUrl = $form.attr('action');
    $.ajax({
        type: "POST",
        url: actionUrl,
        data: $form.serialize(),
        success: function(data)
        {
            $("#send").html("Wyślij");
            $("#send").prop("disabled", false);
            $("#alerts").empty();

            try {
                const result = JSON.parse(data);
                let transferGroupId = result[0];
                let alerts = result[1];

                generateLastProduction($("#list__device option:selected").val(), transferGroupId);

                alerts.forEach(function(alert) {
                    $("#alerts").append(alert);
                });
            } catch (e) {
                $("#alerts").append('<div class="alert alert-danger alert-dismissible fade show" role="alert">' +
                    data +
                    '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                    '<span aria-hidden="true">&times;</span>' +
                    '</button>' +
                    '</div>');
            }
        },
        error: function(xhr, status, error) {
            $("#send").html("Wyślij");
            $("#send").prop("disabled", false);
            $("#alerts").empty();

            let errorMessage = "Wystąpił błąd podczas przetwarzania żądania.";
            if (xhr.responseText) {
                errorMessage = xhr.responseText;
            }

            $("#alerts").append('<div class="alert alert-danger alert-dismissible fade show" role="alert">' +
                errorMessage +
                '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                '<span aria-hidden="true">&times;</span>' +
                '</button>' +
                '</div>');
        }
    });
});

function updateRollbackButtonState() {
    var $rollbackBtn = $('#rollbackBtn');

    // Count selected groups
    var selectedGroups = $('.group-checkbox:checked').length;

    // Count selected individual rows (excluding those in fully selected groups)
    var groupsFullySelected = new Set();
    $('.group-checkbox:checked').each(function() {
        var groupId = $(this).data('group-id');
        if (groupId) groupsFullySelected.add(groupId);
    });

    var selectedIndividualRows = 0;
    $('.row-checkbox:checked').each(function() {
        var groupId = $(this).data('transfer-group-id');
        if (!groupsFullySelected.has(groupId)) {
            selectedIndividualRows++;
        }
    });

    var totalSelected = selectedGroups + selectedIndividualRows;

    if (totalSelected > 0) {
        $rollbackBtn.prop('disabled', false).removeClass('btn-secondary').addClass('btn-warning');

        if (selectedGroups > 0 && selectedIndividualRows > 0) {
            $rollbackBtn.text('Cofnij zaznaczone (' + selectedGroups + ' grup, ' + selectedIndividualRows + ' wpisów)');
        } else if (selectedGroups > 0) {
            $rollbackBtn.text('Cofnij zaznaczone (' + selectedGroups + ' grup)');
        } else {
            $rollbackBtn.text('Cofnij zaznaczone (' + selectedIndividualRows + ' wpisów)');
        }
    } else {
        $rollbackBtn.prop('disabled', true).removeClass('btn-warning').addClass('btn-secondary');
        $rollbackBtn.text('Cofnij zaznaczone');
    }
}

$(document).ready(function(){
    updateRollbackButtonState();
    let autoSelectValues = JSON.parse($("#list__device").attr("data-auto-select"));
    if(autoSelectValues.length) {
        $("#list__device").selectpicker('val', autoSelectValues[0]).change();
        if (IS_SMD && autoSelectValues.length > 1) {
            $("#laminate").selectpicker('val', autoSelectValues[1]).change();
            if (autoSelectValues.length > 2) {
                $("#version").selectpicker('val', autoSelectValues[2]).change();
            }
        } else if (!IS_SMD && autoSelectValues.length > 1) {
            $("#version").selectpicker('val', autoSelectValues[1]).change();
        }
    }
});