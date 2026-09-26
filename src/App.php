<?php

namespace Households;

use WpApp\WpApp;
use WpApp\BaseApp;

class App extends BaseApp {
    public function __construct() {
        // See https://github.com/akirk/wp-app for documentation.
        $this->app = new WpApp( $this->get_template_dir(), $this->get_url_path(), [
            'require_login'       => true,
            'app_name'            => $this->get_plugin_name(),
            'app_name_textdomain' => 'households',
            'launcher'            => true,
            // A roof in the launcher, in the terracotta the app already uses
            // for its warm accent, so the tile and the pages agree.
            'app_icon'            => 'dashicons-admin-home',
            'app_icon_background' => 'linear-gradient(135deg, #9b5d2f, #d29561)',
            'app_icon_color'      => '#fff',
            'app_icon_shadow'     => true,
            // Owned content: REST reads are gated with the app's capability and
            // OpenStation keeps these menus out of its dock.
            'post_types'          => [ Access::PERSON, Storage::FACT, Storage::ITEM, Storage::TASK ],
            'taxonomies'          => [ Access::TAXONOMY ],
        ] );

        $this->storage = new Storage();

        // People who log in get a WordPress account with this role: enough to
        // reach the app, nothing else. People who never log in get no account.
        add_action( 'init', [ $this, 'register_roles' ] );

        Access::init();

        add_action( 'init', [ $this, 'register_post_types' ] );
        add_action( 'template_redirect', [ $this, 'route_by_home' ] );
        add_filter( 'login_redirect', [ $this, 'redirect_members_to_app' ], 10, 3 );
        // Forms post back to the page they were made on, and are handled
        // before it renders. `route_by_home` has turned away anyone who does
        // not belong here by the time this runs.
        add_action( 'template_redirect', [ $this, 'handle_post' ], 11 );
    }

    public function register_roles(): void {
        $this->app->add_role( 'member', __( 'Household Member', 'households' ), [ 'read' => true ] );
    }

    protected function get_url_path(): string {
        return 'households';
    }

    protected function get_template_dir(): string {
        return dirname( __DIR__ ) . '/templates';
    }

    protected function get_plugin_name(): string {
        if ( ! function_exists( 'get_file_data' ) ) {
            return 'Households';
        }

        $plugin_data = get_file_data( dirname( __DIR__ ) . '/households.php', [ 'name' => 'Plugin Name' ] );

        return $plugin_data['name'] ?: 'Households';
    }

    protected function setup_storage(): void {
        if ( ! $this->storage ) {
            $this->storage = new Storage();
        }
    }

    protected function setup_database(): void {
        $this->setup_storage();
    }

    /**
     * Every home has its own address.
     *
     * The literal routes are registered before `{id}`, and `{id}` only matches
     * digits, so a home can never shadow `homes`, `where` or `person`.
     */
    protected function setup_routes(): void {
        // The index: your day, across every home you belong to.
        $this->app->route( '' );
        // Every home you belong to, and where a new one is started.
        $this->app->route( 'homes', 'homes.php' );
        // Who is at which home, day by day, across the homes you belong to.
        $this->app->route( 'where', 'where.php' );
        // Everything kept across the homes you belong to, and where it is.
        $this->app->route( 'things', 'things.php' );
        // What is waiting to be taken from one home to another, and the trip
        // it is waiting for.
        $this->app->route( 'pack', 'pack.php' );
        // A person, and what travels with them between homes.
        $this->app->route( 'person/{person_id}', 'person.php' );
        // One thing: what it is, where it lives, and which household it is at.
        $this->app->route( 'thing/{note_id}', 'thing.php' );
        // One home: its people, tasks, facts and things.
        $this->app->route( '{id}', 'home.php' );
        // Managing that home: who is in it, who administers it, its name.
        $this->app->route( '{id}/manage', 'manage.php' );
        // That home, seen as one of the people in it.
        $this->app->route( '{id}/as/{person_id}', 'home.php' );
    }

