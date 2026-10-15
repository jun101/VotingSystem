# Installer VoteSystem sur un serveur en ligne (VPS) / Install VoteSystem on an online server (VPS)

Édition en ligne : Docker, un nom de domaine, HTTPS, courriel. / Online edition: Docker, a domain name, HTTPS, email.

- Français : [plus bas](#français)
- English: [below](#english)

---

## Français

### Ce qu'il faut

| | |
|---|---|
| Un serveur | Debian 12 ou 13, ou Ubuntu 24.04 ou 26.04, 64 bits, **2 processeurs, 4 Go de mémoire, 20 Go de disque** au moins |
| Un accès | Un utilisateur qui peut utiliser `sudo`, connecté en SSH |
| Un nom de domaine | **Un seul**, par exemple `vote.example.org`, avec un enregistrement **A** qui pointe vers l'adresse du serveur |
| Les ports | 80 et 443 joignables (aussi dans le pare-feu du fournisseur) |
| Un compte de courriel (SMTP) | Serveur, port, identifiant, mot de passe, adresse d'expéditeur (voir « Courriel avec Resend » plus bas) |
| Une adresse pour l'administrateur de la plateforme | Son identifiant de connexion |

### Étape 0. Le nom de domaine

Chez le fournisseur du domaine, créez un enregistrement :

| Type | Nom | Valeur |
|---|---|---|
| A | `vote` (pour `vote.example.org`) | l'adresse IPv4 publique du serveur |

Gardez-le en « DNS seulement » (pas de proxy du fournisseur) : le certificat HTTPS est obtenu par le serveur lui-même.
Vérifiez, depuis n'importe quel ordinateur (la réponse doit être l'adresse du serveur) :

```bash
getent hosts vote.example.org
```

### Étape 1. Regarder le serveur

Connectez-vous en SSH au serveur, puis :

```bash
cat /etc/os-release | head -2; nproc; free -m | head -2; df -h / | tail -1
```

```bash
docker ps --format 'table {{.Names}}\t{{.Ports}}' 2>/dev/null || echo "Docker n'est pas installé (l'installateur le fera)"
```

**Lisez la colonne des ports.** Si une ligne contient `0.0.0.0:80->80` et `0.0.0.0:443->443`, un autre programme (souvent
Nginx Proxy Manager) occupe déjà ces ports : suivez la **variante B**. Sinon, **variante A**.

### Étape 2. Télécharger et vérifier

Remplacez `1.0.0-rc.5` par la version voulue.

```bash
cd ~ && wget -q https://github.com/jun101/VotingSystem/releases/download/v1.0.0-rc.5/votesystem-online-1.0.0-rc.5.tar.gz{,.sha256}
```

```bash
sha256sum -c votesystem-online-1.0.0-rc.5.tar.gz.sha256 && tar -xzf votesystem-online-1.0.0-rc.5.tar.gz && cd votesystem-online-1.0.0-rc.5
```

Vous devez lire `...tar.gz: OK`. Sinon, retéléchargez.

### Étape 3. Regarder avant d'installer (ne change rien)

Variante A (VoteSystem prend les ports 80 et 443) :

```bash
sudo ./install-online.sh --dry-run --domain vote.example.org --admin-email admin@example.org
```

Variante B (derrière Nginx Proxy Manager, ajoutez `--behind-proxy`, et le nom du réseau Docker de NPM) :

```bash
sudo ./install-online.sh --dry-run --behind-proxy --proxy-network npm_network --domain vote.example.org --admin-email admin@example.org
```

Le nom du réseau de NPM : `docker inspect npm --format '{{json .NetworkSettings.Networks}}'` (le nom du conteneur NPM peut
être différent, voir `docker ps`). Lisez le plan : site, version, administrateur, courriel, dossier `/opt/votesystem`.

### Étape 4. Installer

La même commande, sans `--dry-run`. Variante A :

```bash
sudo ./install-online.sh --domain vote.example.org --admin-email admin@example.org
```

Variante B :

```bash
sudo ./install-online.sh --behind-proxy --proxy-network npm_network --domain vote.example.org --admin-email admin@example.org
```

Questions posées :

| Question | Réponse |
|---|---|
| Installer Docker ? (s'il manque) | `o` |
| Un autre proxy fera le HTTPS ? (variante B) | `o` |
| Serveur SMTP | par exemple `smtp.resend.com` |
| Port | `465` |
| Chiffrement | `tls` |
| Identifiant | par exemple `resend` |
| Mot de passe | la clé d'API (saisie invisible) |
| Adresse de l'expéditeur | **une adresse complète** du domaine vérifié, par exemple `vote@example.org` |

Attention à la dernière : ce doit être une adresse (avec `@`), pas le nom du site.

L'installation prend 3 à 5 minutes (téléchargement des images, migrations). À la fin, deux fichiers sont écrits dans votre
dossier personnel : `votesystem-identifiants.txt` (mot de passe provisoire de l'administrateur) et
`votesystem-cle-audit-A-EMPORTER.txt` (la **clé privée** de l'audit). À la question « Supprimer le fichier maintenant ? »,
répondez **N** : copiez d'abord la clé (étape 6).

### Étape 5 (variante B seulement). Nginx Proxy Manager

Si vous avez utilisé `--proxy-network`, le conteneur du proxy de VoteSystem est déjà sur le réseau de NPM. Dans NPM
(**Hosts**, **Proxy Hosts**, **Add Proxy Host**) :

| Onglet | Champ | Valeur |
|---|---|---|
| Details | Domain Names | `vote.example.org` |
| Details | Scheme | `http` |
| Details | Forward Hostname / IP | `votesystem-proxy-1` |
| Details | Forward Port | `80` |
| Details | Block Common Exploits | activé |
| Details | Websockets, Cache Assets | désactivés |
| SSL | SSL Certificate | Request a new SSL Certificate (Let's Encrypt) |
| SSL | Force SSL, HTTP/2 | activés |
| SSL | HSTS | **désactivé** (VoteSystem l'envoie déjà) |
| Advanced | Custom Nginx Configuration | `client_max_body_size 10m;` |

Enregistrez. Le certificat est obtenu en une vingtaine de secondes.

Pour que les limites par adresse comptent chaque visiteur (et non NPM tout entier), le proxy de VoteSystem fait déjà
confiance à l'adresse transmise par NPM, car elle vient d'une adresse privée.

### Étape 6. Mettre la clé d'audit à l'abri

La clé permet de relire l'audit des votes. Elle ne doit pas rester sur le serveur.

Sur **votre ordinateur** (pas le serveur) :

```bash
scp UTILISATEUR@ADRESSE_DU_SERVEUR:votesystem-cle-audit-A-EMPORTER.txt ~/votesystem-cle-audit-A-EMPORTER.txt
```

Comparez les empreintes. Sur votre ordinateur :

```bash
sha256sum ~/votesystem-cle-audit-A-EMPORTER.txt
```

Sur le serveur :

```bash
sha256sum ~/votesystem-cle-audit-A-EMPORTER.txt
```

Les deux lignes doivent être identiques. Faites une **deuxième copie** ailleurs (gestionnaire de mots de passe, clé USB
chiffrée). Puis, sur le serveur :

```bash
shred -u ~/votesystem-cle-audit-A-EMPORTER.txt
```

### Étape 7. Vérifier et première connexion

```bash
sudo votectl status
```

Tous les services doivent être `healthy`. Test du courriel (vous devez recevoir un message) :

```bash
sudo votectl mail test VOTRE_ADRESSE
```

Ouvrez `https://vote.example.org/login`. Connectez-vous avec l'adresse de l'administrateur et le mot de passe du fichier
`votesystem-identifiants.txt` (lisez-le dans votre terminal, ne le copiez nulle part). Choisissez un nouveau mot de passe, puis
activez la **double authentification** : scannez le code QR avec une application d'authentification, saisissez le code,
gardez les codes de récupération. Le bouton **« Accéder à la plateforme »** apparaît ensuite. Pour finir, effacez le fichier
d'identifiants :

```bash
shred -u ~/votesystem-identifiants.txt
```

### Étape 8. Sauvegardes

Une sauvegarde à la main (le chemin et la somme de contrôle sont affichés) :

```bash
sudo votectl backup
```

Une par nuit, et gardez 14 jours (deux lignes dans un fichier `cron`) :

```bash
printf '%s\n' '0 3 * * * root /usr/local/bin/votectl backup >> /var/log/votesystem-backup.log 2>&1' '30 3 * * * root find /var/backups/votesystem -name "votesystem-backup-*" -mtime +14 -delete' | sudo tee /etc/cron.d/votesystem-backup
```

Les archives sont lisibles par `root` seulement. Pour pouvoir les copier hors du serveur, faites une copie lisible par votre
utilisateur chaque nuit (une ligne de plus) :

```bash
sudo mkdir -p ~/backups/votesystem && printf '%s\n' "10 3 * * * root cp -u /var/backups/votesystem/votesystem-backup-* $HOME/backups/votesystem/ && chown -R $USER:$USER $HOME/backups && chmod 700 $HOME/backups $HOME/backups/votesystem" | sudo tee -a /etc/cron.d/votesystem-backup
```

Pour la première copie, sans attendre la nuit :

```bash
sudo sh -c "cp -u /var/backups/votesystem/votesystem-backup-* $HOME/backups/votesystem/ && chown -R $USER:$USER $HOME/backups && chmod 700 $HOME/backups $HOME/backups/votesystem"
```

Puis, sur **votre ordinateur**, à la main ou dans un `cron` :

```bash
mkdir -p ~/backups/votesystem-vps && scp 'UTILISATEUR@ADRESSE_DU_SERVEUR:backups/votesystem/votesystem-backup-*' ~/backups/votesystem-vps/ && (cd ~/backups/votesystem-vps && sha256sum -c *.sha256)
```

Vous devez lire `...tar: OK`. Une sauvegarde qui reste sur le même disque ne protège pas de la perte du serveur.
Restauration : [docs/ops/backup-restore.md](../ops/backup-restore.md).

### Mettre à jour

Téléchargez, vérifiez et décompressez la nouvelle version comme à l'étape 2, puis (la sauvegarde est faite d'abord, et le
retour à la version précédente est automatique en cas d'échec) :

```bash
cd ~/votesystem-online-X.Y.Z && sudo ./install-online.sh update --archive ~/votesystem-online-X.Y.Z.tar.gz
```

Variante : sans télécharger à la main, `sudo votectl update --version X.Y.Z`.

Après la mise à jour, vérifiez :

```bash
sudo votectl status && curl -sI https://vote.example.org/api/v1/health | head -3
```

On doit lire `HTTP/2 200`.

### Désinstaller

```bash
sudo ./install-online.sh uninstall
```

Les données sont conservées ; ajoutez `--purge-data` pour tout effacer.

### Courriel avec Resend

| Réglage | Valeur |
|---|---|
| Serveur | `smtp.resend.com` |
| Port | `465` (conseillé : chiffré dès le début de la connexion) ; `587` avec `starttls` si 465 est bloqué |
| Identifiant | `resend` |
| Mot de passe | une clé d'API Resend (droit d'envoi suffit) |
| Expéditeur | une adresse du **domaine vérifié** dans Resend, par exemple `vote@example.org` |

Resend refuse un expéditeur dont le domaine n'est pas vérifié. Pour changer l'expéditeur après l'installation :

```bash
sudo sed -i 's/^MAIL_FROM_ADDRESS=.*/MAIL_FROM_ADDRESS=vote@example.org/' /opt/votesystem/.env && cd /opt/votesystem && sudo docker compose -f compose.prod.yaml up -d api queue scheduler
```

### Si quelque chose ne va pas

| Vous voyez | Cause probable | Faites |
|---|---|---|
| `502 Bad Gateway` depuis NPM | NPM ne joint pas VoteSystem | Vérifiez que le conteneur `votesystem-proxy-1` et `npm` sont sur le même réseau (`docker network inspect NOM_DU_RESEAU`) et que le port est `80` |
| `RfcComplianceException ... addr-spec` au test du courriel | L'expéditeur n'est pas une adresse | Voir « Courriel avec Resend » : mettre une adresse complète |
| Le certificat n'est pas obtenu | Le DNS ne pointe pas encore sur le serveur, ou le port 80 est fermé | `getent hosts NOM`, pare-feu du fournisseur |
| `ports 80 et 443 indisponibles` | Un autre proxy les utilise | Variante B (`--behind-proxy`) |
| Un service n'est pas `healthy` | | `sudo votectl logs` puis le nom du service, par exemple `sudo votectl logs api` |
| Page « Cette page ne peut pas se charger » sur `/platform` | Version trop ancienne | Mettre à jour vers 1.0.0-rc.5 ou plus récent |

---

## English

### What you need

| | |
|---|---|
| A server | Debian 12 or 13, or Ubuntu 24.04 or 26.04, 64-bit, at least **2 CPUs, 4 GB of memory, 20 GB of disk** |
| Access | A user who can use `sudo`, connected over SSH |
| A domain name | **One only**, for example `vote.example.org`, with an **A** record pointing to the server's address |
| Ports | 80 and 443 reachable (also in the provider's firewall) |
| An email (SMTP) account | Server, port, user name, password, sender address (see "Email with Resend" below) |
| An address for the platform admin | Their sign-in name |

### Step 0. The domain name

At your domain's provider, create a record:

| Type | Name | Value |
|---|---|---|
| A | `vote` (for `vote.example.org`) | the server's public IPv4 address |

Keep it "DNS only" (no proxy from the provider): the HTTPS certificate is obtained by the server itself. Check from any
computer (the answer must be the server's address):

```bash
getent hosts vote.example.org
```

### Step 1. Look at the server

Connect to the server over SSH, then:

```bash
cat /etc/os-release | head -2; nproc; free -m | head -2; df -h / | tail -1
```

```bash
docker ps --format 'table {{.Names}}\t{{.Ports}}' 2>/dev/null || echo "Docker is not installed (the installer will do it)"
```

**Read the ports column.** If a line contains `0.0.0.0:80->80` and `0.0.0.0:443->443`, another program (often Nginx Proxy
Manager) already holds these ports: follow **variant B**. Otherwise, **variant A**.

### Step 2. Download and check

Replace `1.0.0-rc.5` with the version you want.

```bash
cd ~ && wget -q https://github.com/jun101/VotingSystem/releases/download/v1.0.0-rc.5/votesystem-online-1.0.0-rc.5.tar.gz{,.sha256}
```

```bash
sha256sum -c votesystem-online-1.0.0-rc.5.tar.gz.sha256 && tar -xzf votesystem-online-1.0.0-rc.5.tar.gz && cd votesystem-online-1.0.0-rc.5
```

You must read `...tar.gz: OK`. Otherwise, download again.

### Step 3. Look before installing (changes nothing)

Variant A (VoteSystem takes ports 80 and 443):

```bash
sudo ./install-online.sh --dry-run --domain vote.example.org --admin-email admin@example.org
```

Variant B (behind Nginx Proxy Manager: add `--behind-proxy` and the name of NPM's Docker network):

```bash
sudo ./install-online.sh --dry-run --behind-proxy --proxy-network npm_network --domain vote.example.org --admin-email admin@example.org
```

NPM's network name: `docker inspect npm --format '{{json .NetworkSettings.Networks}}'` (NPM's container name may differ, see
`docker ps`). Read the plan: site, version, admin, email, folder `/opt/votesystem`.

### Step 4. Install

The same command, without `--dry-run`. Variant A:

```bash
sudo ./install-online.sh --domain vote.example.org --admin-email admin@example.org
```

Variant B:

```bash
sudo ./install-online.sh --behind-proxy --proxy-network npm_network --domain vote.example.org --admin-email admin@example.org
```

Questions asked:

| Question | Answer |
|---|---|
| Install Docker? (when missing) | `y` |
| Another proxy does the HTTPS? (variant B) | `y` |
| SMTP server | for example `smtp.resend.com` |
| Port | `465` |
| Encryption | `tls` |
| User name | for example `resend` |
| Password | the API key (typed, not shown) |
| Sender address | **a full address** on the verified domain, for example `vote@example.org` |

Mind the last one: it must be an address (with `@`), not the site's name.

The installation takes 3 to 5 minutes (image download, migrations). At the end two files are written in your home folder:
`votesystem-identifiants.txt` (the admin's temporary password) and `votesystem-cle-audit-A-EMPORTER.txt` (the audit's
**private key**). To "Delete the file now?" answer **N**: copy the key first (step 6).

### Step 5 (variant B only). Nginx Proxy Manager

If you used `--proxy-network`, VoteSystem's proxy container is already on NPM's network. In NPM
(**Hosts**, **Proxy Hosts**, **Add Proxy Host**):

| Tab | Field | Value |
|---|---|---|
| Details | Domain Names | `vote.example.org` |
| Details | Scheme | `http` |
| Details | Forward Hostname / IP | `votesystem-proxy-1` |
| Details | Forward Port | `80` |
| Details | Block Common Exploits | on |
| Details | Websockets, Cache Assets | off |
| SSL | SSL Certificate | Request a new SSL Certificate (Let's Encrypt) |
| SSL | Force SSL, HTTP/2 | on |
| SSL | HSTS | **off** (VoteSystem already sends it) |
| Advanced | Custom Nginx Configuration | `client_max_body_size 10m;` |

Save. The certificate is obtained in about twenty seconds.

So that the per-address limits count each visitor (and not NPM as a whole), VoteSystem's proxy already trusts the address NPM
forwards, because it comes from a private address.

### Step 6. Put the audit key somewhere safe

The key lets you read the vote audit. It must not stay on the server.

On **your computer** (not the server):

```bash
scp USER@SERVER_ADDRESS:votesystem-cle-audit-A-EMPORTER.txt ~/votesystem-cle-audit-A-EMPORTER.txt
```

Compare the fingerprints. On your computer:

```bash
sha256sum ~/votesystem-cle-audit-A-EMPORTER.txt
```

On the server:

```bash
sha256sum ~/votesystem-cle-audit-A-EMPORTER.txt
```

The two lines must be identical. Make a **second copy** elsewhere (password manager, encrypted USB key). Then, on the
server:

```bash
shred -u ~/votesystem-cle-audit-A-EMPORTER.txt
```

### Step 7. Check and first sign-in

```bash
sudo votectl status
```

Every service must be `healthy`. Email test (you must receive a message):

```bash
sudo votectl mail test YOUR_ADDRESS
```

Open `https://vote.example.org/login`. Sign in with the admin address and the password from `votesystem-identifiants.txt`
(read it in your terminal, do not copy it anywhere). Choose a new password, then turn on **two-factor authentication**: scan
the QR code with an authenticator app, type the code, keep the recovery codes. The **"Go to the platform"** button then
appears. Finally, erase the credentials file:

```bash
shred -u ~/votesystem-identifiants.txt
```

### Step 8. Backups

A backup by hand (the path and the checksum are printed):

```bash
sudo votectl backup
```

One every night, keeping 14 days (two lines in a `cron` file):

```bash
printf '%s\n' '0 3 * * * root /usr/local/bin/votectl backup >> /var/log/votesystem-backup.log 2>&1' '30 3 * * * root find /var/backups/votesystem -name "votesystem-backup-*" -mtime +14 -delete' | sudo tee /etc/cron.d/votesystem-backup
```

The archives are readable by `root` only. To be able to copy them off the server, make a copy readable by your user each
night (one more line):

```bash
sudo mkdir -p ~/backups/votesystem && printf '%s\n' "10 3 * * * root cp -u /var/backups/votesystem/votesystem-backup-* $HOME/backups/votesystem/ && chown -R $USER:$USER $HOME/backups && chmod 700 $HOME/backups $HOME/backups/votesystem" | sudo tee -a /etc/cron.d/votesystem-backup
```

For the first copy, without waiting for the night:

```bash
sudo sh -c "cp -u /var/backups/votesystem/votesystem-backup-* $HOME/backups/votesystem/ && chown -R $USER:$USER $HOME/backups && chmod 700 $HOME/backups $HOME/backups/votesystem"
```

Then, on **your computer**, by hand or in a `cron`:

```bash
mkdir -p ~/backups/votesystem-vps && scp 'USER@SERVER_ADDRESS:backups/votesystem/votesystem-backup-*' ~/backups/votesystem-vps/ && (cd ~/backups/votesystem-vps && sha256sum -c *.sha256)
```

You must read `...tar: OK`. A backup that stays on the same disk does not protect you from losing the server. Restore:
[docs/ops/backup-restore.md](../ops/backup-restore.md).

### Update

Download, check and unpack the new version as in step 2, then (the backup is made first, and the return to the previous
version is automatic on failure):

```bash
cd ~/votesystem-online-X.Y.Z && sudo ./install-online.sh update --archive ~/votesystem-online-X.Y.Z.tar.gz
```

Alternative: without downloading by hand, `sudo votectl update --version X.Y.Z`.

After the update, check:

```bash
sudo votectl status && curl -sI https://vote.example.org/api/v1/health | head -3
```

You must read `HTTP/2 200`.

### Uninstall

```bash
sudo ./install-online.sh uninstall
```

The data is kept; add `--purge-data` to erase everything.

### Email with Resend

| Setting | Value |
|---|---|
| Server | `smtp.resend.com` |
| Port | `465` (recommended: encrypted from the start of the connection); `587` with `starttls` if 465 is blocked |
| User name | `resend` |
| Password | a Resend API key (sending access is enough) |
| Sender | an address on the domain **verified** in Resend, for example `vote@example.org` |

Resend refuses a sender whose domain is not verified. To change the sender after the installation:

```bash
sudo sed -i 's/^MAIL_FROM_ADDRESS=.*/MAIL_FROM_ADDRESS=vote@example.org/' /opt/votesystem/.env && cd /opt/votesystem && sudo docker compose -f compose.prod.yaml up -d api queue scheduler
```

### When something is wrong

| You see | Likely cause | Do |
|---|---|---|
| `502 Bad Gateway` from NPM | NPM cannot reach VoteSystem | Check that `votesystem-proxy-1` and `npm` share a network (`docker network inspect NETWORK_NAME`) and that the port is `80` |
| `RfcComplianceException ... addr-spec` in the email test | The sender is not an address | See "Email with Resend": use a full address |
| The certificate is not obtained | DNS does not point to the server yet, or port 80 is closed | `getent hosts NAME`, the provider's firewall |
| `ports 80 and 443 unavailable` | Another proxy uses them | Variant B (`--behind-proxy`) |
| A service is not `healthy` | | `sudo votectl logs`, then the service name, for example `sudo votectl logs api` |
| "This page couldn't load" on `/platform` | Version too old | Update to 1.0.0-rc.5 or later |
