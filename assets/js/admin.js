( function () {
	'use strict';

	const form = document.querySelector( '.taxonomy-tidy-planning-form' );
	if ( ! form ) {
		return;
	}

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
			const name = document.createElement( 'strong' );
			const counts = document.createElement( 'span' );
			name.textContent = row.dataset.termName;
			counts.textContent = `${ selectedTerms.dataset.published }: ${ row.dataset.publishedCount } · ${ selectedTerms.dataset.total }: ${ row.dataset.totalCount }`;
			item.append( name, counts );
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
					const name = document.createElement( 'strong' );
					const detail = document.createElement( 'span' );
					name.textContent = row.dataset.termName;
					detail.textContent = `${ deleteTargets.dataset.published }: ${ row.dataset.publishedCount } · ${ deleteTargets.dataset.total }: ${ row.dataset.totalCount } · ${ row.dataset.deletionAvailable === '1' ? deleteTargets.dataset.available : deleteTargets.dataset.unavailable }`;
					item.append( name, detail );
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
}() );
