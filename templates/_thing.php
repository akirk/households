<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * One thing on a list: where it lives, which households keep it, where it has
 * got to if that is somewhere other than where the list is being read, and
 * where it is to get to if it is on its way anywhere.
 *
 * Expects $hh_thing; $hh_homes, the households of the viewer's; and
 * $hh_thing_home — the household the heading has said the thing is at, or 0
 * when the list is of everything and no heading has said. Under a household
 * the line says where in that house it lives and nothing about the other
 * houses that have a place for it, because beside a thing that is in one place
 * at a time that reads as it being in several — unless this is not one of
 * those houses at all, and the thing is only here, in which case where it
 * belongs is the one thing worth saying. Across households, where no heading
 * has said anything, the line says which house it is at and, beside it, where
 * in that house it should be — nothing else, and not one without the other,
 * because a list read with no heading over it is a list being read to find
 * something and a place means nothing without the house it is in.
 * $hh_thing_at_said says a heading has already answered where it is, however
 * it answered — a household of yours, or that it is somewhere that is not. $hh_thing_writing says whether this page offers anything to be said at
 * all — a household read as somebody else is being read rather than organised,
 * and a list on the overview is a shelf being looked at. $hh_thing_going_said
 * is for a heading that is itself a trip and has named where it is going.
 *
 * A partial is read inside the page that requires it and shares its variables,
 * so everything this one works out for itself is named for the thing it is
 * about. A row that quietly took `$hh_where` for its own would be answering
 * about a shelf on a page that had been asking about a person's day.
 */

namespace Households;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Under a household's heading the line says where in that house it lives, and
// names the others only to say that they have it too. Read across households
// nothing has been said yet, so each one says its own name and its own answer.
// A household nobody in the room belongs to is never named.
$hh_thing_where = '';
$hh_thing_keeps_here = false;
$hh_thing_also = [];
foreach ( $hh_thing['homes'] as $hh_thing_one ) {
    if ( $hh_thing_one['id'] === $hh_thing_home ) {
        $hh_thing_keeps_here = true;
        $hh_thing_where = $hh_thing_one['where'];
    } elseif ( Access::can_reach( View::user_id(), $hh_thing_one['id'] ) ) {
        $hh_thing_also[] = $hh_thing_one;
    }
}

// Where it is at this moment, said only where that adds something. Under a
// household's heading it is worth saying whenever the thing is not there. Read
// as one list, a thing kept in one house and in it says nothing new; a thing
// taken elsewhere, or kept in several and said to be in one of them, does.
// A household of somebody else's is not named, only that it is not one of
// yours.
$hh_thing_at = ! empty( $hh_thing['at'] ) ? $hh_thing['at'] : [];
$hh_thing_at_away = ! empty( $hh_thing_at['home_id'] ) && $hh_thing_at['home_id'] !== $hh_thing_home;
$hh_thing_at_worth = $hh_thing_at_away && $hh_thing_home;
$hh_thing_at_named = $hh_thing_at_worth && Access::can_reach( View::user_id(), $hh_thing_at['home_id'] );

// Read with no heading over it, a thing is one line answering the two things
// asked of it: which house it is at, and where in that house it should be.
// Which houses would have it elsewhere is the thing's own page's to say.
$hh_thing_at_mine = ! empty( $hh_thing_at['home_id'] ) && Access::can_reach( View::user_id(), $hh_thing_at['home_id'] );
$hh_thing_at_where = '';
foreach ( $hh_thing_at_mine ? $hh_thing['homes'] : [] as $hh_thing_one ) {
    if ( $hh_thing_one['id'] === $hh_thing_at['home_id'] ) {
        $hh_thing_at_where = $hh_thing_one['where'];
    }
}

// Where it is to get to, and the bag it is waiting in. A heading that is itself
// a trip has said it already, and a household of somebody else's is not named
// here any more than anywhere else.
$hh_thing_goes = ! empty( $hh_thing['going'] ) ? $hh_thing['going'] : [];
$hh_thing_goes_from = ! empty( $hh_thing_at['home_id'] ) ? $hh_thing_at['home_id'] : $hh_thing_home;
$hh_thing_goes_named = ! empty( $hh_thing_goes['home_id'] )
    && empty( $hh_thing_going_said )
    && Access::can_reach( View::user_id(), $hh_thing_goes['home_id'] );

