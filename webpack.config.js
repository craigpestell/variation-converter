/*
 * Builds the admin app from src/ into build/ with @wordpress/scripts. React and the @wordpress packages the app
 * imports are not bundled: the build lists them in build/admin.asset.php, and WordPress loads its own copies.
 */
const path = require( 'path' );
const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		admin: path.resolve( __dirname, 'src/admin/index.js' ),
	},
};
