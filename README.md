# BookYourHotel

![BookYourHotel](screenshots/banner.png)

**BookYourHotel** is a hotel booking and supplier management SaaS-style portfolio MVP.

## [Live Demo](https://bookyourhotelapp.com)

The deployed application uses simulated payments only; no real money is charged.

Customers and guests can search hotels, check availability, create a booking, use a simulated payment flow and receive a voucher. Suppliers manage hotels, rooms, facilities, images, inventory and bookings. The platform also includes guest booking recovery, notifications and transaction-safe booking and inventory operations.

## Screenshots

The repository currently includes these portfolio screenshots:

![Supplier dashboard](screenshots/dashboard.png)
![Supplier inventory](screenshots/inventory.png)
![Room management](screenshots/rooms.png)
![Image management](screenshots/images.png)

Dedicated captures for hotel details/gallery, booking/payment, notifications and the supplier mobile navigation are still recommended for the portfolio presentation.

## Key engineering features

- Database transactions and `lockForUpdate` protection for booking and inventory changes
- Overbooking protection, stale inventory detection and concurrent cancellation/confirmation safety
- Booking expiration with idempotent inventory restoration
- Signed guest management links and generic guest booking recovery responses
- Preserved booking, payment and voucher history
- Fake payment and refund simulation; no real payment provider is included
- Queue-backed database notifications and voucher generation
- GD/Intervention Image WebP processing for supplier uploads
- Deterministic, idempotent fictional demo media importer
- Faceted hotel search/filtering with measured query optimizations
- Supplier archive/deactivate lifecycle with restrictive history foreign keys
- PHP, frontend and opt-in MySQL concurrency/lifecycle tests

## Product flows

| Customer / Guest | Supplier | Platform |
| --- | --- | --- |
| Search → availability → booking → simulated payment → voucher | Hotels → rooms → facilities → inventory → bookings | Guest recovery → notifications → concurrency-safe booking/inventory logic |

## Architecture

The main request path is:

`Controller → Form Request / Policy → Service layer → Eloquent / database transaction`

Public, authenticated user and supplier flows share domain services and policy rules. Booking and payment have separate lifecycle models, while booking items preserve the values shown at booking time.

## Tech stack

- PHP 8.2+
- Laravel 12 and Eloquent ORM
- MySQL
- Laravel Breeze
- Livewire and Alpine.js
- Tailwind CSS and Vite
- Laravel queues and notifications
- Intervention Image with the GD driver
- PHPUnit and Node's built-in test runner

## Local setup

```bash
git clone https://github.com/BojanDjurdjevic/BokYourHotel.git
cd BokYourHotel
composer install
pnpm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan storage:link
php artisan serve
pnpm dev
```

Configure the database and mail values in `.env`. For SMTP, use Laravel's standard `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION`, `MAIL_FROM_ADDRESS` and `MAIL_FROM_NAME` settings. For local queued notifications, run:

```bash
php artisan queue:work --queue=default --tries=3 --timeout=60
php artisan schedule:work
```

For a production frontend bundle, run `pnpm build`.

## Demo data

```bash
php artisan demo:seed
php artisan demo:images
```

Demo hotels, suppliers, bookings and images are fictional. Images are generated portfolio assets with local provenance. The media importer is deterministic and idempotent. The payment flow is a local simulator. The demo catalog cleanup command, when needed for a local database, is `php artisan demo:catalog --apply`.

The catalog seed users are separate from the recruiter sandbox below. Never publish or reuse their passwords in production.

## Recruiter supplier sandbox

Live site: [bookyourhotelapp.com](https://bookyourhotelapp.com). Sign in at [supplier demo login](https://bookyourhotelapp.com/login).

- Email: `recruiter.supplier@example.test`
- Password: `RecruiterDemo!2026`

This fictional shared account is a temporary sandbox. Explore hotel and room setup, images, facilities, board options, pricing and inventory. Publishing enables an authenticated customer preview; sandbox hotels never enter the public booking marketplace and cannot receive bookings. The demo account cannot create bookings or change its login credentials.

Hotels and their data automatically reset about 4–5 hours after hotel creation (hourly cleanup, minimum age 4 hours). Limits are 3 hotels, 8 rooms per hotel, 8 images per hotel or room, and 366 inventory dates per room. Archived items still count until cleanup. Other recruiters share this account and may see or edit its temporary data; use fictional information only.

After deploying the additive migration, provision this account once with:

```bash
php artisan demo:supplier-create
```

The command is safe to rerun: it preserves existing credentials/data and refuses to take over an unrelated account. It does not invoke the demo catalog seeders.

The existing scheduler runs `demo:supplier-reset` hourly with overlap protection; no new server cron is needed. Manual invocation uses the same age threshold:

```bash
php artisan demo:supplier-reset
```

Optional configuration: `DEMO_SUPPLIER_MAX_HOTELS=3`, `DEMO_SUPPLIER_MAX_ROOMS_PER_HOTEL=8`, `DEMO_SUPPLIER_MAX_IMAGES=8`, `DEMO_SUPPLIER_RETENTION_HOURS=4`. Defaults require no `.env` changes. Cleanup includes archived rooms and removes only numeric hotel/room owner directories on the public disk. Shared catalogs and the supplier account survive. Any booking reference or cleanup failure is logged, reported as skipped, and returns a failing command status for operator attention; historical data is never deleted. A filesystem failure rolls back database deletion for retry, though already removed sandbox files cannot be restored by that rollback. Livewire temporary uploads retain their existing automatic cleanup and upload throttles.

Production release order: stage the code and built assets, apply the additive migration with `php artisan migrate --force` before serving the new code (use your normal atomic release or maintenance window), then run `php artisan demo:supplier-create`. Rebuild configuration/routes/views using your normal release process and restart queue workers. Verify `php artisan schedule:list`, login, private preview, and public exclusion. Do not roll back the sandbox isolation code while sandbox hotels remain in the database; keep isolation deployed until those hotels have safely expired and been removed. See [implementation and verification notes](docs/recruiter-sandbox.md) for the complete change inventory and limitations.

## Testing

```bash
php artisan test
node --test tests/Frontend/*.test.mjs
```

The MySQL suites are opt-in because they require a prepared MySQL test database:

```powershell
$env:RUN_BOOKING_MYSQL_TESTS='1'
php artisan test tests/Feature/BookingManagementConcurrencyTest.php
$env:RUN_LIFECYCLE_MYSQL_TESTS='1'
php artisan test tests/Feature/SupplierLifecycleMySqlTest.php
```

## Deployment notes

Use `APP_ENV=production`, `APP_DEBUG=false`, a correct HTTPS `APP_URL`, production database settings, SMTP secrets, a supervised queue worker and the scheduler. Configure persistent storage and the storage link, backups, logs and application secrets before deployment.

## Limitations

Payment is a simulator: there is no real provider, webhook or reconciliation flow. Admin operations are limited. This is a portfolio MVP, not a Booking.com replacement, and a production deployment still needs normal operational setup, monitoring, backups and privacy decisions.
