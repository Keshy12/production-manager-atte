<script type="text/template" data-template="vendorRowTemplate">
    <!--
        Reference template for a Vendor row. The actual rendering happens
        in vendors-view.js via the renderRow() template literal — this
        file documents the column shape so future changes stay in sync.

        Variables interpolated by renderRow():
          ${id}              int
          ${rowClass}        'vr-row--inactive table-secondary' for inactive
                             rows, '' otherwise. Inactive state is conveyed
                             entirely by the row tint — no badge column.
          ${nameHtml}        html-escaped vendor name
          ${addressHtml}     html-escaped address, or muted em-dash when empty
          ${komentarzCell}   small/muted text with bi-journal-text icon;
                             truncated with ellipsis at 200 chars (full text
                             stays on the native title tooltip)
          ${leadTimeCell}    int + ' dni' badge, or muted em-dash when null
          ${supplierBadge}   supplierCount as a small info badge; reads as
                             '—' when 0 so the column doesn't look like
                             "0 used"
          ${vendorPartBadge} vendorPartCount as a small info badge; same
                             empty-state rule
          ${editBtn}         <a class="btn btn-sm btn-warning" href="/atte_ms_new/admin/purchase/vendors/edit?id=${id}">
                             — the dedicated edit page (see edit/vendor-edit-view.php).
                             Was a <button class="edit-vendor-btn"> + modal in
                             the previous design.

        Column order (matches <thead> in vendors-view.php):
          ID | Nazwa | Adres | Komentarz | Lead time | #Dostawców | #Artykułów | Akcje

        8 columns. colspan for the empty-state row is also 8.
    -->
</script>