<?php
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Household access checks intentionally query taxonomy relationships.
namespace Households;

/**
 * Who may see or change what.
 *
 * A home is a term. A person is a post, tagged with the term of every home they
 * belong to, and authored by their WordPress user — or by nobody, which is how
 * a toddler or a pet gets a record without an account it would never use.
 *
 * Only two things are actually decided here. Whether someone administers a
 * home, which is per home and lives in term meta; and whether someone is a
 * child, which is a property of the person and true wherever they go. Both are
 * reachable through the ordinary capability API as `manage_household` and
 * `organise_household`, each taking a home's term ID.
 */
class Access {
    public const TAXONOMY = 'household';
    public const PERSON   = 'household_person';

    /** Term meta: WP user IDs who administer this home. */
    public const TERM_ADMINS = '_households_admins';

    /** Post meta on a person. */
    public const META_IS_CHILD = '_households_is_child';

    /** @var array<int,int> user ID => person post ID, for this request. */
    private static $person_by_user = [];

    public static function init(): void {
        add_filter( 'map_meta_cap', [ self::class, 'map_meta_cap' ], 10, 4 );
        add_filter( 'wp_insert_post_data', [ self::class, 'keep_person_author' ], 10, 2 );
    }

    /**
     * Nobody is a valid answer to who a person is, and the post API cannot say
     * it: `wp_insert_post()` reads an author of 0 as "whoever is logged in", so
     * writing down a toddler would hand their record to whoever wrote it, and
     * editing that record later would hand it to whoever edited it.
     *
     * So for people, an author given outright is taken at its word — 0 included
     * — and an edit that says nothing about the author keeps the one on file.
     *
     * @param array $data    the row about to be written.
     * @param array $postarr what the caller actually asked for.
     */
    public static function keep_person_author( array $data, array $postarr ): array {
        if ( ! isset( $data['post_type'] ) || self::PERSON !== $data['post_type'] ) {
            return $data;
        }
        if ( array_key_exists( 'post_author', $postarr ) ) {
            $data['post_author'] = (int) $postarr['post_author'];
        } elseif ( ! empty( $postarr['ID'] ) ) {
            $data['post_author'] = self::user_for_person( (int) $postarr['ID'] );
        }
        return $data;
    }

    /* ---------------------------------------------------------------- People */

    /**
     * The person record belonging to a WordPress user, or 0 if they have none.
     *
     * Someone can hold an account without a record — an administrator who set
     * the site up, say — and they are not a member of anything until one exists.
     */
    public static function person_for_user( int $user_id ): int {
        if ( ! $user_id ) {
            return 0;
        }
        if ( isset( self::$person_by_user[ $user_id ] ) ) {
            return self::$person_by_user[ $user_id ];
        }
        $ids = get_posts( [
            'author'           => $user_id,
            'fields'           => 'ids',
            'numberposts'      => 1,
            'post_status'      => 'private',
            'post_type'        => self::PERSON,
            'suppress_filters' => false,
        ] );
        self::$person_by_user[ $user_id ] = $ids ? (int) $ids[0] : 0;
        return self::$person_by_user[ $user_id ];
    }

    /** The WordPress user behind a person, or 0 when nobody logs in as them. */
    public static function user_for_person( int $person_id ): int {
        $person = get_post( $person_id );
        return $person && self::PERSON === $person->post_type ? (int) $person->post_author : 0;
    }

    public static function flush_person_cache(): void {
        self::$person_by_user = [];
    }

    /**
     * Say which WordPress account is this person's, or none with a user ID of 0.
     *
     * Accounts are not made here. They are made in WordPress, by whoever runs
     * the site, and pointed at a person afterwards — a family app has no
     * business minting logins, and a person is a record long before anybody
     * signs in as them. One account answers for one person: an account already
     * spoken for is refused rather than quietly moved.
     *
     * Administering is held as a user ID, so an account that stops being this
     * person stops administering the households they belong to at the same
     * moment. Otherwise it would keep a say in a household it has no presence
     * in.
     */
    public static function assign_user( int $person_id, int $user_id ): bool {
        if ( ! self::is_person( $person_id ) ) {
            return false;
        }
        if ( $user_id ) {
            $taken = self::person_for_user( $user_id );
            if ( ! get_userdata( $user_id ) || ( $taken && $taken !== $person_id ) ) {
                return false;
            }
        }

        $previous = self::user_for_person( $person_id );
        if ( $previous === $user_id ) {
            return true;
        }
        foreach ( self::home_ids_for_person( $person_id ) as $home_id ) {
            self::set_admin( $home_id, $previous, false );
        }

        wp_update_post( [
            'ID'          => $person_id,
            'post_author' => $user_id,
        ] );
        self::flush_person_cache();
        return true;
    }

