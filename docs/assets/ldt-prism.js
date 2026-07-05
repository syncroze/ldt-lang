/* Prism.js grammar for ldt-lang (.ldt files). Not the interpreter's grammar —
   a lightweight approximation good enough for readable syntax highlighting. */
(function () {
	var STRING = /"(?:\\.|[^"\\])*"/;
	var EXPR_KEYWORD = /\b(?:and|or|not|in|to|by|defined|count|contains|starts with|ends with)\b/;
	var FILTER_NAME = /(?<=\|\s*)[a-z]\w*/;
	var OPERATOR = /==|!=|<=|>=|[<>=+\-*/%|]/;
	var VARIABLE = {
		pattern: /@[A-Za-z_][\w.]*/,
		inside: { 'sigil': /^@/ },
	};
	var NUMBER = /\b\d+\b/;

	var emit = {
		// [= expr] — a quoted string may hold a literal ]
		pattern: /\[=(?:"(?:\\.|[^"\\])*"|[^\]"])*\]/,
		greedy: true,
		inside: {
			'sigil': [/^\[/, /\]$/],
			'string': STRING,
			'function': FILTER_NAME,
			// the leading '=' is the tag's own keyword (before 'operator' runs)
			'keyword': [/^=/, EXPR_KEYWORD],
			'operator': OPERATOR,
			'variable': VARIABLE,
			'number': NUMBER,
			'punctuation': /[():,]/,
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
						pattern: /^set\s+[A-Za-z_][\w.]*/,
						inside: {
							'keyword': /^set/,
							'variable': /[A-Za-z_][\w.]*/,
						},
					},
					{
						pattern: /^unset\s+[A-Za-z_][\w.]*(?:\s*,\s*[A-Za-z_][\w.]*)*/,
						inside: {
							'keyword': /^unset/,
							'punctuation': /,/,
							'variable': /[A-Za-z_][\w.]*/,
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
				'operator': OPERATOR,
				'variable': VARIABLE,
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
