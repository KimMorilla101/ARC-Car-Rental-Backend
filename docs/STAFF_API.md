# ARC Car Rental — Staff API

Reference for clients that manage ARC's data, such as the Admin Website. The renter-facing contract
used by the mobile app lives in the mobile repository (`docs/API_CONTRACT.md`).

Status: implemented in the backend for vehicles (Phase 2), booking reference data (Phase 3a),
bookings with document review (Phase 3b) and staff booking actions (Phase 3c). The Phase 3
database tables exist. Returns, extensions and notifications arrive in Phase 5.

## Conventions

| Topic | Rule |
|---|---|
| Base URL | `APP_URL` + `/api`, e.g. `http://192.168.100.112:8000/api` locally. Production must use HTTPS. |
| Sign in | `POST /api/auth/login` with `{ email, password, device_name }` returns `{ token, user }`. Use `device_name` such as `"ARC Admin Website"`. |
| Auth header | `Authorization: Bearer <token>` and `Accept: application/json` on every request. Tokens expire after 30 days. |
| Who am I | `GET /api/user` returns `{ data: { id, name, email, role, ... } }`. `role` is `renter`, `staff` or `admin`. |
| JSON | snake_case keys. Single records and lists are wrapped in `{ "data": ... }`. |
| Money | Numbers in pesos with at most 2 decimals, e.g. `2500` or `350.50`. |
| Dates | ISO-8601 with the Philippine offset, e.g. `2026-10-27T10:30:00+08:00`. Stored in UTC. |
| Errors | `401` not signed in, `403` role not allowed, `404` not found, `422` validation (`{ message, errors: { field: [..] } }`), `429` throttled. |
| Deleting | Branches, zones and payment options are deactivated with `is_active: false`, never deleted. Vehicles are soft-deleted by admins only. Published agreements are never edited. |

## Roles

Roles cannot be changed through the API. A server operator runs
`php artisan arc:set-role <email> <renter|staff|admin>` on an existing account.

Current state: **no staff or admin account exists yet.** Every account is a renter, so every
`/api/staff` route answers `403` until ARC designates an administrator. This is intentional.

Guidance for assigning roles:

- Give staff and admin roles to **dedicated work accounts** (registered through the normal sign-up),
  not to someone's everyday renter account.
- A role change takes effect **immediately on that account's existing login tokens**, including
  phones already signed in. Sign out other devices after promoting or demoting an account.
- Role changes are not yet recorded in an audit log; keep a note of who changed what and when.

| Area | staff | admin |
|---|---|---|
| Vehicles, units, photos (create, edit) | yes | yes |
| Delete a vehicle | no | yes |
| Branches, delivery zones | yes | yes |
| Rental agreement drafts (create, edit, list) | yes | yes |
| Publish a rental agreement | no | yes |
| Payment options (list, create, edit) | no | yes |
| Business settings | read only | read and update |
| Bookings: list, view, review documents and payment, confirm, activate, cancel | yes | yes |

## Vehicles

A vehicle is a model (e.g. "Honda BR-V 2024"); each physical car is a unit. Renters only see vehicles
with `status: "active"` and all three rates set.

| Method | Path | Body / notes |
|---|---|---|
| GET | `/staff/vehicles` | All vehicles, including drafts, with `units` and `photos`. |
| GET | `/staff/vehicles/{id}` | One vehicle. |
| POST | `/staff/vehicles` | `name`, `category` (Sedan, SUV, MPV, Pickup, Luxury, Hatchback), `transmission` (Automatic, Manual), `fuel` (Gasoline, Diesel, Hybrid, Electric), `seats`, optional `doors`, `description`, `features[]`, `hourly_rate`, `daily_rate`, `monthly_rate`, `is_popular`, `status` (draft, active, inactive). Returns `201`. |
| PUT/PATCH | `/staff/vehicles/{id}` | Any of the fields above. `status: "active"` requires all three rates (422 otherwise). |
| DELETE | `/staff/vehicles/{id}` | Admin only. Soft delete; returns `204`. |
| POST | `/staff/vehicles/{id}/units` | `label`, `plate_number` (unique, stored uppercase), optional `status` (available, maintenance, retired). Returns `201`. |
| PATCH | `/staff/units/{id}` | `label`, `plate_number`, `status`. Units are retired, not deleted. |
| POST | `/staff/vehicles/{id}/photos` | `multipart/form-data` field `photo`: JPEG or PNG, max 5 MB, 200–4000 px. HEIC is rejected. Metadata is stripped. Max 10 per vehicle. Returns `201` with the vehicle. |
| DELETE | `/staff/photos/{id}` | Removes the photo and its file. Returns `204`. |

