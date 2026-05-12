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

	function postToggle( enabled ) {
		const body = new URLSearchParams();
		body.set( 'action', 'habit_creator_toggle_ai' );
		body.set( '_wpnonce', HabitCreator.nonce );
		body.set( 'enabled', enabled ? '1' : '0' );
		if ( HabitCreator.isMock ) {
			body.set( 'mock', '1' );
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
		// "Enhance with AI" toggle — switch via track button or its label.
		const toggleLabel = event.target.closest( '.habit-creator-ai-toggle__label' );
		const toggleBtn   = event.target.closest( '.habit-creator-ai-toggle__form-toggle' )
			|| ( toggleLabel
				? toggleLabel.parentElement.querySelector( '.habit-creator-ai-toggle__form-toggle' )
				: null );
		if ( toggleBtn ) {
			event.preventDefault();
			const next     = toggleBtn.getAttribute( 'aria-checked' ) !== 'true';
			const wrap     = toggleBtn.closest( '.habit-creator-ai-toggle' );
			const tooltip  = wrap ? wrap.querySelector( '.habit-creator-ai-toggle__tooltip' ) : null;
			const root     = toggleBtn.closest( '.habit-creator' );
			const bodyWrap = root ? root.querySelector( '.habit-creator-body-wrap' ) : null;

			// Optimistic flip.
			toggleBtn.classList.toggle( 'is-checked', next );
			toggleBtn.setAttribute( 'aria-checked', next ? 'true' : 'false' );
			toggleBtn.classList.add( 'is-saving' );
			if ( tooltip ) {
				tooltip.textContent = next
					? tooltip.dataset.on
					: tooltip.dataset.off;
			}

			postToggle( next ).then( ( res ) => {
				toggleBtn.classList.remove( 'is-saving' );
				if ( ! res || ! res.success ) {
					// Revert on failure.
					toggleBtn.classList.toggle( 'is-checked', ! next );
					toggleBtn.setAttribute( 'aria-checked', next ? 'false' : 'true' );
					if ( tooltip ) {
						tooltip.textContent = next
							? tooltip.dataset.off
							: tooltip.dataset.on;
					}
					return;
				}
				if ( bodyWrap && res.data && typeof res.data.html === 'string' ) {
					bodyWrap.innerHTML = res.data.html;
				}
			} ).catch( () => {
				toggleBtn.classList.remove( 'is-saving' );
			} );
			return;
		}

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
