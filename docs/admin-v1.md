# Admin V1 audit and handoff

## Read-only audit (before implementation)

- `/admin/dashboard` was a superadmin-only placeholder, using `SuperMiddleware`; admin users were linked directly to `/bookings`. No dedicated supplier, hotel or user administration existed.
- Both admin roles could list all bookings (15/page, eager-loaded hotel/payment), view snapshots and vouchers, confirm pending reservations, cancel pending/confirmed reservations and complete confirmed stays after checkout. There were no search/status filters or dashboard KPIs. Booking details omitted account identity and guest contact details.
- `BookingPolicy` governs visibility, ownership and actions. `BookingService` reauthorizes under a booking row lock, enforces terminal states, unpaid hold expiry and checkout, restores inventory and invokes `FakePaymentService` refunds in the cancellation transaction. Controllers do not write statuses. CSRF and booking-action throttling already apply.
- **No P0 physical booking deletion was found in normal admin/superadmin UI or routes.** There is no booking DELETE route. `BookingPolicy::delete/forceDelete` deny everyone; the model deleting event throws. Booking-item and payment foreign keys restrict deletion. Cancel changes status and preserves rows. No normal reject/remove/archive booking endpoint exists.
- Hotel/room DELETE resource routes are misleading only at the HTTP-method level: `SupplierLifecycleService` archives them and rejects unresolved bookings. Restrictive historical foreign keys remain intact. Supplier self-service profile deletion deactivates suppliers through that service. Customer account deletion nulls booking ownership and does not delete booking history.
- Superadmin's existing `RoleMiddleware` bypass and `HotelPolicy` allow supplier setup/create/edit/publish/archive across owners. Admin has no such setup permission. Supplier list remains scoped to the signed-in account. No admin role editor, impersonation or account deletion control exists.
- Supplier deactivation had no actor parameter or policy because its sole caller was authenticated self-service. This is a P1 authorization gap to close before adding admin intervention, not a previously exposed arbitrary-user endpoint. Its named `userDeletion` validation bag also needs adaptation for admin feedback.
- Sandbox account credentials/deactivation/deletion are protected; public catalog and booking creation exclude sandbox properties. Maintenance `DemoSupplierSandbox::reset` physically deletes only aged sandbox catalog data after checking all booking references, including archived rooms; it never deletes bookings. Demo catalog maintenance merges catalog entries, not historical booking rows. Neither is an admin UI control.
- No dedicated admin capability tests existed. Existing booking, payment, lifecycle, sandbox and route suites cover many underlying invariants, but not both-role HTTP deletion regressions or the new list/detail/actions.
- No existing booking-list N+1 found; lists were paginated. Missing operational lists must use pagination and relationship counts rather than per-row queries. Existing lifecycle deactivation loads the supplier's properties under locks for an atomic operation; retain that domain behavior.

## V1 decisions

Both admin and superadmin get dashboard, booking search/detail/actions, supplier review/deactivation and hotel review/archive. Existing superadmin supplier setup permissions remain; admin gains only explicit hotel review/archive abilities, not general setup/edit/publish. Shared sandbox suppliers cannot be deactivated and their hotels are read-only in admin review. No generic user editor, status editor, booking deletion, reactivation or publication action is added.

## Delivered capabilities and authorization

| Capability | Admin | Superadmin |
| --- | --- | --- |
| Dashboard and all booking records | Yes | Yes |
| Confirm, cancel, complete via existing lifecycle | Yes | Yes |
| Supplier list/detail and safe deactivation | Yes | Yes |
| Hotel list/detail and safe archival | Yes | Yes |
| Supplier hotel/room setup across owners | No | Existing permissions retained |
| Change sandbox identity or deactivate it | No | No |
| Archive sandbox hotels from admin review | No | No |
| Delete booking/payment history, edit roles, impersonate | No | No |

