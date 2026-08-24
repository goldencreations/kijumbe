# Kijumbe Real-Money Office Pilot Runbook

## 1. Final operating flow

```text
Office member (private WhatsApp chat)
    -> official Kijumbe WhatsApp number
    -> Meta Cloud API signed webhook
    -> Laravel conversation + cycle rules
    -> Selcom Wallet Pull (Push USSD) request
    -> member enters PIN in the mobile-money prompt
    -> Selcom authenticated C2B notification
    -> exact contribution and round update in MySQL
    -> WhatsApp receipt/status notice

Ready round
    -> staff member requests payout in the existing dashboard
    -> a different authorized staff member approves
    -> Selcom Wallet Cashin pays the recipient mobile wallet
    -> result/status reconciliation completes the round and notifies members
```

WhatsApp never asks for or receives a mobile-money PIN. A screenshot is not proof of payment. Provider callbacks and reconciliation are proof.

## 2. Deployment prerequisites

Before connecting either live account, deploy the Laravel API to a stable public HTTPS hostname, for example:

```text
https://api.your-domain.tz
```

Required production processes:

- HTTPS web application behind a reverse proxy/load balancer.
- MySQL with encrypted backups and restricted network access.
- At least one continuously running queue worker.
- Laravel scheduler invoked every minute.
- Central logs and alerts for failed jobs, signature failures, payment mismatches and payout exceptions.
- Secrets stored in the host/cloud secret manager, never committed to Git or pasted into WhatsApp.

The permanent webhook URLs are:

```text
https://api.your-domain.tz/api/v1/webhooks/whatsapp
https://api.your-domain.tz/api/v1/webhooks/payments/selcom/lookup
https://api.your-domain.tz/api/v1/webhooks/payments/selcom/validation
https://api.your-domain.tz/api/v1/webhooks/payments/selcom/notification
```

Do not use a temporary tunnel for the live-money pilot.

## 3. Meta account connection

The inspected business portfolio already has a real WhatsApp Business Account. Kijumbe will use the number currently shown as unverified. “Unverified” is its present state, not a mode that can send real Cloud API messages indefinitely: the team must control the SIM/line and complete Meta's SMS or voice OTP before the number becomes usable for the live office pilot. Also confirm the customer-visible display name before registration.

The Meta Business portfolio owns the WhatsApp Business Account, number and billing. The Meta for Developers account is required for a different reason: it creates the Meta App that authorizes Laravel, provides the app secret used to verify signed webhooks, and lets the app subscribe to the WABA's `messages` events. Office members never need developer accounts.

### Meta steps

1. Finish creation of the Meta for Developers account for the portfolio administrator.
2. Create a Business app named `Kijumbe Office Pilot` in Meta for Developers.
3. Add the WhatsApp product to the app and select the existing business portfolio/WABA.
4. In WhatsApp Manager, select the chosen unverified number and complete OTP verification.
5. Record the WABA ID and Phone Number ID. These are identifiers, not access secrets.
6. Create a Meta system user for the backend, assign the app and WhatsApp account, and create a non-human production token with only the permissions needed for WhatsApp business messaging/management.
7. Generate a long random webhook verify token locally.
8. Configure the WhatsApp webhook URL and verify token in Meta.
9. Subscribe the app/WABA to the `messages` webhook field so incoming messages and outgoing status changes arrive at Laravel.
10. Create and approve utility templates for reminders and receipts used outside the customer-service window.

Suggested templates:

```text
kijumbe_contribution_due
kijumbe_grace_started
kijumbe_payment_received
kijumbe_round_ready
kijumbe_payout_received
```

Keep the ordinary office WhatsApp group as the social room. The official API interaction is private 1:1 messaging with the Kijumbe number.

### Meta environment values

```dotenv
WHATSAPP_LIVE_ENABLED=true
WHATSAPP_GRAPH_VERSION=v26.0
WHATSAPP_BUSINESS_ACCOUNT_ID=...
WHATSAPP_PHONE_NUMBER_ID=...
WHATSAPP_ACCESS_TOKEN=...
WHATSAPP_APP_SECRET=...
WHATSAPP_VERIFY_TOKEN=...
```

The access token and app secret are sensitive. Enter them directly into the production secret store; do not send them in chat.

## 4. Selcom live merchant connection

The backend uses Selcom's API Gateway product for both directions: Wallet Pull Funds (Push USSD) for member contributions, C2B callbacks/status query as proof of collection, and Wallet Cashin with the `CASHIN` utility code for mobile-wallet payouts. `CASHIN` lets Selcom route the destination using mobile-number portability lookup.

### Merchant steps

