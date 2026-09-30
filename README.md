# GetL1

Reverse-auction procurement SaaS for Indian SMEs. Buyers post a requirement, invite suppliers,
collect sealed quotes, then run a live reverse auction where suppliers bid the price down.
Laravel 12 · PHP 8.2+ · MySQL 8 · Blade + Tailwind 4.

## Local setup (XAMPP / MySQL)

```bash
composer install
cp .env.example .env
php artisan key:generate
# create an empty MySQL database named `getl1` first
php artisan migrate --seed        # plans, categories, and (APP_ENV=local) demo data
npm install && npm run build      # or `npm run dev` while working on views
php artisan serve
```

Demo logins (password `password`):

| Email | Role |
| --- | --- |
| buyer@getl1.test | Buyer admin, Demo Packaging Pvt Ltd (30-day trial) |
| supplier1@getl1.test … supplier5@getl1.test | Suppliers in the buyer's list |

Run tests (in-memory SQLite, no MySQL needed):

```bash
php artisan test
```

## Architecture notes

- **Multi-tenant by `organization_id`.** `App\Support\Tenancy\CurrentOrganization` holds the org a
  request acts for, and the `org` middleware sets it. Buyer-side models (`Rfq`, `Auction`, `Award`,
  `AiJob`) use the `BelongsToOrganization` trait: queries are auto-filtered and `organization_id` is
  auto-filled. Supplier-side reads of a buyer's RFQ must go through the invite and use
  `withoutGlobalScope('organization')`.
- **Roles** live on `org_user.role` (`buyer_admin`, `buyer_user`, `approver`, `supplier_user`).
  Route middleware: `org.type:buyer|supplier`, `org.role:buyer_admin,buyer_user`.
- **Append-only tables.** `bids` and `audit_logs` throw on update/delete at the model level. In
  production also revoke UPDATE/DELETE on those tables from the app's DB user:

  ```sql
  REVOKE UPDATE, DELETE ON getl1.bids FROM 'getl1_app'@'localhost';
  REVOKE UPDATE, DELETE ON getl1.audit_logs FROM 'getl1_app'@'localhost';
  ```

- **Money** is `DECIMAL(15,2)` INR. **Timestamps** are UTC; bid times keep microseconds so the
  earlier of two equal bids wins.
- **Audit:** write every state change that matters through `App\Services\AuditLogger`.

## Build plan

1. ✅ Base: auth, organizations, roles, trial, all 22 tables, models, seeders, tests
2. Supplier onboarding, KYC upload, buyer supplier lists
3. RFQ builder, invites (magic link), sealed quotes
4. Live auction engine: row-locked bids, ranks, auto-extend, Reverb broadcasting
5. Award, approval, PO PDF, audit views
6. Razorpay subscriptions, plan limits, savings report
7. AI RFQ parsing, WhatsApp notifications
