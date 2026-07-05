/* Prism.js grammar for ldt-lang (.ldt files). Not the interpreter's grammar —
   a lightweight approximation good enough for readable syntax highlighting. */
Prism.languages.ldt = {
	'comment': /\[#[\s\S]*?#\]/,
	'tag': {
		pattern: /\[\/?(?:if|elseif|else|for|set|unset|break|continue)\b[^\]]*\]/,
		greedy: true,
		inside: {
			'punctuation': [/^\[\/?/, /\]$/],
			'keyword': [
				/^\/?(?:if|elseif|else|for|set|unset|break|continue)/,
				/\b(?:and|or|not|in|to|by|defined|count|contains|starts with|ends with)\b/,
			],
			'string': /"(?:\\.|[^"\\])*"/,
			'operator': /==|!=|<=|>=|[<>=+\-*/%|]/,
			'variable': [
				/@[A-Za-z_][\w.]*/,
				/(?<=\[(?:set|unset)\s)[A-Za-z_][\w.]*/,
				/(?<=\[unset\b[^\]]*,\s*)[A-Za-z_][\w.]*/,
				/(?<=\[for\s)[A-Za-z_]\w*(?=\s*(?:,|\s+in\b))/,
				/(?<=\[for\s[A-Za-z_]\w*\s*,\s*)[A-Za-z_]\w*(?=\s+in\b)/,
			],
			'number': /\b\d+\b/,
		},
	},
	'interpolation': {
		pattern: /@\{[^}]*\}/,
		greedy: true,
		inside: {
			'punctuation': [/^@\{/, /\}$/, /\|/],
			'function': /\b[a-z][\w]*(?=\s*:)/,
			'string': /"(?:\\.|[^"\\])*"/,
			'variable': /[A-Za-z_][\w.]*/,
			'number': /\b\d+\b/,
		},
	},
	'expression': {
		pattern: /@\([^)]*\)/,
		greedy: true,
		inside: {
			'punctuation': [/^@\(/, /\)$/],
			'keyword': /\b(?:and|or|not|defined|count|contains|starts with|ends with)\b/,
			'operator': /==|!=|<=|>=|[<>=+\-*/%|]/,
			'string': /"(?:\\.|[^"\\])*"/,
			'variable': /@?[A-Za-z_][\w.]*/,
			'number': /\b\d+\b/,
		},
	},
	'escape': {
		pattern: /\\./,
		alias: 'important',
	},
};
