# Go-live PROD — cedricrivrain.com (Infomaniak) + migration DNS depuis online.net

Suite de `DEPLOY-INFOMANIAK.md` (preprod). Ici : construire la **prod** sur Infomaniak à partir
de la **preprod déjà en ligne**, puis **basculer le domaine** `cedricrivrain.com` depuis online.net.

**Point de départ** (au 2026-07-15) : preprod modernisée en ligne sur Infomaniak
(`https://fu9oqnceqzu.preview.infomaniak.website/`, WP 7.0.1 + Make). Ancienne prod encore sur
**online.net (Dedibox)**, vieux core. Le domaine `cedricrivrain.com` appartient à **Cédric (le frère)**
→ il faut son accès registrar/DNS.

> ⚠️ **LE piège n°1 d'une migration DNS : casser les emails.** Le domaine a sûrement des enregistrements
> **MX / SPF (TXT) / DKIM / DMARC** pour la messagerie. Il faut les **inventorier et les préserver**
> AVANT toute bascule, sinon les mails de Cédric tombent.

---

## A. Inventaire & décisions (AVANT de toucher quoi que ce soit)

- [ ] Identifier le **registrar** de `cedricrivrain.com` et **où sa DNS est gérée aujourd'hui**
      (online.net ? autre ?) + récupérer les **accès** (avec Cédric).
- [ ] **Exporter TOUS les enregistrements DNS actuels** (capture ou export de zone) :
      `A`, `AAAA`, `CNAME` (dont `www`), **`MX`**, **`TXT` (SPF, DKIM, DMARC, vérifs)**, `SRV`, `NS`, `CAA`.
      → C'est la référence pour ne rien perdre (surtout le mail).
- [ ] Vérifier ce qui tourne **encore sur online.net** à part le site : **messagerie ?** autres
      sous-domaines ? crons ? → à recréer/rediriger ailleurs si oui.
- [ ] **Choisir la stratégie DNS** :
      - **(1) Repointage simple** (recommandé, le moins risqué) : garder la gestion DNS chez l'hôte
        actuel, ne changer QUE les `A`/`AAAA` (+ `www`) vers Infomaniak. On ne touche PAS aux MX.
      - **(2) Migration de la zone DNS chez Infomaniak** : recréer **tous** les enregistrements
        (dont MX/SPF/DKIM) côté Infomaniak, PUIS changer les `NS` chez le registrar. Plus propre à
        terme, mais tout doit être recréé fidèlement d'abord.
- [ ] Confirmer avec Cédric que le **contenu de la preprod est validé** (sort des vieilles pages,
      cf. BACKLOG) — la preprod est la source de vérité, l'ancien site online.net est figé.

## B. Préparer l'hébergement PROD sur Infomaniak

- [ ] Créer le **site prod** `cedricrivrain.com` dans le Manager (docroot dédié, ≠ preprod).
- [ ] **PHP 8.4** sélectionné pour ce site.
- [ ] **Base prod** créée + **utilisateur associé avec tous les droits** (ne pas réutiliser la base
      preprod). Noter nom/user/host ; le mot de passe reste côté Cédric/toi.
- [ ] Confirmer `~/bin/wp` (WP-CLI) accessible (déjà installé), `git`, `rsync` OK.

## C. Construire la prod depuis la preprod (clone)

> Bonne nouvelle : **côté serveur, le `rsync` est le vrai GNU rsync** — la copie preprod→prod peut
> se faire proprement sans les galères d'openrsync/tar du 1er déploiement.

- [ ] **Fichiers** : `rsync -a --delete ~/cedricrivrain/preprod/ ~/<docroot-prod>/`
      en excluant `wp-config.php`, `.htaccess`, `.maintenance`, `wp-content/cache`, `wflogs`.
- [ ] **Vérifier l'absence de `._*`** (par prudence) : `find <docroot-prod> -name '._*' -delete`.
- [ ] **DB** : `~/bin/wp db export` (preprod) → import dans la base prod
      (`wp db import`), puis **search-replace** :
      `wp search-replace https://fu9oqnceqzu.preview.infomaniak.website https://cedricrivrain.com --all-tables-with-prefix --skip-columns=guid --report-changed-only --skip-plugins --skip-themes`
