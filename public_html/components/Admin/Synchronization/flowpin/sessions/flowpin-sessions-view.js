/**
 * FlowPin Sessions View - Frontend Logic
 * Handles loading, displaying, and managing FlowPin update sessions
 */

// Global state for modal data (for pagination)
let currentModalSession = null;
let currentModalSessionId = null;
let currentModalPage = 1;
let currentModalPagination = null;

// Filter state - persists across pagination
let currentFilters = {
    operationType: '',
    dateFrom: '',
    dateTo: '',
    user: '',
    search: '',
    devices: []
};

// Filter options cache - keyed by session ID
let filterOptionsCache = {
    users: [],
    devices: []
};
let loadedFilterOptionsSessionId = null;

// Input type ID to operation name mapping
const inputTypeMap = {
    2: 'Przesunięcie',
    4: 'Produkcja',
    6: 'Zejście z magazynu',
    9: 'Sprzedaż',
    10: 'Zwrot'
};

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

    postData(COMPONENTS_PATH + '/Admin/Synchronization/flowpin/sessions/get-sessions.php', {
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
                        <button onclick="viewSessionTransfers(${session.id})" class="btn btn-sm btn-info" title="Zobacz transfery">
                            <i class="fas fa-eye"></i> Zobacz
                        </button>
                        <a href="${getArchiveUrl(session.id)}" class="btn btn-sm btn-secondary ml-1" title="Zobacz w archiwum">
                            <i class="fas fa-archive"></i> Archiwum
                        </a>
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
 * Get operation type name from input_type_id
 */
function getOperationTypeName(inputTypeId) {
    return inputTypeMap[inputTypeId] || 'Nieznany';
}

/**
 * Open modal to view session transfers with pagination
 */
function viewSessionTransfers(sessionId, page = 1) {
    // Store current session ID for pagination
    currentModalSessionId = sessionId;
    currentModalPage = page;

    // Show modal
    $('#sessionTransfersModal').modal('show');

    // Show loading, hide content
    $('#modalLoadingSpinner').show();
    $('#modalTransfersContent').html('');
    $('#sessionMetadata').hide();
    $('#modalCancelAllBtn').hide();
    $('#modalPagination').hide();
    
    // Show filters section immediately (it has its own loading state)
    $('#modalFilters').removeClass('d-none');
    updateActiveFiltersCount();

    // Load session transfers with filters
    const requestData = {
        session_id: sessionId,
        page: page,
        limit: 5,
        filter_operation_type: currentFilters.operationType,
        filter_date_from: currentFilters.dateFrom,
        filter_date_to: currentFilters.dateTo,
        filter_user: Array.isArray(currentFilters.user) ? currentFilters.user.join(',') : currentFilters.user,
        filter_search: currentFilters.search,
        filter_devices: Array.isArray(currentFilters.devices) ? currentFilters.devices.join(',') : currentFilters.devices
    };

    postData(COMPONENTS_PATH + '/Admin/Synchronization/flowpin/sessions/get-session-transfers.php', requestData)
    .then(response => response.json())
    .then(data => {
        $('#modalLoadingSpinner').hide();

        if (data.success === false) {
            $('#modalTransfersContent').html(
                '<div class="alert alert-danger">Błąd ładowania transferów: ' + data.message + '</div>'
            );
        } else {
            renderSessionModal(data.session, data.events, data.pagination);
            
            // Load filter options separately (don't block main content)
            loadFilterOptions(sessionId);
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
 * Load filter options separately to avoid blocking main content
 */
function loadFilterOptions(sessionId) {
    // Don't reload if we already have options for this session
    if (loadedFilterOptionsSessionId === sessionId && filterOptionsCache.users.length > 0) {
        populateFilterOptions();
        return;
    }
    
    // Show loading indicators
    $('#filterUserLoading').removeClass('d-none');
    $('#filterDevicesLoading').removeClass('d-none');
    
    postData(COMPONENTS_PATH + '/Admin/Synchronization/flowpin/sessions/get-filter-options.php', {
        session_id: sessionId
    })
    .then(response => response.json())
    .then(data => {
        // Hide loading indicators
        $('#filterUserLoading').addClass('d-none');
        $('#filterDevicesLoading').addClass('d-none');
        
        if (data.success && data.filter_options) {
            filterOptionsCache = data.filter_options;
            loadedFilterOptionsSessionId = sessionId;
            populateFilterOptions();
        }
    })
    .catch(err => {
        // Hide loading indicators
        $('#filterUserLoading').addClass('d-none');
        $('#filterDevicesLoading').addClass('d-none');
        
        console.error('Failed to load filter options:', err);
        // Don't show error - filters are optional
    });
}

/**
 * Populate filter dropdowns with options from the session
 */
function populateFilterOptions() {
    // Populate user dropdown with counts
    const userSelect = $('#filterUser');
    const currentUserVals = userSelect.val() || [];
    userSelect.empty();
    
    if (filterOptionsCache.users && filterOptionsCache.users.length > 0) {
        filterOptionsCache.users.forEach(user => {
            const userEmail = user.email || user;
            const userCount = user.count || 0;
            const selected = currentUserVals.includes(userEmail) ? 'selected' : '';
            // Add data-content with badge for count - don't escape HTML in data-content
            const content = `${escapeHtml(userEmail)} <span class="badge badge-primary ml-1">${userCount}</span>`;
            userSelect.append(`<option value="${escapeHtml(userEmail)}" ${selected} data-content="${content.replace(/"/g, '&quot;')}">${escapeHtml(userEmail)} (${userCount})</option>`);
        });
    }

    // Populate devices dropdown with counts
    const deviceSelect = $('#filterDevices');
    const currentDeviceVals = deviceSelect.val() || [];
    deviceSelect.empty();
    
    if (filterOptionsCache.devices && filterOptionsCache.devices.length > 0) {
        filterOptionsCache.devices.forEach(device => {
            const selected = currentDeviceVals.includes(device.name) ? 'selected' : '';
            const content = `${escapeHtml(device.name)} <span class="badge badge-primary ml-1">${device.count}</span>`;
            deviceSelect.append(`<option value="${escapeHtml(device.name)}" ${selected} data-content="${content.replace(/"/g, '&quot;')}">${escapeHtml(device.name)} (${device.count})</option>`);
        });
    }
    
    // Refresh bootstrap-select to apply changes
    userSelect.selectpicker('refresh');
    deviceSelect.selectpicker('refresh');
}

/**
 * Update the active filters count badge
 */
function updateActiveFiltersCount() {
    let count = 0;
    if (currentFilters.operationType) count++;
    if (currentFilters.dateFrom) count++;
    if (currentFilters.dateTo) count++;
    if (currentFilters.user && currentFilters.user.length > 0) count++;
    if (currentFilters.search) count++;
    if (currentFilters.devices && currentFilters.devices.length > 0) count++;

    const badge = $('#activeFiltersCount');
    if (count > 0) {
        badge.text(`Aktywne: ${count}`);
        badge.removeClass('d-none');
    } else {
        badge.text('');
        badge.addClass('d-none');
    }
}

/**
 * Apply filters and reload data
 */
function applyFilters() {
    // Read filter values
    currentFilters.operationType = $('#filterOperationType').val() || '';
    currentFilters.dateFrom = $('#filterDateFrom').val() || '';
    currentFilters.dateTo = $('#filterDateTo').val() || '';
    currentFilters.user = $('#filterUser').val() || [];
    currentFilters.search = $('#filterSearch').val() || '';
    currentFilters.devices = $('#filterDevices').val() || [];

    // Reset to page 1 and reload
    currentModalPage = 1;
    viewSessionTransfers(currentModalSessionId, 1);
}

/**
 * Clear all filters
 */
function clearFilters() {
    // Reset filter values
    currentFilters = {
        operationType: '',
        dateFrom: '',
        dateTo: '',
        user: [],
        search: '',
        devices: []
    };

    // Reset form inputs
    $('#filterOperationType').val('');
    $('#filterDateFrom').val('');
    $('#filterDateTo').val('');
    $('#filterUser').val([]).selectpicker('refresh');
    $('#filterSearch').val('');
    $('#filterDevices').val([]).selectpicker('refresh');

    // Reset to page 1 and reload
    currentModalPage = 1;
    viewSessionTransfers(currentModalSessionId, 1);
}

/**
 * Render session modal with metadata and paginated events
 */
function renderSessionModal(session, events, pagination) {
    // Store data globally for pagination
    currentModalSession = session;
    currentModalPagination = pagination;

    // Populate session metadata
    $('#modalSessionId').html('<code>' + escapeHtml(session.session_id) + '</code>');
    $('#modalSessionDate').text(formatDateTime(session.started_at));
    $('#modalEventRange').text(formatEventRange(session.starting_event_id, session.finishing_event_id));
    $('#modalStatus').html(getStatusBadge(session.status));
    $('#modalTransferCount').html('<strong>' + (session.created_transfer_count || 0) + '</strong>');
    $('#modalGroupCount').html('<strong>' + (session.created_group_count || 0) + '</strong>');
    $('#modalDuration').text(calculateDuration(session.started_at, session.updated_at));

    $('#sessionMetadata').show();

    // Show Cancel All button if there are active transfers
    if (session.created_transfer_count > 0) {
        $('#modalCancelAllBtn').show().off('click').on('click', function() {
            confirmCancelSession(session.id, session.session_id, session.created_transfer_count);
        });
    }

    // Render events table
    renderEventsTable(events);

    // Render pagination controls
    renderPaginationControls(pagination);

    // Update active filters count
    updateActiveFiltersCount();
}

/**
 * Render events table with collapsible sections
 */
function renderEventsTable(events) {
    if (!events || events.length === 0) {
        $('#modalTransfersContent').html(
            '<div class="alert alert-info">Brak transferów w tej sesji.</div>'
        );
        return;
    }

    let html = '<div class="events-container">';

    events.forEach(event => {
        const flowpin = event.flowpin_raw;
        const localRows = event.local_rows;
        const eventId = event.flowpin_event_id;
        
        // Get primary local row for header display (first row)
        const primaryRow = localRows[0] || {};
        
        // Determine operation type from input_type_id
        const operationType = getOperationTypeName(primaryRow.input_type_id);
        
        // Get quantity from FlowPin or local row
        const flowpinQty = flowpin?.ProductionQty || primaryRow.qty || '?';
        
        // Use FlowPin device name instead of local row device name
        const flowpinDeviceName = flowpin?.device_name || 'Unknown';
        
        html += `
        <div class="card mb-3 border-primary">
            <!-- Header Row (Always Visible) -->
            <div class="card-header bg-light d-flex justify-content-between align-items-center py-2" 
                 style="cursor: pointer;" 
                 data-toggle="collapse" 
                 data-target="#event-${eventId}"
                 aria-expanded="false">
                
                <!-- Left Side: Local System Data (Priority Order) -->
                <div class="d-flex align-items-center flex-wrap">
                    <span class="badge badge-primary mr-2" style="font-size: 0.9rem;">
                        ${escapeHtml(flowpinDeviceName)}
                    </span>
                    <small class="text-muted mr-3">
                        <i class="far fa-calendar-alt"></i> 
                        ${formatDateTime(primaryRow.timestamp)}
                    </small>
                    <small class="mr-3" title="User Email">
                        <i class="far fa-user"></i> 
                        ${escapeHtml(flowpin?.ByUserEmail || 'N/A')}
                    </small>
                    <span class="badge badge-info mr-2">
                        ${operationType}
                    </span>
                    <strong class="text-dark">Ilość: ${flowpinQty}</strong>
                </div>
                
                <!-- Right Side: Mini FlowPin Raw Data -->
                <div class="text-right d-flex align-items-center">
                    <small class="text-muted mr-3" style="font-size: 0.75rem;">
                        <code>FP#${eventId}</code> | 
                        PID:${flowpin?.ProductTypeId || '?'} | 
                        ${flowpin?.EventTypeValue || 'N/A'} | 
                        Qty:${flowpinQty}
                    </small>
                    <i class="fas fa-chevron-down text-muted"></i>
                </div>
            </div>
            
            <!-- Collapsible Section: FlowPin Raw Data + Local System Results -->
            <div id="event-${eventId}" class="collapse">
                <div class="card-body p-0">
                    <!-- FlowPin Raw Data (Full) -->
                    ${flowpin ? `
                    <div class="p-2 bg-light border-bottom">
                        <small class="text-muted font-weight-bold">FlowPin Raw Data:</small>
                        <div class="row small text-muted mt-1">
                            <div class="col-md-3"><strong>EventId:</strong> ${flowpin.EventId}</div>
                            <div class="col-md-3"><strong>ExecutionDate:</strong> ${flowpin.ExecutionDate}</div>
                            <div class="col-md-3"><strong>ByUserEmail:</strong> ${escapeHtml(flowpin.ByUserEmail || 'N/A')}</div>
                            <div class="col-md-3"><strong>ProductTypeId:</strong> ${flowpin.ProductTypeId}</div>
                            <div class="col-md-3"><strong>EventTypeValue:</strong> ${flowpin.EventTypeValue}</div>
                            <div class="col-md-3"><strong>ProductionQty:</strong> ${flowpin.ProductionQty || 'N/A'}</div>
                            <div class="col-md-3"><strong>FieldOldValue:</strong> ${flowpin.FieldOldValue || 'N/A'}</div>
                            <div class="col-md-3"><strong>FieldNewValue:</strong> ${flowpin.FieldNewValue || 'N/A'}</div>
                            <div class="col-md-3"><strong>State:</strong> ${flowpin.State || 'N/A'}</div>
                            <div class="col-md-3"><strong>WarehouseId:</strong> ${flowpin.WarehouseId || 'N/A'}</div>
                            <div class="col-md-3"><strong>ParentId:</strong> ${flowpin.ParentId || 'N/A'}</div>
                            <div class="col-md-3"><strong>IsInter:</strong> ${flowpin.IsInter || 'N/A'}</div>
                        </div>
                    </div>
                    ` : `
                    <div class="p-2 bg-warning border-bottom">
                        <small class="text-dark">
                            <i class="fas fa-exclamation-triangle"></i> 
                            FlowPin data not available (EventId: ${eventId})
                        </small>
                    </div>
                    `}
                    
                    <!-- Local System Rows Table -->
                    <div class="p-2">
                        <small class="text-muted font-weight-bold">Local System Results (${localRows.length} rows):</small>
                    </div>
                    <table class="table table-sm table-striped table-hover mb-0">
                        <thead class="thead-light">
                            <tr>
                                <th>Typ</th>
                                <th>Urządzenie</th>
                                <th>Magazyn</th>
                                <th>Ilość</th>
                                <th>Typ wpisu</th>
                                <th>Data</th>
                                <th>Komentarz</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${localRows.map(row => `
                            <tr class="${row.is_cancelled ? 'table-secondary' : ''}">
                                <td>${getDeviceTypeBadge(row.device_type)}</td>
                                <td>${escapeHtml(row.device_name || 'N/A')}</td>
                                <td><small>${escapeHtml(row.magazine_name || 'N/A')}</small></td>
                                <td><strong>${row.qty}</strong></td>
                                <td><small>${escapeHtml(row.input_type_name || 'N/A')}</small></td>
                                <td><small>${formatDateTime(row.timestamp)}</small></td>
                                <td><small>${escapeHtml(row.comment || '-')}</small></td>
                                <td>${row.is_cancelled 
                                    ? `<span class="badge badge-secondary">Anulowany</span>` 
                                    : `<span class="badge badge-success">Aktywny</span>`}</td>
                            </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        `;
    });

    html += '</div>';

    $('#modalTransfersContent').html(html);
}

/**
 * Render pagination controls
 */
function renderPaginationControls(pagination) {
    const currentPage = pagination.page;
    const totalPages = pagination.total_pages;
    const totalEvents = pagination.total_events;
    const filteredCount = pagination.filtered_count !== undefined ? pagination.filtered_count : totalEvents;
    const filteredOut = pagination.filtered_out || 0;

    let html = `
        <div class="d-flex justify-content-between align-items-center">
            <div>
                <small class="text-muted">
                    Strona ${currentPage} z ${totalPages} | 
                    EventIds ${((currentPage - 1) * pagination.limit) + 1} - ${Math.min(currentPage * pagination.limit, totalEvents)} z ${totalEvents}
    `;
    
    // Show filtered count if filters are active
    if (filteredOut > 0) {
        html += ` (wyświetlono ${filteredCount}, pominięto ${filteredOut})`;
    }
    
    html += `
                </small>
            </div>
    `;
    
    if (totalPages > 1) {
        html += `
            <div class="btn-group" role="group">
                <button type="button" class="btn btn-sm btn-outline-primary" 
                    onclick="changePage(${currentPage - 1})" 
                    ${!pagination.has_prev ? 'disabled' : ''}>
                    <i class="fas fa-chevron-left"></i> Poprzednia
                </button>
                <button type="button" class="btn btn-sm btn-outline-primary" 
                    onclick="changePage(${currentPage + 1})" 
                    ${!pagination.has_next ? 'disabled' : ''}>
                    Następna <i class="fas fa-chevron-right"></i>
                </button>
            </div>
        `;
    }
    
    html += `</div>`;

    $('#modalPagination').html(html).show();
}

/**
 * Change page in modal
 */
function changePage(newPage) {
    if (!currentModalSessionId || !currentModalPagination) return;
    
    if (newPage < 1 || newPage > currentModalPagination.total_pages) return;
    
    viewSessionTransfers(currentModalSessionId, newPage);
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

    postData(COMPONENTS_PATH + '/Admin/Synchronization/flowpin/sessions/cancel-session.php', {
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

/**
 * Get device type badge with color coding matching Archive view
 */
function getDeviceTypeBadge(deviceType) {
    if (!deviceType) return '';
    const classes = {
        'sku': 'badge-primary',
        'tht': 'badge-success',
        'smd': 'badge-info',
        'parts': 'badge-warning'
    };
    const typeNames = {
        'sku': 'SKU',
        'tht': 'THT',
        'smd': 'SMD',
        'parts': 'PARTS'
    };
    const badgeClass = classes[deviceType.toLowerCase()] || 'badge-secondary';
    const displayName = typeNames[deviceType.toLowerCase()] || deviceType.toUpperCase();
    return `<span class="badge badge-sm ${badgeClass}">${displayName}</span>`;
}

/**
 * Get Archive URL for a session
 */
function getArchiveUrl(sessionId) {
    const baseUrl = (typeof ROOT_DIR !== 'undefined') ? ROOT_DIR : '';
    return baseUrl + '/archive?flowpin_session=' + sessionId;
}
