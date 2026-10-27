<script type="text/template" data-template="vendorPartRowTemplate">
    <!--
        Reference template for a VendorPart row. The actual rendering happens
        in vendor-parts-view.js via renderRow() — this file documents the
        column shape so future changes stay in sync.

        Column order (matches <thead> in vendor-parts-view.php):
          ID | Artykuł | Dostawca / Producent | Opakowania | Status | Akcje

        Cells:
          id                int, grey tabular figures
          rowClass          'vp-row--inactive' for inactive rows (muted background
                            + grey left edge, see vendor-parts-ui.css), '' otherwise
          Artykuł           .vp-code  vendor part number (monospace, primary line)
                            .vp-sub   catalogue part name (muted line)
                            .vp-code--sub  'prod.: <no>' — only when the producer
                            number differs from the vendor number
                            .vp-cmt   last line, only when a comment exists (no
                            comment column): bi-journal-text icon (.vp-note, full
                            text in title=) + the comment in muted italics
                            (.vp-cmt-text), clipped with an ellipsis
          Dostawca / Prod.  .vp-name vendor name + .vp-sub producer name
                            (sub-line omitted when producerName is empty)
          Opakowania        .vp-pack-badge per pack size + unit name once,
                            or '—' when no packs are recorded
          Status            .rx-badge.rx-badge--green 'Aktywny' / neutral grey
                            .rx-badge 'Nieaktywny'
          Akcje             <a class="btn btn-sm rx-btn-quiet"
                               href="/atte_ms_new/admin/purchase/vendor-parts/edit?id=${id}"
                               title="Edytuj artykuł #id" aria-label="...">
                            with a bi-pencil icon only. Dedicated edit page:
                            see edit/vendor-part-edit-view.php.

        CSS lives in vendor-parts-ui.css (scoped under .vpx-page), on top of the
        shared receipts-ui.css. 6 columns; the first-load skeleton rows in
        vendor-parts-view.js also have 6 cells.
    -->
</script>
