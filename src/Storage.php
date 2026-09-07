<?php
// phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query,WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Household lookups intentionally use metadata and taxonomy relationships.
namespace Households;

/**
 * Persistence.
 *
 * A home is a term in the `household` taxonomy: its name is the term name, its
 * administrators are term meta. Everything that happens in a home — the people,
 * the facts about the place, the things kept there, the tasks — is a post
 * tagged with that term, so one join answers every question, and a question
 * about several homes at once is the same join with more terms in it.
 *
 * A person is a post whose author is their WordPress user, or nobody at all.
 * What is true of them at length — allergies, medication, what the next person
 * needs to know — is the post's own content, so its history is the post's
 * revisions. The handful of answers that are a value rather than a sentence,
 * the sizes, are meta, each carrying the day it was written down.
 */
class Storage {
    /**
     * Every thing on its way somewhere, worked out once.
     *
     * The overview and the board ask how much is waiting on a route once per
     * trip they name, which is a question about the same handful of things
     * each time. Nothing changes it within a request: a form that does is
     * answered before anything is rendered and redirects, and what does write
     * puts this back to being unasked.
     *
     * @var array[]|null
     */
    private $going = null;

    public const WP_ROLE = 'households_member';

    public const FACT = 'household_fact';
    public const ITEM = 'household_item';
    public const TASK = 'household_task';

    /** Person meta. */
    public const META_LABEL     = '_households_label';
    public const META_BIRTHDATE = '_households_birthdate';
    public const META_SIZES     = '_households_sizes';
    public const META_LAST_HOME = '_households_last_home';

    /** Item meta. */
    public const META_WHERE = '_households_where';
    public const META_AT    = '_households_at';
    public const META_GOING = '_households_going';

    /** Task meta. */
    public const META_TASK_TYPE = '_households_task_type';
    public const META_DUE_DATE  = '_households_due_date';
    public const META_DONE_AT   = '_households_done_at';

    /* ---------------------------------------------------------------- Homes */

    public function create_home( string $name, int $admin_user_id = 0 ): int {
        $name = sanitize_text_field( $name );
        if ( '' === trim( $name ) ) {
            return 0;
        }

        // Terms are global; the names families give their homes are not. Two
        // households on one site may both have a "Home", and neither should be
        // refused — or told the other exists. Only the slug has to be unique,
        // so it is made unique here and the name is left alone.
        $slug = wp_unique_term_slug( sanitize_title( $name ), (object) [
            'taxonomy'   => Access::TAXONOMY,
            'parent'     => 0,
            'term_id'    => 0,
            'term_group' => 0,
        ] );
        $term = wp_insert_term( $name, Access::TAXONOMY, $slug ? [ 'slug' => $slug ] : [] );
        if ( is_wp_error( $term ) ) {
            return 0;
        }
        $home_id = (int) $term['term_id'];
        if ( $admin_user_id ) {
            Access::set_admin( $home_id, $admin_user_id, true );
        }
        return $home_id;
    }

    /**
     * Start a home. Whoever starts it administers it, and nothing else follows.
     *
     * Setting a household up is not the same as living in it: somebody has to
     * make the grandparents' house, or the flat a child stays in every other
     * weekend, before anybody is put in it. So this settles the one thing a
     * home cannot be without — someone who may add to it — and leaves who is
     * in it to be said next, on its own page, the starter included.
     */
    public function start_home( int $user_id, string $name ): int {
        return $this->create_home( $name, $user_id );
    }

    public function update_home( int $home_id, string $name ): void {
        $name = sanitize_text_field( $name );
        if ( '' !== trim( $name ) ) {
            wp_update_term( $home_id, Access::TAXONOMY, [ 'name' => $name ] );
        }
    }

    /** @return array{id:int,name:string}|array{} */
    public function get_home( int $home_id ): array {
        $term = $home_id ? get_term( $home_id, Access::TAXONOMY ) : null;
        if ( ! $term || is_wp_error( $term ) ) {
            return [];
        }
        return [
            'id'   => (int) $term->term_id,
            'name' => $term->name,
        ];
    }

    /** @return array[] the homes this user may open, named and ordered. */
    public function get_homes_for_user( int $user_id ): array {
        $homes = array_filter( array_map( [ $this, 'get_home' ], Access::home_ids_for_user( $user_id ) ) );
        usort( $homes, static function( array $a, array $b ): int {
            return strcasecmp( $a['name'], $b['name'] );
        } );
        return array_values( $homes );
    }

    /**
     * The homes this user may write in, which is where a task can be put and
     * where one already written down can be moved to.
     *
     * @return array[] named and ordered as the households list is.
     */
    public function homes_you_organise( int $user_id ): array {
        return array_values( array_filter(
            $this->get_homes_for_user( $user_id ),
            static function( array $home ) use ( $user_id ): bool {
                return Access::can_organise( $user_id, $home['id'] );
            }
        ) );
    }

    /** @return array[] the homes this person belongs to, named and ordered. */
    public function get_homes_for_person( int $person_id ): array {
        $homes = array_filter( array_map( [ $this, 'get_home' ], Access::home_ids_for_person( $person_id ) ) );
        usort( $homes, static function( array $a, array $b ): int {
            return strcasecmp( $a['name'], $b['name'] );
        } );
        return array_values( $homes );
    }

    /**
     * The index: every home the viewer belongs to, with who is under each roof
     * today and what is still open there.
     */
    public function get_homes_overview( int $user_id ): array {
        $overview = [];
        foreach ( $this->get_homes_for_user( $user_id ) as $home ) {
            $open = 0;
            foreach ( $this->get_tasks( $home['id'] ) as $task ) {
                $open += $task['is_done'] ? 0 : 1;
            }
            $overview[] = [
                'id'         => $home['id'],
                'name'       => $home['name'],
                'here'       => $this->who_is_here( $home['id'] ),
                'unknown'    => $this->whereabouts_unknown( $home['id'] ),
                'open_tasks' => $open,
                'can_manage' => Access::can_manage( $user_id, $home['id'] ),
            ];
        }
        return $overview;
    }

    /**
     * The last home this user was looking at.
     *
     * Every home has its own URL, so this is not a mode anyone switches into:
     * it is a note of where they were, used to pick a landing spot and to give
     * the cross-home views somewhere to look from.
     */
    public function last_home_id( int $user_id ): int {
        $homes = Access::home_ids_for_user( $user_id );
        if ( ! $homes ) {
            return 0;
        }
        // The note is kept on the person, so someone who only administers has
        // nowhere to keep one and simply lands on the first of theirs.
        $person_id = Access::person_for_user( $user_id );
        $last = $person_id ? (int) get_post_meta( $person_id, self::META_LAST_HOME, true ) : 0;
        return in_array( $last, $homes, true ) ? $last : $homes[0];
    }

    public function remember_home( int $user_id, int $home_id ): bool {
        $person_id = Access::person_for_user( $user_id );
        if ( ! $person_id || ! Access::can_reach( $user_id, $home_id ) ) {
            return false;
        }
        update_post_meta( $person_id, self::META_LAST_HOME, $home_id );
        return true;
    }

    /* ---------------------------------------------------------------- People */

    /**
     * Put the person behind an account into a home, making them a record first
     * if they have never needed one.
     *
     * Whoever starts a household is outside it until they say otherwise, and
     * this is how they say it. Someone who already has a record joins with it:
     * a second household does not make a second them.
     */
    public function add_self( int $user_id, int $home_id ): int {
        $person_id = Access::person_for_user( $user_id );
        if ( $person_id ) {
            Access::join( $person_id, $home_id );
            return $person_id;
        }

        $user = get_userdata( $user_id );
        $person_id = $this->add_person( $home_id, $user ? $user->display_name : '' );
        Access::assign_user( $person_id, $user_id );
        return $person_id;
    }

    /**
     * Add a person to a home: a name, and whatever else is known about them.
     *
     * Only the record is made here. Whether anybody signs in as them is a
     * separate question with a separate answer — `Access::assign_user()` — and
     * most of the time it is no. A toddler whose shoe size is worth writing
     * down, or a relative you only keep notes about, is a person here without
     * ever being a login.
     */
    public function add_person( int $home_id, string $name, array $args = [] ): int {
        $name = sanitize_text_field( $name );
        if ( '' === trim( $name ) || ! $this->get_home( $home_id ) ) {
            return 0;
        }

        $person_id = $this->write_post( [
            'post_author' => 0,
            'post_status' => 'private',
            'post_title'  => $name,
            'post_type'   => Access::PERSON,
        ] );
        if ( ! $person_id ) {
            return 0;
        }

        Access::join( $person_id, $home_id );
        $this->save_person( $person_id, $args );

        return $person_id;
    }

    /**
     * Accounts that could be this person's: the ones nobody else answers for.
     *
     * @return array[] id and a label saying who the account is, ordered by name.
     */
    public function assignable_users( int $person_id ): array {
        $users = [];
        foreach ( get_users( [ 'orderby' => 'display_name' ] ) as $user ) {
            $taken = Access::person_for_user( (int) $user->ID );
            if ( $taken && $taken !== $person_id ) {
                continue;
            }
            $users[] = [
                'id'    => (int) $user->ID,
                'name'  => $user->display_name ? $user->display_name : $user->user_login,
                'login' => $user->user_login,
            ];
        }
        return $users;
    }

