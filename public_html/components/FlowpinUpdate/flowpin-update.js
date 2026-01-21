// Helper function for POST requests
async function postData(url = "", data = {}) {
    const response = await fetch(url, {
        method: "POST",
        credentials: "omit",
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams(data)
    });
    return response;
}

let progressInterval = null;
let currentSessionId = $('#latestSessionId').val() || null;
let isStartingNewUpdate = false;

// Check if there's a running session on page load
if (currentSessionId) {
    // Only start polling if the session is actually running or we just started one
    // We'll let checkProgress decide based on the initial state
    startProgressPolling(currentSessionId);
}


// Analysis button click handler
$("#btnAnalyze").click(function() {
    $(this).prop("disabled", true);
    $("#spinnerAnalyze").show();
    $("#analysisResult").html("");
    $("#issuesSummary").hide();

    fetch(COMPONENTS_PATH + "/FlowpinUpdate/flowpin-sku-analyze.php", {
        method: "POST"
    })
    .then(response => response.json())
    .then(data => {
        $("#spinnerAnalyze").hide();
        $("#btnAnalyze").prop("disabled", false);

        if (data.success) {
            displayAnalysisResults(data);
        } else {
            $("#analysisResult").html(
                '<div class="alert alert-danger">Błąd podczas analizy: ' +
                (data.error || 'Nieznany błąd') + '</div>'
            );
        }
    })
    .catch(err => {
        $("#spinnerAnalyze").hide();
        $("#btnAnalyze").prop("disabled", false);
        $("#analysisResult").html(
            '<div class="alert alert-danger">Błąd połączenia: ' + err.message + '</div>'
        );
    });
});

// Display analysis results
function displayAnalysisResults(data) {
    const totalIssues = data.summary.user_issues + data.summary.device_issues + data.summary.warehouse_issues;

    let html = '<div class="alert alert-info">';
    html += '<h4>Wyniki analizy:</h4>';
    html += '<p>Całkowita liczba rekordów do przetworzenia: <strong>' + data.total_records + '</strong></p>';

    if (totalIssues > 0) {
        html += '<p>Znaleziono <strong>' + totalIssues + '</strong> kategorii problemów:</p>';
        html += '<ul>';
        html += '<li>Problemy z użytkownikami: <strong>' + data.summary.user_issues + '</strong></li>';
        html += '<li>Problemy z urządzeniami/SKU: <strong>' + data.summary.device_issues + '</strong></li>';
        html += '<li>Problemy z magazynami: <strong>' + data.summary.warehouse_issues + '</strong></li>';
        html += '</ul>';
        html += '<p class="text-warning"><strong>Uwaga:</strong> Te problemy nie blokują aktualizacji. Dla rekordów z problemami zostaną utworzone powiadomienia.</p>';
    } else {
        html += '<p class="text-success"><strong>Nie znaleziono żadnych problemów!</strong> Wszystkie rekordy powinny zostać przetworzone pomyślnie.</p>';
    }

    html += '</div>';

    $("#analysisResult").html(html);

    if (totalIssues > 0) {
        // Display issues in detail
        displayIssues(data.issues);
        $("#issuesSummary").show();
    }
}

// Display detailed issues
function displayIssues(issues) {
    // User issues
    $("#userIssueCount").text(issues.users.length);
    $("#userIssuesList").empty();
    issues.users.forEach(issue => {
        $("#userIssuesList").append(
            '<li class="list-group-item">' +
            '<strong>' + issue.email + '</strong><br>' +
            '<small>' + issue.reason + '</small><br>' +
            '<span class="badge badge-secondary">' + issue.count + ' rekordów</span>' +
            '</li>'
        );
    });

    // Device issues
    $("#deviceIssueCount").text(issues.devices.length);
    $("#deviceIssuesList").empty();
    issues.devices.forEach(issue => {
        $("#deviceIssuesList").append(
            '<li class="list-group-item">' +
            '<strong>SKU ID: ' + issue.sku_id + '</strong> (' + issue.sku_name + ')<br>' +
            '<small>' + issue.reason + '</small><br>' +
            '<span class="badge badge-secondary">' + issue.count + ' rekordów</span>' +
            '</li>'
        );
    });

    // Warehouse issues
    $("#warehouseIssueCount").text(issues.warehouses.length);
    $("#warehouseIssuesList").empty();
    issues.warehouses.forEach(issue => {
        $("#warehouseIssuesList").append(
            '<li class="list-group-item">' +
            '<strong>Magazyn ID: ' + issue.magazine_id + '</strong><br>' +
            '<small>' + issue.reason + '</small><br>' +
            '<span class="badge badge-secondary">' + issue.count + ' rekordów</span>' +
            '</li>'
        );
    });
}

