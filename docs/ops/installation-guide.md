# Installation guide for the person who runs the server (français d'abord, English below)

The same guide is in the application, for the signed-in owner and platform admin: user menu, "Guide d'utilisation",
"Installation" (`/guide/install`), with the numbered steps in French and English.

## Français

### Choisir l'installation

| Situation | Installateur | Prérequis | Courriel |
|---|---|---|---|
| Un serveur Linux dans l'établissement, sans internet | `installers/install.sh` (édition école, natif, sans Docker) | Debian 12 ou 13, Ubuntu 24.04 ou 26.04, x86_64, 4 Go de mémoire, 5 Go libres, systemd | Non : les comptes sont créés par l'installateur, les mots de passe se réinitialisent avec `votectl` |
| Un ordinateur Windows dans l'établissement | `installers\install.ps1` (édition école, natif, sans Docker ni WSL) | Windows 10 1809 ou plus, Windows 11, Windows Server 2019 ou plus, 64 bits, 4 Go, 5 Go libres | Non |
| Un serveur en ligne avec un nom de domaine | `install-online.sh` (édition en ligne, Docker, HTTPS automatique) | Debian ou Ubuntu avec Docker (installé par le script si besoin), les ports 80 et 443, un nom de domaine | Oui (SMTP) |

### Étapes communes

1. Télécharger l'archive de la version et son fichier `.sha256`, vérifier la somme, décompresser.
2. Lancer l'installateur en administrateur. `--dry-run` (Linux, en ligne) ou `-DryRun` (Windows) montre les vérifications et le
   plan sans rien changer. `--yes` accepte les valeurs proposées.
3. Lire le résumé : adresse du site, ports choisis (le port 80 est pris par défaut, sinon le suivant), fichiers écrits.
4. Deux fichiers sont écrits : les comptes (`votesystem-identifiants.txt`, mots de passe provisoires) et la **clé privée de
   l'audit** (`votesystem-cle-audit-A-EMPORTER.txt`). Copier la clé sur une clé USB, puis la supprimer de la machine.
5. Ouvrir l'adresse du site : chaque compte change son mot de passe provisoire à la première connexion. Activer la
   double authentification du propriétaire et de l'administrateur.
6. Pare-feu : l'installateur propose d'ouvrir le seul port du site (réseau privé) et retire la règle à la désinstallation.
7. Sauvegardes : une par nuit (écoles) ; copier les archives sur un disque externe. Procédure et essai : `backup-restore.md`.

### Exploitation

`votectl status | version | logs | backup | restore | update | user list | user reset-password | owner create | admin create |
2fa reset | mail test` (Windows : même commande dans PowerShell administrateur).
Mise à jour : décompresser la nouvelle version puis `install.sh update` (`install.ps1 update`, `votectl update --version X.Y.Z`) :
sauvegarde, migrations, retour automatique à la version précédente en cas d'échec.
Désinstaller : `install.sh uninstall` : les données sont conservées, sauf avec `--purge-data`.

### Ce qui a été essayé

| Système | Résultat |
|---|---|
| Debian 12, Debian 13, Ubuntu 24.04.5, Ubuntu 26.04.1 (machines virtuelles neuves) | installation en 10 à 15 s, parcours de vote complet par l'API, redémarrage, mise à jour, mise à jour échouée avec retour, même version refusée, désinstallation : réussi |
| Windows 11 (machine virtuelle, 5 Go) | installation en 71 s, parcours de vote, mise à jour en 64 s, retour arrière, sauvegarde et restauration, désinstallation, redémarrage de la machine : réussi |
| En ligne (images de production, Docker) | installation, parcours de vote, test de charge 500 votants en une minute, CSP, sauvegarde, sinistre et restauration, mise à jour et retour : réussi. Le certificat HTTPS d'un vrai domaine et le serveur VPS lui-même ne sont pas essayés (`production-vps.md`) |

## English

### Which installer

| Situation | Installer | Needs | Email |
|---|---|---|---|
| A Linux server in the institution, no internet | `installers/install.sh` (school edition, native, no Docker) | Debian 12 or 13, Ubuntu 24.04 or 26.04, x86_64, 4 GB memory, 5 GB free, systemd | No: the installer creates the accounts, passwords are reset with `votectl` |
| A Windows computer in the institution | `installers\install.ps1` (school edition, native, no Docker or WSL) | Windows 10 1809 or later, Windows 11, Windows Server 2019 or later, 64-bit, 4 GB, 5 GB free | No |
| An online server with a domain name | `install-online.sh` (online edition, Docker, automatic HTTPS) | Debian or Ubuntu with Docker (installed by the script when missing), ports 80 and 443, a domain name | Yes (SMTP) |

### Common steps

1. Download the version's archive and its `.sha256`, check the sum, unpack.
2. Run the installer as an administrator. `--dry-run` (Linux, online) or `-DryRun` (Windows) shows the checks and the plan
   and changes nothing. `--yes` accepts the proposed values.
3. Read the summary: site address, chosen ports (port 80 by default, else the next free one), files written.
4. Two files are written: the accounts (`votesystem-identifiants.txt`, temporary passwords) and the **audit private key**
   (`votesystem-cle-audit-A-EMPORTER.txt`). Copy the key to a USB key, then delete it from the machine.
5. Open the site address: each account changes its temporary password at first sign-in. Turn on two-factor
   authentication for the owner and the administrator.
6. Firewall: the installer offers to open the site's one port (private network) and removes the rule on uninstall.
7. Backups: one a night (school editions); copy the archives to an external disk. Procedure and rehearsal: `backup-restore.md`.

### Operation

`votectl status | version | logs | backup | restore | update | user list | user reset-password | owner create | admin create |
2fa reset | mail test` (Windows: the same command in an administrator PowerShell).
Update: unpack the new version, then `install.sh update` (`install.ps1 update`, `votectl update --version X.Y.Z`): backup,
migrations, automatic return to the previous version on failure. Uninstall: `install.sh uninstall` keeps the data unless
`--purge-data`.

### What was tried

See the French table above: Debian 12, Debian 13, Ubuntu 24.04.5 and 26.04.1 (fresh virtual machines), Windows 11 (virtual
machine), the online edition on its production images. Not tried: a real HTTPS domain, a real VPS.
