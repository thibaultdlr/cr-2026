# Cache — architecture, réglages et mise en prod

Doc de référence sur le cache pour ce site (préprod **et** prod). Objectif : que la prod soit
cachée proprement, sans les pièges rencontrés en preprod. Statut : preprod en ligne (derrière
Basic Auth), migration DNS de `cedricrivrain.com` en cours.

## 1. Vue d'ensemble — 3 couches

| Couche | Rôle | Où |
|---|---|---|
| **Autoptimize** | minifie/diffère le CSS/JS, cache d'**assets** | `wp-content/cache/autoptimize/` |
| **WP Super Cache** | sert des **pages HTML statiques** en cache | `wp-content/cache/` + drop-in `advanced-cache.php` |
| **mu-plugin `tr-cache`** | **purge unifiée dans le bon ordre** + 1 entrée admin bar « Vider le cache » | `wp-content/mu-plugins/tr-cache.php` (versionné) |

Ordre de purge (géré par le mu-plugin) : **assets (Autoptimize) → données → pages (WP Super Cache) en dernier**.
Voir `\TR\Cache\clear_all()` / helper `tr_clear_caches()`. Détail du « pourquoi cet ordre » : quand une page
en cache référence un fichier Autoptimize supprimé, on a un 404 CSS/JS (site déstylé) — d'où pages purgées en dernier.

## 2. Constantes dans `wp-config.php` — ⚠️ le piège du wp-config custom

Notre `wp-config.php` est **fait main** (pas celui généré par WP) : il **n'a pas** l'ancre
`/* That's all, stop editing! */`. Conséquence : `wp config set` (et WP Super Cache) ont du mal
à **ajouter** de nouvelles constantes (« Unable to locate placement anchor »). Il faut donc les
poser à la main. Deux constantes nécessaires au cache :

```php
define( 'WP_CACHE', true ); // charge advanced-cache.php (WP Super Cache)
// WPCACHEHOME doit être défini APRÈS ABSPATH ; le placer juste avant le require final :
define( 'WPCACHEHOME', ABSPATH . 'wp-content/plugins/wp-super-cache/' );
require_once ABSPATH . 'wp-settings.php';
```

- **`WP_CACHE`** existe déjà dans notre template (mis à `false` au déploiement) → on le passe à `true` :
  `~/bin/wp config set WP_CACHE true --raw --type=constant` (ça marche car la constante **existe déjà**).
- **`WPCACHEHOME`** : le plugin tente de l'ajouter lui-même (souvent en chemin absolu en haut du fichier,
  ça marche aussi). S'il n'y arrive pas → l'ajouter à la main. Utiliser **`ABSPATH . '…'`** et le placer
  **juste avant** `require_once ABSPATH . 'wp-settings.php';` (à ce point ABSPATH est défini ; en haut du
  fichier il ne l'est pas encore → il faudrait alors le chemin absolu en dur).
- Sans `WPCACHEHOME`, `advanced-cache.php` n'écrit qu'un **commentaire HTML** « installed but broken » —
  **ça ne casse pas le site**, mais le cache de pages ne fonctionne pas.

## 3. WP Super Cache — **mode « Simple » obligatoire**

**Réglages → WP Super Cache → onglet Simple → Mise en cache : Activée → Mettre à jour.**

⚠️ **NE PAS utiliser le mode « Expert »** : il injecte des règles `mod_rewrite` dans le `.htaccess`
pour servir le cache en court-circuitant PHP → **conflit** avec notre `.htaccess` durci (et, en preprod,
avec le Basic Auth). Le mode **Simple** sert le cache via PHP, cohabite proprement.

- Les drop-ins `advanced-cache.php` et `wp-cache-config.php` utilisent des chemins **dynamiques**
  (`WP_CONTENT_DIR`) → portables entre preprod/prod. En cas de doute, les **supprimer** et cliquer
  « Mettre à jour » les régénère proprement.
- **Purge / preload / garbage collection** de WP Super Cache passent par le **cron** → nécessite le
  webcron actif (cf. `DEPLOY-INFOMANIAK.md`, section cron).

## 4. Autoptimize

- **Agréger : OFF**, **Minifier : ON**, **Do not aggregate but defer : ON** (HTTP/2 ; moins de churn).
- **« Also defer inline JS » : ON** actuellement — ⚠️ peut casser l'init inline de MetaSlider (cf. BACKLOG,
  à vérifier/ajuster en v2).
- Purge : le mu-plugin écoute `autoptimize_action_cachepurged` et purge WP Super Cache derrière (glue).

## 5. mu-plugin `tr-cache` (déjà en place, versionné)

- **1 seule entrée admin bar « ⚡ Vider le cache »** (masque celles d'Autoptimize et de WP Super Cache),
  purge tout dans le bon ordre. Cap `manage_options`, nonce.
- Fonction appelable : `\TR\Cache\clear_all()` / `tr_clear_caches()`.
- Hooks d'extension : `tr_cache_before_clear` / `tr_cache_clear_data` / `tr_cache_after_clear`
  (brancher transients / cache objet). `wp_cache_flush()` déjà appelé (prêt si Redis un jour).
- **Le script de déploiement `bin/deploy.php` appelle `tr_clear_caches()`** en fin de release → cache purgé à chaque déploiement.

## 6. Mise en PROD — checklist cache

- [ ] `wp-config.php` prod : `WP_CACHE=true` + `WPCACHEHOME` (cf. §2). `DISABLE_WP_CRON=true` (déjà requis).
- [ ] **Webcron** actif sur `…/wp-cron.php` (sinon purge/preload/GC de WP Super Cache ne tournent pas).
      En prod le site est public → pas besoin de l'exception Basic Auth (le `<Files wp-cron.php>` reste inoffensif).
- [ ] WP Super Cache : **mode Simple**, cache **Activé**, « Mettre à jour ». Durée d'expiration par défaut OK
      pour une vitrine (allonger si souhaité, le contenu bouge peu).
- [ ] Autoptimize : agrégation OFF / minif ON (comme preprod) ; trancher « defer inline JS » vs MetaSlider.
- [ ] Vérifier l'entrée admin bar « Vider le cache » et faire un test : purger → recharger → CSS/JS OK.
- [ ] `bin/deploy.php` purge déjà le cache en fin de release — rien à faire de plus au déploiement.

## 7. Recette / développement — éviter que le cache masque les modifs

Avec le cache de pages actif, une modif peut ne pas apparaître (page servie depuis le cache).
→ **Vider le cache** après chaque changement (bouton « ⚡ Vider le cache »), ou **désactiver** WP Super Cache
le temps de bosser et le réactiver avant la prod. Autoptimize aussi : « Delete all optimized content » si le
CSS/JS ne se met pas à jour.

## 8. Pièges rencontrés (récap)

- **wp-config custom sans ancre** → `wp config set`/plugins ne peuvent pas *ajouter* de constante
  (« placement anchor ») ; poser `WPCACHEHOME` à la main (cf. §2).
- **WP_CACHE=false au déploiement** (volontaire) → notice « WP_CACHE constant set to false » tant qu'on
  ne l'active pas.
- **Mode Expert de WP Super Cache** = conflit `.htaccess` → rester en **Simple**.
- **Basic Auth (preprod)** : bloque le webcron → exception `<Files "wp-cron.php"> Require all granted </Files>`.
  En prod, pas de Basic Auth donc non concerné.
- **Cache + recette** : purger après chaque modif.
