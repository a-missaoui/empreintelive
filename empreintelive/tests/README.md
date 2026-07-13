# Tests unitaires — empreintelive

Tests **unitaires** PHP (aucun réseau, aucun serveur Nextcloud booté : uniquement
les autoloaders `OCP\*` / `Sabre\*` + des mocks).

## Couverture

- `Unit/Service/TokenServiceTest.php` — stockage/lecture des tokens, calcul de
  `expires_at`, logique d'expiration (leeway).
- `Unit/Service/OAuthServiceTest.php` — `codeChallenge` PKCE (vecteur RFC 7636),
  `login` (succès / erreurs), `connect` (flux complet + rejet sur `state` altéré).
- `Unit/Service/EmpreinteApiServiceTest.php` — normalisation des réponses Live,
  mapping du payload, retry automatique après refresh sur 401.
- `Unit/Service/CalendarEventServiceTest.php` — écriture / mise à jour / suppression
  de l'événement miroir dans le calendrier (flux inversé, back-fill / prune).
- `Unit/Service/ContactSearchServiceTest.php` — recherche de participants via
  `OCP\Contacts` (mapping nom / email, dédoublonnage).
- `Unit/Service/EmpreinteLiveIdTest.php` — extraction du `liveId` depuis un `.ics`
  (`X-EMPREINTE-*`, `LOCATION`, `DESCRIPTION`), cas sans identifiant.
- `Unit/Listener/CalendarObjectListenerTest.php` — dispatch selon l'événement
  (corbeille / suppression / édition), `userId` depuis le `principaluri`,
  événement non concerné ignoré.

## Lancer les tests

Il n'y a pas de PHP sur l'hôte : on exécute dans le conteneur `empreinte-nextcloud`
(qui fournit PHP + les autoloaders Nextcloud).

```bash
# 1) Récupérer PHPUnit dans le conteneur (une fois par cycle de vie du conteneur)
docker exec empreinte-nextcloud sh -c \
  'curl -sSfL -o /tmp/phpunit.phar https://phar.phpunit.de/phpunit-10.phar'

# 2) Lancer la suite
docker exec -w /var/www/html/custom_apps/empreintelive/tests \
  empreinte-nextcloud php /tmp/phpunit.phar --configuration phpunit.xml
```

Avec un environnement disposant de Composer :
`composer install && composer test:unit`.
