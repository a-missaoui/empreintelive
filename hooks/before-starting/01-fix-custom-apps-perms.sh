#!/bin/sh
# S'execute en root AVANT le demarrage d'Apache, a chaque boot du conteneur.
# Donne a www-data l'acces en ecriture au dossier custom_apps, afin que
# l'App Store puisse y installer des apps (ex: Calendar officiel).
set -e
mkdir -p /var/www/html/custom_apps
# NON recursif : on rend inscriptible UNIQUEMENT le dossier custom_apps lui-meme
# (suffisant pour que l'App Store y cree de nouveaux dossiers d'app).
# Un "chown -R" toucherait aussi notre app montee en bind-mount et casserait
# les permissions cote hote (fichiers passes en uid www-data).
chown www-data:www-data /var/www/html/custom_apps
exit 0
