( function () {
	'use strict';

	const modal = document.querySelector( '.taxonomy-tidy-history-modal' );
	const strings = globalThis.taxonomyTidyHistory || {};
	let busy = false;
	let terminal = false;
	const maxAttempts = 3;

	function setModalLocked( locked ) {
		busy = locked;
		modal?.querySelectorAll( '.taxonomy-tidy-modal__close, .taxonomy-tidy-modal__cancel, [name="undo_command"]' ).forEach( ( button ) => {
			button.disabled = locked;
			button.classList.toggle( 'is-loading', locked && button.matches( '[name="undo_command"]' ) );
			if ( locked && button.matches( '[name="undo_command"]' ) ) {
				button.setAttribute( 'aria-busy', 'true' );
			} else {
				button.removeAttribute( 'aria-busy' );
			}
		} );
	}

	function closeModal() {
		if ( ! modal || busy ) {
			return;
		}
		modal.hidden = true;
		document.body.style.overflow = '';
		if ( terminal ) {
			globalThis.location.href = globalThis.location.href;
			return;
		}
		document.querySelector( '.taxonomy-tidy-undo-preview' )?.focus();
	}

	function showProgress( progress ) {
		const body = modal?.querySelector( '.taxonomy-tidy-modal__body' );
		if ( ! body ) {
			return;
		}
		let status = body.querySelector( '.taxonomy-tidy-undo-status' );
		let output = body.querySelector( '.taxonomy-tidy-undo-progress' );
		if ( ! status ) {
			body.replaceChildren();
			status = document.createElement( 'p' );
			status.className = 'taxonomy-tidy-undo-status';
			status.append( document.createElement( 'strong' ) );
			output = document.createElement( 'p' );
			output.className = 'taxonomy-tidy-undo-progress';
			body.append( status, output );
		}
		status.querySelector( 'strong' ).textContent = progress.status_label;
		output.textContent = ( strings.progress || '進捗：%1$d / %2$d、成功：%3$d件、失敗：%4$d件、残り：%5$d件' )
			.replace( '%1$d', progress.processed )
			.replace( '%2$d', progress.total )
			.replace( '%3$d', progress.succeeded )
			.replace( '%4$d', progress.failed )
			.replace( '%5$d', progress.remaining );
	}

	function showStopped( message ) {
		const body = modal?.querySelector( '.taxonomy-tidy-modal__body' );
		if ( body ) {
			const notice = document.createElement( 'p' );
			notice.className = 'notice notice-error inline taxonomy-tidy-undo-error';
			notice.setAttribute( 'role', 'alert' );
			notice.textContent = message;
			body.append( notice );
		}
		setModalLocked( false );
	}

	async function requestBatch( form ) {
		const data = new globalThis.FormData( form );
		data.set( 'action', 'taxonomy_tidy_undo_batch' );
		let lastError;
		for ( let attempt = 1; attempt <= maxAttempts; attempt++ ) {
			try {
				const response = await globalThis.fetch( modal.dataset.ajaxUrl || globalThis.ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } );
				let result;
				try {
					result = await response.json();
				} catch {
					if ( response.status >= 500 ) {
						throw new Error( 'temporary response error' );
					}
					throw new globalThis.DOMException( strings.cannotContinue || '取り消し処理を続行できませんでした。', 'DataError' );
				}
				if ( ! response.ok || ! result.success ) {
					throw new globalThis.DOMException( result.data?.message || strings.cannotContinue || '取り消し処理を続行できませんでした。', 'DataError' );
				}
				return result.data;
			} catch ( error ) {
				if ( error?.name === 'DataError' ) {
					throw error;
				}
				lastError = error;
				if ( attempt < maxAttempts ) {
					await new Promise( ( resolve ) => globalThis.setTimeout( resolve, 250 * attempt ) );
				}
			}
		}
		throw lastError;
	}

	async function runUndo( form ) {
		if ( busy || ! form ) {
			return;
		}
		setModalLocked( true );
		try {
			let progress;
			let previousProcessed = -1;
			let requestCount = 0;
			do {
				progress = await requestBatch( form );
				requestCount++;
				showProgress( progress );
				if ( progress.has_more && ( progress.processed <= previousProcessed || progress.processed > progress.total || requestCount > progress.total + 1 ) ) {
					throw new globalThis.DOMException( strings.progressStopped || 'サーバー側の進捗を確認できないため、取り消し処理を中断しました。操作履歴から再開できます。', 'DataError' );
				}
				previousProcessed = progress.processed;
			} while ( progress.has_more && progress.status === 'undoing' );
			terminal = [ 'undone', 'undo_partial_failed', 'failed' ].includes( progress.status );
			setModalLocked( false );
		} catch ( error ) {
			showStopped( error?.name === 'DataError' ? error.message : ( strings.interrupted || '取り消し処理を中断しました。操作履歴から再開できます。' ) );
		}
	}

	if ( modal ) {
		modal.hidden = false;
		document.body.style.overflow = 'hidden';
		modal.querySelector( '.taxonomy-tidy-modal__dialog' )?.focus();
		modal.addEventListener( 'click', ( event ) => {
			if ( event.target === modal || event.target.closest( '.taxonomy-tidy-modal__close, .taxonomy-tidy-modal__cancel' ) ) {
				closeModal();
			}
		} );
		modal.addEventListener( 'submit', ( event ) => {
			if ( event.submitter?.value !== 'run_undo' ) {
				return;
			}
			event.preventDefault();
			event.submitter.disabled = true;
			runUndo( event.target );
		} );
		document.addEventListener( 'keydown', ( event ) => {
			if ( modal.hidden ) {
				return;
			}
			if ( event.key === 'Escape' ) {
				event.preventDefault();
				closeModal();
			} else if ( event.key === 'Tab' ) {
				const focusable = Array.from( modal.querySelectorAll( 'button:not(:disabled)' ) );
				const first = focusable[ 0 ];
				const last = focusable[ focusable.length - 1 ];
				if ( event.shiftKey && document.activeElement === first ) {
					event.preventDefault();
					last?.focus();
				} else if ( ! event.shiftKey && document.activeElement === last ) {
					event.preventDefault();
					first?.focus();
				}
			}
		} );
		if ( modal.dataset.autoContinue === '1' ) {
			runUndo( modal.querySelector( 'form' ) );
		}
	}

	document.querySelectorAll( '.taxonomy-tidy-history-logs' ).forEach( ( section ) => {
		const button = section.querySelector( '.taxonomy-tidy-log-toggle' );
		const list = section.querySelector( '.taxonomy-tidy-change-summary' );
		if ( ! button || ! list ) {
			return;
		}
		const summary = Array.from( list.children, ( item ) => item.cloneNode( true ) );
		let full = [];
		let loaded = false;
		button.addEventListener( 'click', async () => {
			if ( button.getAttribute( 'aria-expanded' ) === 'true' ) {
				list.replaceChildren( ...summary.map( ( item ) => item.cloneNode( true ) ) );
				button.setAttribute( 'aria-expanded', 'false' );
				button.textContent = strings.showDetails || '詳しく見る';
				return;
			}
			button.disabled = true;
			try {
				if ( ! loaded ) {
					const logs = [];
					let page = 1;
					let totalPages = 1;
					do {
						const data = new globalThis.FormData();
						data.set( 'action', 'taxonomy_tidy_history_logs' );
						data.set( 'operation_id', section.dataset.operation );
						data.set( 'log_page', String( page ) );
						data.set( 'taxonomy_tidy_undo_nonce', section.dataset.nonce );
						const response = await globalThis.fetch( globalThis.ajaxurl, { method: 'POST', body: data, credentials: 'same-origin' } );
						const result = await response.json();
						if ( ! response.ok || ! result.success ) {
							throw new Error( 'log request failed' );
						}
						logs.push( ...result.data.items );
						totalPages = result.data.total_pages;
						page++;
					} while ( page <= totalPages );
					full = logs.map( ( log ) => {
						const item = document.createElement( 'li' );
						item.className = `taxonomy-tidy-log taxonomy-tidy-log--${ log.severity }`;
						const state = document.createElement( 'strong' );
						state.textContent = log.severity === 'error' ? ( strings.failure || '失敗：' ) : ( log.severity === 'warning' ? ( strings.warning || '警告：' ) : ( strings.success || '成功：' ) );
						item.append( state, document.createTextNode( ` ${ log.label }（${ log.date }）` ) );
						return item;
					} );
					loaded = true;
				}
				list.replaceChildren( ...full.map( ( item ) => item.cloneNode( true ) ) );
				button.setAttribute( 'aria-expanded', 'true' );
				button.textContent = strings.collapse || '閉じる';
			} catch {
				const error = document.createElement( 'p' );
				error.className = 'notice notice-error inline';
				error.setAttribute( 'role', 'alert' );
				error.textContent = section.dataset.error;
				section.append( error );
			} finally {
				button.disabled = false;
			}
		} );
	} );
}() );
