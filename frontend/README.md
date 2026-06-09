# Frontend React — TalentMatch IA

Interface web : candidats, postes, analyse IA, Outlook, chat RH.

Le backend PHP est dans le dossier voisin [`../backend_php/`](../backend_php/).

## Developpement local

```powershell
# Terminal 1 — API (depuis ../backend_php)
cd ..\backend_php
php -S 127.0.0.1:8001 -t public

# Terminal 2 — React
cd frontend
copy .env.example .env
npm install
npm start
```

## Build production

```bash
cp .env.example .env.production
# REACT_APP_API_URL=https://votre-domaine.com/api
npm ci && npm run build
```

Le dossier `build/` est servi par Nginx (voir `../deploy/nginx-fullstack.conf`).
