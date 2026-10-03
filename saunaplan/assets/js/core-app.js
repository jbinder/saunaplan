/* Saunaplan – booking page. No dependencies.
 * Everything room-specific (modes, people categories, extra fields, rating
 * questions, badges) comes from the server as a schema. */
( function () {
	'use strict';

	var cfg = window.Saunaplan || {};
	var t = cfg.i18n || {};
	var root = null;
	var data = null; // Last /state response.
	var dialog = null;
	var toastTimer = null;
	var view = loadPref( 'view', 'calendar' ) === 'list' ? 'list' : 'calendar';
	var weekOffset = 0;

	/* ------------------------------------------------------------ helpers */

	function fmt( s ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var i = 0;
		return String( s ).replace( /%(\d)\$s|%s|%d/g, function ( m, n ) {
			return n ? args[ n - 1 ] : args[ i++ ];
		} );
	}

	/** Tiny DOM builder: el('div', {class: 'x', onclick: fn}, [children | 'text']) */
	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			var val = attrs[ key ];
			if ( val === null || val === undefined || val === false ) {
				return;
			}
			if ( key.indexOf( 'on' ) === 0 ) {
				node.addEventListener( key.slice( 2 ), val );
			} else if ( key === 'text' ) {
				node.textContent = val;
			} else {
				node.setAttribute( key, val === true ? '' : val );
			}
		} );
		( children || [] ).forEach( function ( c ) {
			if ( c === null || c === undefined || c === false ) {
				return;
			}
			node.appendChild( typeof c === 'string' ? document.createTextNode( c ) : c );
		} );
		return node;
	}

	function api( path, body, method ) {
		var options = {
			method: method || ( body ? 'POST' : 'GET' ),
			credentials: 'same-origin',
			headers: { 'X-Saunaplan': '1' },
		};
		var url = cfg.restUrl + path;
		if ( body && options.method === 'GET' ) {
			url += ( url.indexOf( '?' ) > -1 ? '&' : '?' ) + new URLSearchParams( body ).toString();
		} else if ( body ) {
			options.headers[ 'Content-Type' ] = 'application/json';
			options.body = JSON.stringify( body );
		}
		return fetch( url, options ).then( function ( res ) {
			return res.json().catch( function () {
				return {};
			} ).then( function ( json ) {
				if ( ! res.ok ) {
					var err = new Error( json.message || t.genericError );
					err.code = json.code;
					err.status = res.status;
					err.data = json.data || {};
					throw err;
				}
				return json;
			} );
		} );
	}

	function schema() {
		return ( data && data.schema ) || cfg.schema || {};
	}

	function modeOf( key ) {
		var modes = schema().modes || [];
		for ( var i = 0; i < modes.length; i++ ) {
			if ( modes[ i ].key === key ) {
				return modes[ i ];
			}
		}
		return null;
	}

	function categoriesOf( mode ) {
		return ( schema().categories || [] ).filter( function ( c ) {
			return ! mode || mode.categories.indexOf( c.key ) > -1;
		} );
	}

	function countsLabel( counts ) {
		return ( schema().categories || [] )
			.filter( function ( c ) {
				return counts && counts[ c.key ];
			} )
			.map( function ( c ) {
				return counts[ c.key ] + ' ' + c.symbol;
			} )
			.join( ' · ' );
	}

	function dayLabel( day ) {
		if ( day.date === cfg.today ) {
			return t.today + ' · ' + day.label;
		}
		if ( day.date === cfg.tomorrow ) {
			return t.tomorrow + ' · ' + day.label;
		}
		return day.label;
	}

	function findDayLabel( date ) {
		var days = ( data && data.days ) || [];
		for ( var i = 0; i < days.length; i++ ) {
			if ( days[ i ].date === date ) {
				return dayLabel( days[ i ] );
			}
		}
		return date;
	}

	function timeRange( slot ) {
		return slot.time + '–' + slot.end;
	}

	/* Date helpers on ISO strings (YYYY-MM-DD), calculated in UTC so time zones never shift a day. */
	function addDays( iso, n ) {
		var d = new Date( iso + 'T00:00:00Z' );
		d.setUTCDate( d.getUTCDate() + n );
		return d.toISOString().slice( 0, 10 );
	}

	function isoWeekday( iso ) {
		var w = new Date( iso + 'T00:00:00Z' ).getUTCDay();
		return w === 0 ? 7 : w;
	}

	function weekStart( iso ) {
		return addDays( iso, 1 - isoWeekday( iso ) );
	}

	function shortDate( iso ) {
		return fmt( t.dayMonth, String( +iso.slice( 8, 10 ) ), ( cfg.months || [] )[ +iso.slice( 5, 7 ) - 1 ] || '' );
	}

	/* Remembered per device; storage can be blocked (private mode), so never rely on it. */
	function loadPref( key, fallback ) {
		try {
			return window.localStorage.getItem( 'saunaplan-' + key ) || fallback;
		} catch ( e ) {
			return fallback;
		}
	}

	function savePref( key, value ) {
		try {
			window.localStorage.setItem( 'saunaplan-' + key, value );
		} catch ( e ) {}
	}

	function toast( message, isError ) {
		var box = root && root.querySelector( '.sp-toast' );
		if ( ! box || ! message ) {
			return;
		}
		box.textContent = message;
		box.className = 'sp-toast is-visible' + ( isError ? ' is-error' : '' );
		clearTimeout( toastTimer );
		toastTimer = setTimeout( function () {
			box.className = 'sp-toast';
		}, 6000 );
	}

	/* ------------------------------------------------------------- status */

	/** Visual state of a slot: free, open (joinable with room), private, full, blocked. */
	function slotState( slot ) {
		if ( slot.state === 'free' ) {
			return 'free';
		}
		if ( slot.state === 'blocked' ) {
			return 'blocked';
		}
		var mode = modeOf( slot.mode );
		if ( mode && mode.joinable ) {
			return slot.total >= slot.capacity ? 'full' : 'open';
		}
		return 'private';
	}

	function stateText( slot ) {
		var state = slotState( slot );
		if ( state === 'free' ) {
			return t.free;
		}
		var mode = modeOf( slot.mode );
		var text = mode ? mode.short : slot.mode;
		if ( state === 'full' ) {
			text += ' · ' + t.full;
		}
		return text;
	}

	function stateIcon( slot ) {
		if ( slot.state === 'free' ) {
			return '＋';
		}
		var mode = modeOf( slot.mode );
		return ( mode && mode.icon ) || '•';
	}

	function badgesNode( slot ) {
		if ( ! slot.badges || ! slot.badges.length ) {
			return null;
		}
		return el(
			'span',
			{ class: 'sp-badges' },
			slot.badges.map( function ( b ) {
				return el( 'span', { class: 'sp-badge', title: b.label }, [
					el( 'span', { 'aria-hidden': 'true', text: b.icon } ),
					el( 'span', { class: 'sp-badge-label', text: b.label } ),
				] );
			} )
		);
	}

	function peopleNode( slot ) {
		if ( slot.state !== 'booked' ) {
			return null;
		}
		var parts = [];
		var counts = countsLabel( slot.counts );
		var mode = modeOf( slot.mode );
		if ( counts ) {
			parts.push( counts );
		}
		if ( mode && mode.joinable ) {
			parts.push( fmt( t.placesTaken, slot.total, slot.capacity ) );
		}
		if ( slot.waiting ) {
			parts.push( slot.waiting === 1 ? t.oneWaiting : fmt( t.waiting, slot.waiting ) );
		}
		return parts.length ? el( 'span', { class: 'sp-people', text: parts.join( ' · ' ) } ) : null;
	}

	function actionHint( slot ) {
		if ( slot.mine ) {
			return t.yours;
		}
		if ( slot.joined ) {
			return t.joined;
		}
		if ( slot.started ) {
			return t.started;
		}
		if ( slot.onWaitlist ) {
			return t.onWaitlistShort;
		}
		if ( slot.actions.indexOf( 'book' ) > -1 ) {
			return t.actionBook;
		}
		if ( slot.actions.indexOf( 'join' ) > -1 ) {
			return t.actionJoin;
		}
		if ( slot.actions.indexOf( 'wait' ) > -1 ) {
			return t.actionWait;
		}
		return '';
	}

	/* -------------------------------------------------------------- views */

	function render() {
		root.innerHTML = '';
		root.appendChild( el( 'div', { class: 'sp-toast', role: 'status', 'aria-live': 'polite' } ) );
		if ( ! data ) {
			root.appendChild( renderLogin() );
			return;
		}
		root.appendChild( renderTopbar() );
		if ( data.pendingRating ) {
			root.appendChild( renderRatingBanner() );
		}
		root.appendChild( renderMine() );
		root.appendChild( renderDays() );
		root.appendChild( renderFooter() );
	}

	function renderLogin( prefill ) {
		var input = el( 'input', {
			id: 'sp-code',
			class: 'sp-input sp-code-input',
			type: 'text',
			inputmode: 'text',
			autocomplete: 'one-time-code',
			autocapitalize: 'characters',
			spellcheck: 'false',
			maxlength: '12',
			placeholder: 'ABCD-EFGH',
			required: true,
		} );
		input.value = prefill || cfg.prefillCode || '';
		var error = el( 'p', { class: 'sp-error', role: 'alert' } );
		var button = el( 'button', { class: 'sp-btn sp-btn-primary sp-btn-big', type: 'submit', text: t.signIn } );
		var form = el(
			'form',
			{
				class: 'sp-card sp-login',
				onsubmit: function ( e ) {
					e.preventDefault();
					button.disabled = true;
					button.textContent = t.signingIn;
					error.textContent = '';
					api( 'login', { code: input.value } )
						.then( function ( json ) {
							cfg.prefillCode = '';
							data = json;
							render();
							toast( t.welcome );
							openFromUrl();
						} )
						.catch( function ( err ) {
							error.textContent = err.message;
							button.disabled = false;
							button.textContent = t.signIn;
						} );
				},
			},
			[
				el( 'h2', { class: 'sp-h2', text: t.signInTitle } ),
				el( 'p', { text: t.signInText } ),
				el( 'label', { class: 'sp-label', for: 'sp-code', text: t.codeLabel } ),
				input,
				error,
				button,
				el( 'p', { class: 'sp-muted', text: t.lostCode } ),
				el( 'p', { class: 'sp-muted sp-privacy', text: t.privacyNote } ),
			]
		);
		return form;
	}

	function renderTopbar() {
		return el( 'div', { class: 'sp-topbar' }, [
			el( 'span', { class: 'sp-me', text: data.me.unit } ),
			el( 'span', { class: 'sp-topbar-actions' }, [
				el( 'button', { class: 'sp-btn sp-btn-small', type: 'button', onclick: openEmailDialog, text: data.me.email ? t.reminders + ' ✓' : t.reminders } ),
				el( 'button', {
					class: 'sp-btn sp-btn-small',
					type: 'button',
					text: t.signOut,
					onclick: function () {
						if ( window.confirm( t.signOutConfirm ) ) {
							api( 'logout', {} ).then( function () {
								data = null;
								render();
							} );
						}
					},
				} ),
			] ),
		] );
	}

	function renderRatingBanner() {
		return el( 'div', { class: 'sp-card sp-rating-banner' }, [
			el( 'p', {}, [ el( 'strong', { text: t.ratePrompt } ), el( 'br' ), data.pendingRating.label ] ),
			el( 'button', {
				class: 'sp-btn sp-btn-primary',
				type: 'button',
				text: t.rateNow,
				onclick: function () {
					openRatingDialog( { id: data.pendingRating.id, label: data.pendingRating.label } );
				},
			} ),
		] );
	}

	function limitModeNames() {
		return ( schema().modes || [] )
			.filter( function ( m ) {
				return m.countsLimit;
			} )
			.map( function ( m ) {
				return m.label;
			} )
			.join( ', ' );
	}

	function renderUsage() {
		if ( ! data.usage || ! data.usage.length ) {
			return null;
		}
		var names = limitModeNames();
		return el( 'p', { class: 'sp-usage' }, [
			el( 'span', { text: fmt( t.usageLabel, names ) + ' ' } ),
			data.usage
				.map( function ( u ) {
					return fmt( t.usage, u.label, u.used, u.limit );
				} )
				.join( ' · ' ),
		] );
	}

	function renderMine() {
		var section = el( 'section', { class: 'sp-section sp-mine', 'aria-labelledby': 'sp-mine-title' }, [
			el( 'h2', { class: 'sp-h2', id: 'sp-mine-title', text: t.myBookings } ),
		] );
		if ( ! data.mine.length && ! data.waiting.length ) {
			section.appendChild( el( 'p', { class: 'sp-muted', text: t.noBookings } ) );
		}
		data.mine.forEach( function ( slot ) {
			section.appendChild( mineCard( slot ) );
		} );
		data.waiting.forEach( function ( slot ) {
			var free = slot.state === 'free';
			section.appendChild(
				el( 'div', { class: 'sp-card sp-mine-card sp-waiting-card sp-state-' + slotState( slot ) }, [
					el( 'div', { class: 'sp-mine-when' }, [ el( 'strong', { text: findDayLabel( slot.date ) } ), ' ', timeRange( slot ) ] ),
					el( 'div', { class: free ? 'sp-notice' : 'sp-muted', text: free ? t.freeNow : t.onWaitlist } ),
					el( 'div', { class: 'sp-card-actions' }, [ free && slot.actions.indexOf( 'book' ) > -1 ? actionButton( slot, 'book' ) : null, actionButton( slot, 'unwait' ) ] ),
				] )
			);
		} );
		var usage = renderUsage();
		if ( usage ) {
			section.appendChild( usage );
		}
		return section;
	}

	function mineCard( slot ) {
		var mode = modeOf( slot.mode );
		return el( 'div', { class: 'sp-card sp-mine-card sp-state-' + slotState( slot ) }, [
			el( 'div', { class: 'sp-mine-when' }, [ el( 'strong', { text: findDayLabel( slot.date ) } ), ' ', timeRange( slot ) ] ),
			el( 'div', { class: 'sp-mine-what' }, [
				el( 'span', { class: 'sp-chip' }, [ el( 'span', { 'aria-hidden': 'true', text: stateIcon( slot ) + ' ' } ), mode ? mode.label : slot.mode ] ),
				slot.title ? el( 'span', { class: 'sp-title-small', text: slot.title } ) : null,
				badgesNode( slot ),
			] ),
			peopleNode( slot ),
			slot.notes && slot.notes.length
				? el(
						'ul',
						{ class: 'sp-notes' },
						slot.notes.map( function ( n ) {
							return el( 'li', { text: n } );
						} )
				  )
				: null,
			slot.started ? el( 'p', { class: 'sp-muted', text: t.started } ) : el( 'div', { class: 'sp-card-actions' }, slot.actions.map( function ( a ) {
				return actionButton( slot, a );
			} ) ),
		] );
	}

	function renderDays() {
		var section = el( 'section', { class: 'sp-section sp-days', 'aria-labelledby': 'sp-days-title' }, [
			el( 'div', { class: 'sp-days-head' }, [ el( 'h2', { class: 'sp-h2', id: 'sp-days-title', text: t.timetable } ), renderViewSwitch() ] ),
			renderLegend(),
		] );
		if ( ! data.days.length ) {
			section.appendChild( el( 'p', { class: 'sp-muted', text: t.noSlots } ) );
			return section;
		}
		section.appendChild( view === 'calendar' ? renderCalendar() : renderList() );
		return section;
	}

	function renderViewSwitch() {
		return el(
			'div',
			{ class: 'sp-switch', role: 'group', 'aria-label': t.view },
			[ [ 'calendar', t.viewCalendar ], [ 'list', t.viewList ] ].map( function ( item ) {
				return el( 'button', {
					type: 'button',
					class: 'sp-switch-btn',
					'aria-pressed': view === item[ 0 ] ? 'true' : 'false',
					text: item[ 1 ],
					onclick: function () {
						view = item[ 0 ];
						savePref( 'view', view );
						render();
						var again = root.querySelector( '.sp-switch-btn[aria-pressed="true"]' );
						if ( again ) {
							again.focus();
						}
					},
				} );
			} )
		);
	}

	function renderList() {
		var wrap = el( 'div', { class: 'sp-list' } );
		data.days.forEach( function ( day ) {
			wrap.appendChild(
				el( 'div', { class: 'sp-day' }, [
					el( 'h3', { class: 'sp-day-title', text: dayLabel( day ) } ),
					el(
						'ul',
						{ class: 'sp-slots' },
						day.slots.map( function ( slot ) {
							return el( 'li', {}, [ slotRow( slot ) ] );
						} )
					),
				] )
			);
		} );
		return wrap;
	}

	/** Week grid: one column per day (Monday first), one row per start time. */
	function renderCalendar() {
		var days = {};
		data.days.forEach( function ( day ) {
			var slots = {};
			day.slots.forEach( function ( slot ) {
				slots[ slot.time ] = slot;
			} );
			days[ day.date ] = { day: day, slots: slots };
		} );

		var first = weekStart( cfg.today );
		var lastWeek = Math.round( ( Date.parse( weekStart( data.days[ data.days.length - 1 ].date ) ) - Date.parse( first ) ) / 6048e5 );
		weekOffset = Math.max( 0, Math.min( weekOffset, lastWeek ) );
		var start = addDays( first, 7 * weekOffset );
		var dates = [];
		for ( var i = 0; i < 7; i++ ) {
			dates.push( addDays( start, i ) );
		}

		var times = [];
		dates.forEach( function ( date ) {
			Object.keys( ( days[ date ] || { slots: {} } ).slots ).forEach( function ( time ) {
				if ( times.indexOf( time ) < 0 ) {
					times.push( time );
				}
			} );
		} );
		times.sort();

		function go( delta ) {
			weekOffset += delta;
			render();
			var nav = root.querySelector( delta < 0 ? '.sp-week-prev' : '.sp-week-next' );
			if ( nav && ! nav.disabled ) {
				nav.focus();
			} else if ( root.querySelector( '.sp-week-title' ) ) {
				root.querySelector( '.sp-week-title' ).focus();
			}
		}

		var nav = el( 'div', { class: 'sp-week-nav' }, [
			el( 'button', { type: 'button', class: 'sp-btn sp-round sp-week-prev', 'aria-label': t.prevWeek, title: t.prevWeek, disabled: weekOffset === 0, text: '‹', onclick: function () {
				go( -1 );
			} } ),
			el( 'h3', { class: 'sp-week-title', tabindex: '-1', 'aria-live': 'polite', text: shortDate( dates[ 0 ] ) + ' – ' + shortDate( dates[ 6 ] ) } ),
			el( 'button', { type: 'button', class: 'sp-btn sp-round sp-week-next', 'aria-label': t.nextWeek, title: t.nextWeek, disabled: weekOffset >= lastWeek, text: '›', onclick: function () {
				go( 1 );
			} } ),
		] );

		if ( ! times.length ) {
			return el( 'div', { class: 'sp-calendar' }, [ nav, el( 'p', { class: 'sp-muted', text: t.noSlotsWeek } ) ] );
		}

		var lastDate = data.days[ data.days.length - 1 ].date;
		var head = el( 'tr', {}, [ el( 'th', { class: 'sp-cal-corner', 'aria-hidden': 'true' } ) ].concat(
			dates.map( function ( date ) {
				var known = days[ date ];
				return el( 'th', { scope: 'col', class: 'sp-cal-day' + ( date === cfg.today ? ' is-today' : '' ) + ( date < cfg.today ? ' is-past' : '' ), title: known ? dayLabel( known.day ) : '' }, [
					el( 'span', { class: 'sp-cal-wd', text: ( cfg.weekdays || [] )[ isoWeekday( date ) - 1 ] || '' } ),
					el( 'span', { class: 'sp-cal-num', text: String( +date.slice( 8, 10 ) ) } ),
				] );
			} )
		) );

		var rows = times.map( function ( time ) {
			return el( 'tr', {}, [ el( 'th', { scope: 'row', class: 'sp-cal-time', text: time } ) ].concat(
				dates.map( function ( date ) {
					var slot = days[ date ] && days[ date ].slots[ time ];
					if ( ! slot ) {
						var closed = ! days[ date ] && date >= cfg.today && date <= lastDate;
						return el( 'td', { class: 'sp-cal-empty' + ( closed ? ' is-closed' : '' ), title: closed ? t.closed : '' } );
					}
					return el( 'td', {}, [ calendarCell( slot ) ] );
				} )
			) );
		} );

		return el( 'div', { class: 'sp-calendar' }, [
			nav,
			el( 'table', { class: 'sp-cal' }, [ el( 'thead', {}, [ head ] ), el( 'tbody', {}, rows ) ] ),
		] );
	}

	function calendarCell( slot ) {
		var state = slotState( slot );
		var hint = actionHint( slot );
		var label = findDayLabel( slot.date ) + ', ' + timeRange( slot ) + ': ' + stateText( slot ) + ( slot.title ? ' (' + slot.title + ')' : '' ) + ( hint ? ' – ' + hint : '' );
		var classes = 'sp-cal-cell sp-state-' + state + ( slot.mine || slot.joined ? ' is-mine' : '' ) + ( slot.started ? ' is-started' : '' ) + ( slot.onWaitlist ? ' is-waiting' : '' );
		var icon = slot.mine || slot.joined ? '✓' : slot.state === 'free' ? '' : stateIcon( slot );
		return el(
			'button',
			{
				type: 'button',
				class: classes,
				'aria-label': label,
				title: label,
				onclick: function () {
					openSlotDialog( slot );
				},
			},
			[ el( 'span', { 'aria-hidden': 'true', text: icon } ) ]
		);
	}

	function renderLegend() {
		var items = [
			[ 'free', t.legendFree ],
			[ 'open', t.legendOpen ],
			[ 'private', t.legendPrivate ],
		];
		if ( view === 'calendar' ) {
			items.push( [ 'mine', t.legendMine ] );
		}
		return el(
			'ul',
			{ class: 'sp-legend', 'aria-label': t.legend },
			items.map( function ( item ) {
				return el( 'li', { class: 'sp-state-' + item[ 0 ] }, [ el( 'span', { class: 'sp-dot', 'aria-hidden': 'true' } ), item[ 1 ] ] );
			} )
		);
	}

	function slotRow( slot ) {
		var state = slotState( slot );
		var classes = 'sp-slot sp-state-' + state + ( slot.mine || slot.joined ? ' is-mine' : '' ) + ( slot.started ? ' is-started' : '' );
		return el(
			'button',
			{
				type: 'button',
				class: classes,
				onclick: function () {
					openSlotDialog( slot );
				},
			},
			[
				el( 'span', { class: 'sp-slot-time', text: timeRange( slot ) } ),
				el( 'span', { class: 'sp-slot-main' }, [
					el( 'span', { class: 'sp-slot-state' }, [ el( 'span', { class: 'sp-slot-icon', 'aria-hidden': 'true', text: stateIcon( slot ) } ), stateText( slot ) ] ),
					slot.title ? el( 'span', { class: 'sp-title-small', text: slot.title } ) : null,
					badgesNode( slot ),
					peopleNode( slot ),
				] ),
				el( 'span', { class: 'sp-slot-action', text: actionHint( slot ) } ),
			]
		);
	}

	function renderFooter() {
		var s = data.settings;
		var rules = [];
		if ( s.limit ) {
			rules.push( fmt( t.ruleLimit, s.limit, limitModeNames() ) );
		}
		if ( s.consecutive ) {
			rules.push( fmt( t.ruleConsecutive, s.consecutive ) );
		}
		rules.push( fmt( t.ruleHorizon, s.horizon ) );
		rules.push( fmt( t.ruleCapacity, s.capacity ) );
		return el( 'footer', { class: 'sp-footer' }, [
			el( 'p', { class: 'sp-muted', text: rules.join( ' · ' ) } ),
			el( 'p', { class: 'sp-muted sp-privacy', text: t.privacyNote } ),
		] );
	}

	/* ------------------------------------------------------------ dialogs */

	function openDialog( title, body ) {
		closeDialog();
		var heading = el( 'h2', { class: 'sp-dialog-title', id: 'sp-dialog-title', text: title } );
		var close = el( 'button', { class: 'sp-dialog-close', type: 'button', 'aria-label': t.close, text: '×', onclick: closeDialog } );
		dialog = el( 'dialog', { class: 'sp-dialog saunaplan', 'aria-labelledby': 'sp-dialog-title' }, [
			el( 'div', { class: 'sp-dialog-head' }, [ heading, close ] ),
			el( 'div', { class: 'sp-dialog-body' }, [ body ] ),
		] );
		dialog.addEventListener( 'close', function () {
			if ( dialog && dialog.parentNode ) {
				dialog.parentNode.removeChild( dialog );
			}
			dialog = null;
		} );
		dialog.addEventListener( 'click', function ( e ) {
			if ( e.target === dialog ) {
				closeDialog();
			}
		} );
		document.body.appendChild( dialog );
		if ( dialog.showModal ) {
			dialog.showModal();
		} else {
			dialog.setAttribute( 'open', '' );
		}
		return dialog;
	}

	function closeDialog() {
		if ( dialog ) {
			if ( dialog.close ) {
				dialog.close();
			} else if ( dialog.parentNode ) {
				dialog.parentNode.removeChild( dialog );
				dialog = null;
			}
		}
	}

	/** Run an API call from a dialog: disable the button, show errors inline, refresh on success. */
	function submit( button, error, path, body, onSuccess ) {
		var label = button.textContent;
		button.disabled = true;
		button.textContent = t.sending;
		error.textContent = '';
		return api( path, body )
			.then( function ( json ) {
				if ( json.days ) {
					data = json;
				}
				closeDialog();
				render();
				toast( json.message );
				if ( onSuccess ) {
					onSuccess( json );
				}
			} )
			.catch( function ( err ) {
				button.disabled = false;
				button.textContent = label;
				if ( err.status === 401 ) {
					data = null;
					closeDialog();
					render();
					return;
				}
				if ( err.code === 'saunaplan_rating_required' && err.data.booking_id ) {
					var retry = function () {
						api( path, body )
							.then( function ( json ) {
								data = json;
								render();
								toast( json.message );
							} )
							.catch( function ( e2 ) {
								toast( e2.message, true );
							} );
					};
					openRatingDialog( { id: err.data.booking_id, label: data.pendingRating ? data.pendingRating.label : '' }, retry, err.message );
					return;
				}
				error.textContent = err.message;
			} );
	}

	function stepper( category, value, max, onChange ) {
		var count = el( 'output', { class: 'sp-stepper-value', text: String( value ) } );
		function set( v ) {
			value = Math.max( 0, Math.min( max, v ) );
			count.textContent = String( value );
			onChange( value );
		}
		return el( 'div', { class: 'sp-stepper' }, [
			el( 'span', { class: 'sp-stepper-label' }, [ el( 'span', { class: 'sp-symbol', 'aria-hidden': 'true', text: category.symbol } ), category.label ] ),
			el( 'span', { class: 'sp-stepper-controls' }, [
				el( 'button', {
					type: 'button',
					class: 'sp-btn sp-round',
					'aria-label': fmt( t.less, category.label ),
					text: '−',
					onclick: function () {
						set( value - 1 );
					},
				} ),
				count,
				el( 'button', {
					type: 'button',
					class: 'sp-btn sp-round',
					'aria-label': fmt( t.more, category.label ),
					text: '+',
					onclick: function () {
						set( value + 1 );
					},
				} ),
			] ),
		] );
	}

	/** People steppers for the categories a mode allows. Returns {node, counts}. */
	function peopleInput( mode, max, initial ) {
		var counts = {};
		var cats = categoriesOf( mode );
		var box = el( 'div', { class: 'sp-steppers' } );
		cats.forEach( function ( c ) {
			counts[ c.key ] = ( initial && initial[ c.key ] ) || 0;
			box.appendChild(
				stepper( c, counts[ c.key ], max, function ( v ) {
					counts[ c.key ] = v;
				} )
			);
		} );
		// One category and nothing chosen yet: start with one person.
		if ( cats.length === 1 && ! counts[ cats[ 0 ].key ] ) {
			counts[ cats[ 0 ].key ] = 1;
			box.innerHTML = '';
			box.appendChild(
				stepper( cats[ 0 ], 1, max, function ( v ) {
					counts[ cats[ 0 ].key ] = v;
				} )
			);
		}
		return { node: box, counts: counts };
	}

	function slotSummary( slot ) {
		var mode = modeOf( slot.mode );
		return el( 'div', { class: 'sp-summary sp-state-' + slotState( slot ) }, [
			el( 'p', { class: 'sp-summary-when' }, [ el( 'strong', { text: findDayLabel( slot.date ) } ), el( 'br' ), timeRange( slot ) ] ),
			slot.state !== 'free'
				? el( 'p', { class: 'sp-summary-mode' }, [
						el( 'span', { class: 'sp-chip' }, [ el( 'span', { 'aria-hidden': 'true', text: stateIcon( slot ) + ' ' } ), mode ? mode.label : slot.mode ] ),
						slot.title ? el( 'span', { class: 'sp-title-small', text: slot.title } ) : null,
				  ] )
				: null,
			mode && mode.description && slot.state !== 'free' ? el( 'p', { class: 'sp-muted', text: mode.description } ) : null,
			badgesNode( slot ),
			peopleNode( slot ),
		] );
	}

	function openSlotDialog( slot ) {
		if ( slot.actions.indexOf( 'book' ) > -1 ) {
			openBookDialog( slot );
			return;
		}
		var body = el( 'div', {}, [ slotSummary( slot ) ] );
		if ( slot.notes && slot.notes.length ) {
			body.appendChild(
				el(
					'ul',
					{ class: 'sp-notes' },
					slot.notes.map( function ( n ) {
						return el( 'li', { text: n } );
					} )
				)
			);
		}
		if ( slot.actions.indexOf( 'join' ) > -1 ) {
			body.appendChild( joinForm( slot ) );
		}
		var other = slot.actions.filter( function ( a ) {
			return a !== 'join';
		} );
		if ( other.length ) {
			if ( slot.actions.indexOf( 'join' ) > -1 ) {
				body.appendChild( el( 'p', { class: 'sp-muted sp-or', text: t.or } ) );
			}
			body.appendChild(
				el(
					'div',
					{ class: 'sp-card-actions' },
					other.map( function ( a ) {
						return actionButton( slot, a, true );
					} )
				)
			);
		}
		if ( ! slot.actions.length ) {
			var msg = slot.started ? t.started : slot.state === 'free' ? t.beyondHorizon : slot.state === 'blocked' ? t.notAvailable : '';
			if ( msg ) {
				body.appendChild( el( 'p', { class: 'sp-muted', text: msg } ) );
			}
		}
		openDialog( slot.state === 'free' ? t.free : stateText( slot ), body );
	}

	function actionButton( slot, action, inDialog ) {
		var labels = { cancel: t.cancelBooking, leave: t.leave, wait: t.waitlist, unwait: t.unwait, join: t.join, book: t.book };
		var error = el( 'p', { class: 'sp-error', role: 'alert' } );
		var button = el( 'button', {
			type: 'button',
			class: 'sp-btn' + ( action === 'cancel' || action === 'leave' ? ' sp-btn-danger' : action === 'wait' ? ' sp-btn-primary' : '' ),
			text: labels[ action ],
			onclick: function () {
				if ( action === 'cancel' && ! window.confirm( t.cancelConfirm ) ) {
					return;
				}
				if ( action === 'leave' && ! window.confirm( t.leaveConfirm ) ) {
					return;
				}
				if ( action === 'join' || action === 'book' ) {
					openSlotDialog( slot );
					return;
				}
				var body = action === 'cancel' || action === 'leave' ? { id: slot.id } : { date: slot.date, time: slot.time };
				submit( button, error, action, body ).then( function () {
					if ( ! inDialog && error.textContent ) {
						toast( error.textContent, true );
					}
				} );
			},
		} );
		return inDialog ? el( 'span', { class: 'sp-action' }, [ button, error ] ) : button;
	}

	function joinForm( slot ) {
		var mode = modeOf( slot.mode );
		var free = slot.capacity - slot.total;
		var people = peopleInput( mode, free );
		var error = el( 'p', { class: 'sp-error', role: 'alert' } );
		var button = el( 'button', { class: 'sp-btn sp-btn-primary sp-btn-big', type: 'submit', text: t.joinNow } );
		return el(
			'form',
			{
				class: 'sp-form',
				onsubmit: function ( e ) {
					e.preventDefault();
					submit( button, error, 'join', { date: slot.date, time: slot.time, counts: people.counts } );
				},
			},
			[ el( 'h3', { class: 'sp-h3', text: t.joinTitle } ), el( 'p', { class: 'sp-muted', text: fmt( t.placesFree, free ) } ), people.node, error, button ]
		);
	}

	function openBookDialog( slot ) {
		var modes = ( schema().modes || [] ).filter( function ( m ) {
			return ! m.internal;
		} );
		var chosen = null;
		var attributes = {};
		var people = null;
		var error = el( 'p', { class: 'sp-error', role: 'alert' } );
		var button = el( 'button', { class: 'sp-btn sp-btn-primary sp-btn-big', type: 'submit', text: t.bookNow, disabled: true } );
		var details = el( 'div', { class: 'sp-details' } );

		var modeButtons = modes.map( function ( m ) {
			return el(
				'button',
				{
					type: 'button',
					class: 'sp-mode',
					'aria-pressed': 'false',
					'data-mode': m.key,
					onclick: function () {
						chosen = m;
						modeButtons.forEach( function ( b ) {
							b.setAttribute( 'aria-pressed', b.getAttribute( 'data-mode' ) === m.key ? 'true' : 'false' );
						} );
						renderDetails();
						button.disabled = false;
					},
				},
				[
					el( 'span', { class: 'sp-mode-icon', 'aria-hidden': 'true', text: m.icon } ),
					el( 'span', { class: 'sp-mode-text' }, [ el( 'strong', { text: m.label } ), m.description ? el( 'span', { class: 'sp-muted', text: m.description } ) : null ] ),
				]
			);
		} );

		function renderDetails() {
			details.innerHTML = '';
			var previous = people ? people.counts : null;
			people = peopleInput( chosen, slot.capacity, previous );
			details.appendChild( el( 'h3', { class: 'sp-h3', text: chosen.requiresPeople ? t.people : t.peopleOptional } ) );
			details.appendChild( people.node );
			var fields = ( schema().fields || [] ).filter( function ( f ) {
				return f.modes.indexOf( chosen.key ) > -1;
			} );
			Object.keys( attributes ).forEach( function ( key ) {
				var still = fields.some( function ( f ) {
					return f.key === key;
				} );
				if ( ! still ) {
					delete attributes[ key ];
				}
			} );
			fields.forEach( function ( f ) {
				var input = el( 'input', {
					type: 'checkbox',
					id: 'sp-field-' + f.key,
					checked: !! attributes[ f.key ],
					onchange: function ( e ) {
						attributes[ f.key ] = e.target.checked;
					},
				} );
				details.appendChild(
					el( 'label', { class: 'sp-toggle', for: 'sp-field-' + f.key }, [
						input,
						el( 'span', { class: 'sp-toggle-icon', 'aria-hidden': 'true', text: f.icon } ),
						el( 'span', { class: 'sp-toggle-text' }, [ el( 'strong', { text: f.label } ), f.help ? el( 'span', { class: 'sp-muted', text: f.help } ) : null ] ),
					] )
				);
			} );
		}

		var form = el(
			'form',
			{
				class: 'sp-form',
				onsubmit: function ( e ) {
					e.preventDefault();
					if ( ! chosen ) {
						return;
					}
					submit( button, error, 'book', {
						date: slot.date,
						time: slot.time,
						mode: chosen.key,
						counts: people ? people.counts : {},
						attributes: attributes,
					} );
				},
			},
			[
				slotSummary( slot ),
				el( 'h3', { class: 'sp-h3', text: t.chooseMode } ),
				el( 'div', { class: 'sp-modes', role: 'group', 'aria-label': t.chooseMode }, modeButtons ),
				details,
				error,
				button,
			]
		);
		openDialog( t.bookTitle, form );
	}

	/**
	 * Rating: one tap "everything was fine", or smileys + issue chips + comment.
	 *
	 * @param {Object}   target  {id, label}
	 * @param {Function} after   Called after a successful rating.
	 * @param {string}   intro   Optional explanation shown on top.
	 * @param {Object}   link    {r, key} when opened from an emailed link.
	 */
	function openRatingDialog( target, after, intro, link ) {
		var questions = schema().rating || { scales: [], issues: [] };
		var scales = {};
		var issues = [];
		var comment = el( 'textarea', { class: 'sp-input', id: 'sp-comment', rows: '2', maxlength: '1000' } );
		var error = el( 'p', { class: 'sp-error', role: 'alert' } );
		var faces = [
			[ 1, '☹️', t.faceBad ],
			[ 2, '😐', t.faceOkay ],
			[ 3, '😊', t.faceGood ],
		];

		function send( button, body ) {
			if ( link ) {
				body.r = link.r;
				body.key = link.key;
			}
			body.id = target.id;
			var label = button.textContent;
			button.disabled = true;
			error.textContent = '';
			api( 'rate', body )
				.then( function ( json ) {
					if ( json.days ) {
						data = json;
					}
					closeDialog();
					if ( data ) {
						render();
					} else {
						showThanks();
					}
					toast( json.message );
					if ( after ) {
						after();
					}
				} )
				.catch( function ( err ) {
					button.disabled = false;
					button.textContent = label;
					error.textContent = err.message;
				} );
		}

		var quick = el( 'button', {
			type: 'button',
			class: 'sp-btn sp-btn-primary sp-btn-big sp-quick',
			text: '👍 ' + t.rateQuick,
			onclick: function () {
				send( quick, { quick: true } );
			},
		} );

		var scaleNodes = questions.scales.map( function ( q ) {
			var buttons = faces.map( function ( f ) {
				return el(
					'button',
					{
						type: 'button',
						class: 'sp-face',
						'aria-pressed': 'false',
						'aria-label': q.label + ': ' + f[ 2 ],
						title: f[ 2 ],
						onclick: function () {
							scales[ q.key ] = f[ 0 ];
							buttons.forEach( function ( b, i ) {
								b.setAttribute( 'aria-pressed', faces[ i ][ 0 ] === f[ 0 ] ? 'true' : 'false' );
							} );
						},
					},
					[ f[ 1 ] ]
				);
			} );
			return el( 'div', { class: 'sp-scale' }, [ el( 'span', { class: 'sp-scale-label', text: q.label } ), el( 'span', { class: 'sp-faces' }, buttons ) ] );
		} );

		var issueNodes = questions.issues.map( function ( q ) {
			return el( 'button', {
				type: 'button',
				class: 'sp-issue',
				'aria-pressed': 'false',
				text: q.label,
				onclick: function ( e ) {
					var i = issues.indexOf( q.key );
					if ( i > -1 ) {
						issues.splice( i, 1 );
					} else {
						issues.push( q.key );
					}
					e.currentTarget.setAttribute( 'aria-pressed', i > -1 ? 'false' : 'true' );
				},
			} );
		} );

		var sendButton = el( 'button', { type: 'submit', class: 'sp-btn sp-btn-big', text: t.send } );
		var form = el(
			'form',
			{
				class: 'sp-form sp-rating',
				onsubmit: function ( e ) {
					e.preventDefault();
					send( sendButton, { scales: scales, issues: issues, comment: comment.value } );
				},
			},
			[
				intro ? el( 'p', { class: 'sp-notice', text: intro } ) : null,
				target.label ? el( 'p', { class: 'sp-muted', text: target.label } ) : null,
				quick,
				el( 'p', { class: 'sp-muted sp-or', text: t.rateOr } ),
				el( 'div', { class: 'sp-scales' }, scaleNodes ),
				issueNodes.length ? el( 'p', { class: 'sp-label', text: t.issuesLabel } ) : null,
				issueNodes.length ? el( 'div', { class: 'sp-issues' }, issueNodes ) : null,
				el( 'label', { class: 'sp-label', for: 'sp-comment', text: t.commentLabel } ),
				comment,
				error,
				sendButton,
			]
		);
		openDialog( t.rateTitle, form );
	}

	function showThanks() {
		root.innerHTML = '';
		root.appendChild( el( 'div', { class: 'sp-toast', role: 'status', 'aria-live': 'polite' } ) );
		root.appendChild( el( 'div', { class: 'sp-card' }, [ el( 'h2', { class: 'sp-h2', text: t.thanks } ), el( 'p', { text: t.thanksText } ) ] ) );
	}

	function openEmailDialog() {
		var input = el( 'input', { class: 'sp-input', id: 'sp-email', type: 'email', autocomplete: 'email', value: data.me.emailPending || data.me.email } );
		var error = el( 'p', { class: 'sp-error', role: 'alert' } );
		var save = el( 'button', { class: 'sp-btn sp-btn-primary sp-btn-big', type: 'submit', text: t.emailSave } );
		var remove = data.me.email || data.me.emailPending
			? el( 'button', {
					class: 'sp-btn sp-btn-danger',
					type: 'button',
					text: t.emailRemove,
					onclick: function () {
						submit( remove, error, 'email', { email: '' } );
					},
			  } )
			: null;
		var status = data.me.email ? fmt( t.emailActive, data.me.email ) : t.emailNone;
		var form = el(
			'form',
			{
				class: 'sp-form',
				onsubmit: function ( e ) {
					e.preventDefault();
					submit( save, error, 'email', { email: input.value } );
				},
			},
			[
				el( 'p', { text: t.emailText } ),
				el( 'p', { class: 'sp-notice', text: status } ),
				data.me.emailPending ? el( 'p', { class: 'sp-muted', text: fmt( t.emailPendingNote, data.me.emailPending ) } ) : null,
				el( 'label', { class: 'sp-label', for: 'sp-email', text: t.emailLabel } ),
				input,
				error,
				save,
				remove,
				el( 'p', { class: 'sp-muted sp-privacy', text: t.emailPrivacy } ),
			]
		);
		openDialog( t.emailTitle, form );
	}

	/* ------------------------------------------------------------- start */

	/** Open the rating dialog when the page was opened from a rating email. */
	function openFromUrl() {
		var rate = cfg.rateLink;
		if ( ! rate ) {
			return;
		}
		cfg.rateLink = null;
		api( 'rating', { id: rate.id, r: rate.r, key: rate.key }, 'GET' )
			.then( function ( json ) {
				if ( ! data ) {
					cfg.schema = json.schema;
				}
				if ( json.rated ) {
					toast( t.alreadyRated );
					return;
				}
				openRatingDialog( json.target, null, '', rate );
			} )
			.catch( function ( err ) {
				toast( err.message, true );
			} );
	}

	/** Read ?code= / ?rate=&r=&key= once and remove them from the address bar. */
	function readUrl() {
		var params = new URLSearchParams( window.location.search );
		if ( params.get( 'code' ) ) {
			cfg.prefillCode = params.get( 'code' );
		}
		if ( params.get( 'rate' ) && params.get( 'key' ) ) {
			cfg.rateLink = { id: params.get( 'rate' ), r: params.get( 'r' ), key: params.get( 'key' ) };
		}
		[ 'code', 'rate', 'r', 'key' ].forEach( function ( p ) {
			params.delete( p );
		} );
		if ( window.history && window.history.replaceState ) {
			var qs = params.toString();
			window.history.replaceState( null, '', window.location.pathname + ( qs ? '?' + qs : '' ) + window.location.hash );
		}
	}

	function start() {
		root = document.querySelector( '.saunaplan[data-saunaplan]' );
		if ( ! root ) {
			return;
		}
		readUrl();
		api( 'state' )
			.then( function ( json ) {
				data = json;
				render();
				openFromUrl();
			} )
			.catch( function ( err ) {
				data = null;
				if ( err.status === 401 ) {
					if ( cfg.rateLink ) {
						// Rating from an email works without signing in.
						render();
						openFromUrl();
						return;
					}
					render();
					return;
				}
				root.innerHTML = '';
				root.appendChild( el( 'p', { class: 'sp-error', text: t.loadError } ) );
			} );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