    public static function is_person( int $person_id ): bool {
        $person = get_post( $person_id );
        return (bool) $person && self::PERSON === $person->post_type;
    }

    /** A child is a child at every home they go to, so this is not per home. */
    public static function is_child( int $person_id ): bool {
        return (bool) get_post_meta( $person_id, self::META_IS_CHILD, true );
    }

    /* ---------------------------------------------------------------- Membership */

    /** @return int[] term IDs of the homes this person belongs to. */
    public static function home_ids_for_person( int $person_id ): array {
        if ( ! $person_id ) {
            return [];
        }
        $terms = wp_get_object_terms( $person_id, self::TAXONOMY, [ 'fields' => 'ids' ] );
        return is_wp_error( $terms ) ? [] : array_map( 'intval', $terms );
    }

    /**
     * @return int[] term IDs of the homes this user may open: the ones they
     *               are in, and the ones they administer without being in.
     *
     * Setting a household up is not the same as living in it — somebody has to
     * make the grandparents' house before anybody is put in it — so a person
     * record is not what a household is reached through. Administering it is
     * enough, and a household nobody has joined yet is still yours to fill.
     */
    public static function home_ids_for_user( int $user_id ): array {
        return array_values( array_unique( array_merge(
            self::home_ids_for_person( self::person_for_user( $user_id ) ),
            self::administered_home_ids( $user_id )
        ) ) );
    }

    /**
     * @return int[] term IDs of the homes this user administers.
     *
     * Administrators are user IDs in term meta, which no query can ask about
     * backwards, so this reads the terms. A site holds few households — one
     * family's several — and this is the price of keeping the list on the
     * household rather than scattered over the people in it.
     */
    public static function administered_home_ids( int $user_id ): array {
        if ( ! $user_id ) {
            return [];
        }
        $terms = get_terms( [
            'taxonomy'   => self::TAXONOMY,
            'hide_empty' => false,
            'fields'     => 'ids',
        ] );
        if ( is_wp_error( $terms ) ) {
            return [];
        }
        $homes = [];
        foreach ( array_map( 'intval', $terms ) as $home_id ) {
            if ( in_array( $user_id, self::admins_of( $home_id ), true ) ) {
                $homes[] = $home_id;
            }
        }
        return $homes;
    }

    /** Is this household one this user may open at all? */
    public static function can_reach( int $user_id, int $home_id ): bool {
        return $home_id && ( self::is_member( self::person_for_user( $user_id ), $home_id ) || self::can_manage( $user_id, $home_id ) );
    }

    /** @return int[] person post IDs tagged with this home, oldest first. */
    public static function person_ids_in_home( int $home_id ): array {
        if ( ! $home_id ) {
            return [];
        }
        return array_map( 'intval', get_posts( [
            'fields'           => 'ids',
            'numberposts'      => -1,
            'order'            => 'ASC',
            'orderby'          => 'ID',
            'post_status'      => 'private',
            'post_type'        => self::PERSON,
            'suppress_filters' => false,
            'tax_query'        => [
                [
                    'field'    => 'term_id',
                    'taxonomy' => self::TAXONOMY,
                    'terms'    => $home_id,
                ],
            ],
        ] ) );
    }

    public static function is_member( int $person_id, int $home_id ): bool {
        return $person_id && $home_id && in_array( $home_id, self::home_ids_for_person( $person_id ), true );
    }

    public static function join( int $person_id, int $home_id ): void {
        wp_set_object_terms( $person_id, [ $home_id ], self::TAXONOMY, true );
    }

