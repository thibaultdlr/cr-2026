#!/usr/bin/env php
<?php
/**
 * TR — Script de release WordPress.
 *
 * À exécuter SUR le serveur (Infomaniak), depuis la racine du site, APRÈS le transfert
 * des fichiers (core/plugins/thèmes/uploads via rsync) et l'import de la DB. Automatise
 * les étapes répétables : maintenance, git pull du code custom, migration DB, réécriture
 * d'URL (1er déploiement), purge des caches, .htaccess, noindex en preprod.
 *
 * Le core/plugins/thèmes/uploads NE sont PAS gérés ici (hors périmètre git) : transfert manuel.
 *
 * Usage :
 *   php bin/deploy.php [--env=preprod|prod] [--dry-run] [--first-run]
 *                      [--url-from=URL --url-to=URL] [--no-git] [--composer]
 *
 *   --env         Cible (défaut: preprod). En preprod : force blog_public=0 (noindex).
 *   --dry-run     N'exécute rien, affiche les commandes (search-replace passe en --dry-run).
 *   --first-run   Premier déploiement : réécriture d'URL + réglages initiaux. Requiert
 *                 --url-from et --url-to.
 *   --no-git      Saute le git pull (si le code est transféré autrement).
 *   --composer    Lance `composer install` (⚠️ uniquement en structure Bedrock ; en install
 *                 racine actuelle, laisser désactivé — cf. docs/DEPLOY-INFOMANIAK.md).
 *
 * Exemples :
 *   php bin/deploy.php --env=preprod --dry-run
 *   php bin/deploy.php --env=preprod --first-run \
 *       --url-from=https://cedricrivrain.local --url-to=https://preprod.cedricrivrain.com
 *   php bin/deploy.php --env=preprod
 */

declare( strict_types=1 );

if ( PHP_SAPI !== 'cli' ) {
	fwrite( STDERR, "Ce script s'exécute en CLI uniquement.\n" );
	exit( 1 );
}

/* -------------------------------------------------------------------------- */
/* Arguments & config                                                          */
/* -------------------------------------------------------------------------- */

$opts = tr_parse_args( array_slice( $argv, 1 ) );

if ( isset( $opts['help'] ) || isset( $opts['h'] ) ) {
	tr_usage();
	exit( 0 );
}

$env        = $opts['env'] ?? 'preprod';
$dry_run    = isset( $opts['dry-run'] );
$first_run  = isset( $opts['first-run'] );
$do_git     = ! isset( $opts['no-git'] );
$do_composer = isset( $opts['composer'] );
$url_from   = $opts['url-from'] ?? null;
$url_to     = $opts['url-to'] ?? null;

$root   = dirname( __DIR__ );                 // bin/ → racine du site
$wp_bin = getenv( 'WP_CLI' ) ?: 'wp';         // surchargeable : WP_CLI=/path/to/wp
$backup_dir = $root . '/.deploy-backups';     // protégé par .htaccess (dotfile)

if ( ! in_array( $env, array( 'preprod', 'prod' ), true ) ) {
	tr_fail( "--env doit valoir 'preprod' ou 'prod' (reçu: {$env})." );
}
if ( $first_run && ( ! $url_from || ! $url_to ) ) {
	tr_fail( '--first-run nécessite --url-from et --url-to.' );
}

/* -------------------------------------------------------------------------- */
/* Déroulé                                                                     */
/* -------------------------------------------------------------------------- */

tr_info( "Déploiement — env={$env}" . ( $dry_run ? ' [DRY-RUN]' : '' ) . ( $first_run ? ' [FIRST-RUN]' : '' ) );

$maintenance = $root . '/.maintenance';

try {
	tr_preflight( $root, $wp_bin );

	// Mode maintenance ON (mécanisme natif WP : fichier .maintenance).
	tr_step( 'Mode maintenance ON' );
	if ( ! $dry_run ) {
		file_put_contents( $maintenance, "<?php \$upgrading = " . time() . "; // TR deploy\n" );
	}

	// Sauvegarde DB avant toute mutation (utilise les creds de wp-config via WP-CLI).
	if ( ! $dry_run ) {
		tr_step( 'Sauvegarde DB' );
		if ( ! is_dir( $backup_dir ) ) {
			mkdir( $backup_dir, 0750, true );
		}
		$dump = $backup_dir . '/db-' . date( 'Ymd-His' ) . '.sql';
		tr_wp( $wp_bin, $root, 'db export ' . escapeshellarg( $dump ) . ' --skip-plugins --skip-themes', $dry_run );
		tr_ok( "Dump : {$dump}" );
	}

	// Code custom.
	if ( $do_git ) {
		tr_step( 'git pull (code custom)' );
		tr_run( 'git -C ' . escapeshellarg( $root ) . ' pull --ff-only', $dry_run );
	}

	// Dépendances composer (Bedrock uniquement).
	if ( $do_composer ) {
		tr_step( 'composer install' );
		tr_run( 'composer install --no-dev --optimize-autoloader --working-dir=' . escapeshellarg( $root ), $dry_run );
	}

	// Migrations DB éventuelles.
	tr_step( 'wp core update-db' );
	tr_wp( $wp_bin, $root, 'core update-db --skip-plugins --skip-themes', $dry_run );

	// Premier déploiement : réécriture d'URL + réglages initiaux.
	if ( $first_run ) {
		tr_step( "Réécriture d'URL : {$url_from} → {$url_to}" );
		$sr = sprintf(
			'search-replace %s %s --all-tables-with-prefix --skip-columns=guid --report-changes-only%s --skip-plugins --skip-themes',
			escapeshellarg( $url_from ),
			escapeshellarg( $url_to ),
			$dry_run ? ' --dry-run' : ''
		);
		tr_wp( $wp_bin, $root, $sr, $dry_run );
	}

	// Noindex en preprod.
	if ( 'preprod' === $env ) {
		tr_step( 'Preprod : blog_public = 0 (noindex)' );
		tr_wp( $wp_bin, $root, 'option update blog_public 0', $dry_run );
	}

	// .htaccess depuis le modèle si absent.
	tr_step( '.htaccess' );
	$htaccess = $root . '/.htaccess';
	$example  = $root . '/.htaccess.example';
	if ( ! file_exists( $htaccess ) && file_exists( $example ) ) {
		tr_ok( 'Copie .htaccess.example → .htaccess' );
		if ( ! $dry_run ) {
			copy( $example, $htaccess );
		}
	} else {
		tr_ok( file_exists( $htaccess ) ? '.htaccess déjà présent (inchangé)' : 'pas de .htaccess.example' );
	}

	// Purge des caches (via le mu-plugin tr-cache + cache objet).
	tr_step( 'Purge des caches' );
	tr_wp( $wp_bin, $root, 'cache flush', $dry_run );
	tr_wp( $wp_bin, $root, 'eval ' . escapeshellarg( "if (function_exists('tr_clear_caches')) { tr_clear_caches(); echo 'tr_clear_caches OK'; }" ), $dry_run );

} catch ( \Throwable $e ) {
	tr_err( 'ÉCHEC : ' . $e->getMessage() );
	tr_maintenance_off( $maintenance, $dry_run );
	exit( 1 );
}

