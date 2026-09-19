( function () {
	'use strict';

	const form = document.querySelector( '.taxonomy-tidy-planning-form' );
	if ( ! form ) {
		return;
	}
	const panels = Array.from( document.querySelectorAll( '.taxonomy-tidy-panel' ) );
	panels.forEach( ( panel ) => {
		const summary = panel.querySelector( '.taxonomy-tidy-panel__summary' );
		const updateExpanded = () => summary?.setAttribute( 'aria-expanded', panel.open ? 'true' : 'false' );
		updateExpanded();
		panel.addEventListener( 'toggle', updateExpanded );
	} );

	const termCheckboxes = Array.from( form.querySelectorAll( '.taxonomy-tidy-term-select' ) );
	const selectPage = form.querySelector( '.taxonomy-tidy-select-page' );
	const selectionSummary = form.querySelector( '.taxonomy-tidy-selection-summary' );
	const selectedCount = form.querySelector( '.taxonomy-tidy-selected-count' );
	const selectedTerms = form.querySelector( '.taxonomy-tidy-selected-terms' );
	const currentName = form.querySelector( '.taxonomy-tidy-current-name' );
	const currentSlug = form.querySelector( '.taxonomy-tidy-current-slug' );
	const mergeSources = form.querySelector( '.taxonomy-tidy-merge-sources' );
	const mergeOutcome = form.querySelector( '.taxonomy-tidy-merge-outcome' );
	const deleteTargets = form.querySelector( '.taxonomy-tidy-delete-targets' );
	const validation = form.querySelector( '.taxonomy-tidy-validation' );
	const validationMessages = form.querySelector( '.taxonomy-tidy-validation-messages' );
	const actionChoices = Array.from( form.querySelectorAll( 'input[name="operation_action"]' ) );
	const actionFields = Array.from( form.querySelectorAll( '[data-action-fields]' ) );
	const changesSection = form.querySelector( '.taxonomy-tidy-changes-section' );
	const destinationGroup = form.querySelector( '#taxonomy-tidy-merge-destination-group' );
	const destination = form.querySelector( '#taxonomy-tidy-destination' );
	const destinationNotice = form.querySelector( '#taxonomy-tidy-destination-selection-notice' );
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
			empty.className = 'taxonomy-tidy-selected-empty';
			empty.textContent = selectedTerms.dataset.empty;
			selectedTerms.append( empty );
			return;
		}
		const list = document.createElement( 'ul' );
		list.className = 'taxonomy-tidy-selected-list';
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
				heading.className = 'taxonomy-tidy-field-label';
				heading.textContent = mergeOutcome.dataset.heading;
				list.className = 'taxonomy-tidy-outcome-list';
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
				list.className = 'taxonomy-tidy-delete-list';
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
		paragraph.className = `taxonomy-tidy-message taxonomy-tidy-message--${ type }`;
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
	const errorFocus = actionableError || form.querySelector( '.taxonomy-tidy-field-error' );
	if ( errorFocus ) {
		globalThis.requestAnimationFrame( () => errorFocus.focus() );
	}

	let modal = document.querySelector( '.taxonomy-tidy-modal' );
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
		modal.querySelector( '.taxonomy-tidy-modal__close' ).focus();
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
		form.querySelectorAll( '.taxonomy-tidy-field-error' ).forEach( ( error ) => error.remove() );
		const panel = form.querySelector( '.taxonomy-tidy-process-panel' );
		panel.open = true;
		let first = null;
		page.querySelectorAll( '.taxonomy-tidy-process-group' ).forEach( ( section ) => {
			const heading = section.querySelector( 'h3[id]' );
			const target = heading && form.querySelector( `#${ heading.id }` )?.closest( '.taxonomy-tidy-process-group' );
			if ( ! target ) {
				return;
			}
			section.querySelectorAll( '.taxonomy-tidy-field-error' ).forEach( ( error ) => {
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
		if ( submitter.value === 'run' ) {
			modal.querySelector( '.taxonomy-tidy-modal__cancel' ).disabled = true;
			modal.querySelector( '.taxonomy-tidy-modal__close' ).disabled = true;
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
				const progress = page.querySelector( '#taxonomy-tidy-progress-heading' );
				if ( progress ) {
					navigating = true;
					globalThis.location.reload();
					return;
				}
				const error = page.querySelector( '.taxonomy-tidy-error-summary' );
				if ( error ) {
					modal.querySelector( '.taxonomy-tidy-modal__body' ).prepend( document.importNode( error, true ) );
				}
				modal.querySelector( '.taxonomy-tidy-modal__cancel' ).disabled = false;
				modal.querySelector( '.taxonomy-tidy-modal__close' ).disabled = false;
				return;
			}
			const nextModal = page.querySelector( '.taxonomy-tidy-modal[data-auto-open="1"]' );
			if ( nextModal ) {
				const savedPlan = form.querySelector( '.taxonomy-tidy-plan' );
				const nextPlan = page.querySelector( '.taxonomy-tidy-plan' );
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
			}
		}
	} );

	document.addEventListener( 'click', ( event ) => {
		if ( event.target.closest( '.taxonomy-tidy-reopen-preview' ) ) {
			openModal( event.target.closest( 'button' ) );
		} else if ( modal && ( event.target === modal || event.target.closest( '.taxonomy-tidy-modal__cancel, .taxonomy-tidy-modal__close' ) ) ) {
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
		if ( ! details.matches?.( '.taxonomy-tidy-preview-posts' ) || ! details.open || details.dataset.loaded ) {
			return;
		}
		const data = new globalThis.FormData();
		data.set( 'action', 'taxonomy_tidy_preview_posts' );
		data.set( 'taxonomy', details.dataset.taxonomy );
		data.set( 'operation_id', details.dataset.operation );
		data.set( 'item_index', details.dataset.item );
		data.set( 'taxonomy_tidy_nonce', form.querySelector( '[name="taxonomy_tidy_nonce"]' ).value );
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
		openModal( form.querySelector( '.taxonomy-tidy-execute' ) || form.querySelector( '[value="preview"]' ) );
	}
}() );
