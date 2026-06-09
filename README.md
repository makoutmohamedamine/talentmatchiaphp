# TalentMatch IA

Application de recrutement : **API PHP** (racine) + **frontend React** (`frontend/`).

Depot : [github.com/makoutmohamedamine/talentmatchiaphp](https://github.com/makoutmohamedamine/talentmatchiaphp)

## Structure

```
talentmatchiaphp/
  public/          # Point d'entree API
  src/             # Code PHP (Groq, Outlook, candidats…)
  database/        # Schema MySQL
  frontend/        # Application React
  deploy/          # Nginx (API seule ou fullstack)
  .env.example     # Configuration PHP
```

## Installation rapide

### 1. Base MySQL

1. Creer la base `newpro` (utf8mb4_unicode_ci)
2. Importer `database/mysql_schema.sql`
3. `copy .env.example .env` et adapter DB / Groq / Azure

### 2. Backend PHP

Extensions : `curl`, `mbstring`, `pdo_mysql`, `zip`

```powershell
php -S 127.0.0.1:8001 -t public
```

### 3. Frontend React

```powershell
cd frontend
copy .env.example .env
npm install
npm start
```

→ http://localhost:3000 (API : `http://127.0.0.1:8001/api`)

## Deploiement production

```bash
# Sur le serveur
git clone https://github.com/makoutmohamedamine/talentmatchiaphp.git /var/www/talentmatchiaphp
cd /var/www/talentmatchiaphp
cp .env.example .env   # editer DB, GROQ_API_KEY, Azure…

cd frontend
cp .env.example .env.production
# REACT_APP_API_URL=https://votre-domaine.com/api
npm ci && npm run build

sudo cp deploy/nginx-fullstack.conf /etc/nginx/sites-available/talentmatchia
# Adapter VOTRE_DOMAINE et chemins
sudo nginx -t && sudo systemctl reload nginx
```

## Configuration Groq

```env
GROQ_API_KEY=gsk_...
GROQ_MODEL=llama-3.1-8b-instant
```

## Outlook (cv@colorado.ma)

```env
AZURE_TENANT_ID=...
AZURE_CLIENT_ID=...
AZURE_CLIENT_SECRET=...
OUTLOOK_MAILBOX=cv@colorado.ma
```

Permissions Azure : `Mail.Read` (application) + consentement admin.

Endpoints : `GET /api/outlook/status/`, `POST /api/outlook/sync/`

## API principale

Auth, candidats, postes, upload CV, analyse/scoring Groq, chat RH, utilisateurs — voir `src/App.php`.
