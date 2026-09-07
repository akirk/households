<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * What a cross took off the packing, offered back.
 *
 * A cross is easy to mean and easy to miss by, so what it took off is named in
 * the URL by whatever answered it — the way anything a write leaves behind is
 * said here — and this is the page saying it. Putting it back is the same
 * sentence that put it there in the first place, so nothing new is stored to
 * make it possible: a reload still offers it, and navigating away is how it
 * goes.
 *
 * Included inside the list it was taken off, so a page that puts its lists
 * back without going away offers it there too.
 */

namespace Households;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Read for display only; putting it back is a form of its own, with its own
// nonce, and everything it asks for is asked again of the viewer.
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$hh_undone_note = isset( $_GET['undo'] ) ? absint( wp_unslash( $_GET['undo'] ) ) : 0;
$hh_undone_to = isset( $_GET['undo_to'] ) ? absint( wp_unslash( $_GET['undo_to'] ) ) : 0;
// phpcs:enable WordPress.Security.NonceVerification.Recommended
if ( ! $hh_undone_note || ! $hh_undone_to ) {
    return;
}

$hh_undone_thing = View::storage()->get_note( $hh_undone_note, Storage::ITEM );
$hh_undone_home = View::storage()->get_home( $hh_undone_to );
// A thing that is not yours to open, or a household you do not write in, is
// not one to be offered anything about.
if ( ! $hh_undone_thing || ! $hh_undone_home
    || ! View::storage()->may_reach_note( View::user_id(), $hh_undone_note, Storage::ITEM )
    || ! current_user_can( 'organise_household', $hh_undone_to ) ) {
    return;
}
?>
<div class="row" style="margin-bottom:12px">
    <div class="grow status">
        <?php
        echo esc_html( sprintf(
            /* translators: 1: what a thing is called, 2: the name of a household. */
            __( '“%1$s” is off the list to %2$s.', 'households' ),
            $hh_undone_thing['title'],
            $hh_undone_home['name']
        ) );
        ?>
    </div>
    <form method="post">
        <?php View::fields( 'note_goes_to', [ 'kind' => 'item', 'note_id' => $hh_undone_note, 'home_id' => $hh_undone_to ] ); ?>
        <button type="submit" class="quiet"><?php echo esc_html__( 'Put it back', 'households' ); ?></button>
    </form>
</div>
