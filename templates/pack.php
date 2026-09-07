<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * What is waiting to be taken from one household to another.
 *
 * Saying a thing is to go somewhere does not put it there — it is on its shelf
 * until somebody carries it — so what has been said that way is gathered here,
 * a trip at a time. Things going the same way are one list, because that is one
 * bag, and the list says when the trip is when the fortnight already holds one.
 *
 * Ticking something off is putting it in the bag, which is not moving it: it is
 * at the house it was at, by the door. Carrying the bag is said once, at the
 * foot of the list, because it is one thing somebody did — and it is only then
 * that what was in it is at the other house and off the list. Whatever was not
 * ticked was not taken, and is still here for the next trip that way.
 */

namespace Households;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$hh_user = View::user_id();
$hh_routes = View::storage()->get_packlist( $hh_user );
$hh_homes = View::storage()->get_homes_for_user( $hh_user );

$hh_title = __( 'What to pack', 'households' );

require __DIR__ . '/_head.php';
?>
        <a class="back" href="<?php echo esc_url( View::base() ); ?>">&larr; <?php echo esc_html__( 'Overview', 'households' ); ?></a>
        <h1><?php echo esc_html__( 'What to pack', 'households' ); ?></h1>
        <p class="subtitle"><?php echo esc_html__( 'What is waiting to be taken from one household to another, and the trip it is waiting for.', 'households' ); ?></p>
        <?php View::notice(); ?>

        <?php // The lists come back from the server rather than the page going away and returning, so a tick is a tick and a cross leaves the offer to put it back where the eye already is. ?>
        <div id="hh-pack" data-hh-live-section>
        <?php require __DIR__ . '/_undone.php'; ?>

        <?php if ( ! $hh_routes ) : ?>
            <section>
                <ul class="plain">
                    <li class="empty">
                        <?php echo esc_html__( 'Nothing is waiting to go anywhere.', 'households' ); ?>
                        <?php // Where the lists are made: a thing is put on one from the household it is at. ?>
                        <a href="<?php echo esc_url( View::base() . 'things/' ); ?>"><?php echo esc_html__( 'Say a thing is to go somewhere', 'households' ); ?></a>
                        <?php echo esc_html__( 'and it waits here until it is packed.', 'households' ); ?>
                    </li>
                </ul>
            </section>
        <?php endif; ?>

        <?php foreach ( $hh_routes as $hh_route ) : ?>
            <section id="going-<?php echo (int) $hh_route['from_id']; ?>-<?php echo (int) $hh_route['to_id']; ?>">
                <div class="row heading">
                    <h2>
                        <?php if ( $hh_route['from_name'] ) : ?>
                            <?php
                            printf(
                                /* translators: 1: the household being left, linked, 2: the household being arrived at, linked. */
                                esc_html__( '%1$s to %2$s', 'households' ),
                                '<a href="' . esc_url( View::home_url( $hh_route['from_id'] ) ) . '">' . esc_html( $hh_route['from_name'] ) . '</a>',
                                '<a href="' . esc_url( View::home_url( $hh_route['to_id'] ) ) . '">' . esc_html( $hh_route['to_name'] ) . '</a>'
                            );
                            ?>
                        <?php else : ?>
                            <?php // Nothing this page may say names the house they are coming from, so the list has one end rather than a guess or a household of somebody else's at the other. ?>
                            <?php
                            printf(
                                /* translators: %s: the household being arrived at, linked. */
                                esc_html__( 'To go to %s', 'households' ),
                                '<a href="' . esc_url( View::home_url( $hh_route['to_id'] ) ) . '">' . esc_html( $hh_route['to_name'] ) . '</a>'
                            );
                            ?>
                        <?php endif; ?>
                    </h2>
                    <?php // The trip the bag is for, if the fortnight holds one. It is a day on the board, so the pill is the way to it. ?>
                    <?php if ( $hh_route['when'] ) : ?>
                        <div class="actions">
                            <a class="pill" href="<?php echo esc_url( add_query_arg( 'home', $hh_route['to_id'], View::base() . 'where/' ) ); ?>">
                                <?php echo esc_html( $hh_route['when'] ); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ( $hh_route['people'] ) : ?>
                    <?php // Whose trip it is. Which day it is is the pill above, so it is not said twice. ?>
                    <p class="meta" style="margin:-4px 0 10px"><?php echo esc_html( implode( ', ', $hh_route['people'] ) ); ?></p>
                <?php elseif ( $hh_route['from_name'] ) : ?>
                    <p class="meta" style="margin:-4px 0 10px">
                        <?php echo esc_html__( 'Nobody is going that way in the next fortnight. It waits until somebody does.', 'households' ); ?>
                    </p>
                <?php elseif ( $hh_route['from_id'] ) : ?>
                    <p class="meta" style="margin:-4px 0 10px">
                        <?php echo esc_html__( 'They are at a household that is not yours, so there is no trip here to read. They come back by somebody saying they have.', 'households' ); ?>
                    </p>
                <?php else : ?>
                    <p class="meta" style="margin:-4px 0 10px">
                        <?php echo esc_html__( 'Nobody has said which household these are at, so there is no trip to read.', 'households' ); ?>
                    </p>
                <?php endif; ?>

                <ul class="plain">
                    <?php foreach ( $hh_route['things'] as $hh_thing ) : ?>
                        <?php
                        // The heading is the trip, so it has named both ends of
                        // it and the line says only what the thing is and, from
                        // the house it is leaving, where in it to find it.
                        $hh_thing_home = $hh_route['from_name'] ? $hh_route['from_id'] : 0;
                        $hh_thing_at_said = true;
                        $hh_thing_writing = true;
                        $hh_thing_going_said = true;
                        ?>
                        <?php require __DIR__ . '/_thing.php'; ?>
                    <?php endforeach; ?>
                </ul>

                <?php // One press for the whole bag, because carrying it is one thing somebody did. It is offered as soon as anything is in the bag, and what is not in it stays here. ?>
                <?php if ( $hh_route['packed'] && current_user_can( 'organise_household', $hh_route['to_id'] ) ) : ?>
                    <div class="row" style="margin-top:12px">
                        <div class="grow meta">
                            <?php
                            echo esc_html( sprintf(
                                /* translators: %d: how many things have been ticked off. */
                                _n( '%d in the bag.', '%d in the bag.', $hh_route['packed'], 'households' ),
                                $hh_route['packed']
                            ) );
                            echo ' ';
                            echo count( $hh_route['things'] ) > $hh_route['packed']
                                ? esc_html__( 'The rest stays here for next time.', 'households' )
                                : '';
                            ?>
                        </div>
                        <form method="post">
                            <?php View::fields( 'things_arrived', [ 'home_id' => $hh_route['to_id'], 'from_home_id' => $hh_route['from_id'] ] ); ?>
                            <button class="primary" type="submit">
                                <?php
                                /* translators: %s: the name of a household. */
                                echo esc_html( sprintf( __( 'Taken to %s', 'households' ), $hh_route['to_name'] ) );
                                ?>
                            </button>
                        </form>
                    </div>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
        </div>
<?php require __DIR__ . '/_todo-script.php'; ?>
<?php require __DIR__ . '/_foot.php'; ?>