- Dashboard: total/public hotels, total/active suppliers, total/pending/confirmed/cancelled bookings, five recent bookings. Totals include demo/sandbox records; the public-hotel KPI uses the existing public catalog scope and excludes sandbox properties.
- Bookings: reuse the existing controller and Blade views, 15/page, booking-number search, status/hotel-ID/supplier-ID filters, preserved query strings, account and guest identity/contact, hotel review link, item snapshots/totals, notes, payment status, dates and voucher. Only existing confirm/cancel/complete methods are exposed. Confirmation remains possible for an unpaid reservation before its hold expires, exactly as before; payment is a simulation.
- Suppliers: 15/page, name/email search, active/deactivated and sandbox/non-sandbox filters, hotel/booking counts, details and paginated properties. Demo/sandbox labels are explicit. Deactivation requires a checked confirmation, policy authorization and no pending/confirmed bookings. Existing account-access revocation remains effective.
- Hotels: 15/page, name/city search, published/draft/archived and supplier-ID filters, owner, active-room and booking counts, details and paginated rooms including archived rooms. Supplier/detail pages link to filtered bookings. Publication status on sandbox records is labelled private. No admin publication endpoint exists.
- Navigation: shared dashboard shell, mobile navigation, existing theme, badges, empty states and flash/validation feedback. Booking confirmation checkboxes use native browser validation; supplier/hotel confirmation is additionally validated server-side. Neither replaces policy/service authorization.
- Authorization: all `/admin/*` routes require `auth` and the existing `role:admin` middleware (which admits superadmin). `BookingPolicy`, extended `HotelPolicy` and the new narrowly scoped `UserPolicy` enforce object-level abilities. `SupplierLifecycleService::deactivateSupplier` now requires an actor and authorizes the freshly locked target; the existing self-service caller passes itself. Non-supplier account IDs are rejected by admin endpoints. Cancellation validation moved to a FormRequest. Confirm/complete accept no writable status payload and reauthorize inside `BookingService`.
- Reused services: `BookingService`, `FakePaymentService`, `SupplierLifecycleService`. Reused query/domain rules: `Booking::visibleTo`, `Hotel::publicCatalog`, lifecycle helpers and restrictive foreign keys. No schema, booking lifecycle, inventory/refund algorithm, notifications or email-suppression changes.
- Performance: aggregate dashboard counts, five-row recent-booking limit, eager loading and `withCount`, fixed page sizes and deterministic ID ordering. No full supplier/hotel dataset is loaded to populate filter dropdowns. A regression compares list query counts for one row and eleven rows. Supplier deactivation retains its existing atomic locked operation over all of that supplier's hotels.

## Routes

`admin.dashboard` at `GET /admin/dashboard` now admits both admin roles and uses a controller. Added:

| Method | URI | Route name |
| --- | --- | --- |
| GET | `/admin/bookings` | `admin.bookings.index` |
| GET | `/admin/bookings/{booking}` | `admin.bookings.show` |
| POST | `/admin/bookings/{booking}/confirm` | `admin.bookings.confirm` |
| POST | `/admin/bookings/{booking}/cancel` | `admin.bookings.cancel` |
| POST | `/admin/bookings/{booking}/complete` | `admin.bookings.complete` |
| GET | `/admin/suppliers` | `admin.suppliers.index` |
| GET | `/admin/suppliers/{supplier}` | `admin.suppliers.show` |
| POST | `/admin/suppliers/{supplier}/deactivate` | `admin.suppliers.deactivate` |
| GET | `/admin/hotels` | `admin.hotels.index` |
| GET | `/admin/hotels/{hotel}` | `admin.hotels.show` |
| POST | `/admin/hotels/{hotel}/archive` | `admin.hotels.archive` |

Booking binding still uses booking numbers. Existing `/bookings/*`, supplier and guest routes remain; admin users using the shared booking pages see admin navigation and return to admin details after mutations. All booking mutations retain the existing throttling. There are no admin DELETE routes.

## File inventory

New files:

- `app/Http/Controllers/Admin/DashboardController.php`
- `app/Http/Controllers/Admin/HotelController.php`
- `app/Http/Controllers/Admin/SupplierController.php`
- `app/Http/Requests/Admin/HotelIndexRequest.php`
- `app/Http/Requests/Admin/LifecycleRequest.php`
- `app/Http/Requests/Admin/SupplierIndexRequest.php`
- `app/Http/Requests/BookingIndexRequest.php`
- `app/Http/Requests/CancelBookingRequest.php`
- `app/Policies/UserPolicy.php`
- `resources/views/admin/hotels/_list.blade.php`
- `resources/views/admin/hotels/index.blade.php`
- `resources/views/admin/hotels/show.blade.php`
- `resources/views/admin/suppliers/_identity.blade.php`
- `resources/views/admin/suppliers/index.blade.php`
- `resources/views/admin/suppliers/show.blade.php`
- `resources/views/components/admin-confirmation.blade.php`
- `tests/Feature/AdminTest.php`
- `docs/admin-v1.md`

Modified files:

