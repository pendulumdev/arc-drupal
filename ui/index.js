/**
 * WordPress dependencies
 */
import { createRoot } from 'react-dom/client';

/**
 * Internal dependencies
 */
import { ConnectPage } from './connect/connect-page';
import { ConsentPage } from './consent/consent-page';
import { DashboardPage } from './dashboard/dashboard-page';
import { DashboardWidget } from './dashboard/dashboard-widget';
import './style.scss';

const rootElement = document.getElementById( 'pendulum-arc-root' );

if ( rootElement ) {
	let bootstrap = {};
	try {
		bootstrap = JSON.parse( rootElement.dataset.bootstrap || '{}' );
	} catch {
		bootstrap = {};
	}
	const Page =
		bootstrap.page === 'dashboard'
			? DashboardPage
			: bootstrap.page === 'consent'
				? ConsentPage
				: ConnectPage;
	createRoot( rootElement ).render( <Page bootstrap={ bootstrap } /> );
}

const widgetElement = document.getElementById(
	'pendulum-arc-dashboard-widget'
);

if ( widgetElement ) {
	createRoot( widgetElement ).render(
		<DashboardWidget
			dashboardUrl={ widgetElement.dataset.dashboardUrl || '' }
			connectUrl={ widgetElement.dataset.connectUrl || '' }
			portalUrl={ widgetElement.dataset.portalUrl || '' }
			connected={ widgetElement.dataset.connected === '1' }
		/>
	);
}
