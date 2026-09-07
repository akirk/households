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
?>
<!DOCTYPE html>
<html <?php wp_app_language_attributes(); ?>>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php wp_app_the_title( $hh_title ); ?></title>
    <?php wp_app_head(); ?>
    <style>
        :root {
            color-scheme: light dark;
            --hh-bg: #f6f7f2;
            --hh-text: #17201b;
            --hh-muted: #607067;
            --hh-surface: #ffffff;
            --hh-line: #d9e0d8;
            --hh-accent: #176b5b;
            --hh-accent-strong: #0f4f43;
            --hh-warm: #9b5d2f;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --hh-bg: #111614;
                --hh-text: #eef3ee;
                --hh-muted: #a7b3aa;
                --hh-surface: #1a211e;
                --hh-line: #344039;
                --hh-accent: #55b7a2;
                --hh-accent-strong: #87d4c2;
                --hh-warm: #d29561;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; background: var(--hh-bg); color: var(--hh-text); line-height: 1.5; }
        main { width: min(900px, calc(100% - 32px)); margin: 0 auto; padding: 24px 0 48px; }
        a { color: var(--hh-accent-strong); }
        .back { display: inline-block; margin-bottom: 10px; font-weight: 700; text-decoration: none; }
        h1 { margin: 0; font-size: clamp(1.6rem, 4.5vw, 2.6rem); line-height: 1.15; }
        .subtitle { margin: 6px 0 18px; color: var(--hh-muted); }
        .status { color: var(--hh-muted); font-size: 0.92rem; min-height: 22px; }
        /* A section waiting on the server it just posted to. */
        [aria-busy="true"] { opacity: 0.55; transition: opacity 120ms ease-in; }
        /* The whereabouts board: the days scroll, the names they belong to stay
           beside them, so a fortnight is read across without losing whose it is.
           Sticky cells and collapsed borders disagree in some browsers, so the
           borders are the cells' own. */
        .hh-board { display: flex; align-items: stretch; gap: 6px; }
        .hh-board .hh-scroller { flex: 1 1 auto; min-width: 0; }
        .hh-page { flex: 0 0 auto; display: flex; align-items: center; padding: 0 10px; border: 1px solid var(--hh-line); border-radius: 6px; color: var(--hh-accent-strong); font-weight: 700; text-decoration: none; }
        .hh-page.off { color: color-mix(in srgb, var(--hh-muted) 40%, transparent); }
        /* On a phone the arrows are taking width the fortnight wants. */
        @media (max-width: 560px) { .hh-board { gap: 3px; } .hh-page { padding: 0 4px; } }
        .hh-scroller { overflow-x: auto; overscroll-behavior-x: contain; }
        .hh-scroller:focus-visible { outline: 2px solid var(--hh-accent); outline-offset: 2px; }
        .hh-scroller table { border-collapse: separate; border-spacing: 0; min-width: 100%; }
        .hh-scroller th.hh-who { position: sticky; left: 0; text-align: left; background: var(--hh-surface); border-right: 1px solid var(--hh-line); }
        .hh-scroller thead th { border-bottom: 1px solid var(--hh-line); }
        .hh-scroller td { border-right: 1px solid var(--hh-line); border-bottom: 1px solid var(--hh-line); }
        .status[data-error] { color: var(--hh-warm); }
        section { background: var(--hh-surface); border: 1px solid var(--hh-line); border-radius: 8px; padding: 16px; margin: 0 0 16px; }
        /* The overview reads in two: what today asks of you on one side, where
           everyone is on the other. The sections keep their own spacing, so the
           columns want a gap across and none down. A phone gets one column, and
           the order the sections are written in is the order they fall into. */
        .columns { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 0 16px; align-items: start; }
        @media (max-width: 760px) { .columns { grid-template-columns: minmax(0, 1fr); } }
        /* A household is one line: its name, who is under it, and the arrow that
           opens who is going. The line you are at is the one that is filled in,
           so nothing has to say it in words. The marker is drawn rather than the
           browser's, which a flex summary does not show. */
        ul.homes { gap: 2px; }
        details.home > summary { display: flex; align-items: baseline; gap: 10px; padding: 6px 8px; border-radius: 6px; cursor: pointer; list-style: none; }
        details.home > summary::-webkit-details-marker { display: none; }
        details.home > summary::after { content: "\25B8"; color: var(--hh-muted); }
        details.home[open] > summary::after { content: "\25BE"; }
        details.home > summary:hover { background: color-mix(in srgb, var(--hh-accent) 7%, transparent); }
        details.home.at > summary { background: color-mix(in srgb, var(--hh-accent) 13%, transparent); }
        details.home .who { flex: 1 1 auto; min-width: 0; text-align: right; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        details.home .going { display: flex; flex-wrap: wrap; gap: 4px 14px; margin: 4px 0 8px; padding-left: 8px; }
        section > h2 { margin: 0 0 10px; font-size: 1.05rem; }
        /* A section's own line: what it is on the left, what can be done with it
           on the right. The heading keeps its size and gives up its margin. */
        .row.heading { align-items: center; margin: 0 0 10px; }
        .row.heading > h2 { margin: 0; font-size: 1.05rem; }
        .pill.on { background: var(--hh-accent); color: #fff; }
        a.pill { text-decoration: none; }
        /* Writing something down is one line until it is wanted. */
        details.add { margin-top: 12px; }
        details.add > summary { display: inline-block; cursor: pointer; list-style: none; color: var(--hh-accent-strong); font-weight: 700; font-size: 0.9rem; }
        details.add > summary::-webkit-details-marker { display: none; }
        details.add > form { margin-top: 10px; }
        /* Sitting in a section's own line it keeps to the right, and opening it
           takes the whole width of that line rather than a corner of it. */
        .row.heading details.add, .actions details.add { margin-top: 0; }
        .row.heading details.add[open], .actions details.add[open] { flex: 1 1 100%; }
        /* Beside other controls it is the whole group that gives up the line. */
        .row.heading .actions:has(details.add[open]) { flex: 1 1 100%; }
        ul.plain { list-style: none; margin: 0; padding: 0; display: grid; gap: 8px; }
        .row { display: flex; gap: 10px; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; }
        .meta { color: var(--hh-muted); font-size: 0.9rem; }
        .pill { display: inline-flex; align-items: center; min-height: 22px; padding: 0 8px; border-radius: 999px; background: color-mix(in srgb, var(--hh-accent) 12%, transparent); color: var(--hh-accent-strong); font-size: 0.76rem; font-weight: 700; white-space: nowrap; }
        .pill.warm { background: color-mix(in srgb, var(--hh-warm) 16%, transparent); color: var(--hh-warm); }
        .empty { color: var(--hh-muted); border: 1px dashed var(--hh-line); border-radius: 6px; padding: 16px; text-align: center; }
        /* A question with its answers beside it: the one you are reading is
           said rather than offered, so there is nothing to press that would
           leave you where you already are. */
        .choose { display: inline-flex; align-items: baseline; gap: 8px; font-size: 0.9rem; }
        .choose .meta::after { content: ":"; }
        button, .button { min-height: 38px; padding: 0 12px; border: 1px solid var(--hh-accent-strong); border-radius: 6px; background: transparent; color: var(--hh-accent-strong); font: inherit; font-weight: 700; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; text-decoration: none; }
        button.primary, .button.primary { background: var(--hh-accent); border-color: var(--hh-accent); color: #fff; }
        button.quiet, .button.quiet { border-color: var(--hh-line); color: var(--hh-muted); font-weight: 400; }
        input, select, textarea { min-height: 38px; width: 100%; padding: 6px 10px; border: 1px solid var(--hh-line); border-radius: 6px; background: var(--hh-bg); color: var(--hh-text); font: inherit; }
        textarea { min-height: 140px; resize: vertical; line-height: 1.6; }
        label { display: grid; gap: 4px; font-size: 0.9rem; font-weight: 700; }
        label small { font-weight: 400; color: var(--hh-muted); }
        label.inline { display: flex; align-items: center; gap: 8px; font-weight: 400; }
        label.inline input { width: auto; min-height: 0; }
        form.grid { display: grid; gap: 10px; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); align-items: end; }
        form.grid .wide { grid-column: 1 / -1; }
        /* Prose somebody typed, kept with the line breaks they typed. */
        p.note { margin: 8px 0 0; white-space: pre-wrap; }
        .done { text-decoration: line-through; color: var(--hh-muted); }
        /* Something offered about one line rather than about the list: it waits
           to be pointed at, and is out of the way of reading until it is. Where
           there is no pointer there is no hover, so it is simply there; the
           space it takes is kept either way, so no row moves under the cursor. */
        @media (hover: hover) {
            .row .onhover { opacity: 0; pointer-events: none; transition: opacity 0.12s ease-in-out; }
            .row:hover .onhover, .row:focus-within .onhover { opacity: 1; pointer-events: auto; }
        }
        form.inline { display: inline; }
        .actions { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }
        .grow { flex: 1 1 240px; }
        p.status { margin: 0 0 12px; }
        /* What a page falls back on when its script did not run. The script says
           so on the document itself, which no section being exchanged can undo. */
        html[data-hh-live] [data-hh-fallback] { display: none; }
        [hidden] { display: none !important; }
    </style>
</head>
<body>
    <?php wp_app_body_open(); ?>
    <main id="app">
