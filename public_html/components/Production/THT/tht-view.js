function generateLastProduction(deviceId, lastId, lastIdRange){
    let data = {deviceType: 'tht', deviceId: deviceId};
    if (lastIdRange) {
        data.lastIdRange = lastIdRange;
    } else if (lastId) {
        data.lastId = lastId;
    }
    $("#lastProduction").load('../public_html/components/production/last-production-table.php', data, function() {
        // Update button state after table loads
        updateRollbackButtonState();
    });
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

function generateVersionSelect(possibleVersions){
    $("#version").empty();
    if(Object.keys(possibleVersions).length == 1) {
        let version_id = Object.keys(possibleVersions)[0];
        let version = possibleVersions[version_id];
        let option = null;
        if(version === null) {
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

function rollbackLastProduction() {
    let deviceId = $("#list__device").val();
    if (!deviceId) {
        alert("Najpierw wybierz urządzenie");
        return;
    }

    // Get highlighted row IDs
    let highlightedIds = [];
    $('.highlighted-row').each(function() {
        highlightedIds.push($(this).data('row-id'));
    });

    if (highlightedIds.length === 0) {
        alert("Brak zaznaczonych wpisów do cofnięcia");
        return;
    }

    if (!confirm("Czy na pewno chcesz cofnąć zaznaczone wpisy produkcji (" + highlightedIds.length + " szt.)?")) {
        return;
    }

    $("#rollbackBtn").html("Cofanie...").prop("disabled", true);

    $.ajax({
        type: "POST",
        url: "../public_html/components/production/rollback-production.php",
        data: {
            deviceType: 'tht',
            deviceId: deviceId,
            rollbackIds: highlightedIds.join(',')
        },
        success: function(data) {
            const result = JSON.parse(data);
            $("#rollbackBtn").html("Cofnij ostatnią").prop("disabled", true);

            if (result.success) {
                generateLastProduction(deviceId, null, result.rollbackIdRange);
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
            $("#rollbackBtn").html("Cofnij ostatnią").prop("disabled", true);
            $("#alerts").empty();
            $("#alerts").append('<div class="alert alert-danger alert-dismissible fade show" role="alert">' +
                'Błąd podczas cofania produkcji' +
                '<button type="button" class="close" data-dismiss="alert">&times;</button></div>');
        }
    });
}

$("#list__device").change(function(){
    $("#laminate, #version, #alerts").empty();
    $("#laminate, #version").selectpicker('refresh');
    let possibleVersions = $("#list__device option:selected").data("jsonversions");
    let marking = $("#list__device option:selected").data("jsonmarking");
    let deviceDescription = $("#list__device option:selected").data("subtext");
    $("#device_description").val(deviceDescription);
    generateVersionSelect(possibleVersions);
    generateMarking(marking);
    generateLastProduction(this.value);
});

$("#form").submit(function(e) {
    //Avoid to execute the actual submit of the form.
    e.preventDefault();
    //When doing correction (negative quantity), commment is required.
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
        data: $form.serialize(), // serializes the form's elements.
        success: function(data)
        {
            $("#send").html("Wyślij");
            $("#send").prop("disabled", false);
            $("#alerts").empty();

            try {
                const result = JSON.parse(data);
                let firstId = result[0];
                let lastId = result[1];
                let alerts = result[2];

                // Create ID range for highlighting
                let idRange = (firstId === lastId) ? firstId : firstId + '-' + lastId;
                generateLastProduction($("#list__device option:selected").val(), null, idRange);

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

            // Handle HTTP error responses
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
    var highlightedRows = $('.highlighted-row').length;
    var $rollbackBtn = $('#rollbackBtn');

    if (highlightedRows > 0) {
        $rollbackBtn.prop('disabled', false).removeClass('btn-secondary').addClass('btn-warning');
        $rollbackBtn.text('Cofnij zaznaczone (' + highlightedRows + ')');
    } else {
        $rollbackBtn.prop('disabled', true).removeClass('btn-warning').addClass('btn-secondary');
        $rollbackBtn.text('Cofnij ostatnią');
    }
}

$(document).ready(function(){
    updateRollbackButtonState();
    let autoSelectValues = JSON.parse($("#list__device").attr("data-auto-select"));
    if(autoSelectValues.length) {
        $("#list__device").selectpicker('val', autoSelectValues[0]).change();
        $("#version").selectpicker('val', autoSelectValues[1]).change();
    }
});