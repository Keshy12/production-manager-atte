/**
 * FlowPin Sessions View - Frontend Logic
 * Handles loading, displaying, and managing FlowPin update sessions
 */

// Global state for modal data (for toggling between grouped/ungrouped views)
let currentModalSession = null;
let currentModalGroups = null;

// Load sessions on page load
$(document).ready(function() {
    loadSessions();
});

/**
 * Load sessions based on current filter values
 */
function loadSessions() {
    const dateFrom = $('#dateFrom').val();
    const dateTo = $('#dateTo').val();
    const status = $('#statusFilter').val();

    $('#loadingSpinner').removeClass('d-none');
    $('#sessionsTableContainer').html('');

    postData(COMPONENTS_PATH + '/FlowpinSessions/get-sessions.php', {
        date_from: dateFrom,
        date_to: dateTo,
        status: status
    })
    .then(response => response.json())
    .then(data => {
        $('#loadingSpinner').addClass('d-none');
        if (data.success === false) {
            $('#sessionsTableContainer').html(
                '<div class="alert alert-danger">Błąd ładowania sesji: ' + data.message + '</div>'
            );
        } else {
            renderSessionsTable(data);
        }
    })
    .catch(err => {
        $('#loadingSpinner').addClass('d-none');
        $('#sessionsTableContainer').html(
            '<div class="alert alert-danger">Błąd ładowania sesji: ' + err.message + '</div>'
        );
        console.error(err);
    });
}

/**
 * Render the sessions table
 */
function renderSessionsTable(sessions) {
    if (!sessions || sessions.length === 0) {
        $('#sessionsTableContainer').html(
            '<div class="alert alert-info">Nie znaleziono sesji spełniających kryteria.</div>'
        );
        return;
    }

    let html = `
        <div class="table-responsive">
            <table class="table table-striped table-hover">
                <thead class="thead-dark">
                    <tr>
                        <th style="width: 15%">ID Sesji</th>
                        <th style="width: 12%">Data/Czas</th>
                        <th style="width: 8%">Status</th>
                        <th style="width: 15%">Zakres EventId</th>
                        <th style="width: 8%">Transfery</th>
                        <th style="width: 8%">Grupy</th>
                        <th style="width: 10%">Czas trwania</th>
                        <th style="width: 24%">Akcje</th>
                    </tr>
                </thead>
                <tbody>
    `;

    sessions.forEach(session => {
        const statusBadge = getStatusBadge(session.status);
        const duration = calculateDuration(session.started_at, session.updated_at);
        const eventRange = formatEventRange(session.starting_event_id, session.finishing_event_id);

        html += `
            <tr class="session-row">
                <td><small><code>${escapeHtml(session.session_id)}</code></small></td>
                <td>${formatDateTime(session.started_at)}</td>
                <td>${statusBadge}</td>
                <td><small>${eventRange}</small></td>
                <td><strong>${session.created_transfer_count || 0}</strong></td>
                <td><strong>${session.created_group_count || 0}</strong></td>
                <td>${duration}</td>
                <td>
                    ${session.created_transfer_count > 0 ? `
                        <button onclick="viewInArchive(${session.id})" class="btn btn-sm btn-info" title="Zobacz transfery">
                            <i class="fas fa-eye"></i> Zobacz
                        </button>
                    ` : `
                        <span class="text-muted">Brak transferów</span>
                    `}
                </td>
            </tr>
        `;
    });

    html += `
                </tbody>
            </table>
        </div>
    `;

    $('#sessionsTableContainer').html(html);
}

/**
 * Get status badge HTML
 */
function getStatusBadge(status) {
    const badges = {
        'completed': '<span class="badge badge-success">Ukończone</span>',
        'running': '<span class="badge badge-primary status-badge-running">W trakcie</span>',
        'error': '<span class="badge badge-danger">Błąd</span>',
        'pending': '<span class="badge badge-warning">Oczekujące</span>'
    };
    return badges[status] || '<span class="badge badge-secondary">Nieznany</span>';
}

/**
 * Calculate duration between two timestamps
 */
function calculateDuration(startTime, endTime) {
    if (!startTime || !endTime) return 'N/A';

    const start = new Date(startTime);
    const end = new Date(endTime);
    const diffMs = end - start;

    const diffMins = Math.floor(diffMs / 60000);
    const diffSecs = Math.floor((diffMs % 60000) / 1000);

    if (diffMins > 0) {
        return `${diffMins}m ${diffSecs}s`;
    } else {
        return `${diffSecs}s`;
    }
}

/**
 * Format datetime for display
 */
function formatDateTime(datetime) {
    if (!datetime) return 'N/A';
    const d = new Date(datetime);
    return d.toLocaleString('pl-PL', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit'
    });
}

/**
 * Format EventId range
 */
function formatEventRange(startId, endId) {
    if (!startId && !endId) return 'N/A';
    if (!startId) return `? - ${endId}`;
    if (!endId) return `${startId} - ?`;
    return `${startId} - ${endId}`;
}

/**
 * Escape HTML to prevent XSS
 */
