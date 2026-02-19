# Getting Started

This guide walks you through installing, configuring, and running Bike Trip Planner on your local machine.

---

## Prerequisites

Ensure the following tools are installed before continuing.

| Tool           | Minimum version | Purpose                                      |
|----------------|-----------------|----------------------------------------------|
| Docker         | 24+             | Runs all services (PHP, Node, Gotenberg)     |
| Docker Compose | 2.20+           | Orchestrates the multi-container environment |
| Git            | 2.40+           | Version control                              |
| Make           | 4+              | Task runner (wraps Docker Compose commands)  |

> **Note:** No local PHP or Node.js installation is required. All runtimes run inside containers.

Verify your setup:

```bash
docker --version
docker compose version
make --version
```

---

## Installation

### 1. Clone the repository

```bash
git clone <repo-url> bike-trip-planner
cd bike-trip-planner
```

### 2. Configure environment variables

Copy the example environment files and adjust as needed:

```bash
cp api/.env api/.env.local
cp pwa/.env.example pwa/.env.local
```

For local development the defaults work out of the box. Edit `api/.env.local` only if you need to:

- Point to a custom Gotenberg instance
- Configure external API keys (OpenStreetMap, weather)

> **Security:** Never commit `.env.local` files. They are git-ignored by default.

### 3. Install dependencies

```bash
make install
```

This runs `composer install` (PHP) and `npm install` (Node.js) inside their respective containers.

### 4. Start the application

```bash
make start
```

This boots three services:

| Service     | URL                     | Description                       |
|-------------|-------------------------|-----------------------------------|
| `php`       | `https://localhost/api` | API Platform backend (FrankenPHP) |
| `pwa`       | `https://localhost`     | Next.js frontend                  |
| `gotenberg` | Internal only           | PDF generation microservice       |

> **TLS:** FrankenPHP generates a self-signed certificate for `localhost`. Accept the browser warning on first load, or install the certificate into your system trust store.

The application is ready when `make start` shows all services as healthy.

---

## Verify the installation

Open `https://localhost` in your browser. You should see the Bike Trip Planner home page.

To verify the API is responding:

```bash
curl -k https://localhost/api/docs.json | head -20
```

You should receive an OpenAPI JSON document.

To run the full test suite:

```bash
make test
```

This runs QA checks, PHPUnit unit tests, and Playwright E2E tests in sequence.

---

## Common tasks

### Start and stop

```bash
make start     # Start all containers
make stop      # Stop all containers (preserves data)
make restart   # Stop then start
```

### Open a shell inside a container

```bash
make php-shell    # Bash inside the PHP container
make pwa-shell    # Bash inside the Node container
```

### Run only specific test suites

```bash
make test-php     # PHPUnit only
make test-e2e     # Playwright E2E only
```

### Regenerate TypeScript types after a backend DTO change

```bash
cd pwa && npm run typegen
```

See `make help` for the full list of available targets.

---

## Troubleshooting

### Port 443/80 already in use

Another process is using the default HTTPS port. Either stop it or override the port:

```bash
# Find the conflicting process
sudo lsof -i :443

# Or configure a different port in docker-compose.override.yml
```

### Self-signed certificate errors in the browser

Accept the certificate warning once, or add the FrankenPHP CA to your system trust store:

```bash
# On macOS
make php-shell
# Inside container:
cat /data/caddy/pki/authorities/local/root.crt
# Then import the certificate via Keychain Access
```

### `make install` fails with permission errors

Make sure Docker has access to the project directory. On Linux, you may need to add your user to the `docker` group:

```bash
sudo usermod -aG docker $USER
newgrp docker
```

### TypeScript compilation errors after `git pull`

A backend DTO likely changed. Regenerate types:

```bash
make start         # Ensure backend is running
cd pwa && npm run typegen
```
