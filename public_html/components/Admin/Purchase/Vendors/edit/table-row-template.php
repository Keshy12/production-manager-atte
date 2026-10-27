<script type="text/template" data-template="vendorRowTemplate">
    <!--
        Reference template for a Vendor row. The actual rendering happens
        in vendors-view.js via the renderRow() function — this file
        documents the column shape so future changes stay in sync.

        Visual language mirrors /purchase/documents (rx-* classes from
        receipts-ui.css + vendors-ui.css): the table sits inside an
        .rx-panel; ID muted + tabular, name semi-bold, quiet grey badge
        for inactive (never red), single icon-only quiet button for edit.

        Fields used by renderRow():
          id               int
          rowClass         'vr-row--inactive' for inactive rows (muted
                           background + grey left edge, see vendors-ui.css),
                           '' otherwise
          name             vendor name (.vr-name) — first line of the
                           "Dostawca" cell
          address          muted second line (.vr-sub), clipped, full text
                           in title; line omitted when the address is empty
          comment          not a column: a bi-journal-text icon followed by
                           the comment text in muted italics (.vr-cmt) on its
                           own third line, clipped with an ellipsis, full text
                           in the icon's title; omitted when empty
          leadTimeDays     '<n> dni', or muted em-dash when null
          supplierCount    person icon + number  } one "Powiązania" cell;
          vendorPartCount  box icon + number     } 0 shown dimmed
          isActive         .rx-badge.rx-badge--green 'Aktywny' /
                           .rx-badge (neutral grey) 'Nieaktywny'
          edit button      <a class="btn btn-sm rx-btn-quiet"> with bi-pencil,
                           title and aria-label

        Not shown (still returned by vendor-list.php): additionalData,
        createdAt. createdAt remains available through the date filter.

        Column order (matches <thead> in vendors-view.php):
          ID | Dostawca | Lead time | Powiązania | Status | Akcje

        6 columns. colspan for the loading / error row is also 6.
    -->
</script>
