class FromOrdersRenderer {
    constructor() {
        this.ordersTemplate = null;
        this.currentPage = 1;
        this.totalItems = 0;
        this.itemsPerPage = 10;
        this.hasNextPage = false;
        this.isLoading = false;
        this.stats = {
            total: 0,
            withMissingParts: 0,
            readyToImport: 0
        };
        this.filterOptions = {
            allPOIDs: [],
            allGRNIDs: [],
            allPartNames: []
        };
        this.paginatedOrders = []; // Orders for current page display
        this.allOrders = []; // FULL filtered dataset for import
        this.missingParts = [];
        this.newLastFoundCell = null;
        this.oldLastFoundCell = null;

        this.init();
    }

    init() {
        // Load template
        this.ordersTemplate = $('script[data-template="fromOrders_template"]').text().split(/\$\{(.+?)\}/g);

        // Initialize selectpickers
        $('.selectpicker').selectpicker();
    }

    async fetchInitialData() {
        if (this.isLoading) return;

        this.isLoading = true;
        $("#missingPartsAlert, #errorAlert, #successAlert").hide();
        this.showSpinner();

        try {
            const filters = this.getFilters();
            const response = await $.ajax({
                type: "POST",
                url: COMPONENTS_PATH + "/admin/components/fromorders/get-orders.php",
                data: filters
            });

            const result = JSON.parse(response);
            const paginatedOrders = result[0];
            const missingParts = result[1];
            const lastFoundCell = result[2];
            const nextPageAvailable = result[3];
            const totalCount = result[4];
            const stats = result[5];
            const allPOIDs = result[6];
            const allGRNIDs = result[7];
            const allPartNames = result[8];
            const fullDataset = result[9]; // FULL filtered dataset

            this.oldLastFoundCell = parseInt($("#lastFoundCell").text());
            this.newLastFoundCell = lastFoundCell;
            this.paginatedOrders = paginatedOrders;
            this.allOrders = fullDataset; // Store full dataset for import
            this.missingParts = missingParts;
            this.hasNextPage = nextPageAvailable;
            this.totalItems = totalCount;
            this.stats = stats;
            this.filterOptions = { allPOIDs, allGRNIDs, allPartNames };

            if (totalCount === 0) {
                this.generateError("Nie znaleziono żadnych nowych zamówień.");
                return;
            }

            this.renderOrdersTable();
            this.renderPagination();
            this.updateStats();
            this.updateFilterOptions();

            if (missingParts.length !== 0) {
                this.generateMissingPartsError(missingParts);
                $("#importOrders").prop('disabled', true);
            } else {
                $("#importOrders").prop('disabled', false);
            }

        } catch (error) {
            console.error("Error fetching orders:", error);
            this.generateError("Błąd podczas pobierania zamówień: " + error);
        } finally {
            this.isLoading = false;
            this.hideSpinner();
        }
    }

    async render() {
        if (this.isLoading) return;

        this.isLoading = true;
        this.showLoadingOverlay('Filtrowanie zamówień...');

        try {
            const filters = this.getFilters();
            const response = await $.ajax({
                type: "POST",
                url: COMPONENTS_PATH + "/admin/components/fromorders/get-orders.php",
                data: filters
            });

            const result = JSON.parse(response);
            const paginatedOrders = result[0];
            const missingParts = result[1];
            const lastFoundCell = result[2];
            const nextPageAvailable = result[3];
            const totalCount = result[4];
            const stats = result[5];
            const fullDataset = result[9]; // FULL filtered dataset

            this.newLastFoundCell = lastFoundCell;
            this.paginatedOrders = paginatedOrders;
            this.allOrders = fullDataset; // Store full dataset for import
            this.missingParts = missingParts;
            this.hasNextPage = nextPageAvailable;
            this.totalItems = totalCount;
            this.stats = stats;

            this.renderOrdersTable();
            this.renderPagination();
            this.updateStats();

            if (missingParts.length !== 0) {
                this.generateMissingPartsError(missingParts);
                $("#importOrders").prop('disabled', true);
            } else {
                $("#missingPartsAlert").hide();
                $("#importOrders").prop('disabled', false);
            }

        } catch (error) {
            console.error("Error rendering orders:", error);
            this.generateError("Błąd podczas ładowania zamówień: " + error);
        } finally {
            this.isLoading = false;
            this.hideLoadingOverlay();
        }
    }

