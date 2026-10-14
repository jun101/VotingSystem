# VoteSystem, school edition, Windows installer

Installs VoteSystem natively on Windows 10 (1809 or later), Windows 11 or Windows Server 2019 or later (x64). No Docker, no
WSL, no internet needed: the bundle holds PHP, MariaDB, Redis for Windows, Node and Caddy. The services are scheduled
tasks that start with the computer. French first, English below.

## Installer (français)

1. Téléchargez `votesystem-X.Y.Z-windows-x64.zip` et son fichier `.sha256` depuis la page de la version.
2. Vérifiez la somme de contrôle, puis décompressez l'archive près de la racine du disque (chemins courts) :

   ```powershell
   Get-FileHash .\votesystem-X.Y.Z-windows-x64.zip -Algorithm SHA256
   Expand-Archive .\votesystem-X.Y.Z-windows-x64.zip -DestinationPath C:\
   ```

3. Ouvrez PowerShell **en tant qu'administrateur** et lancez :

   ```powershell
   cd C:\votesystem-X.Y.Z-windows-x64
   powershell -ExecutionPolicy Bypass -File .\installers\install.ps1
   ```

Le script vérifie la machine, choisit les ports libres (80 pour le site, sinon le suivant), pose ses questions une
fois (nom de l'école, adresses de connexion, pare-feu) et installe tout en une minute environ. À la fin, deux fichiers
sont écrits sur le Bureau : `votesystem-identifiants.txt` (adresses et mots de passe provisoires, à changer à la première
connexion) et `votesystem-cle-audit-A-EMPORTER.txt` (clé privée de l'audit : à copier sur une clé USB, puis à supprimer de
l'ordinateur).

## Install (English)

Download the zip and its `.sha256`, check the hash, extract it near the root of the disk, open PowerShell **as
administrator**, and run `installers\install.ps1`. Options: `-Yes` (no questions), `-DryRun` (checks and plan only),
`-WebPort N`, `-School NAME`, `-OwnerEmail`, `-AdminEmail`, `-CredentialsFile F`, `-NoFirewall`, `-Workers N`,
`-Lang fr|en`. `update` puts a new bundle in place of the installed version (a backup first, an automatic return to the
previous version when the migration fails); `uninstall [-PurgeData]` removes the services, the firewall rule and the
program, and the data only on request.

## Day to day: `votectl`

Open a new terminal as administrator: `votectl status | version | logs | start | stop | restart`,
`votectl user list`, `votectl user reset-password EMAIL`, `votectl owner create EMAIL --institution NAME`,
`votectl admin create EMAIL`, `votectl 2fa reset EMAIL`, `votectl backup [--out DIR]`, `votectl restore FILE`. A nightly
backup runs at 02:30 and keeps the last 14 in `C:\VoteSystemData\backups`.

## How it is built

- `C:\VoteSystem` (program): `releases\<version>`, `current` (a junction to the active release), `installer`, `bin`.
- `C:\VoteSystemData` (data): `config` (env, php.ini, Caddyfile, mariadb.ini, redis.conf), `mariadb`, `storage`, `media`,
  `backups`. The API storage and cache are junctions into it, so a release is replaced as a whole.
- Services (Task Scheduler, user SYSTEM, at startup, restarted when they stop): `mariadb`, `redis`, four `php-<port>`
  FastCGI workers, `queue`, `scheduler`, `web`, `proxy` (Caddy). Octane and `pcntl` are not used on Windows.
- The same Caddy routes and headers as the other editions; plain HTTP on the school's network.

## For the maintainer

- `installers/bundle/build-windows.sh --version X.Y.Z` builds the zip on Linux from `sources-windows.lock` (every file
  pinned by checksum) and the Linux bundle's `api` and `web`.
- Tests: `tests/run.sh` (static checks, no Windows needed) and, in a Windows VM, `tests/vm-flow.ps1` and
  `tests/vm-lifecycle.ps1`. Result of the first full run (Windows 11, 5 GB, 4 CPUs): install 71 s, vote flow, update 64 s,
  rollback on a broken migration, backup and restore, uninstall: all passed.
- Known limits: x64 only; Redis is the Windows port of Redis 5 (no longer maintained upstream: it is bound to
  127.0.0.1 and password protected; Garnet is the candidate to replace it); the web application's image resizing
  (`sharp`) is not bundled for Windows because no page uses it.
