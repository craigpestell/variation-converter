/*
 * Products > Variation Converter. includes/admin.php prints #varconv-root and loads this.
 */
import { createRoot } from '@wordpress/element';
import App from './App';
import './admin.scss';

const root = document.getElementById( 'varconv-root' );
if ( root ) {
	createRoot( root ).render( <App boot={ window.varconvBoot || {} } /> );
}
