# Recruiter supplier sandbox handoff

## Architecture and isolation

An indexed `users.is_demo_sandbox` boolean identifies the dedicated account independently of its email. The additive migration defaults existing users to false; the marker is not mass assignable. Normal supplier policies and role middleware remain in force. The account's email, password, role, sandbox marker and activation state are protected against Eloquent changes. Account deletion and supplier deactivation are blocked for this account. The profile explains that its shared credentials are fixed.

`Hotel::publicCatalog()` centralizes publication/archive checks and excludes sandbox ownership. Public listings, search filters and destination autocomplete use it. Public details, booking pages and availability routes check the same visibility rule. Booking request validation and `BookingService::create()` independently reject sandbox hotels. The shared account also cannot create bookings on normal hotels. Existing booking/payment transitions, notifications and history restrictions remain intact.

Publishing still completes the existing supplier setup, but for a sandbox hotel it only enables preview. `supplier.hotels.demo-preview` is an authenticated supplier route requiring the existing ownership policy, sandbox ownership, publication and complete setup. It reuses `hotels.show`, hides the booking action and adds a preview notice. Responses have private/no-store and noindex/nofollow headers. Normal public URLs continue to reject the hotel even for its owner.

## Account and credentials

After the migration, run `php artisan demo:supplier-create`. It creates only the dedicated fictional account; it preserves existing credentials/data on repeat execution and refuses conflicting accounts or a second sandbox under another email. It does not run the production portfolio dataset seeders.

- Site: https://bookyourhotelapp.com
- Login: https://bookyourhotelapp.com/login
- Email: `recruiter.supplier@example.test`
- Password: `RecruiterDemo!2026`

These are deliberately public fictional credentials. No production secrets or `.env` values were added to documentation.

## Limits and cleanup

Defaults in `config/demo-supplier.php` are 3 hotels, 8 rooms per hotel, 8 images per hotel or room, and 366 total inventory dates per room. Archived hotels/rooms count toward quotas. Limits are checked while holding database locks, including cumulative inventory writes and the image actions shared by HTTP and Livewire. Hotel facility JSON is bounded for this account. Existing MIME, size, dimensions, 12-megapixel checks, WebP normalization and upload throttles are reused.

`DemoSupplierSandbox` implements limits, transactional room creation and cleanup. `demo:supplier-reset` selects only hotels owned by marked supplier accounts and created at least 4 hours ago. It rechecks ownership and age under locks, includes archived rooms, and skips any hotel with a booking or room booking-item reference. Existing restrictive foreign keys stay enabled. Hotel deletion cascades to rooms, inventory, images and catalog pivots; shared board/facility catalogs remain.

Storage deletion targets only `hotels/{numeric hotel ID}` and `rooms/{numeric room ID}` from the locked records. Image paths in the database cannot expand that scope. Cleanup errors are logged, printed as skips and return a failing exit status. A storage failure rolls back database deletion so cleanup can retry; files already removed in that attempt are not restored by a database rollback.

The scheduler invokes cleanup hourly with `withoutOverlapping()`. With a working scheduler, removal occurs about 4–5 hours after hotel creation, regardless of subsequent edits. The user account and credentials survive. Manual resets apply the same age threshold; no force-delete or all-data option is exposed.

## Files changed

New files:

- `database/migrations/2026_09_22_000001_add_demo_sandbox_to_users.php`
- `config/demo-supplier.php`
- `app/Services/DemoSupplierSandbox.php`
- `app/Console/Commands/DemoSupplierCreate.php`
- `app/Console/Commands/DemoSupplierReset.php`
- `resources/views/components/demo-supplier-notice.blade.php`
- `tests/Feature/DemoSupplierSandboxTest.php`
- `docs/recruiter-sandbox.md`

Updated files:

