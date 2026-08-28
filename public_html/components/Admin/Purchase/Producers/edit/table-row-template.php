<script type="text/template" data-template="producerRowTemplate">
    <!--
        Reference template for a Producer row. The actual rendering happens
        in producers-view.js via the renderRow() template literal — this
        file documents the column shape so future changes stay in sync.

        Variables interpolated by renderRow():
          ${id}              int
          ${rowClass}        'pr-row--inactive table-secondary' for inactive
                             rows, '' otherwise. Inactive state is conveyed
                             entirely by the row tint — no badge column.
          ${nameHtml}        html-escaped producer name
          ${komentarzCell}   small/muted text with bi-journal-text icon;
                             truncated with ellipsis at 200 chars (full text
                             stays on the native title tooltip)
          ${vendorPartCount} int — number of `list__vendor_part` rows
                             referencing this producer. Shows '—' when 0
                             so the column doesn't read like "0 used".
          ${editBtn}         <a class="btn btn-sm btn-warning" href="/atte_ms_new/admin/purchase/producers/edit?id=${id}">
                             — the dedicated edit page (see edit/producer-edit-view.php).
                             Was a <button class="edit-producer-btn"> + modal in the
                             previous design.

        Column order (matches <thead> in producers-view.php):
          ID | Nazwa | Komentarz | Liczba artykułów | Akcje

        5 columns. colspan for in the empty-state row is also 5.
    -->
</script>