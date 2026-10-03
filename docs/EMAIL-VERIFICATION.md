# Email ownership before booking

Implemented and validated locally on 2026-10-03. Nothing was deployed; production configuration, database, seeders, SMTP settings, and queue configuration were not modified.

## Audit and registered accounts

Breeze already supplied registration's `Registered` event, authenticated verification notice/resend routes, signed verification links, a notice screen, and a six-per-minute resend throttle. Profile email changes already cleared `email_verified_at`. The dashboard already used `verified` middleware. However, `User` did not implement `MustVerifyEmail`, so native automatic delivery and the dashboard verification gate were inactive. Booking submission had no verification gate. Existing verification tests exercised the screen and signed link but did not cover registration delivery or booking restrictions.

`User` now implements the native contract. Registration normalizes email and continues to authenticate the new user; the dashboard sends them to the existing notice. Booking middleware redirects browser requests there, or returns HTTP 403 with a verification URL for JSON requests. The booking UI follows that URL. `BookingService` also rejects unverified authenticated users before any transaction. Verified users retain their existing booking flow.

Existing normal accounts with a null verification timestamp must verify before making another booking. No bulk verification or unsolicited migration-time mail is performed. As before, the authenticated booking form may contain a guest contact email different from the account email: the registered-user requirement verifies the account's ownership, not that separate contact address.

## Guest architecture and transaction boundary

One `guest_booking_challenges` row per browser session stores a session-ID HMAC, rotating random UUID token, normalized email, encrypted validated booking payload, hashed OTP, expiry, attempt counter, and resend deadline. The database row survives page reloads without relying on client booking fields. There is no temporary Booking, account creation, or inventory hold.

Initial submission validates the FormRequest and calls a read-only `BookingService::validateIntent()` that reuses the service's date, room, capacity, board, and availability checks. It never materializes inventory. The existing price-calculation routine is reused for validation; client prices are never authoritative.

Verification locks the challenge row, validates the token/session/code/expiry/attempt count, reruns the booking request rules, and calls the existing `BookingService::create()`. That service still takes the hotel and inventory locks, checks availability after locking, calculates prices, creates booking/items, and decreases inventory. Deleting the challenge and creating the booking commit in the same outer transaction. Replay cannot produce another booking. A creation failure rolls back all booking/inventory changes and keeps the intent available to edit or retry while its code remains valid. Booking lifecycle events retain their existing after-commit behavior.

The existing signed checkout, management, voucher, payment, cancellation, and recovery URLs are unchanged. No account is created for a guest.

## OTP and abuse controls

- Six digits from `random_int(100000, 999999)`; Laravel `Hash` stores only the hash. Plaintext exists only in request-time memory and the intended email.
- Ten-minute validity, five incorrect attempts per issued code, sixty-second resend cooldown.
- Each successful new send rotates the token and code, clears attempts, and replaces the old challenge. Editing deletes it immediately. Tokens and codes are excluded from flashed validation input; OTPs never appear in URLs.
- Session binding uses HMAC-SHA256 with the existing application key. The pending payload uses Laravel's encrypted array cast. Keep the existing application key.
- Dedicated synchronous `GuestBookingCode` notification uses the existing mailer. It does not enter `jobs` or `failed_jobs`. No SMTP/queue configuration changed. Delivery exceptions return a generic error without reporting potentially sensitive message bodies. Log mailers, including a log fallback, are refused for OTP delivery.
- Send budgets use Laravel RateLimiter: 20/hour/IP, 5/hour/normalized-email, and 1/minute/email+IP. Email-based cache keys use SHA256. Budgets are charged outside the challenge transaction so mail rollback cannot undo them on the database cache.
- Initial submissions retain the existing 10/minute and 60/hour/IP limiter. Resend additionally permits 5 endpoint requests/minute/IP. Verification permits 20/minute/IP and 10/minute/session, including malformed requests, plus the persisted five-attempt challenge limit.
- Laravel session blocking serializes mutations from the same browser; database row locks protect challenge consumption. Existing database cache/session infrastructure supplies shared state and locks. CSRF remains required.
- Guest responses never query whether the email belongs to a registered user. Known fictional demo-domain addresses and the fixed recruiter sandbox address receive an explanation to use a reachable email or sign in, without sending mail.
- A daily scheduler callback removes challenges expired for more than 24 hours. Abandoned encrypted payloads are normally retained for at most about 48 hours after expiry. Successful/edited challenges are deleted immediately. The existing scheduler cron picks up this task.

## Guest UX

Submit the normal booking form -> HTTP 202 with a redirect to **Verify your email** -> masked recipient, six-digit input with mobile numeric keyboard/autofill, Verify button, expiry feedback, and a countdown-disabled Resend button -> successful verification redirects to the existing signed payment checkout.

Errors appear inline. Resending keeps the stored intent and issues a new code. Expired codes can be renewed. Editing cancels the challenge, restores the hotel/dates/name/email/phone, and asks the guest to search availability and select rooms again. Verification is clearly described as not holding inventory. The new page uses existing Tailwind, Alpine, layout, and light/dark conventions, without native alerts or prompts.

