# Kijumbe Office MVP Backend

Laravel/MySQL backend for a controlled, real-money upatu pilot operated through one official WhatsApp Business Platform number.

Office members do not need a new consumer app. They privately message the official Kijumbe number with commands such as `PAY` and `STATUS`. The backend starts a mobile-money prompt, accepts authenticated provider callbacks as proof, applies grace/default rules, and prevents payout before the round is ready.

## Current capabilities

- Groups, flexible cycle length and contribution amount, payout order, grace periods and blocked rounds.
- Meta Cloud API webhook verification and `X-Hub-Signature-256` validation.
- Incoming/outgoing WhatsApp message and delivery-status records.
- Office-member allowlist and explicit WhatsApp opt-in/out.
- `PAY`, `STATUS`, `START` and `STOP` private-chat commands.
- Selcom Wallet Pull adapter for real mobile-money Push USSD.
- Authenticated, idempotent Selcom C2B callbacks with exact amount/currency matching.
- Late payments automatically recorded as `paid_late`.
- Selcom Wallet Cashin payout adapter with different-person maker/checker approval.
- Five-minute provider status reconciliation for delayed or missing callbacks.
- MySQL audit trail for financial and operational changes.
- No automatic company guarantee for missing contributions.

## Stack

- PHP 8.4 and Laravel 13
- MySQL 8.4 as the permanent source of truth
- Laravel Sanctum for the existing office dashboard
- Laravel database queue for the office pilot; Redis can replace it later
- Meta WhatsApp Business Platform Cloud API
- Replaceable `PaymentGateway` contract; Selcom is the current implementation

## What “office dashboard” means

It is the private staff/admin panel—not a member app. Since you already have one, do not build another. Connect the existing dashboard to these APIs so authorized staff can create cycles, configure member WhatsApp numbers, see payment exceptions, request payouts, and provide the second approval. Members and the local Kijumbe continue using WhatsApp.

## Start locally

```bash
docker compose up -d
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Run these in separate processes:

```bash
php artisan queue:work --tries=5
php artisan schedule:work
```

Seeded development administrator:

```text
email: admin@kijumbe.test
password: password
```

Never use those credentials in production.

## Important API endpoints

All endpoints start with `/api/v1`.

```text
POST   /auth/login
GET    /office-users
POST   /office-users
PUT    /office-users/{user}/whatsapp
POST   /groups
POST   /groups/{group}/members
POST   /groups/{group}/cycles
POST   /cycles/{cycle}/activate
GET    /cycles/{cycle}
POST   /contributions/{contribution}/payment-attempts
GET    /payment-attempts/{paymentAttempt}
POST   /rounds/{round}/decisions
POST   /rounds/{round}/live-payouts
POST   /payouts/{payout}/approve
POST   /whatsapp/templates/send
GET    /webhooks/whatsapp
POST   /webhooks/whatsapp
POST   /webhooks/payments/selcom/lookup
POST   /webhooks/payments/selcom/validation
POST   /webhooks/payments/selcom/notification
```

The older manual contribution and payout endpoints remain available for controlled recovery, but live operation should use provider-backed attempts and callbacks.

## Production checks

```bash
php artisan kijumbe:integration-status
php artisan test
vendor/bin/pint --test
```

`kijumbe:integration-status` prints only whether each required value is present; it never prints tokens or API keys.

The complete account, webhook and real-money launch procedure is in [docs/LIVE_PILOT_RUNBOOK.md](docs/LIVE_PILOT_RUNBOOK.md).

The exact GitHub-to-server Docker and Nginx Proxy Manager procedure is in [docs/DOCKER_SERVER_DEPLOYMENT.md](docs/DOCKER_SERVER_DEPLOYMENT.md).
# kijumbe
