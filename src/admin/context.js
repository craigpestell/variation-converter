/*
 * What every screen of the app can reach: the notice above the content, the tab counts and bulk job, and a way to say
 * the store changed so lists load again.
 */
import { createContext, useContext } from '@wordpress/element';

export const AppContext = createContext( {
	status: { counts: {}, scanned: '', job: null },
	version: 0,
	jobRunning: false,
	notify: () => {},
	changed: () => {},
	followJob: () => {},
	setCombineCount: () => {},
} );

export function useApp() {
	return useContext( AppContext );
}
