<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * What can be said about where a thing is to get to, wherever it is read.
 *
 * Where it is and where it is to go are two marks on the thing, and saying the
 * second moves nothing: it is where it was until somebody says it has got
 * there. That is the same question on a row in a list, on the thing's own page
 * and on the packlist, so it is asked once, here, and the pages differ only in
 * how they say where the thing is now.
 *
 * Expects $hh_going_note — the thing; $hh_going_going — where it is to get to,
 * as the storage says it, or nothing; $hh_going_targets — the households of
 * yours it could be sent to; $hh_going_off — whether taking it off is a cross
 * on a list rather than a sentence on a page; and $hh_going_writing, whether
 * this page is in a mood to offer writing at all, which is not only a matter
 * of permission, since a household being read as somebody else is being read
 * rather than organised.
 *
 * What it works out for itself is named for what it is about, because a
 * partial is read inside the page that requires it and shares its variables.
 */

namespace Households;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$hh_going_to = ! empty( $hh_going_going['home_id'] ) ? (int) $hh_going_going['home_id'] : 0;
// Both of the answers to a thing that is going somewhere are answers about the
// household it is going to — it has got there, or it is not coming after all —
// so both are asked of whoever writes in that household, exactly as saying it
// was going there was. Somebody who does not is left the line and no buttons.
if ( $hh_going_to && ! current_user_can( 'organise_household', $hh_going_to ) ) {
    return;
}
if ( ! $hh_going_writing || ( ! $hh_going_to && ! $hh_going_targets ) ) {
    return;
}
?>
<div class="actions">
    <?php if ( $hh_going_to ) : ?>
        <?php // Saying it has got there is not offered here: on a list it is the box the line is ticked off with and the one press that carries the whole bag, and on a page it is already offered, in the same words as for every other house, by what says where the thing is now. ?>
        <?php // Off the list, which asks nothing of where it is or where it lives. On a list it is a cross, and easy to mean by mistake, so what it took off is named in the URL and offered back. ?>
        <form method="post" class="inline">
            <?php View::fields( 'note_not_going', [ 'kind' => 'item', 'note_id' => $hh_going_note, 'home_id' => $hh_going_to ] ); ?>
            <button type="submit" class="quiet"
                <?php if ( $hh_going_off ) : ?>aria-label="<?php echo esc_attr__( 'Take it off the list', 'households' ); ?>"<?php endif; ?>>
                <?php echo $hh_going_off ? '&times;' : esc_html__( 'Not going', 'households' ); ?>
            </button>
        </form>
    <?php else : ?>
        <?php // The household is the button rather than the form, so one nonce answers for the whole row of them. ?>
        <form method="post" class="actions">
            <?php View::fields( 'note_goes_to', [ 'kind' => 'item', 'note_id' => $hh_going_note ] ); ?>
            <?php foreach ( $hh_going_targets as $hh_going_other ) : ?>
                <button type="submit" class="quiet" name="home_id" value="<?php echo (int) $hh_going_other['id']; ?>">
                    <?php
                    /* translators: %s: the name of a household. */
                    echo esc_html( sprintf( __( 'To go to %s', 'households' ), $hh_going_other['name'] ) );
                    ?>
                </button>
            <?php endforeach; ?>
        </form>
    <?php endif; ?>
</div>