## Branches

| Method | Path | Body |
|---|---|---|
| GET | `/staff/branches` | Includes inactive branches. |
| POST | `/staff/branches` | `name` (unique, max 120), `address` (max 255), optional `is_active`. Returns `201`. |
| PUT/PATCH | `/staff/branches/{id}` | Any of the fields above. |

Response: `{ "data": { "id": 1, "name": "...", "address": "...", "is_active": true } }`

## Delivery zones

| Method | Path | Body |
|---|---|---|
| GET | `/staff/delivery-zones` | Includes inactive zones. |
| POST | `/staff/delivery-zones` | `name` (unique, max 120), `fee` (required, ≥ 0, 2 decimals), optional `is_active`. Returns `201`. |
| PUT/PATCH | `/staff/delivery-zones/{id}` | Any of the fields above. |

Response: `{ "data": { "id": 1, "name": "...", "fee": 300, "is_active": true } }`

## Payment options (admin only)

One option per method. These accounts are shown to renters as where to send money.

| Method | Path | Body |
|---|---|---|
| GET | `/staff/payment-options` | Includes inactive options. |
| POST | `/staff/payment-options` | `method` (online, bank_transfer, cash; unique), `label` (max 60), `description` (optional, max 500), `requires_proof` (boolean), `accounts` (array, may be empty for cash), optional `is_active`, `sort_order`. Returns `201`. |
| PUT/PATCH | `/staff/payment-options/{id}` | Any field except `method`, which cannot change. |

Each account: `{ "provider": "BPI", "account_name": "...", "account_number": "..." }`.
An active option other than cash must have at least one account.

## Rental agreements

Staff and admins write drafts; only admins publish. Renters always receive the most recently
published version. A published version cannot be edited: publish a new version instead, so every
booking keeps the exact text the renter accepted.

| Method | Path | Body |
|---|---|---|
| GET | `/staff/rental-agreements` | Drafts and published versions. |
| POST | `/staff/rental-agreements` | `version` (unique; letters, numbers, `.`, `-`, `_`), `title`, `sections` (1–50 of `{ title, body }`). Creates a draft; returns `201`. |
| PUT/PATCH | `/staff/rental-agreements/{id}` | Drafts only; a published version returns `422`. |
| POST | `/staff/rental-agreements/{id}/publish` | Admin only. Returns `422` if already published. |

Response: `{ "data": { "id", "is_published", "published_at", "created_at", "version", "title", "sections" } }`

## Business settings

| Method | Path | Body |
|---|---|---|
| GET | `/staff/settings` | Staff and admin. |
| PATCH | `/staff/settings` | Admin only. Send only the keys to change. Unknown keys are rejected. |

| Key | Default | Rule |
|---|---|---|
| `car_wash_fee` | `null` (not configured) | ≥ 0, 2 decimals, or `null` to clear. Pricing is blocked while it is `null`. |
| `down_payment` | `1000` (confirmed by ARC) | Required number ≥ 0. |
| `turnaround_buffer_minutes` | `0` | Whole minutes between two rentals of the same car, 0–10080. |

Response: `{ "data": { "car_wash_fee": null, "down_payment": 1000, "turnaround_buffer_minutes": 0 } }`

## Bookings

Renters create bookings from the mobile app; the server prices them, assigns one free physical unit
and starts them as `pending`. Staff then review the documents and payment and move the booking on.

### Status flow

