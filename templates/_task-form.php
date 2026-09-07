<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * The four answers a task is made of, asked in one place.
 *
 * Writing one down and putting one right are the same four questions, so they
 * are the same form. It sits above the list, shut until it is wanted, and what
 * tells the two apart is whether it has a task in it. One form is one row of
 * buttons in one place, whichever of the two it is doing.
 *
 * Expects $hh_form_home, the household the list is of. Takes $hh_form_people
 * (who a task can be for), $hh_form_homes (the households it could be in, if
 * there is a choice), $hh_form_task (the one being put right, if any),
 * $hh_form_shut (the page with the form closed, where putting one right posts
 * to) and $hh_form_open.
 */

namespace Households;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$hh_form_people = isset( $hh_form_people ) ? $hh_form_people : [];
$hh_form_homes = isset( $hh_form_homes ) ? $hh_form_homes : [];
$hh_form_task = ! empty( $hh_form_task ) ? $hh_form_task : [];
$hh_form_shut = isset( $hh_form_shut ) ? $hh_form_shut : remove_query_arg( [ 'add', 'edit' ] );
$hh_form_open = ! empty( $hh_form_open );
?>
<?php if ( $hh_form_task ) : ?>
    <?php // Taking a task off the list is its own verb and its own nonce, so it is its own form. Forms do not sit inside forms, so it is empty and out of the way, and its button stands with the others by naming it. ?>
    <form method="post" id="hh-remove" action="<?php echo esc_url( $hh_form_shut ); ?>" hidden>
        <?php View::fields( 'remove_task', [ 'home_id' => $hh_form_home, 'task_id' => $hh_form_task['id'] ] ); ?>
    </form>
<?php endif; ?>
<?php // Which of the two it is doing is said on the form, because the script has to know whether the form it is putting back is the one that was open. ?>
<form method="post" class="grid" id="add" data-hh-mode="<?php echo $hh_form_task ? 'edit' : 'add'; ?>"
    <?php echo $hh_form_task ? 'action="' . esc_url( $hh_form_shut ) . '"' : ''; ?>
    style="margin-bottom:12px" <?php echo $hh_form_open ? '' : 'hidden'; ?>>
    <?php
    if ( $hh_form_task ) {
        View::fields( 'edit_task', [ 'home_id' => $hh_form_home, 'task_id' => $hh_form_task['id'] ] );
    } else {
        View::fields( 'add_task', [ 'home_id' => $hh_form_home ] );
    }
    ?>
    <label class="wide"><?php echo esc_html__( 'What needs doing', 'households' ); ?>
        <input type="text" name="title" value="<?php echo $hh_form_task ? esc_attr( $hh_form_task['title'] ) : ''; ?>" required <?php echo $hh_form_open ? 'autofocus' : ''; ?>>
    </label>
    <?php // Which household it is in is an answer like the rest, so a task written down in the wrong house is put right where it is read rather than in the house it should have been in. Asked only where there is more than one to name. ?>
    <?php if ( count( $hh_form_homes ) > 1 ) : ?>
        <label><?php echo esc_html__( 'Where', 'households' ); ?>
            <select name="to_home_id">
                <?php foreach ( $hh_form_homes as $hh_form_elsewhere ) : ?>
                    <option value="<?php echo (int) $hh_form_elsewhere['id']; ?>" <?php selected( $hh_form_elsewhere['id'], $hh_form_home ); ?>>
                        <?php echo esc_html( $hh_form_elsewhere['name'] ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
    <?php endif; ?>
    <?php // Whoever it is for is somebody in the household it is in, so moving it to another leaves it for everybody there rather than for somebody who is not. ?>
    <label><?php echo esc_html__( 'For whom', 'households' ); ?>
        <select name="person_id">
            <option value="0"><?php echo esc_html__( 'Everyone', 'households' ); ?></option>
            <?php foreach ( $hh_form_people as $hh_person ) : ?>
                <option value="<?php echo (int) $hh_person['id']; ?>" <?php selected( $hh_form_task && $hh_person['id'] === $hh_form_task['person_id'] ); ?>>
                    <?php echo esc_html( $hh_person['name'] ); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </label>
    <label><?php echo esc_html__( 'Kind', 'households' ); ?>
        <select name="task_type">
            <?php $hh_form_kind = $hh_form_task ? $hh_form_task['task_type'] : 'task'; ?>
            <option value="task" <?php selected( 'task', $hh_form_kind ); ?>><?php echo esc_html__( 'Task', 'households' ); ?></option>
            <option value="appointment" <?php selected( 'appointment', $hh_form_kind ); ?>><?php echo esc_html__( 'Appointment', 'households' ); ?></option>
        </select>
    </label>
    <label><?php echo esc_html__( 'When', 'households' ); ?>
        <input type="date" name="due_date" value="<?php echo $hh_form_task ? esc_attr( $hh_form_task['due_date'] ) : ''; ?>">
    </label>
    <?php // One row of buttons, in the order they are wanted: what you came to do, the way back out, and — away to one side, where it will not be hit by accident — the one that takes the task away altogether. ?>
    <div class="actions wide">
        <button class="primary" type="submit">
            <?php echo $hh_form_task ? esc_html__( 'Save', 'households' ) : esc_html__( 'Add', 'households' ); ?>
        </button>
        <?php // Leaving a new one unwritten is shutting the form, which is nothing anybody could be sent; leaving a written one as it was means the list has a row to put back, so the page is asked for it. ?>
        <a class="button quiet" href="<?php echo esc_url( $hh_form_shut ); ?>" <?php echo $hh_form_task ? 'data-hh-live' : 'data-hh-add'; ?>>
            <?php echo esc_html__( 'Cancel', 'households' ); ?>
        </a>
        <?php if ( $hh_form_task ) : ?>
            <button class="quiet" type="submit" form="hh-remove" style="margin-left:auto">
                <?php echo esc_html__( 'Remove', 'households' ); ?>
            </button>
        <?php endif; ?>
    </div>
</form>
