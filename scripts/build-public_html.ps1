# Genere public_html/ pret pour Hostinger.
# Usage (depuis la racine du depot) :
#   powershell -ExecutionPolicy Bypass -File scripts/build-public_html.ps1

$ErrorActionPreference = "Stop"
$RepoRoot = Split-Path $PSScriptRoot -Parent
$FrontendDir = Join-Path $RepoRoot "frontend"
$BackendDir = Join-Path $RepoRoot "backend_php"
$OutDir = Join-Path $RepoRoot "public_html"
$HostingerDir = Join-Path $PSScriptRoot "hostinger"

if (-not (Test-Path $FrontendDir)) { throw "frontend/ introuvable" }
if (-not (Test-Path $BackendDir)) { throw "backend_php/ introuvable" }

Write-Host "==> Nettoyage $OutDir"
if (Test-Path $OutDir) { Remove-Item $OutDir -Recurse -Force }
New-Item -ItemType Directory -Path $OutDir | Out-Null

Write-Host "==> Build React (REACT_APP_API_URL=/api)"
Push-Location $FrontendDir
if (-not (Test-Path "node_modules")) {
    npm install
    if ($LASTEXITCODE -ne 0) { throw "npm install a echoue" }
}
$env:REACT_APP_API_URL = "/api"
npm run build
if ($LASTEXITCODE -ne 0) { throw "npm run build a echoue" }
Pop-Location

Write-Host "==> Copie frontend build"
Copy-Item -Path (Join-Path $FrontendDir "build\*") -Destination $OutDir -Recurse -Force

Write-Host "==> Copie backend PHP"
$BackendOut = Join-Path $OutDir "backend_php"
New-Item -ItemType Directory -Path $BackendOut | Out-Null
foreach ($item in @("bootstrap.php", "src", "scripts", "database")) {
    Copy-Item (Join-Path $BackendDir $item) (Join-Path $BackendOut $item) -Recurse -Force
}
New-Item -ItemType Directory -Path (Join-Path $BackendOut "public") -Force | Out-Null
Copy-Item (Join-Path $BackendDir "public\index.php") (Join-Path $BackendOut "public\index.php") -Force
New-Item -ItemType Directory -Path (Join-Path $BackendOut "media\cvs") -Force | Out-Null

Write-Host "==> Config Hostinger"
Copy-Item (Join-Path $HostingerDir ".htaccess") (Join-Path $OutDir ".htaccess") -Force
Copy-Item (Join-Path $HostingerDir "backend_php.htaccess") (Join-Path $BackendOut ".htaccess") -Force
Copy-Item (Join-Path $HostingerDir "public.htaccess") (Join-Path $BackendOut "public\.htaccess") -Force
Copy-Item (Join-Path $HostingerDir ".user.ini") (Join-Path $OutDir ".user.ini") -Force
Copy-Item (Join-Path $HostingerDir "DEPLOY_LISEZMOI.txt") (Join-Path $OutDir "DEPLOY_LISEZMOI.txt") -Force
Copy-Item (Join-Path $HostingerDir "env.production.example") (Join-Path $BackendOut ".env.example") -Force

Write-Host ""
Write-Host "PRET : $OutDir"
Write-Host "Uploadez tout le contenu dans public_html Hostinger."
