/**
 * commissions-view-renderer.js
 * Updated with dynamic pagination based on total items and fixed popover handling
 */

class CommissionsRenderer {
    constructor() {
        this.commissionCardTemplate = null;
        this.currentPage = 1;
        this.totalItems = 0;
        this.itemsPerPage = 10;
        this.hasNextPage = false;
        this.isLoading = false;

        this.init();
    }

    init() {
        // Load card template
        this.commissionCardTemplate = $('script[data-template="commissionCard"]').text().split(/\$\{(.+?)\}/g);

        // Initialize on document ready
        $(document).ready(() => {
            this.render();
        });
    }

    /**
     * Main render function - fetches data and renders everything
     */
    render() {
        if (this.isLoading) return;

        this.isLoading = true;
        this.showSpinner();

        const filters = this.getFilters();

        $.ajax({
            type: "POST",
            url: COMPONENTS_PATH + "/commissions/get-commissions.php",
            data: filters,
            success: (data) => {
                const result = JSON.parse(data);
                const commissions = result[0];
                const nextPageAvailable = result[1];
                const totalCount = result[2] || null; // Total count from backend

                this.hasNextPage = nextPageAvailable;

                // Update total items (use backend count if available)
                if (totalCount !== null && totalCount !== undefined) {
                    this.totalItems = totalCount;
                } else {
                    // Fallback to estimation
                    this.totalItems = this.estimateTotalItems(commissions.length, nextPageAvailable);
                }

                this.renderCommissions(commissions);
                this.renderPagination();
                this.updateStats(commissions);
            },
            error: (xhr, status, error) => {
                this.showError("Błąd podczas ładowania zleceń: " + error);
            },
            complete: () => {
                this.isLoading = false;
                this.hideSpinner();
            }
        });
    }

    /**
     * Get current filter values
     */
    getFilters() {
        return {
            transferFrom: $("#transferFrom").val(),
            transferTo: $("#transferTo").val(),
            device: [
                $("#type").val(),
                $("#list__device").val(),
                $("#laminate").val(),
                $("#version").val()
            ],
            receivers: $("#user").val(),
            state_id: $("#state").val(),
            priority_id: $("#priority").val(),
            showCancelled: $("#showCancelled").prop('checked'),
            groupTogether: $("#groupTogether").prop('checked'),
            page: this.currentPage
        };
    }

    /**
     * Render commission cards
     */
    renderCommissions(commissions) {
        const container = $("#container");
        container.empty();

        if (!commissions || commissions.length === 0) {
            container.append(this.renderEmptyState());
            return;
        }

        commissions.forEach(commission => {
            const card = this.renderCard(commission);
            container.append(card);
        });

        // Initialize popovers with proper configuration
        this.initializePopovers();
    }

    /**
     * Initialize popovers with click trigger and outside click handling
     */
    initializePopovers() {
        // Destroy existing popovers first
        $('[data-toggle="popover"]').popover('dispose');

        // Initialize info button popovers
        $('.commission-info-btn').popover({
            trigger: 'click',
            html: true,
            placement: 'bottom',
            container: 'body'
        });

        // Close popover when clicking outside
        $(document).on('click', function (e) {
            const $target = $(e.target);

            // Don't close if clicking on the button itself or the popover content
            if (!$target.closest('.commission-info-btn').length &&
                !$target.closest('.popover').length) {
                $('.commission-info-btn').popover('hide');
            }
        });

        // Prevent event propagation on popover button
        $('.commission-info-btn').on('click', function(e) {
            e.stopPropagation();
            // Close other open popovers
            $('.commission-info-btn').not(this).popover('hide');
        });
    }

    /**
     * Render single commission card
     */
    renderCard(commission) {
        return this.commissionCardTemplate
            .map((token, i) => {
                return (i % 2) ? commission[token] : token;
            })
            .join('');
    }

    /**
     * Render empty state
     */
    renderEmptyState() {
        return `
            <div class="w-100 text-center py-5">
                <div class="text-muted">
                    <i class="bi bi-inbox" style="font-size: 3rem;"></i>
                    <h5 class="mt-3">Nie znaleziono zleceń</h5>
                    <p>Spróbuj zmienić filtry lub dodaj nowe zlecenie</p>
                </div>
            </div>
        `;
    }

    /**
     * Render pagination controls
     */
    renderPagination() {
        const paginationHtml = this.buildPaginationHtml();

        // Render in both top and bottom containers
        $("#paginationTop").html(paginationHtml);
        $("#paginationBottom").html(paginationHtml);

        // Attach event handlers
        this.attachPaginationHandlers();
    }

