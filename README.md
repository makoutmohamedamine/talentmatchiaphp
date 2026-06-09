# TalentMatch IA — Backend PHP

Backend PHP 8.3 natif pour l'application de recrutement TalentMatch (API REST, Groq IA, Outlook).

## Base MySQL avec phpMyAdmin

1. Ouvrez phpMyAdmin.
2. Creez une base nommee `newpro` avec l'interclassement `utf8mb4_unicode_ci`.
3. Importez le fichier `backend_php/database/mysql_schema.sql`.
4. Copiez `backend_php/.env.example` vers `backend_php/.env`.
5. Adaptez `DB_USER` / `DB_PASSWORD` selon votre installation MySQL.

## Demarrage local

Extensions PHP requises : `curl` (appels Groq), `mbstring`, `pdo_mysql`, `zip` (DOCX).

Sous Windows, activez `extension=curl` dans `php.ini` si vous voyez `Call to undefined function curl_init()`.

```powershell
php -S 127.0.0.1:8001 -t public
```

Puis lancez le frontend avec:

```powershell
cd frontend
$env:REACT_APP_API_URL="http://127.0.0.1:8001/api"
npm start
```

## Configuration

Le backend PHP charge `backend/.env` puis `backend_php/.env`. Pour phpMyAdmin, gardez surtout ces valeurs dans `backend_php/.env`:

```env
DB_CONNECTION=mysql
DB_NAME=newpro
DB_USER=root
DB_PASSWORD=
DB_HOST=127.0.0.1
DB_PORT=3306
```

## Routes migrees

- Auth: `/api/auth/check-setup/`, `/api/auth/setup/`, `/api/auth/login/`, `/api/auth/refresh/`, `/api/auth/me/`, `/api/auth/logout/`
- Admin users: `/api/users/`, `/api/users/create/`, `/api/users/stats/`, `/api/users/{id}/`, `/api/users/{id}/delete/`, `/api/users/{id}/toggle/`
- Recrutement: `/api/dashboard/`, `/api/candidates/`, `/api/candidates/{id}/`, `/api/candidates/upload/`, `/api/candidates/{id}/update/`
- CRUD REST: `/api/postes/`, `/api/candidats/`, `/api/cvs/`, `/api/candidatures/`, `/api/entretiens/`
- Domaines/dossiers: `/api/domains/`, `/api/domains/create/`, `/api/domains/{id}/candidates/`, `/api/dossiers/`
- Chat RH: conversations et historique, reponses via Groq.
- IA / Analyse CV: `/api/ai/analyse/`, `/api/ai/score/`, `/api/ml/analyse/` — **Groq uniquement** (pas de TF-IDF, Word2Vec ni XGBoost).

## Configuration Groq

Ajoutez votre cle API dans `backend_php/.env` (ou `backend/.env`, charge en premier):

```env
GROQ_API_KEY=gsk_...
GROQ_MODEL=llama-3.1-8b-instant
```

Obtenez une cle gratuite sur [console.groq.com/keys](https://console.groq.com/keys).

L'extraction PDF utilise d'abord un parseur PHP, puis bascule automatiquement sur PyMuPDF (`python` + `scripts/extract_cv_text.py`) si le texte est vide. Python doit etre installe avec les dependances du dossier `backend/` (`PyMuPDF`).

## Outlook (cv@colorado.ma)

Configurer dans `backend_php/.env` :

```env
AZURE_TENANT_ID=votre-tenant-id
AZURE_CLIENT_ID=votre-client-id
AZURE_CLIENT_SECRET=votre-secret
OUTLOOK_MAILBOX=cv@colorado.ma
OUTLOOK_MAX_MESSAGES=50
```

### Azure AD (une fois)

1. [Portail Azure](https://portal.azure.com) → **App registrations** → **New registration**
2. **API permissions** → **Microsoft Graph** → **Application permissions** → `Mail.Read`
3. **Grant admin consent** pour le tenant
4. **Certificates & secrets** → creer un **Client secret**
5. Pour une boite partagee : accorder a l'application l'acces a `cv@colorado.ma` (Exchange admin ou policy d'acces application)

Endpoints : `GET /api/outlook/status/`, `POST /api/outlook/sync/`

## Non migre dans cette passe

Le connecteur Gmail repond encore en mode non migre.
