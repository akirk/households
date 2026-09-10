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
<script src="<?php echo esc_url( plugins_url( 'assets/households-where.js', dirname( __DIR__ ) . '/households.php' ) ); ?>?ver=<?php echo esc_attr( filemtime( dirname( __DIR__ ) . '/assets/households-where.js' ) ); ?>" defer></script>
<?php endif; ?>
<?php require __DIR__ . '/_foot.php'; ?>
