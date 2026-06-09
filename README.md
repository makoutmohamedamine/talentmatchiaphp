# TalentMatch IA

Application de recrutement — depot monorepo avec **deux dossiers separes** :

| Dossier | Role |
|---------|------|
| [`backend_php/`](backend_php/) | API REST PHP 8.3 (Groq, Outlook, MySQL) |
| [`frontend/`](frontend/) | Interface React |

## Clone et demarrage local

```bash
git clone https://github.com/makoutmohamedamine/talentmatchiaphp.git
cd talentmatchiaphp
```

### Backend

```powershell
cd backend_php
copy .env.example .env
# Editer DB, GROQ_API_KEY, Azure Outlook…
php -S 127.0.0.1:8001 -t public
```

### Frontend

```powershell
cd frontend
copy .env.example .env
npm install
npm start
```

→ http://localhost:3000 — API : `http://127.0.0.1:8001/api`

## Deploiement production

```bash
git clone https://github.com/makoutmohamedamine/talentmatchiaphp.git /var/www/talentmatchiaphp

# Backend
cd /var/www/talentmatchiaphp/backend_php
cp .env.example .env
# Configurer DB, Groq, Azure…

# Frontend
cd /var/www/talentmatchiaphp/frontend
cp .env.example .env.production
# REACT_APP_API_URL=https://votre-domaine.com/api
npm ci && npm run build

# Nginx
sudo cp /var/www/talentmatchiaphp/deploy/nginx-fullstack.conf /etc/nginx/sites-available/talentmatchia
sudo nginx -t && sudo systemctl reload nginx
```

Structure serveur :

```
/var/www/talentmatchiaphp/
  backend_php/public/    # API
  backend_php/media/     # CV uploades
  frontend/build/        # React compile
```