// Mode maintenance OFF (toujours, même après erreur gérée plus haut).
tr_maintenance_off( $maintenance, $dry_run );

tr_info( 'Terminé.' . ( $dry_run ? ' (dry-run : rien n\'a été modifié)' : '' ) );
if ( ! $first_run ) {
	tr_info( 'Rappel : vérifier siteurl/home et la home en HTTP après un vrai déploiement.' );
}
exit( 0 );

/* -------------------------------------------------------------------------- */
/* Helpers                                                                     */
/* -------------------------------------------------------------------------- */

function tr_parse_args( array $argv ): array {
	$out = array();
	foreach ( $argv as $arg ) {
		if ( ! str_starts_with( $arg, '--' ) && ! str_starts_with( $arg, '-' ) ) {
			continue;
		}
		$arg = ltrim( $arg, '-' );
		if ( str_contains( $arg, '=' ) ) {
			[ $k, $v ] = explode( '=', $arg, 2 );
			$out[ $k ] = $v;
		} else {
			$out[ $arg ] = true;
		}
	}
	return $out;
}

function tr_preflight( string $root, string $wp_bin ): void {
	tr_step( 'Pré-vérifications' );
	if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
		tr_fail( 'PHP ≥ 8.1 requis (actuel : ' . PHP_VERSION . ').' );
	}
	if ( ! file_exists( $root . '/wp-load.php' ) ) {
		tr_fail( "wp-load.php introuvable dans {$root} : ce script doit tourner à la racine du site." );
	}
	exec( escapeshellarg( $wp_bin ) . ' --version 2>/dev/null', $o, $code );
	if ( 0 !== $code ) {
		tr_fail( "WP-CLI introuvable (binaire: {$wp_bin}). Définir WP_CLI=/chemin/vers/wp au besoin." );
	}
	tr_ok( 'PHP ' . PHP_VERSION . ', WP-CLI OK, racine OK' );
}

function tr_wp( string $wp_bin, string $root, string $subcmd, bool $dry_run ): void {
	$cmd = escapeshellarg( $wp_bin ) . ' --path=' . escapeshellarg( $root ) . ' ' . $subcmd;
	tr_run( $cmd, $dry_run );
}

function tr_run( string $cmd, bool $dry_run ): void {
	if ( $dry_run ) {
		echo "  [dry] {$cmd}\n";
		return;
	}
	echo "  \$ {$cmd}\n";
	passthru( $cmd, $code );
	if ( 0 !== $code ) {
		throw new \RuntimeException( "commande échouée (code {$code}) : {$cmd}" );
	}
}

function tr_maintenance_off( string $maintenance, bool $dry_run ): void {
	tr_step( 'Mode maintenance OFF' );
	if ( ! $dry_run && file_exists( $maintenance ) ) {
		unlink( $maintenance );
	}
}

function tr_step( string $m ): void { echo "\n==> {$m}\n"; }
function tr_info( string $m ): void { echo "\n[deploy] {$m}\n"; }
function tr_ok( string $m ): void { echo "  ✓ {$m}\n"; }
function tr_warn( string $m ): void { fwrite( STDERR, "  ! {$m}\n" ); }
function tr_err( string $m ): void { fwrite( STDERR, "  ✗ {$m}\n" ); }
function tr_fail( string $m ): void { tr_err( $m ); exit( 1 ); }

function tr_usage(): void {
	echo <<<TXT
TR — Script de release WordPress

Usage:
  php bin/deploy.php [--env=preprod|prod] [--dry-run] [--first-run]
                     [--url-from=URL --url-to=URL] [--no-git] [--composer]

Voir l'en-tête du fichier et docs/DEPLOY-INFOMANIAK.md pour le détail.
TXT;
	echo "\n";
}
