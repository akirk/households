<?php
/** Run through WP-CLI against an explicitly named disposable WordPress database. */

use Households\Access;
use Households\Storage;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! defined( 'DB_NAME' ) ) {
    throw new RuntimeException( 'Run this integration suite through WP-CLI.' );
}
if ( ! getenv( 'HOUSEHOLDS_TEST_DATABASE' ) || getenv( 'HOUSEHOLDS_TEST_DATABASE' ) !== DB_NAME ) {
    throw new RuntimeException( 'Explicitly name the disposable database in HOUSEHOLDS_TEST_DATABASE.' );
}
if ( ! taxonomy_exists( Access::TAXONOMY ) || ! post_type_exists( Access::PERSON ) ) {
    throw new RuntimeException( 'Activate Households before running its integration tests.' );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

$assertions = 0;
$same = static function ( $expected, $actual, string $message ) use ( &$assertions ): void {
    ++$assertions;
    if ( $expected !== $actual ) {
        throw new RuntimeException( $message . ': expected ' . var_export( $expected, true ) . ', got ' . var_export( $actual, true ) );
    }
};

/** Owns real WordPress fixtures; never substitutes for a production collaborator. */
final class Households_Integration_Fixture {
    public $storage;
    private $users = [];
    private $homes = [];
    private $people = [];
    private $previous_user;

    public function __construct() {
        $this->previous_user = get_current_user_id();
        $this->storage = new Storage();
    }

    public function user( string $name ): int {
        $id = wp_insert_user( [
            'user_login' => 'households_test_' . $name . '_' . wp_generate_uuid4(),
            'user_pass' => wp_generate_password( 24 ),
            'role' => 'subscriber',
        ] );
        if ( is_wp_error( $id ) ) {
            throw new RuntimeException( $id->get_error_message() );
        }
        $this->users[] = $id;
        return $id;
    }

    public function home( string $name, int $admin ): int {
        $id = $this->storage->start_home( $admin, $name );
        if ( ! $id ) {
            throw new RuntimeException( 'Could not create the household fixture.' );
        }
        $this->homes[] = $id;
        return $id;
    }

    public function person( int $home, string $name, int $user = 0, array $fields = [] ): int {
        $id = $this->storage->add_person( $home, $name, $fields );
        if ( ! $id ) {
            throw new RuntimeException( 'Could not create the person fixture.' );
        }
        $this->people[] = $id;
        if ( $user && ! Access::assign_user( $id, $user ) ) {
            throw new RuntimeException( 'Could not link the fixture person to their account.' );
        }
        return $id;
    }

    public function cleanup(): void {
        wp_set_current_user( $this->previous_user );
        foreach ( $this->people as $id ) {
            wp_delete_post( $id, true );
        }
        foreach ( $this->homes as $id ) {
            wp_delete_term( $id, Access::TAXONOMY );
        }
        foreach ( $this->users as $id ) {
            wp_delete_user( $id );
        }
        Access::flush_person_cache();
    }
}

$cases = [
    'home permissions distinguish administrators, adults, children, and strangers' => static function ( Households_Integration_Fixture $f ) use ( $same ): void {
        $admin = $f->user( 'admin' );
        $adult = $f->user( 'adult' );
        $child = $f->user( 'child' );
        $stranger = $f->user( 'stranger' );
        wp_set_current_user( $admin );
        $home = $f->home( 'Shared home', $admin );
        $other_home = $f->home( 'Other home', $stranger );
        $f->person( $home, 'Adult member', $adult );
        $child_person = $f->person( $home, 'Child member', $child, [ 'is_child' => true ] );
        $f->person( $other_home, 'Stranger', $stranger );

        // An administrator may manage a home without having a person record there.
        foreach ( [
            'administrator without membership' => [ $admin, [ true, true, true, true ] ],
            'adult member' => [ $adult, [ true, false, true, false ] ],
            'child member' => [ $child, [ true, false, false, true ] ],
            'stranger' => [ $stranger, [ false, false, false, false ] ],
        ] as $name => [ $user, $expected ] ) {
            wp_set_current_user( $user );
            $same( $expected, [
                Access::can_reach( $user, $home ),
                user_can( $user, 'manage_household', $home ),
                user_can( $user, 'organise_household', $home ),
                Access::can_view_person( $user, $child_person ),
            ], 'Per-home access for ' . $name );
        }
    },
    'a person without a login stays unowned when another administrator edits it' => static function ( Households_Integration_Fixture $f ) use ( $same ): void {
        $creator = $f->user( 'creator' );
        $editor = $f->user( 'editor' );
        wp_set_current_user( $creator );
        $home = $f->home( 'Family home', $creator );
        $person = $f->person( $home, 'Person without a login' );
        $same( 0, Access::user_for_person( $person ), 'Creation must not claim the current account as owner' );

        Access::set_admin( $home, $editor, true );
        wp_set_current_user( $editor );
        $f->storage->save_person( $person, [ 'about' => 'Medication instructions stay on this person.' ] );
        $same( 0, Access::user_for_person( $person ), 'Editing must not transfer ownership to the editor' );
        $same( 'Medication instructions stay on this person.', get_post( $person )->post_content, 'The real edit is saved' );
    },
    'leaving one home preserves the person and their other home' => static function ( Households_Integration_Fixture $f ) use ( $same ): void {
        $user = $f->user( 'member' );
        wp_set_current_user( $user );
        $home = $f->home( 'First home', $user );
        $other_home = $f->home( 'Second home', $user );
        $person = $f->person( $home, 'Household member', $user );
        Access::join( $person, $other_home );
        $f->storage->save_person( $person, [ 'about' => 'Allergy notes remain.', 'label' => 'Family' ] );

        Access::leave( $person, $home );

        $post = get_post( $person );
        $same( true, $post instanceof WP_Post, 'The person record survives leaving a household' );
        $same( 'Allergy notes remain.', $post->post_content, 'Leaving does not erase personal notes' );
        $same( $user, Access::user_for_person( $person ), 'The account link survives' );
        $same( 'Family', get_post_meta( $person, Storage::META_LABEL, true ), 'Personal metadata survives' );
        $same( [ $other_home ], Access::home_ids_for_person( $person ), 'Only the requested membership is removed' );
        $same( false, Access::can_reach( $user, $home ), 'Leaving revokes access even for the former administrator' );
        $same( false, Access::can_manage( $user, $home ), 'Leaving revokes administration of that home' );
        $same( true, Access::can_manage( $user, $other_home ), 'The other home remains administered' );
    },
];

$failures = 0;
foreach ( $cases as $name => $case ) {
    $fixture = new Households_Integration_Fixture();
    try {
        $case( $fixture );
        WP_CLI::log( 'PASS ' . $name );
    } catch ( Throwable $e ) {
        ++$failures;
        WP_CLI::warning( $name . ': ' . $e->getMessage() );
    } finally {
        $fixture->cleanup();
    }
}
if ( $failures ) {
    WP_CLI::error( $failures . ' of ' . count( $cases ) . ' behavioral cases failed (' . $assertions . ' assertions reached).' );
}
WP_CLI::success( count( $cases ) . ' behavioral cases passed (' . $assertions . ' assertions).' );