    /** @return array The person as the app talks about them, or an empty array. */
    public function get_person( int $person_id ): array {
        $person = get_post( $person_id );
        if ( ! $person || Access::PERSON !== $person->post_type ) {
            return [];
        }
        $birthdate = (string) get_post_meta( $person_id, self::META_BIRTHDATE, true );
        return [
            'id'        => (int) $person->ID,
            'name'      => $person->post_title,
            'about'     => $person->post_content,
            'label'     => (string) get_post_meta( $person_id, self::META_LABEL, true ),
            'is_child'  => Access::is_child( $person_id ),
            'birthdate' => $birthdate,
            'age'       => $this->age_from_birthdate( $birthdate ),
            'sizes'     => $this->get_sizes( $person_id ),
            'user_id'   => (int) $person->post_author,
            'homes'     => $this->get_homes_for_person( $person_id ),
        ];
    }

    /** @return array[] everyone tagged with this home. */
    public function get_people( int $home_id ): array {
        return array_values( array_filter( array_map( [ $this, 'get_person' ], Access::person_ids_in_home( $home_id ) ) ) );
    }

    /**
     * Save what is known about a person. The name and the prose are the post's
     * own title and content, so editing either leaves a revision — and both go
     * in one write, because one press of Save is one version, not two.
     *
     * A name is not emptied by a form that carries none: renaming somebody to
     * nothing is not a thing anyone means to do, and the pages that save the
     * rest of a person do not ask for it.
     */
    public function save_person( int $person_id, array $fields ): void {
        if ( ! Access::is_person( $person_id ) ) {
            return;
        }
        $write = [];
        if ( array_key_exists( 'name', $fields ) && '' !== trim( (string) $fields['name'] ) ) {
            $write['post_title'] = sanitize_text_field( (string) $fields['name'] );
        }
        if ( array_key_exists( 'about', $fields ) ) {
            $write['post_content'] = sanitize_textarea_field( (string) $fields['about'] );
        }
        if ( $write ) {
            $this->write_post( [ 'ID' => $person_id ] + $write );
        }
        if ( array_key_exists( 'label', $fields ) ) {
            update_post_meta( $person_id, self::META_LABEL, sanitize_text_field( (string) $fields['label'] ) );
        }
        if ( array_key_exists( 'is_child', $fields ) ) {
            update_post_meta( $person_id, Access::META_IS_CHILD, $fields['is_child'] ? 1 : 0 );
        }
        if ( array_key_exists( 'birthdate', $fields ) ) {
            update_post_meta( $person_id, self::META_BIRTHDATE, $this->normalize_date( (string) $fields['birthdate'] ) );
        }
        if ( array_key_exists( 'sizes', $fields ) && is_array( $fields['sizes'] ) ) {
            $this->save_sizes( $person_id, $fields['sizes'] );
        }
    }

    /**
     * The few things about a person that are a value rather than a sentence.
     *
     * These are asked for by somebody standing in a shop with a phone, and the
     * answer is two characters long. Hunting for it down a paragraph is the
     * wrong shape, so they are fields — but each keeps the day it was written,
     * because a child's shoe size is only an answer if you know its age.
     *
     * @return array<string,string> the key, and what the form calls it.
     */
    public static function size_fields(): array {
        return [
            'clothing' => __( 'Clothing size', 'households' ),
            'shoe'     => __( 'Shoe size', 'households' ),
        ];
    }

    /**
     * Every size the app knows to ask about, whether or not it has an answer.
     *
     * The page draws a row per field either way, so the empty ones come back
     * too rather than leaving the template to work out what is missing.
     *
     * @return array<string,array> value, and the date it was written down.
     */
    public function get_sizes( int $person_id ): array {
        $stored = get_post_meta( $person_id, self::META_SIZES, true );
        $stored = is_array( $stored ) ? $stored : [];
        $sizes = [];
        foreach ( array_keys( self::size_fields() ) as $key ) {
            $sizes[ $key ] = [
                'value' => isset( $stored[ $key ]['value'] ) ? (string) $stored[ $key ]['value'] : '',
                'noted' => isset( $stored[ $key ]['noted'] ) ? $this->normalize_date( (string) $stored[ $key ]['noted'] ) : '',
            ];
        }
        return $sizes;
    }

    /**
     * Write the sizes down, stamping the ones that changed with today.
     *
     * The date is not asked for. It is the day the value was written, which is
     * the only day anybody could honestly claim, and a size that came back
     * unchanged keeps the stamp it had — so saving this page to fix a typo
     * elsewhere does not make a year-old measurement look like this morning's.
     * Emptied, a size goes, stamp and all: there is no answer to date.
     */
    private function save_sizes( int $person_id, array $said ): void {
        $today = current_time( 'Y-m-d' );
        $keep = [];
        foreach ( $this->get_sizes( $person_id ) as $key => $size ) {
            $value = array_key_exists( $key, $said ) ? sanitize_text_field( (string) $said[ $key ] ) : $size['value'];
            $value = trim( $value );
            if ( '' === $value ) {
                continue;
            }
            $keep[ $key ] = [
                'value' => $value,
                'noted' => ( $value === $size['value'] && $size['noted'] ) ? $size['noted'] : $today,
            ];
        }
        if ( $keep ) {
            update_post_meta( $person_id, self::META_SIZES, $keep );
        } else {
            delete_post_meta( $person_id, self::META_SIZES );
        }
    }

