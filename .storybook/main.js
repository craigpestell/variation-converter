/*
 * The style guide: every component in src/components with its story (Name.stories.js beside it).
 *   npm run storybook         browse it at http://localhost:6006 while working
 *   npm run build-storybook   build it into site/components, which the Site workflow publishes with the website
 */
module.exports = {
	stories: [ '../src/**/*.mdx', '../src/**/*.stories.js' ],
	addons: [ '@storybook/addon-essentials', '@storybook/addon-a11y' ],
	framework: { name: '@storybook/react-webpack5', options: {} },
	docs: { autodocs: true },
	core: { disableTelemetry: true },
	webpackFinal: async ( config ) => {
		config.module.rules.push( {
			test: /\.scss$/,
			use: [ 'style-loader', 'css-loader', 'sass-loader' ],
		} );
		// Component files are .js with JSX, as @wordpress/scripts builds them.
		config.module.rules.push( {
			test: /\.js$/,
			include: /src/,
			use: {
				loader: require.resolve( 'babel-loader' ),
				options: { presets: [ require.resolve( '@wordpress/babel-preset-default' ) ] },
			},
		} );
		return config;
	},
};
