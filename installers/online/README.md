# VoteSystem, online edition: installer

Installs the online edition on a server with Docker: one domain, HTTPS by Caddy, the images pulled by version from
the registry, `votectl` to run it. French first, English below.

## Installer (français)

Sur un serveur Debian ou Ubuntu, en administrateur :

```bash
wget https://github.com/jun101/VotingSystem/releases/download/vX.Y.Z/votesystem-online-X.Y.Z.tar.gz
wget https://github.com/jun101/VotingSystem/releases/download/vX.Y.Z/votesystem-online-X.Y.Z.tar.gz.sha256
sha256sum -c votesystem-online-X.Y.Z.tar.gz.sha256
tar -xzf votesystem-online-X.Y.Z.tar.gz && cd votesystem-online-X.Y.Z
sudo ./install-online.sh
```

Le script pose ses questions une fois : le **nom de domaine** (un seul, exemple `vote.fleuridor.com`), le courriel
(SMTP), l'adresse de l'administrateur de la plateforme. Avant de commencer, le nom de domaine doit pointer vers le
serveur (enregistrement A) et les ports 80 et 443 doivent être libres : sinon Caddy ne peut pas obtenir le certificat.
Si un autre proxy (Nginx Proxy Manager) les utilise déjà, répondez oui à « un autre proxy fera le HTTPS » : VoteSystem
écoute alors sur `127.0.0.1:<port libre>` et le script donne la cible à régler dans l'autre proxy.

À la fin, deux fichiers sont écrits dans le dossier de la personne qui a lancé `sudo` :
`votesystem-identifiants.txt` (mot de passe de l'administrateur, à changer à la première connexion) et
`votesystem-cle-audit-A-EMPORTER.txt` (clé privée de l'audit : à copier ailleurs puis à supprimer du serveur).

## Install (English)

On a Debian or Ubuntu server, as an administrator: the same five commands as above. The script asks once for the
**domain** (one only, example `vote.fleuridor.com`), the mail (SMTP) settings and the platform admin's address. The domain
must already point to the server and ports 80 and 443 must be free; with another proxy in front, answer yes to "another
proxy does the HTTPS" and point it to the local port the script prints.

Options: `--yes --domain NAME --admin-email ADDRESS` for a run with no questions, `--behind-proxy`, `--skip-mail`,
`--dry-run` (checks and plan, nothing changed), `--lang fr|en`. `./install-online.sh --help` lists them all.

## Day to day: `votectl`

| Command | What it does |
|---|---|
| `votectl status` | The services and the disk |
| `votectl admin create EMAIL` | Another platform admin (asks the password) |
| `votectl user reset-password EMAIL` | A new one-time password |
| `votectl mail test EMAIL` | Sends a test message with the mail settings |
| `votectl backup` | The database and the pictures in one checked archive |
| `votectl restore FILE` | Puts a backup back |
| `votectl ssh-key create` | An SSH key for the operator |
| `votectl update [--version X.Y.Z]` | Backup, new images, migrations, restart; goes back if it fails |

`sudo /opt/votesystem/installer/install-online.sh uninstall [--purge-data]` removes the services and, only on request,
the data.

## Files

`/opt/votesystem` holds `compose.prod.yaml`, `Caddyfile`, `.env` (mode 0600), `VERSION` and `installer/`. The data is in
Docker volumes (`db_data`, `redis_data`, `storage`, `media`, `caddy_data`).

## For the maintainer

- `installers/online/build.sh --version X.Y.Z` builds the archive; the release workflow does it on a tag, after
  pushing the images `ghcr.io/<owner>/votesystem-api:X.Y.Z` and `votesystem-web:X.Y.Z`.
- The images must be readable without a login: make the two packages public once, after the first push.
- Tests: `installers/online/tests/run.sh` (no Docker, no root).
