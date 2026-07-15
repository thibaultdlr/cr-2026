# Backlog — cedricrivrain.com

Suivi des points différés pendant la modernisation. Objectif global : nouvelle version
**thème custom (from scratch) + Bedrock**. Le site réel aujourd'hui = une **vitrine** (nom +
lien Fitzpatrick Gallery + email en texte + copyright).

Statut au 2026-07-15 : local migré WP 4.6.30 → 7.0.1, thème Make 1.9.25 → 1.10.10, plugins
orphelins retirés, 7 plugins de préparation ajoutés, mu-plugin de glue cache en place.

---

## 🎨 v2 / plus tard

- [ ] **MetaSlider vs Autoptimize « Also defer inline JS »** — `autoptimize_js_defer_inline`
      est ON, et la home contient l'init inline de MetaSlider → risque que le slider ne
      s'initialise pas. À trancher en v2 : vérifier visuellement le slider ; s'il casse, soit
      exclure le handle `ml-slider`/`metaslider` dans *Autoptimize → JS → Exclude scripts*
      (ciblé, recommandé), soit décocher « Also defer inline JS » (global). Possible aussi que
      le slider soit un vestige à supprimer, auquel cas la question disparaît.
- [ ] **Formulaire de contact** — CF7 est actif mais le form (id 176) n'est rendu nulle part.
      Décider si le nouveau site a besoin d'un formulaire ; sinon désactiver/retirer CF7 +
      really-simple-captcha. Si on le garde : refaire la redirection après envoi (l'ancien
      `on_sent_ok` est cassé depuis CF7 5.0) en JS `wpcf7mailsent`, idéalement dans le thème custom.
- [ ] **Plugins superflus pour une vitrine** — réévaluer ml-slider, statcounter,
      all-404-redirect, contact-form-7, really-simple-captcha au moment du thème custom.

## 🚧 Avant mise en prod (config plugins)

- [ ] **Wordfence** — configurer le WAF + premier scan.
- [ ] **WP Super Cache** — passer en mode *Simple* et activer le cache (la glue Autoptimize→SC
      est déjà gérée par le mu-plugin `tr-cache`).
- [ ] **Redirection** — lancer l'assistant (crée ses tables DB).
- [ ] **Autoptimize** — agrégation OFF + minif ON déjà en place (rien à faire, cf. question MetaSlider ci-dessus).
- [ ] **Déploiement** — méthode à définir ; NE PAS livrer `.git`, `vendor`, `node_modules` sur
      le serveur (le `.htaccess.example` durci est un filet, pas une excuse). Copier
      `.htaccess.example` → `.htaccess` côté serveur.
- [ ] **`.htaccess` cible** — retirer l'`AddType ...php5` (fatal en PHP 8) ; c'est déjà fait
      dans `.htaccess.example`, à vérifier au déploiement.

## 🧹 Nettoyage legacy

- [ ] **Vieilles pages publiées** (Puppies Puppies, Shanaynay Benefit, C'est la vie, Transvas,
      Shanaynay Portraits, EXHIBITIONS, PRESS, Queer Rising, 4219) — orphelines (aucune nav).
      **Décision en attente de l'artiste (frère)** : dépublier / trasher.
- [ ] **Fichiers racine legacy** — reliquats pré-WP-3.x (`wp-atom.php`, `wp-rss.php`,
      `wp-register.php`, `wp-pass.php`, `wp-feed.php`, `wp-rdf.php`, `wp-commentsrss2.php`) et
      `readme.html` (annonce encore « 2.7 »). À supprimer (le core 7.0.1 ne les fournit plus).
- [ ] **Thèmes inutilisés** — antreas, autofocus, blockbase, capture, expositio, snaps,
      tdsimple, wiles, Less, twenty* → à supprimer (seul Make est actif, et il partira aussi).
- [ ] **`blogname` vide** en DB → `<title>` vide sur la home. À corriger (ou géré par le thème custom).

## 🏗️ Chantiers majeurs

- [ ] **Thème custom from scratch** — remplacer Make. Rendre la vitrine (nom, lien galerie,
      contact, copyright), propre et moderne. Cible du site (vitrine minimale vs vrai portfolio)
      encore à arbitrer avec l'artiste.
- [ ] **Bascule Bedrock** — structurer le projet (web/, config/, .env) une fois le thème custom prêt.

---

## ✅ Fait (pour mémoire)

- Repo initialisé (gitignore custom-only, composer = index, `.htaccess.example` durci).
- Scan sécu : site sain (pas de compromission active), core vérifié aux checksums.
- yellow-pencil (vulnérable, inactif) retiré → `../backups/`.
- Core WP 4.6.30 → 7.0.1, Make → 1.10.10, 8 plugins à jour (CF7 3.3.1 → 6.1.6).
- 4 plugins orphelins retirés (wp-recaptcha, cf7-recaptcha-ext, wsa-favicon, wp-gallery-custom-links).
- 7 plugins ajoutés : Yoast SEO, Wordfence, WP Super Cache, Autoptimize, Yoast Duplicate Post,
  Disable Comments (initialisé), Redirection.
- mu-plugin `tr-cache` (namespace `TR\Cache`, réutilisable) : purge unifiée ordonnée,
  entrée admin bar unique, glue Autoptimize → WP Super Cache, hooks transients/cache objet.