function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Open modal to view session transfers
 */
function viewInArchive(sessionId) {
    // Store current session ID for Cancel All button
    window.currentModalSessionId = sessionId;

    // Show modal
    $('#sessionTransfersModal').modal('show');

    // Show loading, hide content
    $('#modalLoadingSpinner').show();
    $('#modalTransfersContent').html('');
    $('#sessionMetadata').hide();
    $('#modalCancelAllBtn').hide();

    // Load session transfers
    postData(COMPONENTS_PATH + '/FlowpinSessions/get-session-transfers.php', {
        session_id: sessionId
    })
    .then(response => response.json())
    .then(data => {
        $('#modalLoadingSpinner').hide();

        if (data.success === false) {
            $('#modalTransfersContent').html(
                '<div class="alert alert-danger">Błąd ładowania transferów: ' + data.message + '</div>'
            );
        } else {
            renderSessionModal(data.session, data.groups);
        }
    })
    .catch(err => {
        $('#modalLoadingSpinner').hide();
        $('#modalTransfersContent').html(
            '<div class="alert alert-danger">Błąd ładowania transferów: ' + err.message + '</div>'
        );
        console.error(err);
    });
}

/**
 * Render session modal with metadata and transfers
 */
function renderSessionModal(session, groups) {
    // Store data globally for toggling
    currentModalSession = session;
    currentModalGroups = groups;

    // Populate session metadata
    $('#modalSessionId').html('<code>' + escapeHtml(session.session_id) + '</code>');
    $('#modalSessionDate').text(formatDateTime(session.started_at));
    $('#modalEventRange').text(formatEventRange(session.starting_event_id, session.finishing_event_id));
    $('#modalStatus').html(getStatusBadge(session.status));
    $('#modalTransferCount').html('<strong>' + (session.created_transfer_count || 0) + '</strong>');
    $('#modalGroupCount').html('<strong>' + (session.created_group_count || 0) + '</strong>');
    $('#modalDuration').text(calculateDuration(session.started_at, session.updated_at));

    $('#sessionMetadata').show();

    // Setup checkbox event handler for toggling grouped/ungrouped view
    $('#modalNoGrouping').off('change').on('change', function() {
        renderTransfersContent();
    });

    // Show Cancel All button if there are active transfers
    if (session.created_transfer_count > 0) {
        $('#modalCancelAllBtn').show().off('click').on('click', function() {
            confirmCancelSession(session.id, session.session_id, session.created_transfer_count);
        });
    }

    // Render transfers content
    renderTransfersContent();
}

/**
 * Render transfers content based on grouping checkbox state
 */
function renderTransfersContent() {
    const isUngrouped = $('#modalNoGrouping').is(':checked');

    if (!currentModalGroups || currentModalGroups.length === 0) {
        $('#modalTransfersContent').html(
            '<div class="alert alert-info">Brak transferów w tej sesji.</div>'
        );
        return;
    }

    if (isUngrouped) {
        renderUngroupedTransfers(currentModalGroups);
    } else {
        renderGroupedTransfers(currentModalGroups);
    }
}

/**
 * Render transfers in grouped view (collapsible cards)
 */
