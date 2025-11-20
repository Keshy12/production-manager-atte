<?php
use Atte\DB\MsaDB;

$MsaDB = MsaDB::getInstance();

// Get filter parameters from URL
$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$status = $_GET['status'] ?? 'all';
?>

<div class="container-fluid mt-4">
    <h2><i class="fas fa-history"></i> Historia Sesji Aktualizacji FlowPin</h2>
    <p class="text-muted">Przeglądaj i zarządzaj sesjami aktualizacji FlowPin oraz utworzonymi transferami</p>

    <!-- Filters -->
    <div class="card mb-3">
        <div class="card-body">
            <div class="row">
                <div class="col-md-3">
                    <label class="font-weight-bold">Data od:</label>
                    <input type="date" id="dateFrom" class="form-control" value="<?= htmlspecialchars($dateFrom) ?>">
                </div>
                <div class="col-md-3">
                    <label class="font-weight-bold">Data do:</label>
                    <input type="date" id="dateTo" class="form-control" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
                <div class="col-md-3">
                    <label class="font-weight-bold">Status:</label>
                    <select id="statusFilter" class="form-control">
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Wszystkie</option>
                        <option value="completed" <?= $status === 'completed' ? 'selected' : '' ?>>Ukończone</option>
                        <option value="running" <?= $status === 'running' ? 'selected' : '' ?>>W trakcie</option>
                        <option value="error" <?= $status === 'error' ? 'selected' : '' ?>>Błąd</option>
                    </select>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button onclick="loadSessions()" class="btn btn-primary btn-block">
                        <i class="fas fa-filter"></i> Filtruj
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Loading spinner -->
    <div id="loadingSpinner" class="text-center d-none">
        <div class="spinner-border text-primary" role="status">
            <span class="sr-only">Ładowanie...</span>
        </div>
        <p>Ładowanie sesji...</p>
    </div>

    <!-- Sessions Table -->
    <div id="sessionsTableContainer"></div>
</div>

<?php include 'modals.php'; ?>

<script>

// Helper function for POST requests
function postData(url, data) {
    return fetch(url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: new URLSearchParams(data)
    });
}
</script>
<script src="http://<?=BASEURL?>/public_html/components/FlowpinSessions/flowpin-sessions-view.js"></script>