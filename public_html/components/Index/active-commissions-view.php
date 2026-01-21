<?php
include('modals.php');
include('commission-card-template.php');

echo '<div id="content" class="mt-4 container text-center">';

echo '<div class="card mb-3">';
echo '<div class="card-body p-3">';
echo '<div class="custom-control custom-switch">';
echo '<input type="checkbox" class="custom-control-input" id="groupCommissions" checked>';
echo '<label class="custom-control-label" for="groupCommissions">';
echo '<i class="bi bi-layers"></i> Grupuj identyczne zlecenia';
echo '</label>';
echo '</div>';
echo '</div>';
echo '</div>';

echo '<div class="d-flex justify-content-center mb-3">';
echo '<div style="display: none;" id="commissionsSpinner" class="spinner-border text-primary" role="status">';
echo '<span class="sr-only">Ładowanie...</span>';
echo '</div>';
echo '</div>';

echo '<div class="d-flex flex-wrap justify-content-center" id="commissionsContainer"></div>';

echo '</div>';
?>

<script>
    window.config = {
        baseUrl: '<?=BASEURL?>',
    };
</script>
<script src="<?= asset('public_html/components/index/active-commissions-view.js') ?>"></script>
