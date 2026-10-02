( function () {
	'use strict';

	let form = document.querySelector( '#term-steward-board-form' );
	if ( ! form ) {
		return;
	}

	let modal = document.querySelector( '.term-steward-board-modal' );
	let opener = null;
	let openerSelector = '';
	let busy = false;
	let modalOpen = false;
	let originalOverflow = '';

	function selectorForOpener( trigger ) {
		if ( ! trigger ) {
			return '';
		}
		if ( trigger.id ) {
			return `#${ globalThis.CSS.escape( trigger.id ) }`;
		}
		if ( trigger.name && trigger.value ) {
			return `[name="${ globalThis.CSS.escape( trigger.name ) }"][value="${ globalThis.CSS.escape( trigger.value ) }"]`;
		}
		return '';
	}

	function restoreOpenerFocus() {
		const currentOpener = openerSelector ? document.querySelector( openerSelector ) : null;
		const target = currentOpener || ( opener?.isConnected ? opener : null ) || document.querySelector( '#term-steward-board-content h2, .nav-tab[href*="view=plan"]' );
		if ( target ) {
			if ( ! target.matches( 'button, a, input, select, textarea, [tabindex]' ) ) {
				target.setAttribute( 'tabindex', '-1' );
			}
			globalThis.requestAnimationFrame( () => target.focus() );
		}
	}

	function closeModal( force = false, restoreFocus = true ) {
		if ( ! modal || ( ! force && ( busy || modal.querySelector( '.term-steward-modal__close' )?.disabled ) ) ) {
			return;
		}
		modal.hidden = true;
		modal.dataset.autoOpen = '0';
		document.body.style.overflow = originalOverflow;
		modalOpen = false;
		if ( restoreFocus ) {
			restoreOpenerFocus();
		}
		opener = null;
		openerSelector = '';
	}

	function openModal( trigger ) {
		if ( ! modal ) {
			return;
		}
		opener = trigger;
		openerSelector = selectorForOpener( trigger );
		if ( ! modalOpen ) {
			originalOverflow = document.body.style.overflow;
		}
		document.body.style.overflow = 'hidden';
		modal.hidden = false;
		modalOpen = true;
		( modal.querySelector( '.term-steward-modal__close:not(:disabled)' ) || modal.querySelector( '.term-steward-modal__footer button:not(:disabled)' ) )?.focus();
	}

	function replaceBoard( page, trigger ) {
		const nextContent = page.querySelector( '#term-steward-board-content' );
		if ( nextContent ) {
			document.querySelector( '#term-steward-board-content' )?.replaceWith( document.importNode( nextContent, true ) );
			form = document.querySelector( '#term-steward-board-form' );
		}
		const nextTab = page.querySelector( '.nav-tab[href*="view=plan"]' );
		const tab = document.querySelector( '.nav-tab[href*="view=plan"]' );
		if ( nextTab && tab ) {
			tab.innerHTML = nextTab.innerHTML;
			tab.setAttribute( 'aria-label', nextTab.getAttribute( 'aria-label' ) );
		}

		const nextModal = page.querySelector( '.term-steward-board-modal' );
		if ( ! nextModal ) {
			closeModal( true, false );
			modal?.remove();
			modal = null;
			( form?.querySelector( '[value="preview_all"], [value="continue_all"]' ) || tab )?.focus();
			return null;
		}

		const wasOpen = modalOpen;
		const next = document.importNode( nextModal, true );
		if ( modal ) {
			modal.replaceWith( next );
		} else {
			document.querySelector( '.term-steward-screen' )?.append( next );
		}
		modal = next;
		if ( wasOpen ) {
			modal.hidden = false;
			document.body.style.overflow = 'hidden';
		} else {
			openModal( trigger );
		}
		return modal;
	}

	document.addEventListener( 'submit', async ( event ) => {
		if ( event.target.id !== 'term-steward-board-form' ) {
			return;
		}
		form = event.target;
		const submitter = event.submitter;
		if ( submitter?.value === 'discard_all' ) {
			if ( ! globalThis.confirm( submitter.dataset.confirm ) ) {
				event.preventDefault();
				return;
			}
			form.querySelector( '[name="confirmed"]' ).value = '1';
			return;
		}
		if ( ! [ 'preview_all', 'run_all', 'continue_all' ].includes( submitter?.value ) ) {
			return;
		}
		event.preventDefault();
		if ( busy ) {
			return;
		}
		busy = true;
		submitter.disabled = true;
		submitter.classList.add( 'is-loading' );
		submitter.setAttribute( 'aria-busy', 'true' );
		const trigger = submitter.value === 'preview_all' ? submitter : ( opener || submitter );
		try {
			let command = submitter.value;
			do {
				const data = new globalThis.FormData( form );
				data.set( 'plan_command', command );
				modal?.querySelectorAll( '[name^="operation_ids["]' ).forEach( ( field ) => data.set( field.name, field.value ) );
				const response = await globalThis.fetch( globalThis.location.href, { method: 'POST', body: data, credentials: 'same-origin' } );
				if ( ! response.ok ) {
					throw new Error( 'request failed' );
				}
				const page = new globalThis.DOMParser().parseFromString( await response.text(), 'text/html' );
				const error = page.querySelector( '#term-steward-board-content .notice-error' );
				if ( error ) {
					document.querySelector( '#term-steward-board-content .notice-error' )?.remove();
					document.querySelector( '#term-steward-board-content h2' )?.after( document.importNode( error, true ) );
					if ( modal && ! modal.hidden ) {
						modal.querySelector( '.term-steward-modal__body' )?.prepend( document.importNode( error, true ) );
					}
					break;
				}
				const nextModal = replaceBoard( page, trigger );
				if ( nextModal?.dataset.running === '1' ) {
					command = 'continue_all';
					continue;
				}
				break;
			} while ( true );
		} catch {
			globalThis.location.reload();
		} finally {
			busy = false;
			if ( submitter.isConnected ) {
				submitter.disabled = false;
				submitter.classList.remove( 'is-loading' );
				submitter.removeAttribute( 'aria-busy' );
			}
		}
	} );

	document.addEventListener( 'click', ( event ) => {
		if ( event.target.closest( '.term-steward-discard' ) ) {
			return;
		}
		if ( modal && ( event.target === modal || event.target.closest( '.term-steward-modal__cancel, .term-steward-modal__close' ) ) ) {
			closeModal();
		}
	} );

	document.addEventListener( 'keydown', ( event ) => {
		if ( ! modal || modal.hidden || busy ) {
			return;
		}
		if ( event.key === 'Escape' ) {
			event.preventDefault();
			closeModal();
		} else if ( event.key === 'Tab' ) {
			const focusable = Array.from( modal.querySelectorAll( 'button:not(:disabled), summary' ) );
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

	document.addEventListener( 'toggle', async ( event ) => {
		const details = event.target;
		if ( ! details.matches?.( '.term-steward-preview-posts' ) || ! details.open || details.dataset.loaded ) {
			return;
		}
		const data = new globalThis.FormData();
		data.set( 'action', 'term_steward_preview_posts' );
		data.set( 'taxonomy', details.dataset.taxonomy );
		data.set( 'operation_id', details.dataset.operation );
		data.set( 'item_index', details.dataset.item );
		data.set( 'term_steward_nonce', form.querySelector( '[name="term_steward_nonce"]' ).value );
		try {
			const response = await globalThis.fetch( globalThis.ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } );
			const result = await response.json();
			if ( ! result.success ) {
				throw new Error( 'request failed' );
			}
			result.data.titles.forEach( ( title ) => {
				const item = document.createElement( 'li' );
				item.textContent = title;
				details.querySelector( 'ul' ).append( item );
			} );
			details.dataset.loaded = '1';
		} catch {
			const item = document.createElement( 'li' );
			item.textContent = details.dataset.error;
			details.querySelector( 'ul' ).append( item );
		}
	}, true );

	if ( modal?.dataset.autoOpen === '1' ) {
		openModal( form.querySelector( '[value="preview_all"]' ) );
	}

	globalThis.addEventListener( 'pagehide', () => closeModal( true, false ), { once: true } );
}() );
