# DOMAIN.md — Rental Manager business rules

Source of truth for terms, states, rules and invariants. Code must follow this file.
If code needs a rule that is not here, add a question to `docs/QUESTIONS.md` instead of inventing it.

## 1. Glossary

| Term (code) | Arabic (UI) | Meaning |
| --- | --- | --- |
| Account | الحساب | A SaaS customer (one owner's business). Never called "tenant" in code. |
| User | المستخدم | A platform identity (WordPress user). Can belong to many accounts. |
| Membership | العضوية | `(user, account, role, status)`. Grants access to one account. |
| Role | الدور | `owner`, `manager`, `renter` (MVP). `accountant` reserved, not exposed. |
| Renter | المستأجر | A party renting a unit, scoped to one account. May or may not have a portal user. |
| Party type | نوع الطرف | `individual` or `company`. |
| Unit | الوحدة | Apartment, villa, chalet, shop, office, other. |
| Contract | العقد | Links one unit and one renter for a date range with financial terms. |
| Amendment | ملحق العقد | A dated change to contract terms (e.g. rent change). |
| Charge | الاستحقاق / المستحق | Money the renter owes: rent, deposit, utility, maintenance, late fee, other. |
| Payment submission | إثبات دفع | Renter's claim "I paid", with proof. **Not money.** |
| Transaction | معاملة / دفعة | Money confirmed received (in) or paid back (out). |
| Allocation | تخصيص | Part of a transaction applied to a charge. |
| Credit | رصيد دائن | Unallocated part of incoming transactions (derived). |
| Receipt | إيصال | Document issued for one incoming payment transaction. |
| Reversal | عكس | A new transaction cancelling a previous one. |
| Void | إلغاء إيصال | Receipt state after its transaction is reversed. |
| Waiver | إعفاء | Owner cancels a charge's remaining amount on purpose. |
| Deposit settlement | تسوية التأمين | End-of-contract calculation: deductions and refund of the held deposit. |
| Expense | مصروف | Cost recorded against a unit. |
| Maintenance request | طلب صيانة | A repair request with status updates. |
| Access mode | وضع الوصول | `full`, `read_only`, `blocked` — derived from subscription + admin status. |
| Entitlement | صلاحية الخطة | Plan feature/limit key, e.g. `units.max`. |
| Override | استثناء | Per-account entitlement value that **replaces** the plan value. |
| Support grant | وصول الدعم | Time-boxed, owner-approved access for platform support staff. |

## 2. Roles and permissions (static map in code)

| Permission | owner | manager | renter (own scope only) |
| --- | --- | --- | --- |
| units.view / units.manage | ✓ / ✓ | ✓ / ✓ | own unit view |
| renters.view / renters.manage | ✓ / ✓ | ✓ / ✓ | own profile view |
| renters.sensitive.view (raw ID, ID images) | ✓ | ✓ | own |
| contracts.view / contracts.manage | ✓ / ✓ | ✓ / ✓ | own view |
| charges.view / charges.manage (add, waive) | ✓ / ✓ | ✓ / ✓ | own view |
| payments.view | ✓ | ✓ | own |
| payments.submit | — | — | ✓ |
| payments.approve (approve, reject, clarify, manual record) | ✓ | ✓ | — |
| payments.reverse (reverse transaction, void receipt) | ✓ | — | — |
| receipts.view | ✓ | ✓ | own |
| deposits.manage (settlement) | ✓ | ✓ | — |
| expenses.view / expenses.manage | ✓ / ✓ | ✓ / ✓ | — |
| maintenance.view / maintenance.manage | ✓ / ✓ | ✓ / ✓ | own view + create |
| documents.view / documents.manage | ✓ / ✓ | ✓ / ✓ | own, non-internal |
| reports.view | ✓ | ✓ | — |
| account.users.manage | ✓ | — | — |
| account.settings.manage | ✓ | — | — |
| account.export | ✓ | — | — |
| account.support.approve | ✓ | — | — |

Rules: a user cannot grant a role higher than their own, cannot change their own role,
and the last active owner cannot be removed or demoted.

## 3. Access mode

| Subscription status | Account admin status | Access mode |
| --- | --- | --- |
| trial, active | active | full |
| past_due, within grace days | active | full (+ warning banner) |
| past_due after grace, expired, cancelled | active | read_only |
| any | suspended, closed | blocked |

- `read_only`: all GET allowed; all writes rejected (409 `account_read_only`) **except** owner data export.
- `blocked`: owner/manager get 403 `account_blocked` with a support message; **renters keep read access to their own receipts and statement**.
- Grace days and messages are platform settings, not constants.

## 4. Identity and portal access

- A WP user can hold memberships in many accounts; the SPA shows an account switcher.
- A `Renter` record exists without any login. `renters.portal_user_id` is set when an invitation is accepted.
- Invitations: random token (≥128 bit), stored as hash, single use, expires in 7 days, carry role and optional `renter_id`.
- Renters without email: login identifier is the mobile number. Implementation: the WP user gets a non-routable
  placeholder email `u-<ulid>@users.invalid`; login form accepts mobile or email; the plugin maps mobile → user.
  Invitation is delivered by the owner (link/code shown to copy and send via WhatsApp/SMS manually). OTP is post-MVP.
- Removing a membership takes effect on the next request (membership is checked every request) and destroys that user's sessions if they have no other memberships.

## 5. Units

- Stored flags: `availability_flag` ∈ {`available`, `maintenance`, `inactive`}; `archived_at`.
- Displayed status is derived: `rented` if an active contract covers today (account timezone), else the flag.
- `reserved` = an active contract whose start_date is in the future (derived).
- A unit with an active contract cannot be archived.
- Creating a unit checks entitlement `units.max` (locks the account usage row). Existing units stay editable after downgrade.

## 6. Contracts (Leasing)

### Stored status
`draft → active → ended`, `active → terminated`, `draft → discarded`.

| Transition | Rule |
| --- | --- |
| draft → active (activate) | unit + renter set; no overlap; terms valid; generates charges atomically (via Billing Api) |
| active → terminated | owner sets `termination_date` (≥ start_date); future charges with `period_start > termination_date` and unpaid → `cancelled`; last partial period prorated; deposit untouched |
| active → ended (close) | allowed when `end_date < today`; does not touch money |
| draft → discarded | no charges exist |

Derived (never stored): `upcoming` (active, start > today), `current`, `expiring` (current, end_date − today ≤ 60 days),
`expired` (active, today > end_date, not closed), `renewed` (another contract has `renews_contract_id` = this).

### Overlap
Two contracts on the same unit overlap if both are `active` and their `[start_date, effective_end]` ranges intersect
(`effective_end` = termination_date or end_date, inclusive). Enforced by locking the unit row `FOR UPDATE` then checking, inside the activation transaction.

### Renewal
Creates a **new** draft contract with `renews_contract_id`; the old contract is never edited.
The new contract may reuse the held deposit (deposit carried over — no new deposit charge, a `deposit_transfer` record links them).

### Amendment
`effective_date` + new rent (MVP: rent amount only). On apply: unpaid charges of type rent with `period_start ≥ effective_date` → `cancelled`; new charges generated for those periods. Partially paid charges in that range: block the amendment with 422 `amendment_paid_period` (owner must reverse first).

### Existing contract onboarding (opening balance)
Contract may be created with `tracking_start_date` > `start_date` ("contract started before registration").
- Charges are generated only for periods with `period_start ≥ tracking_start_date`.
- Opening arrears: one or more charges of type `opening_balance` with a description and due date.
- Deposit already held: a transaction `kind = opening_deposit` allocated to a `deposit` charge, no receipt issued.
- Opening renter credit: a transaction `kind = opening_credit`, unallocated, no receipt.

## 7. Schedule generation

- Frequency: monthly (1), quarterly (3), annual (12) months.
- Periods are anchored on `start_date`: period n starts at `start_date + n×freq months` (day clamped to month length, e.g. 31 → 28/29/30),
  ends the day before the next period start, last period ends at `end_date` (inclusive).
- `due_date` = period_start by default, or the contract's `due_day` within the period's start month (clamped) if set.
- A last period shorter than a full period is prorated: `amount × days_in_partial / days_in_full_period`, rounded with the account rounding rule.
- Annual increase (`none` | `percent` | `fixed`): applied from each anniversary of `start_date`, compounding on the previous year's rent, rounded per account rule.
- A `deposit` charge is generated on activation with `due_date = start_date` (unless carried over or opening deposit).
- Uniqueness: `(account_id, contract_id, type, period_start)` prevents double generation.

## 8. Charges

Stored status: `open`, `settled`, `waived`, `cancelled`.
- `settled` is set inside the same transaction when net allocations = amount; set back to `open` when a reversal reduces them.
- Derived: `paid_amount` (sum of non-reversed allocations; may be cached in `paid_amount_cache` updated in the same transaction),
  `remaining = amount − paid`, `partially_paid`, `overdue` (open and today > due_date + grace_days), `due` (today in [due_date, due_date+grace]), `upcoming`.
- `has_pending_submission` is a derived flag for the UI. **Pending verification is never a charge status.**
- Waive: only the remaining amount; requires reason; audit. Cannot waive `deposit` charges.
- Cancel: only charges with zero allocations (used by termination/amendment).

## 9. Payment submissions (renter "I paid")

States: `pending → approved | rejected | clarification_requested`; `clarification_requested → pending` (renter edits & resubmits);
`pending | clarification_requested → withdrawn` (renter).
- Fields: claimed_amount, paid_on, method (`instapay`, `bank_transfer`, `wallet`, `cash`, `other`), reference, proof document(s), note,
  intended charges (`rm_submission_items`, intention only).
- Every action appends `rm_submission_events` (who, action, reason_code, note, snapshot of changed fields). No silent edits.
- Reject reason codes: `amount_mismatch`, `not_received`, `invalid_proof`, `wrong_period`, `duplicate`, `other` (+ note required for `other`).
- Duplicate warning (not a block): same account + same reference + same amount + same method as any approved transaction or other pending submission.
- **Approve** (one Unit of Work, idempotent):
  1. conditional update `status='pending' → 'approved'` (`affected_rows` must be 1, else 409 `submission_not_pending`);
  2. create transaction (`kind=payment`, `source_submission_id` UNIQUE) with **confirmed** amount and `received_on` chosen by approver;
  3. lock target charges (ascending id), create allocations (approver's final split; default FIFO by due_date), enforce invariants;
  4. update charge statuses; 5. issue receipt (sequence, snapshot); 6. audit; 7. outbox events.
- Reject and clarify create **no** financial rows.

## 10. Transactions, allocations, credit

- `direction`: `in` | `out`. `kind`: `payment`, `refund`, `reversal`, `deposit_application`, `opening_deposit`, `opening_credit`.
- Manual owner payment = transaction `kind=payment` without submission + allocations + receipt (same use case core as approve).
- Invariants (checked under lock, covered by tests):
  - Σ allocations of a transaction ≤ transaction.amount
  - Σ net allocations of a charge ≤ charge.amount
  - allocation amount > 0; no allocation to `waived`/`cancelled` charges; currency of allocation = charge = transaction.
- Credit of a renter = Σ(in transactions not reversed) − Σ(their allocations) − Σ(out refunds of credit). Derived.
- Credit can later be allocated to a charge (new allocation row on the original transaction) or refunded (`out`, `kind=refund`).

## 11. Reversal and receipts

- Reverse transaction T: T.status `posted → reversed`; new transaction `kind=reversal`, `reverses_transaction_id = T` (UNIQUE);
  T's allocations marked `reversed_at`; affected charges recomputed (`settled → open`); T's receipt → `voided` with reason, user, time. Reason required. Owner only.
- Receipt: one per incoming `payment` transaction (`UNIQUE transaction_id`). Number from `rm_sequences`, per account, per year:
  `{prefix}{YYYY}-{000001}`, allocated in the same transaction (no gaps on rollback). Never reused.
- Receipt stores an immutable `snapshot` JSON (issuer display name, renter name, unit, contract ref, lines with periods and amounts,
  method, reference, received_on, approved_at, approver). PDF is rendered from the snapshot only.
- To correct receipt data: reverse + record again (new number); new receipt references the voided one.
- Receipt text: "This receipt is not a tax invoice." (Arabic + English).

## 12. Deposits

- Deposit is a **liability**, never income. Collected through a `deposit` charge.
- Held deposit (per contract) = allocations to its deposit charges − applications − refunds. Derived.
- Settlement (one per contract): `draft → finalized`; finalized is immutable (correction = reverse its transactions and create a new settlement).
  Lines: `damage` (optional link to expense), `unpaid_charge` (link to a charge → creates `deposit_application` transaction + allocation to that charge), `other`.
  Refund = held − Σ lines; if > 0 → transaction `direction=out, kind=refund`. If < 0 → remaining stays as open charges; settlement shows the shortfall.
- Settlement does not require the contract to be ended, but warns if the contract is still current.

## 13. Late fees

- Contract terms: `late_fee_type` (`none`|`fixed`|`percent`), `late_fee_value`.
- Daily job: for each open rent charge where today > due_date + grace_days and no late fee exists for `(charge_id, stage=1)` → create charge `type=late_fee`.
- Unique `(account_id, source_charge_id, late_fee_stage)`. MVP: one stage only. Waivable.

## 14. Expenses and maintenance

- Expense: unit, spent_on, category (fixed list), amount, supplier, method, attachment, note, `responsibility` (`owner`|`renter`|`shared`).
  `renter` responsibility with an active contract → creates a `type=maintenance` or `type=utility` charge via Billing Api (`charge_id` stored). Shared = owner records only the owner part (MVP).
- Maintenance states: `open → assigned → in_progress → resolved → closed`; `open|assigned → cancelled`. Renter can create and view own; owner manages.
  Resolving with a cost creates an expense through Expenses Api (no duplicate cost fields kept in Maintenance beyond the link).

## 15. Documents

- Types: `contract`, `renter_id`, `unit_image`, `payment_proof`, `receipt_pdf`, `maintenance_photo`, `expense_invoice`, `other`.
- `sensitivity`: `normal` | `sensitive` (renter_id, payment_proof). Sensitive downloads are audited.
- Two-step: upload → `pending`; attach to owner entity → `attached`. Pending older than 24h are deleted by a job.
- Renters see only documents attached to their own entities and not marked `internal`.

## 16. Subscriptions and entitlements

- Entitlement keys (MVP): `units.max`, `users.max` (owners+managers, renters excluded), `storage.mb`, `reports.advanced`, `receipts.qr`.
- Effective value = active, non-expired override if any, else plan-version value. **Override replaces, never adds.**
- Limits are checked on create only. Usage computed from source counts (except storage, kept as a counter).
- Subscription changes, extensions and overrides are platform-admin actions, always audited with reason.
- MVP has no payment gateway: admin extends subscriptions manually.

## 17. Support access

- Platform admin requests a grant: scope (`read_only` | `read_write`), duration (≤72h), reason/ticket. Account owner approves or rejects.
- While active, admin acts through `X-RM-Account` with `actorType=support`; UI shows a permanent "support mode" banner;
  audit rows store `actor_user_id` = admin and `on_behalf_of_account`. Sensitive documents are never available in `read_only`.
- Owner can revoke any time; grants expire automatically.

## 18. Audit vs Activity

- `rm_audit_log`: append-only security/audit record (actor, action, entity, before/after diff, request_id, IP). Never updated or deleted by the app.
- `rm_activity`: user-facing timeline built from outbox events ("Contract renewed", "Payment 6,000 received"). Can be rebuilt. May be shown to renters (filtered).

## 19. Notifications (MVP)

In-app for all events below; email only for invitations, password reset, payment approved/rejected/clarification, rent overdue (owner digest).
Events: rent due soon (3 days), due today, overdue, submission created, approved, rejected, clarification requested,
contract expiring (60/30 days), contract expired, maintenance created/updated, deposit settlement finalized, subscription ending.
Dedupe key per (user, event, entity, date).

## 20. Database tables (owner module in brackets)

All tenant tables: `id BIGINT PK`, `public_id CHAR(26) UNIQUE` (where exposed), `account_id`, `UNIQUE(account_id,id)`, `created_at`, `updated_at`, InnoDB.

- [Platform] `rm_accounts`(admin_status, name), `rm_plans`, `rm_plan_versions`, `rm_plan_entitlements`, `rm_entitlement_definitions`,
  `rm_subscriptions`(account_id, plan_version_id, status, trial_ends_at, current_period_end), `rm_account_overrides`(key, value, reason, granted_by, expires_at), `rm_account_usage`(account_id PK, storage_bytes)
- [Settings] `rm_account_settings`(account_id PK, timezone, currency, locale, receipt_prefix, display_name, rounding_mode, rounding_unit, default_grace_days)
- [Identity] `rm_memberships`(UNIQUE account_id,user_id), `rm_invitations`, `rm_support_grants`, `rm_login_identifiers`(user_id, type mobile|email, value UNIQUE)
- [Units] `rm_units`
- [Renters] `rm_renters`(party_type, full_name, mobile, email, id_number_enc, id_number_last4, employer, emergency_contact JSON, portal_user_id NULL)
- [Leasing] `rm_contracts`(unit_id, renter_id, status, start_date, end_date, tracking_start_date, termination_date, rent_amount, currency, frequency, due_day NULL, grace_days, late_fee_type, late_fee_value, increase_type, increase_value, deposit_amount, renews_contract_id NULL, terms TEXT), `rm_contract_amendments`
- [Billing] `rm_charges`, `rm_payment_submissions`, `rm_submission_items`, `rm_submission_events`, `rm_transactions`, `rm_allocations`, `rm_receipts`, `rm_deposit_settlements`, `rm_deposit_settlement_lines`, `rm_deposit_transfers`, `rm_idempotency_keys`
- [Expenses] `rm_expenses`  [Maintenance] `rm_maintenance_requests`, `rm_maintenance_updates`
- [Documents] `rm_documents`  [Notifications] `rm_notifications`  [Activity] `rm_activity`
- [Kernel] `rm_migrations`, `rm_outbox`, `rm_sequences`, `rm_audit_log`, `rm_rate_limits`

Key constraints and indexes:
- `rm_charges`: UNIQUE `(account_id, contract_id, type, period_start)` for generated charges; UNIQUE `(account_id, source_charge_id, late_fee_stage)`;
  idx `(account_id, status, due_date)`, `(account_id, renter_id, due_date)`, `(account_id, contract_id)`.
- `rm_transactions`: UNIQUE `source_submission_id`, UNIQUE `reverses_transaction_id`; idx `(account_id, renter_id, received_on)`.
- `rm_allocations`: idx `(account_id, charge_id)`, `(account_id, transaction_id)`.
- `rm_receipts`: UNIQUE `transaction_id`, UNIQUE `(account_id, receipt_number)`.
- `rm_contracts`: idx `(account_id, unit_id, status)`.
- `rm_audit_log`: idx `(account_id, created_at)`, `(account_id, entity_type, entity_id)`.
- `rm_notifications`: UNIQUE `(account_id, user_id, dedupe_key)`; idx `(user_id, account_id, read_at, created_at)`.
- No foreign keys to WordPress core tables.