    protected function setup_menu(): void {
        $base = home_url( '/' . $this->get_url_path() . '/' );
        $user_id = get_current_user_id();
        if ( ! $user_id ) {
            $this->app->add_menu_item( 'index', __( 'Overview', 'households' ), $base );
            return;
        }

        $person_id = Access::person_for_user( $user_id );
        $homes = $this->storage->get_homes_for_user( $user_id );
        $this->app->add_menu_item( 'index', __( 'Overview', 'households' ), $base );

        // Each home is a place you go to, not a mode you switch into.
        foreach ( $homes as $home ) {
            $this->app->add_menu_item( 'home-' . $home['id'], $home['name'], $base . $home['id'] . '/' );
        }

        $this->app->add_menu_item( 'homes', __( 'Your households', 'households' ), $base . 'homes/' );
        $this->app->add_menu_item( 'where', __( 'Who is where', 'households' ), $base . 'where/' );
        $this->app->add_menu_item( 'things', __( 'Things', 'households' ), $base . 'things/' );
        $this->app->add_menu_item( 'pack', __( 'What to pack', 'households' ), $base . 'pack/' );

        $open = $this->home_in_view( $user_id );
        if ( $open && Access::can_manage( $user_id, $open ) ) {
            $this->app->add_menu_item( 'manage', __( 'Manage this household', 'households' ), $base . $open . '/manage/' );
            foreach ( $this->storage->get_people( $open ) as $person ) {
                if ( $person['id'] === $person_id ) {
                    continue;
                }
                $this->app->add_menu_item(
                    'view-as-' . $person['id'],
                    /* translators: %s: the name of a person in this household. */
                    sprintf( __( 'View as %s', 'households' ), $person['name'] ),
                    $base . $open . '/as/' . $person['id'] . '/'
                );
            }
        }

        if ( $person_id ) {
            $this->app->add_user_menu_item( 'me', __( 'My page', 'households' ), $base . 'person/' . $person_id . '/' );
        }
    }

    /**
     * The home the current page is about: the one in the URL, else the last one
     * visited. Used for the menu and for the views that span homes.
     */
    public function home_in_view( int $user_id ): int {
        $from_url = (int) get_query_var( 'id' );
        if ( $from_url && Access::can_reach( $user_id, $from_url ) ) {
            return $from_url;
        }
        return $this->storage->last_home_id( $user_id );
    }

    /**
     * Put someone at a household, joining them to it if they are not in it yet.
     *
     * Saying where someone is is the ordinary way a household gains a person:
     * the child who stays at their grandparents every other weekend belongs
     * there from the first weekend they are said to be there. So a move to a
     * household you organise brings them into it rather than being refused,
     * and leaving stays what it always was — something said out loud, on the
     * household's own page.
     */
    private function put_person_at( int $person_id, int $home_id ): bool {
        if ( Access::is_member( $person_id, $home_id ) ) {
            return true;
        }
        if ( ! current_user_can( 'organise_household', $home_id ) ) {
            return false;
        }
        Access::join( $person_id, $home_id );
        return true;
    }

    /**
     * The path within the app for this request, or null if this is not one.
     *
     * The router itself only runs at `template_include`, by which point it is
     * too late to redirect; this reads the same path from the query vars
     * WordPress has already parsed.
     */
    private function app_request_path(): ?string {
        global $wp_query;
        if ( ! $wp_query || ! isset( $wp_query->query_vars['wp_app_request'] ) ) {
            return null;
        }
        if ( get_query_var( 'wp_app_path' ) !== $this->get_url_path() ) {
            return null;
        }
        return trim( (string) get_query_var( 'wp_app_request' ), '/' );
    }

    /**
     * Every home is addressed by its term ID, so a request for one the viewer
     * does not belong to is turned away before anything is rendered.
     */
    public function route_by_home(): void {
        $path = $this->app_request_path();
        if ( null === $path || ! is_user_logged_in() ) {
            return;
        }

        if ( ! preg_match( '#^(\d+)(?:/|$)#', $path, $matches ) ) {
            return;
        }

        $user_id = get_current_user_id();
        $index = home_url( '/' . $this->get_url_path() . '/' );
        $home_id = (int) $matches[1];
        if ( ! Access::can_reach( $user_id, $home_id ) ) {
            wp_safe_redirect( $index );
            exit;
        }

        // Being on a home's page is what "the last home you looked at" means.
        $this->storage->remember_home( $user_id, $home_id );

        // Managing a home, and looking at it as someone else, are both things
        // only its administrators do. Turning them away here means the page
        // never renders controls that every action would refuse anyway.
        if ( preg_match( '#^\d+/(manage|as)(?:/|$)#', $path ) && ! Access::can_manage( $user_id, $home_id ) ) {
            wp_safe_redirect( $index . $home_id . '/' );
            exit;
        }
    }

