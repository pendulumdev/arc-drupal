const MENU_COUNT_ID = 'pendulum-arc-menu-count';

/**
 * Keeps server alerts, then screen-only notices. Extras never add to the menu count.
 * A null keyphrase flag leaves the server keyphrase alert in place.
 *
 * @param {Array<Object>} alerts
 * @param {Array<Object>} extras
 * @param {boolean|null}  keyphraseDiverged
 * @param {Object|null}   keyphraseNotice
 * @param {boolean|null}  [googleMissing]
 * @param {Object|null}   [googleNotice]
 */
export function pageAlerts(
	alerts,
	extras,
	keyphraseDiverged,
	keyphraseNotice,
	googleMissing = null,
	googleNotice = null
) {
	const server = Array.isArray( alerts ) ? alerts : [];
	let next = applyNotice(
		server.filter( ( alert ) => alert && alert.id ),
		'keyphrases',
		keyphraseDiverged,
		keyphraseNotice ? { ...keyphraseNotice, count: true } : null
	);
	next = applyNotice(
		next,
		'google',
		googleMissing,
		googleNotice ? { ...googleNotice, count: false } : null
	);
	for ( const extra of extras || [] ) {
		if (
			extra &&
			extra.id &&
			! next.some( ( alert ) => alert.id === extra.id )
		) {
			next.push( { ...extra, count: false } );
		}
	}
	return next;
}

/**
 * @param {Array<Object>} alerts
 * @param {string}        id
 * @param {boolean|null}  flag
 * @param {Object|null}   notice
 */
function applyNotice( alerts, id, flag, notice ) {
	let next = alerts.filter( ( alert ) => alert.id !== id || flag !== false );
	if ( flag === true && notice && ! next.some( ( alert ) => alert.id === id ) ) {
		next = [ ...next, notice ];
	}
	return next;
}

/**
 * Analytics has no Google account.
 *
 * @param {Array<Object>} scores
 */
export function googleAccountMissing( scores ) {
	const analytics = ( scores || [] ).find(
		( row ) => row && row.service === 'analytics'
	);
	return Boolean( analytics && analytics.setup === 'connect_google' );
}

/**
 * How many alerts add to the Arc menu count.
 *
 * @param {Array<Object>} alerts
 */
export function countedAlerts( alerts ) {
	return ( alerts || [] ).reduce(
		( count, alert ) => count + ( alert && alert.count ? 1 : 0 ),
		0
	);
}

/**
 * Sets or hides the Arc toolbar bubble.
 *
 * @param {number} count
 */
export function setMenuCount( count ) {
	if ( typeof window.pendulumArcSetMenuCount === 'function' ) {
		window.pendulumArcSetMenuCount( count );
		return;
	}
	const current = document.getElementById( MENU_COUNT_ID );
	if ( ! current ) {
		return;
	}
	if ( count < 1 ) {
		current.hidden = true;
		return;
	}
	current.hidden = false;
	current.textContent = String( count );
}
