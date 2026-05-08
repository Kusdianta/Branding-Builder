# Branding Builder — AI-Powered Brand Kit & KOL Discovery Platform

> A Laravel 13 web application that generates complete brand activation kits using Claude AI and discovers Instagram influencers (KOLs) for Indonesian businesses.

---

## Table of Contents

- [Overview](#overview)
- [Features](#features)
- [Architecture](#architecture)
- [Tech Stack](#tech-stack)
- [Project Structure](#project-structure)
- [API Integrations](#api-integrations)
- [Database Schema](#database-schema)
- [Queue & Async Processing](#queue--async-processing)
- [Routes Reference](#routes-reference)
- [Controllers & Services](#controllers--services)
- [Frontend](#frontend)
- [Environment Variables](#environment-variables)
- [Installation](#installation)
- [Development Workflow](#development-workflow)
- [Deployment](#deployment)
- [PDF & Excel Export](#pdf--excel-export)
- [KOL Tier Classification](#kol-tier-classification)
- [Security Considerations](#security-considerations)
- [License](#license)

---

## Overview

**Branding Builder** is a full-stack web application built for Indonesian small and medium businesses (UKM) who need professional brand identity without a dedicated marketing team. It has two core modules:

1. **Brand Kit Generator** — Takes 7 business inputs and uses Anthropic Claude (`claude-sonnet-4-6`) to produce a complete brand activation kit: positioning narratives, brand pillars, content mapping, brand voice, and ready-to-post caption templates.

2. **KOL Discovery** — Finds Instagram influencers matching a business's city, niche, and budget tier via Apify's Instagram hashtag scraper. Results are filterable, engagement-scored, and exportable to styled Excel reports.

---

## Features

### Brand Kit Generator
- Input form: business name, city, service type, target customer, differentiators, brand personality, price segment
- Async processing via Laravel Queue (no UI freezing on slow AI calls)
- Polling-based progress feedback (no WebSocket needed)
- Structured JSON output from Claude covering:
  - Brand positioning narratives
  - Brand pillars
  - Content strategy mapping
  - Brand voice & tone guidelines
  - Platform-specific caption templates (Instagram, TikTok, etc.)
- One-click PDF download (landscape A4, styled report)
- 2-hour result caching

### KOL Discovery
- Search by city + niche + follower range
- Auto-generates hashtag combinations for Instagram scraping
- Classifies influencers into tiers: Nano, Micro, Mid-tier, Macro
- Calculates engagement rate: `(avgLikes + avgComments) / followers × 100`
- Location filtering using Indonesian keyword detection in bio/captions
- Deduplication by username
- Styled Excel export with:
  - Tier-based cell color coding
  - Green header row, alternating body rows
  - Frozen header, optimized column widths
  - Timestamp & title metadata rows

---

## Architecture

```
┌─────────────────────────────────────────────────────────────────┐
│                         Browser (Blade + Tailwind)               │
│   Home Form ──► Loading Page (polling) ──► Results ──► PDF DL   │
│   KOL Form ──► Loading Page (polling) ──► KOL Results ──► Excel │
└──────────────────────────┬──────────────────────────────────────┘
                           │ HTTP
┌──────────────────────────▼──────────────────────────────────────┐
│                      Laravel 13 App Server                       │
│  ┌────────────────┐  ┌────────────────┐  ┌───────────────────┐  │
│  │BrandKitController│ │ KolController  │  │   Route Layer     │  │
│  └───────┬────────┘  └───────┬────────┘  └───────────────────┘  │
│          │                   │                                    │
│  ┌───────▼────────┐  ┌───────▼────────┐                          │
│  │  ClaudeService │  │  Apify HTTP    │                          │
│  │ (Anthropic SDK)│  │   Client       │                          │
│  └───────┬────────┘  └───────┬────────┘                          │
│          │                   │                                    │
│  ┌───────▼────────┐  ┌───────▼────────┐                          │
│  │GenerateBrandKit│  │  KolExport     │                          │
│  │   (Queue Job)  │  │ (Excel/DomPDF) │                          │
│  └───────┬────────┘  └────────────────┘                          │
│          │                                                        │
│  ┌───────▼────────────────────────────┐                          │
│  │         Cache (Database Driver)    │                          │
│  │  token → {status, data, error}     │                          │
│  └────────────────────────────────────┘                          │
└─────────────────────────────────────────────────────────────────┘
         │                         │
┌────────▼────────┐       ┌────────▼────────┐
│  Anthropic API  │       │   Apify API     │
│ claude-sonnet-  │       │ instagram-      │
│ 4-6             │       │ hashtag-scraper │
└─────────────────┘       └─────────────────┘
```

### Request Lifecycle — Brand Kit

```
POST /generate
  └─► Validate input (7 fields)
  └─► Generate UUID token
  └─► Cache token → "pending"
  └─► Dispatch GenerateBrandKit job
  └─► [Windows] Spawn PHP queue worker via popen()
  └─► Redirect to /loading?token=xxx

GET /status?token=xxx  (polled every 2s by frontend JS)
  └─► Return JSON: { status: "pending|done|error", data?, error? }

GET /results
  └─► Read brand kit from session
  └─► Render Blade view with structured data

GET /download
  └─► Render PDF via DomPDF (landscape A4)
  └─► Stream as file download
```

### Request Lifecycle — KOL Discovery

```
POST /kol/search
  └─► Build hashtag combinations (city × niche × variants)
  └─► Start Apify actor run via HTTP POST
  └─► Store runId in session
  └─► Return JSON: { runId }

GET /kol/status?runId=xxx  (polled by frontend)
  └─► Poll Apify run status endpoint
  └─► Return: { status: "RUNNING|SUCCEEDED|FAILED" }

GET /kol/results
  └─► Fetch dataset items from Apify
  └─► Deduplicate by username
  └─► Filter by follower range
  └─► Filter by Indonesian location keywords
  └─► Calculate engagement rates
  └─► Classify into tiers
  └─► Store in session
  └─► Render KOL results view

POST /kol/export
  └─► Read KOL data from session
  └─► Build styled Excel via Maatwebsite/Excel
  └─► Stream as .xlsx download
```

---

## Tech Stack

### Backend
| Component | Technology | Version |
|-----------|-----------|---------|
| Framework | Laravel | ^13.0 |
| Language | PHP | ^8.3 |
| AI SDK | anthropic-ai/sdk | ^0.8.0 |
| PDF Generation | barryvdh/laravel-dompdf | ^3.1 |
| Excel Export | maatwebsite/excel | ^3.1 |
| Code Style | Laravel Pint | ^1.27 |
| Testing | PHPUnit | ^12.5 |
| Log Viewer | Laravel Pail | ^1.2.5 |

### Frontend
| Component | Technology | Version |
|-----------|-----------|---------|
| Build Tool | Vite | ^8.0.0 |
| CSS Framework | Tailwind CSS | ^4.0.0 |
| Templates | Laravel Blade | — |
| HTTP Client | Axios | ^1.11.0 |
| Dev Runner | Concurrently | ^9.0.1 |

### Infrastructure
| Component | Technology |
|-----------|-----------|
| Database | SQLite (local) / MySQL (production) |
| Cache | Database driver |
| Queue | Database driver |
| Session | Database driver |
| Local Dev | Laravel Herd (Windows) |

### External APIs
| Service | Purpose |
|---------|---------|
| Anthropic Claude API | Brand kit AI generation |
| Apify Instagram Scraper | KOL discovery & profile data |

---

## Project Structure

```
brandkit-ai/
├── app/
│   ├── Exports/
│   │   └── KolExport.php              # Styled Excel export for KOL data
│   ├── Http/
│   │   └── Controllers/
│   │       ├── BrandKitController.php # Brand kit generation flow
│   │       └── KolController.php      # KOL discovery & export
│   ├── Jobs/
│   │   └── GenerateBrandKit.php       # Async queue job for Claude API call
│   ├── Models/
│   │   └── User.php                   # Standard Laravel user model
│   ├── Providers/
│   │   └── AppServiceProvider.php     # Service container bindings
│   └── Services/
│       └── ClaudeService.php          # Anthropic Claude API wrapper
├── bootstrap/
│   ├── app.php                        # Application bootstrap
│   └── providers.php                  # Provider registration
├── config/
│   ├── app.php                        # App config (name, timezone, etc.)
│   ├── auth.php                       # Authentication guards
│   ├── cache.php                      # Cache stores (database)
│   ├── database.php                   # Database connections (SQLite/MySQL)
│   ├── mail.php                       # Mailer config
│   ├── queue.php                      # Queue connections (database)
│   ├── services.php                   # Third-party API credentials
│   └── session.php                    # Session driver (database)
├── database/
│   ├── migrations/
│   │   ├── 0001_01_01_000000_create_users_table.php
│   │   ├── 0001_01_01_000001_create_cache_table.php
│   │   └── 0001_01_01_000002_create_jobs_table.php
│   └── seeders/
│       └── DatabaseSeeder.php
├── public/
│   ├── index.php                      # Web entry point
│   └── build/                         # Compiled Vite assets
├── resources/
│   ├── css/
│   │   └── app.css                    # Tailwind CSS entry
│   ├── js/
│   │   └── app.js                     # JS entry (Axios etc.)
│   └── views/
│       ├── home.blade.php             # Brand kit input form
│       ├── kol.blade.php              # KOL search form
│       ├── loading.blade.php          # Polling loading screen
│       ├── results.blade.php          # Brand kit results display
│       ├── welcome.blade.php          # Default Laravel landing
│       ├── layouts/
│       │   └── app.blade.php          # Master layout template
│       └── pdf/
│           └── brand-kit.blade.php    # PDF-specific Blade template
├── routes/
│   ├── web.php                        # All application routes
│   └── console.php                    # Artisan command routes
├── storage/
│   ├── app/                           # App file storage
│   ├── framework/                     # Cache, sessions, views
│   └── logs/                          # Laravel logs (laravel.log)
├── tests/
│   ├── Feature/                       # HTTP/integration tests
│   └── Unit/                          # Unit tests
├── .env.example                       # Environment variable template
├── artisan                            # Laravel CLI entry point
├── composer.json                      # PHP dependency manifest
├── package.json                       # Node.js dependency manifest
├── phpunit.xml                        # PHPUnit configuration
└── vite.config.js                     # Vite bundler configuration
```

---

## API Integrations

### Anthropic Claude API

**Service:** `app/Services/ClaudeService.php`

- **Model:** `claude-sonnet-4-6`
- **Max tokens:** 4096
- **SDK:** `anthropic-ai/sdk` PHP package

**Prompt input fields:**
| Field | Description |
|-------|-------------|
| `nama_bisnis` | Business name |
| `kota` | City/location |
| `layanan` | Service or product type |
| `pelanggan` | Target customer description |
| `keunggulan` | Business differentiators |
| `kepribadian` | Brand personality |
| `segmen_harga` | Price segment (budget/mid/premium) |

**Claude output structure (JSON):**
```json
{
  "narasi": "Brand positioning statement",
  "pilar": ["Pillar 1", "Pillar 2", "Pillar 3"],
  "pemetaan_konten": {
    "edukasi": ["content idea 1", "content idea 2"],
    "hiburan": ["content idea 1"],
    "promosi": ["content idea 1"]
  },
  "suara_merek": {
    "tone": "friendly/professional/etc",
    "kata_kunci": ["keyword1", "keyword2"]
  },
  "caption": {
    "instagram": "Ready-to-post IG caption",
    "tiktok": "Ready-to-post TikTok caption"
  }
}
```

**Configuration:**
```php
// config/services.php
'anthropic' => [
    'key' => env('ANTHROPIC_API_KEY'),
],
```

---

### Apify Instagram Hashtag Scraper

**Controller:** `app/Http/Controllers/KolController.php`

- **Actor:** `apify~instagram-hashtag-scraper`
- **Base URL:** `https://api.apify.com/v2`

**Workflow:**

1. **Start run** — `POST /acts/apify~instagram-hashtag-scraper/runs`
   - Body: `{ hashtags: [...], resultsLimit: 50 }`
   - Returns: `{ data: { id: "runId" } }`

2. **Poll status** — `GET /actor-runs/{runId}`
   - Returns: `{ data: { status: "RUNNING|SUCCEEDED|FAILED", defaultDatasetId } }`

3. **Fetch results** — `GET /datasets/{defaultDatasetId}/items`
   - Returns array of Instagram profile objects

**Hashtag generation strategy:**
```
city × niche combinations:
  ["jakarta_kuliner", "jakarta_food", "kuliner_jakarta",
   "jakartatimur_kuliner", "jakartakuliner", ...]
```

**Raw profile fields used:**
| Field | Usage |
|-------|-------|
| `username` | Dedup key, profile link |
| `fullName` | Display name |
| `followersCount` | Tier classification + filter |
| `followingCount` | Displayed in export |
| `postsCount` | Displayed in export |
| `biography` | Location keyword filtering |
| `avgLikes` | Engagement rate calculation |
| `avgComments` | Engagement rate calculation |
| `isVerified` | Badge in UI |
| `externalUrl` | Contact link |

**Configuration:**
```php
// config/services.php
'apify' => [
    'token' => env('APIFY_API_TOKEN'),
],
```

---

## Database Schema

The app uses SQLite locally and supports MySQL in production. All three tables are created via Laravel migrations.

### `users`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint (PK) | Auto-increment |
| `name` | varchar(255) | |
| `email` | varchar(255) | Unique |
| `email_verified_at` | timestamp | Nullable |
| `password` | varchar(255) | bcrypt hashed |
| `remember_token` | varchar(100) | Nullable |
| `created_at` | timestamp | |
| `updated_at` | timestamp | |

### `cache`
| Column | Type | Notes |
|--------|------|-------|
| `key` | varchar(255) | Primary key |
| `value` | mediumtext | Serialized value |
| `expiration` | int | Unix timestamp |

### `jobs`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint (PK) | Auto-increment |
| `queue` | varchar(255) | Queue name |
| `payload` | longtext | Serialized job |
| `attempts` | tinyint | Retry count |
| `reserved_at` | int | Nullable Unix timestamp |
| `available_at` | int | Unix timestamp |
| `created_at` | int | Unix timestamp |

### Cache Keys (Application-Level)

| Key Pattern | TTL | Contents |
|-------------|-----|---------|
| `brand_kit_{token}` | 7200s (2h) | `{ status: "pending\|done\|error", data?: {...}, error?: "..." }` |

---

## Queue & Async Processing

### Why Queues?

Claude API calls can take 5–30 seconds. Running them synchronously in a web request would time out. The queue system decouples the HTTP response from the AI processing.

### Flow

```
HTTP Request
    └─► Controller validates & dispatches job
    └─► Job stored in `jobs` database table
    └─► Worker picks up job from table
    └─► Worker calls Claude API
    └─► Worker stores result in cache
    └─► Frontend polling detects "done" status
```

### Queue Configuration

```
QUEUE_CONNECTION=database
```

**Job class:** `app/Jobs/GenerateBrandKit.php`
- **Timeout:** 120 seconds
- **Max tries:** 1
- **On success:** Stores `{ status: "done", data: {...} }` in cache
- **On failure:** Stores `{ status: "error", error: "message" }` in cache

### Running the Worker

**Linux/Production:**
```bash
php artisan queue:work --timeout=120
# or one-shot cron:
* * * * * php artisan queue:work --once
```

**Windows (Herd) — Automatic:**

The controller auto-spawns a PHP worker process via `popen()` when a job is dispatched. No manual step needed.

---

## Routes Reference

### Web Routes (`routes/web.php`)

#### Brand Kit Module

| Method | URI | Controller | Description |
|--------|-----|-----------|-------------|
| GET | `/` | `BrandKitController@index` | Home page with input form |
| POST | `/generate` | `BrandKitController@generate` | Submit form, dispatch job |
| GET | `/loading` | `BrandKitController@loading` | Loading/polling page |
| GET | `/status` | `BrandKitController@status` | JSON: check job status |
| GET | `/results` | `BrandKitController@results` | Display brand kit results |
| GET | `/download` | `BrandKitController@download` | Download brand kit as PDF |

**Query parameters:**
- `/loading?token=uuid` — UUID token for this generation request
- `/status?token=uuid` — Same token, returns JSON status

#### KOL Discovery Module

| Method | URI | Controller | Description |
|--------|-----|-----------|-------------|
| GET | `/kol` | `KolController@index` | KOL search form |
| POST | `/kol/search` | `KolController@search` | Start Apify scraper |
| GET | `/kol/status` | `KolController@status` | JSON: poll Apify run |
| GET | `/kol/results` | `KolController@results` | Display KOL results |
| POST | `/kol/export` | `KolController@export` | Download Excel report |

---

## Controllers & Services

### `BrandKitController`

**File:** `app/Http/Controllers/BrandKitController.php`

| Method | HTTP | Purpose |
|--------|------|---------|
| `index()` | GET `/` | Renders the home form |
| `generate()` | POST `/generate` | Validates 7 input fields, creates UUID token, caches `pending` state, dispatches `GenerateBrandKit` job, spawns worker (Windows), redirects to loading page |
| `loading()` | GET `/loading` | Renders polling loading page with token in template |
| `status()` | GET `/status` | Reads cache by token, returns JSON `{ status, data?, error? }` |
| `results()` | GET `/results` | Reads brand kit from session, passes to Blade view |
| `download()` | GET `/download` | Renders PDF template via DomPDF, streams as file download |

**Validation rules:**
```php
'nama_bisnis' => 'required|string|max:255',
'kota'        => 'required|string|max:255',
'layanan'     => 'required|string|max:255',
'pelanggan'   => 'required|string|max:500',
'keunggulan'  => 'required|string|max:500',
'kepribadian' => 'required|string|max:255',
'segmen_harga'=> 'required|in:budget,menengah,premium',
```

---

### `KolController`

**File:** `app/Http/Controllers/KolController.php`

| Method | HTTP | Purpose |
|--------|------|---------|
| `index()` | GET `/kol` | Renders KOL search form |
| `search()` | POST `/kol/search` | Builds hashtag list, starts Apify actor, returns `{ runId }` |
| `status()` | GET `/kol/status` | Polls Apify run endpoint, returns status string |
| `results()` | GET `/kol/results` | Fetches dataset, deduplicates, filters, scores, classifies, stores in session, renders view |
| `export()` | POST `/kol/export` | Reads session KOL data, builds `KolExport`, streams as Excel download |
| `getTier()` | private | Classifies follower count → Nano/Micro/Mid/Macro |

**KOL Engagement Rate Formula:**
```
engagementRate = ((avgLikes + avgComments) / followersCount) * 100
```

**Location Filter:**
Checks if profile bio or recent caption text contains any Indonesian keyword:
```
["indonesia", "indo", "jakarta", "bandung", "surabaya",
 "yogyakarta", "medan", "semarang", "makassar", "bali", ...]
```

---

### `ClaudeService`

**File:** `app/Services/ClaudeService.php`

Single public method: `generateBrandKit(array $data): array`

**Steps:**
1. Builds structured Indonesian-language prompt with business data
2. Calls `client->messages()->create()` with `claude-sonnet-4-6`
3. Parses response JSON
4. Returns structured array

**Error handling:**
- Throws exception on API error
- `GenerateBrandKit` job catches and stores error state in cache

---

### `GenerateBrandKit` (Queue Job)

**File:** `app/Jobs/GenerateBrandKit.php`

**Constructor params:** `string $token, array $data`

**`handle()` method:**
1. Resolves `ClaudeService` from container
2. Calls `generateBrandKit($data)`
3. On success: `Cache::put("brand_kit_{$token}", ['status' => 'done', 'data' => $result], 7200)`
4. On failure: `Cache::put("brand_kit_{$token}", ['status' => 'error', 'error' => $e->getMessage()], 7200)`

---

### `KolExport`

**File:** `app/Exports/KolExport.php`

Implements `FromCollection`, `WithHeadings`, `WithStyles`, `ShouldAutoSize`, `WithColumnWidths`, `WithTitle`.

**Columns (15 total):**
1. Username
2. Full Name
3. Tier
4. Followers
5. Following
6. Posts
7. Avg Likes
8. Avg Comments
9. Engagement Rate (%)
10. Bio
11. External URL
12. Verified
13. Instagram URL
14. Posts Found

**Styling:**
- Row 1: Title row (`"KOL Report — {timestamp}"`)
- Row 2: Empty separator
- Row 3: Column headers (green background `#16a34a`, white text, bold)
- Row 4+: Data rows with alternating `#f0fdf4` / white
- Tier cell colors:
  - Macro (100k+): `#fef9c3` (yellow)
  - Mid-tier (10k+): `#dcfce7` (green)
  - Micro (1k+): `#dbeafe` (blue)
  - Nano (<1k): `#f3e8ff` (purple)

---

## Frontend

### Blade Views

| View | Route | Description |
|------|-------|-------------|
| `home.blade.php` | GET `/` | 7-field input form, client-side validation |
| `loading.blade.php` | GET `/loading` | Spinner + JS polling every 2s via Axios |
| `results.blade.php` | GET `/results` | Structured brand kit display with all AI output |
| `kol.blade.php` | GET `/kol` | Search form + JS polling for Apify run |
| `layouts/app.blade.php` | — | Master layout: head, nav, Vite assets |
| `pdf/brand-kit.blade.php` | GET `/download` | PDF-specific layout (no nav, print styles) |

### Vite Configuration

**File:** `vite.config.js`

```js
import { defineConfig } from 'vite'
import laravel from 'laravel-vite-plugin'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig({
  plugins: [
    laravel({ input: ['resources/css/app.css', 'resources/js/app.js'], refresh: true }),
    tailwindcss(),
  ],
})
```

### Polling Pattern (JS)

Both loading screens use the same pattern:

```js
const poll = async () => {
  const res = await axios.get('/status', { params: { token } })
  if (res.data.status === 'done') {
    window.location.href = '/results'
  } else if (res.data.status === 'error') {
    showError(res.data.error)
  } else {
    setTimeout(poll, 2000)
  }
}
poll()
```

---

## Environment Variables

Copy `.env.example` to `.env` and fill in:

```env
# Application
APP_NAME=BrandKit-AI
APP_ENV=local
APP_KEY=                        # Generated by: php artisan key:generate
APP_DEBUG=true
APP_URL=http://brandkit-ai.test

# Database
DB_CONNECTION=sqlite
# DB_CONNECTION=mysql           # For production
# DB_HOST=127.0.0.1
# DB_PORT=3306
# DB_DATABASE=brandkit_ai
# DB_USERNAME=root
# DB_PASSWORD=

# Session / Cache / Queue (all use database by default)
SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

# Mail (log driver for local dev)
MAIL_MAILER=log

# === REQUIRED THIRD-PARTY KEYS ===

# Anthropic Claude API
ANTHROPIC_API_KEY=sk-ant-api03-...

# Apify (Instagram KOL scraper)
APIFY_API_TOKEN=apify_api_...

# Optional / reserved
RAPIDAPI_KEY=
```

---

## Installation

### Prerequisites

- PHP 8.3+
- Composer 2.x
- Node.js 20+ & npm
- SQLite (bundled with PHP) or MySQL 8+
- [Laravel Herd](https://herd.laravel.com/) (Windows/macOS) or Valet (macOS) or any PHP dev server

### Quick Start

```bash
# 1. Clone the repository
git clone https://github.com/Kusdianta/Branding-Builder.git
cd Branding-Builder

# 2. Run the automated setup script
composer run setup
```

The `setup` script does:
- `composer install`
- `cp .env.example .env`
- `php artisan key:generate`
- `php artisan migrate`
- `npm install`
- `npm run build`

**Then** open `.env` and fill in:
- `ANTHROPIC_API_KEY`
- `APIFY_API_TOKEN`

### Manual Setup (step by step)

```bash
# Install PHP dependencies
composer install

# Copy environment file
cp .env.example .env

# Generate application key
php artisan key:generate

# Run database migrations
php artisan migrate

# Install Node dependencies
npm install

# Build frontend assets
npm run build
```

---

## Development Workflow

### Start All Services

```bash
composer run dev
```

This runs 4 processes concurrently:
1. `php artisan serve` — Laravel dev server (http://localhost:8000)
2. `php artisan queue:listen` — Queue worker for async jobs
3. `php artisan pail` — Real-time log viewer
4. `npm run dev` — Vite HMR dev server

### Run Tests

```bash
composer run test
# or
php artisan test
# or directly
vendor/bin/phpunit
```

### Code Formatting (Pint)

```bash
vendor/bin/pint
```

### Useful Artisan Commands

```bash
# Clear all caches
php artisan optimize:clear

# View queue jobs
php artisan queue:monitor

# Failed jobs
php artisan queue:failed

# Retry failed jobs
php artisan queue:retry all

# Flush all cache
php artisan cache:clear

# Fresh migration (wipes data)
php artisan migrate:fresh
```

---

## Deployment

### Linux VPS / Shared Hosting

```bash
# 1. Upload project files
# 2. Install dependencies (no dev)
composer install --no-dev --optimize-autoloader

# 3. Build frontend
npm install && npm run build

# 4. Set environment
cp .env.example .env
php artisan key:generate

# 5. Set storage permissions
chmod -R 775 storage bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

# 6. Run migrations
php artisan migrate --force

# 7. Optimize
php artisan config:cache
php artisan route:cache
php artisan view:cache

# 8. Set up queue worker (cron)
* * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
# Or persistent worker via Supervisor:
php artisan queue:work --sleep=3 --tries=1 --timeout=120
```

### Supervisor Configuration (Linux)

```ini
[program:brandkit-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/brandkit-ai/artisan queue:work database --sleep=3 --tries=1 --timeout=120
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/brandkit-ai/storage/logs/worker.log
```

### MySQL Production Config

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=brandkit_ai
DB_USERNAME=your_user
DB_PASSWORD=your_password
```

---

## PDF & Excel Export

### PDF (Brand Kit)

- **Library:** `barryvdh/laravel-dompdf` ^3.1
- **Paper:** A4 landscape
- **Template:** `resources/views/pdf/brand-kit.blade.php`
- **Filename:** `brand-kit-{slug(business_name)}.pdf`
- **Trigger:** GET `/download`

### Excel (KOL Report)

- **Library:** `maatwebsite/excel` ^3.1 (wraps PhpOffice/PhpSpreadsheet)
- **Format:** `.xlsx`
- **Filename:** `kol-report-{timestamp}.xlsx`
- **Trigger:** POST `/kol/export`
- **Features:** 15 columns, color-coded tiers, frozen header, auto-sized columns

---

## KOL Tier Classification

| Tier | Followers | Cell Color |
|------|-----------|-----------|
| Macro | >= 100,000 | Yellow `#fef9c3` |
| Mid-tier | 10,000 - 99,999 | Green `#dcfce7` |
| Micro | 1,000 - 9,999 | Blue `#dbeafe` |
| Nano | < 1,000 | Purple `#f3e8ff` |

**Engagement Rate Benchmarks (Instagram):**
- > 6% — Excellent
- 3-6% — Good
- 1-3% — Average
- < 1% — Low

---

## Security Considerations

- **No secrets in code** — All API keys via `.env`, never committed
- **Input validation** — All form fields validated via Laravel rules before processing
- **CSRF protection** — Laravel's built-in CSRF tokens on all POST forms
- **SQL injection** — Using Eloquent ORM and parameterized queries throughout
- **Session isolation** — Brand kit and KOL results stored per-session, not globally
- **Cache TTL** — AI results auto-expire after 2 hours; no stale data persists indefinitely
- **Queue isolation** — AI calls run in worker processes, not web processes; failures don't affect the HTTP layer
- **Error masking** — Internal exceptions mapped to user-friendly messages; stack traces never sent to browser

---

## License

This project is proprietary software. All rights reserved.

&copy; 2026 Branding Builder. Built with Laravel 13 + Anthropic Claude.
