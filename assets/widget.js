( function () {
	'use strict';

	function postForm( action, patternKey ) {
		const body = new URLSearchParams();
		body.set( 'action', action );
		body.set( '_wpnonce', HabitCreator.nonce );
		body.set( 'pattern_key', patternKey );
		return fetch( HabitCreator.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body,
		} ).then( ( r ) => r.json() );
	}

	document.addEventListener( 'click', function ( event ) {
		const expandBtn = event.target.closest( '.habit-creator-expand' );
		if ( expandBtn ) {
			event.preventDefault();
			const list = expandBtn.parentElement.querySelector( '.habit-creator-list' );
			if ( ! list ) return;
			const open = ! list.hasAttribute( 'hidden' );
			if ( open ) {
				list.setAttribute( 'hidden', '' );
				expandBtn.setAttribute( 'aria-expanded', 'false' );
			} else {
				list.removeAttribute( 'hidden' );
				expandBtn.setAttribute( 'aria-expanded', 'true' );
			}
			return;
		}

		const card = event.target.closest( '.habit-creator-card' );
		if ( ! card ) {
			return;
		}
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
			return;
		}

		if ( event.target.closest( '.habit-creator-dismiss' ) ) {
			event.preventDefault();
			postForm( 'habit_creator_dismiss', key ).then( () => {
				const wrapper = card.closest( 'li' ) || card;
				wrapper.remove();
			} );
		}
	} );
} )();
