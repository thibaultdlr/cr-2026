<?php
/**
 * Plugin Name: Cédric Rivrain — Cache glue (Autoptimize → WP Super Cache)
 * Description: Quand Autoptimize purge ses assets (CSS/JS agrégés/minifiés), on purge
 *              aussi le cache de pages WP Super Cache. Évite que des pages en cache
 *              pointent vers des fichiers Autoptimize supprimés (404 CSS/JS → site déstylé).
 *              Purge à sens unique : Autoptimize (interne) déclenche la purge des pages
 *              (externe). L'inverse n'est pas nécessaire.
 * Author:      cedricrivrain.com
 * Version:     1.0.0
 *
 * @package cedricrivrain
 */

defined( 'ABSPATH' ) || exit;

/**
 * Purge globale de WP Super Cache après chaque purge d'Autoptimize.
 *
 * Autoptimize déclenche `autoptimize_action_cachepurged` après avoir vidé son cache
 * (bouton « Delete all optimized content », MAJ thème/plugin, purge programmatique…).
 */
add_action(
	'autoptimize_action_cachepurged',
	static function () {
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
	}
);
