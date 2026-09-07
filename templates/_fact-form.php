<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * What a household knows about itself: a label and a note, asked in one place.
 *
 * Written down and put right are the same two questions, so — as with the
 * tasks above — they are the same form: it sits above the list, shut until it
 * is wanted, and what tells the two apart is whether it has something in it.
 * The note is a note like a thing's: prose, as long as it needs to be.
 *
 * Takes $hh_fact_note (the one being put right, if any), $hh_fact_shut (the
 * page with the form closed) and $hh_fact_open.
 */

namespace Households;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$hh_fact_note = ! empty( $hh_fact_note ) ? $hh_fact_note : [];
$hh_fact_shut = isset( $hh_fact_shut ) ? $hh_fact_shut : remove_query_arg( 'fact' );
$hh_fact_open = ! empty( $hh_fact_open );
?>
<?php if ( $hh_fact_note ) : ?>
    <?php // Taking it off the list is its own verb and its own nonce, so it is its own form, empty and out of the way, named by the button that submits it. ?>
    <form method="post" id="hh-remove-fact" action="<?php echo esc_url( $hh_fact_shut ); ?>" hidden>
        <?php View::fields( 'remove_note', [ 'kind' => 'fact', 'note_id' => $hh_fact_note['id'] ] ); ?>
    </form>
<?php endif; ?>
<form method="post" class="grid" id="fact" action="<?php echo esc_url( $hh_fact_shut ); ?>"
    style="margin-bottom:12px" <?php echo $hh_fact_open ? '' : 'hidden'; ?>>
    <?php
    if ( $hh_fact_note ) {
        View::fields( 'update_note', [ 'kind' => 'fact', 'note_id' => $hh_fact_note['id'] ] );
    } else {
        View::fields( 'add_note', [ 'kind' => 'fact' ] );
    }
    ?>
    <label class="wide"><?php echo esc_html__( 'Label', 'households' ); ?>
        <input type="text" name="title" value="<?php echo $hh_fact_note ? esc_attr( $hh_fact_note['title'] ) : ''; ?>" required <?php echo $hh_fact_open ? 'autofocus' : ''; ?>>
    </label>
    <label class="wide"><?php echo esc_html__( 'Note', 'households' ); ?>
        <textarea name="detail"><?php echo $hh_fact_note ? esc_textarea( $hh_fact_note['detail'] ) : ''; ?></textarea>
    </label>
    <div class="actions wide">
        <button class="primary" type="submit">
            <?php echo $hh_fact_note ? esc_html__( 'Save', 'households' ) : esc_html__( 'Add', 'households' ); ?>
        </button>
        <a class="button quiet" href="<?php echo esc_url( $hh_fact_shut ); ?>"><?php echo esc_html__( 'Cancel', 'households' ); ?></a>
        <?php if ( $hh_fact_note ) : ?>
            <button class="quiet" type="submit" form="hh-remove-fact" style="margin-left:auto">
                <?php echo esc_html__( 'Remove', 'households' ); ?>
            </button>
        <?php endif; ?>
    </div>
</form>
