# Installer VoteSystem sur un serveur Linux d'école / Install VoteSystem on a school Linux server

Édition école : tout est sur une seule machine de l'établissement, sans internet après le téléchargement, sans Docker, sans
courriel. / School edition: everything runs on one machine in the institution, no internet needed after the download, no
Docker, no email.

- Français : [plus bas](#français)
- English: [below](#english)

---

## Français

### Ce qu'il faut

| | |
|---|---|
| Une machine | Debian 12 ou 13, ou Ubuntu 24.04 ou 26.04, 64 bits (x86_64), systemd |
| Ressources | 4 Go de mémoire, 5 Go d'espace libre |
| Accès | Un utilisateur qui peut utiliser `sudo` |
| Réseau | Pendant l'installation seulement : pour télécharger l'archive (environ 260 Mo) |
| Autre | La machine a une adresse fixe dans le réseau de l'école (demandez à la personne qui gère le réseau) |

### Étape 1. Télécharger et vérifier

Ouvrez un terminal sur la machine. Remplacez `1.0.0-rc.5` par la version que vous voulez installer (la dernière est sur la
page des versions du projet).

```bash
cd ~ && wget -q https://github.com/jun101/VotingSystem/releases/download/v1.0.0-rc.5/votesystem-1.0.0-rc.5-linux-x86_64.tar.gz{,.sha256}
```

Vérifiez que le fichier n'est pas abîmé. Vous devez lire `OK` :

```bash
sha256sum -c votesystem-1.0.0-rc.5-linux-x86_64.tar.gz.sha256
```

Si vous lisez `FAILED`, ne continuez pas : retéléchargez le fichier.

Décompressez :

```bash
tar -xzf votesystem-1.0.0-rc.5-linux-x86_64.tar.gz && cd votesystem-1.0.0-rc.5-linux-x86_64
```

### Étape 2. Regarder avant d'installer (ne change rien)

```bash
sudo ./installers/install.sh --dry-run --lang fr --school "Collège Saint-Louis" --owner-email directeur@ecole.local --admin-email admin@ecole.local
```

Remplacez le nom de l'école et les deux adresses (elles servent d'identifiants de connexion, elles n'ont pas besoin d'exister
comme boîtes courriel). Lisez le plan : adresse du site, port, dossier `/opt/votesystem`. Si une ligne signale un problème
(mémoire, espace, port déjà pris), corrigez-le avant de continuer.

### Étape 3. Installer

Même commande, sans `--dry-run` :

```bash
sudo ./installers/install.sh --lang fr --school "Collège Saint-Louis" --owner-email directeur@ecole.local --admin-email admin@ecole.local
```

L'installation prend une à deux minutes. À la fin, le script affiche l'adresse du site (par exemple `http://192.168.1.20`) et
écrit **deux fichiers** dans votre dossier personnel :

| Fichier | Contenu | Quoi en faire |
|---|---|---|
| `votesystem-identifiants.txt` | Les adresses de connexion et les mots de passe provisoires | Lisez-le, changez les mots de passe à la première connexion, puis supprimez-le |
| `votesystem-cle-audit-A-EMPORTER.txt` | La **clé privée** de l'audit des votes | À copier sur une clé USB, puis à supprimer de la machine |

### Étape 4. Mettre la clé d'audit à l'abri

La clé permet de relire l'audit des votes. Sans elle, personne ne peut le faire ; si elle reste sur le serveur, ce n'est plus
un secret.

1. Branchez une clé USB et copiez-y le fichier `votesystem-cle-audit-A-EMPORTER.txt`.
2. Faites une deuxième copie dans un autre endroit sûr (gestionnaire de mots de passe, autre clé USB).
3. Comparez les empreintes du fichier d'origine et de la copie (les deux lignes doivent être identiques) :

   ```bash
   sha256sum ~/votesystem-cle-audit-A-EMPORTER.txt
   ```

   ```bash
   sha256sum /media/VOTRE_CLE/votesystem-cle-audit-A-EMPORTER.txt
   ```

4. Quand les deux copies existent, effacez le fichier de la machine :

   ```bash
   shred -u ~/votesystem-cle-audit-A-EMPORTER.txt
   ```

### Étape 5. Première connexion

1. Depuis un ordinateur du réseau de l'école, ouvrez l'adresse affichée par l'installateur.
2. Le site n'est pas chiffré (HTTP) : c'est normal dans un réseau privé, et un bandeau le rappelle.
3. Connectez-vous avec `directeur@ecole.local` (le propriétaire) et son mot de passe provisoire du fichier d'identifiants.
4. Le site vous demande de choisir un nouveau mot de passe (12 caractères au moins).
5. Ouvrez **Mon compte** et activez la **double authentification** (une application comme FreeOTP ou Google Authenticator
   sur le téléphone). Gardez les codes de récupération en lieu sûr.
6. Faites de même avec le compte administrateur de la plateforme (`admin@ecole.local`).
7. Supprimez le fichier d'identifiants :

   ```bash
   shred -u ~/votesystem-identifiants.txt
   ```

### Étape 6. Sauvegardes

Une sauvegarde est faite chaque nuit dans `/var/backups/votesystem`. Faites aussi une sauvegarde à la main et copiez-la sur un
disque externe de temps en temps :

```bash
sudo votectl backup
```

La commande affiche le chemin de l'archive `.tar` et de sa somme de contrôle. Procédure complète de restauration :
[docs/ops/backup-restore.md](../ops/backup-restore.md).

### Étape 7. Commandes de tous les jours

| Commande | Fait |
|---|---|
| `sudo votectl status` | Montre l'état des services |
| `sudo votectl logs` | Montre les messages récents |
| `sudo votectl user list` | Liste les comptes |
| `sudo votectl user reset-password ADRESSE` | Donne un nouveau mot de passe provisoire à un compte |
| `sudo votectl 2fa reset ADRESSE` | Retire la double authentification d'un compte (téléphone perdu) |
| `sudo votectl backup` | Fait une sauvegarde |

### Mettre à jour

Téléchargez, vérifiez et décompressez la nouvelle version comme aux étapes 1, puis depuis son dossier :

```bash
sudo ./installers/install.sh update
```

L'installateur fait une sauvegarde, met en place la nouvelle version, et revient tout seul à la précédente si quelque chose
échoue.

### Désinstaller

```bash
sudo ./installers/install.sh uninstall
```

Les données sont conservées. Pour tout effacer, ajoutez `--purge-data`.

### Si quelque chose ne va pas

| Vous voyez | Faites |
|---|---|
| `la somme de contrôle ne correspond pas` | Retéléchargez l'archive |
| Un port est déjà pris | L'installateur prend le port libre suivant et l'affiche ; utilisez l'adresse affichée |
| Le site ne s'ouvre pas | `sudo votectl status`, puis `sudo votectl logs` ; vérifiez le pare-feu de la machine |
| Mot de passe perdu | `sudo votectl user reset-password ADRESSE` |
| Téléphone perdu | `sudo votectl 2fa reset ADRESSE` |

---

## English

### What you need

| | |
|---|---|
| A machine | Debian 12 or 13, or Ubuntu 24.04 or 26.04, 64-bit (x86_64), systemd |
| Resources | 4 GB of memory, 5 GB of free space |
| Access | A user who can use `sudo` |
| Network | During the installation only: to download the archive (about 260 MB) |
| Other | The machine has a fixed address on the school network (ask whoever looks after the network) |

### Step 1. Download and check

Open a terminal on the machine. Replace `1.0.0-rc.5` with the version you want (the latest is on the project's releases
page).

```bash
cd ~ && wget -q https://github.com/jun101/VotingSystem/releases/download/v1.0.0-rc.5/votesystem-1.0.0-rc.5-linux-x86_64.tar.gz{,.sha256}
```

Check that the file is not damaged. You must read `OK`:

```bash
sha256sum -c votesystem-1.0.0-rc.5-linux-x86_64.tar.gz.sha256
```

If you read `FAILED`, do not go on: download the file again.

Unpack:

```bash
tar -xzf votesystem-1.0.0-rc.5-linux-x86_64.tar.gz && cd votesystem-1.0.0-rc.5-linux-x86_64
```

### Step 2. Look before installing (changes nothing)

```bash
sudo ./installers/install.sh --dry-run --lang en --school "Saint-Louis College" --owner-email principal@school.local --admin-email admin@school.local
```

Replace the school name and the two addresses (they are sign-in names; they do not need to exist as mailboxes). Read the plan:
site address, port, folder `/opt/votesystem`. If a line reports a problem (memory, space, port already used), fix it before
going on.

### Step 3. Install

The same command, without `--dry-run`:

```bash
sudo ./installers/install.sh --lang en --school "Saint-Louis College" --owner-email principal@school.local --admin-email admin@school.local
```

The installation takes one or two minutes. At the end the script prints the site address (for example `http://192.168.1.20`)
and writes **two files** in your home folder:

| File | Content | What to do |
|---|---|---|
| `votesystem-identifiants.txt` | The sign-in addresses and the temporary passwords | Read it, change the passwords at the first sign-in, then delete it |
| `votesystem-cle-audit-A-EMPORTER.txt` | The **private key** of the vote audit | Copy it to a USB key, then delete it from the machine |

### Step 4. Put the audit key somewhere safe

The key lets you read the vote audit. Without it nobody can; if it stays on the server it is no longer a secret.

1. Plug in a USB key and copy `votesystem-cle-audit-A-EMPORTER.txt` to it.
2. Make a second copy somewhere else that is safe (password manager, another USB key).
3. Compare the fingerprints of the original and the copy (the two lines must be identical):

   ```bash
   sha256sum ~/votesystem-cle-audit-A-EMPORTER.txt
   ```

   ```bash
   sha256sum /media/YOUR_KEY/votesystem-cle-audit-A-EMPORTER.txt
   ```

4. When both copies exist, erase the file from the machine:

   ```bash
   shred -u ~/votesystem-cle-audit-A-EMPORTER.txt
   ```

### Step 5. First sign-in

1. From a computer on the school network, open the address the installer printed.
2. The site is not encrypted (HTTP): this is normal on a private network, and a banner says so.
3. Sign in with the owner address you gave and its temporary password from the credentials file.
4. The site asks you to choose a new password (at least 12 characters).
5. Open **My account** and turn on **two-factor authentication** (an authenticator app on your phone). Keep the recovery codes
   somewhere safe.
6. Do the same with the platform admin account.
7. Delete the credentials file:

   ```bash
   shred -u ~/votesystem-identifiants.txt
   ```

### Step 6. Backups

A backup is made every night in `/var/backups/votesystem`. Also make one by hand, and copy it to an external disk from time to
time:

```bash
sudo votectl backup
```

The command prints the path of the `.tar` archive and of its checksum. Full restore procedure:
[docs/ops/backup-restore.md](../ops/backup-restore.md).

### Step 7. Everyday commands

| Command | Does |
|---|---|
| `sudo votectl status` | Shows the state of the services |
| `sudo votectl logs` | Shows recent messages |
| `sudo votectl user list` | Lists the accounts |
| `sudo votectl user reset-password ADDRESS` | Gives an account a new temporary password |
| `sudo votectl 2fa reset ADDRESS` | Removes an account's two-factor (lost phone) |
| `sudo votectl backup` | Makes a backup |

### Update

Download, check and unpack the new version as in step 1, then from its folder:

```bash
sudo ./installers/install.sh update
```

The installer makes a backup, puts the new version in place, and goes back to the previous one by itself if something fails.

### Uninstall

```bash
sudo ./installers/install.sh uninstall
```

The data is kept. To erase everything, add `--purge-data`.

### When something is wrong

| You see | Do |
|---|---|
| `the checksum does not match` | Download the archive again |
| A port is already used | The installer takes the next free port and prints it; use the printed address |
| The site does not open | `sudo votectl status`, then `sudo votectl logs`; check the machine's firewall |
| Lost password | `sudo votectl user reset-password ADDRESS` |
| Lost phone | `sudo votectl 2fa reset ADDRESS` |
