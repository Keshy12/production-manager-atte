<script type="text/template" data-template="producerRowTemplate">
    <!--
        Reference template for a Producer row. The actual rendering happens
        in producers-view.js via renderRow() — this file documents the column
        shape so future changes stay in sync. Styles live in the <style>
        block of producers-view.php (all scoped under #prTable).

        Fields used by renderRow():
          id, name, comment, isActive, vendorPartCount

        Row classes:
          inactive -> 'pr-row--inactive table-secondary' (grey text, name
                      weight 500); active -> no extra class.

        Column order (matches <thead> in producers-view.php):
          ID | Producent | Artykuły | Status | Akcje

        Cells:
          ID         .pr-col-id.text-center  muted tabular digits
          Producent  span.pr-name (semibold) + optional span.pr-note beneath it:
                     the comment, single line, ellipsis, full text in title="".
                     The comment has no column of its own — it only helps
                     recognise the producer.
          Artykuły   badge-info with count, '—' when 0
          Status     badge-success 'Aktywny' / .pr-badge-off 'Nieaktywny' (muted, not red)
          Akcje      one btn btn-sm btn-warning with bi-pencil, title + aria-label

        5 columns. colspan in the fallback message row is also 5.
    -->
</script>