    /** Members have nothing to do in wp-admin; send them to the app after login. */
    public function redirect_members_to_app( $redirect_to, $requested, $user ) {
        if ( $user instanceof \WP_User && in_array( Storage::WP_ROLE, (array) $user->roles, true ) ) {
            return home_url( '/' . $this->get_url_path() . '/' );
        }
        return $redirect_to;
    }

    /**
     * A home is a term; everything in it is a post tagged with that term.
     *
     * The taxonomy is closed on purpose. Terms are global in a way private
     * posts are not, and a term named after someone's home would otherwise be
     * readable through term archives and the REST namespace.
     */
    public function register_post_types(): void {
        register_taxonomy( Access::TAXONOMY, [ Access::PERSON, Storage::FACT, Storage::ITEM, Storage::TASK ], [
            'labels'            => [
                'name'          => __( 'Households', 'households' ),
                'singular_name' => __( 'Household', 'households' ),
            ],
            'public'            => false,
            'show_ui'           => current_user_can( 'manage_options' ),
            'show_in_rest'      => false,
            // Nothing is nested yet, but a flat taxonomy cannot later hold a
            // flat inside a building without revisiting every query.
            'hierarchical'      => true,
            'show_admin_column' => true,
        ] );

        $post_types = [
            Access::PERSON => [
                'singular' => __( 'Person', 'households' ),
                'plural'   => __( 'People', 'households' ),
                // The editor holds what is true of someone in prose, and
                // revisions are how that reads back as a history.
                'supports' => [ 'title', 'editor', 'revisions', 'author' ],
            ],
            Storage::FACT => [
                'singular' => __( 'Household Fact', 'households' ),
                'plural'   => __( 'Household Facts', 'households' ),
                'supports' => [ 'title', 'editor', 'revisions' ],
            ],
            Storage::ITEM => [
                'singular' => __( 'Item', 'households' ),
                'plural'   => __( 'Items', 'households' ),
                'supports' => [ 'title', 'editor', 'revisions' ],
            ],
            Storage::TASK => [
                'singular' => __( 'Task', 'households' ),
                'plural'   => __( 'Tasks', 'households' ),
                'supports' => [ 'title', 'page-attributes' ],
            ],
        ];

        foreach ( $post_types as $post_type => $labels ) {
            register_post_type( $post_type, [
                'labels'              => [
                    'name'          => $labels['plural'],
                    'singular_name' => $labels['singular'],
                ],
                'public'              => false,
                'show_ui'             => current_user_can( 'manage_options' ),
                'show_in_menu'        => current_user_can( 'manage_options' ),
                'show_in_rest'        => true,
                'capability_type'     => 'post',
                'map_meta_cap'        => true,
                'supports'            => $labels['supports'],
                'exclude_from_search' => true,
            ] );
        }
    }