1. Use the actual legal entity operating Kijumbe and complete Selcom's merchant KYC/onboarding.
2. Give Selcom the exact rotating-upatu funds flow and obtain written confirmation that collection, safeguarding/settlement and payouts are permitted under the merchant agreement.
3. Ask Selcom to enable all three required capabilities: Wallet Pull Funds (`/v1/wallet/pushussd`), C2B collection callbacks/status query, and Wallet Cashin (`/v1/walletcashin/process`).
4. Obtain the Selcom-provided production base URL, API key, API secret, vendor/float identifier and float PIN. Do not guess the base URL from documentation examples.
5. Generate and share a strong C2B bearer token through an approved secure channel, then register the three permanent callback URLs above with Selcom.
6. Configure settlement and maintain enough Selcom float for payouts.
7. Confirm transaction limits, MNO coverage, fees, callback retry rules and reconciliation contacts before agreeing the office contribution amount.

### Selcom environment values

```dotenv
PAYMENT_DRIVER=selcom
PAYMENTS_LIVE_ENABLED=true
SELCOM_BASE_URL=... # exact value issued by Selcom
SELCOM_API_KEY=...
SELCOM_API_SECRET=...
SELCOM_VENDOR=...
SELCOM_FLOAT_PIN=...
SELCOM_C2B_TOKEN=...
SELCOM_INITIATOR_MSISDN=...
SELCOM_WALLET_CASHIN_UTILITY_CODE=CASHIN
```

Outbound Selcom requests are signed with HS256 using the API secret. Incoming C2B requests are rejected unless their bearer token matches `SELCOM_C2B_TOKEN`.

## 5. Configure office members

Each participant must be created in the backend and explicitly opted in for the pilot. The existing dashboard can call:

```http
PUT /api/v1/office-users/{user}/whatsapp
Authorization: Bearer OFFICE_ADMIN_TOKEN
Content-Type: application/json

{
  "phone": "+255700000000",
  "opted_in": true
}
```

The backend stores a normalized Tanzanian WhatsApp ID. Unknown numbers can reach the webhook but cannot run `PAY`, view group status, or trigger money movement.

## 6. Controlled go-live test

Use a two-member or three-member office group and the provider's permitted minimum values.

1. Run `php artisan migrate --force`.
2. Run `php artisan kijumbe:integration-status`; every check must show `YES`.
3. Confirm queue worker and scheduler are healthy.
4. Send `START` from each allowlisted office phone.
5. Create and activate one small cycle in the existing dashboard.
6. Send `STATUS`; verify the expected amount and due time.
7. Send `PAY`; verify the mobile-money prompt appears on that same member's phone.
8. Enter the PIN only in the provider/MNO prompt.
9. Confirm the contribution changes only after an authenticated Selcom C2B notification.
10. Repeat one callback and verify no duplicate contribution/ledger update occurs.
11. Pay one contribution after its due time and verify `paid_late`.
12. Intentionally leave one contribution unpaid through grace and verify the round becomes `blocked`.
13. Fully fund a test round, request payout as staff member A, and approve as staff member B.
14. Verify recipient name/number/amount before approval, then confirm the successful Selcom result or status query closes the round.
15. Reconcile provider dashboard totals, Kijumbe totals, fees and settlement before increasing amounts.

## 7. Failure handling

| Event | System behavior | Staff action |
|---|---|---|
| Duplicate callback | Unique event key ignores the duplicate | None |
| Invalid Meta signature or Selcom C2B token | Request rejected; no financial state changes | Investigate source/secret rotation |
| Amount/currency mismatch | Event retained with processing error; contribution stays unpaid | Compare provider record and escalate |
| Member debited but callback delayed | Five-minute status query can recover the result | Check reconciliation/failed jobs |
| Contribution late | Recorded `paid_late`; round recalculated | Follow the group's agreed rule |
| Grace expires underfunded | Round becomes `blocked`; no payout | Extend grace, formally accept partial, or cancel |
| Payout request | Remains pending | A different authorized person verifies recipient and approves |
| Payout refunded/reversed | Payout/round are marked as an exception | Stop next round and reconcile funds |
| Meta outage | Money state remains in MySQL/provider | Use the office dashboard; send notices after recovery |

## 8. Launch gates

An office-only test reduces exposure but does not remove payment-system, consumer-protection, privacy, custody, AML/KYC, tax or employment obligations. Before moving beyond a small controlled pilot, obtain written confirmation from the licensed payment partner and Tanzanian counsel about the exact custody/settlement and fee model. Kijumbe should orchestrate and record payments through a licensed provider, not quietly hold pooled member funds in an ordinary personal wallet.
