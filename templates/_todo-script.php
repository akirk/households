<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * What spares a to-do list the page going away and coming back. Included by
 * every page that has one, and about nothing but the sections it is told to
 * keep live.
 */

namespace Households;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

wp_app_enqueue_script(
    'households-todo',
    plugins_url( 'assets/households-todo.js', dirname( __DIR__ ) . '/households.php' ),
    array(),
    filemtime( dirname( __DIR__ ) . '/assets/households-todo.js' ),
    true,
    'households'
);