## Demo and recruiter safety

The recruiter creation command already sets `email_verified_at`; its shared credentials, restrictions, reset lifecycle, and notifications remain unchanged. Native verification delivery is suppressed for sandbox users and the fictional demo domain.

New catalog-seeded demo accounts are explicitly pre-verified. A separate data migration backfills only the exact 33 seeded email/name/role combinations, and only when the `portfolio-v1` seed marker exists. It never verifies arbitrary normal users or every address under the demo domain. Renamed legacy demo identities deliberately do not match and need individual operator review. No `BookingNotice` behavior changed.

## Changed files

New application files:

- `app/Http/Controllers/Booking/GuestBookingVerificationController.php`
- `app/Http/Middleware/EnsureBookingEmailVerified.php`
- `app/Http/Requests/GuestBookingCodeRequest.php`
- `app/Models/GuestBookingChallenge.php`
- `app/Notifications/GuestBookingCode.php`
- `app/Services/GuestBookingVerification.php`
- `app/Support/EmailAddress.php`
- `resources/views/booking/verify-email.blade.php`

Updated application files:

- `app/Models/User.php`
- `app/Http/Controllers/Auth/RegisteredUserController.php`
- `app/Http/Controllers/Booking/BookingController.php`
- `app/Http/Requests/CreateBookingRequest.php`
- `app/Providers/AppServiceProvider.php`
- `app/Services/BookingService.php`
- `bootstrap/app.php`
- `database/seeders/DemoSeeder.php`
- `resources/views/booking/show.blade.php`
- `routes/booking.php`
- `routes/console.php`

Migrations:

- `database/migrations/2026_10_03_000001_create_guest_booking_challenges_table.php`: additive challenge table.
- `database/migrations/2026_10_03_000002_verify_existing_seeded_demo_accounts.php`: narrowly scoped data backfill; rollback deliberately does not revoke verification timestamps.

Tests/documentation:

- New `tests/Feature/GuestEmailVerificationTest.php` and `tests/Feature/RegisteredBookingVerificationTest.php`.
- Updated `tests/Feature/BookingFlowTest.php`, `tests/Feature/BookingNotificationTest.php`, `tests/Feature/DemoSeederIntegrityTest.php`, and `tests/Frontend/booking.test.mjs`.
- This document.

## Validation

Final full PHP suite: **193 passed, 26 skipped, 2,518 assertions**. Both opt-in disposable/destructive MySQL suites were explicitly disabled. Their real MySQL concurrency guarantees were therefore not re-executed; the ordinary booking/inventory regression tests passed, and existing creation locks remain in place.

Focused auth/verification and booking tests passed. The full suite includes booking management, payment, notifications, guest recovery, recruiter sandbox, catalog/search, and supplier regressions. Additional tests exercise real top-level commits for guest booking notices and database-cache rate budgets surviving delivery failure.

All 27 frontend tests, `php artisan route:list`, `php artisan route:cache` (then cleared locally), `php artisan view:cache`, `php artisan schedule:list`, `pnpm.cmd build`, and `git diff --check` passed. `public/build` was regenerated locally and is ignored by Git. Ship its complete contents, including the manifest, through the existing asset deployment process. No dependencies were changed.

No browser was connected to this session; live visual/mobile review and real SMTP delivery were not performed. HTTP tests use isolated SQLite databases and fake/array mail, never production data or real recipient delivery.

## Production handoff — commands to run later, not executed here

1. Back up the production database through the existing deployment process. Confirm the existing shared database cache/session setup, `cache_locks` table, SMTP mailer, application key, and scheduler remain available. No new environment variables are required.
2. Build assets locally/CI using `pnpm build` and include the complete `public/build` directory with this release.
3. Enter maintenance mode before activating the code, using `php artisan down`. Release the changed application files, views, and built assets using the existing deployment process. Do not run seeders.
4. From the new release's application root, apply only these migrations:

   ```sh
   php artisan migrate --force --path=database/migrations/2026_10_03_000001_create_guest_booking_challenges_table.php
   php artisan migrate --force --path=database/migrations/2026_10_03_000002_verify_existing_seeded_demo_accounts.php
   ```

5. Refresh application caches and queue workers:

   ```sh
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   php artisan queue:restart
   php artisan schedule:list
   php artisan up
   ```

   Keep the existing cron-driven worker and scheduler configuration. OTP delivery is synchronous; existing queued booking notices continue unchanged. Never regenerate `APP_KEY`.

6. Smoke-test registration -> native verification -> account booking; guest booking -> received OTP -> signed checkout; resend/old-code rejection; wrong/expired code; editing; and sold-out inventory during verification. Check recruiter and catalog-demo logins, queued guest/supplier notices, and guest recovery. Review the new screen on a narrow mobile viewport and both themes.

Operational limitations: SMTP latency now affects the guest's initial send/resend request. A lost browser session requires restarting verification. Only one pending guest intent per browser session is supported; a successfully submitted newer intent supersedes the older tab. Shared NAT users can reach the IP budget. If the final checkout redirect is lost after commit, use existing guest recovery instead of creating another booking. No production deployment or migrations were performed in this task.