- [ ] **`wp-config.php` prod** : creds base prod, **nouveaux salts**, `WP_ENVIRONMENT_TYPE='production'`,
      `WP_DEBUG=false`, `DISABLE_WP_CRON=true` (⚠️ sinon hang wp-cli — cf. field notes) — et prévoir
      un **vrai cron système** (tâche planifiée Infomaniak appelant `wp cron event run --due-now`).
- [ ] **`.htaccess`** : `cp .htaccess.example .htaccess`.
- [ ] **Dé-preprod** : `wp option update blog_public 1` (réindexation OK), **PAS de HTTP Basic Auth**.

## D. SSL

- [ ] **Let's Encrypt** pour `cedricrivrain.com` (+ `www`) via le Manager Infomaniak. Selon les cas,
      le cert s'émet une fois le domaine pointé, ou par validation — vérifier avant/juste après bascule.

## E. Répétition à blanc (tant que le DNS pointe encore online.net)

- [ ] Tester la prod via l'**URL preview Infomaniak du site prod** (ou fichier `hosts` local pointant
      `cedricrivrain.com` vers l'IP Infomaniak) : home 200, `wp core verify-checksums`, login admin,
      quelques pages, **entrée « Clear cache »**. Corriger avant de toucher au DNS.

## F. Bascule DNS (le cœur)

- [ ] **24–48 h avant** : baisser le **TTL** des enregistrements `A`/`AAAA` (et `www`) à **300 s**
      chez l'hôte DNS actuel.
- [ ] Récupérer les **IP/cibles Infomaniak** du site prod (dans le Manager).
- [ ] **Sync final** : re-`rsync` uploads + re-`wp db export/import` + re-`search-replace` si du
      contenu a bougé (peu probable, mais pour être carré).
- [ ] **Bascule** :
      - Stratégie (1) : changer `A`/`AAAA` (+ `www` CNAME) vers Infomaniak. **Ne PAS toucher aux MX/TXT mail.**
      - Stratégie (2) : recréer TOUTE la zone chez Infomaniak (dont MX/SPF/DKIM), PUIS changer les `NS`.
- [ ] Attendre la **propagation** (surveiller `dig cedricrivrain.com A @1.1.1.1`, `dig … MX`).

## G. Post go-live

- [ ] `https://cedricrivrain.com` sert le **nouveau site**, **SSL valide** (cadenas), `www`→apex cohérent.
- [ ] `wp core verify-checksums`, **purge caches**, tester une page interne + permaliens.
- [ ] **Tester la messagerie** de Cédric (envoi ET réception) — le point critique.
- [ ] SEO : `blog_public=1`, `robots.txt`, **sitemap Yoast** OK, resoumettre à Google Search Console.
- [ ] **Remonter le TTL** DNS à une valeur normale (ex. 3600).
- [ ] Configs plugins finalisées (Wordfence WAF/scan, WP Super Cache activé, Autoptimize, Redirection).

## H. Rollback

- [ ] Garder **online.net actif** + TTL bas pendant la bascule. En cas de pépin : repointer `A`/`AAAA`
      vers online.net (retour rapide grâce au TTL bas). Le mail n'ayant pas bougé (stratégie 1), il reste OK.

## I. Décommission online.net

- [ ] Après **1–2 semaines stables** : backup complet online.net (fichiers + DB) en stockage froid,
      vérifier que **plus rien** (mail, sous-domaines, crons) n'en dépend, **puis résilier la Dedibox**.

## J. À faire AVANT la prod (repris du BACKLOG)

- [ ] Nettoyage legacy (`phpehrTKw`, vieux `wp-*.php` racine, `readme.html`, thèmes inutilisés).
- [ ] Sort des **vieilles pages publiées** (décision de Cédric) — dépublier/trasher.
- [ ] `blogname` vide → `<title>` vide : renseigner le nom du site.
- [ ] (Optionnel) Thème custom / bascule Bedrock — chantiers séparés, pas bloquants pour ce go-live.

---

### Field notes (rappel, cf. DEPLOY-INFOMANIAK.md)
Transfert local→serveur : `COPYFILE_DISABLE=1 tar …` (AppleDouble `._*`). WP-CLI = `~/bin/wp`.
`DISABLE_WP_CRON=true` obligatoire pour wp-cli. Bien **associer user↔base**. 301 canonique collant
en cache navigateur si on visite une URL avant son search-replace → nav privée pour recetter.