    /**
     * Take a person out of a home. The record itself survives — someone leaving
     * a household should not erase who they are, or what was written about them.
     */
    public static function leave( int $person_id, int $home_id ): void {
        wp_remove_object_terms( $person_id, [ $home_id ], self::TAXONOMY );
        self::set_admin( $home_id, self::user_for_person( $person_id ), false );
    }

    /* ---------------------------------------------------------------- Administrators */

    /** @return int[] WP user IDs who administer this home. */
    public static function admins_of( int $home_id ): array {
        $stored = get_term_meta( $home_id, self::TERM_ADMINS, true );
        return is_array( $stored ) ? array_values( array_unique( array_filter( array_map( 'intval', $stored ) ) ) ) : [];
    }

    public static function set_admin( int $home_id, int $user_id, bool $is_admin ): void {
        if ( ! $user_id ) {
            return;
        }
        $admins = self::admins_of( $home_id );
        $admins = array_values( array_diff( $admins, [ $user_id ] ) );
        if ( $is_admin ) {
            $admins[] = $user_id;
        }
        update_term_meta( $home_id, self::TERM_ADMINS, $admins );
    }

    /* ---------------------------------------------------------------- Decisions */

    /** Administers this home: may change its settings, members and rotations. */
    public static function can_manage( int $user_id, int $home_id ): bool {
        return $user_id && in_array( $user_id, self::admins_of( $home_id ), true );
    }

    /**
     * May add and assign things here: anyone in the household who is not a
     * child, and anyone who administers it, whether or not they are in it.
     */
    public static function can_organise( int $user_id, int $home_id ): bool {
        if ( self::can_manage( $user_id, $home_id ) ) {
            return true;
        }
        $person = self::person_for_user( $user_id );
        return self::is_member( $person, $home_id ) && ! self::is_child( $person );
    }

    /**
     * May this user settle things about that person — which account is theirs,
     * above all? True for anyone administering a home the person belongs to.
     *
     * It is asked of the person rather than of a home, because a person is one
     * record across all of them: saying whose account this is is the same act
     * whichever of their households it is said from.
     */
    public static function can_manage_person( int $user_id, int $person_id ): bool {
        foreach ( self::home_ids_for_person( $person_id ) as $home_id ) {
            if ( user_can( $user_id, 'manage_household', $home_id ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * May this user say where that person is today? True of themselves — a
     * statement about your own day is anybody's to make — and of anyone in a
     * household they organise, which is how a parent takes the children along.
     *
     * Like the rest of what is asked of a person rather than of a home: whether
     * you may move them is the same answer read from any household you share.
     */
    public static function can_place_person( int $user_id, int $person_id ): bool {
        if ( ! $person_id ) {
            return false;
        }
        if ( self::person_for_user( $user_id ) === $person_id ) {
            return true;
        }
        foreach ( self::home_ids_for_person( $person_id ) as $home_id ) {
            if ( self::can_organise( $user_id, $home_id ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * May this user look at that person's own view? True for themselves, and
     * for anyone administering a home the person belongs to.
     */
    public static function can_view_person( int $user_id, int $person_id ): bool {
        if ( ! $person_id ) {
            return false;
        }
        if ( self::person_for_user( $user_id ) === $person_id ) {
            return true;
        }
        foreach ( self::home_ids_for_person( $person_id ) as $home_id ) {
            if ( self::can_manage( $user_id, $home_id ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * `current_user_can( 'manage_household', $home_id )` and its organising
     * counterpart, so the rest of the plugin asks WordPress rather than us.
     */
    public static function map_meta_cap( array $caps, string $cap, int $user_id, array $args ): array {
        if ( 'manage_household' !== $cap && 'organise_household' !== $cap ) {
            return $caps;
        }
        $home_id = isset( $args[0] ) ? (int) $args[0] : 0;
        if ( ! $home_id ) {
            return [ 'do_not_allow' ];
        }
        $allowed = 'manage_household' === $cap
            ? self::can_manage( $user_id, $home_id )
            : self::can_organise( $user_id, $home_id );

        return $allowed ? [ 'read' ] : [ 'do_not_allow' ];
    }
}