```
pending ──(renter submits all 4 documents + payment proof if required)──▶ pending_verification
pending_verification ──(staff reject a document or the payment)──▶ pending
pending_verification ──confirm──▶ confirmed ──activate──▶ active
pending / pending_verification / confirmed ──cancel──▶ cancelled
```

- `return_due` is never stored: an `active` booking is reported as `return_due` once its return time
  passes (`stored_status` shows the stored value). Returns and extensions are Phase 5.
- **Confirm** requires: status `pending_verification`; all four documents `verified`; the payment
  `verified` when the payment method requires proof; the vehicle still active and not deleted; the
  assigned unit `available` with no other overlapping reservation.
- **Activate** requires: status `confirmed`; the return time not yet passed; the same vehicle and unit
  checks as confirm.
- **Cancel** requires a `reason` and status `pending`, `pending_verification` or `confirmed`. Active
  rentals cannot be cancelled. A cancelled booking no longer reserves its unit.
- Every action re-checks the rules inside a database transaction with the booking and unit locked,
  and records who made the change.

### Endpoints

| Method | Path | Body / notes |
|---|---|---|
| GET | `/staff/bookings` | Optional `?status=` (any stored status). Newest first. |
| GET | `/staff/bookings/{id}` | One booking with renter, unit, document links and review details. |
| PATCH | `/staff/bookings/{id}/requirements/{type}` | `type` = `drivers_license`, `valid_id`, `proof_of_billing` or `down_payment`. Body `{ status: "verified" \| "rejected", rejection_reason }` (reason required when rejecting). Only a document waiting for review can be reviewed. |
| PATCH | `/staff/bookings/{id}/payment` | Same body. Reviews the latest payment proof. Only for payment methods that require proof. |
| POST | `/staff/bookings/{id}/confirm` | No body. |
| POST | `/staff/bookings/{id}/activate` | No body. |
| POST | `/staff/bookings/{id}/cancel` | `{ reason }` (max 500). |

Errors from a rule above return `422` with the explanation in `errors.status`.

### Private documents

Documents and payment proofs are stored on the private disk, never under `/storage`. Download them
with the bearer token (the booking's renter or staff only; anyone else gets `404`):

- `GET /api/bookings/{id}/requirements/{type}/file`
- `GET /api/bookings/{id}/payment-proof/file` (latest proof)

The staff booking resource includes these links as `requirements[].file_url` and
`payment.proof_url`. Responses are sent with `Cache-Control: private, no-store`.

## What stays blocked until ARC configures it

Renters cannot get a quote (`POST /api/bookings/quote`) or book (`POST /api/bookings`) until an
admin has entered real values. Nothing is filled in automatically.

| Missing | Effect | Who fixes it |
|---|---|---|
| Car wash fee (`car_wash_fee` is `null`) | Every quote and booking returns `422` (`errors.pricing`). | Admin: `PATCH /staff/settings` |
| Active branch or delivery zone | Quote and booking fail validation on `branch_id` / `delivery_zone_id`. | Staff or admin |
| Published rental agreement | Booking returns `422` (`errors.agreement_version`); `GET /api/rental-agreement` returns `404`. | Staff drafts, admin publishes |
| Active payment option | Booking returns `422` (`errors.payment_method`). | Admin |
| Active, fully priced vehicle with an available unit | Not listed to renters; quote/booking returns `422` (`errors.vehicle_id`) or `available: false`. | Staff or admin |
| Partial-month pricing rule (31–59 days, etc.) | Rentals of 30+ days that are not whole 30-day months return `422` (`errors.return_at`). | ARC business decision, then a code change |

## Timezone assumptions

- The database stores all timestamps in UTC (`config/app.php` `timezone = UTC`).
- API responses convert dates to `Asia/Manila` (`config/app.php` `api_timezone`), which has no
  daylight-saving time, so the offset is always `+08:00`.
- Booking and availability dates (`pickup_at`, `return_at`) must include a timezone (`Z` or an
  offset such as `+08:00`); dates without one are rejected with `422`. Any offset is accepted and
  converted to UTC for storage.

## Browser clients (CORS)

A browser-based admin site on a different origin needs that origin allowed in the backend's CORS
settings. In production, allow only the real admin domain.
