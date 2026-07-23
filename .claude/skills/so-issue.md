# /so-issue

Diagnose why a sales order is stuck, given only an **SO number** and the **reported problem** (e.g. from a WhatsApp complaint like "TIDAK BISA LANJUT PROFIT" or "HAPUS").

Usage: `/so-issue {SONumber} {reported problem, optional}`

Example: `/so-issue 054/VIK-SO/II/2026 tidak bisa lanjut SPPB`

This investigates the live DB directly — it does not modify anything unless Step 6 explicitly proposes a fix and the user confirms.

---

## Step 0 — Connect

Read `connection/connection.php` for the current host/user/pass/db (do not hardcode credentials here — they rotate). Connect with the `mysql` CLI:

```
mysql -h {host} -P 3306 -u {user} -p'{pass}' {db}
```

If multiple SO numbers are given at once (e.g. a pasted WhatsApp list), run every step below for each one, then produce one combined report.

---

## Step 1 — Resolve the exact SO number

SO numbers in this system are hand-typed and frequently have typos: missing `/` (`135VIK-SO/V/2026`), stray trailing `.` (`166/VIK-SO/VI/2026.`), or duplicate numbers reused across different periods (`083/VIK-SO/II/2025` vs `083/VIK-SO/IV/2026` vs `083/VIK-SO/VI/2026.`).

Never trust an exact-match miss as "doesn't exist." Always run a fuzzy check first:

```sql
SELECT SONumber, SODate, InsertDt FROM salesOrder WHERE SONumber LIKE '%{numeric part}%VIK%' ORDER BY InsertDt;
```

If more than one row comes back, list all of them — the one the user means may not be the one they typed.

---

## Step 2 — Pull current status

```sql
SELECT A1.SONumber, A1.SODate, A1.SOStatus, A4.SO_Status_Name, A1.InsertDt, A1.UpdateDt, A1.UpdateBy
FROM salesOrder A1
LEFT JOIN salesStatus A4 ON A1.SOStatus = A4.SO_Status_ID
WHERE A1.SONumber = '{SONumber}';
```

Status ID reference (`salesStatus` table — re-verify with `SELECT * FROM salesStatus` if anything looks off, but this is the known map):

| SO_Status_ID | Name |
|---|---|
| 6d352c3a-1efc-11ef-a | Draft |
| 704908b0-1efc-11ef-a | Approve |
| 72d82232-1efc-11ef-a | Rejected |
| 76b86e17-1efc-11ef-a | SPPB Draft |
| d9b88016-1f11-11ef-a | SPPB Approved |
| dd82fd04-1f11-11ef-a | SPPB Rejected |
| 7c44858e-1efc-11ef-a | Profit Draft |
| 04eadb99-21a3-11ef-a | Profit Approved |
| 07527336-21a3-11ef-a | Profit Rejected |
| 8096b9e4-1efc-11ef-a | Delivery Order Draft |
| 35c04a28-2334-11ef-a | Delivery Order Approved |
| 380ba9a9-2334-11ef-a | Delivery Order Rejected |
| 995fe7dc-1efc-11ef-a | Sales Invoice Draft |
| 201b3d86-26eb-11ef-a | Sales Invoice Approved |
| 2358931e-26eb-11ef-a | Sales Invoice Rejected |
| 9b39ec93-1efc-11ef-a | Invoice Draft |
| 7fe60d9c-31ed-11ef-9 | Paid |

The workflow is strictly linear: **Draft → Approve → SPPB Draft → SPPB Approved → Profit Draft → Profit Approved → Delivery Order Draft → Delivery Order Approved → Sales Invoice Draft → ... → Paid**. Whatever status it's sitting at is the *last completed* step — the next step is what's missing.

---

## Step 3 — Pull the full action history

```sql
SELECT SONumber, Action, ActionBy, ActionDt FROM salesOrderHistory
WHERE SONumber = '{SONumber}' ORDER BY ActionDt;
```

Read the timeline top to bottom. Classify the last entry:

- Ends with `"...telah berhasil diinput dan menunggu persetujuan"` (submitted, awaiting approval) with **no later `"...disetujui"` for that same stage** → **stuck waiting on a human approver** for that stage. This is the most common case — not a bug.
- Ends with `"...ditolak"` (rejected) → the draft was rejected and needs to be **redone from that stage**, not just re-approved.
- History looks complete/advancing but `SOStatus` (Step 2) doesn't match the last history action → **status desync bug** — flag this explicitly, it means the status column update in the relevant `insert*.php`/`approve*.php` file didn't fire or used the wrong status ID.

---

## Step 4 — Check for the "empty header" data-integrity bug

