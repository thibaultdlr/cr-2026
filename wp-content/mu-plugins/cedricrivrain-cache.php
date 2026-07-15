<?php
/**
 * Plugin Name: Cédric Rivrain — Cache
 * Description: Gestion centralisée du cache. (1) Glue Autoptimize → WP Super Cache.
 *              (2) Fonction unique de purge dans le bon ordre, appelable par le thème.
 *              (3) Une seule entrée « Vider le cache » dans l'admin bar (masque celles
 *              d'Autoptimize et de WP Super Cache). (4) Points d'accroche pour purger
 *              transients / cache objet au bon moment (anticipation : pas de cache objet
 *              persistant aujourd'hui, budget hosting).
 * Author:      cedricrivrain.com
 * Version:     2.0.0
 *
 * @package cedricrivrain
 */

defined( 'ABSPATH' ) || exit;

/**
 * Purge de TOUS les caches, dans l'ordre correct : interne → externe.
 *
 * Ordre : assets (Autoptimize) → données (transients / cache objet) → pages (WP Super
 * Cache) EN DERNIER, pour que les pages régénérées capturent des assets et des données
 * fraîches. Appelable directement par le thème : `cedricrivrain_clear_all_caches()`.
 *
 * Points d'accroche (via add_action) :
 *  - `cedricrivrain_before_clear_caches` : avant tout (ex. purge CDN).
 *  - `cedricrivrain_clear_data_caches`   : couche données — brancher ici les
 *                                          delete_transient() / purges de cache objet.
 *  - `cedricrivrain_after_clear_caches`  : après tout (pages déjà purgées).
 */
function cedricrivrain_clear_all_caches() {
	/**
	 * @hook cedricrivrain_before_clear_caches
	 */
	do_action( 'cedricrivrain_before_clear_caches' );

	// 1. Assets — Autoptimize. propagate=false : on ne laisse PAS Autoptimize purger
	//    les pages lui-même, on gère l'ordre ci-dessous (pages en dernier).
	if ( class_exists( 'autoptimizeCache' ) ) {
		autoptimizeCache::clearall( false );
	}

	// 2. Données — transients / cache objet.
	//    Aujourd'hui : pas de cache objet persistant (budget hosting) → wp_cache_flush()
	//    est un quasi no-op. On le laisse + un hook dédié pour anticiper Redis/Memcached.
	/**
	 * @hook cedricrivrain_clear_data_caches
	 */
	do_action( 'cedricrivrain_clear_data_caches' );
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}

	// 3. Pages — WP Super Cache, EN DERNIER (couche externe).
	if ( function_exists( 'wp_cache_clear_cache' ) ) {
		wp_cache_clear_cache();
	}

	/**
	 * @hook cedricrivrain_after_clear_caches
	 */
	do_action( 'cedricrivrain_after_clear_caches' );
}

/**
 * Glue : si Autoptimize purge ses assets de son côté (bouton natif, MAJ thème/plugin,
 * purge programmatique avec propagation), on purge aussi les pages WP Super Cache.
 * Couvre les cas où la purge NE passe pas par cedricrivrain_clear_all_caches().
 */
add_action(
	'autoptimize_action_cachepurged',
	static function () {
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
	}
);

/**
 * Admin bar : masque les entrées d'Autoptimize et WP Super Cache, et ajoute une entrée
 * unique « Vider le cache » qui purge tout dans le bon ordre. Priorité 200 pour passer
 * APRÈS Autoptimize (100) et WP Super Cache (99), afin de pouvoir retirer leurs nodes.
 */
add_action(
	'admin_bar_menu',
	static function ( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$wp_admin_bar->remove_node( 'delete-cache' ); // WP Super Cache
		$wp_admin_bar->remove_node( 'autoptimize' );  // Autoptimize (+ enfants)

		$wp_admin_bar->add_node(
			array(
				'id'    => 'cedricrivrain-clear-cache',
				'title' => '⚡ Vider le cache',
				'href'  => wp_nonce_url(
					admin_url( 'admin-post.php?action=cedricrivrain_clear_cache' ),
					'cedricrivrain_clear_cache'
				),
				'meta'  => array( 'title' => 'Purge Autoptimize + WP Super Cache dans le bon ordre' ),
			)
		);
	},
	200
);

/**
 * Handler de l'entrée admin bar : vérifie droits + nonce, purge, revient en arrière.
 */
add_action(
	'admin_post_cedricrivrain_clear_cache',
	static function () {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission refusée.' );
		}
		check_admin_referer( 'cedricrivrain_clear_cache' );

		cedricrivrain_clear_all_caches();

		$back = wp_get_referer() ? wp_get_referer() : admin_url();
		wp_safe_redirect( add_query_arg( 'cr_cache_cleared', '1', $back ) );
		exit;
	}
);

/**
 * Confirmation en back-office après purge.
 */
add_action(
	'admin_notices',
	static function () {
		if ( isset( $_GET['cr_cache_cleared'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>Cache vidé (Autoptimize + WP Super Cache).</p></div>';
		}
	}
);