// Saying it is back is said where you are standing: on a household's own list,
// about that household, and only when the thing is not already there and is not
// on its way anywhere, which is a sentence with its own answers.
$hh_thing_here_now = $hh_thing_writing
    && $hh_thing_home
    && empty( $hh_thing_goes['home_id'] )
    && ( empty( $hh_thing_at['home_id'] ) || $hh_thing_at['home_id'] !== $hh_thing_home )
    && current_user_can( 'organise_household', $hh_thing_home );

// Where it could be sent: your households, less the one it is already at. Only
// offered where the thing is, because a shelf you are not standing at is not
// one you are packing from.
$hh_going_targets = [];
if ( $hh_thing_writing && $hh_thing_home && $hh_thing_goes_from === $hh_thing_home ) {
    foreach ( $hh_homes as $hh_thing_one ) {
        if ( $hh_thing_one['id'] !== $hh_thing_home && current_user_can( 'organise_household', $hh_thing_one['id'] ) ) {
            $hh_going_targets[] = $hh_thing_one;
        }
    }
}

// A thing on its way somewhere is a thing to be packed, and that is a list to
// tick off: the box says it has got there, and it is the same box again that
// says it has not after all. Only whoever writes in the household it is going
// to may say either, which is who may say where a thing is at all.
$hh_thing_tick = $hh_thing_writing
    && ! empty( $hh_thing_goes['home_id'] )
    && current_user_can( 'organise_household', $hh_thing_goes['home_id'] );
