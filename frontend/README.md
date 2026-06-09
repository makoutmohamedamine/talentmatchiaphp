# TalentMatch IA — Frontend React

Interface web (candidats, postes, analyse IA Groq, Outlook). Ce dossier fait partie du depot unique **talentmatchiaphp** (backend PHP a la racine + `frontend/`).

## Developpement local

```powershell
# Terminal 1 — API PHP (racine du depot)
php -S 127.0.0.1:8001 -t public

# Terminal 2 — React
cd frontend
copy .env.example .env
npm install
npm start
```

Application : http://localhost:3000 — API : `http://127.0.0.1:8001/api`

## Build production

```bash
cd frontend
cp .env.example .env.production
# REACT_APP_API_URL=https://votre-domaine.com/api
npm ci
npm run build
```

## Deploiement serveur

Structure apres `git clone` :

```
/var/www/talentmatchiaphp/
  public/           # API PHP
  src/
  frontend/build/   # npm run build
  media/            # CV uploades
  .env              # config PHP (non versionne)
```

1. Configurer `.env` a la racine (voir `.env.example`)
2. Builder le frontend avec la bonne `REACT_APP_API_URL`
3. Copier `deploy/nginx-fullstack.conf` vers Nginx
4. `sudo nginx -t && sudo systemctl reload nginx`
