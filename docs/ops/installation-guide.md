# Guide d'installation / Installation guide

Trois guides pas à pas, pour une personne qui sait utiliser un ordinateur : chaque commande est dans son propre bloc, avec ce
que vous devez voir après. Choisissez celui qui correspond à votre situation. / Three step-by-step guides, for a person who can
use a computer: each command is in its own block, with what you should see afterwards. Pick the one that matches your
situation.

| Situation | Guide | Il vous faut / You need |
|---|---|---|
| Un serveur Linux dans l'établissement, sans internet / A Linux server in the institution, no internet | [Installer sur Linux (école)](../guides/install-school-linux.md) | Debian 12 ou 13, Ubuntu 24.04 ou 26.04, 4 Go, 5 Go libres |
| Un ordinateur Windows dans l'établissement / A Windows computer in the institution | [Installer sur Windows (école)](../guides/install-school-windows.md) | Windows 10 (1809+), 11 ou Server 2019+, 4 Go, 5 Go libres |
| Un serveur en ligne avec un nom de domaine / An online server with a domain name | [Installer en ligne (VPS)](../guides/install-online-vps.md) | Debian ou Ubuntu, 2 CPU, 4 Go, 20 Go, un domaine, un compte SMTP |

Chaque guide contient, en français puis en anglais : le téléchargement et la vérification, un essai à blanc, l'installation,
la mise à l'abri de la clé d'audit, la première connexion avec la double authentification, les sauvegardes, la mise à jour, la
désinstallation et les problèmes courants. / Each guide contains, in French then English: the download and its check, a
dry run, the installation, putting the audit key away, the first sign-in with two-factor authentication, backups, update,
uninstall and common problems.

## Aussi / Also

- Le même guide est dans l'application, pour le propriétaire et l'administrateur connectés : menu utilisateur,
  « Guide d'utilisation », « Installation » (`/guide/install`). / The same guide is in the application, for the signed-in
  owner and platform admin: user menu, "Guide d'utilisation", "Installation" (`/guide/install`).
- Sauvegarde et restauration / Backup and restore : [backup-restore.md](backup-restore.md)
- Déploiement en production sur un VPS, liste de contrôle de mise en service / Production deployment on a VPS, go-live
  checklist : [production-vps.md](production-vps.md)
- Test de charge / Load test : [load-test.md](load-test.md)
- Rapport des derniers tests / Report of the latest tests : [test-report-2026-10-14.md](test-report-2026-10-14.md)

## Ce qui a été essayé / What was tried

| Système / System | Résultat / Result |
|---|---|
| Debian 12, Debian 13, Ubuntu 24.04.5, Ubuntu 26.04.1 (machines virtuelles neuves / fresh virtual machines) | Installation, parcours de vote complet, redémarrage, mise à jour, retour arrière, désinstallation : réussi / install, full vote flow, restart, update, rollback, uninstall: passed |
| Windows 11 (machine virtuelle / virtual machine) | Installation en 71 s, parcours de vote, mise à jour, retour arrière, sauvegarde et restauration, désinstallation : réussi / passed |
| En ligne, VPS réel avec domaine et HTTPS (derrière Nginx Proxy Manager), courriel par Resend / Online, a real VPS with a domain and HTTPS (behind Nginx Proxy Manager), mail through Resend | Installation, connexion, double authentification, sauvegarde et copie hors serveur : réussi / install, sign-in, two-factor, backup and off-server copy: passed |
