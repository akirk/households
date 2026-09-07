<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * One task, tickable where it is read, and — for whoever writes here — a way to
 * open it in the form above the list. The household is the one the list is of,
 * so the line says who it is for instead of saying the household again on every
 * row. Ticked, it stays here struck through, with the tick still in the box:
 * the way to take it back is the way it was done.
 *
 * Expects $hh_task and $hh_task_home. A page that lets it be written wants
 * $hh_task_url (the page, without anything a form left behind), $hh_task_writing
 * and $hh_task_editing (which row the form has, if any).
 */

namespace Households;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$hh_task_url = isset( $hh_task_url ) ? $hh_task_url : remove_query_arg( 'problem' );
$hh_task_writing = ! empty( $hh_task_writing );
// Editing is a page you can be sent, reload and come back to, so it is in the
// URL, and the form above the list is the one that opens with the task in it.
$hh_task_shut = remove_query_arg( [ 'add', 'edit' ], $hh_task_url );
$hh_task_open = add_query_arg( 'edit', $hh_task['id'], $hh_task_shut );
// Said into a name of its own: which row the form has outlives this one row,
// and the list asks the partial about the next one straight after.
$hh_task_is_open = $hh_task_writing && isset( $hh_task_editing ) && (int) $hh_task_editing === $hh_task['id'];

$hh_bits = [];
if ( $hh_task['due_date'] ) {
    $hh_bits[] = View::date( $hh_task['due_date'] );
}
if ( 'appointment' === $hh_task['task_type'] ) {
    $hh_bits[] = __( 'appointment', 'households' );
}
// Nothing done is late, whenever it was due.
$hh_overdue = ! $hh_task['is_done'] && $hh_task['due_date'] && $hh_task['due_date'] < current_time( 'Y-m-d' );

// Who wrote it down and when, kept under the cursor rather than said on the
// line: a list is read to find what is still to do, and whose idea it was is
// the question after that one.
$hh_written = '';
if ( ! empty( $hh_task['added_at'] ) ) {
    $hh_when = mysql2date( get_option( 'date_format' ) . ', ' . get_option( 'time_format' ), $hh_task['added_at'] );
    $hh_written = empty( $hh_task['added_by'] )
        /* translators: %s: a date and time. */
        ? sprintf( __( 'Written down on %s', 'households' ), $hh_when )
        : sprintf(
            /* translators: 1: a person's name. 2: a date and time. */
            __( 'Written down by %1$s on %2$s', 'households' ),
            $hh_task['added_by'],
            $hh_when
        );
}
?>
<li class="row">
    <form method="post" class="actions grow">
        <?php View::fields( 'toggle_task', [ 'home_id' => $hh_task_home, 'task_id' => $hh_task['id'] ] ); ?>
        <?php // Ticking it off is a tick, and unticking it is the same tick again. The button behind it is what does the ticking when there is no script to notice the box. ?>
        <label class="inline">
            <input type="checkbox" data-hh-tick <?php checked( $hh_task['is_done'] ); ?>>
            <span class="<?php echo $hh_task['is_done'] ? 'done' : ''; ?>" title="<?php echo esc_attr( $hh_written ); ?>">
                <?php echo esc_html( $hh_task['title'] ); ?>
                <?php if ( $hh_bits ) : ?>
                    <span class="meta">· <?php echo esc_html( implode( ' · ', $hh_bits ) ); ?></span>
                <?php endif; ?>
            </span>
        </label>
        <button type="submit" class="quiet" data-hh-fallback>
            <?php echo $hh_task['is_done'] ? esc_html__( 'Undo', 'households' ) : esc_html__( 'Done', 'households' ); ?>
        </button>
    </form>
    <div class="actions">
        <?php // Waiting to be pointed at: a list is for reading, and only the line under the cursor is being thought about. ?>
        <?php if ( $hh_task_writing ) : ?>
            <?php // The row the form has open says so, because the form is not beside it; pressing it again is the way back out. ?>
            <a class="pill onhover<?php echo $hh_task_is_open ? ' on' : ''; ?>" data-hh-live href="<?php echo esc_url( $hh_task_is_open ? $hh_task_shut : $hh_task_open ); ?>"
                aria-label="<?php echo esc_attr( sprintf(
                    /* translators: %s: what a task says. */
                    __( 'Edit “%s”', 'households' ),
                    $hh_task['title']
                ) ); ?>"><?php echo esc_html__( 'edit', 'households' ); ?></a>
        <?php endif; ?>
        <?php if ( $hh_overdue ) : ?>
            <span class="pill warm"><?php echo esc_html__( 'overdue', 'households' ); ?></span>
        <?php endif; ?>
        <?php // Whose it is. Nothing named is nobody's, which the empty space says as well as a word would. ?>
        <?php if ( $hh_task['person'] ) : ?>
            <span class="pill"><?php echo esc_html( $hh_task['person'] ); ?></span>
        <?php endif; ?>
    </div>
</li>
