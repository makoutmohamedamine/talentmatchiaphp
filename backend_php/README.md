# Backend PHP — TalentMatch IA

API REST : auth JWT, candidats, postes, analyse CV Groq, scoring, chat RH, synchronisation Outlook (`cv@colorado.ma`).

## Prerequis

- PHP 8.3+ : `curl`, `mbstring`, `pdo_mysql`, `zip`
- MySQL / MariaDB
- Python + PyMuPDF (optionnel, extraction PDF)

## Installation

1. Importer `database/mysql_schema.sql` dans MySQL (base `newpro`)
2. `copy .env.example .env` et configurer :

```env
DB_NAME=newpro
DB_USER=root
DB_PASSWORD=
GROQ_API_KEY=gsk_...
OUTLOOK_MAILBOX=cv@colorado.ma
AZURE_TENANT_ID=...
AZURE_CLIENT_ID=...
AZURE_CLIENT_SECRET=...
MEDIA_ROOT=media
```

3. Demarrer :

```powershell
php -S 127.0.0.1:8001 -t public
```

## Endpoints principaux

- Auth : `/api/auth/login/`, `/api/auth/me/`, …
- Candidats : `/api/candidates/`, `/api/candidates/upload/`
- IA : `/api/ai/analyse/`, `/api/ai/score/`, `/api/chat/ask/`
- Outlook : `/api/outlook/status/`, `/api/outlook/sync/`
