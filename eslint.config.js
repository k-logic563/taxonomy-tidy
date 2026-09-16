module.exports = [
	{
		files: [ 'assets/js/**/*.js' ],
		languageOptions: {
			ecmaVersion: 2022,
			sourceType: 'script',
			globals: {
				document: 'readonly',
			},
		},
		rules: {
			curly: 'error',
			eqeqeq: 'error',
			'no-undef': 'error',
			'no-unused-vars': 'error',
		},
	},
];
