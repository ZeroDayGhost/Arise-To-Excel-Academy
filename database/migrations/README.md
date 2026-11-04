# Parent Portal Migrations

## Fresh Install Schema

`arise_to_excel.sql` in the project root is an additive deployment schema for the current PHP application. It creates missing tables and inserts the initial administrator login (`admin` / `admin123`) only when that username is absent. It does not create or select a database, drop tables, truncate data, or delete existing records. The tables contain no sample students or payments. Change the initial administrator password immediately after first login.

**Import on hosting:** create the MySQL database and database user in your hosting control panel, open that database in phpMyAdmin, then import `arise_to_excel.sql`. For an existing website, take and verify a restorable backup first. The import is safe to repeat for tables and the admin login, but `CREATE TABLE IF NOT EXISTS` does not add missing columns to tables that already exist. Do not import this file as a schema-upgrade substitute; use the applicable documented migrations below, one at a time, after reviewing their preflight instructions. Do not upload the SQL file to a public web directory.

Configure hosting credentials in the server environment: `ARISE_APP_ENV=production`, `ARISE_DB_HOST`, `ARISE_DB_NAME`, `ARISE_DB_USER`, and `ARISE_DB_PASS`. `ARISE_DB_PORT` is optional (defaults to 3306); use `ARISE_DB_SOCKET` only when the host supplies a Unix socket path. Production mode refuses to start with missing credentials. The local XAMPP `root` / blank-password fallback is development-only.

The schema covers tables referenced by the active student, fee, parent portal, Daycare payments, Kitchen spending, feeding, transport, uniform, fuel, expenses, Academic, roles/permissions, audit, and reporting code. Setup/configuration rows such as roles, permissions, default class levels, and grading ranges may be added idempotently where required; student/payment/business records are not seeded. On a genuinely empty database, the first student import initializes the admission-number sequence automatically. Existing student records with no sequence still require the identity migration below.

## Hosting Deployment Checklist

1. In the hosting control panel, create a MySQL database and a database user with access to it. Select that database in phpMyAdmin and import `arise_to_excel.sql` for a new installation.
2. Upload the current application files from `parent-portal-stage` to the intended web directory. Do not upload backup folders or place database backups in a public directory.
3. Set `ARISE_APP_ENV=production` and the `ARISE_DB_HOST`, `ARISE_DB_NAME`, `ARISE_DB_USER`, and `ARISE_DB_PASS` values in the hosting environment. Confirm that PHP-FPM/Apache exposes these environment variables to PHP before opening the site.
4. Sign in with the initial admin login only for a brand-new empty database, then immediately change its password. Repeat SQL imports do not reset an existing `admin` password.
5. For an existing live database, verify a restorable backup first. Do not use the fresh-install SQL to upgrade columns; apply only the documented migration(s) that match the current schema, then verify counts and recent receipts/records before reopening the site.

The local PHP/MySQL integration tests require a separate test database. Do not point them at the hosting database. The provider's database name, user, and password are not included in this project and must be supplied by the hosting account owner.

For an existing database that predates the parent portal, back up the database first. In phpMyAdmin, select that database and apply these migrations in order. New installations already receive these tables from `arise_to_excel.sql` and should not run them again:

1. `20261017_parent_portal.sql`
2. `20261018_parent_registration.sql`

The first migration creates the explicit parent-child link and audit tables and removes legacy module/action grants from the Parent role. The second adds parent contact/identity profiles, one-time activation records, duplicate-submission protection, and relationship metadata. Both preserve existing role assignments, students, fee balances, payments, and receipts.

Parent accounts use the existing `admin` login store, but server-side guards keep Parent-role sessions out of every admin route. Registration automatically creates a pending parent login and relationship, or reuses a unique in-person-verified match. Ambiguous phone matches require an administrator to select and confirm the identity. The activation code is not emailed; it is shown once to the authenticated administrator for direct handoff, expires after seven days, and is stored only as a hash.

## Permanent Admission Numbers and Enrollment Status

