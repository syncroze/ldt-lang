/* Prism.js grammar for ldt-lang (.ldt files). Not the interpreter's grammar —
   a lightweight approximation good enough for readable syntax highlighting. */
(function () {
	// a quote right after a '.' opens a quoted path segment, not a string
	var STRING = /(?<!\.)"(?:\\.|[^"\\])*"/;
	var EXPR_KEYWORD = /\b(?:and|or|not|in|to|by|defined|count|contains|starts with|ends with)\b/;
	var FILTER_NAME = /(?<=\|\s*)[a-z]\w*/;
	var OPERATOR = /==|!=|<=|>=|[<>=+\-*/%|]/;
	// a dot-path: bare segments, plus quoted ones (@headers."x-shopify-topic")
	var PATH = '[A-Za-z_]\\w*(?:\\.(?:"(?:\\\\.|[^"\\\\])*"|-?\\w+))*';
	var VARIABLE = {
		pattern: new RegExp('@' + PATH),
		inside: { 'sigil': /^@/ },
	};
	var NUMBER = /\b\d+\b/;

	var emit = {
		// [= expr] — a quoted string may hold a literal ]
		pattern: /\[=(?:"(?:\\.|[^"\\])*"|[^\]"])*\]/,
		greedy: true,
		inside: {
			'punctuation': [/^\[/, /\]$/],
			'string': STRING,
			'function': FILTER_NAME,
			// the leading '=' is the tag's own keyword (before 'operator' runs)
			'keyword': [/^=/, EXPR_KEYWORD],
			// before 'operator': a quoted segment may hold a '-' (@h."x-a")
			'variable': VARIABLE,
			'operator': OPERATOR,
			'number': NUMBER,
			'inner-punctuation': {
				pattern: /[():,]/,
				alias: 'punctuation',
			},
			// leftover barewords are unquoted string values/literals
			'value': {
				pattern: /\S+/,
				alias: 'string',
			},
		},
	};

	Prism.languages.ldt = {
		'comment': /\[#[\s\S]*?#\]/,
		'escape': {
			pattern: /\\[^\r\n0-9A-Za-z]/,
			alias: 'important',
		},
		'emit': emit,
		'tag': {
			// one level of [...] nesting so [set x = [= ...]] highlights whole;
			// \x escapes and quoted strings may hold brackets/quotes
			pattern: /\[\/?(?:if|elseif|else|for|set|unset|break|continue)\b(?:\\.|"(?:\\.|[^"\\])*"|\[(?:\\.|"(?:\\.|[^"\\])*"|[^\]"\\])*\]|[^[\]"\\])*\]/,
			greedy: true,
			inside: {
				'punctuation': [/^\[\/?/, /\]$/],
				'declaration': [
					{
						pattern: new RegExp('^set\\s+' + PATH + '\\.?'),
						inside: {
							'keyword': /^set/,
							'variable': new RegExp(PATH + '\\.?'),
						},
					},
					{
						pattern: new RegExp('^unset\\s+' + PATH + '(?:\\s*,\\s*' + PATH + ')*'),
						inside: {
							'keyword': /^unset/,
							'punctuation': /,/,
							'variable': new RegExp(PATH),
						},
					},
					{
						pattern: /^for\s+[A-Za-z_]\w*(?:\s*,\s*[A-Za-z_]\w*)?(?=\s+in\b)/,
						inside: {
							'keyword': /^for/,
							'punctuation': /,/,
							'variable': /[A-Za-z_]\w*/,
						},
					},
				],
				'emit': emit,
				'keyword': [
					/^\/?(?:if|elseif|else|for|set|unset|break|continue)/,
					EXPR_KEYWORD,
				],
				'string': STRING,
				// after 'string' (an interior \" belongs to its quoted value),
				// before 'operator' (so \| and friends stay whole)
				'escape': {
					pattern: /\\[^\r\n0-9A-Za-z]/,
					alias: 'important',
				},
				'variable': VARIABLE, // before 'operator', as in emit
				'operator': OPERATOR,
				'number': NUMBER,
				'inner-punctuation': {
					pattern: /[():,]/,
					alias: 'punctuation',
				},
				// leftover barewords are unquoted string values/literals
				'value': {
					pattern: /\S+/,
					alias: 'string',
				},
			},
		},
	};
}());
