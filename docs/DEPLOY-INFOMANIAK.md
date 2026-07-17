# Déploiement — Infomaniak (preprod → prod), migration depuis online.net

Contexte : site actuellement en prod sur **online.net (Dedibox, offre historique)**, core
legacy. Version modernisée montée en **local (MAMP, WP 7.0.1)**. Objectif : mettre la version
modernisée en **preprod sur Infomaniak**, la recetter, puis **basculer le domaine** depuis
online.net.

⚠️ **Rappel structure repo** : git ne versionne QUE le code custom (`wp-content/mu-plugins/`,
`composer.json`, `.htaccess.example`, `docs/`, `bin/`). Le **core WP, les plugins, les thèmes
et `wp-content/uploads/` ne sont PAS dans git** → ils se transfèrent par rsync/backup, pas par
`git pull`. La **source de vérité du contenu = l'install local modernisée** (DB + uploads).
`online.net` = l'ancienne prod à remplacer.

---

## A. Comptes & accès Infomaniak

- [ ] Commander l'hébergement (Hébergement Web / Managed — vérifier : **SSH**, **Composer**,
      **WP-CLI**, **Git**, choix de la **version PHP**, **MySQL/MariaDB**).
- [ ] Créer une **clé SSH** dédiée et l'ajouter dans le Manager. Tester `ssh` + `wp --info`.
- [ ] Noter les infos serveur : chemin racine du site, **hôte DB** (Infomaniak = hôte distant
      type `xxxxx.myd.infomaniak.com`, PAS `localhost`), nom DB, user DB.
- [ ] Choisir la **version PHP = 8.4** (matcher le local ; ≥ 8.1 minimum pour WP 7.0.1).

## B. Environnement serveur

- [ ] Sous-domaine preprod (ex. `preprod.cedricrivrain.com`) OU domaine temporaire Infomaniak.
- [ ] **SSL Let's Encrypt** activé sur le sous-domaine preprod.
- [ ] DB preprod créée + user + mot de passe (mémo hors repo).
- [ ] Confirmer que `wp` (WP-CLI) et `composer` répondent en SSH.
- [ ] (Option) Utiliser la **fonction preprod/clone WordPress** native d'Infomaniak si tu pars
      d'un WP déjà installé chez eux — sinon setup manuel ci-dessous.

## C. Premier déploiement preprod

Ordre : fichiers → DB → config → réécriture d'URL → caches.

- [ ] **Code custom** : `git clone`/`git pull` du repo dans la racine du site.
- [ ] **Core + plugins + thèmes** : deux options —
      - Simple/fidèle : **rsync** de l'install local (`wp-admin/`, `wp-includes/`, `wp-*.php`,
        `wp-content/plugins`, `wp-content/themes`) vers le serveur. ✅ reproduit l'existant.
      - « Recette » composer : `composer install` **seulement quand on sera passé en structure
        Bedrock** (aujourd'hui l'index a `wordpress-install-dir: wp` → mettrait le core dans
        `wp/`, ≠ install racine actuelle). ➜ pour la preprod root, rester sur rsync.
- [ ] **Uploads** : `rsync` de `wp-content/uploads/` (~349 Mo) depuis le local.
      `rsync -avz --progress wp-content/uploads/ user@host:.../wp-content/uploads/`
- [ ] **`wp-config.php`** : NE PAS copier celui du local. En créer un **propre à la preprod**
      (DB Infomaniak, nouveaux **salts** via https://api.wordpress.org/secret-key/1.1/salt/,
      `WP_ENVIRONMENT_TYPE = 'staging'`, `WP_DEBUG = false`). Fichier hors git (déjà gitignoré).
- [ ] **`.htaccess`** : `cp .htaccess.example .htaccess` (durci, sans `AddType php5`).
- [ ] **DB** : dump local → import preprod.
      `mysqldump ... cedricrivrain_local | gzip > dump.sql.gz` puis import côté Infomaniak.
- [ ] **Réécriture d'URL** (via le script) : `cedricrivrain.local` → URL preprod, sur toutes
      les tables, `--skip-columns=guid`. Vérifier ensuite `siteurl`/`home`.
- [ ] **Permissions** : dossiers 755, fichiers 644, `wp-config.php` 640, `wp-content/uploads`
      inscriptible par PHP.
- [ ] Purger les caches (script) et charger la home.

## D. Durcissement PREPROD (rester non-public)

- [ ] **Bloquer l'indexation** : `wp option update blog_public 0` (le script le fait en `--env=preprod`).
- [ ] **HTTP Basic Auth** sur tout le sous-domaine (via le Manager Infomaniak ou `.htpasswd`).
      C'est LA barrière qui garde la preprod privée.
- [ ] **Emails** : empêcher tout envoi vers de vrais destinataires depuis la preprod
      (plugin type « WP Mail Logging »/« Stop Emails », ou filtre `wp_mail` no-op en mu-plugin).
- [ ] Vérifier que **Wordfence** n'envoie pas d'alertes prod, que **Redirection** n'a pas de
      règles pointant vers le domaine final.

## E. Config plugins sur le serveur (cf. BACKLOG « avant prod »)

- [ ] **Wordfence** : WAF + premier scan.
- [ ] **WP Super Cache** : mode *Simple* + activer le cache (`WP_CACHE` défini dans wp-config).
- [ ] **Autoptimize** : agrégation OFF, minif ON (déjà en place en local) ; vérifier le slider
      MetaSlider vs « defer inline JS » (cf. backlog).
