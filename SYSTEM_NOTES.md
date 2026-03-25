# System Notes

## PMS Improvement Roadmap

### Phase 1: Stabilize Core Operations
- Fix visual and state bugs in stay view, booking calendar, booking create/edit, payment modal, room assignment, and check-in/check-out flows.
- Harden the reservation flow end-to-end so create, edit, assign room, multi-room booking, and status updates stay consistent.
- Remove runtime issues and broken bindings in core booking screens.
- Standardize API response handling across frontend modules.
- Define and execute manual test scenarios for booking, room assignment, add-ons, payments, check-in, and check-out.

### Phase 2: Lock Data and Access Control
- Enforce RBAC at route level and backend level, not only in sidebar navigation.
- Remove demo login shortcuts from active production-facing UI.
- Move operational modules that still depend on local frontend store state into persistent API/database flows.
- Harden auth/session behavior for reloads, logout, and expired sessions.
- Add audit trail for important actions such as booking edits, rate changes, payments, and journal updates.

### Phase 3: Strengthen Accounting Integrity
- Make invoice, payment, and outstanding balances derive from stored transactions, not fragile derived booking fields.
- Synchronize operational actions with accounting posting rules.
- Separate operational status from financial status.
- Add validation around payment overages, invoice composition, journal balancing, and report consistency.
- Review owner-facing accounting outputs: occupancy, ADR, revenue mix, receivables, cash flow, profit/loss, and balance sheet.

### Phase 4: Owner Readiness
- Build a concise owner dashboard focused on occupancy, arrivals/departures, unpaid folios, revenue, and cash position.
- Prepare department-specific dashboards for Front Office, Housekeeping, Cashier, and Owner.
- Reduce clicks and improve UX for busy shift workflows.
- Add useful print/export outputs for invoice, folio, arrival/departure lists, cash reports, and journals.

## Current Execution Order
1. Stabilize booking, room, stay view, and payment flow.
2. Tighten auth, role access, and route permission.
3. Migrate remaining local-only modules to API/database persistence.
4. Strengthen invoice, outstanding, payment, and accounting reporting.
5. Refine owner dashboard and final UX polish.

## Current Focus
- Start with priority 1: fix visual and state issues in booking and stay view flows.
