<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * Everyone across the households you belong to, where they are today, and
 * the fortnight ahead. It spans households, so it says which one it is
 * reading from.
 */

namespace Households;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$hh_user = View::user_id();
$hh_homes = View::storage()->get_homes_for_user( $hh_user );

// The home this is read from: the one asked for, if it is one of yours, else
// the last one you looked at.
$hh_asked = isset( $_GET['home'] ) ? absint( wp_unslash( $_GET['home'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$hh_home_id = $hh_asked && Access::can_reach( $hh_user, $hh_asked ) ? $hh_asked : View::storage()->last_home_id( $hh_user );

$hh_today = current_time( 'Y-m-d' );

// Which fortnight the board is showing. The arrows move it a window at a time;
// the past is not somewhere it goes, because what was said about it has been
// pruned and what is left would be the pattern guessing backwards.
$hh_from = isset( $_GET['from'] ) ? sanitize_text_field( wp_unslash( $_GET['from'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $hh_from ) || $hh_from < $hh_today ) {
    $hh_from = $hh_today;
}

$hh_board = $hh_home_id ? View::storage()->get_whereabouts_board( $hh_home_id, $hh_from ) : [];
$hh_everyone = View::storage()->get_people_overview( $hh_user );
$hh_organises = $hh_home_id && current_user_can( 'organise_household', $hh_home_id );

// What a tap on the board means: that day alone, or that day and the rest of
// the fortnight with it. Which of the two is in the URL, so the board stays a
// page of plain forms with nothing to remember and the choice survives a tap.
$hh_onwards = ! empty( $_GET['onwards'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$hh_here = remove_query_arg( 'problem' );

$hh_title = __( 'Who is where', 'households' );

require __DIR__ . '/_head.php';
?>
        <a class="back" href="<?php echo esc_url( View::base() ); ?>">&larr; <?php echo esc_html__( 'Overview', 'households' ); ?></a>
        <h1><?php echo esc_html__( 'Who is where', 'households' ); ?></h1>
        <p class="subtitle"><?php echo esc_html__( 'Everyone across the households you belong to, and where they are today.', 'households' ); ?></p>
        <?php View::notice(); ?>

        <?php if ( ! $hh_board ) : ?>
            <section><p class="empty"><?php echo esc_html__( 'You do not have a household yet.', 'households' ); ?></p></section>
            <?php require __DIR__ . '/_foot.php'; ?>
            <?php return; ?>
        <?php endif; ?>

        <section id="hh-everyone">
            <h2><?php echo esc_html__( 'Everyone', 'households' ); ?></h2>
            <p class="meta"><?php echo esc_html__( 'Tap where someone is today. Somewhere they have never been, they join by being sent there.', 'households' ); ?></p>
            <?php
            // A row is a name and where that person is. Which household is
            // pressed says where they are, so nothing repeats it; what is true
            // of the person rather than of today — that they are a child, what
            // they are called in the family, whether they have an account at
            // all — is on their own page, one tap away under their name.
            ?>
            <ul class="plain">
                <?php foreach ( $hh_everyone as $hh_one ) : ?>
                    <?php
                    // Saying where you are is something anyone may do about
                    // themselves. Saying it about someone else is organising, so
                    // it goes through a household you organise and they belong
                    // to — the first one that is both, since which one it is
                    // asked through changes nothing about the answer.
                    $hh_via = 0;
                    foreach ( $hh_one['homes'] as $hh_their ) {
                        if ( current_user_can( 'organise_household', $hh_their['id'] ) ) {
                            $hh_via = (int) $hh_their['id'];
                            break;
                        }
                    }
                    $hh_may_move = $hh_one['is_you'] || $hh_via;

                    // Where they could be: their own households, and any of
                    // yours you organise. Somewhere they have never been is
                    // still somewhere they can be sent — the first weekend at
                    // the grandparents is a move before it is an arrangement —
                    // and being sent puts them in it.
                    $hh_targets = $hh_one['homes'];
                    $hh_ids = wp_list_pluck( $hh_targets, 'id' );
                    foreach ( $hh_homes as $hh_mine ) {
                        if ( ! in_array( $hh_mine['id'], $hh_ids, true ) && current_user_can( 'organise_household', $hh_mine['id'] ) ) {
                            $hh_targets[] = $hh_mine;
                        }
                    }
                    usort( $hh_targets, static function( array $a, array $b ): int {
                        return strcasecmp( $a['name'], $b['name'] );
                    } );
                    $hh_ask = $hh_may_move && ( count( $hh_targets ) > 1 || $hh_one['said'] );
                    ?>
                    <li class="row">
                        <div class="grow">
                            <a style="font-weight:700" href="<?php echo esc_url( View::person_url( $hh_one['id'] ) ); ?>"><?php echo esc_html( $hh_one['name'] ); ?></a>
                            <?php if ( $hh_one['is_you'] ) : ?>
                                <span class="meta"> &middot; <?php echo esc_html__( 'you', 'households' ); ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ( $hh_ask ) : ?>
                            <form method="post" class="actions">
                                <?php
                                View::fields(
                                    $hh_one['is_you'] ? 'say_where' : 'set_override',
                                    $hh_one['is_you']
                                        ? [ 'date' => $hh_today ]
                                        : [ 'home_id' => $hh_via, 'person_id' => $hh_one['id'], 'date' => $hh_today ]
                                );
                                $hh_field = $hh_one['is_you'] ? 'said_home_id' : 'override_home_id';
                                ?>
                                <?php foreach ( $hh_targets as $hh_their ) : ?>
                                    <?php $hh_at_it = $hh_one['location']['home_id'] === $hh_their['id']; ?>
                                    <button type="submit" name="<?php echo esc_attr( $hh_field ); ?>" value="<?php echo (int) $hh_their['id']; ?>"
                                        class="<?php echo $hh_at_it ? 'primary' : ''; ?>"
                                        aria-pressed="<?php echo $hh_at_it ? 'true' : 'false'; ?>">
                                        <?php echo esc_html( $hh_their['name'] ); ?>
                                    </button>
                                <?php endforeach; ?>
                                <?php // Only somebody with a pattern has anywhere to go back to; without one, being at the wrong house is answered by pressing the right one. ?>
                                <?php if ( $hh_one['said'] && $hh_one['rotates'] ) : ?>
                                    <button type="submit" name="<?php echo esc_attr( $hh_field ); ?>" value="0" class="quiet">
                                        <?php echo esc_html__( 'Back to the pattern', 'households' ); ?>
                                    </button>
                                <?php endif; ?>
                            </form>
                        <?php else : ?>
                            <?php // Nobody to ask and no button to read it off, so the row says it: somewhere known, or honestly nowhere. ?>
                            <span class="meta">
                                <?php
                                /* translators: %s: the name of a household. */
                                echo esc_html( $hh_one['location']['known'] ? sprintf( __( 'at %s', 'households' ), $hh_one['location']['name'] ) : __( 'not tracked', 'households' ) );
                                ?>
                            </span>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <?php
        // The window the arrows move: a fortnight at a time, forward from
        // today. There is no arrow into the past because there is nothing
        // truthful to show there — a day that has been and gone was pruned,
        // and the pattern would only be guessing backwards over it.
        $hh_span = count( $hh_board['dates'] );
        $hh_step = static function ( string $from, int $days ): string {
            return ( new \DateTimeImmutable( $from, new \DateTimeZone( 'UTC' ) ) )->modify( $days . ' days' )->format( 'Y-m-d' );
        };
        $hh_later = add_query_arg( 'from', $hh_step( $hh_from, $hh_span ), $hh_here );
        $hh_back = $hh_from > $hh_today ? max( $hh_today, $hh_step( $hh_from, -$hh_span ) ) : '';
        $hh_earlier = $hh_today === $hh_back ? remove_query_arg( 'from', $hh_here ) : add_query_arg( 'from', $hh_back, $hh_here );
        ?>
        <section id="hh-fortnight">
            <h2>
                <?php
                echo esc_html(
                    $hh_from === $hh_today
                        ? __( 'The next fortnight', 'households' )
                        /* translators: %s: a date. */
                        : sprintf( __( 'The fortnight from %s', 'households' ), View::date( $hh_from ) )
                );
                ?>
            </h2>
            <p class="meta">
                <?php
                /* translators: %s: the name of a household. */
                echo esc_html( sprintf( __( 'Read from %s.', 'households' ), $hh_board['home']['name'] ) );
                ?>
                <?php if ( $hh_from > $hh_today ) : ?>
                    <a data-hh-live data-hh-page="-1" href="<?php echo esc_url( remove_query_arg( 'from', $hh_here ) ); ?>"><?php echo esc_html__( 'Back to today', 'households' ); ?></a>
                <?php endif; ?>
            </p>
            <?php if ( count( $hh_homes ) > 1 ) : ?>
                <p class="meta">
                    <?php echo esc_html__( 'Look at another household', 'households' ); ?>
                    <?php foreach ( $hh_homes as $hh_home ) : ?>
                        <?php if ( $hh_home['id'] !== $hh_board['home']['id'] ) : ?>
                            <a style="margin-right:8px" href="<?php echo esc_url( add_query_arg( 'home', $hh_home['id'], View::base() . 'where/' ) ); ?>"><?php echo esc_html( $hh_home['name'] ); ?></a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
            <?php if ( $hh_organises ) : ?>
                <p class="meta">
                    <?php if ( $hh_onwards ) : ?>
                        <?php echo esc_html__( 'Tap a day to say it and clear whatever is already arranged after it in this fortnight. The pattern behind it is still left alone, and picks up again where the fortnight ends.', 'households' ); ?>
                        <a data-hh-live href="<?php echo esc_url( remove_query_arg( 'onwards', $hh_here ) ); ?>"><?php echo esc_html__( 'One day at a time', 'households' ); ?></a>
                    <?php else : ?>
                        <?php echo esc_html__( 'Tap a day to move it. Where a pattern answers for the days, only that one moves; where none does, it stands until the next day that is said.', 'households' ); ?>
                        <a data-hh-live href="<?php echo esc_url( add_query_arg( 'onwards', 1, $hh_here ) ); ?>"><?php echo esc_html__( 'From that day onwards', 'households' ); ?></a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <div class="hh-board">
                <?php if ( '' !== $hh_back ) : ?>
                    <a class="hh-page" data-hh-live data-hh-page="-1" href="<?php echo esc_url( $hh_earlier ); ?>"
                        aria-label="<?php echo esc_attr__( 'The fortnight before', 'households' ); ?>">&larr;</a>
                <?php else : ?>
                    <span class="hh-page off" aria-hidden="true">&larr;</span>
                <?php endif; ?>
                <div class="hh-scroller" tabindex="0" role="region" aria-label="<?php echo esc_attr__( 'The fortnight shown', 'households' ); ?>">
                <table>
                    <thead>
                        <tr>
                            <th class="hh-who" style="padding:4px 8px;z-index:3"></th>
                            <?php foreach ( $hh_board['dates'] as $hh_date ) : ?>
                                <th style="padding:4px 2px;font-size:0.72rem;font-weight:700;<?php
                                    echo $hh_date['is_weekend'] ? 'color:var(--hh-warm);' : '';
                                    echo $hh_date['is_today'] ? 'text-decoration:underline;' : '';
                                ?>">
                                    <?php echo esc_html( $hh_date['weekday'] ); ?><br><?php echo esc_html( $hh_date['day'] ); ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $hh_board['people'] as $hh_one ) : ?>
                            <?php $hh_days = array_column( $hh_one['days'], null, 'date' ); ?>
                            <tr>
                                <th class="hh-who" style="padding:4px 8px;font-weight:700;white-space:nowrap">
                                    <a style="text-decoration:none" href="<?php echo esc_url( View::person_url( $hh_one['id'] ) ); ?>"><?php echo esc_html( $hh_one['name'] ); ?></a>
                                </th>
                                <?php foreach ( $hh_board['dates'] as $hh_slot => $hh_date ) : ?>
                                    <?php
                                    $hh_day = isset( $hh_days[ $hh_date['date'] ] ) ? $hh_days[ $hh_date['date'] ] : null;
                                    $hh_letter = $hh_day && $hh_day['home_name'] ? mb_substr( $hh_day['home_name'], 0, 1 ) : '·';
                                    // Cycle through this person's homes, starting
                                    // from the one the day is showing — whatever
                                    // worked that out — so one tap always moves
                                    // the day somewhere else. Taking it back, which
                                    // is what a home ID of 0 means, is only in the
                                    // cycle for a day that was said: a day the
                                    // pattern answers for, or one carried in from
                                    // an earlier day, has nothing of its own to
                                    // take back and would sit there doing nothing.
                                    $hh_options = wp_list_pluck( $hh_one['homes'], 'id' );
                                    if ( $hh_day && $hh_day['is_override'] ) {
                                        $hh_options[] = 0;
                                    }
                                    $hh_at = $hh_day ? array_search( $hh_day['home_id'], $hh_options, true ) : false;
                                    if ( false === $hh_at ) {
                                        $hh_at = count( $hh_options ) - 1;
                                    }
                                    $hh_next = $hh_options[ ( (int) $hh_at + 1 ) % count( $hh_options ) ];
                                    // A run reaches to the end of the board and
                                    // no further: the days it says are the days
                                    // being looked at.
                                    $hh_run = $hh_onwards ? count( $hh_board['dates'] ) - (int) $hh_slot : 1;
                                    ?>
                                    <td title="<?php echo esc_attr( $hh_day ? $hh_day['home_name'] : '' ); ?>"
                                        style="padding:0;text-align:center;<?php
                                            echo $hh_day && $hh_day['is_here'] ? 'background:color-mix(in srgb, var(--hh-accent) 22%, transparent);' : '';
                                            // The day that was said is marked; the
                                            // days it carries into are not, so the
                                            // marks read as the moves themselves.
                                            echo $hh_day && $hh_day['is_override'] ? 'outline:2px solid var(--hh-warm);outline-offset:-2px;' : '';
                                        ?>">
                                        <?php if ( $hh_day && $hh_organises ) : ?>
                                            <form method="post" style="margin:0">
                                                <?php
                                                View::fields( 'set_override', [
                                                    'home_id'          => $hh_board['home']['id'],
                                                    'person_id'        => $hh_one['id'],
                                                    'date'             => $hh_day['date'],
                                                    'override_home_id' => $hh_next,
                                                    'onwards'          => $hh_run,
                                                ] );
                                                $hh_next_name = $hh_next ? ( $hh_board['home']['id'] === $hh_next ? $hh_board['home']['name'] : '' ) : '';
                                                foreach ( $hh_one['homes'] as $hh_option ) {
                                                    if ( $hh_option['id'] === $hh_next ) {
                                                        $hh_next_name = $hh_option['name'];
                                                    }
                                                }
                                                $hh_says = $hh_next_name
                                                    /* translators: 1: a date, 2: the name of a household. */
                                                    ? sprintf( $hh_run > 1 ? __( 'From %1$s on: %2$s', 'households' ) : __( '%1$s: %2$s', 'households' ), View::date( $hh_day['date'] ), $hh_next_name )
                                                    /* translators: %s: a date. */
                                                    : sprintf( $hh_run > 1 ? __( 'From %s on: back to the pattern', 'households' ) : __( '%s: back to the pattern', 'households' ), View::date( $hh_day['date'] ) );
                                                ?>
                                                <button type="submit" title="<?php echo esc_attr( $hh_says ); ?>" style="width:100%;min-height:30px;padding:0;border:0;border-radius:0;background:transparent;color:inherit">
                                                    <?php echo esc_html( $hh_letter ); ?>
                                                </button>
                                            </form>
                                        <?php else : ?>
                                            <span style="display:inline-block;min-height:30px;line-height:30px"><?php echo esc_html( $hh_letter ); ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <a class="hh-page" data-hh-live data-hh-page="1" href="<?php echo esc_url( $hh_later ); ?>"
                    aria-label="<?php echo esc_attr__( 'The fortnight after', 'households' ); ?>">&rarr;</a>
            </div>
        </section>

        <section id="hh-handovers">
            <h2><?php echo esc_html__( 'Handovers', 'households' ); ?></h2>
            <ul class="plain">
                <?php if ( ! $hh_board['handovers'] ) : ?>
                    <li class="empty"><?php echo esc_html__( 'No handovers in this window.', 'households' ); ?></li>
                <?php endif; ?>
                <?php foreach ( $hh_board['handovers'] as $hh_handover ) : ?>
                    <li class="row">
                        <div class="grow">
                            <strong><?php echo esc_html( View::date( $hh_handover['date'] ) ); ?></strong>
                            <div class="meta">
                                <?php echo esc_html( implode( ', ', $hh_handover['people'] ) ); ?> ·
                                <?php echo esc_html( $hh_handover['from_name'] ); ?> &rarr; <?php echo esc_html( $hh_handover['to_name'] ); ?>
                            </div>
                        </div>
                        <div class="actions">
                            <?php // What is waiting to go along on that trip, and the bag it is waiting in. ?>
                            <?php if ( ! empty( $hh_handover['to_pack'] ) ) : ?>
                                <a class="pill warm" href="<?php echo esc_url( View::pack_url( $hh_handover['from_id'], $hh_handover['to_id'] ) ); ?>">
                                    <?php
                                    printf(
                                        esc_html(
                                            /* translators: %d: how many things are waiting to be taken along. */
                                            _n( '%d to pack', '%d to pack', $hh_handover['to_pack'], 'households' )
                                        ),
                                        (int) $hh_handover['to_pack']
                                    );
                                    ?>
                                </a>
                            <?php endif; ?>
                            <span class="pill">
                                <?php
                                if ( 'out' === $hh_handover['direction'] ) {
                                    echo esc_html__( 'leaving', 'households' );
                                } elseif ( 'in' === $hh_handover['direction'] ) {
                                    echo esc_html__( 'arriving', 'households' );
                                } else {
                                    echo esc_html__( 'elsewhere', 'households' );
                                }
                                ?>
                            </span>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>

        <?php
        $hh_rotating = array_values( array_filter( $hh_board['people'], static function ( array $person ): bool {
            return $person['can_rotate'];
        } ) );
        ?>
        <?php if ( $hh_organises && $hh_rotating ) : ?>
            <section>
                <h2><?php echo esc_html__( 'Rotations', 'households' ); ?></h2>
                <p class="meta"><?php echo esc_html__( 'A rotation names its households in order and repeats a cycle of days. It is stored on the person, so every household reads the same answer.', 'households' ); ?></p>
                <?php foreach ( $hh_rotating as $hh_one ) : ?>
                    <div style="border-top:1px solid var(--hh-line);padding-top:12px;margin-top:12px">
                        <form method="post" class="grid">
                            <?php View::fields( 'save_rotation', [ 'home_id' => $hh_board['home']['id'], 'person_id' => $hh_one['id'] ] ); ?>
                            <div class="wide"><strong><?php echo esc_html( $hh_one['name'] ); ?></strong></div>
                            <label><?php echo esc_html__( 'Pattern', 'households' ); ?>
                                <select name="pattern">
                                    <?php foreach ( $hh_board['patterns'] as $hh_pattern ) : ?>
                                        <option value="<?php echo esc_attr( $hh_pattern['key'] ); ?>" <?php selected( $hh_one['rotation']['pattern'] ?? '', $hh_pattern['key'] ); ?>>
                                            <?php echo esc_html( $hh_pattern['label'] ); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </label>
                            <label><?php echo esc_html__( 'Starts', 'households' ); ?>
                                <input type="date" name="start_date" value="<?php echo esc_attr( $hh_one['rotation']['start_date'] ?? '' ); ?>">
                            </label>
                            <label><?php echo esc_html__( 'Changeover', 'households' ); ?>
                                <input type="time" name="changeover_time" value="<?php echo esc_attr( $hh_one['rotation']['changeover_time'] ?? Whereabouts::DEFAULT_CHANGEOVER_TIME ); ?>">
                            </label>
                            <div class="wide">
                                <div class="meta"><?php echo esc_html__( 'Households, in order', 'households' ); ?></div>
                                <div class="actions">
                                    <?php foreach ( $hh_one['homes'] as $hh_home ) : ?>
                                        <label class="inline">
                                            <input type="checkbox" name="homes[]" value="<?php echo (int) $hh_home['id']; ?>"
                                                <?php checked( in_array( $hh_home['id'], $hh_one['rotation']['homes'] ?? [], true ) ); ?>>
                                            <span><?php echo esc_html( $hh_home['name'] ); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <button class="primary" type="submit"><?php echo esc_html__( 'Save rotation', 'households' ); ?></button>
                        </form>
                        <?php if ( $hh_one['rotation'] ) : ?>
                            <form method="post" style="margin-top:10px">
                                <?php View::fields( 'clear_rotation', [ 'home_id' => $hh_board['home']['id'], 'person_id' => $hh_one['id'] ] ); ?>
                                <button type="submit" class="quiet"><?php echo esc_html__( 'Clear', 'households' ); ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
<?php if ( $hh_organises ) : ?>
<script>
/*
 * The board already works without this. Every day on it is a form: tapping one
 * posts it, the page redirects to itself, and what comes back is the board read
 * afresh. All the script does is spare the page going away and coming back — it
 * posts the same form to the same URL and puts in the sections the server
 * rendered in reply. Nothing is worked out here that the server has not already
 * worked out, so switching it off changes how it feels and not what it does.
 */
( function () {
    var live = [ 'hh-everyone', 'hh-fortnight', 'hh-handovers' ];
    if ( ! document.getElementById( 'hh-fortnight' ) || ! window.fetch || ! window.DOMParser || ! window.FormData ) {
        return;
    }

    var STILL = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

    // How long a fortnight takes to go past: long enough to be followed, short
    // enough not to be waited for. `?turn=<milliseconds>` overrides it, which is
    // how another speed is tried without editing anything.
    var TURN = 280;
    var asked = /[?&]turn=(\d{2,5})/.exec( window.location.search );
    if ( asked ) {
        TURN = parseInt( asked[1], 10 );
    }

    function parse( html ) {
        return new DOMParser().parseFromString( html, 'text/html' );
    }

    function swap( fresh ) {
        live.forEach( function ( id ) {
            var here = document.getElementById( id );
            var came = fresh.getElementById( id );
            if ( here && came ) {
                here.replaceWith( came );
            }
        } );
    }

    /*
     * Paging is the days moving past, and nothing else about the board moving
     * at all.
     *
     * Whose row is whose does not change from one fortnight to the next, so the
     * names take no part in it: only the day columns of the fortnight asked for
     * are taken out of what the server sent and hung on the ends of the rows
     * that are already here, out of sight beyond the edge. The board is then
     * twice as wide as its window and the window is scrolled the width of a
     * fortnight — real scrolling, so the names hold their place at the left
     * exactly as they do when you drag the days across by hand.
     *
     * When it comes to rest the fortnight that has gone by is deleted and the
     * days that scrolled into place are left where they are. They are the very
     * cells that arrived, never redrawn, so there is no moment of settling at
     * the end: they were always there, and now the rest of the section says so.
     */

    /** Hang the next fortnight's days on the rows. Returns the width of one fortnight. */
    function reach( table, next, dir ) {
        var here = table.rows;
        var there = next.rows;
        // The rows are the same people in the same order, or this is not the
        // board we asked for and it is put in place rather than scrolled to.
        if ( ! here.length || here.length !== there.length ) {
            return 0;
        }

        // The columns are pinned at the width they are being shown at, or
        // doubling the table would squeeze every day narrower on the way.
        var head = here[0].cells;
        var widths = [];
        var fortnight = 0;
        for ( var c = 0; c < head.length; c++ ) {
            widths.push( head[ c ].getBoundingClientRect().width );
            fortnight += c ? widths[ c ] : 0;
        }
        if ( ! fortnight || here[0].cells.length !== there[0].cells.length ) {
            return 0;
        }
        for ( c = 0; c < head.length; c++ ) {
            head[ c ].style.width = widths[ c ] + 'px';
        }

        for ( var r = 0; r < here.length; r++ ) {
            var row = here[ r ];
            var from = there[ r ].cells;
            var at = dir > 0 ? null : row.cells[ 1 ] || null;
            for ( c = 1; c < from.length; c++ ) {
                var cell = document.importNode( from[ c ], true );
                if ( 0 === r ) {
                    cell.style.width = widths[ c ] + 'px';
                }
                row.insertBefore( cell, at );
            }
        }
        table.style.tableLayout = 'fixed';
        table.style.width = ( widths[0] + fortnight * 2 ) + 'px';

        return fortnight;
    }

    /** Delete the fortnight that has gone by, and let the table be itself again. */
    function shed( table, dir ) {
        for ( var r = 0; r < table.rows.length; r++ ) {
            var row = table.rows[ r ];
            var gone = ( row.cells.length - 1 ) / 2;
            for ( var i = 0; i < gone; i++ ) {
                row.deleteCell( dir > 0 ? 1 : row.cells.length - 1 );
            }
        }
        table.style.tableLayout = '';
        table.style.width = '';
        for ( var c = 0; c < table.rows[0].cells.length; c++ ) {
            table.rows[0].cells[ c ].style.width = '';
        }
    }

    /** Everything around the days, so it says the fortnight the days are showing. */
    function dress( section, fresh ) {
        var came = fresh.getElementById( 'hh-fortnight' );
        var here = section.children;
        var there = came ? came.children : [];
        if ( ! came || here.length !== there.length ) {
            return false;
        }
        for ( var i = here.length - 1; i >= 0; i-- ) {
            if ( here[ i ].classList.contains( 'hh-board' ) ) {
                // The days themselves stay; only the arrows either side of them
                // are exchanged, for the ones pointing at the next fortnights.
                if ( here[ i ].children.length === there[ i ].children.length ) {
                    here[ i ].firstElementChild.replaceWith( there[ i ].firstElementChild );
                    here[ i ].lastElementChild.replaceWith( there[ i ].lastElementChild );
                }
            } else {
                here[ i ].replaceWith( there[ i ] );
            }
        }
        return true;
    }

    function glide( el, from, to ) {
        el.scrollLeft = from;
        if ( STILL ) {
            el.scrollLeft = to;
            return Promise.resolve();
        }
        return new Promise( function ( done ) {
            var started = 0;
            ( function step( now ) {
                started = started || now;
                var run = Math.min( 1, ( now - started ) / TURN );
                el.scrollLeft = from + ( to - from ) * ( 1 - Math.pow( 1 - run, 3 ) );
                if ( run < 1 ) {
                    requestAnimationFrame( step );
                } else {
                    done();
                }
            } )( performance.now() );
        } );
    }

    /** Scroll from this fortnight to the one beside it. False if it could not be done. */
    function turn( section, fresh, dir ) {
        var scroller = section.querySelector( '.hh-scroller' );
        var table = scroller && scroller.querySelector( 'table' );
        var came = fresh.getElementById( 'hh-fortnight' );
        var next = came && came.querySelector( '.hh-scroller table' );
        if ( ! table || ! next ) {
            return null;
        }
        var offset = scroller.scrollLeft;
        // Held still for the length of the turn: a bar that appears because the
        // board is briefly twice as wide, and goes again when it is not, is the
        // one thing on screen that would give away that anything was loaded.
        scroller.style.overflowX = 'hidden';
        var fortnight = reach( table, next, dir );
        if ( ! fortnight ) {
            scroller.style.overflowX = '';
            return null;
        }
        return glide(
            scroller,
            dir > 0 ? offset : offset + fortnight,
            dir > 0 ? offset + fortnight : offset
        ).then( function () {
            shed( table, dir );
            scroller.scrollLeft = offset;
            scroller.style.overflowX = '';
            return dress( section, fresh );
        } );
    }

    function load( url, form, dir, pressed ) {
        // A day being changed dims the board it is on; a fortnight being fetched
        // dims only the arrow that asked for it, because the board itself has
        // not gone anywhere yet and dimming it would be the fade this movement
        // is meant to do without.
        var busy = dir && pressed ? pressed : document.getElementById( 'hh-fortnight' );
        busy.setAttribute( 'aria-busy', 'true' );
        fetch( url, form
            ? { method: 'POST', body: new FormData( form ), credentials: 'same-origin' }
            : { credentials: 'same-origin' } )
            .then( function ( response ) {
                if ( ! response.ok ) {
                    throw new Error( String( response.status ) );
                }
                return response.text();
            } )
            .then( function ( html ) {
                var fresh = parse( html );
                var turning = dir ? turn( document.getElementById( 'hh-fortnight' ), fresh, dir ) : null;
                return Promise.resolve( turning ).then( function ( turned ) {
                    if ( turned ) {
                        // The days are already the new ones; only the lists that
                        // follow from them are left to put in.
                        [ 'hh-everyone', 'hh-handovers' ].forEach( function ( id ) {
                            var was = document.getElementById( id );
                            var now = fresh.getElementById( id );
                            if ( was && now ) {
                                was.replaceWith( now );
                            }
                        } );
                        return;
                    }
                    swap( fresh );
                    busy.removeAttribute( 'aria-busy' );
                } );
            } )
            // Anything unexpected hands the page back to the browser, which
            // has known how to do this all along.
            .catch( function () {
                if ( form ) {
                    form.submit();
                } else {
                    window.location.href = url;
                }
            } );
    }

    // Listening is done once, from outside anything that gets exchanged, so no
    // amount of swapping can leave a form posting twice or a link doing nothing.
    document.addEventListener( 'submit', function ( event ) {
        var form = event.target.closest( '#hh-fortnight form' );
        if ( ! form ) {
            return;
        }
        event.preventDefault();
        load( window.location.href, form, 0, null );
    } );

    document.addEventListener( 'click', function ( event ) {
        var link = event.target.closest( '#hh-fortnight a[data-hh-live]' );
        if ( ! link ) {
            return;
        }
        event.preventDefault();
        // Which fortnight is being looked at, and what a tap on it means, are
        // both kept in the URL, so a reload or a link passed to somebody else
        // still says them.
        window.history.replaceState( {}, '', link.href );
        load( link.href, null, parseInt( link.getAttribute( 'data-hh-page' ) || '0', 10 ), link );
    } );
}() );
</script>
<?php endif; ?>
<?php require __DIR__ . '/_foot.php'; ?>
