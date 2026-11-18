/**
 * Tests for assets/js/agentic-markdown.js (the chat / embed / Tasks renderer).
 *
 * Plain node, no dependencies: `node tests/js/markdown.test.js`.
 * Exits non-zero on the first failing case.
 */
'use strict';

const assert = require( 'assert' );
const path = require( 'path' );
const md = require( path.join( __dirname, '../../assets/js/agentic-markdown.js' ) );

const cases = [
	{
		name: 'asterisk bullets with italic titles (the reported reply)',
		input: 'Here are the published posts on your site:\n* *New holiday pastry menu*\n* *Five tips for storing fresh bread*',
		expected: '<p>Here are the published posts on your site:</p><ul><li><em>New holiday pastry menu</em></li><li><em>Five tips for storing fresh bread</em></li></ul>',
	},
	{
		name: 'dash bullets',
		input: '- one\n- two',
		expected: '<ul><li>one</li><li>two</li></ul>',
	},
	{
		name: 'plus bullets',
		input: '+ one\n+ two',
		expected: '<ul><li>one</li><li>two</li></ul>',
	},
	{
		name: 'indented bullets are a list, not code',
		input: 'Posts:\n  * first\n  * second',
		expected: '<p>Posts:</p><ul><li>first</li><li>second</li></ul>',
	},
	{
		name: 'nested bullets, two spaces',
		input: '* parent\n  * child one\n  * child two\n* next',
		expected: '<ul><li>parent<ul><li>child one</li><li>child two</li></ul></li><li>next</li></ul>',
	},
	{
		name: 'nested bullets, four spaces, mixed markers',
		input: '- parent\n    + child\n        * grandchild\n- next',
		expected: '<ul><li>parent<ul><li>child<ul><li>grandchild</li></ul></li></ul></li><li>next</li></ul>',
	},
	{
		name: 'underscore italic and bold inside items',
		input: '* _Draft_ post\n* **Published** post\n* __Scheduled__ post',
		expected: '<ul><li><em>Draft</em> post</li><li><strong>Published</strong> post</li><li><strong>Scheduled</strong> post</li></ul>',
	},
	{
		name: 'bold item with italic text',
		input: '* **Title:** *Bread basics*',
		expected: '<ul><li><strong>Title:</strong> <em>Bread basics</em></li></ul>',
	},
	{
		name: 'numbered list',
		input: '1. First\n2. Second\n3. Third',
		expected: '<ol><li>First</li><li>Second</li><li>Third</li></ol>',
	},
	{
		name: 'numbered list starting above one',
		input: '3. Third\n4. Fourth',
		expected: '<ol start="3"><li>Third</li><li>Fourth</li></ol>',
	},
	{
		name: 'numbered list with nested bullets',
		input: '1. Step one\n   - detail a\n   - detail b\n2. Step two',
		expected: '<ol><li>Step one<ul><li>detail a</li><li>detail b</li></ul></li><li>Step two</li></ol>',
	},
	{
		name: 'loose numbered list (blank lines between items)',
		input: '1. One\n\n2. Two',
		expected: '<ol><li>One</li><li>Two</li></ol>',
	},
	{
		name: 'list followed by a paragraph',
		input: '- a\n- b\n\nThat is all.',
		expected: '<ul><li>a</li><li>b</li></ul><p>That is all.</p>',
	},
	{
		name: 'indented continuation line stays in the item',
		input: '- first item\n  more about it\n- second',
		expected: '<ul><li>first item<br>more about it</li><li>second</li></ul>',
	},
	{
		name: 'four spaces inside a list is not code',
		input: '- item\n    still the item',
		expected: '<ul><li>item<br>still the item</li></ul>',
	},
	{
		name: 'four spaces outside a list is code',
		input: 'Run this:\n\n    wp cache flush\n    wp cron event run --due-now',
		expected: '<p>Run this:</p><div class="agentic-code-wrap"><button class="agentic-copy-btn" title="Copy">Copy</button><pre><code class="language-">wp cache flush\nwp cron event run --due-now</code></pre></div>',
	},
	{
		name: 'one to three leading spaces are plain text',
		input: '   Just text with spaces.',
		expected: '<p>Just text with spaces.</p>',
	},
	{
		name: 'indented line right after text continues the paragraph',
		input: 'Line one\n    line two',
		expected: '<p>Line one<br>line two</p>',
	},
	{
		name: 'fenced code keeps markdown characters literal',
		input: '```php\n* not a list *\n_x_\n```',
		expected: '<div class="agentic-code-wrap"><button class="agentic-copy-btn" title="Copy">Copy</button><pre><code class="language-php">* not a list *\n_x_</code></pre></div>',
	},
	{
		name: 'fenced code, plain mode',
		input: 'Code:\n```\na  b\n```',
		expected: '<p>Code:</p><pre><code>a  b</code></pre>',
		options: { codeBlock: 'plain' },
	},
	{
		name: 'fenced code under a list item stays in the item',
		input: '- Run:\n  ```\n  ls\n  ```\n- Done',
		expected: '<ul><li>Run:<pre><code>  ls</code></pre></li><li>Done</li></ul>',
		options: { codeBlock: 'plain' },
	},
	{
		name: 'italic never spans lines',
		input: 'a *b\nc* d',
		expected: '<p>a *b<br>c* d</p>',
	},
	{
		name: 'multiplication stays literal',
		input: '2 * 3 * 4 = 24',
		expected: '<p>2 * 3 * 4 = 24</p>',
	},
	{
		name: 'snake_case names stay intact',
		input: 'Use get_agent_list and wc_get_orders.',
		expected: '<p>Use get_agent_list and wc_get_orders.</p>',
	},
	{
		name: 'inline code keeps asterisks literal',
		input: '* use `*` for bullets',
		expected: '<ul><li>use <code>*</code> for bullets</li></ul>',
	},
	{
		name: 'horizontal rules are not bullets',
		input: 'above\n\n* * *\n\nbelow\n\n---',
		expected: '<p>above</p><hr><p>below</p><hr>',
	},
	{
		name: 'headings, then a list',
		input: '## Posts\n* one',
		expected: '<h2>Posts</h2><ul><li>one</li></ul>',
	},
	{
		name: 'links in items, unsafe scheme dropped',
		input: '* [Docs](https://example.com/a_b_c)\n* [bad](javascript:alert(1))',
		expected: '<ul><li><a href="https://example.com/a_b_c" target="_blank" rel="noopener">Docs</a></li><li>bad)</li></ul>',
	},
	{
		name: 'delegation button',
		input: '[→ Content Writer](agentic-delegate:content-writer)',
		expected: '<p><button class="agentic-delegate-btn" data-agent="content-writer">→ Content Writer</button></p>',
		options: { delegateLinks: true },
	},
	{
		name: 'html is escaped',
		input: '* <script>alert(1)</script>',
		expected: '<ul><li>&lt;script&gt;alert(1)&lt;/script&gt;</li></ul>',
	},
	{
		name: 'table',
		input: '| Post | Status |\n|---|---|\n| *Bread* | draft |',
		expected: '<table><thead><tr><th>Post</th><th>Status</th></tr></thead><tbody><tr><td><em>Bread</em></td><td>draft</td></tr></tbody></table>',
	},
	{
		name: 'blockquote',
		input: '> quoted *text*',
		expected: '<blockquote>quoted <em>text</em></blockquote>',
	},
	{
		name: 'paragraphs and single line breaks',
		input: 'line1\nline2\n\npara2',
		expected: '<p>line1<br>line2</p><p>para2</p>',
	},
	{
		name: 'bullets then numbers are two lists',
		input: '- a\n1. b',
		expected: '<ul><li>a</li></ul><ol><li>b</li></ol>',
	},
	{
		name: 'line starting with italic or bold is not a bullet',
		input: '*Note:* drafts are private.\n**Tip:** schedule posts.',
		expected: '<p><em>Note:</em> drafts are private.<br><strong>Tip:</strong> schedule posts.</p>',
	},
	{
		name: 'Windows line endings',
		input: '* one\r\n* two',
		expected: '<ul><li>one</li><li>two</li></ul>',
	},
];

let failed = 0;
for ( const c of cases ) {
	const actual = md.render( c.input, c.options );
	try {
		assert.strictEqual( actual, c.expected );
		console.log( 'ok   ' + c.name );
	} catch ( e ) {
		failed++;
		console.log( 'FAIL ' + c.name + '\n  expected: ' + c.expected + '\n  actual:   ' + actual );
	}
}

console.log( '\n' + ( cases.length - failed ) + '/' + cases.length + ' passed' );
process.exit( failed ? 1 : 0 );
