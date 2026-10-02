( function () {
	'use strict';

	const form = document.querySelector( '.term-steward-planning-form' );
	if ( ! form ) {
		return;
	}
	const panels = Array.from( document.querySelectorAll( '.term-steward-panel' ) );
	panels.forEach( ( panel ) => {
		const summary = panel.querySelector( '.term-steward-panel__summary' );
		const updateExpanded = () => summary?.setAttribute( 'aria-expanded', panel.open ? 'true' : 'false' );
		updateExpanded();
		panel.addEventListener( 'toggle', updateExpanded );
	} );

	const termCheckboxes = Array.from( form.querySelectorAll( '.term-steward-term-select' ) );
	const selectPage = form.querySelector( '.term-steward-select-page' );
	const selectionSummary = form.querySelector( '.term-steward-selection-summary' );
	const selectedCount = form.querySelector( '.term-steward-selected-count' );
	const selectedTerms = form.querySelector( '.term-steward-selected-terms' );
	const currentName = form.querySelector( '.term-steward-current-name' );
	const currentSlug = form.querySelector( '.term-steward-current-slug' );
	const mergeSources = form.querySelector( '.term-steward-merge-sources' );
	const mergeOutcome = form.querySelector( '.term-steward-merge-outcome' );
	const deleteTargets = form.querySelector( '.term-steward-delete-targets' );
	const validation = form.querySelector( '.term-steward-validation' );
	const validationMessages = form.querySelector( '.term-steward-validation-messages' );
	const actionChoices = Array.from( form.querySelectorAll( 'input[name="operation_action"]' ) );
	const actionFields = Array.from( form.querySelectorAll( '[data-action-fields]' ) );
	const changesSection = form.querySelector( '.term-steward-changes-section' );
	const destinationGroup = form.querySelector( '#term-steward-merge-destination-group' );
	const destination = form.querySelector( '#term-steward-destination' );
	const destinationNotice = form.querySelector( '#term-steward-destination-selection-notice' );
	let sourceSignature = null;

	function selectedRows() {
		return termCheckboxes.filter( ( checkbox ) => checkbox.checked ).map( ( checkbox ) => checkbox.closest( 'tr' ) );
	}

	function selectedLabel( count ) {
		return count === 0 ? selectionSummary.dataset.none : selectionSummary.dataset.selected.replace( '%d', String( count ) );
	}

	function renderSelectedTerms( rows ) {
		if ( ! selectedTerms ) {
			return;
		}
		selectedTerms.replaceChildren();
		if ( rows.length === 0 ) {
			if ( selectedTerms.dataset.selectionError === '1' ) {
				return;
			}
			const empty = document.createElement( 'p' );
			empty.className = 'term-steward-selected-empty';
			empty.textContent = selectedTerms.dataset.empty;
			selectedTerms.append( empty );
			return;
		}
		const list = document.createElement( 'ul' );
		list.className = 'term-steward-selected-list';
		rows.slice( 0, 5 ).forEach( ( row ) => {
			const item = document.createElement( 'li' );
			item.textContent = row.dataset.termName;
			list.append( item );
		} );
		if ( rows.length > 5 ) {
			const more = document.createElement( 'li' );
			more.textContent = selectedTerms.dataset.more.replace( '%d', String( rows.length - 5 ) );
			list.append( more );
		}
		selectedTerms.append( list );
	}

	function renderOutcomes( rows ) {
		if ( mergeSources ) {
			mergeSources.textContent = rows.length === 0 ? mergeSources.dataset.empty : rows.slice( 0, 5 ).map( ( row ) => row.dataset.termName ).join( ', ' ) + ( rows.length > 5 ? ` +${ rows.length - 5 }` : '' );
		}
		if ( mergeOutcome ) {
			mergeOutcome.replaceChildren();
			if ( rows.length > 0 ) {
				const heading = document.createElement( 'p' );
				const list = document.createElement( 'ul' );
				heading.className = 'term-steward-field-label';
				heading.textContent = mergeOutcome.dataset.heading;
				list.className = 'term-steward-outcome-list';
				rows.forEach( ( row ) => {
					const item = document.createElement( 'li' );
					const retained = row.dataset.mergeRetained === '1';
					const reasons = [];
					if ( row.dataset.excludedUse === '1' ) {
						reasons.push( validation.dataset.excludedWarning );
					}
					if ( row.dataset.hasChildren === '1' ) {
						reasons.push( validation.dataset.childWarning );
					}
					item.textContent = `${ row.dataset.termName } — ${ retained ? mergeOutcome.dataset.retain : mergeOutcome.dataset.delete }${ reasons.length > 0 ? ` — ${ reasons.join( ' ' ) }` : '' }`;
					list.append( item );
				} );
				mergeOutcome.append( heading, list );
			}
		}
		if ( deleteTargets ) {
			deleteTargets.replaceChildren();
			if ( rows.length === 0 ) {
				const empty = document.createElement( 'p' );
				empty.textContent = deleteTargets.dataset.empty;
				deleteTargets.append( empty );
			} else {
				const list = document.createElement( 'ul' );
				list.className = 'term-steward-delete-list';
				rows.forEach( ( row ) => {
					const item = document.createElement( 'li' );
					item.textContent = row.dataset.termName;
					list.append( item );
				} );
				deleteTargets.append( list );
			}
		}
	}

	function appendValidationMessage( type, heading, message ) {
		const paragraph = document.createElement( 'p' );
		const icon = document.createElement( 'span' );
		const title = document.createElement( 'strong' );
		paragraph.className = `term-steward-message term-steward-message--${ type }`;
		icon.className = `dashicons dashicons-${ type === 'info' ? 'info-outline' : 'warning' }`;
		icon.setAttribute( 'aria-hidden', 'true' );
		title.textContent = `${ heading }:`;
		paragraph.append( icon, title, ` ${ message }` );
		validationMessages.append( paragraph );
	}

	function updateValidation( rows ) {
		if ( ! validation || ! validationMessages || validation.dataset.serverErrors === '1' ) {
			return;
		}
		validationMessages.replaceChildren();
		if ( rows.length === 0 ) {
			validation.hidden = true;
			return;
		}
		validation.hidden = false;
		const selectedAction = actionChoices.find( ( choice ) => choice.checked );
		if ( selectedAction && selectedAction.value === 'merge' ) {
			rows.forEach( ( row ) => {
				if ( row.dataset.excludedUse === '1' ) {
					appendValidationMessage( 'warning', validation.dataset.warning, validation.dataset.excludedWarning );
				}
				if ( row.dataset.hasChildren === '1' ) {
					appendValidationMessage( 'warning', validation.dataset.warning, validation.dataset.childWarning );
				}
			} );
		}
		const published = rows.reduce( ( total, row ) => total + Number( row.dataset.publishedCount ), 0 );
		appendValidationMessage( 'info', validation.dataset.information, validation.dataset.impact.replace( '%d', String( published ) ) );
	}

	function sourceIds( rows = selectedRows() ) {
		return new Set( rows.map( ( row ) => row.dataset.termKey ) );
	}

	function updateDestinationCandidates( rows ) {
		if ( ! destination ) {
			return;
		}
		const currentSourceIds = sourceIds( rows );
		const nextSignature = Array.from( currentSourceIds ).sort().join( ',' );
		const selectionChanged = sourceSignature !== null && sourceSignature !== nextSignature;
		const selectedId = destination.selectedOptions[ 0 ]?.dataset.termKey || '';
		if ( selectedId && currentSourceIds.has( selectedId ) ) {
			destination.value = '';
			if ( destinationNotice ) {
				destinationNotice.textContent = destinationGroup.dataset.cleared;
				destinationNotice.hidden = false;
			}
		} else if ( selectionChanged && destinationNotice ) {
			destinationNotice.hidden = true;
		}
		Array.from( destination.options ).forEach( ( option ) => {
			if ( ! option.dataset.termKey ) {
				return;
			}
			const excluded = currentSourceIds.has( option.dataset.termKey );
			option.disabled = excluded;
			option.hidden = excluded;
		} );
		sourceSignature = nextSignature;
	}

	function updateSelection() {
		const rows = selectedRows();
		const label = selectedLabel( rows.length );
		selectionSummary.textContent = label;
		if ( selectedCount ) {
			selectedCount.textContent = label;
		}
		renderSelectedTerms( rows );
		renderOutcomes( rows );
		updateValidation( rows );
		updateDestinationCandidates( rows );
		if ( currentName ) {
			currentName.textContent = rows.length === 1 ? rows[ 0 ].dataset.termName : currentName.dataset.fallback;
		}
		if ( currentSlug ) {
			currentSlug.textContent = rows.length === 1 ? rows[ 0 ].dataset.termSlug : currentSlug.dataset.fallback;
		}
		if ( selectPage ) {
			selectPage.checked = rows.length > 0 && rows.length === termCheckboxes.length;
			selectPage.indeterminate = rows.length > 0 && rows.length < termCheckboxes.length;
		}
	}

	function updateActionFields() {
		const selectedAction = actionChoices.find( ( choice ) => choice.checked );
		if ( changesSection ) {
			changesSection.hidden = ! selectedAction;
		}
		actionFields.forEach( ( fields ) => {
			fields.hidden = ! selectedAction || fields.dataset.actionFields !== selectedAction.value;
		} );
		updateValidation( selectedRows() );
	}

	destination?.addEventListener( 'change', () => {
		if ( destinationNotice ) {
			destinationNotice.hidden = true;
		}
	} );

	termCheckboxes.forEach( ( checkbox ) => checkbox.addEventListener( 'change', updateSelection ) );
	actionChoices.forEach( ( choice ) => choice.addEventListener( 'change', updateActionFields ) );

	if ( selectPage ) {
		selectPage.addEventListener( 'change', () => {
			termCheckboxes.forEach( ( checkbox ) => {
				checkbox.checked = selectPage.checked;
			} );
			updateSelection();
		} );
	}

	updateSelection();
	updateActionFields();

	const actionableError = form.querySelector( 'input[data-error-focus="true"], select[data-error-focus="true"], textarea[data-error-focus="true"]' );
	const errorFocus = actionableError || form.querySelector( '.term-steward-field-error' );
	if ( errorFocus ) {
		globalThis.requestAnimationFrame( () => errorFocus.focus() );
	}

	let modal = document.querySelector( '.term-steward-modal' );
	let opener = null;
	let busy = false;
	let navigating = false;
	let originalOverflow = '';

	function closeModal() {
		if ( ! modal || busy ) {
			return;
		}
		modal.hidden = true;
		document.body.style.overflow = originalOverflow;
		if ( opener && opener.isConnected ) {
			opener.focus();
		}
	}

	function openModal( trigger ) {
		if ( ! modal ) {
			return;
		}
		opener = trigger;
		originalOverflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';
		modal.hidden = false;
		modal.querySelector( '.term-steward-modal__close' ).focus();
	}

	function installModal( nextModal, trigger ) {
		if ( modal ) {
			modal.remove();
		}
		modal = document.importNode( nextModal, true );
		document.body.append( modal );
		openModal( trigger );
	}

	function showServerErrors( page ) {
		form.querySelectorAll( '.term-steward-field-error' ).forEach( ( error ) => error.remove() );
		const panel = form.querySelector( '.term-steward-process-panel' );
		panel.open = true;
		let first = null;
		page.querySelectorAll( '.term-steward-process-group' ).forEach( ( section ) => {
			const heading = section.querySelector( 'h3[id]' );
			const target = heading && form.querySelector( `#${ heading.id }` )?.closest( '.term-steward-process-group' );
			if ( ! target ) {
				return;
			}
			section.querySelectorAll( '.term-steward-field-error' ).forEach( ( error ) => {
				const copy = document.importNode( error, true );
				target.append( copy );
				first ||= copy;
			} );
		} );
		if ( first ) {
			first.focus();
		}
	}

	form.addEventListener( 'submit', async ( event ) => {
		const submitter = event.submitter;
		if ( ! submitter || ! [ 'execute', 'preview', 'run' ].includes( submitter.value ) ) {
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
		if ( submitter.value === 'run' ) {
			modal.querySelector( '.term-steward-modal__cancel' ).disabled = true;
			modal.querySelector( '.term-steward-modal__close' ).disabled = true;
		}
		try {
			const data = new globalThis.FormData( form );
			data.set( 'plan_command', submitter.value );
			if ( submitter.value === 'run' ) {
				data.set( 'operation_id', modal.querySelector( '[name="operation_id"]' ).value );
			}
			const response = await globalThis.fetch( globalThis.location.href, { method: 'POST', body: data, credentials: 'same-origin' } );
			if ( ! response.ok ) {
				throw new Error( 'request failed' );
			}
			const page = new globalThis.DOMParser().parseFromString( await response.text(), 'text/html' );
			if ( submitter.value === 'run' ) {
				const progress = page.querySelector( '#term-steward-progress-heading' );
				if ( progress ) {
					navigating = true;
					globalThis.location.reload();
					return;
				}
				const error = page.querySelector( '.term-steward-error-summary' );
				if ( error ) {
					modal.querySelector( '.term-steward-modal__body' ).prepend( document.importNode( error, true ) );
				}
				modal.querySelector( '.term-steward-modal__cancel' ).disabled = false;
				modal.querySelector( '.term-steward-modal__close' ).disabled = false;
				return;
			}
			const nextModal = page.querySelector( '.term-steward-modal[data-auto-open="1"]' );
			if ( nextModal ) {
				const savedPlan = form.querySelector( '.term-steward-plan' );
				const nextPlan = page.querySelector( '.term-steward-plan' );
				if ( nextPlan ) {
					if ( savedPlan ) {
						savedPlan.replaceWith( document.importNode( nextPlan, true ) );
					} else {
						form.append( document.importNode( nextPlan, true ) );
					}
				}
				installModal( nextModal, submitter );
			} else {
				showServerErrors( page );
			}
		} catch {
			globalThis.location.reload();
		} finally {
			if ( ! navigating ) {
				busy = false;
				submitter.disabled = false;
				submitter.classList.remove( 'is-loading' );
				submitter.removeAttribute( 'aria-busy' );
			}
		}
	} );

	document.addEventListener( 'click', ( event ) => {
		if ( event.target.closest( '.term-steward-reopen-preview' ) ) {
			openModal( event.target.closest( 'button' ) );
		} else if ( modal && ( event.target === modal || event.target.closest( '.term-steward-modal__cancel, .term-steward-modal__close' ) ) ) {
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
		openModal( form.querySelector( '.term-steward-execute' ) || form.querySelector( '[value="preview"]' ) );
	}
}() );
