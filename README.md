# EventReservation

EventReservation is a Symfony application for publishing events and handling reservations with modern authentication:
- Passkey (WebAuthn) sign-up/sign-in
- JWT access tokens
- Admin back-office APIs for event management
- Reservation emails (confirmation/waitlist + promotion notifications)

The app is designed to run with Docker (PHP-FPM + Nginx + PostgreSQL).

## Tech Stack

- PHP 8.4 (Docker image), Symfony 7.4
- Doctrine ORM + Doctrine Migrations
- PostgreSQL 15
- LexikJWTAuthenticationBundle
- GesdinetJWTRefreshTokenBundle
- web-auth/webauthn-lib + web-auth/webauthn-symfony-bundle
- Symfony Mailer + Mailpit (local SMTP inbox in Docker)
- Twig + vanilla JS frontend

## Project Structure

- `src/Controller`: public pages, auth APIs, admin APIs
- `src/Entity`: Event, Reservation, User, WebauthnCredential
- `src/Service/PasskeyAuthService.php`: WebAuthn registration/login flow
- `src/Service/ReservationNotificationService.php`: reservation email notifications
- `config/packages`: security, doctrine, jwt, webauthn settings
- `migrations`: database migrations
- `templates`: Twig pages (events, login, admin)
- `public/js/auth.js`: browser auth helpers (passkey, jwt, refresh)

## Prerequisites

- Docker + Docker Compose
- OpenSSL (for JWT key generation if keys are missing)

## Quick Start (Docker)

Run all commands from the `EventReservation` directory.

1. Build and start containers

```bash
docker compose up -d --build
```

2. Install PHP dependencies (inside PHP container)

```bash
docker compose exec -T php composer install
```

3. Ensure JWT keys exist

```bash
ls config/jwt/private.pem config/jwt/public.pem
```

If missing, generate them:

```bash
mkdir -p config/jwt
openssl genpkey -algorithm RSA -out config/jwt/private.pem -aes256 -pass pass:change_this_passphrase -pkeyopt rsa_keygen_bits:4096
openssl pkey -in config/jwt/private.pem -out config/jwt/public.pem -pubout -passin pass:change_this_passphrase
```

Then set the same passphrase in environment (for example `.env.local`):

```dotenv
JWT_PASSPHRASE=change_this_passphrase
APP_DOMAIN=localhost
MAILER_DSN=smtp://mailer:1025
MAILER_FROM_ADDRESS=no-reply@eventreservation.local
```

4. Run database migrations

```bash
docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction
```

5. Open the app

- Frontend: http://localhost:8080
- Login (Passkey): http://localhost:8080/login
- Admin UI: http://localhost:8080/admin
- Mail inbox (Mailpit): http://localhost:8025

## Day-to-Day Commands

### Container and service checks

```bash
docker compose ps
docker compose logs -f nginx
docker compose logs -f php
docker compose logs -f db
docker compose logs -f mailer
```

### Symfony/Doctrine commands

```bash
docker compose exec -T php php bin/console about
docker compose exec -T php php bin/console debug:router
docker compose exec -T php php bin/console doctrine:migrations:status
docker compose exec -T php php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec -T php php bin/console cache:clear
docker compose exec -T php php bin/console mailer:test user@example.com --from=no-reply@eventreservation.local --subject="SMTP check" --body="Mailer is working"
```

### Composer

```bash
docker compose exec -T php composer install
docker compose exec -T php composer update
```

### Promote a user to admin

```bash
docker compose exec -T php php bin/console app:promote-admin user@example.com
```


## API Overview

### Auth API (`/api/auth`)

- `POST /register/options`
- `POST /register/verify`
- `POST /login/options`
- `POST /login/verify`
- `POST /refresh`

### Admin API (`/api/admin`)

- `GET /data`
- `POST /event`
- `PUT|PATCH /event/{id}`
- `DELETE /event/{id}`

## Data Model

- `Event`: title, description, date, location, seats, image, subscription window
- `Reservation`: event, name, email, phone, createdAt, status, claimExpiresAt, claimedAt
- `User`: UUID id, email, roles
- `WebauthnCredential`: serialized credential source linked to user
- `refresh_tokens` table from Gesdinet bundle migration

## Functional Notes

- Event booking is blocked when:
  - user is not authenticated
  - current time is outside subscription window
  - required booking fields are missing
- Booking behavior:
  - if seats are available, reservation is created as `confirmed` and seats are decremented
  - if seats are 0, reservation is created as `waitlisted`
- A reservation email is sent after each booking (confirmed or waitlisted).
- When an admin cancels a confirmed reservation, the next waitlisted user can be promoted with a claim deadline email.
- Admin delete is blocked when reservations already exist for the event.
