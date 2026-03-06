<!-- Session Transfers Modal -->
<div class="modal fade" id="sessionTransfersModal" tabindex="-1" role="dialog" aria-labelledby="sessionTransfersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="sessionTransfersModalLabel">
                    <i class="fas fa-exchange-alt"></i> Transfery Sesji
                </h5>
                <button type="button" class="close" data-dismiss="modal" aria-label="Zamknij">
                    <span aria-hidden="true">&times;</span>
                </button>
            </div>
            <div class="modal-body">
                <!-- Session Metadata -->
                <div id="sessionMetadata" class="card mb-3" style="display: none;">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <strong>ID Sesji:</strong>
                                <p id="modalSessionId" class="mb-0"></p>
                            </div>
                            <div class="col-md-3">
                                <strong>Data/Czas:</strong>
                                <p id="modalSessionDate" class="mb-0"></p>
                            </div>
                            <div class="col-md-3">
                                <strong>Zakres EventId:</strong>
                                <p id="modalEventRange" class="mb-0"></p>
                            </div>
                            <div class="col-md-3">
                                <strong>Status:</strong>
                                <p id="modalStatus" class="mb-0"></p>
                            </div>
                        </div>
                        <div class="row mt-2">
                            <div class="col-md-3">
                                <strong>Liczba transferów:</strong>
                                <p id="modalTransferCount" class="mb-0"></p>
                            </div>
                            <div class="col-md-3">
                                <strong>Liczba grup:</strong>
                                <p id="modalGroupCount" class="mb-0"></p>
                            </div>
                            <div class="col-md-3">
                                <strong>Czas trwania test:</strong>
                                <p id="modalDuration" class="mb-0"></p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Filters Section -->
                <div id="modalFilters" class="card mb-3 d-none">
                    <div class="card-header bg-light py-2" data-toggle="collapse" data-target="#filterContent" style="cursor: pointer;">
                        <div class="d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-filter"></i> <strong>Filtry</strong></span>
                            <span>
                                <small class="text-muted mr-2" id="activeFiltersCount"></small>
                                <i class="fas fa-chevron-down"></i>
                            </span>
                        </div>
                    </div>
                    <div id="filterContent" class="collapse show">
                        <div class="card-body">
                            <div class="row">
                                <!-- Operation Type Filter -->
                                <div class="col-md-3 mb-2">
                                    <label class="small font-weight-bold">Typ operacji:</label>
                                    <select id="filterOperationType" class="form-control form-control-sm">
                                        <option value="">Wszystkie</option>
                                        <option value="4">Produkcja</option>
                                        <option value="9">Sprzedaż</option>
                                        <option value="10">Zwrot</option>
                                        <option value="2">Przesunięcie</option>
                                        <option value="6">Zejście z magazynu</option>
                                    </select>
                                </div>
                                
                                <!-- Date From Filter -->
                                <div class="col-md-3 mb-2">
                                    <label class="small font-weight-bold">Data od:</label>
                                    <input type="datetime-local" id="filterDateFrom" class="form-control form-control-sm">
                                </div>
                                
                                <!-- Date To Filter -->
                                <div class="col-md-3 mb-2">
                                    <label class="small font-weight-bold">Data do:</label>
                                    <input type="datetime-local" id="filterDateTo" class="form-control form-control-sm">
                                </div>
                                
                                <!-- User Filter -->
                                <div class="col-md-3 mb-2">
                                    <label class="small font-weight-bold">Użytkownik:</label>
                                    <select id="filterUser" class="selectpicker form-control form-control-sm" data-live-search="true" data-width="100%" multiple data-actions-box="true" data-selected-text-format="count > 2" title="Wszyscy">
                                    </select>
                                    <small id="filterUserLoading" class="text-muted d-none">
                                        <i class="fas fa-spinner fa-spin"></i> Ładowanie...
                                    </small>
                                </div>
                            </div>
                            
                            <div class="row">
                                <!-- Device Filter -->
                                <div class="col-md-6 mb-2">
                                    <label class="small font-weight-bold">Urządzenia:</label>
                                    <select id="filterDevices" class="selectpicker form-control form-control-sm" data-live-search="true" data-width="100%" multiple data-actions-box="true" data-selected-text-format="count > 2" title="Wszystkie urządzenia">
                                    </select>
                                    <small id="filterDevicesLoading" class="text-muted d-none">
                                        <i class="fas fa-spinner fa-spin"></i> Ładowanie...
                                    </small>
                                </div>
                                
                                <!-- Search Filter -->
                                <div class="col-md-6 mb-2">
                                    <label class="small font-weight-bold">Szukaj (nazwa urządzenia, Event ID):</label>
                                    <div class="input-group input-group-sm">
                                        <input type="text" id="filterSearch" class="form-control" placeholder="Wpisz aby wyszukać...">
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-secondary" type="button" onclick="applyFilters()">
                                                <i class="fas fa-search"></i>
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row mt-2">
                                <div class="col-12">
                                    <button class="btn btn-sm btn-primary" onclick="applyFilters()">
                                        <i class="fas fa-filter"></i> Zastosuj filtry
                                    </button>
                                    <button class="btn btn-sm btn-outline-secondary ml-2" onclick="clearFilters()">
                                        <i class="fas fa-times"></i> Wyczyść
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Loading Spinner -->
                <div id="modalLoadingSpinner" class="text-center">
                    <div class="spinner-border text-primary" role="status">
                        <span class="sr-only">Ładowanie...</span>
                    </div>
                    <p>Ładowanie transferów...</p>
                </div>

                <!-- Transfers Content -->
                <div id="modalTransfersContent"></div>
            </div>
            <div class="modal-footer">
                <div id="modalPagination" class="mr-auto"></div>
                <button type="button" id="modalCancelAllBtn" class="btn btn-danger" style="display: none;">
                    <i class="fas fa-times-circle"></i> Anuluj wszystkie
                </button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Zamknij</button>
            </div>
        </div>
    </div>
</div>