- `app/Http/Controllers/Booking/BookingManagementController.php`
- `app/Http/Controllers/ProfileController.php`
- `app/Models/Hotel.php`
- `app/Models/User.php`
- `app/Policies/HotelPolicy.php`
- `app/Services/SupplierLifecycleService.php`
- `routes/admin.php`
- `resources/views/admin/dashboard.blade.php`
- `resources/views/booking/index.blade.php`
- `resources/views/booking/manage-content.blade.php`
- `resources/views/booking/manage.blade.php`
- `resources/views/components/layouts/dashboard.blade.php`
- `resources/views/dashboard.blade.php`
- `resources/views/layouts/partials/navigation-links.blade.php`
- `resources/views/layouts/partials/sidebar-admin.blade.php`
- `tests/Feature/MvpIntegrationTest.php` (intentional dashboard access change)
- `tests/Feature/RouteIntegrityTest.php` (exclude FormRequest parameter lookups from named-URL scanning)

## Validation results (local, 2026-10-08)

- New `AdminTest`: **26 passed, 491 assertions** (from the full-suite JUnit report).
- Focused admin/booking management/payment/supplier lifecycle/sandbox/route/integration run before the final two deactivation cases: **101 passed, 1,836 assertions**. Final full-suite execution includes those two additional cases.
- Final `php artisan test --compact --log-junit storage/app/admin-full-junit.xml`: **219 passed, 26 skipped, 3,051 assertions; zero failures/errors**. Skips are the opt-in `BookingManagementConcurrencyTest` and `SupplierLifecycleMySqlTest`; neither opt-in flag was enabled. Existing booking flow/management, payment, supplier lifecycle, sandbox, email verification, guest recovery and route integrity tests passed.
- `node --test tests/Frontend/*.test.mjs`: **27 passed**. The sandbox initially blocked Node child-process creation with `EPERM`; rerunning with the existing approved Node test permission succeeded. No application JS was changed.
- `pnpm.cmd build`: passed. `public/build` was regenerated (git-ignored), including `manifest.json`, `assets/app-CGN6Db1f.css` and `assets/app-DMsN-rLE.js`. Ship this generated directory explicitly. No CSS/JS source or dependency files changed.
- `php artisan route:list --except-vendor` and cached `php artisan route:list --path=admin -v`: passed; 12 admin routes with auth/role middleware, and booking throttling on the three booking mutations.
- `php artisan route:cache`, `php artisan view:cache`, `git diff --check`: passed. Local route/view caches were then cleared to restore the pre-validation development state.
- New PHP files were formatted with Pint. No production connection, deployment, production migration, production seed or `.env` modification was performed. Database tests used the configured in-memory SQLite database; no claim is made about newly executed MySQL contention tests or browser screenshot inspection.

## Deployment handoff — instructions only, not executed

No migrations, seeds, dependency updates or environment changes are required for this change. `public/build` is git-ignored and was regenerated locally; include the generated manifest and assets in the release artifact. No production command was run.

1. Package the reviewed application changes and generated `public/build` using the project's existing release process. Preserve the production `.env`, persistent `storage`, storage link and existing database. Do not copy local test output or development caches.
2. For an in-place release, run `php artisan down` from the current production application root before replacing application files. Upload the reviewed files and complete built-assets directory. With atomic releases, use the existing equivalent release switch instead.
3. From the updated production application root run:

   ```bash
   composer dump-autoload --optimize --no-dev
   php artisan route:cache
   php artisan view:cache
   php artisan queue:restart
   php artisan up
   ```

   No `migrate`, `db:seed`, `demo:seed`, `demo:supplier-create`, `.env` edit or new admin-account provisioning is part of this release. Configuration has not changed. Keep the existing scheduler and worker supervision.
4. Using existing authorized staff accounts, check the admin dashboard and read-only list/detail pages. Use only fictional test records for lifecycle smoke checks. Rollback can restore the preceding application/build artifact and regenerate route/view caches; legitimate completed lifecycle actions remain recorded in the database.

## Recommended manual acceptance

- Check both roles on desktop and a narrow mobile viewport in light/dark themes, including navigation, filter retention, confirmation checkboxes and validation feedback.
- Check a fictional paid reservation cancellation: one inventory release, one simulated refund and a still-readable voucher. Confirm that a terminal reservation exposes no further lifecycle actions.
- Attempt retirement while an active booking exists; verify clear feedback and unchanged data. Then check safe retirement of an eligible fictional hotel/supplier.
- Verify sandbox labels, disabled admin retirement, private publication and public-search exclusion; verify customer/supplier admin requests receive 403.
- HTTP rendering and regression tests cover the screens; real-browser visual acceptance and real MySQL lock/concurrency testing are separate checks. The opt-in disposable MySQL suites were not enabled during this task.

## Deliberately deferred

User/role administration, impersonation, generic status editing, reject transitions without an existing domain operation, booking deletion, reactivation/restore, bulk operations, audit-log platform, disputes/tickets, analytics/charts, CMS, real payments and date-range filters. No unrelated customer/supplier redesign was introduced.
