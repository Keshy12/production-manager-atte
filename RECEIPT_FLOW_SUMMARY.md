# Receipts page — what changed (invoice-driven reception)

Date: 2026-10-31 · Commit: `35209f0` · Plan: [`docs/procurement/RECEIPT_INVOICE_FLOW_PLAN.md`](docs/procurement/RECEIPT_INVOICE_FLOW_PLAN.md)

## The problem we solved

Reception used to work **per purchase order**: one page per PO, one
„Akceptuję” step, then quantities per PO line. In reality a vendor ships
**one package / one invoice** whose lines come from **several POs at once**,
so the operator had to split the invoice by hand across several pages.

## The new flow

One document per vendor invoice (or WZ), entered line by line:

1. **Pick a vendor.** Vendors with open POs are listed first.
2. **Fill the header:** document type (Faktura / WZ), document number
   (required), document date, receipt date (default today), target magazine
   (default for all lines), currency, comment. Saved as you type.
3. **Type the invoice lines:** article → quantity → price. Keyboard-first:
   Tab → Tab → Enter adds the line and puts the cursor back on the article
   field. The price is prefilled from the PO.
4. **The system matches each line to open PO lines** and shows the split
   right under the line (`PO/00038/00558 — 3, zostanie 4997`). The operator
   can override the split by hand.
5. **„Zatwierdź przyjęcie”** posts the whole document in one transaction:
   stock is booked, every PO the invoice touches is updated.
6. **A summary window** lists each touched PO with its progress, the lines
   still missing, and a choice: „Zostaw otwarte” / „Zamknij zamówienie”.

### How a line is matched to a PO

Candidates are the open PO lines of that vendor for the same article
(PO state `confirmed` / `in_progress` / `partially_received`, still
remaining). They are ordered:

1. **price match** first (the PO price equals the invoice price),
2. then the **earliest due date**,
3. then the **oldest PO**.

The line is filled greedily across candidates. Whatever does not fit any PO
becomes **surplus**: it is still booked into stock, but unallocated, and the
line then requires a comment before posting.

### Flags shown per line

| Flag | Meaning |
|---|---|
| `cena ≠ PO` | the invoice price differs from the PO price (shows both and the delta) |
| `cena brak w PO` | the PO line has no price yet — the invoice price is written there |
| `nadwyżka` | quantity beyond all open PO lines of that article |
| `brak w zamówieniach` | the article is on no open PO |
| `waluta` | the PO line currency differs from the document currency — **blocks posting** |
| `duplikat` | the same article appears twice on the document |

## Decisions taken (all confirmed)

- The invoice price is stored on the delivery line and used for matching. A
  mismatch is a **warning**, not an error. It is written to
  `purchase__order_item.unit_price` **only when that is NULL**. It never
  touches `list__parts.price`.
- Quantity beyond all open POs is **booked into stock as unordered**, with a
  mandatory comment on the line.
- Lines are saved **server-side as a draft** as they are typed, so a long
  invoice survives a reload or a break. Drafts are listed in the „Robocze” tab.
- The „Potwierdź odbiór” step (`in_progress` state) is **retired**. No
  reception path produces it any more.
- The document number is **mandatory**; the type is selectable (Faktura / WZ).
- The invoice quantity is in the same unit as the PO line. No pack
  conversion.
- A posted delivery can only be **cancelled as a whole** (reversal). No
  per-line edits after posting.

## Database changes

Applied by the idempotent migration script:

```bash
php src/cron/migrate-purchase-delivery-schema.php --dry-run   # preview
php src/cron/migrate-purchase-delivery-schema.php             # apply
```

**New tables**

| Table | Holds |
|---|---|
| `purchase__delivery` | the invoice/WZ header — vendor, type, number, dates, currency, magazine, state (`draft` / `posted` / `cancelled`), GRN number, transfer group |
| `purchase__delivery_line` | one row per invoice line — article, quantity, price, currency, magazine, comment, the stock row it booked |
| `purchase__delivery_allocation` | how one line is split across PO lines — quantity and a `is_manual` flag |

**New columns**

| Table | Column |
|---|---|
| `purchase__order_receipt` | `delivery_id` — links the per-PO receipt back to its invoice |
| `purchase__order_receipt_item` | `delivery_allocation_id` — 1:1 traceability allocation → receipt item |

**Also:** a new stock-transfer type `purchase_delivery` (label
„Przyjęcie dostawy {grn} — {vendor}”), so one delivery is one transfer group
instead of one per PO.

**Legacy cleanup (runs inside the migration):** empty draft receipts are
deleted, and POs left in `in_progress` go back to `confirmed` or
`partially_received` depending on what was received.

### The old tables still exist, on purpose

`purchase__order_receipt` and `purchase__order_receipt_item` are **generated
when a delivery is posted** — one receipt per touched PO, one receipt item
per allocation. They are the per-PO projection of the invoice. Everything
that already read them (history tab, receipt detail modal, PO edit page,
progress bars, the close/delete guards) keeps working unchanged.

## Files

**New**

