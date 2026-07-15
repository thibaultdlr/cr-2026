<?php
/**
 * Plugin Name: TR — Cache
 * Description: Gestionnaire de cache réutilisable. (1) Glue Autoptimize → WP Super Cache.
 *              (2) Purge unifiée dans le bon ordre, appelable par le thème. (3) Une seule
 *              entrée « Vider le cache » dans l'admin bar (masque celles d'Autoptimize et
 *              de WP Super Cache). (4) Hooks d'extension pour purger transients / cache
 *              objet au bon moment. Générique : chaque couche est optionnelle (guards).
 * Author:      Thibault Rivrain
 * Version:     1.0.0
 *
 * @package TR\Cache
 */

namespace TR\Cache {

	defined( 'ABSPATH' ) || exit;

	const ACTION = 'tr_clear_cache';   // admin-post action + nonce
	const FLAG   = 'tr_cache_cleared'; // query arg de confirmation
	const NODE   = 'tr-clear-cache';   // id du node admin bar

	if ( ! function_exists( __NAMESPACE__ . '\\clear_all' ) ) {

		/**
		 * Purge de TOUS les caches, dans l'ordre correct : interne → externe.
		 *
		 * Ordre : assets (Autoptimize) → données (transients / cache objet) → pages
		 * (WP Super Cache) EN DERNIER, pour que les pages régénérées capturent des assets
		 * et des données fraîches. Chaque couche est optionnelle (le plugin peut être absent).
		 *
		 * Appel depuis un thème : `\TR\Cache\clear_all()` ou le helper `tr_clear_caches()`.
		 *
		 * Points d'accroche (add_action) :
		 *  - `tr_cache_before_clear` : avant tout (ex. purge CDN).
		 *  - `tr_cache_clear_data`   : couche données — brancher ici delete_transient() / cache objet.
		 *  - `tr_cache_after_clear`  : après tout (pages déjà purgées).
		 */
		function clear_all() {
			/** @hook tr_cache_before_clear */
			do_action( 'tr_cache_before_clear' );

			// 1. Assets — Autoptimize. propagate=false : on gère l'ordre nous-mêmes (pages en dernier).
			if ( class_exists( 'autoptimizeCache' ) ) {
				\autoptimizeCache::clearall( false );
			}

			// 2. Données — transients / cache objet. wp_cache_flush() est un quasi no-op
			//    sans cache objet persistant, mais prêt le jour où un Redis/Memcached arrive.
			/** @hook tr_cache_clear_data */
			do_action( 'tr_cache_clear_data' );
			if ( function_exists( 'wp_cache_flush' ) ) {
				wp_cache_flush();
			}

			// 3. Pages — WP Super Cache, EN DERNIER (couche externe).
			if ( function_exists( 'wp_cache_clear_cache' ) ) {
				wp_cache_clear_cache();
			}

			/** @hook tr_cache_after_clear */
			do_action( 'tr_cache_after_clear' );
		}

		/**
		 * Glue : si Autoptimize purge ses assets de son côté (bouton natif, MAJ thème/plugin,
		 * purge programmatique avec propagation), on purge aussi les pages WP Super Cache.
		 * Couvre les cas qui ne passent PAS par clear_all().
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
		 * Admin bar : masque les entrées Autoptimize + WP Super Cache et laisse une seule
		 * entrée « Vider le cache ». Priorité 200 pour passer APRÈS Autoptimize (100) et
		 * WP Super Cache (99) et pouvoir retirer leurs nodes.
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
						'id'    => NODE,
						'title' => '⚡ Vider le cache',
						'href'  => wp_nonce_url( admin_url( 'admin-post.php?action=' . ACTION ), ACTION ),
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
			'admin_post_' . ACTION,
			static function () {
				if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( 'Permission refusée.' );
				}
				check_admin_referer( ACTION );

				clear_all();

				$back = wp_get_referer() ? wp_get_referer() : admin_url();
				wp_safe_redirect( add_query_arg( FLAG, '1', $back ) );
				exit;
			}
		);

		/**
		 * Confirmation en back-office après purge.
		 */
		add_action(
			'admin_notices',
			static function () {
				if ( isset( $_GET[ FLAG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					echo '<div class="notice notice-success is-dismissible"><p>Cache vidé (Autoptimize + WP Super Cache).</p></div>';
				}
			}
		);
	}
}

namespace {

	// Helper global pour un appel ergonomique depuis les templates : tr_clear_caches().
	if ( ! function_exists( 'tr_clear_caches' ) ) {
		/**
		 * Alias global de \TR\Cache\clear_all().
		 */
		function tr_clear_caches() {
			\TR\Cache\clear_all();
		}
	}
}
