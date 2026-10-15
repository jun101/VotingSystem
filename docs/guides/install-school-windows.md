# Installer VoteSystem sur un ordinateur Windows d'école / Install VoteSystem on a school Windows computer

Édition école : tout est sur un seul ordinateur de l'établissement, sans internet après le téléchargement, sans Docker, sans
WSL, sans courriel. / School edition: everything runs on one computer in the institution, no internet needed after the
download, no Docker, no WSL, no email.

- Français : [plus bas](#français)
- English: [below](#english)

---

## Français

### Ce qu'il faut

| | |
|---|---|
| Un ordinateur | Windows 10 (1809 ou plus récent), Windows 11 ou Windows Server 2019 ou plus récent, 64 bits |
| Ressources | 4 Go de mémoire, 5 Go d'espace libre |
| Accès | Un compte administrateur de l'ordinateur |
| Réseau | Pendant l'installation seulement : pour télécharger l'archive (environ 225 Mo) |
| Autre | L'ordinateur reste allumé pendant les élections et a une adresse fixe dans le réseau de l'école |

### Étape 1. Télécharger et vérifier

1. Ouvrez la page des versions du projet : <https://github.com/jun101/VotingSystem/releases>.
2. Dans la version voulue (par exemple `v1.0.0-rc.5`), téléchargez **les deux fichiers** :
   `votesystem-1.0.0-rc.5-windows-x64.zip` et `votesystem-1.0.0-rc.5-windows-x64.zip.sha256`. Enregistrez-les dans le dossier
   `Téléchargements`.
3. Ouvrez **PowerShell en tant qu'administrateur** : clic droit sur le menu Démarrer, puis « Windows PowerShell (admin) » ou
   « Terminal (admin) ».
4. Allez dans le dossier des téléchargements :

   ```powershell
   cd $HOME\Downloads
   ```

5. Calculez l'empreinte du fichier et comparez-la avec le contenu du fichier `.sha256` (les deux doivent être identiques) :

   ```powershell
   (Get-FileHash .\votesystem-1.0.0-rc.5-windows-x64.zip -Algorithm SHA256).Hash.ToLower()
   ```

   ```powershell
   Get-Content .\votesystem-1.0.0-rc.5-windows-x64.zip.sha256
   ```

   Si elles diffèrent, ne continuez pas : retéléchargez le fichier.

6. Décompressez l'archive à la racine du disque (le chemin doit rester court) :

   ```powershell
   Expand-Archive .\votesystem-1.0.0-rc.5-windows-x64.zip -DestinationPath C:\
   ```

### Étape 2. Regarder avant d'installer (ne change rien)

```powershell
cd C:\votesystem-1.0.0-rc.5-windows-x64
```

```powershell
powershell -ExecutionPolicy Bypass -File .\installers\install.ps1 -DryRun -Lang fr -School "Collège Saint-Louis" -OwnerEmail directeur@ecole.local -AdminEmail admin@ecole.local
```

Remplacez le nom de l'école et les deux adresses (ce sont des identifiants de connexion, pas besoin que ce soient de vraies
boîtes courriel). Lisez le plan : adresse du site, port, dossiers `C:\VoteSystem` et `C:\VoteSystemData`. Corrigez les
problèmes signalés avant de continuer.

### Étape 3. Installer

La même commande, sans `-DryRun` :

```powershell
powershell -ExecutionPolicy Bypass -File .\installers\install.ps1 -Lang fr -School "Collège Saint-Louis" -OwnerEmail directeur@ecole.local -AdminEmail admin@ecole.local
```

Le script pose quelques questions (par exemple l'ouverture du pare-feu pour le réseau privé) ; l'installation prend environ
une minute. À la fin, deux fichiers sont écrits sur le **Bureau** :

| Fichier | Contenu | Quoi en faire |
|---|---|---|
| `votesystem-identifiants.txt` | Les adresses de connexion et les mots de passe provisoires | Lisez-le, changez les mots de passe à la première connexion, puis supprimez-le |
| `votesystem-cle-audit-A-EMPORTER.txt` | La **clé privée** de l'audit des votes | À copier sur une clé USB, puis à supprimer de l'ordinateur |

Si Windows affiche une fenêtre de pare-feu, choisissez **réseau privé** seulement.

### Étape 4. Mettre la clé d'audit à l'abri

La clé permet de relire l'audit des votes. Sans elle, personne ne peut le faire ; si elle reste sur l'ordinateur, ce n'est plus
un secret.

1. Branchez une clé USB (par exemple `E:`) et copiez-y le fichier :

   ```powershell
   Copy-Item "$HOME\Desktop\votesystem-cle-audit-A-EMPORTER.txt" E:\
   ```

2. Faites une deuxième copie dans un autre endroit sûr (gestionnaire de mots de passe, autre clé USB).
3. Comparez les empreintes (les deux lignes doivent être identiques) :

   ```powershell
   (Get-FileHash "$HOME\Desktop\votesystem-cle-audit-A-EMPORTER.txt").Hash
   ```

   ```powershell
   (Get-FileHash E:\votesystem-cle-audit-A-EMPORTER.txt).Hash
   ```

4. Quand les deux copies existent, supprimez le fichier de l'ordinateur et videz la corbeille :

   ```powershell
   Remove-Item "$HOME\Desktop\votesystem-cle-audit-A-EMPORTER.txt"
   ```

### Étape 5. Première connexion

1. Depuis un ordinateur du réseau de l'école, ouvrez l'adresse affichée par l'installateur (par exemple
   `http://192.168.1.20`).
2. Le site n'est pas chiffré (HTTP) : c'est normal dans un réseau privé, et un bandeau le rappelle.
3. Connectez-vous avec `directeur@ecole.local` (le propriétaire) et son mot de passe provisoire.
4. Le site vous demande de choisir un nouveau mot de passe (12 caractères au moins).
5. Ouvrez **Mon compte** et activez la **double authentification** (une application d'authentification sur le téléphone).
   Gardez les codes de récupération en lieu sûr.
6. Faites de même avec le compte administrateur de la plateforme.
7. Supprimez le fichier d'identifiants du Bureau.

### Étape 6. Sauvegardes

Une sauvegarde est faite chaque nuit à 02 h 30 dans `C:\VoteSystemData\backups` (les 14 dernières sont gardées). Faites aussi
une sauvegarde à la main, et copiez de temps en temps le dossier sur un disque externe :

```powershell
votectl backup
```

(dans un PowerShell administrateur neuf). Restauration : [docs/ops/backup-restore.md](../ops/backup-restore.md).

### Étape 7. Commandes de tous les jours

Dans un PowerShell administrateur :

| Commande | Fait |
|---|---|
| `votectl status` | Montre l'état des services |
| `votectl logs` | Montre les messages récents |
| `votectl restart` | Redémarre les services |
| `votectl user list` | Liste les comptes |
| `votectl user reset-password ADRESSE` | Donne un nouveau mot de passe provisoire à un compte |
| `votectl 2fa reset ADRESSE` | Retire la double authentification d'un compte (téléphone perdu) |

### Mettre à jour

Téléchargez, vérifiez et décompressez la nouvelle version comme à l'étape 1, puis depuis son dossier :

```powershell
powershell -ExecutionPolicy Bypass -File .\installers\install.ps1 update
```

L'installateur fait une sauvegarde, met en place la nouvelle version, et revient tout seul à la précédente si une migration
échoue.

### Désinstaller

```powershell
powershell -ExecutionPolicy Bypass -File .\installers\install.ps1 uninstall
```

Les données sont conservées. Pour tout effacer, ajoutez `-PurgeData`.

### Si quelque chose ne va pas

| Vous voyez | Faites |
|---|---|
| « L'exécution de scripts est désactivée » | Gardez `-ExecutionPolicy Bypass` dans la commande |
| Le site ne s'ouvre pas depuis un autre ordinateur | Vérifiez la règle de pare-feu (réseau privé) et l'adresse affichée ; `votectl status` |
| Un port est déjà pris | L'installateur prend le port libre suivant et l'affiche ; utilisez l'adresse affichée |
| Mot de passe perdu | `votectl user reset-password ADRESSE` |
| Téléphone perdu | `votectl 2fa reset ADRESSE` |

---

## English

### What you need

| | |
|---|---|
| A computer | Windows 10 (1809 or later), Windows 11 or Windows Server 2019 or later, 64-bit |
| Resources | 4 GB of memory, 5 GB of free space |
| Access | An administrator account on the computer |
| Network | During the installation only: to download the archive (about 225 MB) |
| Other | The computer stays on during the elections and has a fixed address on the school network |

### Step 1. Download and check

1. Open the project's releases page: <https://github.com/jun101/VotingSystem/releases>.
2. In the version you want (for example `v1.0.0-rc.5`), download **both files**:
   `votesystem-1.0.0-rc.5-windows-x64.zip` and `votesystem-1.0.0-rc.5-windows-x64.zip.sha256`. Save them in the `Downloads`
   folder.
3. Open **PowerShell as administrator**: right-click the Start menu, then "Windows PowerShell (admin)" or "Terminal (admin)".
4. Go to the downloads folder:

   ```powershell
   cd $HOME\Downloads
   ```

5. Compute the file's fingerprint and compare it with the content of the `.sha256` file (they must be identical):

   ```powershell
   (Get-FileHash .\votesystem-1.0.0-rc.5-windows-x64.zip -Algorithm SHA256).Hash.ToLower()
   ```

   ```powershell
   Get-Content .\votesystem-1.0.0-rc.5-windows-x64.zip.sha256
   ```

   If they differ, do not go on: download the file again.

6. Extract the archive at the root of the disk (the path must stay short):

   ```powershell
   Expand-Archive .\votesystem-1.0.0-rc.5-windows-x64.zip -DestinationPath C:\
   ```

### Step 2. Look before installing (changes nothing)

```powershell
cd C:\votesystem-1.0.0-rc.5-windows-x64
```

```powershell
powershell -ExecutionPolicy Bypass -File .\installers\install.ps1 -DryRun -Lang en -School "Saint-Louis College" -OwnerEmail principal@school.local -AdminEmail admin@school.local
```

Replace the school name and the two addresses (they are sign-in names; they do not need to be real mailboxes). Read the plan:
site address, port, folders `C:\VoteSystem` and `C:\VoteSystemData`. Fix the reported problems before going on.

### Step 3. Install

The same command, without `-DryRun`:

```powershell
powershell -ExecutionPolicy Bypass -File .\installers\install.ps1 -Lang en -School "Saint-Louis College" -OwnerEmail principal@school.local -AdminEmail admin@school.local
```

The script asks a few questions (for example opening the firewall for the private network); the installation takes about a
minute. At the end two files are written on the **Desktop**:

| File | Content | What to do |
|---|---|---|
| `votesystem-identifiants.txt` | The sign-in addresses and the temporary passwords | Read it, change the passwords at the first sign-in, then delete it |
| `votesystem-cle-audit-A-EMPORTER.txt` | The **private key** of the vote audit | Copy it to a USB key, then delete it from the computer |

If Windows shows a firewall window, choose **private network** only.

### Step 4. Put the audit key somewhere safe

The key lets you read the vote audit. Without it nobody can; if it stays on the computer it is no longer a secret.

1. Plug in a USB key (for example `E:`) and copy the file to it:

   ```powershell
   Copy-Item "$HOME\Desktop\votesystem-cle-audit-A-EMPORTER.txt" E:\
   ```

2. Make a second copy somewhere else that is safe (password manager, another USB key).
3. Compare the fingerprints (the two lines must be identical):

   ```powershell
   (Get-FileHash "$HOME\Desktop\votesystem-cle-audit-A-EMPORTER.txt").Hash
   ```

   ```powershell
   (Get-FileHash E:\votesystem-cle-audit-A-EMPORTER.txt).Hash
   ```

4. When both copies exist, delete the file from the computer and empty the recycle bin:

   ```powershell
   Remove-Item "$HOME\Desktop\votesystem-cle-audit-A-EMPORTER.txt"
   ```

### Step 5. First sign-in

1. From a computer on the school network, open the address the installer printed (for example `http://192.168.1.20`).
2. The site is not encrypted (HTTP): this is normal on a private network, and a banner says so.
3. Sign in with the owner address you gave and its temporary password.
4. The site asks you to choose a new password (at least 12 characters).
5. Open **My account** and turn on **two-factor authentication** (an authenticator app on your phone). Keep the recovery codes
   somewhere safe.
6. Do the same with the platform admin account.
7. Delete the credentials file from the Desktop.

### Step 6. Backups

A backup is made every night at 02:30 in `C:\VoteSystemData\backups` (the last 14 are kept). Also make one by hand, and copy
the folder to an external disk from time to time:

```powershell
votectl backup
```

(in a new administrator PowerShell). Restore: [docs/ops/backup-restore.md](../ops/backup-restore.md).

### Step 7. Everyday commands

In an administrator PowerShell:

| Command | Does |
|---|---|
| `votectl status` | Shows the state of the services |
| `votectl logs` | Shows recent messages |
| `votectl restart` | Restarts the services |
| `votectl user list` | Lists the accounts |
| `votectl user reset-password ADDRESS` | Gives an account a new temporary password |
| `votectl 2fa reset ADDRESS` | Removes an account's two-factor (lost phone) |

### Update

Download, check and unpack the new version as in step 1, then from its folder:

```powershell
powershell -ExecutionPolicy Bypass -File .\installers\install.ps1 update
```

The installer makes a backup, puts the new version in place, and goes back to the previous one by itself if a migration fails.

### Uninstall

```powershell
powershell -ExecutionPolicy Bypass -File .\installers\install.ps1 uninstall
```

The data is kept. To erase everything, add `-PurgeData`.

### When something is wrong

| You see | Do |
|---|---|
| "Running scripts is disabled" | Keep `-ExecutionPolicy Bypass` in the command |
| The site does not open from another computer | Check the firewall rule (private network) and the printed address; `votectl status` |
| A port is already used | The installer takes the next free port and prints it; use the printed address |
| Lost password | `votectl user reset-password ADDRESS` |
| Lost phone | `votectl 2fa reset ADDRESS` |
