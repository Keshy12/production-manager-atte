<div class="container mt-5">
    <div class="row justify-content-center">
        <div class="col-md-8 text-center">
            <h1 class="mb-4">Aktualizacja cen komponentów</h1>
            <p class="text-muted mb-4">
                Ta funkcja pobiera najnowsze ceny zakupu części z arkusza Google Sheets (ceny_tmp). 
                Wszystkie powiązane urządzenia (SMD, THT, SKU) zostaną automatycznie przeliczone, 
                jeśli cena którejkolwiek z ich części składowych ulegnie zmianie.
            </p>
            
            <div id="ajaxResult" class="mb-4"></div>
            
            <div class="card shadow-sm">
                <div class="card-body py-5">
                    <button id="startSync" class="btn btn-primary btn-lg px-5">
                        <i class="bi bi-arrow-repeat mr-2"></i> Rozpocznij synchronizację
                    </button>
                    <div id="syncLoader" style="display:none;" class="mt-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="sr-only">Ładowanie...</span>
                        </div>
                        <p class="mt-2 text-primary font-weight-bold">Synchronizowanie cen... Proszę czekać.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= asset('public_html/components/admin/components/updateprices/update-prices-view.js') ?>"></script>