// Reset session button click handler
$("#btnResetSession").click(function() {
    const sessionId = $(this).data('session-id');

    if (!confirm("Czy na pewno chcesz zresetować zablokowaną sesję?")) {
        return;
    }

    $(this).prop("disabled", true).text("Resetowanie...");

    postData(COMPONENTS_PATH + "/FlowpinUpdate/reset-session.php", { session_id: sessionId })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert("Sesja została zresetowana. Strona zostanie przeładowana.");
                location.reload();
            } else {
                alert("Błąd resetowania sesji: " + (data.error || 'Nieznany błąd'));
                $(this).prop("disabled", false).html('<i class="bi bi-x-circle"></i> Resetuj zablokowaną sesję');
            }
        })
        .catch(err => {
            alert("Błąd połączenia: " + err.message);
            $(this).prop("disabled", false).html('<i class="bi bi-x-circle"></i> Resetuj zablokowaną sesję');
        });
});

// Update button click handler
$("#btnUpdate").click(function() {
    if (!confirm("Czy na pewno chcesz uruchomić aktualizację FlowPin? Proces może potrwać kilka minut.")) {
        return;
    }

    $(this).prop("disabled", true);
    $("#btnAnalyze").prop("disabled", true);
    $("#spinnerUpdate").show();
    $("#progressSection").show();
    $("#updateResult").html("");

    // Reset progress display
    updateProgressDisplay({
        percentage: 0,
        processed_records: 0,
        total_records: 0,
        current_operation_type: 'Inicjalizacja...',
        current_event_id: '-',
        status: 'running'
    });

    // Start the update process
    isStartingNewUpdate = true;
    currentSessionId = null;

    fetch("/atte_ms_new/src/cron/flowpin-sku-update.php", {
        method: "POST",
        credentials: "omit"
    })
    .then(async response => {
        const fullText = await response.text();
        
        $("#spinnerUpdate").hide();

        // Stop polling if still active
        if (progressInterval) {
            clearInterval(progressInterval);
            progressInterval = null;
        }

        // Extract session_id from first line if present (JSON format)
        let logData = fullText;
        const lines = fullText.split('\n');
        if (lines.length > 0 && lines[0].trim().startsWith('{')) {
            try {
                const sessionInfo = JSON.parse(lines[0]);
                if (sessionInfo.session_id && !currentSessionId) {
                    currentSessionId = sessionInfo.session_id;
                }
                // Remove the JSON line from logs display
                logData = lines.slice(1).join('\n');
            } catch (e) {
                // Not JSON, use full data
            }
        }

        // Final progress check to ensure UI is updated with 100% and correct counts
        let finalData = null;
        if (currentSessionId) {
            finalData = await checkProgress(currentSessionId, true);
        }

        const alertClass = (finalData && finalData.status === 'error') ? 'alert-danger' : 'alert-success';
        const titleText = (finalData && finalData.status === 'error') ? 'Aktualizacja zakończona z błędem!' : 'Aktualizacja zakończona!';
        const recordSummary = finalData ? `<p>Przetworzono <strong>${finalData.processed_records}</strong> z <strong>${finalData.total_records}</strong> rekordów.</p>` : '';

        $("#updateResult").html(
            `<div class="alert ${alertClass}">` +
            `<h4>${titleText}</h4>` +
            recordSummary +
            '<p>Sprawdź logi po więcej szczegółów.</p>' +
            '<pre style="max-height: 300px; overflow-y: auto;">' + logData + '</pre>' +
            '</div>'
        );

        // Re-enable buttons after completion
        setTimeout(() => {
            $("#btnUpdate").prop("disabled", false);
            $("#btnAnalyze").prop("disabled", false);
        }, 2000);
    })
    .catch(err => {
        $("#spinnerUpdate").hide();
        if (progressInterval) {
            clearInterval(progressInterval);
            progressInterval = null;
        }

        $("#updateResult").html(
            '<div class="alert alert-danger">Błąd podczas aktualizacji: ' + err.message + '</div>'
        );

        $("#btnUpdate").prop("disabled", false);
        $("#btnAnalyze").prop("disabled", false);
    });

    // Start polling for progress immediately
    // The session_id will be captured from get-progress.php response
    startProgressPolling(null);
});

