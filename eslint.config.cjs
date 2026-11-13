// ESLint for the admin app's source (src/): WordPress's rules as @wordpress/scripts ships them, with two changes.
const base = require( '@wordpress/scripts/config/eslint.config.cjs' );

module.exports = [
	...base,
	{ ignores: [ 'site/**', 'harness/**', 'tests/**', '.storybook/**' ] },
	{
		// Components document their props in the comment above them, in words.
		rules: {
			'jsdoc/require-param': 'off',
			'jsdoc/require-param-type': 'off',
			'jsdoc/check-tag-names': 'off',
		},
	},
	{
		// A story's render function is a component, though its name starts lower case.
		files: [ '**/*.stories.js' ],
		rules: { 'react-hooks/rules-of-hooks': 'off' },
	},
];