This project's insert scripts (`insertsppb.php`, `insertsalesprofit.php`, `insertdeliveryorder.php`, `insertsalesinvoice.php`) are **not transactional** — the header row + history + status update can commit even if the item-row insert loop fails or receives zero items. This produces a header that exists but has no line items, which then can't be opened/approved by the frontend (looks identical to "stuck," but a re-approval attempt will never work — it needs re-drafting).

Check every stage the SO has reached so far for a header/item mismatch:

```sql
SELECT 'salesSPPB' t, COUNT(*) c FROM salesSPPB WHERE SPPBNumber='{SONumber}'
UNION ALL SELECT 'salesSPPBItem', COUNT(*) FROM salesSPPBItem WHERE SPPBNumber='{SONumber}'
UNION ALL SELECT 'salesProfit', COUNT(*) FROM salesProfit WHERE SalesNumber='{SONumber}'
UNION ALL SELECT 'salesProfitItem', COUNT(*) FROM salesProfitItem WHERE SalesOrderNumber='{SONumber}'
UNION ALL SELECT 'salesDelivery', COUNT(*) FROM salesDelivery WHERE SONumber='{SONumber}'
UNION ALL SELECT 'salesDeliveryItem', COUNT(*) FROM salesDeliveryItem WHERE DeliveryOrder='{SONumber}'
UNION ALL SELECT 'salesInvoice', COUNT(*) FROM salesInvoice WHERE salesOrder='{SONumber}'
UNION ALL SELECT 'salesInvoiceItem', COUNT(*) FROM salesInvoiceItem WHERE SONumber='{SONumber}';
```

A header count of 1 with its matching item count of 0 (for a stage the SO has already passed, per Step 2/3) confirms this bug — call it out explicitly rather than telling the user to "just wait for approval."

---

## Step 5 — Check for a real invoice/receivable before proposing any delete

If the complaint implies deletion ("HAPUS") or the SO looks abandoned, **never assume it's safe to delete just because it was flagged**. Check whether it already produced a real financial document:

```sql
SELECT invoiceNumber, salesOrder, invoiceDate FROM salesInvoice WHERE salesOrder = '{SONumber}';
-- if a row comes back, check the receivable:
SELECT id_transaction, invoice_number, paid_amount, due_amount FROM financeItem WHERE invoice_number = '{invoiceNumber from above}';
```

If a `financeItem` row exists with `due_amount > 0`, this is a live unpaid receivable — flag it clearly and get explicit confirmation before recommending or running any delete, even if the original note said "HAPUS." The note may predate the SO progressing further.

---

## Step 6 — Report + propose a fix

Report, per SO number:
1. **Current status** (Step 2) and **what the reported problem claimed** — call out explicitly if they don't match (the reported symptom can be stale, as in the `166` case where "can't proceed to Profit" was reported but the DB showed it had already passed Profit and was rejected at Delivery Order).
2. **Root cause**: waiting on approval / rejected needs redo / empty-header bug / status desync / duplicate SO number / already resolved.
3. **Recommended action**:
   - Waiting on approval → name the stage and who needs to act (SPPB approver / Profit approver / DO approver) — no DB change needed.
   - Rejected → needs redraft from that stage — no DB change needed.
   - Empty-header bug → propose a cleanup transaction: delete the broken header (+ its item rows, if any exist for a different reason) and the misleading history line, then reset `SOStatus` back to the last real completed stage so the frontend allows re-drafting. **Do not execute** — present the SQL and ask for confirmation first, same as any destructive DB write.
   - Full delete requested → build the cascade delete respecting FK order (children before `salesOrder`): `financeItem` → `salesInvoiceItem` → `salesInvoice` → `salesDeliveryItem` → `salesDelivery` → `salesProfitItem` → `salesProfit` → `salesSPPBItem` → `salesSPPB` → `salesOrderItem` → `salesOrderHistory` → `salesOrder`. Only include the tables that actually have rows (Step 4's counts). **Do not execute** without confirmation, especially if Step 5 found a receivable.

Never run a DELETE/UPDATE against the DB without the user explicitly confirming that specific SO/action — this mirrors how destructive changes are handled everywhere else in this project.

---

## Step 7 — WhatsApp-ready summary

After the technical report, always also produce a short **Bahasa Indonesia** summary formatted for pasting into WhatsApp (short paragraphs, bold with `*asterisks*`, one bullet per SO), following the tone/structure already used in this project: state the current blocker in plain terms and who/what needs to act next. Group by root cause (e.g. "Nunggu approval Profit:", "Perlu input ulang:", "Sudah beres:") rather than listing every SO individually with the same explanation repeated.