For the existing XAMPP database, make a verified backup before deploying code or running the migration. Close student registration and payment screens during the migration so new records cannot be written while the number sequence is being established. Do not re-import `arise_to_excel.sql` into an existing school database; it is a fresh-install schema, not an upgrade script.

From the project folder, first run a no-change preflight:

```powershell
C:\xampp\php\php.exe database\migrations\20261019_student_identity_transfers.php
```

Review the student count and previewed admission-number mapping. If it reports duplicate or missing old numbers, stop and resolve those records before continuing. After confirming a restorable database backup and a clean preflight, apply the migration:

```powershell
C:\xampp\php\php.exe database\migrations\20261019_student_identity_transfers.php --apply
```

The migration preserves every `students.id`, maps existing students in ID order to permanent `ATX001`, `ATX002`, and subsequent numbers, and stores the old-to-new mapping. It adds the global allocation sequence, enrollment status and status history, historical-fee audit table, and permissions. Student-linked fees, assessments, guardian links, transport, feeding, and academic records continue to reference the unchanged student IDs. New status values are `Active`, `Transferred Out`, `Graduated`, and `Withdrawn`; routine enrollment and charges use Active students, while past balances and receipts remain available in student history. Authorized staff may record an explicitly described payment against an already-existing historical fee balance.

The migration is idempotent. Keep the backup in a private local location, not a cloud-synced folder, because it contains student and family information. Verify the final student count and that all admission numbers are unique after applying it.

The SQL files are protected from direct web access by the project-root and `database/.htaccess` rules. Apply them through the database administration tool, not through a browser URL.

## Permanent Fee Receipt Numbers

For an existing XAMPP database, back it up before running the fee receipt migration. From the project folder run a no-change preflight, review the existing receipt values and duplicate check, then apply it:

```powershell
C:\xampp\php\php.exe database\migrations\20261020_permanent_fee_receipts.php
C:\xampp\php\php.exe database\migrations\20261020_permanent_fee_receipts.php --apply
```

The migration preserves all current `fees.receipt_no` values exactly, adds an annual sequence and an append-only payment audit, and keeps payment rows and balances when a calendar term is removed. New successful fee payments receive `ATXR-YYYY-NNNNN` inside the same database transaction as the payment and balance update. Reversal, correction, and cancellation actions retain the original payment and receipt while adjusting the outstanding balance and recording the actor, reason, and before/after details. A corrected transaction is entered as a new payment after reversing the incorrect one.

Receipt numbering checks can be run with `C:\xampp\php\php.exe tests\fee_receipt_test.php`. The MySQL concurrency check, `C:\xampp\php\php.exe tests\fee_receipt_mysql_concurrency_test.php`, creates a uniquely named temporary database, runs eight simultaneous payment transactions against it, verifies the full sequence, and drops only that temporary database.

## Importing Previous-Year Fee Payments

The Fees page keeps its existing CSV export unchanged and adds a permission-controlled historical-payment importer. The downloadable `.xlsx` template contains only receipt number, payment date, student name, M-PESA code, payment method, and amount paid. A unique student name is matched to the existing student record and its admission number is shown automatically; duplicate names require an explicit admission-number choice. Academic year and term are selected once for the whole import, not entered in the workbook. Enrollment status, class, and term balance are read or calculated from the system. M-PESA reference text from older workbooks is ignored and not saved for imported payments.

Back up the database, then run a no-change preflight and apply the import schema/permission migration:

```powershell
C:\xampp\php\php.exe database\migrations\20261021_fee_payment_imports.php
C:\xampp\php\php.exe database\migrations\20261021_fee_payment_imports.php --apply
```

The migration creates import-batch and per-row audit tables and grants the new `fees.import` action to the Director and Accountant roles only. Student and payment records are unchanged. Import preview matches a unique student name automatically and shows its admission number; duplicate names require an explicit selection. The importer rejects duplicate receipt/reference identifiers, shows potential duplicate payments, preserves supplied dates and receipts, and uses the annual receipt sequence only when the source has no receipt number. An M-PESA code is required when the payment method is M-PESA, but an Excel row is never treated as independent verification. Imports run as a single transaction and an identical workbook cannot be replayed. Existing unledgered balance credits are consumed before any additional balance adjustment, with a visible warning and confirmation.

