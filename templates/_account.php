<?php
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- Template variables are render-local state.
/**
 * Which WordPress account is this person's.
 *
 * The account is not made here — it is made in WordPress, by whoever runs the
 * site — so all this offers is pointing at one that exists, and taking the
 * pointer back. Your own is never offered: dropping it would put you outside
 * every household you administer.
 *
 * Expects `$hh_account_person` (a person, as Storage talks about them) and
 * `$hh_free_users` (the accounts nobody answers for).
 */

namespace Households;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$hh_account = $hh_account_person['user_id'] ? get_userdata( $hh_account_person['user_id'] ) : null;
$hh_is_me = Access::person_for_user( View::user_id() ) === $hh_account_person['id'];
?>
<?php if ( $hh_account ) : ?>
    <div class="meta">
        <?php
        /* translators: %s: a WordPress username. */
        echo esc_html( sprintf( __( 'Signs in as %s', 'households' ), $hh_account->user_login ) );
        ?>
    </div>
    <?php if ( ! $hh_is_me ) : ?>
        <form method="post" class="actions" style="margin-top:4px">
            <?php View::fields( 'assign_user', [ 'person_id' => $hh_account_person['id'], 'user_id' => 0 ] ); ?>
            <button type="submit" class="quiet"><?php echo esc_html__( 'Not their account', 'households' ); ?></button>
        </form>
    <?php endif; ?>
<?php elseif ( $hh_free_users ) : ?>
    <form method="post" class="actions" style="margin-top:4px">
        <?php View::fields( 'assign_user', [ 'person_id' => $hh_account_person['id'] ] ); ?>
        <select name="user_id" style="width:auto" aria-label="<?php echo esc_attr__( 'Their account', 'households' ); ?>">
            <?php foreach ( $hh_free_users as $hh_free ) : ?>
                <option value="<?php echo (int) $hh_free['id']; ?>"><?php echo esc_html( $hh_free['login'] ); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="quiet"><?php echo esc_html__( 'This is their account', 'households' ); ?></button>
    </form>
<?php else : ?>
    <div class="meta"><?php echo esc_html__( 'No account is theirs, and there is none spare to give them. Accounts are made in WordPress.', 'households' ); ?></div>
<?php endif; ?>