```
src/cron/migrate-purchase-delivery-schema.php     schema + legacy cleanup (--dry-run)
src/classes/Utils/Purchase/Order/
  class-delivery.php  class-deliveryline.php  class-deliveryallocation.php
  class-deliveryrepository.php  class-deliverylinerepository.php
  class-deliverymatcher.php                     the matching algorithm
public_html/components/purchases/receipts/
  delivery-page.php  delivery-page.js  delivery-ui.css      the new page
  delivery-common.php                            shared guard/helpers
  delivery-vendors.php      delivery-open-items.php
  delivery-part-search.php  delivery-create.php
  delivery-header-save.php  delivery-line-save.php
  delivery-line-delete.php  delivery-line-allocate.php
  delivery-get.php           delivery-list.php
  delivery-post.php          delivery-discard.php
  delivery-cancel.php                            13 endpoints
```

**Changed**

- `src/classes/Utils/Purchase/Order/class-purchaseactionhandler.php` — new
  methods: `createDeliveryDraft`, `updateDeliveryHeader`, `addDeliveryLine`,
  `updateDeliveryLine`, `removeDeliveryLine`, `setLineAllocation`,
  `getDeliveryState`, `postDelivery`, `discardDeliveryDraft`,
  `cancelPostedDelivery`. `acceptReceiptMetadata` removed.
- `src/classes/Utils/Purchase/Order/class-orderreceiptrepository.php` —
  `delete()` refuses rows linked to a delivery (cancel the whole document).
- `public_html/components/purchases/receipts/receipts-view.php` / `.js` —
  three tabs: **„Do przyjęcia”** (open POs grouped by vendor), **„Robocze”**
  (drafts), **„Historia przyjęć”** (posted deliveries + legacy receipts).
- `index.php` — new route `purchase/receipts/new`; the old
  `purchase/receipts/receive` now redirects to the new page with the vendor
  preselected.
- `public_html/components/purchases/documents/documents-view.js`,
  `Admin/Purchase/Documents/documents-edit.php`,
  `purchases/receipts/receipt-view.php` — the per-PO receive links are now a
  single „Przyjmij dostawę”.

**Deleted** (the old per-PO reception path)

```
receive-page.php  receive-page.js  receive-form.php
receipt-po-get.php  receipt-create.php  receipt-accept.php  receipts.css
```

**Docs updated:** `docs/data/DATABASE.md`, `docs/system/ROUTING.md`,
`docs/code/MODULES.md`, `docs/code/CLASSES.md`, `docs/operations/CRON.md`,
`docs/procurement/PLAN.md`, `docs/INDEX.md`, plus the new plan file.

## Behaviour worth knowing

- **PO state on the delivery paths is per-line.** A PO becomes `received`
  only when **every** line on it is complete. One over-received line can no
  longer hide a short line and mark the PO as fully received. The older
  `createReceipt()` path keeps its previous sum-based rule.
- **Posting is atomic.** Stock, `quantity_received`, the receipts and the PO
  states all commit together or not at all.
- **Cancelling reverses everything**: the stock rows are flagged cancelled
  (the triggers restore the stock), the receipts are removed, the received
  quantities go back and the PO states are recomputed. A PO that was closed
  by hand is protected: cancelling refuses to touch it.
- **All AJAX in this repo is POST.** The `.htaccess` blocks GET requests to
  component files, so the read endpoints (`delivery-vendors`,
  `delivery-open-items`, `delivery-part-search`, `delivery-list`,
  `delivery-get`) take POST as well.
- **The GRN number** is allocated on posting from `purchase__number_counter`
  (type `grn`), the same counter the old flow used.

## Deployment order

1. `php src/cron/migrate-purchase-delivery-schema.php --dry-run` → read the
   output.
2. `php src/cron/migrate-purchase-delivery-schema.php` → apply.
3. Deploy the code.

Run the migration **before** the code, or the new pages will fail on missing
tables.

## Verification status

| Check | Result |
|---|---|
| PHP syntax (`php -l`) on all 33 changed/added PHP files | pass |
| JS syntax (`node --check`), no `var` | pass |
| Migration: dry run → apply → re-apply | pass, second run changed nothing |
| Posting + cancelling, from the command line inside a rolled-back transaction | pass |
| Draft flow in a real browser against the dev server (vendor → 2 lines → allocation editor → discard) | pass, no console errors |
| Per-line PO state rule (short line + over-received line on one PO) | pass |
| **A real posting clicked through in the browser** | **not tested yet — this is the acceptance test** |
| The old URL's redirect opened in a browser | not tested |

### How to run the acceptance test

1. Open `/purchase/receipts` → „Przyjmij dostawę” next to a vendor with open
   POs.
2. Fill in the document number and magazine, add 2–3 lines with Tab → Tab →
   Enter.
3. „Zatwierdź przyjęcie” → check the summary window per PO.
4. Open the delivery in „Historia przyjęć” → „Anuluj przyjęcie”. Stock and
   the PO states must return to what they were.

### Known leftovers

- 2 rows from an accidental test post remain in the dev DB:
  `inventory__parts` 393651 and transfer group 129045. Both are already
  cancelled, so stock is unaffected. They can be deleted by hand.
- A few code comments still mention the deleted files. Harmless.
- `atte_ms_struct.sql` was updated with the new DDL, but the file is
  gitignored (`*.sql`), so it does not travel with a commit.
