# Kijumbe Office Dashboard

The dashboard uses Laravel Blade, session authentication, Tailwind/Vite, Lucide icons and Chart.js. Open `/admin/login`. `/` redirects to the dashboard. All dashboard pages and actions require an active administrator.

## Local Development

```sh
npm install
npm run build
php artisan migrate
php artisan kijumbe:create-dashboard-demo
php artisan serve --host=127.0.0.1 --port=8000
```

The local-only demo command creates `demo@kijumbe.local` with a generated 24-character password. Credentials are written to `storage/app/private/dashboard-demo.txt` with owner-only permissions. Re-running it does not reset existing credentials. It creates no groups, members, cycles or transactions. Do not run the sample `DatabaseSeeder` for a clean dashboard.

Collections and payout totals use actual completed records in the selected 7/30/90-day period. Charts display "Waiting for data" without records. Group distribution and membership counts reflect the current database. Existing database records are preserved.

## Operations

The web action routes reuse the API controllers and domain services under session authentication and CSRF protection. Group creation belongs to the WhatsApp bot; the dashboard has no group-creation form or web endpoint. Existing groups can be activated, members added, cycles configured and activated, payment prompts requested, round decisions recorded and payouts requested/approved. Payout approval requires a different user from the requester. WhatsApp templates use the configured provider, and message history and audit changes can be inspected.

The overview welcomes the signed-in administrator with a time-of-day greeting in Africa/Dar_es_Salaam and a supporting illustration.

The included migration widens audit subject IDs to accommodate UUID groups. It does not delete records. Its rollback intentionally preserves the widened column because UUIDs cannot be converted back to integers without data loss.

Production builds must include the Vite assets and `public/images/`. Provision authorized production administrators separately; the local demo command refuses production environments. Provider-backed actions require the appropriate integration configuration. Deploying this UI does not by itself enable those integrations.

## Validation

```sh
WHATSAPP_LIVE_ENABLED=false PAYMENTS_LIVE_ENABLED=false php artisan test
npm run build
```

The user's reusable `dashboard-ui` skill is installed in `~/.codex/skills/dashboard-ui/SKILL.md`.
