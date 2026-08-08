# Job App

Job-seeker facing application for the job portal platform, built with **Laravel 12**. Job seekers browse open vacancies, apply with a résumé, and get an **AI-generated compatibility score and feedback** — powered by Google Gemini — without ever waiting on the API call, thanks to a fully asynchronous processing pipeline.

This repository is one of three that make up the platform:

| Repo | Role |
|---|---|
| **job-app** *(this repo)* | Public-facing app for job seekers |
| [job-backoffice](https://github.com/YoussefSayed-cs/job-backoffice) | Admin & company-owner dashboard |
| [job-shared](https://github.com/YoussefSayed-cs/job-shared) | Shared Eloquent models/notifications used by both apps |

---

## Features

- **Job search & discovery** — searchable, filterable vacancy listing (by keyword, location, company, and job type) with pagination.
- **One-click apply with an existing résumé, or upload a new one** — supports re-using a previously uploaded résumé or uploading a new PDF on the spot.
- **AI-powered résumé screening** — uploaded PDFs are parsed and scored against the job description by Google Gemini, running fully in the background via a queued job:
  - The application is saved and a response returned to the user immediately (`status: pending`, no blocking on the AI call).
  - A queued job (`ProcessResumeAnalysis`) extracts structured résumé data (contact info, education, experience, skills) and then generates a compatibility score + written feedback for the specific vacancy.
  - Automatic retries with backoff on rate limits (`429`) or upstream errors (`503`); a safe fallback result is stored if analysis ultimately fails, so an application is never left stuck.
- **Applicant dashboard** — job seekers track the status (pending / accepted / rejected) and AI feedback for every application they've submitted.
- **Notifications** — company owners and admins are notified when a new application comes in.

## Tech Stack

- **Backend:** Laravel 12, PHP ^8.2
- **Frontend:** Blade, Tailwind CSS, Alpine.js, Vite
- **Database:** MariaDB/MySQL, UUID primary keys throughout
- **Auth:** Laravel Breeze (session-based)
- **AI:** Google Gemini (`google-gemini-php/laravel`)
- **PDF parsing:** `smalot/pdfparser`
- **Storage:** S3-compatible cloud storage (`league/flysystem-aws-s3-v3`, `aws/aws-sdk-php`)
- **Queues:** Database-backed queue for background résumé analysis
- **Testing:** Pest
- **Shared domain layer:** [`job/shared`](https://github.com/YoussefSayed-cs/job-shared) Composer package (Eloquent models & notifications)

## How the AI scoring pipeline works

```
User applies (PDF résumé)
        │
        ▼
JobVacancyController::processApplications()
   ├─ saves the Application immediately (status: pending, score: 0)
   └─ dispatches ProcessResumeAnalysis (queued)
                    │
                    ▼
        ResumesAnalysisServices
   ├─ 1st Gemini call → extract résumé data from the PDF
   └─ 2nd Gemini call → score the résumé against the job description
                    │
                    ▼
        Application updated with aiGeneratedScore + aiGeneratedFeedback
```

The user gets an instant response ("Your application has been submitted — AI evaluation is in progress") while the scoring happens in the background.

---

## Getting Started

### Prerequisites

- PHP >= 8.2
- Composer
- Node.js & npm
- MariaDB/MySQL
- A Google Gemini API key

### Installation

```bash
git clone https://github.com/YoussefSayed-cs/job-app.git
cd job-app

composer install
cp .env.example .env
php artisan key:generate

# configure your database and GEMINI_API_KEY in .env, then:
php artisan migrate

npm install
npm run build
```

### Running locally

```bash
composer dev
```
This runs the PHP server, queue listener (required for résumé analysis to process), log viewer (`pail`), and Vite dev server together. **The queue worker must be running** for AI scoring to happen — without it, applications will stay at `pending` indefinitely.

### ⚠️ Important — shared database

This app **does not ship migrations for the core domain tables** (users, companies, job vacancies, applications, résumés) — only the internal `jobs` queue table. It relies entirely on the shared models from `job/shared`, so its `.env` must point at the **same database** that [job-backoffice](https://github.com/YoussefSayed-cs/job-backoffice)'s migrations created (same `DB_HOST`/`DB_DATABASE`/credentials). Run job-backoffice's migrations first.

### Key environment variables

| Variable | Purpose |
|---|---|
| `DB_*` | Database connection — must match job-backoffice's `.env` (see note above) |
| `GEMINI_API_KEY` | Google Gemini API key, used for résumé parsing & scoring |
| `AWS_*` | S3-compatible bucket for résumé storage, shared with job-backoffice |
| `QUEUE_CONNECTION` | Queue driver (`database` by default) — must be running for AI scoring |

## Testing

```bash
composer test
```

## Related Repositories

- [job-backoffice](https://github.com/YoussefSayed-cs/job-backoffice) — admin & company-owner management console
- [job-shared](https://github.com/YoussefSayed-cs/job-shared) — shared models and notifications package

## License

MIT