    /**
     * Every change is a form posting back to the page it was made on.
     *
     * This runs before anything is rendered: it checks the nonce, does the
     * work, and redirects to the same URL, so a refresh repeats nothing and
     * the page that comes back is simply read afresh. What went wrong, if
     * anything did, is named in the URL and said by the page.
     */
    public function handle_post(): void {
        if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '' ) ) {
            return;
        }
        if ( ! is_user_logged_in() || null === $this->app_request_path() ) {
            return;
        }

        $action = isset( $_POST['household_action'] ) ? sanitize_key( wp_unslash( $_POST['household_action'] ) ) : '';
        if ( '' === $action ) {
            return;
        }

        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'households_' . $action ) ) {
            $this->go_back( 'expired' );
        }

        $outcome = $this->perform( $action );
        $this->go_back( $outcome['problem'], $outcome['to'] );
    }

    /** The page the form was on, as it was asked for. */
    private function here(): string {
        return isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
    }

    /** Back where the form was, with anything that went wrong named in the URL. */
    private function go_back( string $problem = '', string $to = '' ): void {
        if ( '' === $to ) {
            $to = remove_query_arg( 'problem', $this->here() );
        }
        if ( '' !== $problem ) {
            $to = add_query_arg( 'problem', $problem, $to );
        }
        wp_safe_redirect( $to );
        exit;
    }

    private function refuse( string $problem = 'not-allowed' ): array {
        return [ 'problem' => $problem, 'to' => '' ];
    }

    private function done( string $to = '' ): array {
        return [ 'problem' => '', 'to' => $to ];
    }

    /**
     * Do what the form asked, and say where the answer lives.
     *
     * The order is the order the questions have to be asked in: the verbs that
     * span every home, or run before you belong to one, are answered first;
     * everything else needs a home before it can be allowed or refused.
     *
     * @return array{problem:string,to:string}
     */
    private function perform( string $action ): array {
        // Nothing here is reached until handle_post() has verified the nonce for
        // this very action, so every read of $_POST below is a read behind that
        // check; PHPCS cannot see across the two methods.
        // phpcs:disable WordPress.Security.NonceVerification.Missing
        $user_id = get_current_user_id();
        $viewer_person = Access::person_for_user( $user_id );

        $post = static function( string $key, string $filter = 'text' ) {
            if ( ! isset( $_POST[ $key ] ) ) {
                return 'int' === $filter ? 0 : '';
            }
            // Unslashed here and sanitized a line later by the filter asked for;
            // 'raw' is the deliberate exception, and what it feeds — a note's
            // prose, a line about where a thing lives — is sanitized by Storage
            // as it is written, which is where the right filter is known.
            $value = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            switch ( $filter ) {
                case 'int':
                    return absint( $value );
                case 'key':
                    return sanitize_key( $value );
                case 'email':
                    return sanitize_email( $value );
                case 'raw':
                    return (string) $value;
                default:
                    return sanitize_text_field( $value );
            }
        };

        if ( 'start_home' === $action ) {
            $started = $this->storage->start_home( $user_id, $post( 'name' ) );
            if ( ! $started ) {
                return $this->refuse( 'no-name' );
            }
            // A home you have just started has nobody in it at all, so the
            // next thing anyone does is say who is.
            return $this->done( home_url( '/' . $this->get_url_path() . '/' . $started . '/manage/' ) );
        }

        // Saying where you are is something anyone may do about themselves,
        // child or not: it is a statement about your own day rather than a
        // change to anybody's arrangement. Others can be named alongside you —
        // whoever is going too, because a parent and the children are one trip
        // — and each of them is a person you organise for. Named nobody, it is
        // about you, which is what the plain buttons post.
        if ( 'say_where' === $action ) {
            if ( ! $viewer_person ) {
                return $this->refuse();
            }
            $said_home = $post( 'said_home_id', 'int' );
            $going = array_map( 'absint', (array) ( isset( $_POST['people'] ) ? wp_unslash( $_POST['people'] ) : [] ) );
            $going = array_values( array_unique( array_filter( $going ) ) ) ?: [ $viewer_person ];

            // Asked of everybody before anybody is moved: half a party arriving
            // because the last name in the list was refused is worse than none.
            // Somebody who has never been to the home is being sent there for
            // the first time, which only whoever organises it may do.
            $may_send = $said_home && current_user_can( 'organise_household', $said_home );
            foreach ( $going as $person_id ) {
                if ( ! Access::can_place_person( $user_id, $person_id ) ) {
                    return $this->refuse();
                }
                if ( $said_home && ! $may_send && ! Access::is_member( $person_id, $said_home ) ) {
                    return $this->refuse();
                }
            }
            $date = $post( 'date' ) ?: current_time( 'Y-m-d' );
            foreach ( $going as $person_id ) {
                if ( $said_home && ! $this->put_person_at( $person_id, $said_home ) ) {
                    return $this->refuse();
                }
                Whereabouts::set_override( $person_id, $date, $said_home );
                Whereabouts::prune_overrides( $person_id );
            }
            return $this->done();
        }

        // Which account is this person's. The account itself is made in
        // WordPress; all that is settled here is who it belongs to, and that is
        // asked of the person rather than of a household, because it is the
        // same fact in every one of them. Taking your own away is refused: it
        // would leave you outside every household you administer with no way
        // back in.
        if ( 'assign_user' === $action ) {
            $person_id = $post( 'person_id', 'int' );
            if ( ! $person_id || $person_id === $viewer_person || ! Access::can_manage_person( $user_id, $person_id ) ) {
                return $this->refuse();
            }
            if ( ! Access::assign_user( $person_id, $post( 'user_id', 'int' ) ) ) {
                return $this->refuse();
            }
            return $this->done();
        }

        // A person is addressed directly rather than through a home. Their
        // name is theirs too, so it is renamed from here: whoever may read
        // this page — themselves, or someone administering a household they
        // are in — is who may put the spelling of it right.
        if ( 'save_person' === $action ) {
            $person_id = $post( 'person_id', 'int' ) ?: $viewer_person;
            if ( ! Access::can_view_person( $user_id, $person_id ) ) {
                return $this->refuse();
            }
            if ( '' === trim( $post( 'name' ) ) ) {
                return $this->refuse( 'no-name' );
            }
            $sizes = [];
            foreach ( array_keys( Storage::size_fields() ) as $size_key ) {
                $sizes[ $size_key ] = $post( 'size_' . $size_key );
            }
            $this->storage->save_person( $person_id, [
                'name'      => $post( 'name' ),
                'about'     => $post( 'about', 'raw' ),
                'birthdate' => $post( 'birthdate' ),
                'sizes'     => $sizes,
            ] );
            return $this->done();
        }

        // Everything else happens in a home. A form that names one must name a
        // home the viewer belongs to — a form naming another is refused rather
        // than quietly answered about a different home. A form that names none
        // is on a page that is about one, or falls back to the last one seen.
        $home_id = $post( 'home_id', 'int' );
        if ( $home_id ) {
            if ( ! Access::can_reach( $user_id, $home_id ) ) {
                return $this->refuse();
            }
        } else {
            $home_id = (int) get_query_var( 'id' );
            if ( ! $home_id || ! Access::can_reach( $user_id, $home_id ) ) {
                $home_id = $this->storage->last_home_id( $user_id );
            }
        }
        if ( ! $home_id ) {
            return $this->refuse( 'no-home' );
        }

        $can_manage = current_user_can( 'manage_household', $home_id );
        $can_organise = current_user_can( 'organise_household', $home_id );

        if ( in_array( $action, [ 'save_rotation', 'clear_rotation', 'set_override' ], true ) ) {
            $person_id = $post( 'person_id', 'int' );
            if ( ! $can_organise || ! $person_id || ! Access::is_member( $person_id, $home_id ) ) {
                return $this->refuse();
            }
            if ( 'save_rotation' === $action ) {
                $homes = array_map( 'absint', (array) ( isset( $_POST['homes'] ) ? wp_unslash( $_POST['homes'] ) : [] ) );
                $cycle = array_map( 'absint', (array) ( isset( $_POST['cycle'] ) ? wp_unslash( $_POST['cycle'] ) : [] ) );
                Whereabouts::save_rotation( $person_id, [
                    'pattern'         => $post( 'pattern', 'key' ),
                    'start_date'      => $post( 'start_date' ),
                    'homes'           => $homes,
                    'changeover_time' => $post( 'changeover_time' ),
                    'cycle'           => $cycle,
                ] );
            } elseif ( 'clear_rotation' === $action ) {
                Whereabouts::clear_rotation( $person_id );
            } else {
                $target = $post( 'override_home_id', 'int' );
                if ( $target && ! $this->put_person_at( $person_id, $target ) ) {
                    return $this->refuse();
                }
                // One day, or that day and the ones after it: the board says
                // how many when it is being tapped to plan a stay rather than
                // swap a day. Either way it is days that are written down, so
                // the pattern is left alone.
                Whereabouts::set_override_run( $person_id, $post( 'date' ), $post( 'onwards', 'int' ) ?: 1, $target );
                Whereabouts::prune_overrides( $person_id );
            }
            return $this->done();
        }

        switch ( $action ) {
            case 'update_home':
                if ( ! $can_manage ) {
                    return $this->refuse();
                }
                $this->storage->update_home( $home_id, $post( 'name' ) );
                break;

            case 'add_person':
                if ( ! $can_manage ) {
                    return $this->refuse();
                }
                $this->storage->add_person( $home_id, $post( 'name' ), [
                    'is_child'  => (bool) $post( 'is_child', 'int' ),
                    'label'     => $post( 'label' ),
                    'birthdate' => $post( 'birthdate' ),
                ] );
                break;

            // A household you set up is one you are outside of until you say
            // you are in it. Your own record joins; only someone who has never
            // needed one gets a new one.
            case 'join_me':
                if ( ! $can_manage ) {
                    return $this->refuse();
                }
                $this->storage->add_self( $user_id, $home_id );
                break;

            case 'update_person':
                if ( ! $can_manage ) {
                    return $this->refuse();
                }
                $person_id = $post( 'person_id', 'int' );
                if ( Access::is_member( $person_id, $home_id ) ) {
                    $this->storage->save_person( $person_id, [
                        'is_child' => (bool) $post( 'is_child', 'int' ),
                        'label'    => $post( 'label' ),
                    ] );
                }
                break;

            case 'remove_person':
                if ( ! $can_manage ) {
                    return $this->refuse();
                }
                $person_id = $post( 'person_id', 'int' );
                // The record survives leaving; only the membership goes.
                if ( $person_id && $person_id !== $viewer_person && Access::is_member( $person_id, $home_id ) ) {
                    Access::leave( $person_id, $home_id );
                }
                break;

            case 'set_admin':
                if ( ! $can_manage ) {
                    return $this->refuse();
                }
                $person_id = $post( 'person_id', 'int' );
                $target_user = Access::user_for_person( $person_id );
                // An administrator may not drop themselves and leave the home
                // with nobody who can manage it.
                if ( $target_user && Access::is_member( $person_id, $home_id ) && $target_user !== $user_id ) {
                    Access::set_admin( $home_id, $target_user, (bool) $post( 'is_admin', 'int' ) );
                }
                break;

            case 'add_task':
                // Which household it is for is one of the answers the form
                // asks, so it can be a house other than the page it is asked
                // on — and writing in that one is what it takes.
                $into = $post( 'to_home_id', 'int' ) ?: $home_id;
                if ( ! $can_organise || ! current_user_can( 'organise_household', $into ) ) {
                    return $this->refuse();
                }
                $this->storage->add_task( $into, $post( 'title' ), $post( 'person_id', 'int' ), $post( 'task_type', 'key' ) ?: 'task', $post( 'due_date' ) );
                break;

            case 'toggle_task':
                $task_id = $post( 'task_id', 'int' );
                // Anyone may tick what they can see; the page they are on is
                // what they can see, so that is what it is checked against.
                $subject_id = self::subject_for_page( $user_id );
                $visible = array_column( $this->storage->get_dashboard( $user_id, $home_id, $subject_id )['tasks'] ?? [], 'id' );
                if ( ! $task_id || ! ( $can_organise || in_array( $task_id, $visible, true ) ) ) {
                    return $this->refuse();
                }
                $this->storage->toggle_task( $home_id, $task_id );
                break;

            case 'edit_task':
                // Writing a task is the same permission as writing it down in
                // the first place, and about the same answers — one of which is
                // which household it is in. Moving it is writing in both: the
                // one it is leaving and the one it is going to.
                $moves_to = $post( 'to_home_id', 'int' ) ?: $home_id;
                if ( ! $can_organise || ! current_user_can( 'organise_household', $moves_to ) ) {
                    return $this->refuse();
                }
                $this->storage->edit_task(
                    $home_id,
                    $post( 'task_id', 'int' ),
                    $post( 'title' ),
                    $post( 'person_id', 'int' ),
                    $post( 'task_type', 'key' ) ?: 'task',
                    $post( 'due_date' ),
                    $moves_to
                );
                break;

            case 'remove_task':
                if ( ! $can_organise ) {
                    return $this->refuse();
                }
                $this->storage->remove_task( $home_id, $post( 'task_id', 'int' ) );
                break;

            case 'add_note':
                if ( ! $can_organise ) {
                    return $this->refuse();
                }
                $this->storage->add_note( $home_id, $this->note_type( $post( 'kind', 'key' ) ), $post( 'title' ), $post( 'detail', 'raw' ) );
                break;

            case 'update_note':
                if ( ! $can_organise ) {
                    return $this->refuse();
                }
                // A form that says nothing about a field leaves it alone, which
                // is how the pages that never ask about it are safe. A thing's
                // page asks about where it lives one household at a time, so it
                // does not ask here.
                $detail = isset( $_POST['detail'] ) ? $post( 'detail', 'raw' ) : null;
                $note = isset( $_POST['note'] ) ? $post( 'note', 'raw' ) : null;
                $this->storage->update_note( $home_id, $this->note_type( $post( 'kind', 'key' ) ), $post( 'note_id', 'int' ), $post( 'title' ), $detail, $note );
                break;

            case 'restore_note':
                if ( ! $can_organise ) {
                    return $this->refuse();
                }
                $this->storage->restore_note( $home_id, $this->note_type( $post( 'kind', 'key' ) ), $post( 'note_id', 'int' ), $post( 'revision_id', 'int' ) );
                break;

            case 'update_movement_date':
            case 'delete_movement':
                if ( ! $can_organise ) {
                    return $this->refuse();
                }
                $changed = 'update_movement_date' === $action
                    ? $this->storage->update_movement_date( $home_id, $post( 'note_id', 'int' ), $post( 'move_id', 'int' ), $post( 'date' ) )
                    : $this->storage->delete_movement( $home_id, $post( 'note_id', 'int' ), $post( 'move_id', 'int' ) );
                if ( ! $changed ) {
                    return $this->refuse();
                }
                break;

            // Kept at one more household, said afresh where it lives at one it
            // is already kept at, given up by one, said to be at one right now,
            // or said to be going to one. The household each is about is the
            // household the form names, so it is that one's permission that is
            // asked for — and the thing has to be one the viewer could open, or
            // any post ID would do to put anything in a house of theirs.
            //
            // Things only: a fact is true of one house, and a house that is not
            // being told anything is not one to add it to. It is said once for the trip
            // A bag that has been carried. It is said once for the trip rather
            // than once for every thing in it, because carrying it is one
            // thing somebody did: everything ticked off is now at the
            // household it was going to, and off the list. What was not packed
            // was not taken, so it stays where it is and stays on the list for
            // the next trip that way. Each thing in it is still one the viewer
            // must be able to open, which is asked of them one by one.
            case 'things_arrived':
                if ( ! $can_organise ) {
                    return $this->refuse();
                }
                $this->storage->things_arrived( $user_id, $post( 'from_home_id', 'int' ), $home_id );
                break;

            // Where a thing lives, asked of every household of yours at once,
            // because it is one answer about one thing rather than a row of
            // separate ones. Each line is still its own household's to write,
            // so each is asked of that household rather than of the one the
            // form happens to name — and a line for a house the viewer does
            // not write in is not a line the form offered them.
            case 'set_note_places':
                $kind = $this->note_type( $post( 'kind', 'key' ) );
                $note_id = $post( 'note_id', 'int' );
                if ( Storage::ITEM !== $kind || ! $this->storage->may_reach_note( $user_id, $note_id, $kind ) ) {
                    return $this->refuse();
                }
                $lines = isset( $_POST['where'] ) ? (array) map_deep( wp_unslash( $_POST['where'] ), 'sanitize_text_field' ) : [];
                foreach ( $lines as $line_home => $line ) {
                    $line_home = absint( $line_home );
                    if ( $line_home && current_user_can( 'organise_household', $line_home ) ) {
                        $this->storage->keep_note_at( $line_home, $kind, $note_id, (string) $line );
                    }
                }
                break;

            case 'keep_note_at':
            case 'drop_note_at':
            case 'note_is_at':
            case 'note_goes_to':
            case 'note_not_going':
            case 'toggle_packed':
                $kind = $this->note_type( $post( 'kind', 'key' ) );
                $note_id = $post( 'note_id', 'int' );
                if ( Storage::ITEM !== $kind || ! $can_organise || ! $this->storage->may_reach_note( $user_id, $note_id, $kind ) ) {
                    return $this->refuse();
                }
                if ( 'keep_note_at' === $action ) {
                    $this->storage->keep_note_at( $home_id, $kind, $note_id, $post( 'where', 'raw' ) );
                    break;
                }
                // Where it has got to is not where it belongs: the house it is
                // said to be at need not be one that keeps it, and no line
                // about where it lives is touched by saying so.
                if ( 'note_is_at' === $action ) {
                    $this->storage->say_note_is_at( $home_id, $kind, $note_id );
                    break;
                }
                // Where it is to get to is not where it is: saying so moves
                // nothing, and what moves it is somebody saying it has got
                // there. Calling it off is asked of the same household as
                // saying it, because it is the same sentence taken back.
                if ( 'note_goes_to' === $action ) {
                    $this->storage->say_note_goes_to( $home_id, $kind, $note_id );
                    // Put back, so the offer to put it back goes with it.
                    return $this->done( remove_query_arg( [ 'problem', 'undo', 'undo_to' ], $this->here() ) );
                }
                // Ticked off the packlist, or the tick taken back. Which of
                // the two it is is what the mark says, not what the form does,
                // so the same box answers for both.
                if ( 'toggle_packed' === $action ) {
                    $this->storage->toggle_packed( $home_id, $kind, $note_id );
                    break;
                }
                if ( 'note_not_going' === $action ) {
                    $this->storage->say_note_is_not_going( $kind, $note_id );
                    // Taken off a list by a cross, which is easy to mean and
                    // easy to miss by. What was taken off is named in the URL
                    // so the page can offer it back, and offering it back is
                    // the same sentence that put it there in the first place.
                    return $this->done( add_query_arg(
                        [ 'undo' => $note_id, 'undo_to' => $home_id ],
                        remove_query_arg( [ 'problem', 'undo', 'undo_to' ], $this->here() )
                    ) );
                }
                $this->storage->drop_note_at( $home_id, $kind, $note_id );
                // Dropped the last household of yours that kept it, and the
                // page you are on is one you can no longer open.
                if ( (int) get_query_var( 'note_id' ) && ! $this->storage->may_reach_note( $user_id, $note_id, $kind ) ) {
                    return $this->done( home_url( '/' . $this->get_url_path() . '/things/' ) );
                }
                break;

            case 'remove_note':
                if ( ! $can_organise ) {
                    return $this->refuse();
                }
                $this->storage->remove_note( $home_id, $this->note_type( $post( 'kind', 'key' ) ), $post( 'note_id', 'int' ) );
                // Removed from its own page, there is no page to go back to.
                if ( (int) get_query_var( 'note_id' ) ) {
                    return $this->done( home_url( '/' . $this->get_url_path() . '/things/' ) );
                }
                break;
        }

        return $this->done();
        // phpcs:enable WordPress.Security.NonceVerification.Missing
    }

    /**
     * Whose view the page is being read as: the person named in the URL, when
     * the viewer is allowed to see their view, and otherwise the viewer
     * themselves. Both the page and the forms on it read it the same way.
     */
    public static function subject_for_page( int $user_id ): int {
        $subject = (int) get_query_var( 'person_id' );
        if ( $subject && Access::can_view_person( $user_id, $subject ) ) {
            return $subject;
        }
        return Access::person_for_user( $user_id );
    }

    /** Facts and things are the same record; the form says which it is about. */
    private function note_type( string $kind ): string {
        return 'item' === $kind ? Storage::ITEM : Storage::FACT;
    }


    public function activate(): void {
        $this->setup_storage();
        $this->register_post_types();
        flush_rewrite_rules();
    }

    public function deactivate(): void {
        flush_rewrite_rules();
    }
}
