<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * One thing: what it is called, where it lives in each of the houses that keep
 * it, and where it has got to right now. A thing can be in more than one
 * household — a charger, a spare key, a set of wellies at each grandparent's —
 * and it is in a different place in each, so where it lives is asked once per
 * household rather than once.
 *
 * Where it lives and where it is are two questions. The hook by the door is
 * where it lives whether or not it is hanging there, so its line is written and
 * read at every house that keeps it, including the ones the thing is away from.
 */

namespace Households;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$hh_user = View::user_id();
$hh_thing = View::storage()->get_note( (int) get_query_var( 'note_id' ), Storage::ITEM );

// Only the households that are yours. That another family keeps the same thing
// is not yours to be told, so their line is not on this page at all.
$hh_kept = [];
foreach ( $hh_thing ? $hh_thing['homes'] : [] as $hh_one ) {
    if ( Access::can_reach( $hh_user, $hh_one['id'] ) ) {
        $hh_one['writing'] = current_user_can( 'organise_household', $hh_one['id'] );
        $hh_kept[] = $hh_one;
    }
}
$hh_reach = (bool) $hh_kept;

// The name, the note and the deleting are the thing's own rather than any one
// household's, so they are signed for by the first household that keeps it and
// that you write in — and are not offered at all if there is none.
$hh_writing = 0;
foreach ( $hh_kept as $hh_one ) {
    if ( $hh_one['writing'] ) {
        $hh_writing = $hh_one['id'];
        break;
    }
}

// Every wording the note has had, newest first. A save that left it alone is
// not a version of it, so it is not one here either.
$hh_history = $hh_reach ? View::storage()->get_note_history( $hh_thing['id'], Storage::ITEM ) : [];

// Every household of yours, whether or not it has a place for this thing
// already: what a house says about where it lives is the same question of all
// of them, and one asked of a house that has never had it is how it comes to.
// A house you do not write in is on the list to be read, and one that has
// nothing to do with the thing and nothing you could write is not on it.
$hh_places = [];
if ( $hh_reach ) {
    $hh_lines = [];
    foreach ( $hh_thing['homes'] as $hh_one ) {
        $hh_lines[ $hh_one['id'] ] = $hh_one['where'];
    }
    foreach ( View::storage()->get_homes_for_user( $hh_user ) as $hh_other ) {
        $hh_other['keeps'] = array_key_exists( $hh_other['id'], $hh_lines );
        $hh_other['where'] = $hh_other['keeps'] ? $hh_lines[ $hh_other['id'] ] : '';
        $hh_other['writing'] = current_user_can( 'organise_household', $hh_other['id'] );
        if ( $hh_other['keeps'] || $hh_other['writing'] ) {
            $hh_places[] = $hh_other;
        }
    }
}

// Where it is at this moment. Another family's house is no more named here
// than their line is shown, so a thing that has gone somewhere you do not
// belong is somewhere else and nothing more.
$hh_at = $hh_reach ? $hh_thing['at'] : [];
$hh_at_named = ! empty( $hh_at['home_id'] ) && Access::can_reach( $hh_user, $hh_at['home_id'] );

// The households of yours it could be taken to: all of them, less the one it is
// already at. A house that has a place for it is as much somewhere to take it
// as one that has not — going back is the same sentence as being taken along.
$hh_take = [];
foreach ( $hh_places as $hh_other ) {
    if ( $hh_other['writing'] && ( empty( $hh_at['home_id'] ) || $hh_other['id'] !== $hh_at['home_id'] ) ) {
        $hh_take[] = $hh_other;
    }
}

// Where it is to get to, which is not where it is. Anywhere of yours it is not
// already at can be asked for, keeper or not: taking the wellies back to the
// house that has a place for them is the same sentence as taking a charger
// along.
$hh_going = $hh_reach && ! empty( $hh_thing['going'] ) ? $hh_thing['going'] : [];
$hh_going_targets = $hh_take;

$hh_title = $hh_reach ? $hh_thing['title'] : __( 'Thing', 'households' );