- `app/Models/User.php`
- `app/Models/Hotel.php`
- `app/Services/HotelSearchService.php`
- `app/Services/BookingService.php`
- `app/Services/InventoryService.php`
- `app/Services/SupplierLifecycleService.php`
- `app/Actions/Hotels/UploadHotelImage.php`
- `app/Actions/Rooms/UploadRoomImage.php`
- `app/Http/Requests/AddHotelRequest.php`
- `app/Http/Requests/CreateBookingRequest.php`
- `app/Http/Controllers/PublicHotelController.php`
- `app/Http/Controllers/DestinationController.php`
- `app/Http/Controllers/Booking/BookingController.php`
- `app/Http/Controllers/Supplier/HotelController.php`
- `app/Http/Controllers/Supplier/HotelSetupController.php`
- `app/Http/Controllers/Supplier/RoomController.php`
- `routes/supplier.php`
- `routes/console.php`
- `resources/views/layouts/app.blade.php`
- `resources/views/hotels/show.blade.php`
- `resources/views/profile/edit.blade.php`
- `resources/views/supplier/hotels/setup/publish.blade.php`
- `resources/views/livewire/supplier/⚡hotel-images-manager.blade.php`
- `resources/views/livewire/supplier/⚡room-images-manager.blade.php`
- `README.md`

The build regenerated ignored local `public/build` assets. No dependency manifests or lockfiles changed.

## Verification

- Focused sandbox suite: 20 passed, 172 assertions.
- Existing supplier lifecycle, MVP integration and readiness suites: 37 passed, 323 assertions; 16 opt-in MySQL lifecycle tests skipped.
- Full `php artisan test`: 158 passed, 2,155 assertions; 26 opt-in MySQL tests skipped.
- `php artisan route:list --except-vendor`: passed, including the preview route (85 application routes).
- `php artisan schedule:list`: passed with a process-local `CACHE_STORE=array` override because the local MySQL server was stopped. Default execution could not connect to the local database cache. `.env` was unchanged.
- `php artisan view:cache`: passed.
- `pnpm.cmd build`: passed after an approved retry outside the sandbox, which initially blocked the esbuild subprocess with EPERM.
- `git diff --check`: passed.

Tests cover provisioning/collision/login, marker protection, search/destination/filter exclusion, public and domain booking rejection, own and foreign supplier management, preview authorization/setup, cumulative quotas, WebP uploads, inventory limits, account/password-reset protection, age boundaries, archived children, storage cleanup/failure retry, idempotence, normal data preservation, preserved history/payments/notifications, corrupt image paths, scheduler registration and ordinary supplier/public behavior.

## Production deployment

No deployment, production migration, production seeding, real external account creation or production `.env` modification was performed.

1. Back up the database and stage this release and its built frontend assets using the existing deployment process.
2. Apply the additive migration before new code serves requests. Use the existing atomic release mechanism or a maintenance window, since public queries now reference the new column: `php artisan migrate --force`.
3. Provision the dedicated account: `php artisan demo:supplier-create`. Do not run `demo:seed` for this feature.
4. Rebuild config, route and view caches with the existing release process; restart existing queue workers.
5. Confirm `php artisan schedule:list` contains the hourly reset. Keep the existing minute scheduler cron; no infrastructure-specific cron change is required.
6. Verify login, setup, publish/preview, public exclusion and quota messages on the deployed release. Monitor reset logs for skipped hotels or storage failures.

Default limits need no `.env` changes. Optional environment overrides are documented in README.

## Risks and assumptions

- Recruiters share one account and can see/edit its sandbox data. Only fictional content belongs there. The banner describes expiry and shared credentials.
- MySQL-specific locking and concurrency suites were not executed because the local MySQL server was stopped. Row locks are in place, but SQLite feature tests cannot prove InnoDB concurrency behavior.
- Filesystem operations cannot participate in database transactions. On a partial filesystem failure the hotel remains for retry, but some temporary images may already be gone.
- Retention depends on the existing scheduler and storage permissions. A history-reference skip intentionally retains data and needs operator investigation; it never bypasses history restrictions.
- Uploaded images use the existing public disk and UUID filenames. Booking/search isolation does not make a known storage image URL authenticated. Livewire temporary uploads retain their existing 24-hour automatic cleanup and throttles.
- Batches that hit an image quota retain successfully uploaded earlier images and show the limit error.
- Do not deploy older code that lacks sandbox exclusion while sandbox hotels remain. Safely expire and remove sandbox hotels first, retaining this isolation code for any hotel skipped due to history references.