Run `C:\xampp\php\php.exe tests\fee_payment_import_test.php` to verify the XLSX template, date/amount parsing, student-name matching, duplicate-name selection, assigned academic period, balance reconciliation, retry protection, discarded M-PESA reference text, and rollback behavior. `C:\xampp\php\php.exe tests\fee_payment_import_mysql_test.php` runs the same transaction and receipt paths against a uniquely named temporary MySQL database, including advisory reference locking and rollback after a simulated audit-write failure. It drops only its temporary database.

## Optional Guardian Contact Details

For an existing database, back it up and apply `20261020_student_guardian_contacts.sql` before deploying the updated student form. This adds nullable alternative-phone and email columns to `students`; it does not change existing values or require either field. New installations receive the same columns from `arise_to_excel.sql`.

## Secretary Payment Correction Requests

Back up the live database, then run the correction migration preflight and apply it from the project folder:

```powershell
C:\xampp\php\php.exe database\migrations\20261022_fee_payment_corrections.php
C:\xampp\php\php.exe database\migrations\20261022_fee_payment_corrections.php --apply
```

The migration creates an append-preserving correction-request table and grants the existing Secretary role only the Fees history access needed to submit requests. It does not modify existing payment, balance, receipt, or role-assignment records. Directors can approve or reject requests and make reasoned direct corrections; only Directors can reverse or cancel payments. Requests remain pending without changing a payment or balance. Receipts retain their original number, and audit entries capture before/after payment and balance values. Run `C:\xampp\php\php.exe tests\fee_correction_test.php` for Secretary, Director, Teacher, pending, rejection, approval, direct correction, reversal, audit, receipt-preservation, and balance tests. `C:\xampp\php\php.exe tests\fee_correction_mysql_test.php` verifies the same approval transaction against a uniquely named temporary MariaDB database and drops only that database.

## Daycare Module

New installations receive the empty daycare schema from `arise_to_excel.sql`. Existing installations should take and verify a restorable database backup, run the preflight, inspect its output, then apply the additive migration:

```powershell
C:\xampp\php\php.exe database\migrations\20261024_daycare.php
C:\xampp\php\php.exe database\migrations\20261024_daycare.php --apply
```

The current migration creates only the tables used by child registration, groups, guardians, fee plans, payment receipts, payment allocation, and audit records. Attendance, check-in/check-out, pickups, care, daycare expenses, and daycare staff tables are not created. The migration grants active Daycare permissions to matching existing Director, Accountant, Receptionist, Headteacher, and Teacher roles; it does not create a caregiver role. Existing user overrides and operational records are not rewritten or dropped. Daycare payment and fee-plan data remains separate from the school-student fee ledger.

The module generates `DC-YYYY-NNNNN` child admission numbers and `ATXD-YYYY-NNNNN` payment receipts on demand. Receipt counters are transaction-locked. M-Pesa uniqueness is enforced on the shared payment transaction record; allocation-level daycare references are indexed but not unique so one payment can cover multiple children. Run `C:\xampp\php\php.exe tests\daycare_rbac_test.php` to verify the current permissions.

## Kitchen Custom Categories

The fresh-install schema includes an empty `kitchen_expense_categories` table. On an existing installation, apply `20261101_kitchen_expense_categories.sql` to create it. Custom category names are saved only with a successful Kitchen expense entry and then appear in the category dropdown for future entries. This migration inserts no sample categories and does not alter or delete existing Kitchen expenses.

## Activities & Trips

New installations receive the empty collections-only Activities & Trips schema from `arise_to_excel.sql`. Existing installations should make and verify a restorable database backup, then import `20261102_activities_trips.sql` into the existing application database before deploying the updated PHP files. The migration is additive and safe to re-run; it does not delete or rewrite student, payment, or receipt records. It creates activity definitions, class selections, student participation, payment receipts, and an audit log. Activity payments use a separate ledger and are included in school income/net-position calculations once. Reversals and refunds remain in the payment history with a reason and stop counting as collected income. The module does not create or manage activity expense tables; any legacy activity expense tables or rows from a previous version are left untouched and unused.

