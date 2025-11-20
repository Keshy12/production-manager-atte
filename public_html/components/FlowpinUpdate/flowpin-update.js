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

// Check if there's a running session on page load
if (currentSessionId) {
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
    fetch("/atte_ms_new/src/cron/flowpin-sku-update.php", {
        method: "POST",
        credentials: "omit"
    })
    .then(response => response.text())
    .then(data => {
        $("#spinnerUpdate").hide();

        // Stop polling if still active
        if (progressInterval) {
            clearInterval(progressInterval);
            progressInterval = null;
        }

        // Final progress check
        if (currentSessionId) {
            checkProgress(currentSessionId, true);
        }

        // Extract session_id from first line if present (JSON format)
        let logData = data;
        const lines = data.split('\n');
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

        $("#updateResult").html(
            '<div class="alert alert-success">' +
            '<h4>Aktualizacja zakończona!</h4>' +
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
function checkProgress(sessionId, isFinal = false) {
    const data = sessionId ? { session_id: sessionId } : {};

    postData(COMPONENTS_PATH + "/FlowpinUpdate/get-progress.php", data)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Store session ID if we got it
                if (!currentSessionId && data.session_id) {
                    currentSessionId = data.session_id;
                }

                updateProgressDisplay(data);

                // Check for stale session (not updated in 10+ minutes while status=running)
                if (data.status === 'running' && data.updated_at) {
                    const updatedAt = new Date(data.updated_at);
                    const now = new Date();
                    const minutesSinceUpdate = (now - updatedAt) / 1000 / 60;

                    if (minutesSinceUpdate > 10) {
                        // Session appears stale - stop polling
                        if (progressInterval) {
                            clearInterval(progressInterval);
                            progressInterval = null;
                        }

                        $("#spinnerUpdate").hide();
                        $("#updateResult").html(
                            '<div class="alert alert-danger">' +
                            '<h4>Sesja wygląda na zawieszoną!</h4>' +
                            '<p>Aktualizacja nie była kontynuowana przez ponad 10 minut.</p>' +
                            '<p><strong>Przeładuj stronę</strong> aby zobaczyć opcję resetowania sesji.</p>' +
                            '</div>'
                        );

                        $("#btnUpdate").prop("disabled", false);
                        $("#btnAnalyze").prop("disabled", false);
                        return; // Don't process further
                    }
                }

                // Stop polling if completed or error (unless it's just a final check)
                if (!isFinal && (data.status === 'completed' || data.status === 'error')) {
                    if (progressInterval) {
                        clearInterval(progressInterval);
                        progressInterval = null;
                    }

                    if (data.status === 'completed') {
                        $("#updateResult").html(
                            '<div class="alert alert-success">' +
                            '<h4>Aktualizacja zakończona pomyślnie!</h4>' +
                            '<p>Przetworzono ' + data.processed_records + ' z ' + data.total_records + ' rekordów.</p>' +
                            '</div>'
                        );
                        $("#spinnerUpdate").hide();
                        $("#btnUpdate").prop("disabled", false);
                        $("#btnAnalyze").prop("disabled", false);
                    } else if (data.status === 'error') {
                        $("#updateResult").html(
                            '<div class="alert alert-danger">' +
                            '<h4>Aktualizacja zakończona z błędem!</h4>' +
                            '<p>Sprawdź logi po więcej informacji.</p>' +
                            '</div>'
                        );
                        $("#spinnerUpdate").hide();
                        $("#btnUpdate").prop("disabled", false);
                        $("#btnAnalyze").prop("disabled", false);
                    }
                }
            }
        })
        .catch(err => {
            console.error("Error checking progress:", err);
        });
}

// Update progress display
function updateProgressDisplay(data) {
    const percentage = data.percentage || 0;

    $("#progressBar").css("width", percentage + "%");
    $("#percentCompleted").text(percentage.toFixed(2) + "%");
    $("#processedRecords").text(data.processed_records || 0);
    $("#totalRecords").text(data.total_records || 0);
    $("#currentOperation").text(data.current_operation_type || '-');
    $("#currentEventId").text(data.current_event_id || '-');
    $("#updateStatus").text(translateStatus(data.status));

    // Update page title to show progress
    if (data.status === 'running') {
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
