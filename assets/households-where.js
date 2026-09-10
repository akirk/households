/*
 * The board already works without this. Every day on it is a form: tapping one
 * posts it, the page redirects to itself, and what comes back is the board read
 * afresh. All the script does is spare the page going away and coming back — it
 * posts the same form to the same URL and puts in the sections the server
 * rendered in reply. Nothing is worked out here that the server has not already
 * worked out, so switching it off changes how it feels and not what it does.
 */
( function () {
    var live = [ 'hh-everyone', 'hh-fortnight', 'hh-handovers' ];
    if ( ! document.getElementById( 'hh-fortnight' ) || ! window.fetch || ! window.DOMParser || ! window.FormData ) {
        return;
    }

    var STILL = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;

    // How long a fortnight takes to go past: long enough to be followed, short
    // enough not to be waited for. `?turn=<milliseconds>` overrides it, which is
    // how another speed is tried without editing anything.
    var TURN = 280;
    var asked = /[?&]turn=(\d{2,5})/.exec( window.location.search );
    if ( asked ) {
        TURN = parseInt( asked[1], 10 );
    }

    function parse( html ) {
        return new DOMParser().parseFromString( html, 'text/html' );
    }

    function swap( fresh ) {
        live.forEach( function ( id ) {
            var here = document.getElementById( id );
            var came = fresh.getElementById( id );
            if ( here && came ) {
                here.replaceWith( came );
            }
        } );
    }

    /*
     * Paging is the days moving past, and nothing else about the board moving
     * at all.
     *
     * Whose row is whose does not change from one fortnight to the next, so the
     * names take no part in it: only the day columns of the fortnight asked for
     * are taken out of what the server sent and hung on the ends of the rows
     * that are already here, out of sight beyond the edge. The board is then
     * twice as wide as its window and the window is scrolled the width of a
     * fortnight — real scrolling, so the names hold their place at the left
     * exactly as they do when you drag the days across by hand.
     *
     * When it comes to rest the fortnight that has gone by is deleted and the
     * days that scrolled into place are left where they are. They are the very
     * cells that arrived, never redrawn, so there is no moment of settling at
     * the end: they were always there, and now the rest of the section says so.
     */

    /** Hang the next fortnight's days on the rows. Returns the width of one fortnight. */
    function reach( table, next, dir ) {
        var here = table.rows;
        var there = next.rows;
        // The rows are the same people in the same order, or this is not the
        // board we asked for and it is put in place rather than scrolled to.
        if ( ! here.length || here.length !== there.length ) {
            return 0;
        }

        // The columns are pinned at the width they are being shown at, or
        // doubling the table would squeeze every day narrower on the way.
        var head = here[0].cells;
        var widths = [];
        var fortnight = 0;
        for ( var c = 0; c < head.length; c++ ) {
            widths.push( head[ c ].getBoundingClientRect().width );
            fortnight += c ? widths[ c ] : 0;
        }
        if ( ! fortnight || here[0].cells.length !== there[0].cells.length ) {
            return 0;
        }
        for ( c = 0; c < head.length; c++ ) {
            head[ c ].style.width = widths[ c ] + 'px';
        }

        for ( var r = 0; r < here.length; r++ ) {
            var row = here[ r ];
            var from = there[ r ].cells;
            var at = dir > 0 ? null : row.cells[ 1 ] || null;
            for ( c = 1; c < from.length; c++ ) {
                var cell = document.importNode( from[ c ], true );
                if ( 0 === r ) {
                    cell.style.width = widths[ c ] + 'px';
                }
                row.insertBefore( cell, at );
            }
        }
        table.style.tableLayout = 'fixed';
        table.style.width = ( widths[0] + fortnight * 2 ) + 'px';

        return fortnight;
    }

    /** Delete the fortnight that has gone by, and let the table be itself again. */
    function shed( table, dir ) {
        for ( var r = 0; r < table.rows.length; r++ ) {
            var row = table.rows[ r ];
            var gone = ( row.cells.length - 1 ) / 2;
            for ( var i = 0; i < gone; i++ ) {
                row.deleteCell( dir > 0 ? 1 : row.cells.length - 1 );
            }
        }
        table.style.tableLayout = '';
        table.style.width = '';
        for ( var c = 0; c < table.rows[0].cells.length; c++ ) {
            table.rows[0].cells[ c ].style.width = '';
        }
    }

    /** Everything around the days, so it says the fortnight the days are showing. */
    function dress( section, fresh ) {
        var came = fresh.getElementById( 'hh-fortnight' );
        var here = section.children;
        var there = came ? came.children : [];
        if ( ! came || here.length !== there.length ) {
            return false;
        }
        for ( var i = here.length - 1; i >= 0; i-- ) {
            if ( here[ i ].classList.contains( 'hh-board' ) ) {
                // The days themselves stay; only the arrows either side of them
                // are exchanged, for the ones pointing at the next fortnights.
                if ( here[ i ].children.length === there[ i ].children.length ) {
                    here[ i ].firstElementChild.replaceWith( there[ i ].firstElementChild );
                    here[ i ].lastElementChild.replaceWith( there[ i ].lastElementChild );
                }
            } else {
                here[ i ].replaceWith( there[ i ] );
            }
        }
        return true;
    }

    function glide( el, from, to ) {
        el.scrollLeft = from;
        if ( STILL ) {
            el.scrollLeft = to;
            return Promise.resolve();
        }
        return new Promise( function ( done ) {
            var started = 0;
            ( function step( now ) {
                started = started || now;
                var run = Math.min( 1, ( now - started ) / TURN );
                el.scrollLeft = from + ( to - from ) * ( 1 - Math.pow( 1 - run, 3 ) );
                if ( run < 1 ) {
                    requestAnimationFrame( step );
                } else {
                    done();
                }
            } )( performance.now() );
        } );
    }

    /** Scroll from this fortnight to the one beside it. False if it could not be done. */
    function turn( section, fresh, dir ) {
        var scroller = section.querySelector( '.hh-scroller' );
        var table = scroller && scroller.querySelector( 'table' );
        var came = fresh.getElementById( 'hh-fortnight' );
        var next = came && came.querySelector( '.hh-scroller table' );
        if ( ! table || ! next ) {
            return null;
        }
        var offset = scroller.scrollLeft;
        // Held still for the length of the turn: a bar that appears because the
        // board is briefly twice as wide, and goes again when it is not, is the
        // one thing on screen that would give away that anything was loaded.
        scroller.style.overflowX = 'hidden';
        var fortnight = reach( table, next, dir );
        if ( ! fortnight ) {
            scroller.style.overflowX = '';
            return null;
        }
        return glide(
            scroller,
            dir > 0 ? offset : offset + fortnight,
            dir > 0 ? offset + fortnight : offset
        ).then( function () {
            shed( table, dir );
            scroller.scrollLeft = offset;
            scroller.style.overflowX = '';
            return dress( section, fresh );
        } );
    }

    function load( url, form, dir, pressed ) {
        // A day being changed dims the board it is on; a fortnight being fetched
        // dims only the arrow that asked for it, because the board itself has
        // not gone anywhere yet and dimming it would be the fade this movement
        // is meant to do without.
        var busy = dir && pressed ? pressed : document.getElementById( 'hh-fortnight' );
        busy.setAttribute( 'aria-busy', 'true' );
        fetch( url, form
            ? { method: 'POST', body: new FormData( form ), credentials: 'same-origin' }
            : { credentials: 'same-origin' } )
            .then( function ( response ) {
                if ( ! response.ok ) {
                    throw new Error( String( response.status ) );
                }
                return response.text();
            } )
            .then( function ( html ) {
                var fresh = parse( html );
                var turning = dir ? turn( document.getElementById( 'hh-fortnight' ), fresh, dir ) : null;
                return Promise.resolve( turning ).then( function ( turned ) {
                    if ( turned ) {
                        // The days are already the new ones; only the lists that
                        // follow from them are left to put in.
                        [ 'hh-everyone', 'hh-handovers' ].forEach( function ( id ) {
                            var was = document.getElementById( id );
                            var now = fresh.getElementById( id );
                            if ( was && now ) {
                                was.replaceWith( now );
                            }
                        } );
                        return;
                    }
                    swap( fresh );
                    busy.removeAttribute( 'aria-busy' );
                } );
            } )
            // Anything unexpected hands the page back to the browser, which
            // has known how to do this all along.
            .catch( function () {
                if ( form ) {
                    form.submit();
                } else {
                    window.location.href = url;
                }
            } );
    }

    // Listening is done once, from outside anything that gets exchanged, so no
    // amount of swapping can leave a form posting twice or a link doing nothing.
    document.addEventListener( 'submit', function ( event ) {
        var form = event.target.closest( '#hh-fortnight form' );
        if ( ! form ) {
            return;
        }
        event.preventDefault();
        load( window.location.href, form, 0, null );
    } );

    document.addEventListener( 'click', function ( event ) {
        var link = event.target.closest( '#hh-fortnight a[data-hh-live]' );
        if ( ! link ) {
            return;
        }
        event.preventDefault();
        // Which fortnight is being looked at, and what a tap on it means, are
        // both kept in the URL, so a reload or a link passed to somebody else
        // still says them.
        window.history.replaceState( {}, '', link.href );
        load( link.href, null, parseInt( link.getAttribute( 'data-hh-page' ) || '0', 10 ), link );
    } );
}() );
