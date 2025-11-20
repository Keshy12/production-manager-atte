class CommissionsRenderer {
    constructor() {
        this.commissionCardTemplate = null;
        this.currentPage = 1;
        this.totalItems = 0;
        this.itemsPerPage = 10;
        this.hasNextPage = false;
        this.isLoading = false;
        this.stats = {
            total: 0,
            active: 0,
            completed: 0,
            returned: 0,
            grouped: 0
        };

        this.init();
    }

    init() {
        this.commissionCardTemplate = $('script[data-template="commissionCard"]').text().split(/\$\{(.+?)\}/g);

        $(document).ready(() => {
            this.render();
        });
    }

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
                const totalCount = result[2] || null;
                const stats = result[3] || {};

                this.hasNextPage = nextPageAvailable;

                if (totalCount !== null && totalCount !== undefined) {
                    this.totalItems = totalCount;
                } else {
                    this.totalItems = this.estimateTotalItems(commissions.length, nextPageAvailable);
                }

                console.table(commissions);
                this.stats = {
                    total: totalCount || 0,
                    active: parseInt(stats.active_count) || 0,
                    completed: parseInt(stats.completed_count) || 0,
                    returned: parseInt(stats.returned_count) || 0,
                    grouped: parseInt(stats.grouped_count) || 0
                };

                this.renderCommissions(commissions);
                this.renderPagination();
                this.updateStats();
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
            dateFrom: $("#dateFrom").val(),
            dateTo: $("#dateTo").val(),
            page: this.currentPage
        };
    }

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

        this.initializePopovers();
    }

    initializePopovers() {
        $('[data-toggle="popover"]').popover('dispose');

        $('.commission-info-btn').popover({
            trigger: 'click',
            html: true,
            placement: 'bottom',
            container: 'body'
        });

        $(document).on('click', function (e) {
            const $target = $(e.target);

            if (!$target.closest('.commission-info-btn').length &&
                !$target.closest('.popover').length) {
                $('.commission-info-btn').popover('hide');
            }
        });

        $('.commission-info-btn').on('click', function(e) {
            e.stopPropagation();
            $('.commission-info-btn').not(this).popover('hide');
        });
    }

    renderCard(commission) {
        return this.commissionCardTemplate
            .map((token, i) => {
                return (i % 2) ? commission[token] : token;
            })
            .join('');
    }

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
            <div class="d-flex flex-column align-items-center mb-3">
                <div class="text-muted small mb-2">
                    Wyświetlanie <strong>${start}-${end}</strong> z <strong>${this.totalItems}</strong> elementów
                    ${totalPages > 0 ? `(Strona ${this.currentPage} z ${totalPages})` : ''}
                </div>
                
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

    buildPageDropdownItems(totalPages) {
        let items = '';

        if (totalPages > 0) {
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

    getTotalPages() {
        if (this.totalItems <= 0) return 0;
        return Math.ceil(this.totalItems / this.itemsPerPage);
    }

    estimateTotalItems(currentPageCount, hasNextPage) {
        if (hasNextPage) {
            return (this.currentPage * this.itemsPerPage) + 1;
        } else {
            return (this.currentPage - 1) * this.itemsPerPage + currentPageCount;
        }
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

        $(document).off('click', '.page-dropdown-menu').on('click', '.page-dropdown-menu', (e) => {
            if ($(e.target).closest('.input-group').length > 0 ||
                $(e.target).hasClass('custom-page-input')) {
                e.stopPropagation();
            }
        });
    }

    goToPage(page) {
        if (page < 1 || this.isLoading) return;

        const totalPages = this.getTotalPages();

        if (totalPages > 0 && page > totalPages) {
            this.showError(`Strona ${page} nie istnieje. Maksymalna strona: ${totalPages}`);
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
        $("#statActive").text(this.stats.active);
        $("#statCompleted").text(this.stats.completed);
        $("#statReturned").text(this.stats.returned);

        if ($("#groupTogether").prop('checked') && this.stats.grouped > 0) {
            $("#statGrouped").text(this.stats.grouped);
            $("#statsGrouped").show();
        } else {
            $("#statsGrouped").hide();
        }

        $("#statsBar").show();
    }

    showSpinner() {
        $("#transferSpinner").show();
    }

    hideSpinner() {
        $("#transferSpinner").hide();
    }

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

    resetToFirstPage() {
        this.currentPage = 1;
    }
}