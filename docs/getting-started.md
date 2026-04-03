# Getting Started

*[Version francaise](getting-started.fr.md)*

This guide walks you through installing, configuring, and running Bike Trip Planner on your local machine.

---

## Prerequisites

Ensure the following tools are installed before continuing.

| Tool           | Minimum version | Purpose                                      |
|----------------|-----------------|----------------------------------------------|
| Docker         | 24+             | Runs all services (PHP, Node)                |
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

Clone the repository:

```bash
git clone <repo-url> bike-trip-planner
cd bike-trip-planner
```

Start the application in production mode:

```bash
make start
```

This boots multiple services:

| Service     | URL                      | Description                 |
|-------------|--------------------------|-----------------------------|
| `php`       | `https://localhost/docs` | API Platform backend        |
| `pwa`       | `https://localhost`      | Next.js frontend            |
| `worker`    | Internal only            | Async messages worker       |
| `mercure`   | Internal only            | Server-push microservice    |
| `redis`     | Internal only            | Cache microservice          |
| `caddy`     | Internal only            | Web server microservice     |

> **TLS:** Caddy generates a self-signed certificate for `localhost`. Accept the browser warning on first load, or install the certificate into your system trust store.

The application is ready when all services are healthy.

---

## Verify the installation

Open `https://localhost` in your browser. You should see the Bike Trip Planner home page.

To verify the API is responding:

```bash
curl -k https://localhost/docs.json | head -20
```

You should receive an OpenAPI JSON document.

---

## Common tasks

```bash
make start     # Start all containers
make stop      # Stop all containers (preserves data)
make clean     # Stop all containers and erase all data (use with caution)
```

See `make help` for the full list of available targets.

---

## Troubleshooting

### Port 443/80 already in use

Another process is using the default HTTPS port. Either stop it or override the port:

```bash
# Find the conflicting process
sudo lsof -i :443

# Or configure a different port in compose.override.yaml
```

### Self-signed certificate errors in the browser

Accept the certificate warning once.

### `make start` fails with permission errors

Make sure Docker has access to the project directory. On Linux, you may need to add your user to the `docker` group:

```bash
sudo usermod -aG docker $USER
newgrp docker
```
