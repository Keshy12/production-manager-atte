<?php

use Atte\DB\MsaDB;
use Atte\Utils\Locker;

$MsaDB = MsaDB::getInstance();
$locker = new Locker('flowpin.lock');
$isLocked = $locker->isLocked();

// Get last update timestamp
$lastUpdate = $MsaDB->query("SELECT last_timestamp FROM `ref__timestamp` WHERE id = 4")[0]["last_timestamp"] ?? 'Never';

// Get latest progress session status
$latestProgress = $MsaDB->query(
    "SELECT * FROM ref__flowpin_update_progress ORDER BY started_at DESC LIMIT 1",
    PDO::FETCH_ASSOC
);

$hasRunningSession = false;
$isStaleSession = false;
$latestSessionId = null;
$sessionInfo = null;

if (!empty($latestProgress) && $latestProgress[0]['status'] === 'running') {
    $sessionInfo = $latestProgress[0];
    $latestSessionId = $sessionInfo['session_id'];

    // Use lock status to determine if session is truly running
    if (!$isLocked) {
        $isStaleSession = true;
        
        // Mark session as error in DB proactively
        $staleId = $sessionInfo['id'];
        $transferCount = $MsaDB->query("
            SELECT 
                (SELECT COUNT(*) FROM inventory__sku WHERE flowpin_update_session_id = $staleId) +
                (SELECT COUNT(*) FROM inventory__tht WHERE flowpin_update_session_id = $staleId) +
                (SELECT COUNT(*) FROM inventory__smd WHERE flowpin_update_session_id = $staleId) +
                (SELECT COUNT(*) FROM inventory__parts WHERE flowpin_update_session_id = $staleId) as total
        ", PDO::FETCH_COLUMN);
        
        $groupCount = $MsaDB->query("
            SELECT COUNT(DISTINCT id) FROM inventory__transfer_groups 
                WHERE flowpin_update_session_id = $staleId
        ", PDO::FETCH_COLUMN);

        $MsaDB->update("ref__flowpin_update_progress", [
            "status" => "error",
            "updated_at" => date('Y-m-d H:i:s'),
            "created_transfer_count" => $transferCount[0] ?? 0,
            "created_group_count" => $groupCount[0] ?? 0
        ], "id", $staleId);
        
        // Update sessionInfo for the view to show 'error' status instead of 'running'
        $sessionInfo['status'] = 'error';
    } else {
        $hasRunningSession = true;
    }
}


?>
<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-12">
            <h1 class="text-center">Zarządzanie aktualizacją FlowPin</h1>
            <p class="text-center text-muted">Ostatnia aktualizacja: <?=$lastUpdate?></p>
            <hr>
        </div>
    </div>

    <!-- Analysis Section -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-info text-white">
                    <h3>Krok 1: Analiza danych przed aktualizacją</h3>
                </div>
                <div class="card-body">
                    <p>Uruchom analizę, aby sprawdzić, które użytkownicy i urządzenia wymagają działania przed wykonaniem aktualizacji.</p>
                    <button id="btnAnalyze" class="btn btn-primary" <?= ($hasRunningSession || $isStaleSession) ? 'disabled' : ''?>>
                        Uruchom analizę
                    </button>
                    <div id="spinnerAnalyze" style="display: none;" class="mt-2">
                        <div class="spinner-border" role="status"></div>
                        <span class="ml-2">Analizowanie danych...</span>
                    </div>
                    <div id="analysisResult" class="mt-3"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Issues Summary Section -->
    <div id="issuesSummary" class="row mt-4" style="display: none;">
        <div class="col-12">
            <h3>Znalezione problemy:</h3>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-warning">
                    <h5>Problemy z użytkownikami <span id="userIssueCount" class="badge badge-light">0</span></h5>
                </div>
                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                    <ul id="userIssuesList" class="list-group"></ul>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-warning">
                    <h5>Problemy z urządzeniami/SKU <span id="deviceIssueCount" class="badge badge-light">0</span></h5>
                </div>
                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                    <ul id="deviceIssuesList" class="list-group"></ul>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card">
                <div class="card-header bg-warning">
                    <h5>Problemy z magazynami <span id="warehouseIssueCount" class="badge badge-light">0</span></h5>
                </div>
                <div class="card-body" style="max-height: 300px; overflow-y: auto;">
                    <ul id="warehouseIssuesList" class="list-group"></ul>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Section -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h3>Krok 2: Uruchom aktualizację</h3>
                </div>
                <div class="card-body">
                    <p>Uruchom aktualizację danych z FlowPin. Proces będzie śledzony za pomocą paska postępu.</p>
                    <p class="text-info">
                        <strong>Uwaga:</strong> Problemy znalezione podczas analizy nie blokują aktualizacji.
                        Dla rekordów z problemami zostaną utworzone powiadomienia, które można później rozwiązać.
                    </p>
                    <p class="text-muted">
                        <small><strong>Info:</strong> Jeśli postęp aktualizacji wydaje się zablokowany, możesz ręcznie zatrzymać sesję używając przycisku "Resetuj" poniżej.</small>
                    </p>
                    <button id="btnUpdate" class="btn btn-success" <?= ($hasRunningSession || $isStaleSession) ? 'disabled' : ''?>>
                        Uruchom aktualizację
                    </button>

                    <?php if ($hasRunningSession): ?>
                        <div class="alert alert-info mt-3">
                            <strong>Aktualizacja w toku!</strong> Sesja: <?= $latestSessionId ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($isStaleSession): ?>
                        <div class="alert alert-warning mt-3">
                            <strong>Uwaga!</strong> Sesja <code><?= $latestSessionId ?></code> nie jest aktywna i prawdopodobnie uległa awarii (brak blokady systemowej).
                            <br>
                            <small>Ostatnia aktualizacja: <?= $sessionInfo['updated_at'] ?></small>

                            <br>
                            <button id="btnResetSession" class="btn btn-danger btn-sm mt-2" data-session-id="<?= $latestSessionId ?>">
                                <i class="bi bi-x-circle"></i> Resetuj zablokowaną sesję
                            </button>
                        </div>
                    <?php endif; ?>

                    <div id="progressSection" class="mt-4" style="display: <?= $hasRunningSession ? 'block' : 'none' ?>;">
                        <h4>Postęp aktualizacji:</h4>
                        <div class="progress" style="height: 30px;">
                            <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated"
                                 role="progressbar" style="width: 0%">
                                <span id="percentCompleted">0%</span>
                            </div>
                        </div>
                        <div class="mt-2">
                            <small>
                                Przetworzono: <span id="processedRecords">0</span> / <span id="totalRecords">0</span> rekordów
                            </small>
                            <br>
                            <small>
                                Bieżąca operacja: <span id="currentOperation">-</span>
                            </small>
                            <br>
                            <small>
                                EventID: <span id="currentEventId">-</span>
                            </small>
                            <br>
                            <small>
                                Status: <span id="updateStatus">-</span>
                            </small>
                        </div>
                    </div>

                    <div id="spinnerUpdate" style="display: none;" class="mt-2">
                        <div class="spinner-border" role="status"></div>
                        <span class="ml-2">Aktualizacja w toku, proszę NIE zamykać strony...</span>
                    </div>

                    <div id="updateResult" class="mt-3"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Link to Notifications -->
    <div class="row mt-4 mb-4">
        <div class="col-12 text-center">
            <hr>
            <p>Aby zobaczyć i rozwiązać powiadomienia utworzone podczas aktualizacji, przejdź do:</p>
            <a href="/index.php?action=admin" class="btn btn-secondary">Panel powiadomień</a>
        </div>
    </div>
</div>

<input type="hidden" id="latestSessionId" value="<?= $latestSessionId ?? '' ?>">

<script src="http://<?=BASEURL?>/public_html/components/FlowpinUpdate/flowpin-update.js"></script>