?>
<li class="row">
    <div class="grow">
        <?php if ( $hh_thing_tick ) : ?>
            <?php // Ticking it off is a tick, and taking it back is the same tick again. The button behind it is what does the ticking when there is no script to notice the box. ?>
            <form method="post" class="actions">
                <?php View::fields( 'toggle_packed', [ 'home_id' => $hh_thing_goes['home_id'], 'kind' => 'item', 'note_id' => $hh_thing['id'] ] ); ?>
                <label class="inline">
                    <input type="checkbox" data-hh-tick <?php checked( $hh_thing_goes['is_packed'] ); ?>>
                    <span class="<?php echo $hh_thing_goes['is_packed'] ? 'done' : ''; ?>">
                        <strong><a href="<?php echo esc_url( View::thing_url( $hh_thing['id'] ) ); ?>"><?php echo esc_html( $hh_thing['title'] ); ?></a></strong>
                    </span>
                </label>
                <button type="submit" class="quiet" data-hh-fallback>
                    <?php echo $hh_thing_goes['is_packed'] ? esc_html__( 'Not packed', 'households' ) : esc_html__( 'Packed', 'households' ); ?>
                </button>
            </form>
        <?php else : ?>
            <strong><a href="<?php echo esc_url( View::thing_url( $hh_thing['id'] ) ); ?>"><?php echo esc_html( $hh_thing['title'] ); ?></a></strong>
        <?php endif; ?>
        <?php if ( $hh_thing_home && $hh_thing_keeps_here ) : ?>
            <?php // What this house says about where it lives, and nothing about the other houses that have a place for it: the thing is here, and naming them under a heading would read as it being in two places at once. ?>
            <?php if ( $hh_thing_where ) : ?>
                <div class="meta"><?php echo esc_html( $hh_thing_where ); ?></div>
            <?php endif; ?>
        <?php elseif ( $hh_thing_at_said ) : ?>
            <?php // Under a heading that has said where it is, and this is not one of the houses with a place for it: brought along, borrowed, left behind, or away somewhere that is not yours. Where it belongs is the one thing worth saying about it here. ?>
            <?php if ( $hh_thing_also ) : ?>
                <div class="meta">
                    <?php
                    echo esc_html( sprintf(
                        /* translators: %s: a list of household names. */
                        __( 'Kept at %s.', 'households' ),
                        implode( ', ', wp_list_pluck( $hh_thing_also, 'name' ) )
                    ) );
                    ?>
                </div>
            <?php else : ?>
                <div class="meta"><?php echo esc_html__( 'Kept somewhere that is not yours.', 'households' ); ?></div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ( $hh_thing_at_named ) : ?>
            <div class="meta">
                <?php
                /* translators: %s: the name of a household. */
                echo esc_html( sprintf( __( 'It is at %s just now.', 'households' ), $hh_thing_at['name'] ) );
                ?>
            </div>
        <?php elseif ( $hh_thing_at_worth ) : ?>
            <div class="meta"><?php echo esc_html__( 'It is not at any of your households just now.', 'households' ); ?></div>
        <?php endif; ?>
    </div>
    <?php // Which house it is at and where in that house it should be, as one phrase on the line the thing is on: a list with no heading over it is a list being read to find something, and the place means nothing without the house in front of it. It is a sentence rather than a badge, because it is the answer rather than a label on one. ?>
    <?php if ( ! $hh_thing_at_said ) : ?>
        <div class="meta">
            <?php if ( $hh_thing_at_mine ) : ?>
                <?php // Said in one breath, so the colon does not come away from the name it belongs to. ?>
                <a href="<?php echo esc_url( View::home_url( $hh_thing_at['home_id'] ) ); ?>"><?php echo esc_html( $hh_thing_at['name'] ); ?></a><?php echo $hh_thing_at_where ? ': ' . esc_html( $hh_thing_at_where ) : ''; ?>
            <?php elseif ( ! empty( $hh_thing_at['home_id'] ) ) : ?>
                <?php echo esc_html__( 'Somewhere that is not yours', 'households' ); ?>
            <?php else : ?>
                <?php echo esc_html__( 'Nobody has said where it is', 'households' ); ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?php // Where it is to go is a house on the line the thing is on, and a tick beside it where there is no box to say the same thing. The words are still there for anyone the arrow does not reach. ?>
    <?php if ( $hh_thing_goes_named ) : ?>
        <?php $hh_thing_goes_here = $hh_thing_goes['home_id'] === $hh_thing_home; ?>
        <a class="pill<?php echo $hh_thing_goes['is_packed'] ? '' : ' warm'; ?>"
            href="<?php echo esc_url( View::pack_url( $hh_thing_goes_from, $hh_thing_goes['home_id'] ) ); ?>"
            aria-label="<?php
            echo esc_attr( $hh_thing_goes_here
                ? ( $hh_thing_goes['is_packed']
                    ? __( 'It is in the bag, on its way here.', 'households' )
                    : __( 'It is on its way here.', 'households' ) )
                : sprintf(
                    $hh_thing_goes['is_packed']
                        /* translators: %s: the name of a household. */
                        ? __( 'It is in the bag for %s.', 'households' )
                        /* translators: %s: the name of a household. */
                        : __( 'It is to go to %s.', 'households' ),
                    $hh_thing_goes['name']
                ) );
            ?>">
            <?php // Read at the household it is coming to, the household to name is this one, which the heading has said. ?>
            <?php
            echo $hh_thing_goes_here
                ? esc_html__( 'on its way here', 'households' )
                : '&rarr;&nbsp;' . esc_html( $hh_thing_goes['name'] );
            echo $hh_thing_goes['is_packed'] && ! $hh_thing_tick ? '&nbsp;&check;' : '';
            ?>
        </a>
    <?php endif; ?>
    <?php // Where it has got to is not where it belongs, so saying it is here leaves every line about where it lives as it was. ?>
    <?php if ( $hh_thing_here_now ) : ?>
        <form method="post">
            <?php View::fields( 'note_is_at', [ 'home_id' => $hh_thing_home, 'kind' => 'item', 'note_id' => $hh_thing['id'] ] ); ?>
            <button type="submit" class="quiet"><?php echo esc_html__( 'It is here now', 'households' ); ?></button>
        </form>
    <?php endif; ?>
    <?php
    $hh_going_note = $hh_thing['id'];
    $hh_going_going = $hh_thing_goes;
    $hh_going_writing = $hh_thing_writing;
    // A cross belongs beside a box; a page without one says it in words. The
    // tick itself is the line's own, because it is the thing's name that gets
    // struck through.
    $hh_going_off = $hh_thing_tick;
    ?>
    <?php require __DIR__ . '/_going.php'; ?>
</li>
