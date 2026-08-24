# Kijumbe Backend: Dedicated Server Deployment

This deployment runs only the Laravel backend in Docker. The frontend remains outside this stack.

The production stack creates these fixed containers:

| Container | Purpose | Network port |
|---|---|---:|
| `kijumbe-api` | Laravel HTTP API | `8080` |
| `kijumbe-queue` | Queued WhatsApp and payment work | None |
| `kijumbe-scheduler` | Scheduled reconciliation and reminders | None |
| `kijumbe-mysql` | Private MySQL database | `3306` internally only |

Only `kijumbe-api` joins the existing Nginx Proxy Manager network. No Kijumbe port is published directly on the server.

## 1. Push the production files to GitHub

From the backend repository on the development computer:

```bash
git add Dockerfile .dockerignore .env.production.example compose.production.yaml docker docs/DOCKER_SERVER_DEPLOYMENT.md bootstrap/app.php
git commit -m "Add production Docker deployment"
git push
```

Include any other intended backend changes in the commit as appropriate.

## 2. Clone or update the backend on the server

For a first deployment:

```bash
git clone YOUR_BACKEND_GITHUB_URL kijumbe-backend
cd kijumbe-backend
```

For an existing checkout:

```bash
cd kijumbe-backend
git pull
```

## 3. Find the existing Nginx Proxy Manager Docker network

Run this on the server:

```bash
docker ps --filter publish=81 --format 'table {{.Names}}\t{{.Networks}}\t{{.Ports}}'
```

The `NETWORKS` value on the Nginx Proxy Manager row is the value required by `NPM_NETWORK`. It is commonly `npm_default`, but use the exact value shown on this server.

## 4. Create the production environment file

```bash
cp .env.production.example .env.production
nano .env.production
```

At minimum, set:

```dotenv
APP_KEY=base64:YOUR_RANDOM_32_BYTE_KEY
DB_PASSWORD=YOUR_STRONG_DATABASE_PASSWORD
DB_ROOT_PASSWORD=YOUR_DIFFERENT_STRONG_ROOT_PASSWORD
NPM_NETWORK=THE_NETWORK_FROM_STEP_3
```

Generate random values on the server with:

```bash
openssl rand -base64 32
```

Run that command separately for each secret. Never commit `.env.production` to GitHub.

Keep these switches off until the corresponding credentials and callbacks have been configured and checked:

```dotenv
WHATSAPP_LIVE_ENABLED=false
PAYMENTS_LIVE_ENABLED=false
```

## 5. Build and start the backend

```bash
docker compose --env-file .env.production -f compose.production.yaml build
docker compose --env-file .env.production -f compose.production.yaml up -d mysql
docker compose --env-file .env.production -f compose.production.yaml run --rm api php artisan migrate --force
docker compose --env-file .env.production -f compose.production.yaml up -d
docker compose --env-file .env.production -f compose.production.yaml exec api php artisan optimize
docker compose --env-file .env.production -f compose.production.yaml ps
```

All four services should show as running; `kijumbe-api` and `kijumbe-mysql` should become healthy.

## 6. Complete the Nginx Proxy Manager form

In **Proxy Hosts → Add Proxy Host → Details**, enter:

| Field | Value |
|---|---|
| Domain Names | `kijumbe.goldencreations.online` |
| Scheme | `http` |
| Forward Hostname / IP | `kijumbe-api` |
| Forward Port | `8080` |
| Access List | `Publicly Accessible` |
| Cache Assets | Off |
| Block Common Exploits | On |
| Websockets Support | Off |

In the **SSL** tab:

1. Select **Request a new SSL Certificate**.
2. Enable **Force SSL**.
3. Enable **HTTP/2 Support**.
4. Accept the Let's Encrypt terms.
5. Save the proxy host.

Do not enter the server public IP as the forward hostname. Nginx Proxy Manager reaches `kijumbe-api` through their shared private Docker network.

## 7. Verify the public backend

```bash
curl -i https://kijumbe.goldencreations.online/up
curl -i https://kijumbe.goldencreations.online/api/v1/auth/me
```

The first request should return `200`. The second should return `401` without a login token; that is expected and proves the protected API is reachable.

Useful diagnostics:

```bash
docker compose --env-file .env.production -f compose.production.yaml logs --tail=100 api
docker compose --env-file .env.production -f compose.production.yaml exec api php artisan kijumbe:integration-status
```

## 8. Configure real integrations after the API is healthy

The public callback URLs are:

```text
https://kijumbe.goldencreations.online/api/v1/webhooks/whatsapp
https://kijumbe.goldencreations.online/api/v1/webhooks/payments/selcom/lookup
https://kijumbe.goldencreations.online/api/v1/webhooks/payments/selcom/validation
https://kijumbe.goldencreations.online/api/v1/webhooks/payments/selcom/notification
```

Add the Meta and Selcom credentials to `.env.production`, verify their webhooks, and only then change the relevant live switch to `true` and recreate the application services:

```bash
docker compose --env-file .env.production -f compose.production.yaml up -d --force-recreate api queue scheduler
docker compose --env-file .env.production -f compose.production.yaml exec api php artisan optimize
```

## Updating later

```bash
git pull
docker compose --env-file .env.production -f compose.production.yaml build
docker compose --env-file .env.production -f compose.production.yaml up -d
docker compose --env-file .env.production -f compose.production.yaml exec api php artisan migrate --force
docker compose --env-file .env.production -f compose.production.yaml exec api php artisan optimize
```

Restrict the Nginx Proxy Manager admin port `81` with a firewall, VPN, or office-IP allowlist. It should not remain openly reachable from the internet.
