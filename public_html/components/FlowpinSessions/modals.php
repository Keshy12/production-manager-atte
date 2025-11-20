<!-- Session Transfers Modal -->
<div class="modal fade" id="sessionTransfersModal" tabindex="-1" role="dialog" aria-labelledby="sessionTransfersModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="sessionTransfersModalLabel">
                    <i class="fas fa-exchange-alt"></i> Transfery Sesji
                </h5>
                <div class="custom-control custom-checkbox d-inline-block ml-3">
                    <input type="checkbox" class="custom-control-input" id="modalNoGrouping">
                    <label class="custom-control-label" for="modalNoGrouping">Nie grupuj</label>
                </div>
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
                                <strong>Czas trwania:</strong>
                                <p id="modalDuration" class="mb-0"></p>
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
                <button type="button" id="modalCancelAllBtn" class="btn btn-danger" style="display: none;">
                    <i class="fas fa-times-circle"></i> Anuluj wszystkie
                </button>
                <button type="button" class="btn btn-secondary" data-dismiss="modal">Zamknij</button>
            </div>
        </div>
    </div>
</div>
