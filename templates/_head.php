<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * The chrome every page shares: the styles, and nothing else. The pages are
 * ordinary PHP — they read through Storage and post their changes back to
 * their own URL — so there is no client to configure here.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// A page says what it is by setting `$hh_title` before requiring this. Left
// unsaid, the title falls back to the route the page was matched by — which is
// a regular expression, and reads like one.
$hh_title = isset( $hh_title ) && '' !== trim( $hh_title ) ? $hh_title : __( 'Households', 'households' );

wp_app_enqueue_style(
    'households',
    plugins_url( 'assets/households.css', dirname( __DIR__ ) . '/households.php' ),
    array(),
    filemtime( dirname( __DIR__ ) . '/assets/households.css' ),
    'households'
);
?>
<!DOCTYPE html>
<html <?php wp_app_language_attributes(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php wp_app_the_title( $hh_title ); ?></title>
    <?php wp_app_head(); ?>
</head>
<body>
    <?php wp_app_body_open(); ?>
    <main id="app">
