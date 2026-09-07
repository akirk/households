<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * A person, and what travels with them between households.
 */

namespace Households;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$hh_person_id = (int) get_query_var( 'person_id' );
$hh_user = View::user_id();
$hh_allowed = Access::can_view_person( $hh_user, $hh_person_id );
$hh_person = $hh_allowed ? View::storage()->get_person( $hh_person_id ) : [];

// Whose account is theirs is settled by whoever administers a household they
// are in, and this page is where you already are when the question comes up.
$hh_can_manage = $hh_person && Access::can_manage_person( $hh_user, $hh_person_id );
$hh_free_users = $hh_can_manage ? View::storage()->assignable_users( 0 ) : [];

$hh_title = $hh_person ? $hh_person['name'] : __( 'A person', 'households' );

require __DIR__ . '/_head.php';
?>
        <a class="back" href="<?php echo esc_url( View::base() ); ?>">&larr; <?php echo esc_html__( 'Overview', 'households' ); ?></a>
        <?php if ( ! $hh_person ) : ?>
            <h1><?php echo esc_html__( 'A person', 'households' ); ?></h1>
            <p class="subtitle"><?php echo esc_html__( 'There is nobody here you are allowed to look at.', 'households' ); ?></p>
            <?php require __DIR__ . '/_foot.php'; ?>
            <?php return; ?>
        <?php endif; ?>

        <h1><?php echo esc_html( $hh_person['name'] ); ?></h1>
        <p class="subtitle">
            <?php
            $hh_bits = [];
            $hh_bits[] = $hh_person['homes']
                ? __( 'Belongs to:', 'households' ) . ' ' . implode( ', ', wp_list_pluck( $hh_person['homes'], 'name' ) )
                : __( 'Not in any household.', 'households' );
            if ( null !== $hh_person['age'] ) {
                /* translators: %d: an age in years. */
                $hh_bits[] = sprintf( __( '%d years old', 'households' ), $hh_person['age'] );
            }
            if ( ! $hh_person['user_id'] ) {
                $hh_bits[] = __( 'No account — nobody logs in as them.', 'households' );
            }
            echo esc_html( implode( ' · ', $hh_bits ) );
            ?>
        </p>
        <?php View::notice(); ?>

        <?php if ( $hh_can_manage ) : ?>
            <section>
                <h2><?php echo esc_html__( 'Their account', 'households' ); ?></h2>
                <p class="meta"><?php echo esc_html__( 'Whether anybody signs in as them, and as whom. Accounts are made in WordPress and pointed at a person here; most people never need one.', 'households' ); ?></p>
                <?php
                $hh_account_person = $hh_person;
                require __DIR__ . '/_account.php';
                ?>
            </section>
        <?php endif; ?>

        <section>
            <h2><?php echo esc_html__( 'What travels with them', 'households' ); ?></h2>
            <p class="meta"><?php echo esc_html__( 'Sizes, allergies, medication, whatever the next person needs to know. Every household they belong to reads this same page, so it is written once and true in all of them.', 'households' ); ?></p>
            <form method="post" class="grid">
                <?php View::fields( 'save_person', [ 'person_id' => $hh_person['id'] ] ); ?>
                <label><?php echo esc_html__( 'Name', 'households' ); ?>
                    <input type="text" name="name" value="<?php echo esc_attr( $hh_person['name'] ); ?>" required>
                </label>
                <label><?php echo esc_html__( 'Born', 'households' ); ?>
                    <input type="date" name="birthdate" value="<?php echo esc_attr( $hh_person['birthdate'] ); ?>">
                </label>
                <?php
                // A size is a field because the answer is two characters long
                // and is wanted while standing in a shop. What keeps it honest
                // is the day beside it: nobody types that, it is stamped when
                // the value changes, so an old measurement says that it is old.
                foreach ( Storage::size_fields() as $hh_size_key => $hh_size_label ) :
                    $hh_size = $hh_person['sizes'][ $hh_size_key ];
                    ?>
                    <label><?php echo esc_html( $hh_size_label ); ?>
                        <?php if ( $hh_size['noted'] ) : ?>
                            <small><?php
                                /* translators: %s: the date a size was last written down. */
                                echo esc_html( sprintf( __( 'Written down %s', 'households' ), mysql2date( 'j M Y', $hh_size['noted'] ) ) );
                            ?></small>
                        <?php else : ?>
                            <small><?php echo esc_html__( 'Not written down yet', 'households' ); ?></small>
                        <?php endif; ?>
                        <input type="text" name="size_<?php echo esc_attr( $hh_size_key ); ?>" value="<?php echo esc_attr( $hh_size['value'] ); ?>">
                    </label>
                <?php endforeach; ?>
                <label class="wide"><?php echo esc_html__( 'About', 'households' ); ?>
                    <small><?php echo esc_html__( 'Everything the fields above do not ask: allergies, medication, what somebody minding them should know.', 'households' ); ?></small>
                    <textarea name="about"><?php echo esc_textarea( $hh_person['about'] ); ?></textarea>
                </label>
                <div class="actions wide">
                    <button class="primary" type="submit"><?php echo esc_html__( 'Save', 'households' ); ?></button>
                    <span class="meta"><?php echo esc_html__( 'The previous version of what is written here is kept.', 'households' ); ?></span>
                </div>
            </form>
        </section>
<?php require __DIR__ . '/_foot.php'; ?>
