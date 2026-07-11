# Action Required: Sales Order `status_id` is now server-managed

> **Date:** 2026-07-11
> **Affects:** any frontend code that sends `status_id` to the Sales Order API, or shows a status picker for create/approve/reject
> **Related:** [docs/CHANGELOG.md](CHANGELOG.md#2026-07-11-180000-wib--sales-order-status_id-is-now-server-managed), [docs/api/sales-order.md](api/sales-order.md), [docs/api/sales-status.md](api/sales-status.md)

---

## What changed

`status_id` is no longer something the frontend sends when creating, updating, approving, or rejecting a sales order. The backend now sets it automatically based on which action is being performed:

| Action | Endpoint | Status set automatically |
|--------|----------|---------------------------|
| Create | `POST /api/v2/sales-order` | `"Draft"` |
| Approve | `PATCH /api/v2/sales-order/{id}/approve` | `"Approved"` |
| Reject | `PATCH /api/v2/sales-order/{id}/reject` | `"Rejected"` |
| Revise | `PATCH /api/v2/sales-order/{id}/revise` | `"Draft"` (only if current status is `"Rejected"`, else `400`) |
| Update | `PUT /api/v2/sales-order/{id}` | *(cannot change status at all — use approve/reject/revise)* |

Status on a sales order is a workflow state, not a free-form choice — unlike `ppn_type_id` (a real dropdown the user picks from), the status was never meant to be something the user selects at creation time. It's now handled entirely server-side.

---

## Why

`sales_status.id` is a `generateUUID()` value generated independently in every environment — "Draft" in dev and "Draft" in production do not share the same `id`. The old design required the frontend to know and send that UUID, which meant:

- a hardcoded `status_id` copied from one environment silently broke (or worse, wrote a wrong status) in another
- the frontend had to fetch `GET /api/v2/sales-status` and resolve a name → id mapping just to create a sales order, for a value the user never actually chose

Removing `status_id` from the client payload removes the bug class entirely, not just adds a validation check on top of it.

---

## What to change in the frontend

1. **`POST /api/v2/sales-order`** — remove `status_id` from the create payload. It's not read by the backend anymore.
2. **`PUT /api/v2/sales-order/{id}`** — remove `status_id` from the update payload if present. Status can no longer be changed here at all.
3. **`PATCH /api/v2/sales-order/{id}/approve`** and **`PATCH /api/v2/sales-order/{id}/reject`** — remove `status_id` from the body. Only `notes` (optional) is still accepted.
4. **Remove any status dropdown/picker** used on the create form or the approve/reject actions — there's nothing left for the user to choose there.

### Before

```js
await api.post('/sales-order', {
  ...payload,
  status_id: selectedStatus.id, // remove this
});

await api.patch(`/sales-order/${id}/approve`, {
  status_id: approvedStatusId, // remove this
  notes,
});
```

### After

```js
await api.post('/sales-order', payload); // no status_id

await api.patch(`/sales-order/${id}/approve`, { notes }); // no status_id
```

---

## Revision flow (e.g. `SalesOrderDetail.js` `handleRevision`)

If you have a "revise" action that does `PUT .../sales-order/{id}` with `status_id: draftStatusId` to move a rejected order back to `Draft` after editing, **that call will no longer change the status** — `PUT` never reads `status_id` anymore, so the order will keep showing as `Rejected` even though the edit was saved.

Use the new `PATCH /api/v2/sales-order/{id}/revise` endpoint instead. It only changes status — send field edits through the normal `PUT` call as before, then call `revise` separately to flip the order back to `Draft`:

### Before

```js
async function handleRevision(id, formValues, draftStatusId) {
  await api.put(`/sales-order/${id}`, {
    ...formValues,
    status_id: draftStatusId, // silently ignored now — order stays Rejected
  });
}
```

### After

```js
async function handleRevision(id, formValues) {
  await api.put(`/sales-order/${id}`, formValues); // save the edited fields, no status_id
  await api.patch(`/sales-order/${id}/revise`);     // move status back to Draft
}
```

Notes:
- `revise` only succeeds if the order's current status is `Rejected`; otherwise it returns `400 Only rejected sales orders can be revised`. If your revision UI is already gated to only show on rejected orders, no extra client-side check is needed.
- `revise` accepts an optional `notes` field (recorded on the audit log), same as `approve`/`reject`.
- There is no "un-approve" action. If your product needs a way to revert an `Approved` order back to `Draft`, that's a separate ask for the backend team — it's out of scope of this change.

---

## `status_id` as a list filter — unaffected, but resolve it dynamically

`GET /api/v2/sales-order?status_id=...` still works as a **read-only filter** on the list endpoint (e.g. a "Draft / Approved / Rejected" tab or filter dropdown). For that use case, keep resolving the `id` at runtime:

```js
const statuses = await api.get('/sales-status', { params: { limit: 100 } });
const draftId = statuses.data.data.data.find(s => s.status_name === 'Draft')?.id;
await api.get('/sales-order', { params: { status_id: draftId } });
```

Do not hardcode that `id` either — same environment-mismatch risk applies to filtering as it did to writes.

---

## If you see a `500` error mentioning a status name

e.g. `Sales status "Draft" is not configured` — this means the `sales_status` master table in that environment is missing the row the backend expects. That's a backend data-seeding problem, not something to fix in the frontend; flag it to the backend team.
