<script type="text/template" data-template="producerRowTemplate">
    <!--
        Reference template for a Producer row. The actual rendering happens
        in producers-view.js via renderRow() — this file documents the column
        shape so future changes stay in sync. Styles live in producers-ui.css
        (scoped under .prx-page, on top of the shared receipts-ui.css base).

        Fields used by renderRow():
          id, name, comment, isActive, vendorPartCount

        Row classes:
          inactive -> 'pr-row--inactive' (quiet tint + grey left bar, name
                      weight 500); active -> no extra class.

        Column order (matches <thead> in producers-view.php):
          ID | Producent | Artykuły | Status | Akcje

        Cells:
          ID         .pr-col-id  muted tabular digits, centred
          Producent  span.pr-name (semibold) + optional span.pr-cmt beneath it:
                     bi-journal-text icon (.pr-note) + span.pr-cmt-text with
                     the comment, single line, ellipsis, full text in title="".
                     The comment has no column of its own — it only helps
                     recognise the producer.
          Artykuły   span.pr-count: bi-box-seam icon + count; 0 dimmed (.pr-zero)
          Status     rx-badge rx-badge--green 'Aktywny' / rx-badge 'Nieaktywny'
          Akcje      one btn btn-sm rx-btn-quiet with bi-pencil, title + aria-label

        5 columns. Empty / error states are rendered as .rx-empty blocks
        replacing the table (#prStatus), not as a table row.
    -->
</script>