Deploy the schema migration first, then upload the updated module files and shared integration changes for RBAC, navigation, dashboard, and Reports. Grant only the needed `Activities & Trips` module/actions through User & Permissions. Back up the database before production deployment and run `C:\xampp\php\php.exe tests\activities_trips_test.php` plus the PHP syntax checks before opening the module to staff.

## Combined Parent Payments

Combined payments create one central transaction and one or more allocation rows while still writing each allocation to the existing Fees, Activities & Trips, Daycare, Transport, Feeding, or School Uniform ledger. Those module ledgers remain the source for student balances and school income totals; the combined transaction report is a reference view and is not counted as additional income. Existing module payments are not deleted or rewritten. Uniform installments recorded through Combined Payment are stored in `uniform_sale_payments` and update the existing sale's aggregate amount paid and balance without replacing its original sale/payment details.

For a new database, `arise_to_excel.sql` already includes the transaction, allocation, and uniform installment tables. For an existing database, take and verify a restorable backup, then import `20261103_multi_student_payments.sql` followed by `20261103_z_combined_payment_modules.sql` into that database before deploying the PHP changes. The first migration requires the current Daycare schema, including `daycare_payments`, and changes its old unique reference index to a non-unique lookup index while keeping duplicate protection on the central transaction. The second migration adds Transport, Feeding, and Uniform allocation types and creates the additive uniform payment ledger; both migrations leave historical records untouched and may be imported again.

After importing the SQL migration, backfill existing payment rows into the new reporting relationship. Configure `ARISE_DB_HOST`, `ARISE_DB_NAME`, `ARISE_DB_USER`, `ARISE_DB_PASS`, and optionally `ARISE_DB_PORT` for the CLI environment, then run:

```powershell
C:\xampp\php\php.exe database\migrations\20261103_backfill_payment_transactions.php
```

The backfill is repeat-safe, groups legacy M-Pesa rows sharing a code into one historical transaction, creates allocation links, and leaves original payment and receipt rows unchanged. Verify the output count and confirm the combined receipt/payment history before reopening the payment screens. The focused allocation and schema tests are `C:\xampp\php\php.exe tests\payment_transactions_test.php` and `C:\xampp\php\php.exe tests\sql_schema_alignment_test.php`. Test the new category migration against a backed-up test database before production deployment.

## Academic Year Promotion Recovery and Historical Viewing

New installations include per-student promotion snapshots, audited undo metadata, and the nullable `students.admitted_academic_year` field in `arise_to_excel.sql`. For an existing database, back it up and import `20261104_student_promotion_recovery.sql` in phpMyAdmin before deploying the updated PHP files. The migration adds only the required columns and tables, then backfills snapshots from existing promotion assignment rows; it does not delete or rewrite payment, receipt, fee balance, or report records.

The Academic Calendar can complete a year switch when a matching promotion is already recorded, without promoting students a second time. The shared year selector switches the administrator's read-only view period without changing the active school year. While viewing an earlier period, record-changing POST actions are blocked. Future promotions keep exact per-student class/status snapshots, preserve students admitted during the current source year, and mark Grade 5 students Graduated. Undo is limited to Admin/Director roles and is blocked whenever destination-year financial, academic, transport, feeding, or activity records exist or class-history data changed. Every allowed undo requires a reason and remains in the promotion and audit history.

Run `C:\xampp\php\php.exe tests\student_class_promotion_test.php` to verify interrupted switch completion, idempotent retry, new-admission preservation, Grade 5 graduation, dependent-record blocking, exact undo restoration, and repeat-undo protection. Run `C:\xampp\php\php.exe tests\student_class_promotion_mysql_migration_test.php` to import the SQL migration twice into an isolated MariaDB test database and verify safe snapshot backfill without duplicate rows.
