/*
 * The list already works without this. The + is a link asking for the page with
 * the form open, whose list it is is another, editing one is a third, ticking
 * something is a form, and writing something down posts and comes back to a
 * page read afresh. All the script does is spare the page going away and coming
 * back: the form is already here, so showing it is an attribute rather than a
 * request, and everything asked for is asked of the same URLs, with the
 * sections the server rendered in reply put back in. Nothing is worked out here
 * that the server has not worked out already.
 */
( function () {
    if ( ! document.querySelector( '[data-hh-live-section]' ) || ! window.fetch || ! window.DOMParser || ! window.FormData ) {
        return;
    }

    /** The live section a form or a link is inside, if it is inside one. */
    function section( node ) {
        return node ? node.closest( '[data-hh-live-section]' ) : null;
    }

    // Which sections come back in: the page says so on the sections themselves,
    // and it is asked again after every exchange because what came back says it
    // too.
    function living() {
        return [].map.call( document.querySelectorAll( '[data-hh-live-section]' ), function ( section ) {
            return section.id;
        } );
    }

    // Said on the document, so the buttons that are only there for a page with
    // no script go quiet — and stay quiet however often a section is exchanged.
    document.documentElement.setAttribute( 'data-hh-live', '' );

    /** Whether the form is the empty one, waiting for something new. */
    function adding( form ) {
        return !! form && 'add' === form.getAttribute( 'data-hh-mode' );
    }

    function swap( html ) {
        // The server has no idea the empty form is open — that is not in the
        // URL and does not belong there — so what came back has it shut, and it
        // is put back the way it was found. A form with a task in it was opened
        // by the URL, so that one comes back on its own, and being handed one
        // is not being handed the other: what is put back has to be the form
        // that was open, or saving a task would leave the empty one showing.
        var form = document.getElementById( 'add' );
        var was = adding( form ) && ! form.hidden;
        var fresh = new DOMParser().parseFromString( html, 'text/html' );
        living().forEach( function ( id ) {
            var here = document.getElementById( id );
            var came = fresh.getElementById( id );
            if ( ! here ) {
                return;
            }
            // A section the server no longer renders is one the page no longer
            // has: the last thing on a list was taken off it, and what is left
            // is not that list with nothing in it.
            if ( came ) {
                here.replaceWith( came );
            } else {
                here.remove();
            }
        } );
        if ( was && adding( document.getElementById( 'add' ) ) ) {
            reveal( true );
        }
    }

    /** Show the form, or hide it, and leave the + saying what it would do next. */
    function reveal( show ) {
        var form = document.getElementById( 'add' );
        var link = document.querySelector( '#hh-todo a[data-hh-add]' );
        if ( ! form || ! link ) {
            return;
        }
        form.hidden = ! show;
        link.textContent = show ? '\u00d7' : '+';
        link.setAttribute( 'aria-expanded', show ? 'true' : 'false' );
        link.href = link.getAttribute( show ? 'data-hh-close' : 'data-hh-open' );
    }

    function load( url, form, busy ) {
        if ( busy ) {
            busy.setAttribute( 'aria-busy', 'true' );
        }
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
                swap( html );
                // A form that says where it posts is a form that closes what it
                // was opened from, so the address bar is told the same thing:
                // the row is saved, and reloading does not open it again.
                if ( form && form.getAttribute( 'action' ) ) {
                    window.history.replaceState( {}, '', url );
                }
                // Written down and gone from the fields: the next one is
                // expected, so the cursor is put back where it was. Ticking
                // something off is not that, and takes no cursor anywhere.
                var again = adding( form )
                    ? document.querySelector( '#hh-todo form#add:not([hidden]) input[name="title"]' )
                    : null;
                if ( again ) {
                    again.focus();
                }
                // A row opened by a link is a row asking to be typed in. The
                // attribute says so for a page that arrives with one already
                // open; a section put back in was never loaded, so it is said
                // again here.
                if ( ! form ) {
                    var typing = document.querySelector( '[data-hh-live-section] form[action]:not([hidden]) input[name="title"]' );
                    if ( typing ) {
                        typing.focus();
                    }
                }
            } )
            // Anything unexpected hands the page back to the browser, which has
            // known how to do this all along.
            .catch( function () {
                if ( form ) {
                    form.submit();
                } else {
                    window.location.href = url;
                }
            } );
    }

    // Listening is done once, from outside what gets exchanged, so no amount of
    // swapping can leave a form posting twice or a link doing nothing.
    document.addEventListener( 'submit', function ( event ) {
        var form = event.target.closest( 'form' );
        var live = section( form );
        if ( ! live ) {
            return;
        }
        event.preventDefault();
        load( form.getAttribute( 'action' ) || window.location.href, form, live );
    } );

    // A box being ticked is the form it is in being posted; the button beside it
    // says the same thing to a page that never ran this.
    document.addEventListener( 'change', function ( event ) {
        var box = event.target.closest( 'input[data-hh-tick]' );
        var live = section( box );
        if ( ! box || ! box.form || ! live ) {
            return;
        }
        load( window.location.href, box.form, live );
    } );

    // Whose list it is, what was done earlier, which row is open: links like any
    // other, and it is only the page around them that need not be fetched again
    // to answer them.
    document.addEventListener( 'click', function ( event ) {
        var link = event.target.closest( 'a[data-hh-live]' );
        var live = section( link );
        if ( ! link || ! live ) {
            return;
        }
        event.preventDefault();
        window.history.replaceState( {}, '', link.href );
        load( link.href, null, live );
    } );

    // Opening the form changes nothing anybody could be sent or reload into, so
    // it changes nothing about the URL either. It is a form being shown.
    document.addEventListener( 'click', function ( event ) {
        var link = event.target.closest( '[data-hh-live-section] a[data-hh-add]' );
        var form = document.getElementById( 'add' );
        if ( ! link || ! form ) {
            return;
        }
        event.preventDefault();
        var opening = form.hidden;
        reveal( opening );
        if ( opening ) {
            var title = form.querySelector( 'input[name="title"]' );
            if ( title ) {
                title.focus();
            }
        }
    } );
}() );
