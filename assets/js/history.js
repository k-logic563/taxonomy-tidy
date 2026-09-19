( function () {
	'use strict';

	const modal = document.querySelector( '.taxonomy-tidy-history-modal' );
	if ( ! modal ) {
		return;
	}

	const dialog = modal.querySelector( '.taxonomy-tidy-modal__dialog' );
	const closeButtons = modal.querySelectorAll( '.taxonomy-tidy-modal__close, .taxonomy-tidy-modal__cancel' );
	const opener = document.querySelector( '.taxonomy-tidy-undo-preview' );
	const locked = () => Boolean( modal.querySelector( '.taxonomy-tidy-modal__close:disabled' ) );
	const close = () => {
		if ( locked() ) {
			return;
		}
		modal.hidden = true;
		document.body.style.overflow = '';
		opener?.focus();
	};

	modal.hidden = false;
	document.body.style.overflow = 'hidden';
	dialog?.focus();
	closeButtons.forEach( ( button ) => button.addEventListener( 'click', close ) );
	modal.addEventListener( 'click', ( event ) => {
		if ( event.target === modal ) {
			close();
		}
	} );
	document.addEventListener( 'keydown', ( event ) => {
		if ( modal.hidden ) {
			return;
		}
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			close();
		} else if ( event.key === 'Tab' ) {
			const focusable = Array.from( modal.querySelectorAll( 'button:not(:disabled)' ) );
			const first = focusable[ 0 ];
			const last = focusable[ focusable.length - 1 ];
			if ( event.shiftKey && document.activeElement === first ) {
				event.preventDefault();
				last.focus();
			} else if ( ! event.shiftKey && document.activeElement === last ) {
				event.preventDefault();
				first.focus();
			}
		}
	} );
}() );