function renderGroupedTransfers(groups) {
    let html = '<div class="table-responsive mt-3">';

    groups.forEach((group, groupIndex) => {
        const isGroupCancelled = group.is_all_cancelled;
        const groupClass = isGroupCancelled ? 'table-secondary' : '';
        const groupHeader = group.group_id
            ? `Grupa #${group.group_id} - ${group.group_created_by_name} (${formatDateTime(group.group_created_at)})`
            : `Transfer pojedynczy`;

        html += `
            <div class="card mb-3 ${isGroupCancelled ? 'bg-light' : ''}">
                <div class="card-header ${groupClass}" style="cursor: pointer;" onclick="toggleGroup(${groupIndex})">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <i class="fas fa-chevron-right" id="groupIcon${groupIndex}"></i>
                            <strong>${groupHeader}</strong>
                            <span class="badge badge-${isGroupCancelled ? 'secondary' : 'info'} ml-2">
                                ${group.total_transfers} transfer${group.total_transfers !== 1 ? 'ów' : ''}
                            </span>
                            ${group.cancelled_count > 0 ? `
                                <span class="badge badge-warning ml-1">
                                    ${group.cancelled_count} anulowanych
                                </span>
                            ` : ''}
                        </div>
                    </div>
                </div>
                <div id="groupContent${groupIndex}" class="card-body" style="display: none;">
                    <table class="table table-sm table-bordered mb-0">
                        <thead>
                            <tr>
                                <th>Typ</th>
                                <th>Urządzenie</th>
                                <th>Magazyn</th>
                                <th>Ilość</th>
                                <th>Typ wpisu</th>
                                <th>Data</th>
                                <th>EventId</th>
                                <th>Komentarz</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
        `;

        group.transfers.forEach(transfer => {
            const rowClass = transfer.is_cancelled ? 'table-secondary' : '';
            const statusBadge = transfer.is_cancelled
                ? `<span class="badge badge-secondary" title="Anulowany przez: ${transfer.cancelled_by_name || 'N/A'} w ${transfer.cancelled_at}">Anulowany</span>`
                : `<span class="badge badge-success">Aktywny</span>`;

            html += `
                <tr class="${rowClass}">
                    <td><strong>${escapeHtml(transfer.device_type_name)}</strong></td>
                    <td>${escapeHtml(transfer.device_name || 'N/A')}</td>
                    <td>${escapeHtml(transfer.magazine_symbol || '')} - ${escapeHtml(transfer.magazine_name || 'N/A')}</td>
                    <td><strong>${transfer.qty}</strong></td>
                    <td>${escapeHtml(transfer.input_type_name || 'N/A')}</td>
                    <td><small>${formatDateTime(transfer.timestamp)}</small></td>
                    <td><small><code>${transfer.flowpin_event_id || 'N/A'}</code></small></td>
                    <td><small>${escapeHtml(transfer.comment || '-')}</small></td>
                    <td>${statusBadge}</td>
                </tr>
            `;
        });

        html += `
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    });

    html += '</div>';

    $('#modalTransfersContent').html(html);
}

/**
 * Render transfers in ungrouped view (flat table sorted by EventId)
 */
function renderUngroupedTransfers(groups) {
    // Flatten all transfers into single array
    const allTransfers = [];
    groups.forEach(group => {
        group.transfers.forEach(transfer => {
            allTransfers.push(transfer);
        });
    });

    // Sort by flowpin_event_id ascending
    allTransfers.sort((a, b) => {
        const aId = a.flowpin_event_id || 0;
        const bId = b.flowpin_event_id || 0;
        return aId - bId;
    });

    // Render simple table
    let html = `
        <div class="table-responsive mt-3">
            <table class="table table-sm table-striped table-bordered">
                <thead class="thead-light">
                    <tr>
                        <th>Typ</th>
                        <th>Urządzenie</th>
                        <th>Magazyn</th>
                        <th>Ilość</th>
                        <th>Typ wpisu</th>
                        <th>Data</th>
                        <th>EventId</th>
                        <th>Komentarz</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
    `;

    allTransfers.forEach(transfer => {
        const rowClass = transfer.is_cancelled ? 'table-secondary' : '';
        const statusBadge = transfer.is_cancelled
            ? `<span class="badge badge-secondary" title="Anulowany przez: ${transfer.cancelled_by_name || 'N/A'} w ${transfer.cancelled_at}">Anulowany</span>`
            : `<span class="badge badge-success">Aktywny</span>`;

        html += `
            <tr class="${rowClass}">
                <td><strong>${escapeHtml(transfer.device_type_name)}</strong></td>
                <td>${escapeHtml(transfer.device_name || 'N/A')}</td>
                <td>${escapeHtml(transfer.magazine_symbol || '')} - ${escapeHtml(transfer.magazine_name || 'N/A')}</td>
                <td><strong>${transfer.qty}</strong></td>
                <td>${escapeHtml(transfer.input_type_name || 'N/A')}</td>
                <td><small>${formatDateTime(transfer.timestamp)}</small></td>
                <td><small><code>${transfer.flowpin_event_id || 'N/A'}</code></small></td>
                <td><small>${escapeHtml(transfer.comment || '-')}</small></td>
                <td>${statusBadge}</td>
            </tr>
        `;
    });

    html += `
                </tbody>
            </table>
        </div>
    `;

    $('#modalTransfersContent').html(html);
}

/**
 * Toggle group expansion in modal
 */
function toggleGroup(groupIndex) {
    const content = $('#groupContent' + groupIndex);
    const icon = $('#groupIcon' + groupIndex);

    if (content.is(':visible')) {
        content.slideUp(200);
        icon.removeClass('fa-chevron-down').addClass('fa-chevron-right');
    } else {
        content.slideDown(200);
        icon.removeClass('fa-chevron-right').addClass('fa-chevron-down');
    }
}

/**
 * Confirm and cancel all transfers from a session
 */
function confirmCancelSession(sessionId, sessionIdStr, transferCount) {
    if (!confirm(
        `Czy na pewno chcesz anulować WSZYSTKIE ${transferCount} transfery z sesji:\n\n${sessionIdStr}\n\n` +
        `Ta operacja jest nieodwracalna!`
    )) {
        return;
    }

    // Show loading indicator
    $('button').prop('disabled', true);

    postData(COMPONENTS_PATH + '/FlowpinSessions/cancel-session.php', {
        session_id: sessionId
    })
    .then(response => response.json())
    .then(data => {
        $('button').prop('disabled', false);

        if (data.success) {
            alert(`Sukces! Anulowano ${data.cancelled_count} transferów.`);

            // Close the modal if it's open
            $('#sessionTransfersModal').modal('hide');

            // Reload the sessions list
            loadSessions();
        } else {
            alert('Błąd: ' + data.message);
        }
    })
    .catch(err => {
        $('button').prop('disabled', false);
        alert('Błąd podczas anulowania sesji: ' + err.message);
        console.error(err);
    });
}