- [ ] **Redirection** : lancer l'assistant (tables).
- [ ] Vérifier l'entrée admin bar unique « Clear cache » (mu-plugin `tr-cache`).

## F. Recette (smoke tests)

- [ ] Home 200, rendu OK (favicon, lien Fitzpatrick Gallery, CSS Make).
- [ ] Login admin OK, pas d'erreur PHP (activer `WP_DEBUG_LOG` temporairement).
- [ ] `wp core verify-checksums` OK.
- [ ] Permaliens OK (une page interne se charge).
- [ ] Test cache : « Clear cache » → home se régénère sans casse CSS/JS.
- [ ] Vérifier le `robots`/noindex actif.

## G. Bascule online.net → Infomaniak

- [ ] **Avant** : baisser le **TTL DNS** du domaine à ~300 s, 24–48 h à l'avance.
- [ ] Rejouer un **sync final** (uploads + DB) si du contenu a bougé sur online.net (peu
      probable pour une vitrine, mais vérifier).
- [ ] Sur Infomaniak : pointer le **domaine final** `cedricrivrain.com`, SSL Let's Encrypt.
- [ ] **Réécriture d'URL finale** : preprod URL → `https://cedricrivrain.com`.
- [ ] Retirer le **HTTP Basic Auth** et remettre `blog_public = 1`.
- [ ] Bascule **DNS** (A/AAAA/CNAME) vers Infomaniak. Attendre la propagation (TTL).
- [ ] Post-go-live : re-checksums, purge caches, tester la home publique, remonter le TTL,
      revérifier les redirections et le SEO (sitemap Yoast, robots).
- [ ] Garder online.net **en lecture** quelques jours (rollback), puis résilier la Dedibox.

## H. Rollback

- [ ] Snapshot **DB + uploads** de la preprod avant chaque déploiement (le script logue ; faire
      le dump avant).
- [ ] En cas de souci au go-live : re-pointer le DNS vers online.net (TTL bas = retour rapide).

---

## Script de release

`bin/deploy.php` automatise les étapes répétables **sur le serveur** (maintenance, git pull,
`core update-db`, réécriture d'URL en `--first-run`, purge caches, `.htaccess`, noindex preprod).
Voir l'en-tête du script pour l'usage. Le **transfert de fichiers** (rsync core/plugins/thèmes/
uploads) et l'**import DB** restent manuels (une fois), car hors périmètre git.

### Notes terrain (Infomaniak, vécu au 1er déploiement preprod)

- **rsync macOS = openrsync** : refuse `--info`, `--partial`, etc. Transfert fiable via
  `tar czf - --exclude=… . | ssh HOST 'tar xzf - -C ~/site'`.
- **⚠️ tar macOS + AppleDouble** : le `tar` macOS embarque des fichiers `._*` (métadonnées
  xattr `com.apple.provenance`). WordPress charge TOUS les `.php` de `mu-plugins/` → il charge
  `._tr-cache.php` (binaire) et l'affiche en haut des pages. **Préfixer par `COPYFILE_DISABLE=1 tar …`**,
  et/ou nettoyer côté serveur : `find <docroot> -name '._*' -delete; find <docroot> -name '.DS_Store' -delete`.
- **WP-CLI absent du serveur** : copier le phar local → `scp $(command -v wp) HOST:bin/wp` puis
  `chmod +x ~/bin/wp` (le serveur a PHP 8.4, git, rsync, mysql, composer).
- **Auth** : ajouter sa clé SSH via `ssh-copy-id` (le Manager n'expose pas toujours le champ clés).
- **DB** : bien **associer l'utilisateur à la base** dans le Manager (sinon MySQL 1044). Le mot de
  passe se pose côté serveur (`wp config set DB_PASSWORD …`), jamais partagé.
- **`DISABLE_WP_CRON=true`** dans wp-config : SANS ça, les commandes wp-cli qui bootstrappent WP
  **hangent** (loopback cron vers l'ancienne URL injoignable). À mettre avant tout `wp option/search-replace`.
- Docroot preprod réel : `~/cedricrivrain/preprod` (le chemin Manager est relatif au home).
- **Yoast + `WP_ENVIRONMENT_TYPE`** : Yoast ne (re)construit ses indexables **qu'en `production`**. En
  `staging`, il gèle la table `wp_yoast_indexable` → surcharges SEO (titre/description) ignorées en front.
  Pour recetter le SEO, mettre `WP_ENVIRONMENT_TYPE='production'` (la preprod reste privée via
  `blog_public=0` + Basic Auth) puis `wp yoast index --reindex --skip-confirmation` après tout import.
- **Cron** : avec `DISABLE_WP_CRON=true`, brancher un déclencheur externe (Infomaniak = webcron).
  Tâche cron → URL `…/wp-cron.php?doing_wp_cron=1` toutes les 15 min. **Si le site est derrière
  Basic Auth**, exempter wp-cron.php dans `.htaccess` (sinon 401) :
  `<Files "wp-cron.php"> Require all granted </Files>`. Alternative : cron en commande
  `/opt/php8.4/bin/php <home>/bin/wp cron event run --due-now --path=<docroot> --quiet` (pas d'exception .htaccess).

```bash
# depuis la racine du site, sur le serveur Infomaniak :
php bin/deploy.php --env=preprod --dry-run           # simulation
php bin/deploy.php --env=preprod --first-run \
    --url-from=https://cedricrivrain.local \
    --url-to=https://preprod.cedricrivrain.com       # 1er déploiement (avec search-replace)
php bin/deploy.php --env=preprod                     # déploiements suivants (code + caches)
```