// Start polling for progress
function startProgressPolling(sessionId) {
    currentSessionId = sessionId;

    // Clear any existing interval
    if (progressInterval) {
        clearInterval(progressInterval);
    }

    // Poll every 2 seconds
    progressInterval = setInterval(() => {
        checkProgress(currentSessionId);
    }, 2000);

    // Also check immediately
    checkProgress(currentSessionId);
}

// Check progress
async function checkProgress(sessionId, isFinal = false) {
    const data = sessionId ? { session_id: sessionId } : {};

    try {
        const response = await postData(COMPONENTS_PATH + "/FlowpinUpdate/get-progress.php", data);
        const dataJson = await response.json();

        if (dataJson.success) {
            // If we are starting a new update and didn't provide a specific sessionId, 
            // ignore any session that is already finished (to avoid picking up the previous run)
            if (!sessionId && isStartingNewUpdate && dataJson.status !== 'running') {
                return null;
            }

            // We found the new session (either specifically requested or picked up by polling)
            if (isStartingNewUpdate && (dataJson.status === 'running' || sessionId)) {
                isStartingNewUpdate = false;
            }

            // Store session ID if we got it
            if (!currentSessionId && dataJson.session_id) {
                currentSessionId = dataJson.session_id;
            }

            updateProgressDisplay(dataJson);
            
            // Stop polling if completed or error (unless it's just a final check)
            if (!isFinal && (dataJson.status === 'completed' || dataJson.status === 'error')) {
                if (progressInterval) {
                    clearInterval(progressInterval);
                    progressInterval = null;
                }
                
                // Show completion message in UI
                const alertClass = dataJson.status === 'error' ? 'alert-danger' : 'alert-success';
                const titleText = dataJson.status === 'error' ? 'Aktualizacja zakończona z błędem!' : 'Aktualizacja zakończona pomyślnie!';
                
                $("#updateResult").html(
                    `<div class="alert ${alertClass}">` +
                    `<h4>${titleText}</h4>` +
                    `<p>Przetworzono ${dataJson.processed_records} z ${dataJson.total_records} rekordów.</p>` +
                    '</div>'
                );
                
                $("#spinnerUpdate").hide();
                $("#btnUpdate").prop("disabled", false);
                $("#btnAnalyze").prop("disabled", false);
            }
            
            return dataJson;
        }
    } catch (err) {
        console.error("Error checking progress:", err);
    }
    return null;
}


// Update progress display
function updateProgressDisplay(data) {
    const percentage = data.percentage || 0;
    const isRunning = data.status === 'running';
    const isInitializing = isRunning && (data.total_records === 0 || data.current_operation_type === 'Pobieranie danych z FlowPin...');

    $("#progressBar").css("width", percentage + "%");
    $("#percentCompleted").text(percentage.toFixed(2) + "%");
    $("#processedRecords").text(data.processed_records || 0);
    
    if (isInitializing) {
        $("#totalRecords").text('...');
        $("#currentOperation").text(data.current_operation_type || 'Pobieranie danych...');
    } else {
        $("#totalRecords").text(data.total_records || 0);
        $("#currentOperation").text(data.current_operation_type || '-');
    }
    
    $("#currentEventId").text(data.current_event_id || '-');
    $("#updateStatus").text(translateStatus(data.status));

    // Update page title to show progress
    if (isRunning) {
        document.title = '[' + percentage.toFixed(0) + '%] Aktualizacja FlowPin';
    } else {
        document.title = 'Zarządzanie aktualizacją FlowPin';
    }
}


// Translate status to Polish
function translateStatus(status) {
    const translations = {
        'pending': 'Oczekuje',
        'running': 'W trakcie',
        'completed': 'Zakończona',
        'error': 'Błąd'
    };
    return translations[status] || status;
}
