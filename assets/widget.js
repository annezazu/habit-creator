( function () {
	'use strict';

	function postForm( action, patternKey ) {
		const body = new URLSearchParams();
		body.set( 'action', action );
		body.set( '_wpnonce', HabitCreator.nonce );
		body.set( 'pattern_key', patternKey );
		if ( new URLSearchParams( window.location.search ).has( 'habit_creator_mock' ) ) {
			body.set( 'is_mock', '1' );
		}
		return fetch( HabitCreator.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body,
		} ).then( ( r ) => r.json() );
	}

	function rotateSlide( stack ) {
		const slides = Array.from( stack.querySelectorAll( '.habit-creator-slide' ) );
		if ( slides.length < 2 ) return;
		const currentIndex = slides.findIndex( ( s ) => ! s.hasAttribute( 'hidden' ) );
		const nextIndex = ( currentIndex + 1 ) % slides.length;
		slides[ currentIndex ].setAttribute( 'hidden', '' );
		slides[ nextIndex ].removeAttribute( 'hidden' );
	}

	document.addEventListener( 'click', function ( event ) {
		const suggest = event.target.closest( '.habit-creator-suggest' );
		if ( suggest ) {
			event.preventDefault();
			const stack = suggest.closest( '.habit-creator-stack' );
			if ( stack ) rotateSlide( stack );
			return;
		}

		const card = event.target.closest( '.habit-creator-card' );
		if ( ! card ) return;
		const key = card.dataset.patternKey;

		if ( event.target.closest( '.habit-creator-create' ) ) {
			event.preventDefault();
			const btn = event.target.closest( '.habit-creator-create' );
			btn.disabled = true;
			postForm( 'habit_creator_create_draft', key ).then( ( res ) => {
				if ( res && res.success && res.data && res.data.edit_url ) {
					window.location.href = res.data.edit_url;
				} else {
					btn.disabled = false;
					alert( ( res && res.data && res.data.message ) || 'Could not create draft.' );
				}
			} );
		}
	} );
} )();