    /**
     * Build pagination HTML
     */
    buildPaginationHtml() {
        if (this.totalItems === 0) {
            return '';
        }

        const totalPages = this.getTotalPages();
        const start = (this.currentPage - 1) * this.itemsPerPage + 1;
        const end = Math.min(this.currentPage * this.itemsPerPage, this.totalItems);

        return `
            <div class="d-flex flex-column align-items-center mb-3">
                <!-- Items info -->
                <div class="text-muted small mb-2">
                    Wyświetlanie <strong>${start}-${end}</strong> z <strong>${this.totalItems}</strong> elementów
                    ${totalPages > 0 ? `(Strona ${this.currentPage} z ${totalPages})` : ''}
                </div>
                
                <!-- Pagination buttons -->
                <div class="btn-group btn-group-sm" role="group">
                    <button class="btn btn-outline-primary pagination-btn" 
                            data-action="first" 
                            ${this.currentPage === 1 ? 'disabled' : ''}>
                        <i class="bi bi-chevron-double-left"></i>
                    </button>
                    <button class="btn btn-outline-primary pagination-btn" 
                            data-action="prev" 
                            ${this.currentPage === 1 ? 'disabled' : ''}>
                        <i class="bi bi-chevron-left"></i> Poprzednia
                    </button>
                    
                    <!-- Page selector dropdown -->
                    <div class="btn-group" role="group">
                        <button type="button" 
                                class="btn btn-primary dropdown-toggle" 
                                data-toggle="dropdown" 
                                aria-expanded="false">
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

    /**
     * Build dropdown items for page selector
     */
    buildPageDropdownItems(totalPages) {
        let items = '';

        if (totalPages > 0) {
            // We know exact total pages - show all
            for (let i = 1; i <= totalPages; i++) {
                const isActive = i === this.currentPage ? 'active' : '';
                items += `
                    <a class="dropdown-item page-dropdown-item ${isActive}" 
                       href="#" 
                       data-page="${i}">
                       ${i}
                    </a>
                `;
            }
        } else {
            // Don't know exact total - show current + some more
            const pagesToShow = Math.max(this.currentPage + 10, 20);

            for (let i = 1; i <= pagesToShow; i++) {
                const isActive = i === this.currentPage ? 'active' : '';
                items += `
                    <a class="dropdown-item page-dropdown-item ${isActive}" 
                       href="#" 
                       data-page="${i}">
                        Strona ${i}
                    </a>
                `;
            }

            // Add divider and custom input for unknown totals
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
        }

        return items;
    }

    /**
     * Get total pages (0 if unknown)
     */
    getTotalPages() {
        if (this.totalItems <= 0) return 0;
        return Math.ceil(this.totalItems / this.itemsPerPage);
    }

    /**
     * Estimate total items when exact count is not available
     */
    estimateTotalItems(currentPageCount, hasNextPage) {
        if (hasNextPage) {
            // At least one more page exists
            return (this.currentPage * this.itemsPerPage) + 1;
        } else {
            // This is the last page
            return (this.currentPage - 1) * this.itemsPerPage + currentPageCount;
        }
    }

    /**
     * Attach pagination event handlers
     */
    attachPaginationHandlers() {
        // Pagination buttons
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

        // Page dropdown items
        $(document).off('click', '.page-dropdown-item').on('click', '.page-dropdown-item', (e) => {
            e.preventDefault();
            const page = parseInt($(e.currentTarget).data('page'));

            if (page && page >= 1) {
                this.goToPage(page);
            }
        });

        // Custom page input - button click
        $(document).off('click', '.go-to-custom-page').on('click', '.go-to-custom-page', (e) => {
            const pageInput = $(e.currentTarget).closest('.input-group').find('.custom-page-input');
            const page = parseInt(pageInput.val());

            if (page && page >= 1) {
                this.goToPage(page);
            }
        });

        // Custom page input - Enter key
        $(document).off('keypress', '.custom-page-input').on('keypress', '.custom-page-input', (e) => {
            if (e.which === 13) { // Enter key
                e.preventDefault();
                const page = parseInt($(e.currentTarget).val());

                if (page && page >= 1) {
                    this.goToPage(page);
                }
            }
        });

        // Prevent dropdown from closing when clicking inside custom input area
        $(document).off('click', '.page-dropdown-menu').on('click', '.page-dropdown-menu', (e) => {
            // Only stop propagation if clicking on the custom input section
            if ($(e.target).closest('.input-group').length > 0 ||
                $(e.target).hasClass('custom-page-input')) {
                e.stopPropagation();
            }
        });
    }

    /**
     * Go to specific page
     */
    goToPage(page) {
        if (page < 1 || this.isLoading) return;

        const totalPages = this.getTotalPages();

        // Validate page number if we know total pages
        if (totalPages > 0 && page > totalPages) {
            this.showError(`Strona ${page} nie istnieje. Maksymalna strona: ${totalPages}`);
            return;
        }

        this.currentPage = page;
        this.render();

        // Scroll to top
        $('html, body').animate({ scrollTop: 0 }, 300);
    }

    /**
     * Update statistics bar
     */
    updateStats(commissions) {
        if (!commissions || commissions.length === 0) {
            $("#statsBar").hide();
            return;
        }

        const stats = {
            total: commissions.length,
            active: commissions.filter(c => c.state === 'active').length,
            completed: commissions.filter(c => c.state === 'completed').length,
            grouped: 0
        };

        // Calculate grouped count if grouping is enabled
        if ($("#groupTogether").prop('checked')) {
            stats.grouped = commissions.reduce((sum, c) => {
                return sum + (c.groupedCount || 1);
            }, 0);
        }

        $("#statTotal").text(stats.total);
        $("#statActive").text(stats.active);
        $("#statCompleted").text(stats.completed);
        $("#statGrouped").text(stats.grouped || '-');

        $("#statsBar").show();
    }

    /**
     * Show loading spinner
     */
    showSpinner() {
        $("#transferSpinner").show();
    }

    /**
     * Hide loading spinner
     */
    hideSpinner() {
        $("#transferSpinner").hide();
    }

    /**
     * Show error message
     */
    showError(message) {
        const alertHtml = `
            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="close" data-dismiss="alert">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
        `;

        $("#ajaxResult").append(alertHtml);

        setTimeout(() => {
            $(".alert-danger").alert('close');
        }, 5000);
    }

    /**
     * Reset to first page
     */
    resetToFirstPage() {
        this.currentPage = 1;
    }
}