    getFilters() {
        return {
            grnId: $("#filterGrnId").val() || '',
            poId: $("#filterPoId").val() || '',
            partName: $("#filterPartName").val() || [],
            dateFrom: $("#filterDateFrom").val() || '',
            dateTo: $("#filterDateTo").val() || '',
            page: this.currentPage
        };
    }

    renderOrdersTable() {
        const $tbody = $("#fromOrdersTBody");
        $tbody.empty();

        if (!this.paginatedOrders || this.paginatedOrders.length === 0) {
            $tbody.append(this.renderEmptyState());
            return;
        }

        this.paginatedOrders.forEach(order => {
            const renderedRow = this.ordersTemplate
                .map((token, i) => (i % 2) ? order[token] : token)
                .join('');
            $tbody.append(renderedRow);
        });
    }

    renderEmptyState() {
        return `
            <tr>
                <td colspan="5" class="text-center py-5">
                    <div class="text-muted">
                        <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                        <h5 class="mt-3">Brak zamówień do wyświetlenia</h5>
                        <p>Spróbuj zmienić filtry lub pobierz nowe zamówienia</p>
                    </div>
                </td>
            </tr>
        `;
    }

    renderPagination() {
        const paginationHtml = this.buildPaginationHtml();
        $("#paginationTop").html(paginationHtml);
        $("#paginationBottom").html(paginationHtml);
        this.attachPaginationHandlers();
    }

    buildPaginationHtml() {
        if (this.totalItems === 0) {
            return '';
        }

        const totalPages = this.getTotalPages();
        const start = (this.currentPage - 1) * this.itemsPerPage + 1;
        const end = Math.min(this.currentPage * this.itemsPerPage, this.totalItems);

        return `
            <div class="d-flex flex-column align-items-center">
                <div class="text-muted small mb-2">
                    Wyświetlanie <strong>${start}-${end}</strong> z <strong>${this.totalItems}</strong> elementów
                    ${totalPages > 0 ? `(Strona ${this.currentPage} z ${totalPages})` : ''}
                </div>

                <div class="btn-group btn-group-sm" role="group">
                    <button class="btn btn-outline-primary pagination-btn"
                            data-action="first"
                            ${this.currentPage === 1 ? 'disabled' : ''}>
                        <i class="bi bi-chevron-double-left"></i> Pierwsza
                    </button>
                    <button class="btn btn-outline-primary pagination-btn"
                            data-action="prev"
                            ${this.currentPage === 1 ? 'disabled' : ''}>
                        <i class="bi bi-chevron-left"></i> Poprzednia
                    </button>

                    <div class="btn-group" role="group">
                        <button type="button"
                                class="btn btn-primary dropdown-toggle"
                                data-toggle="dropdown">
                            ${this.currentPage}
                        </button>
                        <div class="dropdown-menu page-dropdown-menu" style="max-height: 300px; overflow-y: auto;">
                            ${this.buildPageDropdownItems(totalPages)}
                        </div>
                    </div>

                    <button class="btn btn-outline-primary pagination-btn"
                            data-action="next"
                            ${!this.hasNextPage || (totalPages > 0 && this.currentPage >= totalPages) ? 'disabled' : ''}>
                        Następna <i class="bi bi-chevron-right"></i>
                    </button>
                </div>
            </div>
        `;
    }

