/**
 * External dependencies
 */
import { describe, expect, it } from 'vitest';

/**
 * Internal dependencies
 */
import { inviteIdsToSend } from './invite-selection';

describe( 'inviteIdsToSend', () => {
	it( 'sends only checked people on the current page', () => {
		expect( inviteIdsToSend( [ 2, 4, 9 ], [ 1, 4, 4, 9 ] ) ).toEqual( [
			4, 9,
		] );
	} );
} );