require __DIR__ . '/_head.php';
?>
        <a class="back" href="<?php echo esc_url( View::base() . 'things/' ); ?>">&larr; <?php echo esc_html__( 'Things', 'households' ); ?></a>

        <?php if ( ! $hh_reach ) : ?>
            <?php // Someone else's house, or nothing at all: the same answer either way, because which of the two it is is not yours to learn. ?>
            <h1><?php echo esc_html__( 'Thing', 'households' ); ?></h1>
            <section><p class="empty"><?php echo esc_html__( 'That is not something you can look at.', 'households' ); ?></p></section>
            <?php require __DIR__ . '/_foot.php'; ?>
            <?php return; ?>
        <?php endif; ?>

        <h1><?php echo esc_html( $hh_thing['title'] ); ?></h1>
        <?php View::notice(); ?>

        <section>
            <?php if ( $hh_writing ) : ?>
                <?php // Where it lives is not asked here: it is asked of each household below, and a form that does not carry it leaves it alone. ?>
                <form method="post" class="grid">
                    <?php View::fields( 'update_note', [ 'kind' => 'item', 'note_id' => $hh_thing['id'], 'home_id' => $hh_writing ] ); ?>
                    <label class="wide"><?php echo esc_html__( 'Thing', 'households' ); ?>
                        <input type="text" name="title" required value="<?php echo esc_attr( $hh_thing['title'] ); ?>">
                    </label>
                    <label class="wide"><?php echo esc_html__( 'Note', 'households' ); ?>
                        <textarea name="note"><?php echo esc_textarea( $hh_thing['note'] ); ?></textarea>
                    </label>
                    <button class="primary" type="submit"><?php echo esc_html__( 'Save', 'households' ); ?></button>
                </form>
            <?php elseif ( $hh_thing['note'] ) : ?>
                <p class="note" style="margin:0"><?php echo esc_html( $hh_thing['note'] ); ?></p>
            <?php else : ?>
                <p class="meta" style="margin:0"><?php echo esc_html__( 'Nothing is written down about it.', 'households' ); ?></p>
            <?php endif; ?>
        </section>

        <section>
            <?php // One line per household of yours: its name, and what it says about where the thing lives there. Every one of them is asked the same question, and it is one form and one press, because it is one answer about the thing rather than a row of separate ones. Answering for a house that has never had the thing is how that house comes to have a place for it, so there is nothing else to press. ?>
            <form method="post">
                <?php View::fields( 'set_note_places', [ 'kind' => 'item', 'note_id' => $hh_thing['id'], 'home_id' => $hh_writing ] ); ?>
                <?php // The press sits on the section's own line, where every other section keeps what can be done with it, rather than on a line of its own under the list. ?>
                <div class="row heading">
                    <h2><?php echo esc_html__( 'Where it lives', 'households' ); ?></h2>
                    <?php if ( $hh_writing ) : ?>
                        <div class="actions">
                            <button class="primary" type="submit"><?php echo esc_html__( 'Save', 'households' ); ?></button>
                        </div>
                    <?php endif; ?>
                </div>
                <ul class="plain">
                    <?php foreach ( $hh_places as $hh_one ) : ?>
                        <li class="row">
                            <strong><a href="<?php echo esc_url( View::home_url( $hh_one['id'] ) ); ?>"><?php echo esc_html( $hh_one['name'] ); ?></a></strong>
                            <?php if ( $hh_one['writing'] ) : ?>
                                <?php // Each line is its own household's to write, which is what the name of the field says, so one form can carry them all without any of them being asked of the wrong house. ?>
                                <input class="grow" type="text" name="where[<?php echo (int) $hh_one['id']; ?>]"
                                    value="<?php echo esc_attr( $hh_one['where'] ); ?>"
                                    placeholder="<?php echo esc_attr__( 'Where should it be here?', 'households' ); ?>"
                                    aria-label="<?php
                                    /* translators: %s: the name of a household. */
                                    echo esc_attr( sprintf( __( 'Where it lives at %s', 'households' ), $hh_one['name'] ) );
                                    ?>">
                            <?php else : ?>
                                <div class="grow meta">
                                    <?php
                                    echo $hh_one['where']
                                        ? esc_html( $hh_one['where'] )
                                        : esc_html__( 'Nothing is written down about where it lives.', 'households' );
                                    ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </form>
        </section>

        <?php // Where it has got to, which is a question the houses that keep it cannot each answer for themselves: a thing is in one place at a time even when several houses have a place for it. ?>
        <section>
            <h2><?php echo esc_html__( 'Where it is now', 'households' ); ?></h2>
            <div class="row">
                <div class="grow">
                    <?php if ( $hh_at_named && $hh_at['kept'] ) : ?>
                        <?php
                        printf(
                            /* translators: %s: a link naming a household. */
                            esc_html__( 'It is at %s.', 'households' ),
                            '<a href="' . esc_url( View::home_url( $hh_at['home_id'] ) ) . '">' . esc_html( $hh_at['name'] ) . '</a>'
                        );
                        ?>
                    <?php elseif ( $hh_at_named ) : ?>
                        <?php
                        printf(
                            /* translators: %s: a link naming a household. */
                            esc_html__( 'It has been taken to %s, which does not keep it.', 'households' ),
                            '<a href="' . esc_url( View::home_url( $hh_at['home_id'] ) ) . '">' . esc_html( $hh_at['name'] ) . '</a>'
                        );
                        ?>
                    <?php elseif ( ! empty( $hh_at['home_id'] ) ) : ?>
                        <?php // Somewhere you do not belong: that it is there at all is as much as this page will say. ?>
                        <span class="meta"><?php echo esc_html__( 'It is not at any of your households just now.', 'households' ); ?></span>
                    <?php else : ?>
                        <span class="meta"><?php echo esc_html__( 'Nobody has said which of them it is at.', 'households' ); ?></span>
                    <?php endif; ?>
                </div>
                <?php // Taken to a house that does not keep it, it is lent rather than moved: where it lives stays written where it was, to be said again when it comes back. ?>
                <?php if ( $hh_take ) : ?>
                    <form method="post" class="actions">
                        <?php View::fields( 'note_is_at', [ 'kind' => 'item', 'note_id' => $hh_thing['id'] ] ); ?>
                        <?php foreach ( $hh_take as $hh_other ) : ?>
                            <button type="submit" class="quiet" name="home_id" value="<?php echo (int) $hh_other['id']; ?>">
                                <?php
                                /* translators: %s: the name of a household. */
                                echo esc_html( sprintf( __( 'Taken to %s', 'households' ), $hh_other['name'] ) );
                                ?>
                            </button>
                        <?php endforeach; ?>
                    </form>
                <?php endif; ?>
            </div>

            <?php // Where it is to get to is its own line under where it is, because it is its own question: saying it moves nothing, and what moves the thing is somebody saying it has got there. ?>
            <div class="row" style="margin-top:10px">
                <div class="grow">
                    <?php if ( ! empty( $hh_going['home_id'] ) && Access::can_reach( $hh_user, $hh_going['home_id'] ) ) : ?>
                        <?php
                        printf(
                            $hh_going['is_packed']
                                /* translators: %s: a link naming a household, and what else is going there. */
                                ? esc_html__( 'It is in the bag for %s, and here until the bag goes.', 'households' )
                                /* translators: %s: a link naming a household, and what else is going there. */
                                : esc_html__( 'It is to go to %s, when somebody takes it.', 'households' ),
                            '<a href="' . esc_url( View::pack_url( isset( $hh_at['home_id'] ) ? (int) $hh_at['home_id'] : 0, (int) $hh_going['home_id'] ) ) . '">' . esc_html( $hh_going['name'] ) . '</a>'
                        );
                        ?>
                    <?php elseif ( ! empty( $hh_going['home_id'] ) ) : ?>
                        <span class="meta"><?php echo esc_html__( 'It is to go somewhere that is not yours.', 'households' ); ?></span>
                    <?php elseif ( $hh_going_targets ) : ?>
                        <span class="meta"><?php echo esc_html__( 'It is not waiting to go anywhere.', 'households' ); ?></span>
                    <?php endif; ?>
                </div>
                <?php
                $hh_going_note = $hh_thing['id'];
                $hh_going_going = $hh_going;
                $hh_going_writing = (bool) $hh_writing;
                // A page is not a list: it says what it means in words, and
                // says it once — that it has got there is what the section
                // above is for, whichever house it has got to.
                $hh_going_off = false;
                ?>
                <?php require __DIR__ . '/_going.php'; ?>
            </div>
        </section>

        <?php if ( $hh_history ) : ?>
            <section>
                <h2><?php echo esc_html__( 'What the note said before', 'households' ); ?></h2>
                <ul class="plain">
                    <?php foreach ( $hh_history as $hh_was ) : ?>
                        <li class="row">
                            <div class="grow">
                                <div class="meta">
                                    <?php
                                    echo esc_html( View::when( $hh_was['when'] ) );
                                    echo $hh_was['who'] ? ' · ' . esc_html( $hh_was['who'] ) : '';
                                    ?>
                                </div>
                                <?php if ( $hh_was['note'] ) : ?>
                                    <p class="note"><?php echo esc_html( $hh_was['note'] ); ?></p>
                                <?php else : ?>
                                    <p class="note meta"><?php echo esc_html__( 'Nothing was written down.', 'households' ); ?></p>
                                <?php endif; ?>
                            </div>
                            <?php // Only the note goes back. The name and where it lives are what they are now, and were not what was asked about. ?>
                            <?php if ( $hh_writing ) : ?>
                                <form method="post">
                                    <?php View::fields( 'restore_note', [ 'kind' => 'item', 'note_id' => $hh_thing['id'], 'home_id' => $hh_writing, 'revision_id' => $hh_was['id'] ] ); ?>
                                    <button type="submit" class="quiet"><?php echo esc_html__( 'Put this back', 'households' ); ?></button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php if ( $hh_writing ) : ?>
            <?php // For something the family no longer has anywhere. Something that has only stopped being kept at one house is taken off that house's line above, so this is one line and not a box asking to be read. ?>
            <form method="post" style="margin-bottom:16px">
                <?php View::fields( 'remove_note', [ 'kind' => 'item', 'note_id' => $hh_thing['id'], 'home_id' => $hh_writing ] ); ?>
                <button type="submit" class="quiet"><?php echo esc_html__( 'Delete this thing', 'households' ); ?></button>
            </form>
        <?php endif; ?>
<?php require __DIR__ . '/_foot.php'; ?>