    buildPageDropdownItems(totalPages) {
        let items = '';

        if (totalPages > 0) {
            for (let i = 1; i <= totalPages; i++) {
                const isActive = i === this.currentPage ? 'active' : '';
                items += `
                    <a class="dropdown-item page-dropdown-item ${isActive}"
                       href="#"
                       data-page="${i}">
                       Strona ${i}
                    </a>
                `;
            }
        }

        items += `
            <div class="dropdown-divider"></div>
            <div class="px-3 py-2">
                <label class="small mb-1">Przejdź do strony:</label>
                <div class="input-group input-group-sm">
                    <input type="number"
                           class="form-control custom-page-input"
                           placeholder="Nr"
                           min="1">
                    <div class="input-group-append">
                        <button class="btn btn-primary go-to-custom-page" type="button">
                            <i class="bi bi-arrow-right"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;

        return items;
    }

    getTotalPages() {
        if (this.totalItems <= 0) return 0;
        return Math.ceil(this.totalItems / this.itemsPerPage);
    }

    attachPaginationHandlers() {
        $(document).off('click', '.pagination-btn').on('click', '.pagination-btn', (e) => {
            const action = $(e.currentTarget).data('action');

            switch(action) {
                case 'first':
                    this.goToPage(1);
                    break;
                case 'prev':
                    this.goToPage(this.currentPage - 1);
                    break;
                case 'next':
                    this.goToPage(this.currentPage + 1);
                    break;
            }
        });

        $(document).off('click', '.page-dropdown-item').on('click', '.page-dropdown-item', (e) => {
            e.preventDefault();
            const page = parseInt($(e.currentTarget).data('page'));

            if (page && page >= 1) {
                this.goToPage(page);
            }
        });

        $(document).off('click', '.go-to-custom-page').on('click', '.go-to-custom-page', (e) => {
            const pageInput = $(e.currentTarget).closest('.input-group').find('.custom-page-input');
            const page = parseInt(pageInput.val());

            if (page && page >= 1) {
                this.goToPage(page);
            }
        });

        $(document).off('keypress', '.custom-page-input').on('keypress', '.custom-page-input', (e) => {
            if (e.which === 13) {
                e.preventDefault();
                const page = parseInt($(e.currentTarget).val());

                if (page && page >= 1) {
                    this.goToPage(page);
                }
            }
        });
    }

    goToPage(page) {
        if (page < 1 || this.isLoading) return;

        const totalPages = this.getTotalPages();

        if (totalPages > 0 && page > totalPages) {
            this.generateError(`Strona ${page} nie istnieje. Maksymalna strona: ${totalPages}`);
            return;
        }

        this.currentPage = page;
        this.render();

        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    updateStats() {
        if (this.stats.total === 0) {
            $("#statsBar").hide();
            return;
        }

        $("#statTotal").text(this.stats.total);
        $("#statMissing").text(this.stats.withMissingParts);
        $("#statReady").text(this.stats.readyToImport);

        $("#statsBar").show();
    }

    updateFilterOptions() {
        // Populate GRN ID select
        const $grnSelect = $("#filterGrnId");
        const currentGrn = $grnSelect.val();
        $grnSelect.empty().append('<option value="">Wszystkie</option>');
        this.filterOptions.allGRNIDs.forEach(grnId => {
            $grnSelect.append(`<option value="${grnId}">${grnId}</option>`);
        });
        $grnSelect.val(currentGrn);
        $grnSelect.selectpicker('refresh');

        // Populate PO ID select
        const $poSelect = $("#filterPoId");
        const currentPo = $poSelect.val();
        $poSelect.empty().append('<option value="">Wszystkie</option>');
        this.filterOptions.allPOIDs.forEach(poId => {
            $poSelect.append(`<option value="${poId}">${poId}</option>`);
        });
        $poSelect.val(currentPo);
        $poSelect.selectpicker('refresh');

        // Populate Part Name select
        const $partSelect = $("#filterPartName");
        const currentParts = $partSelect.val() || [];
        $partSelect.empty();
        this.filterOptions.allPartNames.forEach(partName => {
            $partSelect.append(`<option value="${partName}">${partName}</option>`);
        });
        $partSelect.val(currentParts);
        $partSelect.selectpicker('refresh');
    }

    resetToFirstPage() {
        this.currentPage = 1;
    }

    importOrders() {
        if (this.allOrders.length === 0) {
            this.generateError("Brak zamówień do zaimportowania");
            return;
        }

        if (this.missingParts.length > 0) {
            this.generateError("Nie można zaimportować zamówień z brakującymi częściami");
            return;
        }

        // Show confirmation modal instead of importing directly
        this.showImportConfirmationModal();
    }

    showImportConfirmationModal() {
        // Populate modal with data from FULL dataset
        $('#modalTotalCount').text(this.stats.total);
        $('#modalTotalOrders').text(this.stats.total);

        // Calculate unique parts and POs from FULL dataset (allOrders)
        const uniqueParts = new Set(this.allOrders.map(o => o.PartName)).size;
        const uniquePOs = new Set(this.allOrders.map(o => o.PO_ID)).size;

        $('#modalUniqueParts').text(uniqueParts);
        $('#modalUniquePOs').text(uniquePOs);

        // Show missing parts warning if applicable
        if (this.missingParts.length > 0) {
            $('#modalMissingPartsWarning').show();
            $('#modalMissingPartsList').text(this.missingParts.join(', '));
        } else {
            $('#modalMissingPartsWarning').hide();
        }

        // Show filter warning if filters are active
        const filters = this.getFilters();
        const hasActiveFilters = filters.grnId || filters.poId ||
                                (filters.partName && filters.partName.length > 0) ||
                                filters.dateFrom || filters.dateTo;

        if (hasActiveFilters) {
            $('#modalFilterWarning').show();
        } else {
            $('#modalFilterWarning').hide();
        }

        // Reset checkbox and button state
        $('#confirmImportCheckbox').prop('checked', false);
        $('#confirmImportBtn').prop('disabled', true);

        // Enable confirm button only when checkbox is checked
        $('#confirmImportCheckbox').off('change').on('change', function() {
            $('#confirmImportBtn').prop('disabled', !$(this).is(':checked'));
        });

        // Handle confirm button click
        $('#confirmImportBtn').off('click').on('click', () => {
            $('#importConfirmationModal').modal('hide');
            this.executeImport();
        });

        // Show the modal
        $('#importConfirmationModal').modal('show');
    }

    executeImport() {
        // Import FULL dataset (allOrders), not just paginated subset
        this.showLoadingOverlay('Importowanie zamówień...');

        const data = {
            orders: JSON.stringify(this.allOrders),  // Use allOrders, not paginatedOrders!
            oldLastCellFound: this.oldLastFoundCell,
            newLastCellFound: this.newLastFoundCell
        };

        $.ajax({
            url: COMPONENTS_PATH + "/admin/components/fromorders/import-orders.php",
            type: 'POST',
            data: data,
            dataType: 'json',
            success: (response) => {
                this.hideLoadingOverlay();

                // Handle new JSON response format
                if (response.success === false) {
                    this.generateError(response.error || "Błąd importu");
                    return;
                }

                if (response.success === true && response.summary) {
                    // Show success modal with summary
                    this.showImportSuccessModal(response.summary);

                    // Update UI state
                    $("#successAlert").show();
                    $("#successAlert").html("Zamówienia zostały zaimportowane pomyślnie.");
                    $("#importOrders").prop('disabled', true);
                    $("#lastFoundCell").text(this.newLastFoundCell - 1);
                } else {
                    // Fallback for old response format (if response is empty string)
                    if (response === "" || response.length === 0) {
                        $("#successAlert").show();
                        $("#successAlert").html("Zamówienia zostały zaimportowane pomyślnie.");
                        $("#importOrders").prop('disabled', true);
                        $("#lastFoundCell").text(this.newLastFoundCell - 1);
                    } else {
                        this.generateError(response);
                    }
                }
            },
            error: (xhr, status, error) => {
                this.hideLoadingOverlay();

                // Try to parse error response
                try {
                    const errorResponse = JSON.parse(xhr.responseText);
                    if (errorResponse.error) {
                        this.generateError(errorResponse.error);
                        return;
                    }
                } catch (e) {
                    // Not JSON, use default error
                }

                this.generateError("Błąd importu: " + error);
            }
        });
    }

    showImportSuccessModal(summary) {
        // Populate summary header
        $('#successTotalOrders').text(summary.totalOrders);
        $('#successUniqueParts').text(summary.uniqueParts);
        $('#successTransferGroups').text(summary.transferGroupsCreated.length);

        // Build Transfer Groups Accordion (collapsible PO items)
        let accordionHtml = '';
        summary.ordersByPO.forEach((po, index) => {
            // Handle both array and object formats for grnIds
            const grnIdsArray = Array.isArray(po.grnIds) ? po.grnIds : Object.values(po.grnIds || {});
            const grnIdsStr = grnIdsArray.join(', ');

            // Sanitize PO ID for use in HTML IDs (replace slashes and special chars)
            const sanitizedPoId = po.poId.replace(/[\/\\]/g, '-');
            const collapseId = `collapse-po-${sanitizedPoId}`;
            const headingId = `heading-po-${sanitizedPoId}`;

            // Build parts table for this PO
            let partsTableRows = '';
            po.parts.forEach(part => {
                partsTableRows += `
                    <tr>
                        <td>${part.partName}</td>
                        <td class="text-right"><strong>${part.qty}</strong></td>
                        <td>${part.vendorJM || '-'}</td>
                    </tr>
                `;
            });

            // Create accordion card for this PO
            accordionHtml += `
                <div class="card mb-2">
                    <div class="card-header p-2" id="${headingId}">
                        <div class="d-flex justify-content-between align-items-center"
                             style="cursor: pointer;"
                             data-toggle="collapse"
                             data-target="#${collapseId}"
                             aria-expanded="false"
                             aria-controls="${collapseId}">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-chevron-right mr-2" id="icon-${collapseId}"></i>
                                <strong class="mr-2">PO-${po.poId}</strong>
                                <span class="badge badge-info mr-2">${po.transferGroupId}</span>
                                <small class="text-muted">GRN: ${grnIdsStr}</small>
                            </div>
                            <span class="badge badge-secondary">${po.parts.length} części</span>
                        </div>
                    </div>
                    <div id="${collapseId}" class="collapse" aria-labelledby="${headingId}">
                        <div class="card-body p-0">
                            <table class="table table-sm table-hover mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th style="width: 60%;">Nazwa części</th>
                                        <th style="width: 25%;" class="text-right">Ilość</th>
                                        <th style="width: 15%;">JM</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${partsTableRows}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            `;
        });
        $('#transferGroupsAccordion').html(accordionHtml);

        // Add event listeners to update chevron icons for main sections
        $('#transferGroupsSection').on('show.bs.collapse', function () {
            $('#icon-transferGroupsSection').removeClass('bi-chevron-right').addClass('bi-chevron-down');
        }).on('hide.bs.collapse', function () {
            $('#icon-transferGroupsSection').removeClass('bi-chevron-down').addClass('bi-chevron-right');
        });

        $('#componentsSummarySection').on('show.bs.collapse', function () {
            $('#icon-componentsSummarySection').removeClass('bi-chevron-right').addClass('bi-chevron-down');
        }).on('hide.bs.collapse', function () {
            $('#icon-componentsSummarySection').removeClass('bi-chevron-down').addClass('bi-chevron-right');
        });

        // Add event listeners to update chevron icons for PO items
        $('#transferGroupsAccordion .collapse').on('show.bs.collapse', function () {
            const collapseId = $(this).attr('id');
            $(`#icon-${collapseId}`).removeClass('bi-chevron-right').addClass('bi-chevron-down');
        }).on('hide.bs.collapse', function () {
            const collapseId = $(this).attr('id');
            $(`#icon-${collapseId}`).removeClass('bi-chevron-down').addClass('bi-chevron-right');
        });

        // Calculate and populate components summary (aggregated quantities)
        const componentMap = new Map();
        summary.ordersByPO.forEach(po => {
            po.parts.forEach(part => {
                if (componentMap.has(part.partName)) {
                    // Add to existing quantity
                    const existing = componentMap.get(part.partName);
                    existing.qty = parseFloat(existing.qty) + parseFloat(part.qty);
                } else {
                    // Add new component
                    componentMap.set(part.partName, {
                        qty: parseFloat(part.qty),
                        vendorJM: part.vendorJM || '-'
                    });
                }
            });
        });

        // Build components summary table
        let componentsSummaryHtml = '';
        componentMap.forEach((data, partName) => {
            componentsSummaryHtml += `
                <tr>
                    <td>${partName}</td>
                    <td class="text-right"><strong>${data.qty}</strong></td>
                    <td>${data.vendorJM}</td>
                </tr>
            `;
        });
        $('#componentsSummaryBody').html(componentsSummaryHtml);

        // Show the modal
        $('#importSuccessModal').modal('show');
    }

    showLoadingOverlay(message = 'Ładowanie zamówień...') {
        const $overlay = $('#loadingOverlay');
        $overlay.find('h5').text(message);
        $overlay.css('display', 'flex'); // Use flex for centering
    }

    hideLoadingOverlay() {
        $('#loadingOverlay').fadeOut(300);
    }

    showSpinner() {
        $(".spinnerFromOrders").show();
    }

    hideSpinner() {
        $(".spinnerFromOrders").hide();
    }

    generateMissingPartsError(missingParts) {
        $("#missingPartsAlert").show();
        $("#missingParts").html(missingParts.join(", "));
    }

    generateError(message) {
        $("#errorAlert").html(message);
        $("#errorAlert").show();
    }
}