    /** People with a birthdate, ordered by how soon their next birthday is. */
    public function get_upcoming_birthdays( int $home_id ): array {
        $today = new \DateTimeImmutable( current_time( 'Y-m-d' ) );
        $list = [];
        foreach ( $this->get_people( $home_id ) as $person ) {
            $born = '' !== $person['birthdate'] ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $person['birthdate'] ) : null;
            if ( ! $born ) {
                continue;
            }
            $next = $born->setDate( (int) $today->format( 'Y' ), (int) $born->format( 'm' ), (int) $born->format( 'd' ) );
            if ( $next < $today ) {
                $next = $next->modify( '+1 year' );
            }
            $list[] = [
                'id'         => $person['id'],
                'name'       => $person['name'],
                'date'       => $next->format( 'Y-m-d' ),
                'days_until' => (int) $today->diff( $next )->days,
                'turning'    => (int) $next->format( 'Y' ) - (int) $born->format( 'Y' ),
            ];
        }
        usort( $list, static function( array $a, array $b ): int {
            return $a['days_until'] <=> $b['days_until'];
        } );
        return $list;
    }

    private function age_from_birthdate( string $birthdate ): ?int {
        $born = '' !== $birthdate ? \DateTimeImmutable::createFromFormat( '!Y-m-d', $birthdate ) : null;
        return $born ? (int) $born->diff( new \DateTimeImmutable( current_time( 'Y-m-d' ) ) )->y : null;
    }

    /**
     * Everyone across every home the viewer belongs to, each listed once.
     *
     * Someone in three of your homes is one person, not three entries — which
     * is the whole reason a person is a record rather than a membership row.
     */
    public function get_people_overview( int $user_id ): array {
        $viewer = Access::person_for_user( $user_id );
        $people = [];
        foreach ( Access::home_ids_for_user( $user_id ) as $home_id ) {
            foreach ( Access::person_ids_in_home( $home_id ) as $person_id ) {
                if ( isset( $people[ $person_id ] ) ) {
                    continue;
                }
                $person = $this->get_person( $person_id );
                if ( ! $person ) {
                    continue;
                }
                // Both come out of the same lookup: where they are today, and
                // whether that is something said about the day rather than
                // something a pattern worked out. The second is what decides
                // whether there is anything to take back.
                $at = Whereabouts::home_on( $person_id, current_time( 'Y-m-d' ) );
                $at_home = $at['home_id'] ? $this->get_home( $at['home_id'] ) : [];
                $person['location'] = [
                    'home_id' => $at['home_id'],
                    'name'    => isset( $at_home['name'] ) ? $at_home['name'] : '',
                    'known'   => (bool) $at['home_id'],
                ];
                $person['said'] = $at['is_override'];
                $person['rotates'] = (bool) Whereabouts::get_rotation( $person_id );
                $person['is_you'] = $person_id === $viewer;
                $people[ $person_id ] = $person;
            }
        }
        $people = array_values( $people );
        usort( $people, static function( array $a, array $b ): int {
            return strcasecmp( $a['name'], $b['name'] );
        } );
        return $people;
    }

    /* ---------------------------------------------------------------- Facts and items */

    /**
     * Facts and items are the same record in two moods: a title, some detail,
     * and the home it is true of. A fact is what the house needs you to know —
     * the wifi network, where the water main valve is, which day the bins go
     * out. An item is a thing kept there. Both keep their history, because both
     * are posts and both are edited in place rather than replaced.
     *
     * They part company over the detail. A fact is one house's, so it is the
     * post's own content. A thing can be kept in several, and is in a different
     * place in each, so where it lives is written against the household.
     */
    public function add_note( int $home_id, string $post_type, string $title, string $detail ): int {
        $title = sanitize_text_field( $title );
        if ( '' === trim( $title ) || ! $this->get_home( $home_id ) ) {
            return 0;
        }
        $item = self::ITEM === $post_type;
        $post_id = $this->write_post( [
            'post_content' => $item ? '' : sanitize_textarea_field( $detail ),
            'post_status'  => 'private',
            'post_title'   => $title,
            'post_type'    => $post_type,
        ] );
        if ( $post_id ) {
            wp_set_object_terms( $post_id, [ $home_id ], Access::TAXONOMY );
            if ( $item ) {
                $this->set_where( $post_id, $home_id, $detail );
            }
        }
        return $post_id;
    }

    /**
     * Said nothing about a field and the field is left alone: a form that does
     * not carry the note, or where it lives, is not a form emptying it.
     */
    public function update_note( int $home_id, string $post_type, int $post_id, string $title, ?string $detail = null, ?string $note = null ): bool {
        if ( ! $this->note_belongs_to( $post_id, $post_type, $home_id ) || '' === trim( $title ) ) {
            return false;
        }
        $fields = [
            'ID'         => $post_id,
            'post_title' => sanitize_text_field( $title ),
        ];
        if ( null !== $detail ) {
            if ( self::ITEM === $post_type ) {
                $this->set_where( $post_id, $home_id, $detail );
            } else {
                $fields['post_content'] = sanitize_textarea_field( $detail );
            }
        }
        if ( null !== $note ) {
            $fields['post_excerpt'] = sanitize_textarea_field( $note );
        }
        $this->write_post( $fields );
        return true;
    }

    /**
     * Where a thing lives, household by household.
     *
     * The same thing is kept in a different place in each house it is in — on
     * the hook by the door at one, in the kitchen drawer at the other — so the
     * line is written against the household rather than against the thing.
     *
     * @return array<int,string> home id to what is written there.
     */
    private function where_kept( int $post_id ): array {
        $stored = get_post_meta( $post_id, self::META_WHERE, true );
        $where = [];
        foreach ( is_array( $stored ) ? $stored : [] as $home_id => $said ) {
            $where[ (int) $home_id ] = (string) $said;
        }
        return $where;
    }

    /**
     * What one household's line reads, for a thing that may have several.
     *
     * Something written down when a thing could only be in one place has its
     * one line in the post itself, and that is the answer wherever it is kept
     * until some household is given a line of its own.
     */
    private function where_at( \WP_Post $post, int $home_id ): string {
        $kept = $this->where_kept( (int) $post->ID );
        if ( ! $kept ) {
            return (string) $post->post_content;
        }
        return isset( $kept[ $home_id ] ) ? $kept[ $home_id ] : '';
    }

    /**
     * Write one household's line, leaving what the other houses say alone.
     *
     * The first line written takes the old one with it: what the post itself
     * said becomes every household's answer before this one is changed, so
     * nothing said before houses were told apart is lost by telling them apart.
     */
    private function set_where( int $post_id, int $home_id, string $where ): void {
        $kept = $this->where_kept( $post_id );
        if ( ! $kept ) {
            $post = get_post( $post_id );
            $said = $post ? (string) $post->post_content : '';
            foreach ( $this->home_ids_of_post( $post_id ) as $was ) {
                $kept[ $was ] = $said;
            }
        }
        $kept[ $home_id ] = sanitize_text_field( $where );
        update_post_meta( $post_id, self::META_WHERE, $kept );
    }

    /**
     * The note as it has read over time, newest first and each wording said
     * once: a run of saves that left it alone is not a history of anything.
     *
     * @return array[] each with the revision to put back, when it was written
     *                 and by whom.
     */
    public function get_note_history( int $post_id, string $post_type ): array {
        $post = get_post( $post_id );
        if ( ! $post || $post_type !== $post->post_type ) {
            return [];
        }
        $history = [];
        $newer = (string) $post->post_excerpt;
        foreach ( wp_get_post_revisions( $post_id, [ 'check_enabled' => false ] ) as $revision ) {
            $note = (string) $revision->post_excerpt;
            if ( $note === $newer ) {
                continue;
            }
            $history[] = [
                'id'   => (int) $revision->ID,
                'note' => $note,
                'when' => $revision->post_modified,
                'who'  => $this->who_saved( (int) $revision->post_author ),
            ];
            $newer = $note;
        }
        return $history;
    }

    /**
     * Put an older wording of the note back. Only the note: the name and where
     * it lives are what they are now, and were not what was asked about.
     */
    public function restore_note( int $home_id, string $post_type, int $post_id, int $revision_id ): bool {
        if ( ! $this->note_belongs_to( $post_id, $post_type, $home_id ) ) {
            return false;
        }
        $revision = wp_get_post_revision( $revision_id );
        if ( ! $revision || (int) $revision->post_parent !== $post_id ) {
            return false;
        }
        $this->write_post( [
            'ID'           => $post_id,
            'post_excerpt' => $revision->post_excerpt,
        ] );
        return true;
    }

    /** Whoever saved it, said the way the family says their name. */
    private function who_saved( int $user_id ): string {
        $person = $user_id ? Access::person_for_user( $user_id ) : 0;
        $named = $person ? $this->get_person( $person ) : [];
        if ( ! empty( $named['name'] ) ) {
            return $named['name'];
        }
        $user = $user_id ? get_userdata( $user_id ) : null;
        return $user ? $user->display_name : '';
    }

    /**
     * Where the thing is at this moment, which is not the same question as
     * which houses keep a place for it. A thing lives on the hook by the door
     * and is in the car; the hook is still where it lives, and the line saying
     * so is written and read whether or not the thing is hanging on it.
     *
     * Said outright, it is wherever it was last said to be. That may be a house
     * that does not keep it, which is a thing lent rather than a thing moved:
     * it belongs where it belonged, and goes back by being said to be there.
     *
     * Said nothing at all, a thing kept in one house is in it, because keeping
     * it is the whole of what has been said about it. A thing kept in several
     * is somewhere among them nobody has named, and that is answered as not
     * known rather than guessed at.
     *
     * @param int[] $keepers the households that keep it.
     * @return array{home_id:int,name:string,kept:bool,said:bool} home_id 0 when nobody has said.
     */
    private function at_of_note( int $post_id, array $keepers ): array {
        $said = (int) get_post_meta( $post_id, self::META_AT, true );
        $home = $said ? $this->get_home( $said ) : [];
        if ( ! $home ) {
            // A household that has since gone says nothing about where the
            // thing is, so what is left is what keeping it says.
            $said = 0;
            $home = 1 === count( $keepers ) ? $this->get_home( (int) reset( $keepers ) ) : [];
        }
        return [
            'home_id' => isset( $home['id'] ) ? (int) $home['id'] : 0,
            'name'    => isset( $home['name'] ) ? (string) $home['name'] : '',
            'kept'    => isset( $home['id'] ) && in_array( (int) $home['id'], $keepers, true ),
            'said'    => (bool) $said,
        ];
    }

    /**
     * Say a thing is at a household right now. Only that: no house starts or
     * stops keeping it, and no line about where it lives is touched, because
     * something being somewhere else for a while is not something changing
     * where it belongs.
     *
     * The household may be one that does not keep it, which is how a thing
     * taken along is said. It goes back by being said to be back.
     */
    public function say_note_is_at( int $home_id, string $post_type, int $post_id ): bool {
        $post = $post_id ? get_post( $post_id ) : null;
        if ( ! $post || self::ITEM !== $post_type || $post_type !== $post->post_type || ! $this->get_home( $home_id ) ) {
            return false;
        }
        update_post_meta( $post_id, self::META_AT, $home_id );
        // Said to be where it was going, it has got there, and there is
        // nothing left to remember: the whole of the plan was to get it here.
        // Said to be anywhere else it is still on its way, and the list it is
        // on simply starts from where it now is.
        if ( $this->going_mark( $post_id )['to'] === $home_id ) {
            delete_post_meta( $post_id, self::META_GOING );
        }
        $this->going = null;
        return true;
    }

    /**
     * Where a thing is to get to, which is not yet where it is.
     *
     * Between deciding the swimming bag has to be at the other house and it
     * being there is a bag that has to be packed, and for all of that time the
     * truthful answer to where the thing is is the house it is standing in. So
     * this is a second mark beside that one, and saying it moves nothing: what
     * moves the thing is somebody saying it has got there.
     *
     * The mark remembers whether it is in the bag yet, which is a third thing
     * again: packing something is neither where it is nor where it is to go.
     * It is at the house it was at, in a bag by the door, until somebody
     * actually carries the bag.
     *
     * @param int[] $keepers the households that keep it.
     * @return array{home_id:int,name:string,kept:bool,packed_at:string,is_packed:bool}
     *               home_id 0 when it is going nowhere.
     */
    private function going_of_note( int $post_id, array $keepers ): array {
        $said = $this->going_mark( $post_id );
        $home = $said['to'] ? $this->get_home( $said['to'] ) : [];
        return [
            'home_id'   => isset( $home['id'] ) ? (int) $home['id'] : 0,
            'name'      => isset( $home['name'] ) ? (string) $home['name'] : '',
            'kept'      => isset( $home['id'] ) && in_array( (int) $home['id'], $keepers, true ),
            'packed_at' => $home ? $said['packed'] : '',
            'is_packed' => (bool) ( $home && $said['packed'] ),
        ];
    }

    /**
     * The going mark as it is stored: where to, and when it went in the bag.
     * Written before the two were told apart, it is the household it was to go
     * to and nothing else.
     *
     * @return array{to:int,packed:string}
     */
    private function going_mark( int $post_id ): array {
        $said = get_post_meta( $post_id, self::META_GOING, true );
        if ( ! is_array( $said ) ) {
            return [ 'to' => (int) $said, 'packed' => '' ];
        }
        return [
            'to'     => isset( $said['to'] ) ? (int) $said['to'] : 0,
            'packed' => isset( $said['packed'] ) ? (string) $said['packed'] : '',
        ];
    }

    /**
     * Say a thing is to go to a household. Only that: it is where it was until
     * somebody says it has got there, and no line about where it lives is
     * touched, because something being wanted elsewhere is not something
     * changing where it belongs.
     *
     * Saying it is to go where it already is is not a plan, and is the same as
     * saying nothing.
     */
    public function say_note_goes_to( int $home_id, string $post_type, int $post_id ): bool {
        $post = $post_id ? get_post( $post_id ) : null;
        if ( ! $post || self::ITEM !== $post_type || $post_type !== $post->post_type || ! $this->get_home( $home_id ) ) {
            return false;
        }
        $this->going = null;
        $keepers = $this->home_ids_of_post( $post_id );
        if ( $this->at_of_note( $post_id, $keepers )['home_id'] === $home_id ) {
            delete_post_meta( $post_id, self::META_GOING );
            return false;
        }
        update_post_meta( $post_id, self::META_GOING, [
            'to'     => $home_id,
            'packed' => '',
        ] );
        return true;
    }

    /**
     * Tick a thing off the packlist, or take the tick back.
     *
     * Packing something is not moving it. It is in a bag by the door, at the
     * house it was already at, and where to look for it is still that house
     * until somebody carries the bag — which is said once for the whole trip
     * rather than once for every thing in it. So this touches nothing but
     * whether the line is struck through.
     */
    public function toggle_packed( int $home_id, string $post_type, int $post_id ): bool {
        $post = $post_id ? get_post( $post_id ) : null;
        $said = $post ? $this->going_mark( $post_id ) : [ 'to' => 0 ];
        if ( ! $post || self::ITEM !== $post_type || $post_type !== $post->post_type || $said['to'] !== $home_id ) {
            return false;
        }
        $this->going = null;
        $said['packed'] = $said['packed'] ? '' : current_time( 'mysql' );
        update_post_meta( $post_id, self::META_GOING, $said );
        return true;
    }

    /**
     * The bag has been carried: everything in it is at the household it was
     * going to, and off the list.
     *
     * What was not packed is not what was taken, so it stays where it is and
     * stays on the list for the next trip that way — which is the whole reason
     * the bag is ticked off thing by thing and carried in one go.
     *
     * @return int how many things went.
     */
    public function things_arrived( int $user_id, int $from_home_id, int $to_home_id ): int {
        $gone = 0;
        foreach ( $this->things_going() as $thing ) {
            if ( ! $thing['going']['is_packed'] || $thing['going']['home_id'] !== $to_home_id ) {
                continue;
            }
            if ( $thing['at']['home_id'] !== $from_home_id ) {
                continue;
            }
            // A thing kept in none of the viewer's households is not theirs to
            // say anything about, however the bag it was in got here.
            if ( ! $this->may_reach_note( $user_id, $thing['id'], self::ITEM ) ) {
                continue;
            }
            update_post_meta( $thing['id'], self::META_AT, $to_home_id );
            delete_post_meta( $thing['id'], self::META_GOING );
            $gone++;
        }
        $this->going = null;
        return $gone;
    }

    /** It is not going after all, which asks nothing of where it is or lives. */
    public function say_note_is_not_going( string $post_type, int $post_id ): bool {
        $post = $post_id ? get_post( $post_id ) : null;
        if ( ! $post || self::ITEM !== $post_type || $post_type !== $post->post_type ) {
            return false;
        }
        delete_post_meta( $post_id, self::META_GOING );
        $this->going = null;
        return true;
    }

    /**
     * Start keeping a thing at a household, or say afresh where it lives there.
     * One verb for both, because they are the same sentence: this house keeps
     * it, here.
     *
     * A house comes to keep it by being told where in it the thing lives, so a
     * house told nothing is given nothing: an empty line on a form asking
     * every house of yours the same question is a house being passed over, not
     * a house being handed something. A house that already keeps it may of
     * course empty its own line, which says only that nobody has written down
     * where the thing lives there.
     */
    public function keep_note_at( int $home_id, string $post_type, int $post_id, string $where ): bool {
        $post = $post_id ? get_post( $post_id ) : null;
        if ( ! $post || $post_type !== $post->post_type || ! $this->get_home( $home_id ) ) {
            return false;
        }
        $homes = $this->home_ids_of_post( $post_id );
        if ( ! in_array( $home_id, $homes, true ) ) {
            if ( '' === trim( $where ) ) {
                return false;
            }
            $homes[] = $home_id;
            wp_set_object_terms( $post_id, $homes, Access::TAXONOMY );
        }
        $this->set_where( $post_id, $home_id, $where );
        return true;
    }

    /**
     * Stop keeping a thing at a household. The last one is refused: a thing is
     * somewhere rather than nowhere, and something kept nowhere is a thing to
     * be removed rather than a thing to be left unfindable.
     */
    public function drop_note_at( int $home_id, string $post_type, int $post_id ): bool {
        if ( ! $this->note_belongs_to( $post_id, $post_type, $home_id ) ) {
            return false;
        }
        $homes = array_values( array_diff( $this->home_ids_of_post( $post_id ), [ $home_id ] ) );
        if ( ! $homes ) {
            return false;
        }
        wp_set_object_terms( $post_id, $homes, Access::TAXONOMY );
        $this->forget_where( $post_id, $home_id );
        // A house that has given a thing up is not a house it is on loan to,
        // so what was said about it being there is unsaid rather than left to
        // read as a loan.
        if ( (int) get_post_meta( $post_id, self::META_AT, true ) === $home_id ) {
            delete_post_meta( $post_id, self::META_AT );
        }
        return true;
    }

    /** A house that no longer keeps it has nothing to say about where it is. */
    private function forget_where( int $post_id, int $home_id ): void {
        $kept = $this->where_kept( $post_id );
        if ( isset( $kept[ $home_id ] ) ) {
            unset( $kept[ $home_id ] );
            update_post_meta( $post_id, self::META_WHERE, $kept );
        }
    }

    public function remove_note( int $home_id, string $post_type, int $post_id ): bool {
        if ( ! $this->note_belongs_to( $post_id, $post_type, $home_id ) ) {
            return false;
        }
        wp_trash_post( $post_id );
        return true;
    }

    /**
     * @return array[] every note of this type in this home. A thing's detail is
     *                 what this household says about where it lives, and it
     *                 carries the households it is kept at, and which one it is
     *                 at just now, so a page can say where it has got to.
     */
    public function get_notes( int $home_id, string $post_type ): array {
        $notes = [];
        foreach ( $this->posts_in_home( $home_id, $post_type ) as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                continue;
            }
            $item = self::ITEM === $post_type;
            $home_ids = $item ? $this->home_ids_of_post( $post_id ) : [ $home_id ];
            $at = $item ? $this->at_of_note( $post_id, $home_ids ) : [];
            $notes[] = [
                'id'       => (int) $post->ID,
                'title'    => $post->post_title,
                'detail'   => $item ? $this->where_at( $post, $home_id ) : $post->post_content,
                'home_ids' => $home_ids,
                // And those households named, each with its own line, in the
                // shape the pages that span households read: a thing is one
                // thing whichever list it is being read on, so the row that
                // prints it need not be written twice.
                'homes'    => $item ? $this->homes_of_note( $post, $post_type ) : [],
                // Where a thing is at this moment, which the list says only
                // when it is somewhere other than where the list is read.
                'at'       => $at,
                // And where it is to get to, which is nowhere it is yet.
                'going'    => $item ? $this->going_of_note( $post_id, $home_ids ) : [],
                'modified' => $post->post_modified,
            ];
        }
        return $notes;
    }

    /**
     * Things said to be here just now that this house does not keep: brought
     * along for the weekend, borrowed, left behind after a visit. They belong
     * where they belong, and this is only where they have got to, so they are
     * a list of their own rather than mixed in with what is kept here.
     *
     * @return array[] each with the thing and the households that do keep it.
     */
    public function things_on_loan_here( int $home_id ): array {
        if ( ! $home_id ) {
            return [];
        }
        $things = [];
        $post_ids = array_map( 'intval', get_posts( [
            'fields'           => 'ids',
            'numberposts'      => -1,
            'order'            => 'ASC',
            'orderby'          => 'ID',
            'post_status'      => 'private',
            'post_type'        => self::ITEM,
            'suppress_filters' => false,
            'meta_query'       => [
                [
                    'key'   => self::META_AT,
                    'value' => $home_id,
                ],
            ],
        ] ) );
        foreach ( $post_ids as $post_id ) {
            $post = get_post( $post_id );
            $keepers = $post ? $this->home_ids_of_post( $post_id ) : [];
            // A house that keeps it is not a house it has been lent to, and a
            // thing kept nowhere at all is nobody's to be shown.
            if ( ! $keepers || in_array( $home_id, $keepers, true ) ) {
                continue;
            }
            $things[] = [
                'id'       => (int) $post->ID,
                'title'    => $post->post_title,
                'home_ids' => $keepers,
                'homes'    => array_values( array_filter( array_map( [ $this, 'get_home' ], $keepers ) ) ),
            ];
        }
        usort( $things, static function( array $a, array $b ): int {
            return strcasecmp( $a['title'], $b['title'] );
        } );
        return $things;
    }

    /**
     * One note by ID, with every household that keeps it — or nothing at all,
     * if that is not what the ID is.
     *
     * A note kept nowhere is not a note anybody can be shown, so a note whose
     * households have all gone is the same answer as a note that never was.
     */
    public function get_note( int $post_id, string $post_type ): array {
        $post = $post_id ? get_post( $post_id ) : null;
        if ( ! $post || $post_type !== $post->post_type ) {
            return [];
        }
        $homes = $this->homes_of_note( $post, $post_type );
        if ( ! $homes ) {
            return [];
        }
        $keepers = wp_list_pluck( $homes, 'id' );
        $at = self::ITEM === $post_type ? $this->at_of_note( (int) $post->ID, $keepers ) : [];
        return [
            'id'        => (int) $post->ID,
            'title'     => $post->post_title,
            // What is worth remembering about the thing, as against the line
            // saying where it lives. It is kept in the excerpt because that is
            // one of the three fields WordPress writes revisions of, so its
            // history is kept without keeping it.
            'note'      => $post->post_excerpt,
            'modified'  => $post->post_modified,
            // Each household that keeps it, and what it says about where it
            // lives there. Named in the same order the households list is, so
            // the two pages read alike.
            'homes'     => $homes,
            // And which household it is at just now, kept there or lent there.
            'at'        => $at,
            // And which one it is to get to, which is not where it is.
            'going'     => self::ITEM === $post_type
                ? $this->going_of_note( (int) $post->ID, $keepers )
                : [],
        ];
    }

    /** @return array[] the households keeping a note, named and ordered, each with its own line. */
    private function homes_of_note( \WP_Post $post, string $post_type ): array {
        $homes = [];
        foreach ( $this->home_ids_of_post( (int) $post->ID ) as $home_id ) {
            $home = $this->get_home( $home_id );
            if ( ! $home ) {
                continue;
            }
            $home['where'] = self::ITEM === $post_type ? $this->where_at( $post, $home_id ) : $post->post_content;
            $homes[] = $home;
        }
        usort( $homes, static function( array $a, array $b ): int {
            return strcasecmp( $a['name'], $b['name'] );
        } );
        return $homes;
    }

    /**
     * Whether a note is the viewer's to open at all: it is, if any one of the
     * households keeping it is one of theirs.
     */
    public function may_reach_note( int $user_id, int $post_id, string $post_type ): bool {
        $post = $post_id ? get_post( $post_id ) : null;
        if ( ! $post || $post_type !== $post->post_type ) {
            return false;
        }
        foreach ( $this->home_ids_of_post( $post_id ) as $home_id ) {
            if ( Access::can_reach( $user_id, $home_id ) ) {
                return true;
            }
        }
        return false;
    }

    private function note_belongs_to( int $post_id, string $post_type, int $home_id ): bool {
        $post = get_post( $post_id );
        return $post && $post_type === $post->post_type && in_array( $home_id, $this->home_ids_of_post( $post_id ), true );
    }

    /**
     * Everything kept across the homes the viewer belongs to, said once each
     * with the households keeping it under it. A thing in two houses is one
     * thing, so it is one line here however many houses have it.
     *
     * Only the viewer's own households are named: that another family keeps the
     * same thing is not something this page knows how to say, and is not theirs
     * to be told.
     */
    public function get_things_overview( int $user_id ): array {
        $things = [];
        foreach ( Access::home_ids_for_user( $user_id ) as $home_id ) {
            $home = $this->get_home( $home_id );
            foreach ( $this->get_notes( $home_id, self::ITEM ) as $thing ) {
                $id = $thing['id'];
                if ( ! isset( $things[ $id ] ) ) {
                    $things[ $id ] = [
                        'id'    => $id,
                        'title' => $thing['title'],
                        'at'    => $thing['at'],
                        'going' => $thing['going'],
                        'homes' => [],
                    ];
                }
                $things[ $id ]['homes'][] = [
                    'id'    => $home_id,
                    'name'  => $home['name'] ?? '',
                    'where' => $thing['detail'],
                ];
            }
        }
        $things = array_values( $things );
        foreach ( $things as &$one ) {
            usort( $one['homes'], static function( array $a, array $b ): int {
                return strcasecmp( $a['name'], $b['name'] );
            } );
        }
        unset( $one );
        usort( $things, static function( array $a, array $b ): int {
            return strcasecmp( $a['title'], $b['title'] );
        } );
        return $things;
    }

    /**
     * Every thing on its way somewhere: what it is, where it is, where it is
     * to get to, and which households keep it.
     *
     * @return array[] keyed by post ID.
     */
    private function things_going(): array {
        if ( null !== $this->going ) {
            return $this->going;
        }
        $this->going = [];
        $post_ids = array_map( 'intval', get_posts( [
            'fields'           => 'ids',
            'numberposts'      => -1,
            'order'            => 'ASC',
            'orderby'          => 'ID',
            'post_status'      => 'private',
            'post_type'        => self::ITEM,
            'suppress_filters' => false,
            'meta_query'       => [
                [
                    'key'     => self::META_GOING,
                    'compare' => 'EXISTS',
                ],
            ],
        ] ) );
        foreach ( $post_ids as $post_id ) {
            $post = get_post( $post_id );
            if ( ! $post ) {
                continue;
            }
            $keepers = $this->home_ids_of_post( $post_id );
            $at = $this->at_of_note( $post_id, $keepers );
            $going = $this->going_of_note( $post_id, $keepers );
            // A household that has since gone is not somewhere to take
            // anything, so what was said about it says nothing now.
            if ( ! $going['home_id'] ) {
                continue;
            }
            $this->going[ $post_id ] = [
                'id'       => $post_id,
                'title'    => $post->post_title,
                'home_ids' => $keepers,
                // Named, so the packlist prints the same row every other list
                // of things does.
                'homes'    => $this->homes_of_note( $post, self::ITEM ),
                'at'       => $at,
                'going'    => $going,
            ];
        }
        return $this->going;
    }

    /**
     * What is waiting to be taken from one household to another, a trip at a
     * time.
     *
     * Things going the same way are one list, because that is one bag. The
     * trip itself is whatever move the fortnight already holds along the same
     * route: the list is the answer to "what goes with us", so it says when
     * "with us" is, and says plainly when nobody is going that way yet.
     *
     * The household it is going to has to be one of the viewer's, and so has
     * one of the houses that keep it: a bag they could do nothing about is not
     * one to hand them. Where it is coming from is named only if that too is
     * theirs — a thing lent to somewhere that is not is still waiting to come
     * back, and is still on the list, but the house it is at is not named any
     * more than it is anywhere else. Nobody has said where it is at all and
     * that is a list of its own rather than a guess.
     *
     * @return array[] each route naming what it may, what is waiting, and when.
     */
    public function get_packlist( int $user_id ): array {
        $mine = Access::home_ids_for_user( $user_id );
        $routes = [];
        foreach ( $this->things_going() as $thing ) {
            if ( ! array_intersect( $thing['home_ids'], $mine ) ) {
                continue;
            }
            if ( ! in_array( $thing['going']['home_id'], $mine, true ) ) {
                continue;
            }
            // The house the bag is being packed at, which is wherever the
            // thing is: packing it does not move it, so a ticked-off thing
            // stays on the list it was ticked off on until the bag is carried.
            $from = $thing['at']['home_id'];
            $named = $from && in_array( $from, $mine, true );
            $key = $from . ':' . $thing['going']['home_id'];
            if ( ! isset( $routes[ $key ] ) ) {
                $routes[ $key ] = [
                    'from_id'   => $from,
                    'from_name' => $named ? $thing['at']['name'] : '',
                    'to_id'     => $thing['going']['home_id'],
                    'to_name'   => $thing['going']['name'],
                    'things'    => [],
                    // How much of it is in the bag, because a bag with
                    // something in it is one that can be carried.
                    'packed'    => 0,
                    'date'      => '',
                    'when'      => '',
                    'people'    => [],
                ];
            }
            $routes[ $key ]['things'][] = $thing;
            $routes[ $key ]['packed'] += $thing['going']['is_packed'] ? 1 : 0;
        }

        // The first trip along each route, so a list that has a day to be
        // ready by says which one it is.
        $today = current_time( 'Y-m-d' );
        foreach ( $this->agenda_moves( $user_id, $today, self::AGENDA_DAYS ) as $move ) {
            $key = $move['from_id'] . ':' . $move['home_id'];
            if ( ! isset( $routes[ $key ] ) || $routes[ $key ]['date'] ) {
                continue;
            }
            $routes[ $key ]['date'] = $move['date'];
            $routes[ $key ]['when'] = $this->say_date( $move['date'], $today );
            $routes[ $key ]['people'] = $move['people'];
        }

        $routes = array_values( $routes );
        foreach ( $routes as &$route ) {
            // Still to be packed first, and what was ticked off under it, the
            // way a list of things to do reads.
            usort( $route['things'], static function( array $a, array $b ): int {
                if ( $a['going']['is_packed'] !== $b['going']['is_packed'] ) {
                    return $a['going']['is_packed'] ? 1 : -1;
                }
                return strcasecmp( $a['title'], $b['title'] );
            } );
        }
        unset( $route );
        // Soonest first, and the routes nobody is travelling yet after them.
        usort( $routes, static function( array $a, array $b ): int {
            if ( ( '' === $a['date'] ) !== ( '' === $b['date'] ) ) {
                return '' === $a['date'] ? 1 : -1;
            }
            if ( ( '' === $a['from_name'] ) !== ( '' === $b['from_name'] ) ) {
                return '' === $a['from_name'] ? 1 : -1;
            }
            return strcmp( $a['date'], $b['date'] ) ?: strcasecmp( $a['to_name'], $b['to_name'] );
        } );
        return $routes;
    }

    /**
     * How much is still to be packed to go this way, for a line that only
     * counts. What has been ticked off is not what a trip has left to do.
     */
    public function count_going( int $from_home_id, int $to_home_id ): int {
        $waiting = 0;
        foreach ( $this->things_going() as $thing ) {
            if ( $thing['going']['is_packed'] ) {
                continue;
            }
            if ( $thing['at']['home_id'] === $from_home_id && $thing['going']['home_id'] === $to_home_id ) {
                ++$waiting;
            }
        }
        return $waiting;
    }

    /* ---------------------------------------------------------------- Tasks */

    /**
     * How long something ticked off stays on the list before it goes quiet.
     * A tick is not a disappearance: what was ticked stays where it was, struck
     * through, long enough to notice a wrong one and take it back.
     */
    public const DONE_KEPT_DAYS = 7;

    /**
     * The moment before which what was ticked off is no longer kept in view.
     * Said in the site's own time, because that is the time a tick is written
     * down in and the two are compared as they are stored.
     */
    public static function done_cutoff(): string {
        $now = new \DateTimeImmutable( 'now', wp_timezone() );
        return $now->modify( '-' . self::DONE_KEPT_DAYS . ' days' )->format( 'Y-m-d H:i:s' );
    }

    /**
     * A list as it is read: what is open, what was ticked off within the week,
     * and — asked for — everything ticked off before that too. What was left
     * out is counted, because that count is what offers to show it.
     *
     * @param array[] $tasks as `get_tasks` says them.
     * @return array{tasks: array[], quiet: int}
     */
    public static function sift_tasks( array $tasks, bool $earlier = false ): array {
        $cutoff = self::done_cutoff();
        $kept = [];
        $quiet = 0;
        foreach ( $tasks as $task ) {
            if ( $task['is_done'] && $task['done_at'] < $cutoff ) {
                ++$quiet;
                if ( ! $earlier ) {
                    continue;
                }
            }
            $kept[] = $task;
        }
        return [ 'tasks' => $kept, 'quiet' => $quiet ];
    }

    /**
     * A task belongs to a home, and either to one person in it or to the house
     * as a whole. The person is the post's parent, so "everything assigned to
     * her" is one indexed lookup rather than a search.
     */
    public function add_task( int $home_id, string $title, int $person_id = 0, string $task_type = 'task', string $due_date = '' ): int {
        $title = sanitize_text_field( $title );
        if ( '' === trim( $title ) || ! $this->get_home( $home_id ) ) {
            return 0;
        }
        if ( $person_id && ! Access::is_member( $person_id, $home_id ) ) {
            $person_id = 0;
        }
        $task_id = $this->write_post( [
            'post_parent' => $person_id,
            'post_status' => 'private',
            'post_title'  => $title,
            'post_type'   => self::TASK,
        ] );
        if ( ! $task_id ) {
            return 0;
        }
        wp_set_object_terms( $task_id, [ $home_id ], Access::TAXONOMY );
        update_post_meta( $task_id, self::META_TASK_TYPE, in_array( $task_type, [ 'task', 'appointment' ], true ) ? $task_type : 'task' );
        update_post_meta( $task_id, self::META_DUE_DATE, $this->normalize_date( $due_date ) );
        return $task_id;
    }

    /**
     * The same four answers again, about a task already written down. Anything
     * left blank is blank on purpose: the form says all of it every time, so a
     * date taken off is a date taken off rather than a field not mentioned.
     */
    public function edit_task( int $home_id, int $task_id, string $title, int $person_id = 0, string $task_type = 'task', string $due_date = '', int $to_home_id = 0 ): bool {
        $post = get_post( $task_id );
        if ( ! $post || self::TASK !== $post->post_type || ! in_array( $home_id, $this->home_ids_of_post( $task_id ), true ) ) {
            return false;
        }
        $title = sanitize_text_field( $title );
        if ( '' === trim( $title ) ) {
            return false;
        }
        // Which household it is in is one of the answers, so a task written
        // down in the wrong house is put right the same way a misspelt one is.
        // Everything else is then true of the house it has moved to: whoever it
        // is for has to be somebody there, and nobody is who it is for
        // otherwise.
        $moved = $to_home_id && $to_home_id !== $home_id && $this->get_home( $to_home_id ) ? $to_home_id : 0;
        $lives_in = $moved ?: $home_id;
        if ( $person_id && ! Access::is_member( $person_id, $lives_in ) ) {
            $person_id = 0;
        }
        if ( $moved ) {
            wp_set_object_terms( $task_id, [ $moved ], Access::TAXONOMY );
        }
        $this->write_post( [
            'ID'          => $task_id,
            'post_parent' => $person_id,
            'post_title'  => $title,
        ] );
        update_post_meta( $task_id, self::META_TASK_TYPE, in_array( $task_type, [ 'task', 'appointment' ], true ) ? $task_type : 'task' );
        update_post_meta( $task_id, self::META_DUE_DATE, $this->normalize_date( $due_date ) );
        return true;
    }

    /** @return array[] open first, then by due date. */
    public function get_tasks( int $home_id ): array {
        $tasks = [];
        foreach ( $this->posts_in_home( $home_id, self::TASK ) as $task_id ) {
            $post = get_post( $task_id );
            if ( ! $post ) {
                continue;
            }
            $person_id = (int) $post->post_parent;
            $person = $person_id ? get_post( $person_id ) : null;
            $done_at = (string) get_post_meta( $post->ID, self::META_DONE_AT, true );
            $tasks[] = [
                'id'        => (int) $post->ID,
                'title'     => $post->post_title,
                'person_id' => $person_id,
                'person'    => $person ? $person->post_title : '',
                // Who wrote it down and when. A household list is written by
                // several hands, and the answer to "who asked for this?" is
                // worth having without a word of it on the line.
                'added_by'  => $this->who_wrote( (int) $post->post_author ),
                'added_at'  => $post->post_date,
                'task_type' => get_post_meta( $post->ID, self::META_TASK_TYPE, true ) ?: 'task',
                'due_date'  => (string) get_post_meta( $post->ID, self::META_DUE_DATE, true ),
                // When it was ticked off, so a list can keep the recent ones
                // in view and let the rest go quiet.
                'done_at'   => $done_at,
                'is_done'   => '' !== $done_at,
            ];
        }
        usort( $tasks, static function( array $a, array $b ): int {
            if ( $a['is_done'] !== $b['is_done'] ) {
                return (int) $a['is_done'] <=> (int) $b['is_done'];
            }
            if ( $a['is_done'] ) {
                // Among what is done, the thing ticked last: the one still
                // worth a second look.
                return strcmp( $b['done_at'], $a['done_at'] );
            }
            return strcmp( $a['due_date'] ?: '9999-12-31', $b['due_date'] ?: '9999-12-31' );
        } );
        return $tasks;
    }

    /**
     * Write one of ours, keeping what was typed as it was typed.
     *
     * Everything this app stores is plain text: it goes onto the page through
     * esc_html, so nothing in it is markup, and it is made safe on the way in
     * by having any tags taken out of it. WordPress guards a post differently
     * — for anybody without the run of the whole site it turns "&" into
     * "&amp;" as it is stored — which is right for a post that is HTML and
     * wrong for a line somebody typed: they wrote an ampersand and want to
     * read one back, not the name of one. So that guard is held off while ours
     * does the work, and put back exactly where it was found.
     *
     * @return int the post written, or 0.
     */
    private function write_post( array $fields ): int {
        $held = [];
        $guards = [
            'content_save_pre'          => 'wp_filter_post_kses',
            'content_filtered_save_pre' => 'wp_filter_post_kses',
            'excerpt_save_pre'          => 'wp_filter_post_kses',
            'title_save_pre'            => 'wp_filter_kses',
        ];
        foreach ( $guards as $hook => $guard ) {
            $at = has_filter( $hook, $guard );
            if ( false !== $at ) {
                remove_filter( $hook, $guard, $at );
                $held[ $hook ] = [ $guard, $at ];
            }
        }
        $written = empty( $fields['ID'] )
            ? (int) wp_insert_post( $fields )
            : (int) wp_update_post( $fields );
        foreach ( $held as $hook => $guard ) {
            add_filter( $hook, $guard[0], $guard[1] );
        }
        return $written;
    }

    /**
     * The person behind an account, said as the app says people: by the name
     * the household knows them by. An account with nobody's page attached is
     * whoever WordPress says they are, and no account at all is nobody.
     */
    private function who_wrote( int $user_id ): string {
        if ( ! $user_id ) {
            return '';
        }
        $person = get_post( Access::person_for_user( $user_id ) );
        if ( $person ) {
            return $person->post_title;
        }
        $user = get_userdata( $user_id );
        return $user ? $user->display_name : '';
    }

    public function toggle_task( int $home_id, int $task_id ): bool {
        $post = get_post( $task_id );
        if ( ! $post || self::TASK !== $post->post_type || ! in_array( $home_id, $this->home_ids_of_post( $task_id ), true ) ) {
            return false;
        }
        $done = '' !== (string) get_post_meta( $task_id, self::META_DONE_AT, true );
        if ( $done ) {
            delete_post_meta( $task_id, self::META_DONE_AT );
        } else {
            update_post_meta( $task_id, self::META_DONE_AT, current_time( 'mysql' ) );
        }
        return true;
    }

    public function remove_task( int $home_id, int $task_id ): bool {
        $post = get_post( $task_id );
        if ( ! $post || self::TASK !== $post->post_type || ! in_array( $home_id, $this->home_ids_of_post( $task_id ), true ) ) {
            return false;
        }
        wp_trash_post( $task_id );
        return true;
    }

    /* ---------------------------------------------------------------- Dashboard */

    /**
     * Everything one home's page shows, seen by `$user_id`, about `$person_id`.
     *
     * Someone organising sees the whole house. Anyone else sees what is theirs
     * and what belongs to everyone, which is the same rule whether they are a
     * child looking at their own page or a parent viewing it as them.
     */
    public function get_dashboard( int $user_id, int $home_id, int $person_id = 0 ): array {
        $home = $this->get_home( $home_id );
        $viewer_person = Access::person_for_user( $user_id );
        if ( ! $home || ! Access::can_reach( $user_id, $home_id ) ) {
            return [];
        }

        $person_id = $person_id ?: $viewer_person;
        if ( ! Access::is_member( $person_id, $home_id ) ) {
            $person_id = $viewer_person;
        }

        $can_organise = Access::can_organise( $user_id, $home_id );
        $subject_organises = ! Access::is_child( $person_id );

        $tasks = array_values( array_filter( $this->get_tasks( $home_id ), static function( array $task ) use ( $person_id, $subject_organises ): bool {
            return $subject_organises || ! $task['person_id'] || $task['person_id'] === $person_id;
        } ) );

        // Administrators are stored as user IDs; the page talks about people.
        $admins = array_values( array_filter( array_map(
            [ Access::class, 'person_for_user' ],
            Access::admins_of( $home_id )
        ) ) );

        return [
            'home'       => $home,
            'homes'      => $this->get_homes_for_user( $user_id ),
            'people'     => $this->get_people( $home_id ),
            'admins'     => $admins,
            'subject'    => $this->get_person( $person_id ),
            'tasks'      => $tasks,
            'facts'      => $this->get_notes( $home_id, self::FACT ),
            'items'      => $this->get_notes( $home_id, self::ITEM ),
            'on_loan'    => $this->things_on_loan_here( $home_id ),
            'here'       => $this->who_is_here( $home_id ),
            'unknown'    => $this->whereabouts_unknown( $home_id ),
            'birthdays'  => $this->get_upcoming_birthdays( $home_id ),
            'viewer'     => [
                'user_id'      => $user_id,
                'person_id'    => $viewer_person,
                'can_manage'   => Access::can_manage( $user_id, $home_id ),
                'can_organise' => $can_organise,
                'viewing_as'   => $person_id !== $viewer_person,
            ],
        ];
    }

    /* ---------------------------------------------------------------- Your day */

    /** How far ahead the dashboard looks: a fortnight, like the board. */
    private const AGENDA_DAYS = 14;

    /**
     * The index, which is about you rather than about your homes.
     *
     * Where you are today, what is yours to do wherever it happens to be
     * written down, and what the fortnight ahead holds. The appointments, the
     * moves and the birthdays are one dated list rather than three, because a
     * day is one thing to the person living it even when it is spread across
     * three houses.
     */
    public function get_my_day( int $user_id ): array {
        $viewer = Access::person_for_user( $user_id );
        $today = current_time( 'Y-m-d' );
        $where = $this->where_person_is( $viewer, $today );

        return [
            'person' => $this->get_person( $viewer ),
            'today'  => $today,
            'where'  => $where,
            // The household you are standing in, read exactly as its own page
            // reads it: what is written down there, what is kept there, and who
            // is in it. Not knowing where you are, there is nothing to read.
            'here'   => $where['home_id'] ? $this->get_dashboard( $user_id, $where['home_id'] ) : [],
            // Who could go with you, so the page can offer them by name.
            'party'  => $this->people_you_can_place( $user_id ),
            'agenda' => $this->get_agenda( $user_id, $viewer, $today, self::AGENDA_DAYS ),
            'homes'  => $this->get_homes_overview( $user_id ),
        ];
    }

    /**
     * Everyone this viewer may say a day for: themselves, and anyone in a
     * household they organise. Each says where they are today, which is how the
     * page can mark the ones already under the roof being asked about.
     *
     * @return array[] people as `get_people_overview` says them.
     */
    public function people_you_can_place( int $user_id ): array {
        $people = [];
        foreach ( $this->get_people_overview( $user_id ) as $person ) {
            if ( Access::can_place_person( $user_id, $person['id'] ) ) {
                $people[] = $person;
            }
        }
        return $people;
    }

    /**
     * Where a person is today, who is under that roof with them, and — if they
     * rotate — when that stops being true.
     */
    private function where_person_is( int $person_id, string $today ): array {
        $where = $this->location_today( $person_id );
        $stay = Whereabouts::stay_ends( $person_id, $today );
        $next = $stay ? $this->get_home( $stay['next_home_id'] ) : [];
        $said = Whereabouts::home_on( $person_id, $today )['is_override'];

        $with = [];
        foreach ( $where['home_id'] ? $this->who_is_here( $where['home_id'] ) : [] as $person ) {
            if ( $person['id'] !== $person_id ) {
                $with[] = $person['name'];
            }
        }

        return [
            'home_id'     => $where['home_id'],
            'name'        => $where['name'],
            'known'       => $where['known'],
            'rotates'     => (bool) Whereabouts::get_rotation( $person_id ),
            // Whether today is something you said, as opposed to something a
            // pattern worked out or a single home made obvious.
            'said'        => $said,
            'with_you'    => $with,
            'until'       => $stay['until'] ?? '',
            'until_label' => $this->say_date( $stay['until'] ?? '', $today ),
            'next_id'     => $next['id'] ?? 0,
            'next_name'   => $next['name'] ?? '',
        ];
    }

    /**
     * The fortnight ahead as one list: what is booked, who is moving, and
     * whose birthday it is, across every home the viewer belongs to.
     *
     * A day that is coming, not a list of jobs. What is written down to be
     * done is read where it is done, on the household's own list, and the
     * index has that list beside this one; repeating it here made the
     * fortnight mostly chores and buried the three things it is for. An
     * appointment is the exception because it is not a job at all: it is an
     * hour somebody else has already claimed.
     *
     * @return array[] each entry naming its `kind`, dated and said out loud.
     */
    private function get_agenda( int $user_id, int $viewer, string $today, int $days ): array {
        $horizon = ( new \DateTimeImmutable( $today, new \DateTimeZone( 'UTC' ) ) )
            ->modify( '+' . $days . ' days' )->format( 'Y-m-d' );

        $entries = array_merge(
            $this->agenda_appointments( $user_id, $viewer, $today, $horizon ),
            $this->agenda_moves( $user_id, $today, $days ),
            $this->agenda_birthdays( $user_id, $days )
        );

        usort( $entries, static function( array $a, array $b ): int {
            return strcmp( $a['date'], $b['date'] ) ?: strcmp( $a['kind'], $b['kind'] );
        } );

        foreach ( $entries as $index => $entry ) {
            $entries[ $index ]['when'] = $this->say_date( $entry['date'], $today );
        }
        return $entries;
    }

    /**
     * Appointments falling inside the window.
     *
     * Someone organising a home sees everything written down in it; anyone else
     * sees what is theirs and what is the house's, which is the rule the home
     * page itself reads by.
     */
    private function agenda_appointments( int $user_id, int $viewer, string $from, string $until ): array {
        $entries = [];
        foreach ( $this->get_homes_for_user( $user_id ) as $home ) {
            $sees_everything = Access::can_organise( $user_id, $home['id'] );
            foreach ( $this->get_tasks( $home['id'] ) as $task ) {
                if ( $task['is_done'] || 'appointment' !== $task['task_type'] || '' === $task['due_date'] ) {
                    continue;
                }
                if ( $task['due_date'] < $from || $task['due_date'] > $until ) {
                    continue;
                }
                if ( ! $sees_everything && $task['person_id'] && $task['person_id'] !== $viewer ) {
                    continue;
                }
                $entries[] = [
                    'date'      => $task['due_date'],
                    'kind'      => 'appointment',
                    'title'     => $task['title'],
                    'who'       => $task['person'],
                    'home_id'   => $home['id'],
                    'home_name' => $home['name'],
                ];
            }
        }
        return $entries;
    }

    /**
     * The moves the rotations imply, from the viewer's side of them: the ones
     * that arrive at or leave one of their homes, and no others. People moving
     * the same way on the same day are one move, because that is one trip.
     */
    private function agenda_moves( int $user_id, string $today, int $days ): array {
        $mine = Access::home_ids_for_user( $user_id );
        $moves = [];
        $seen = [];
        foreach ( $mine as $home_id ) {
            foreach ( Access::person_ids_in_home( $home_id ) as $person_id ) {
                if ( isset( $seen[ $person_id ] ) ) {
                    continue;
                }
                $seen[ $person_id ] = true;
                $person = $this->get_person( $person_id );
                if ( ! $person ) {
                    continue;
                }
                $previous = null;
                foreach ( Whereabouts::days_for_person( $person_id, $today, $days + 1 ) as $day ) {
                    $moved = null !== $previous && $previous['home_id'] && $day['home_id']
                        && $day['home_id'] !== $previous['home_id'];
                    $concerns_me = $moved
                        && ( in_array( $day['home_id'], $mine, true ) || in_array( $previous['home_id'], $mine, true ) );
                    if ( $concerns_me ) {
                        $key = $day['date'] . ':' . $previous['home_id'] . ':' . $day['home_id'];
                        if ( ! isset( $moves[ $key ] ) ) {
                            $from = $this->get_home( $previous['home_id'] );
                            $to = $this->get_home( $day['home_id'] );
                            $moves[ $key ] = [
                                'date'      => $day['date'],
                                'kind'      => 'move',
                                'title'     => '',
                                'who'       => '',
                                'people'    => [],
                                'from_id'   => $from['id'] ?? 0,
                                'from_name' => $from['name'] ?? '',
                                'home_id'   => $to['id'] ?? 0,
                                'home_name' => $to['name'] ?? '',
                                // What is waiting to go along with them, so
                                // the line that says the trip can say it.
                                'to_pack'   => $this->count_going( $previous['home_id'], $day['home_id'] ),
                            ];
                        }
                        $moves[ $key ]['people'][] = $person['name'];
                    }
                    $previous = $day;
                }
            }
        }
        return array_values( $moves );
    }

    /** Birthdays inside the window, each person once however many homes they are in. */
    private function agenda_birthdays( int $user_id, int $days ): array {
        $entries = [];
        $seen = [];
        foreach ( Access::home_ids_for_user( $user_id ) as $home_id ) {
            foreach ( $this->get_upcoming_birthdays( $home_id ) as $birthday ) {
                if ( isset( $seen[ $birthday['id'] ] ) || $birthday['days_until'] > $days ) {
                    continue;
                }
                $seen[ $birthday['id'] ] = true;
                $entries[] = [
                    'date'      => $birthday['date'],
                    'kind'      => 'birthday',
                    'title'     => $birthday['name'],
                    'who'       => '',
                    'turning'   => $birthday['turning'],
                    'home_id'   => 0,
                    'home_name' => '',
                ];
            }
        }
        return $entries;
    }

    /**
     * A date as the app says it out loud, in a sentence: today, tomorrow, or
     * the weekday and the date.
     */
    public function say_date( string $date, string $today = '' ): string {
        $today = $today ?: current_time( 'Y-m-d' );
        if ( '' === $this->normalize_date( $date ) ) {
            return '';
        }
        $utc = new \DateTimeZone( 'UTC' );
        if ( $date === $today ) {
            return __( 'today', 'households' );
        }
        if ( $date === ( new \DateTimeImmutable( $today, $utc ) )->modify( '+1 day' )->format( 'Y-m-d' ) ) {
            return __( 'tomorrow', 'households' );
        }
        return wp_date( 'D j M', ( new \DateTimeImmutable( $date, $utc ) )->getTimestamp(), $utc );
    }

    /* ---------------------------------------------------------------- Whereabouts */

    /**
     * Where a person is today, as far as this app can honestly say: what they
     * said for today, else what their rotation works out, else the last thing
     * they said, else the single home they belong to. Someone who belongs to
     * several, rotates between none and has never said where they are is not
     * tracked, and saying where they are would be a guess dressed up as an
     * answer — so the app asks them instead.
     *
     * @return array{home_id:int,name:string,known:bool}
     */
    public function location_today( int $person_id ): array {
        $home_id = Whereabouts::home_on( $person_id, current_time( 'Y-m-d' ) )['home_id'];
        $home = $home_id ? $this->get_home( $home_id ) : [];
        return [
            'home_id' => $home_id,
            'name'    => $home['name'] ?? '',
            'known'   => (bool) $home_id,
        ];
    }

    /** @return string 'here', 'away' or 'unknown'. */
    private function presence( int $person_id, int $home_id ): string {
        $location = $this->location_today( $person_id );
        if ( ! $location['known'] ) {
            return 'unknown';
        }
        return $location['home_id'] === $home_id ? 'here' : 'away';
    }

    /** Who is under this roof today. */
    public function who_is_here( int $home_id ): array {
        return $this->people_by_presence( $home_id, 'here' );
    }

    /**
     * Who belongs here but could be anywhere: no rotation, and more than one
     * home to be at. Reported rather than hidden, so an empty roof is
     * distinguishable from one the app cannot account for.
     */
    public function whereabouts_unknown( int $home_id ): array {
        return $this->people_by_presence( $home_id, 'unknown' );
    }

    private function people_by_presence( int $home_id, string $presence ): array {
        $people = [];
        foreach ( $this->get_people( $home_id ) as $person ) {
            if ( $this->presence( $person['id'], $home_id ) !== $presence ) {
                continue;
            }
            $people[] = [
                'id'       => $person['id'],
                'name'     => $person['name'],
                'is_child' => $person['is_child'],
            ];
        }
        return $people;
    }

    /**
     * The board: a day-by-day grid of which home each person is at, and the
     * handovers those days imply.
     *
     * Everything is expressed from `$home_id`'s point of view — `is_here` is
     * what someone standing in this kitchen wants to know.
     */
    public function get_whereabouts_board( int $home_id, string $start = '', int $days = 14 ): array {
        $days = max( 1, min( 56, $days ) );
        $start = $this->normalize_date( $start ) ?: current_time( 'Y-m-d' );
        $today = current_time( 'Y-m-d' );

        $dates = [];
        $cursor = new \DateTimeImmutable( $start, new \DateTimeZone( 'UTC' ) );
        for ( $i = 0; $i < $days; $i++ ) {
            $dates[] = [
                'date'       => $cursor->format( 'Y-m-d' ),
                'weekday'    => wp_date( 'D', $cursor->getTimestamp(), new \DateTimeZone( 'UTC' ) ),
                'day'        => $cursor->format( 'j' ),
                'month'      => wp_date( 'M', $cursor->getTimestamp(), new \DateTimeZone( 'UTC' ) ),
                'is_today'   => $cursor->format( 'Y-m-d' ) === $today,
                'is_weekend' => in_array( (int) $cursor->format( 'N' ), [ 6, 7 ], true ),
            ];
            $cursor = $cursor->modify( '+1 day' );
        }

        // Each person is walked from the day before the window, so a move onto
        // its first day is a handover like any other. Without it, paging
        // forward would hide exactly the arrival that made you page.
        $lead = ( new \DateTimeImmutable( $start, new \DateTimeZone( 'UTC' ) ) )->modify( '-1 day' )->format( 'Y-m-d' );

        $people = [];
        $walks = [];
        foreach ( $this->get_people( $home_id ) as $person ) {
            $rotation = Whereabouts::get_rotation( $person['id'] );
            $days_out = [];
            foreach ( Whereabouts::days_for_person( $person['id'], $lead, $days + 1 ) as $day ) {
                $home = $this->get_home( $day['home_id'] );
                $days_out[] = [
                    'date'        => $day['date'],
                    'home_id'     => $day['home_id'],
                    'home_name'   => $home['name'] ?? '',
                    'is_here'     => $day['home_id'] === $home_id,
                    'is_override' => $day['is_override'],
                    'is_carried'  => $day['is_carried'],
                ];
            }
            $walks[] = [ 'name' => $person['name'], 'days' => $days_out ];
            $days_out = array_slice( $days_out, 1 );
            $people[] = [
                'id'       => $person['id'],
                'name'     => $person['name'],
                'is_child' => $person['is_child'],
                'rotation' => $rotation,
                'can_rotate' => Whereabouts::can_rotate( $person['id'] ),
                'days'     => $days_out,
                'homes'    => $person['homes'],
            ];
        }

        return [
            'home'      => $this->get_home( $home_id ),
            'here'      => $this->who_is_here( $home_id ),
            'unknown'   => $this->whereabouts_unknown( $home_id ),
            'dates'     => $dates,
            'people'    => $people,
            'handovers' => $this->collect_handovers( $walks, $home_id ),
            'patterns'  => $this->format_patterns(),
            'start'     => $start,
            'days'      => $days,
        ];
    }

    /**
     * The handovers the board implies.
     *
     * People moving between the same two homes on the same day are one
     * handover, because that is one trip.
     */
    private function collect_handovers( array $people, int $home_id ): array {
        $handovers = [];
        foreach ( $people as $person ) {
            $previous = null;
            foreach ( $person['days'] as $day ) {
                // A day nobody can account for is not somewhere to travel from
                // or to, so it starts no handover.
                $moved = null !== $previous && $previous['home_id'] && $day['home_id']
                    && $day['home_id'] !== $previous['home_id'];
                if ( $moved ) {
                    $key = $day['date'] . ':' . $previous['home_id'] . ':' . $day['home_id'];
                    if ( ! isset( $handovers[ $key ] ) ) {
                        $handovers[ $key ] = [
                            'date'      => $day['date'],
                            'from_id'   => $previous['home_id'],
                            'from_name' => $previous['home_name'],
                            'to_id'     => $day['home_id'],
                            'to_name'   => $day['home_name'],
                            'direction' => $previous['home_id'] === $home_id ? 'out' : ( $day['home_id'] === $home_id ? 'in' : 'elsewhere' ),
                            'people'    => [],
                            // What is waiting to go along, so a trip can say
                            // there is a bag to fill before it.
                            'to_pack'   => $this->count_going( $previous['home_id'], $day['home_id'] ),
                        ];
                    }
                    $handovers[ $key ]['people'][] = $person['name'];
                }
                $previous = $day;
            }
        }
        usort( $handovers, static function( array $a, array $b ): int {
            return strcmp( $a['date'], $b['date'] );
        } );
        return array_values( $handovers );
    }

    private function format_patterns(): array {
        $patterns = [];
        foreach ( Whereabouts::patterns() as $key => $pattern ) {
            $patterns[] = [
                'key'        => $key,
                'label'      => $pattern['label'],
                'cycle'      => $pattern['cycle'],
                'start_hint' => $pattern['start_hint'],
                'homes'      => $pattern['homes'],
            ];
        }
        return $patterns;
    }

    /* ---------------------------------------------------------------- Helpers */

    /** @return int[] post IDs of this type tagged with this home, oldest first. */
    private function posts_in_home( int $home_id, string $post_type ): array {
        if ( ! $home_id ) {
            return [];
        }
        return array_map( 'intval', get_posts( [
            'fields'           => 'ids',
            'numberposts'      => -1,
            'order'            => 'ASC',
            'orderby'          => 'ID',
            'post_status'      => 'private',
            'post_type'        => $post_type,
            'suppress_filters' => false,
            'tax_query'        => [
                [
                    'field'    => 'term_id',
                    'taxonomy' => Access::TAXONOMY,
                    'terms'    => $home_id,
                ],
            ],
        ] ) );
    }

    /** @return int[] the homes a post is tagged with. */
    private function home_ids_of_post( int $post_id ): array {
        $terms = wp_get_object_terms( $post_id, Access::TAXONOMY, [ 'fields' => 'ids' ] );
        return is_wp_error( $terms ) ? [] : array_map( 'intval', $terms );
    }

    private function normalize_date( string $date ): string {
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : '';
    }
}
