# MemeGenerator — Backend

Laravel REST API for the MemeGenerator app. Handles meme creation, storage and gallery.

## Tech Stack

- **Laravel 12** — PHP 8.3
- **MySQL** — database
- **Cloudinary** — image storage (`cloudinary/cloudinary_php`)

## Features

- `POST /api/generate` — upload and save a meme (limit: 5 per session)
- `GET  /api/memes` — paginated gallery with search
- `GET  /api/session` — current session usage
- `GET  /share/{id}` — Open Graph page for social sharing
- `DELETE /api/deleteMeme` — delete a meme (dev only)

## Getting Started

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan serve
```

## Environment Variables

| Variable                | Description                        |
|-------------------------|------------------------------------|
| `DB_*`                  | MySQL connection                   |
| `CLOUDINARY_CLOUD_NAME` | Cloudinary cloud name              |
| `CLOUDINARY_API_KEY`    | Cloudinary API key                 |
| `CLOUDINARY_API_SECRET` | Cloudinary API secret              |
| `FRONTEND_URL`          | React app URL (CORS + OG redirect) |
| `SESSION_DRIVER`        | Use `cookie` in production         |

## Deployment (Render)

The repo includes a `Dockerfile`. Set all environment variables in the Render dashboard and deploy via the Docker runtime.
