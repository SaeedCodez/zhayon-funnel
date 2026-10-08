/**
 * Zhayon Funnel — report page behaviour. Vanilla JS, no dependencies.
 *
 * - filters: auto-submit selects, custom date range toggle
 * - daily trend: two small-multiple SVG line charts (time flows right → left),
 *   one shared crosshair + tooltip, arrow keys move the crosshair
 * - funnel bars: hover / focus tooltip
 * - settings: source-row repeater, live brand-colour preview
 */
( function () {
	'use strict';

	var root = document.querySelector( '.zhf' );
	if ( ! root ) {
		return;
	}

	var faNumber = new Intl.NumberFormat( 'fa-IR' );
	var SVG_NS = 'http://www.w3.org/2000/svg';

	function svgEl( name, attrs ) {
		var el = document.createElementNS( SVG_NS, name );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			el.setAttribute( key, attrs[ key ] );
		} );
		return el;
	}

	/* -------------------------------------------------------------- Filters */

	var filters = root.querySelector( '[data-zhf-filters]' );
	if ( filters ) {
		filters.querySelectorAll( '[data-zhf-autosubmit]' ).forEach( function ( input ) {
			input.addEventListener( 'change', function () {
				filters.submit();
			} );
		} );

		var toggle = filters.querySelector( '[data-zhf-custom-toggle]' );
		var custom = filters.querySelector( '[data-zhf-custom]' );
		if ( toggle && custom ) {
			toggle.addEventListener( 'click', function () {
				var open = custom.hidden;
				custom.hidden = ! open;
				toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
				custom.querySelectorAll( 'input' ).forEach( function ( input ) {
					input.disabled = ! open;
				} );
				if ( open ) {
					custom.querySelector( 'input' ).focus();
				}
			} );
		}
	}

	/* -------------------------------------------------------- Shared tooltip */

	function placeTooltip( tip, container, x, y ) {
		var box = container.getBoundingClientRect();
		var width = tip.offsetWidth;
		var height = tip.offsetHeight;
		var left = x - box.left - width - 16;
		if ( left < 0 ) {
			left = x - box.left + 16;
		}
		left = Math.max( 0, Math.min( left, box.width - width ) );
		var top = Math.max( 0, Math.min( y - box.top - height / 2, box.height - height ) );
		tip.style.left = left + 'px';
		tip.style.top = top + 'px';
	}

	/* ---------------------------------------------------------- Daily trend */

	function niceMax( value ) {
		if ( value <= 4 ) {
			return 4;
		}
		var half = value / 2;
		var power = Math.pow( 10, Math.floor( Math.log10( half ) ) );
		var steps = [ 1, 1.5, 2, 2.5, 3, 4, 5, 6, 8, 10 ];
		for ( var i = 0; i < steps.length; i++ ) {
			var step = steps[ i ] * power;
			if ( step >= half && step === Math.round( step ) ) {
				return step * 2;
			}
		}
		return Math.ceil( half ) * 2;
	}

	function initTrend( trend ) {
		var data;
		try {
			data = JSON.parse( trend.getAttribute( 'data-zhf-trend' ) );
		} catch ( e ) {
			return;
		}
		var days = data.days || [];
		if ( ! days.length ) {
			return;
		}

		var panels = Array.prototype.map.call( trend.querySelectorAll( '.zhf-trend-panel' ), function ( panel ) {
			return { el: panel, svg: panel.querySelector( 'svg' ), key: panel.getAttribute( 'data-series' ) };
		} );
		var tip = trend.querySelector( '.zhf-tooltip' );
		var geometry = null;
		var active = -1;

		trend.tabIndex = 0;
		trend.setAttribute( 'aria-label', ( data.labels.cart || '' ) + ' / ' + ( data.labels.paid || '' ) );

		function draw() {
			var width = panels[ 0 ].svg.clientWidth;
			if ( ! width ) {
				return;
			}
			var n = days.length;
			var axis = 44; // Y labels sit on the right (RTL).
			var right = width - axis;
			var left = 6;
			var step = n > 1 ? ( right - left ) / ( n - 1 ) : 0;
			var xAt = function ( i ) {
				return n > 1 ? right - i * step : ( left + right ) / 2;
			};

			geometry = { xAt: xAt, step: step, right: right, left: left, n: n, panels: [] };

			panels.forEach( function ( panel, index ) {
				var svg = panel.svg;
				var isLast = index === panels.length - 1;
				var height = svg.clientHeight || 132;
				var top = 8;
				var bottom = height - ( isLast ? 24 : 8 );
				var values = days.map( function ( d ) {
					return d[ panel.key ] || 0;
				} );
				var max = niceMax( Math.max.apply( null, values ) );
				var yAt = function ( v ) {
					return bottom - ( v / max ) * ( bottom - top );
				};

				while ( svg.firstChild ) {
					svg.removeChild( svg.firstChild );
				}
				svg.setAttribute( 'viewBox', '0 0 ' + width + ' ' + height );

				[ 0, max / 2, max ].forEach( function ( tick ) {
					var y = Math.round( yAt( tick ) ) + 0.5;
					svg.appendChild( svgEl( 'line', { 'class': 'zhf-grid-line', x1: left, x2: right, y1: y, y2: y } ) );
					var label = svgEl( 'text', { 'class': 'zhf-axis-text', x: width, y: y + 4, 'text-anchor': 'end' } );
					label.textContent = faNumber.format( tick );
					svg.appendChild( label );
				} );

				if ( isLast ) {
					// Label the latest day, then step back in time.
					var every = Math.max( 1, Math.ceil( n / Math.max( 2, Math.floor( ( right - left ) / 72 ) ) ) );
					for ( var i = n - 1; i >= 0; i -= every ) {
						// Edge labels anchor inward so they never clip (latest day sits at the left edge).
						var anchor = n > 1 && i === n - 1 ? 'start' : ( n > 1 && i === 0 ? 'end' : 'middle' );
						var tx = svgEl( 'text', { 'class': 'zhf-axis-text', x: xAt( i ), y: height - 4, 'text-anchor': anchor } );
						tx.textContent = days[ i ].label;
						svg.appendChild( tx );
					}
				}

				var line = '';
				values.forEach( function ( v, i ) {
					line += ( i ? 'L' : 'M' ) + xAt( i ).toFixed( 1 ) + ' ' + yAt( v ).toFixed( 1 );
				} );
				if ( n > 1 ) {
					svg.appendChild( svgEl( 'path', { 'class': 'zhf-area', d: line + 'L' + xAt( n - 1 ).toFixed( 1 ) + ' ' + bottom + 'L' + xAt( 0 ).toFixed( 1 ) + ' ' + bottom + 'Z' } ) );
				}
				svg.appendChild( svgEl( 'path', { 'class': 'zhf-line', d: line } ) );

				var cross = svgEl( 'line', { 'class': 'zhf-cross', x1: 0, x2: 0, y1: top, y2: bottom, visibility: 'hidden' } );
				var dot = svgEl( 'circle', { 'class': 'zhf-dot', r: 4, cx: 0, cy: 0, visibility: 'hidden' } );
				svg.appendChild( cross );
				svg.appendChild( dot );
				svg.appendChild( svgEl( 'rect', { 'class': 'zhf-hit', x: 0, y: 0, width: width, height: height } ) );

				geometry.panels.push( { cross: cross, dot: dot, yAt: yAt, values: values } );
			} );

			if ( active >= 0 ) {
				show( active );
			}
		}

		function show( index, clientX, clientY ) {
			if ( ! geometry ) {
				return;
			}
			active = index;
			var x = geometry.xAt( index );
			geometry.panels.forEach( function ( p ) {
				p.cross.setAttribute( 'x1', x );
				p.cross.setAttribute( 'x2', x );
				p.cross.setAttribute( 'visibility', 'visible' );
				p.dot.setAttribute( 'cx', x );
				p.dot.setAttribute( 'cy', p.yAt( p.values[ index ] ) );
				p.dot.setAttribute( 'visibility', 'visible' );
			} );

			var day = days[ index ];
			tip.textContent = '';
			var date = document.createElement( 'div' );
			date.className = 'zhf-tooltip-date';
			date.textContent = day.full;
			tip.appendChild( date );
			[ [ 'cart', true ], [ 'paid', false ] ].forEach( function ( series ) {
				var row = document.createElement( 'div' );
				row.className = 'zhf-tooltip-row';
				var key = document.createElement( 'i' );
				key.className = 'zhf-key' + ( series[ 1 ] ? ' is-primary' : '' );
				var value = document.createElement( 'strong' );
				value.textContent = faNumber.format( day[ series[ 0 ] ] || 0 );
				var label = document.createElement( 'span' );
				label.textContent = data.labels[ series[ 0 ] ];
				row.appendChild( key );
				row.appendChild( value );
				row.appendChild( label );
				tip.appendChild( row );
			} );
			tip.hidden = false;

			if ( undefined === clientX ) {
				var svgBox = panels[ 0 ].svg.getBoundingClientRect();
				clientX = svgBox.left + x;
				clientY = svgBox.top + svgBox.height;
			}
			placeTooltip( tip, trend, clientX, clientY );
		}

		function hide() {
			active = -1;
			tip.hidden = true;
			if ( geometry ) {
				geometry.panels.forEach( function ( p ) {
					p.cross.setAttribute( 'visibility', 'hidden' );
					p.dot.setAttribute( 'visibility', 'hidden' );
				} );
			}
		}

		function indexFromPointer( clientX ) {
			var box = panels[ 0 ].svg.getBoundingClientRect();
			var x = clientX - box.left;
			if ( geometry.n < 2 ) {
				return 0;
			}
			var i = Math.round( ( geometry.right - x ) / geometry.step );
			return Math.max( 0, Math.min( geometry.n - 1, i ) );
		}

		trend.addEventListener( 'pointermove', function ( e ) {
			if ( geometry ) {
				show( indexFromPointer( e.clientX ), e.clientX, e.clientY );
			}
		} );
		trend.addEventListener( 'pointerleave', hide );
		trend.addEventListener( 'blur', hide );
		trend.addEventListener( 'keydown', function ( e ) {
			var last = days.length - 1;
			var next = active < 0 ? last : active;
			// RTL: the left arrow moves forward in time.
			if ( 'ArrowLeft' === e.key ) {
				next = Math.min( last, next + ( active < 0 ? 0 : 1 ) );
			} else if ( 'ArrowRight' === e.key ) {
				next = Math.max( 0, next - ( active < 0 ? 0 : 1 ) );
			} else if ( 'Escape' === e.key ) {
				hide();
				return;
			} else {
				return;
			}
			e.preventDefault();
			show( next );
		} );

		draw();
		if ( 'ResizeObserver' in window ) {
			new ResizeObserver( draw ).observe( trend );
		} else {
			window.addEventListener( 'resize', draw );
		}
	}

	root.querySelectorAll( '[data-zhf-trend]' ).forEach( initTrend );

	/* -------------------------------------------------- Funnel bar tooltips */

	var bars = root.querySelectorAll( '[data-zhf-tip]' );
	if ( bars.length ) {
		var floatTip = document.createElement( 'div' );
		floatTip.className = 'zhf-tooltip zhf-tip-float';
		floatTip.hidden = true;
		floatTip.setAttribute( 'role', 'tooltip' );
		root.appendChild( floatTip );

		var showBar = function ( bar, clientX, clientY ) {
			floatTip.textContent = bar.getAttribute( 'data-zhf-tip' );
			floatTip.hidden = false;
			var box = bar.getBoundingClientRect();
			var x = undefined === clientX ? box.left + box.width / 2 : clientX;
			var y = undefined === clientY ? box.top : clientY;
			var left = Math.max( 8, Math.min( x - floatTip.offsetWidth / 2, window.innerWidth - floatTip.offsetWidth - 8 ) );
			floatTip.style.left = left + 'px';
			floatTip.style.top = Math.max( 8, y - floatTip.offsetHeight - 12 ) + 'px';
		};
		var hideBar = function () {
			floatTip.hidden = true;
		};
		bars.forEach( function ( bar ) {
			bar.addEventListener( 'pointermove', function ( e ) {
				showBar( bar, e.clientX, e.clientY );
			} );
			bar.addEventListener( 'pointerleave', hideBar );
			bar.addEventListener( 'focus', function () {
				showBar( bar );
			} );
			bar.addEventListener( 'blur', hideBar );
		} );
		window.addEventListener( 'scroll', hideBar, { passive: true } );
	}

	/* ------------------------------------------------------------- Settings */

	var settings = root.querySelector( '[data-zhf-settings]' );
	if ( settings ) {
		var rows = settings.querySelector( '[data-zhf-source-rows]' );
		var template = settings.querySelector( '[data-zhf-source-template]' );
		var counter = 0;

		settings.addEventListener( 'click', function ( e ) {
			var remove = e.target.closest( '[data-zhf-remove-row]' );
			if ( remove ) {
				var row = remove.closest( 'tr' );
				var focusTarget = row.nextElementSibling || row.previousElementSibling;
				row.remove();
				if ( focusTarget ) {
					focusTarget.querySelector( 'input' ).focus();
				} else {
					settings.querySelector( '[data-zhf-add-row]' ).focus();
				}
				return;
			}
			if ( e.target.closest( '[data-zhf-add-row]' ) && template && rows ) {
				var key = 'n' + Date.now().toString( 36 ) + ( counter++ );
				var html = template.innerHTML.replace( /__i__/g, key );
				var tbody = document.createElement( 'tbody' );
				tbody.innerHTML = html;
				var added = tbody.firstElementChild;
				rows.appendChild( added );
				added.querySelector( 'input' ).focus();
			}
		} );

		// Live brand-colour preview; mirrors Admin::brand_css().
		var picker = settings.querySelector( '[data-zhf-color-picker]' );
		var text = settings.querySelector( '[data-zhf-color-text]' );
		var mix = function ( rgb, target, amount ) {
			return '#' + rgb.map( function ( c, i ) {
				return ( '0' + Math.round( c * ( 1 - amount ) + target[ i ] * amount ).toString( 16 ) ).slice( -2 );
			} ).join( '' );
		};
		var applyColor = function ( hex ) {
			if ( ! /^#[0-9a-f]{6}$/i.test( hex ) ) {
				return false;
			}
			var rgb = [ 1, 3, 5 ].map( function ( i ) {
				return parseInt( hex.substr( i, 2 ), 16 );
			} );
			root.style.setProperty( '--zhf-primary', hex );
			root.style.setProperty( '--zhf-primary-hover', mix( rgb, [ 0, 0, 0 ], 0.08 ) );
			root.style.setProperty( '--zhf-primary-tint', mix( rgb, [ 255, 255, 255 ], 0.91 ) );
			root.style.setProperty( '--zhf-primary-tint-border', mix( rgb, [ 255, 255, 255 ], 0.8 ) );
			root.style.setProperty( '--zhf-primary-ring', 'rgba(' + rgb.join( ',' ) + ',.35)' );
			return true;
		};
		if ( picker && text ) {
			picker.addEventListener( 'input', function () {
				text.value = picker.value;
				applyColor( picker.value );
			} );
			text.addEventListener( 'input', function () {
				var value = text.value.trim();
				if ( value && '#' !== value.charAt( 0 ) ) {
					value = '#' + value;
				}
				if ( applyColor( value ) ) {
					picker.value = value.toLowerCase();
				}
			} );
		}
	}
}() );
