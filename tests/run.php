<?php

declare(strict_types=1);

use Ldtlang\Ldt;

require __DIR__ . '/../autoload.php';

/** Tiny zero-dependency test runner. */
$pass = 0;
$fail = 0;

function check(string $name, string $expected, string $actual): void
{
    global $pass, $fail;
    if ($expected === $actual) {
        $pass++;
        fwrite(STDOUT, "  \e[32mok\e[0m   $name\n");
    } else {
        $fail++;
        fwrite(STDOUT, "  \e[31mFAIL\e[0m $name\n");
        fwrite(STDOUT, "        expected: " . json_encode($expected) . "\n");
        fwrite(STDOUT, "        actual:   " . json_encode($actual) . "\n");
    }
}

function throws(string $name, callable $fn): void
{
    global $pass, $fail;
    try {
        $fn();
        $fail++;
        fwrite(STDOUT, "  \e[31mFAIL\e[0m $name (expected exception)\n");
    } catch (\Throwable) {
        $pass++;
        fwrite(STDOUT, "  \e[32mok\e[0m   $name\n");
    }
}

/** Assert that rendering fails with a SyntaxError at exactly line:col. */
function errorAt(string $name, int $line, int $col, callable $fn): void
{
    global $pass, $fail;
    try {
        $fn();
        $fail++;
        fwrite(STDOUT, "  \e[31mFAIL\e[0m $name (expected an error)\n");
    } catch (\Ldtlang\SyntaxError $e) {
        if ($e->srcLine === $line && $e->srcCol === $col) {
            $pass++;
            fwrite(STDOUT, "  \e[32mok\e[0m   $name\n");
        } else {
            $fail++;
            fwrite(STDOUT, "  \e[31mFAIL\e[0m $name\n");
            fwrite(STDOUT, "        expected: $line:$col  actual: {$e->srcLine}:{$e->srcCol}\n");
        }
    }
}

$L = "\n"; // readability helper for newlines

// --- scalar --------------------------------------------------------------
check('block set + emit', 'testing', Ldt::render('[set a]testing[/set][= @a]'));
check('self-closing set + emit', 'testing', Ldt::render('[set a = testing][= @a]'));
check('embedded mid-text', 'consectetur', Ldt::render('conse[set a = testing]ctetur'));
check('emit splices mid-word', 'consectetur', Ldt::render('[set a = sec]con[= @a]tetur'));
check('emit mid-text', 'constestingequat', Ldt::render('[set a = testing]cons[= @a]equat'));
check('block value keeps inner spaces', 'hello world', Ldt::render('[set a]hello world[/set][= @a]'));
check('self-closing value is trimmed', 'hi', Ldt::render('[set a =   hi  ][= @a]'));

// --- quoted [set] values (strict rule) ------------------------------------
check('quoted value keeps edge spaces', '<   hi   >', Ldt::render('[set a = "   hi   "]<[= @a]>'));
check('quoted block value keeps edge spaces', '<  hi  >', Ldt::render('[set a]"  hi  "[/set]<[= @a]>'));
check('quoted empty value', '<>', Ldt::render('[set a = ""]<[= @a]>'));
check('quoted whitespace-only value', '<   >', Ldt::render('[set a = "   "]<[= @a]>'));
check('interior escaped quotes cook', 'say "hi"', Ldt::render('[set a = "say \"hi\""][= @a]'));
check('escaped leading quote stays literal', '"  q  "', Ldt::render('[set a = \"  q  \"][= @a]'));
check('escaped open, bare close also literal', '"  q  "', Ldt::render('[set a = \"  q  "][= @a]'));
check('non-leading quotes untouched', 'say "hi" now', Ldt::render('[set s = say "hi" now][= @s]'));
check('quoted value may emit', '<  Ada  >', Ldt::render('[set n = Ada][set g = "  [= @n]  "]<[= @g]>'));
check('quoted default arg keeps spaces', '<  x  >', Ldt::render('<[= @missing | default: "  x  "]>'));
check('default arg cooks escapes', 'say "hi"', Ldt::render('[= @missing | default: "say \"hi\""]'));
throws('content after closing quote errors', fn () => Ldt::render('[set a = "yes" or "no"]'));
throws('unterminated quoted value errors', fn () => Ldt::render('[set a = "unclosed]'));
throws('block form enforces the rule too', fn () => Ldt::render('[set a]"x" y[/set]'));
throws('a lone quote char needs escaping', fn () => Ldt::render('[set q]"[/set]'));
check('or in an emit renders a flag (no fallback syntax)', '1', Ldt::render('[= @missing or "x"]'));

// --- uniform ] closer for [set = ] ----------------------------------------
check('] closes the = form (no /)', 'test', Ldt::render('[set a = test][= @a]'));
check('quotes protect ] in a value', 'sdfsd ] vbnvb', Ldt::render('[set b = "sdfsd ] vbnvb"][= @b]'));
check('quotes protect [ and ] together', 'she said "hi" [ok]', Ldt::render('[set m = "she said \"hi\" [ok]"][= @m]'));
check('escaped [ and ] in an unquoted value', 'see [1] here', Ldt::render('[set c = see \[1\] here][= @c]'));
check('bare trailing slash needs no escape', 'http://x.com/', Ldt::render('[set url = http://x.com/][= @url]'));
check('value stops at the first top-level ]', '<x>y]', Ldt::render('[set a = x]<[= @a]>y]'));
check('empty = value', '<>', Ldt::render('[set e =]<[= @e]>'));
check('multiline quoted value keeps its newline', "a\nb", Ldt::render('[set v = "a' . $L . 'b"][= @v]'));
throws('old /] after a quoted value errors', fn () => Ldt::render('[set a = "testomg"/]'));
throws('unterminated = value (no ]) errors', fn () => Ldt::render('[set a = oops'));
throws('unpaired literal [ in a = value errors (escape it)', fn () => Ldt::render('[set a = x [ y]'));

// --- quoted strings are consistent everywhere ------------------------------
check('escaped quote in an [if] string literal', 'match', Ldt::render('[set a = "say \"hi\""][if @a == "say \"hi\""]match[else]no[/if]'));
check('escaped quote in a filter arg', 'a"b', Ldt::render('[set i.=a][set i.=b][= @i | join: "\""]'));
check('escaped backslash cooks in expression strings', 'y', Ldt::render('[set p = a\\\\b][if @p == "a\\\\b"]y[/if]'));
check('backslash before a letter stays literal in expr strings', 'y', Ldt::render('[set p = C:\Users][if @p == "C:\Users"]y[/if]'));
check('multiline expression string', 'y', Ldt::render('[set v = "x' . $L . 'y"][if @v == "x' . $L . 'y"]y[/if]'));
check('quotes protect ] in expression strings (still)', 'y', Ldt::render('[set a = "x]y"][if @a == "x]y"]y[/if]'));
check('quotes protect ] in an emitted string', 'a]b', Ldt::render('[= "a]b"]'));
check('block quotes protect a literal [/set]', 'x [/set] y', Ldt::render('[set a]"x [/set] y"[/set][= @a]'));
check('block quoted value may sit on its own lines', '  spaced  ', Ldt::render('[set b]' . $L . '"  spaced  "' . $L . '[/set][= @b]'));
check('block escaped leading quote stays raw', '"x"', Ldt::render('[set a]\"x\"[/set][= @a]'));
throws('block content after closing quote still errors', fn () => Ldt::render('[set a]"x" y[/set]'));
throws('block lone quote still errors', fn () => Ldt::render('[set q]"[/set]'));
throws('unterminated expression string still errors', fn () => Ldt::render('[if @a == "oops]x[/if]'));

// --- exact error coordinates inside [set] values ---------------------------
errorAt('runtime error in a = value reports template coords', 2, 19,
    fn () => Ldt::render("line one\nline two [set x = [= 1 / 0]][= @x]"));
errorAt('lex error in a multiline block value reports its real line', 3, 4,
    fn () => Ldt::render("[set r]\ngood\n[= @bad..path]\n[/set][= @r]"));
errorAt('error inside a quoted = value reports template coords', 1, 14,
    fn () => Ldt::render('[set q = "[= @oops..]"][= @q]'));
errorAt('filter error deep in a block value reports its real line', 3, 5,
    fn () => Ldt::render("[set r]\n[for x in @i]\n    [= @x | bogus]\n[/for]\n[/set][= @r]", ['i' => ['a']]));
errorAt('[break] in a value points at the actual [break]', 1, 26,
    fn () => Ldt::render('[for n in 1 to 2][set a]x[break]y[/set][/for]'));
check('identical values at two sites both render', 'A:B',
    Ldt::render('[set n=A][set x = [= @n]][set out1 = [= @x]][set n=B][set y = [= @n]][= @out1]:[= @y]'));

// --- newlines inside tag headers --------------------------------------------
check('newline in a [for] header', 'xx', Ldt::render('[for n' . $L . 'in 1 to 2]x[/for]'));
check('newline mid-range in a [for] header', '12', Ldt::render('[for n in' . $L . '1 to 2][= @n][/for]'));
check('newline in a [set] header', '1', Ldt::render('[set' . $L . 'a = 1][= @a]'));
check('newline in an [unset] list', 'ok', Ldt::render('[unset' . $L . 'x,' . $L . 'y]ok'));
check('newline before a condition closer', 'y', Ldt::render('[if 1' . $L . ']y[/if]'));
check('newline inside an emit', '3', Ldt::render('[= 1 +' . $L . '2]'));

// --- seeding rejects unsupported types --------------------------------------
(function () use (&$pass, &$fail) {
    try {
        Ldt::render('[= @u.av]', ['u' => ['av' => new stdClass()]]);
        $fail++;
        fwrite(STDOUT, "  \e[31mFAIL\e[0m seeding an object throws (expected exception)\n");
    } catch (\InvalidArgumentException $e) {
        $ok = str_contains($e->getMessage(), 'stdClass') && str_contains($e->getMessage(), "u.av");
        $ok ? $pass++ : $fail++;
        fwrite(STDOUT, '  ' . ($ok ? "\e[32mok\e[0m  " : "\e[31mFAIL\e[0m") . " seeding an object throws with key path\n");
    }
})();
check('seeding ints and floats still works', '3|1.5', Ldt::render('[= @a]|[= @b]', ['a' => 3, 'b' => 1.5]));

// --- [set] values are mini-templates ----------------------------------------
check('[if] in a block value always executes', 'Hello dear friend', Ldt::render('[set vip = 1][set a]Hello [if @vip]dear [/if]friend[/set][= @a]'));
check('[for] builds a value (no [= ] needed)', 'XX', Ldt::render('[set i.=a][set i.=b][set list][for x in @i]X[/for][/set][= @list]'));
check('loop-built list value', 'a,b,c,', Ldt::render('[set i.=a][set i.=b][set i.=c][set l][for x in @i][= @x],[/for][/set][= @l]'));
check('side-effect [set] inside a value runs', 'z|2', Ldt::render('[set a]z[set y = 2][/set][= @a]|[= @y]'));
check('escaped tag stays literal in a value', 'literal [if kept', Ldt::render('[set a]literal \[if kept[/set][= @a]'));
check('[unset] works inside a value', '10', Ldt::render('[set y = 1][set a][= defined @y][unset y][= defined @y][/set][= @a]'));
check('[if] nests in the = form (bracket-aware value)', 'x y', Ldt::render('[set b = 1][set a = x [if @b]y[/if]][= @a]'));
check('emit nests in the = form', '35', Ldt::render('[set price=10][set qty=3][set total = [= @price * @qty + 5]][= @total]'));
check('emit + text mix in the = form', 'Hello Ada!', Ldt::render('[set name = Ada][set msg = Hello [= @name]!][= @msg]'));
throws('nested block set fails loudly', fn () => Ldt::render('[set a][set b]x[/set][/set]'));

// --- filter arity & context-sensitive keywords ------------------------------
throws('upper takes no arguments', fn () => Ldt::render('[set s=hi][= @s | upper: 1]'));
throws('default takes exactly one argument', fn () => Ldt::render('[= @x | default: "a", "b"]'));
throws('truncate takes one or two arguments', fn () => Ldt::render('[set s=hi][= @s | truncate: 1, "x", "y"]'));
throws('round takes at most one argument', fn () => Ldt::render('[set n=1][= @n | round: 1, 2]'));
check('count compares as a plain literal', 'y', Ldt::render('[set x = count][if @x == count]y[/if]'));
check('defined compares as a plain literal', 'y', Ldt::render('[set x = defined][if @x == defined]y[/if]'));
check('count still operates on a ref', '2', Ldt::render('[set i.=a][set i.=b][= count @i]'));
check('defined still operates on a ref', '1', Ldt::render('[set a=1][= defined @a]'));

// --- indexed arrays (trailing-dot append) --------------------------------
check('append + numeric index', 'one|two', Ldt::render('[set b. = one][set b. = two][= @b.0]|[= @b.1]'));
check('append via block form', 'x|y', Ldt::render('[set b.]x[/set][set b.]y[/set][= @b.0]|[= @b.1]'));

// --- keyed arrays --------------------------------------------------------
check('keyed set + key lookup', 'one|two', Ldt::render('[set c.t1 = one][set c.t2 = two][= @c.t1]|[= @c.t2]'));

// --- nesting -------------------------------------------------------------
check('nested keyed path auto-vivifies', 'deep', Ldt::render('[set a.b.c = deep][= @a.b.c]'));
check('append into a nested indexed slot', 'pen|ink', Ldt::render('[set f.0. = pen][set f.0. = ink][= @f.0.0]|[= @f.0.1]'));
check('append into a nested keyed slot', 'r1|r2', Ldt::render('[set g.rows. = r1][set g.rows. = r2][= @g.rows.0]|[= @g.rows.1]'));
check('mixed keyed then keyed leaf', 'Ada', Ldt::render('[set u.first.name = Ada][= @u.first.name]'));

// --- [unset]: removal → undefined ------------------------------------------
check('unset scalar makes it undefined', '<>0', Ldt::render('[set a=x][unset a]<[= @a]>[= defined @a]'));
check('unset keyed array removes the tree', '0|0', Ldt::render('[set u.a=1][set u.b=2][unset u][= defined @u]|[= count @u]'));
check('unset a subtree leaves siblings', 'Ada|0', Ldt::render('[set u.name=Ada][set u.addr.city=L][unset u.addr][= @u.name]|[= defined @u.addr]'));
check('unset an index leaves a hole', '2:(0:a)(2:c)', Ldt::render('[set i.=a][set i.=b][set i.=c][unset i.1][= count @i]:[for k, v in @i]([= @k]:[= @v])[/for]'));
check('append after index-unset continues from max', 'd', Ldt::render('[set i.=a][set i.=b][unset i.1][set i.=d][= @i.2]'));
check('multi-unset removes all paths', '000', Ldt::render('[set p=1][set q=2][set r=3][unset p, q, r][= defined @p][= defined @q][= defined @r]'));
check('unset of a missing name is a no-op', 'ok', Ldt::render('[unset never]ok'));
check('unset through a scalar is a no-op', 'x', Ldt::render('[set s=x][unset s.deep][= @s]'));
check('unset then set gives a fresh container', 'fresh', Ldt::render('[set g.old=1][unset g][set g.k=fresh][= @g.k]'));
check('unset variable triggers default', 'F', Ldt::render('[set a=1][unset a][= @a | default: "F"]'));
check('[unsettle] stays literal', '[unsettle]', Ldt::render('[unsettle]'));
check('standalone [unset] line is trimmed', 'A' . $L . 'B', Ldt::render('A' . $L . '[unset x]' . $L . 'B'));
throws('unset without a path', fn () => Ldt::render('[unset ]'));
throws('unset with a trailing dot', fn () => Ldt::render('[unset a.]'));
throws('unset missing its closer', fn () => Ldt::render('[unset a'));
throws('unset with a dangling comma', fn () => Ldt::render('[unset a,]'));
throws('strict errors after unset', fn () => Ldt::render('[set a=1][unset a][= @a]', strict: true));

// --- comments ------------------------------------------------------------
check('inline comment renders nothing', 'ab', Ldt::render('a[# note #]b'));
check('comment may span lines', 'ab', Ldt::render('a[# line one' . $L . 'line two #]b'));
check('standalone comment line is trimmed', 'A' . $L . 'B', Ldt::render('A' . $L . '[# c #]' . $L . 'B'));

// --- value emits -----------------------------------------------------------
check('value may reference earlier vars', 'hello world', Ldt::render('[set who = world][set msg = hello [= @who]][= @msg]'));
check('value references a nested path', 'Ada Lovelace', Ldt::render('[set u.first = Ada][set u.last = Lovelace][set full = [= @u.first] [= @u.last]][= @full]'));

// --- undefined -----------------------------------------------------------
check('undefined ref renders empty (default)', 'x', Ldt::render('x[= @nope]'));
check('undefined nested ref renders empty', 'x', Ldt::render('x[= @a.b.c]'));
throws('undefined ref throws in strict mode', fn () => Ldt::render('[= @nope]', strict: true));

// --- literals: brackets and @ in text ---------------------------------------
check('lone [ is literal', 'a [ b', Ldt::render('a [ b'));
check('doubled [[ is literal', '[[x]]', Ldt::render('[[x]]'));
check('bracket flush against an emit is fine', '[hi]', Ldt::render('[set x=hi][[= @x]]'));
check('[setup] is not a set directive', '[setup]', Ldt::render('[setup]'));
check('lone @ in text is literal', 'me@site.com', Ldt::render('me@site.com'));
check('(@handle) in text is literal', 'ping (@amit) now', Ldt::render('ping (@amit) now'));
check('@{ in text is literal (no interpolation form)', '@{x}', Ldt::render('@{x}'));
check('@( in text is literal (no inline-expression form)', '@(1 + 2)', Ldt::render('@(1 + 2)'));

// --- escapes -------------------------------------------------------------
check('escape [= to write it literally', '[= @x]', Ldt::render('[set x=hi]\[= @x]'));
check('escaped [= alongside a real one', '[= @x] = hi', Ldt::render('[set x=hi]\[= @x] = [= @x]'));
check('escape a literal tag opener', '[set a = 1]', Ldt::render('\[set a = 1]'));
check('escaped backslash then emit', '\\' . 'hi', Ldt::render('[set x=hi]\\\\[= @x]'));
check('backslash before a letter is literal (paths survive)', 'C:\\Users', Ldt::render('C:\\Users'));
check('escape closer inside a block value', 'a [/set] b', Ldt::render('[set v]a \[/set] b[/set][= @v]'));
check('escaped ] inside a self-closing value', 'a ] b', Ldt::render('[set v = a \] b][= @v]'));
check('escape closer inside a comment', 'ok', Ldt::render('[# a \#] b #]ok'));

// --- expressions: [= ...] arithmetic --------------------------------------
check('add', '5', Ldt::render('[= 2 + 3]'));
check('subtract', '7', Ldt::render('[= 10 - 3]'));
check('multiply', '20', Ldt::render('[= 4 * 5]'));
check('integer division truncates', '3', Ldt::render('[= 10 / 3]'));
check('modulo', '1', Ldt::render('[= 10 % 3]'));
check('precedence: * before +', '14', Ldt::render('[= 2 + 3 * 4]'));
check('parentheses override precedence', '20', Ldt::render('[= (2 + 3) * 4]'));
check('unary minus', '3', Ldt::render('[= -5 + 8]'));
check('negative result', '-2', Ldt::render('[= 3 - 5]'));
check('refs in an expression', '14', Ldt::render('[set a=10][set b=4][= @a + @b]'));
check('nested dot-path ref in expression', '30', Ldt::render('[set o.x=10][set o.y=20][= @o.x + @o.y]'));
check('expression splices mid-text', 'row-30-end', Ldt::render('[set n=3]row-[= @n * 10]-end'));
check('expression inside a [set] value', '11', Ldt::render('[set a=10][set b = [= @a + 1]][= @b]'));
check('counter increment in a loop', '1234', Ldt::render('[set i=0][for n in 1 to 4][set i = [= @i + 1]][= @i][/for]'));
check('expression in a loop body', '1 4 9 ', Ldt::render('[for n in 1 to 3][= @n * @n] [/for]'));

// --- expressions: comparison / logic render as 1 / 0 ----------------------
check('comparison true renders 1', '1', Ldt::render('[set a=5][= @a > 3]'));
check('comparison false renders 0', '0', Ldt::render('[set a=5][= @a > 9]'));
check('logic in an expression', '1', Ldt::render('[set a=1][= @a and 1]'));
check('quoted string literal in expression', 'y', Ldt::render('[set t=hi there][if @t == "hi there"]y[/if]'));

// --- expressions: escaping & errors --------------------------------------
check('escaped [= stays a literal expression', '[= 1 + 2]', Ldt::render('\[= 1 + 2]'));
throws('non-integer operand errors', fn () => Ldt::render('[set n=hi][= @n + 1]'));
throws('division by zero errors', fn () => Ldt::render('[= 5 / 0]'));
throws('modulo by zero errors', fn () => Ldt::render('[= 5 % 0]'));
throws('@{ } is not a reference form inside [= ]', fn () => Ldt::render('[set a=1][= @{a} + 1]'));
throws('unterminated [= ]', fn () => Ldt::render('[= 1 + 2'));
throws('empty [= ]', fn () => Ldt::render('[=]'));

// --- count: array length -------------------------------------------------
check('count in [= ] emits length', '3', Ldt::render('[set f.=a][set f.=b][set f.=c][= count @f]'));
check('count of undefined is 0', '0', Ldt::render('[= count @nope]'));
check('count of a keyed array', '2', Ldt::render('[set m.x=1][set m.y=2][= count @m]'));
check('count of a nested path', '2', Ldt::render('[set o.items.=x][set o.items.=y][= count @o.items]'));
check('count in arithmetic', '6', Ldt::render('[set f.=a][set f.=b][set f.=c][= count @f * 2]'));
check('count in a condition (non-empty)', 'yes', Ldt::render('[set f.=a][if count @f > 0]yes[/if]'));
check('count in a condition (empty)', 'empty', Ldt::render('[if count @f == 0]empty[/if]'));
check('count of a seeded array', '3', Ldt::render('[= count @items]', ['items' => ['a', 'b', 'c']]));
check('count in a loop body', '22', Ldt::render('[set p.=x][set p.=y][for n in 1 to 2][= count @p][/for]'));
throws('count of a scalar errors', fn () => Ldt::render('[set s=hi][= count @s]'));
throws('count of a scalar errors in a condition too', fn () => Ldt::render('[set s=hi][if count @s > 0]x[/if]'));
throws('count without a reference errors', fn () => Ldt::render('[= count 5]'));

// --- conditionals: basics (bare @name) -----------------------------------
check('if truthy renders body', 'yes', Ldt::render('[set a = x][if @a]yes[/if]'));
check('if falsy (empty) skips body', '', Ldt::render('[set a = ][if @a]yes[/if]'));
check('if undefined ref is falsy', '', Ldt::render('[if @missing]yes[/if]'));
check('else branch runs when false', 'no', Ldt::render('[if @missing]yes[else]no[/if]'));
throws('@{ } is not a reference form in a tag', fn () => Ldt::render('[set a = x][if @{a}]yes[/if]'));
check(
    'elseif chain picks matching branch',
    'editor',
    Ldt::render('[set role = editor][if @role == admin]admin[elseif @role == editor]editor[else]other[/if]')
);
check('body keeps leading space after ]', ' yes', Ldt::render('[set a = x][if @a] yes[/if]'));

// --- conditionals: operators, logic, dot-paths ---------------------------
check('equality string', 'match', Ldt::render('[set x = on][if @x == on]match[/if]'));
check('inequality', 'diff', Ldt::render('[set x = a][if @x != b]diff[/if]'));
check('numeric greater-than', 'big', Ldt::render('[set n = 42][if @n > 9]big[/if]'));
check('lexicographic compare for non-numeric', 'yes', Ldt::render('[if apple < banana]yes[/if]'));
check('and requires both', '', Ldt::render('[set a = 1][if @a and @b]x[/if]'));
check('or needs one', 'x', Ldt::render('[set a = 1][if @a or @b]x[/if]'));
check('not negates', 'x', Ldt::render('[if not @missing]x[/if]'));
check('parentheses group', 'x', Ldt::render('[set a = 1][if (@a or @b) and @a]x[/if]'));
check('quoted literal with spaces', 'hi', Ldt::render('[set t = hello world][if @t == "hello world"]hi[/if]'));
check('dot-path ref in a condition', 'y', Ldt::render('[set u.role = admin][if @u.role == admin]y[/if]'));

// --- conditionals: numbers & defined ------------------------------------
check('numeric equality: 5 == 5.0', 'y', Ldt::render('[set n = 5][if @n == 5.0]y[/if]'));
check('leading zeros: 007 == 7', 'y', Ldt::render('[set id = 007][if @id == 7]y[/if]'));
check('numeric zero is falsy', '', Ldt::render('[set z = 0][if @z]y[/if]'));
check('defined true', 'y', Ldt::render('[set a = 1][if defined @a]y[/if]'));
check('defined false', '', Ldt::render('[if defined @a]y[/if]'));
check('defined nested key', 'y', Ldt::render('[set c.k = v][if defined @c.k]y[/if]'));

// --- conditionals: substring operators ------------------------------------
check('contains true', 'y', Ldt::render('[set e=ada@x.com][if @e contains "@"]y[/if]'));
check('contains false', 'n', Ldt::render('[set e=ada][if @e contains "@"]y[else]n[/if]'));
check('starts with true', 'y', Ldt::render('[set n=Dr. Ada][if @n starts with "Dr."]y[/if]'));
check('starts with false', '', Ldt::render('[set n=Ada][if @n starts with "Dr."]y[/if]'));
check('ends with true', 'y', Ldt::render('[set f=report.pdf][if @f ends with ".pdf"]y[/if]'));
check('ends with is case-sensitive', '', Ldt::render('[set f=report.PDF][if @f ends with ".pdf"]y[/if]'));
check('not negates a substring test', 'y', Ldt::render('[set f=a.txt][if not @f ends with ".pdf"]y[/if]'));
check('substring test in [= ] renders 1/0', '1|0', Ldt::render('[set a=hello][= @a contains "ell"]|[= @a starts with "bye"]'));
check('refs on both sides', 'y', Ldt::render('[set a=hello world][set b=world][if @a contains @b]y[/if]'));
check('number text form: 007 contains 00', 'y', Ldt::render('[set id=007][if @id contains "00"]y[/if]'));
check('empty needle is always found', 'y', Ldt::render('[set a=x][if @a contains ""]y[/if]'));
check('contains stays a literal after ==', 'y', Ldt::render('[set x=contains][if @x == contains]y[/if]'));
check('starts stays a literal after ==', 'y', Ldt::render('[set y=starts][if @y == starts]y[/if]'));
check('combined with logic', 'y', Ldt::render('[set f=a.pdf][set n=Dr.X][if @f ends with ".pdf" and @n starts with "Dr."]y[/if]'));
throws('starts without with', fn () => Ldt::render('[if @a starts "x"]y[/if]'));
throws('ends without with', fn () => Ldt::render('[if @a ends "x"]y[/if]'));
throws('contains without a right side', fn () => Ldt::render('[if @a contains]y[/if]'));

// --- conditionals: nesting & side effects -------------------------------
check('nested if', 'deep', Ldt::render('[set a = 1][set b = 1][if @a][if @b]deep[/if][/if]'));
check('set inside untaken branch does not run', '', Ldt::render('[if @missing][set leaked = boom][/if][= @leaked]'));
check('standalone [if]/[/if] lines are trimmed', 'body' . $L, Ldt::render('[set a = 1][if @a]' . $L . 'body' . $L . '[/if]'));
throws('missing [/if]', fn () => Ldt::render('[if @a]yes'));
throws('stray [/if]', fn () => Ldt::render('[/if]'));
throws('empty condition', fn () => Ldt::render('[if ]x[/if]'));
throws('dangling operand in condition', fn () => Ldt::render('[if @a extra bogus]x[/if]'));

// --- loops: iteration (to / by ranges, @ refs) --------------------------
check('indexed array, two-var (key = index)', '(0=a)(1=c)', Ldt::render('[set b. = a][set b. = c][for i, v in @b]([= @i]=[= @v])[/for]'));
check('indexed array, one-var (value only)', '(a)(c)', Ldt::render('[set b. = a][set b. = c][for v in @b]([= @v])[/for]'));
check('keyed array, two-var (key = key)', '(x:1)(y:2)', Ldt::render('[set m.x = 1][set m.y = 2][for k, v in @m]([= @k]:[= @v])[/for]'));
check('iterate a nested path', 'pen|ink|', Ldt::render('[set order.items. = pen][set order.items. = ink][for it in @order.items][= @it]|[/for]'));
check(
    'loop value is a sub-array: [= @v.field] resolves',
    'Ada(1) Bob(2) ',
    Ldt::render(
        '[set u.0.name = Ada][set u.0.id = 1][set u.1.name = Bob][set u.1.id = 2]'
        . '[for row in @u][= @row.name]([= @row.id]) [/for]'
    )
);
throws('@{ } is not a reference form in a [for] header', fn () => Ldt::render('[set b. = a][set b. = c][for v in @{b}][= @v][/for]'));

// --- loops: ranges (to / by) --------------------------------------------
check('range ascending inclusive', '12345', Ldt::render('[for n in 1 to 5][= @n][/for]'));
check('range with step (by)', '135', Ldt::render('[for n in 1 to 5 by 2][= @n][/for]'));
check('range descending (inferred)', '321', Ldt::render('[for n in 3 to 1][= @n][/for]'));
check('range single element', '7', Ldt::render('[for n in 7 to 7][= @n][/for]'));
check('range dynamic end via @ref', '123', Ldt::render('[set n = 3][for i in 1 to @n][= @i][/for]'));
check('range dynamic START via @ref (fixes old limitation #2)', '345', Ldt::render('[set lo = 3][for i in @lo to 5][= @i][/for]'));
check('range both bounds and step as refs', '246', Ldt::render('[set lo=2][set hi=6][set st=2][for i in @lo to @hi by @st][= @i][/for]'));
check('range negative bounds', '0-1-2', Ldt::render('[for n in 0 to -2][= @n][/for]'));

// --- loops: break / continue --------------------------------------------
check('continue skips, break stops', '13', Ldt::render('[for n in 1 to 5][if @n == 2][continue][/if][if @n == 4][break][/if][= @n][/for]'));
check('output before continue is kept', 'a1aa3', Ldt::render('[for n in 1 to 3]a[if @n == 2][continue][/if][= @n][/for]'));

// --- loops: nesting & scope ---------------------------------------------
check('nested loops', '(11)(12)(21)(22)', Ldt::render('[for i in 1 to 2][for j in 1 to 2]([= @i][= @j])[/for][/for]'));
check('break binds to innermost loop', '1|1|', Ldt::render('[for i in 1 to 2][for j in 1 to 3][if @j == 2][break][/if][= @j][/for]|[/for]'));
check('body may set outer var (accumulator)', 'start-1-2-3', Ldt::render('[set acc = start][for n in 1 to 3][set acc = [= @acc]-[= @n]][/for][= @acc]'));
check('loop var unset after loop', '123|', Ldt::render('[for n in 1 to 3][= @n][/for]|[= @n]'));
check('loop var restored to prior value', 'OUTER', Ldt::render('[set i = OUTER][for i in 1 to 2][/for][= @i]'));
check('undefined iterable is zero iterations', '', Ldt::render('[for v in @nope]X[/for]'));

// --- loops: trimming -----------------------------------------------------
check(
    'standalone loop lines leave no blank lines',
    'Menu:' . $L . '  - apple' . $L . '  - banana' . $L . 'Done',
    Ldt::render(
        '[set f. = apple][set f. = banana]' . $L
        . 'Menu:' . $L
        . '[for i, name in @f]' . $L
        . '  - [= @name]' . $L
        . '[/for]' . $L
        . 'Done'
    )
);

// --- loops: errors -------------------------------------------------------
throws('scalar is not iterable', fn () => Ldt::render('[set s = hi][for v in @s]X[/for]'));
throws('missing [/for]', fn () => Ldt::render('[for v in 1 to 3]x'));
throws('stray [break]', fn () => Ldt::render('[break]'));
throws('stray [continue]', fn () => Ldt::render('[continue]'));
throws('range step must be positive', fn () => Ldt::render('[for n in 1 to 3 by 0]x[/for]'));
throws('duplicate loop vars', fn () => Ldt::render('[for x, x in 1 to 3]y[/for]'));
throws('integer with no "to" is not iterable', fn () => Ldt::render('[for n in 5]x[/for]'));
throws('non-integer range bound ref', fn () => Ldt::render('[set n = hi][for i in 1 to @n]x[/for]'));

// --- default for undefined: [= @path | default: ...] ----------------------
check('default used when undefined (bareword arg)', 'guest', Ldt::render('[= @name | default: guest]'));
check('quoted default allows spaces', 'no one', Ldt::render('[= @name | default: "no one"]'));
check('default ignored when defined', 'Ada', Ldt::render('[set name=Ada][= @name | default: guest]'));
check('default on a missing nested path', 'n/a', Ldt::render('[set u.first=Ada][= @u.last | default: "n/a"]'));
check('default catches an empty value (falsy rule)', 'fallback', Ldt::render('[set e=][= @e | default: fallback]'));
check('default suppresses strict error', 'x', Ldt::render('[= @missing | default: x]', strict: true));
check('default arg may be a variable', 'Backup', Ldt::render('[set fb = Backup][= @missing | default: @fb]'));

// --- unified falsy rule: booleans → 1/0, fallbacks on falsy ---------------
check('stored false flag prints 0', '0', Ldt::render('[set f = [= 1 > 2]][= @f]'));
check('false flag compares equal to 0', '1', Ldt::render('[= (1 > 2) == 0]'));
check('true flag compares equal to 1', '1', Ldt::render('[= (2 > 1) == 1]'));
check('default arg may be an expression', '30', Ldt::render('[set n=3][= @missing | default: @n * 10]'));
check('default catches a decimal zero', 'F', Ldt::render('[set z=0.0][= @z | default: "F"]'));
check('default catches a zero value', 'F', Ldt::render('[set z=0][= @z | default: "F"]'));
check('007 passes a fallback (it is 7)', '007', Ldt::render('[set id=007][= @id | default: "F"]'));
check('non-numeric 0x passes a fallback', '0x', Ldt::render('[set z=0x][= @z | default: "F"]'));
check('seeded false renders 0', '0', Ldt::render('[= @b]', ['b' => false]));
check('seeded false triggers default', 'F', Ldt::render('[= @b | default: "F"]', ['b' => false]));
check('seeded null triggers default', 'F', Ldt::render('[= @n | default: "F"]', ['n' => null]));
check('loop.first prints 1/0', '10', Ldt::render('[for n in 1 to 2][= @loop.first][/for]'));
check('loop.last prints 0/1', '01', Ldt::render('[for n in 1 to 2][= @loop.last][/for]'));
check('default catches a false comparison result', 'no', Ldt::render('[set age=15][= @age >= 18 | default: "no"]'));
check('non-empty array passes default into join', 'x', Ldt::render('[set a.=x][= @a | default: "F" | join]'));
check('strict: defined zero is fine without fallback', '0', Ldt::render('[set z=0][= @z]', strict: true));
check('default cascades through a chain', 'last', Ldt::render('[set fb = ][= @missing | default: @fb | default: "last"]'));

// --- feed data: seeded context -------------------------------------------
check('seeded scalar', 'Acme', Ldt::render('[= @site]', ['site' => 'Acme']));
check('seeded nested path', 'Ada Lovelace', Ldt::render('[= @u.first] [= @u.last]', ['u' => ['first' => 'Ada', 'last' => 'Lovelace']]));
check('seeded int stringified', '3', Ldt::render('[= @n]', ['n' => 3]));
check('seeded bool true is "1"', 'on', Ldt::render('[if @b]on[/if]', ['b' => true]));
check('seeded bool false is falsy', '', Ldt::render('[if @b]on[/if]', ['b' => false]));
check('iterate a seeded array', 'a|b|c|', Ldt::render('[for x in @items][= @x]|[/for]', ['items' => ['a', 'b', 'c']]));
check('inline [set] overrides seeded value', 'inline', Ldt::render('[set x=inline][= @x]', ['x' => 'seeded']));
check('seeded value used before override', 'seeded', Ldt::render('[= @x]', ['x' => 'seeded']));

// --- loop metadata: [= @loop.*] --------------------------------------------
check('loop.index is 1-based', '123', Ldt::render('[for n in 1 to 3][= @loop.index][/for]'));
check('loop.index0 is 0-based', '012', Ldt::render('[for n in 1 to 3][= @loop.index0][/for]'));
check('loop.count is the total', '3-3-3-', Ldt::render('[for n in 1 to 3][= @loop.count]-[/for]'));
check('loop.first flags the first', 'F..', Ldt::render('[for n in 1 to 3][if @loop.first]F[else].[/if][/for]'));
check('loop.last flags the last', 'xxL', Ldt::render('[for n in 1 to 3][if @loop.last]L[else]x[/if][/for]'));
check(
    'loop.last drives a comma separator',
    'a, b, c',
    Ldt::render('[set f.=a][set f.=b][set f.=c][for v in @f][= @v][if not @loop.last], [/if][/for]')
);
check(
    'nested loops each get their own loop metadata',
    '(1:1)(1:2)(2:1)(2:2)',
    Ldt::render('[for a in 1 to 2][for b in 1 to 2]([= @a]:[= @loop.index])[/for][/for]')
);
check('loop metadata unset after the loop', 'in|', Ldt::render('[for n in 1 to 1]in[/for]|[= @loop.index]'));

// --- filters: strings ------------------------------------------------------
check('upper', 'ADA', Ldt::render('[set n=ada][= @n | upper]'));
check('lower', 'ada', Ldt::render('[set n=ADA][= @n | lower]'));
check('trim', 'hi', Ldt::render('[set s]  hi  [/set][= @s | trim]'));
check('capitalize', 'Ada', Ldt::render('[set n=ada][= @n | capitalize]'));
check('chain runs left to right', 'HI', Ldt::render('[set s]  hi  [/set][= @s | trim | upper]'));
check('truncate cuts with suffix', 'hello…', Ldt::render('[set s=hello world][= @s | truncate: 5, "…"]'));
check('truncate leaves short values', 'hi', Ldt::render('[set s=hi][= @s | truncate: 5, "…"]'));

// --- filters: arrays --------------------------------------------------------
check('join with separator', 'a, b, c', Ldt::render('[set i.=a][set i.=b][set i.=c][= @i | join: ", "]'));
check('join default separator is empty', 'abc', Ldt::render('[set i.=a][set i.=b][set i.=c][= @i | join]'));
check('first', 'a', Ldt::render('[set i.=a][set i.=b][= @i | first]'));
check('last', 'b', Ldt::render('[set i.=a][set i.=b][= @i | last]'));
check('first then string filter chains', 'A', Ldt::render('[set i.=a][set i.=b][= @i | first | upper]'));
check('first of nested rows then join', 'x-y', Ldt::render('[= @rows | first | join: "-"]', ['rows' => [['x', 'y'], ['z']]]));
check('first of empty array is empty', '', Ldt::render('[= @e | first]', ['e' => []]));

// --- filters: numbers & html -----------------------------------------------
check('round to decimals', '3.14', Ldt::render('[set pi=3.14159][= @pi | round: 2]'));
check('round default is 0 decimals', '3', Ldt::render('[set pi=3.14159][= @pi | round]'));
check('abs', '7', Ldt::render('[set t=-7][= @t | abs]'));
check('html escapes', '&lt;b&gt; &amp; &quot;q&quot;', Ldt::render('[set h=<b> & "q"][= @h | html]'));

// --- filters: default --------------------------------------------------------
check('default on undefined', 'guest', Ldt::render('[= @missing | default: "guest"]'));
check('default on empty string', 'empty!', Ldt::render('[set e=][= @e | default: "empty!"]'));
check('default on empty array', 'none', Ldt::render('[= @a | default: "none"]', ['a' => []]));
check('default passes a set value', 'Ada', Ldt::render('[set n=Ada][= @n | default: "guest"]'));
check('default suppresses strict', 'g', Ldt::render('[= @missing | default: "g"]', strict: true));
check('default feeds later filters', 'ADA', Ldt::render('[= @missing | default: "ada" | upper]'));
check('quoted default arg may contain a pipe', 'a|b', Ldt::render('[= @missing | default: "a|b"]'));

// --- filters: on computed expressions ----------------------------------------
check('filter on arithmetic', '6', Ldt::render('[= 2 * 3 | abs]'));
check('filter on a negative result', '7', Ldt::render('[= 0 - 7 | abs]'));
check('filter on count', '3', Ldt::render('[set i.=a][set i.=b][set i.=c][= count @i | round]'));
check('expression as a filter arg', 'hello …', Ldt::render('[set w=5][set s=hello world][= @s | truncate: @w + 1, "…"]'));
check('ref as a filter arg', 'a-b', Ldt::render('[set sep=-][set i.=a][set i.=b][= @i | join: @sep]'));
check('array ref filtered then joined', 'a+b', Ldt::render('[set i.=a][set i.=b][= @i | join: "+"]'));

// --- filters: pipes elsewhere stay literal -----------------------------------
check('| in plain text is literal', 'a | b', Ldt::render('a | b'));

// --- filters: errors ----------------------------------------------------------
throws('unknown filter', fn () => Ldt::render('[= @a | bogus]'));
throws('string filter on an array', fn () => Ldt::render('[set i.=x][= @i | upper]'));
throws('array filter on a scalar', fn () => Ldt::render('[set s=hi][= @s | join: ","]'));
throws('round on a non-number', fn () => Ldt::render('[set s=hi][= @s | round]'));
throws('filter in a tag condition', fn () => Ldt::render('[if @a | upper]x[/if]'));
throws('missing filter name after |', fn () => Ldt::render('[= @a |]'));
throws('truncate without a length', fn () => Ldt::render('[= @a | truncate]'));
throws('default without a fallback', fn () => Ldt::render('[= @a | default]'));
throws('array surviving the chain cannot render', fn () => Ldt::render('[set i.=x][= @i | default: "y"]'));
throws('strict still errors without a default filter', fn () => Ldt::render('[= @missing | upper]', strict: true));

// --- general errors ------------------------------------------------------
throws('unterminated block set', fn () => Ldt::render('[set a]oops'));
throws('unterminated self-closing set', fn () => Ldt::render('[set a = oops'));
throws('unterminated emit', fn () => Ldt::render('[= @a'));
throws('unterminated comment', fn () => Ldt::render('[# oops'));
throws('missing name after [set', fn () => Ldt::render('[set ]x[/set]'));
throws('trailing dot in a reference is invalid', fn () => Ldt::render('[= @a.]'));
throws('empty middle segment is invalid', fn () => Ldt::render('[set a..b = x]'));
throws('descending through a scalar is a conflict', fn () => Ldt::render('[set a = x][set a.b = y]'));

// --- arrays are truthy when non-empty ([if] and default: agree) -----------
check('non-empty array is truthy in [if]', 'YES', Ldt::render('[if @items]YES[else]NO[/if]', ['items' => ['a', 'b']]));
check('empty array is falsy in [if]', 'NO', Ldt::render('[if @items]YES[else]NO[/if]', ['items' => []]));
check('undefined stays falsy in [if]', 'NO', Ldt::render('[if @items]YES[else]NO[/if]'));
check('non-empty array under not', 'NO', Ldt::render('[if not @items]YES[else]NO[/if]', ['items' => ['a']]));
check('non-empty array in and/or', '1', Ldt::render('[= @items and 1]', ['items' => ['a']]));
check('inline-built array is truthy', 'YES', Ldt::render('[set i.=x][if @i]YES[else]NO[/if]'));
check('array in a comparison still reads empty', 'EQ', Ldt::render('[if @items == ""]EQ[else]NE[/if]', ['items' => ['a']]));

// --- CRLF is expression whitespace too -------------------------------------
check('CRLF multiline [if] condition', 'T', Ldt::render("[if @x == yes\r\nand 1]T[else]F[/if]", ['x' => 'yes']));
check('CRLF after the [if keyword', 'T', Ldt::render("[if\r\n@x]T[else]F[/if]", ['x' => 'y']));
check('CRLF inside [= ]', '3', Ldt::render("[= 1 +\r\n2]"));

// --- marker tags accept whitespace before ] ---------------------------------
check('[else ] with a space', 'b', Ldt::render('[if 0]a[else ]b[/if]'));
check('[break ] with a space', '1', Ldt::render('[for n in 1 to 3][= @n][break ][/for]'));
check('[continue ] with a space', '', Ldt::render('[for n in 1 to 3][continue ][= @n][/for]'));
check('[/if ] and [/for ] with spaces', '12', Ldt::render('[for n in 1 to 2][if 1][= @n][/if ][/for ]'));
check('marker whitespace may be a newline', 'b', Ldt::render("[if 0]a[else\n]b[/if]"));
check('[else x] stays literal', 'a[else x]b', Ldt::render('[if 1]a[else x]b[/if]'));
check('escaped [else ] stays literal', '[else ]', Ldt::render('\\[else ]'));

// --- exact coordinates: multi-line headers, [elseif] conditions -------------
errorAt('parse error on [if] header line 2', 2, 4, fn () => Ldt::render("[if @a\n   ~bogus]x[/if]"));
errorAt('for-header error on line 2', 2, 4, fn () => Ldt::render("[for n\nin ~q]x[/for]"));
errorAt('elseif runtime error points at the [elseif]', 2, 1, fn () => Ldt::render("[if 0]a\n[elseif count @s]b[/if]", ['s' => 'x']));
errorAt('multi-line header inside a block value', 2, 2, fn () => Ldt::render("x[set v][if @a\n ~bogus]y[/if][/set]"));

// --- error messages name a leftover reference --------------------------------
$msg = '';
try {
    Ldt::render('[if @a @b]x[/if]');
} catch (\Ldtlang\SyntaxError $e) {
    $msg = $e->getMessage();
}
check('unexpected ref is named in the message', '1', str_contains($msg, "unexpected '@b'") ? '1' : $msg);

// --- a leading + is part of the Number text form -----------------------------
check('+5 compares numerically in a tag', 'EQ', Ldt::render('[if @x == +5]EQ[else]NE[/if]', ['x' => '5']));
check('"+5" equals 5.0 in [= ]', '1', Ldt::render('[= "+5" == 5.0]'));
check('+ sign is preserved on output', '+5|6', Ldt::render('[set p = +5][= @p]|[= @p + 1]'));
check('+0 is falsy (a numeric zero)', 'NO', Ldt::render('[if @z]YES[else]NO[/if]', ['z' => '+0']));
check('round accepts a +number', '5', Ldt::render('[= @p | round]', ['p' => '+5.4']));
check('range bounds accept +N and +refs', '123', Ldt::render('[for n in +1 to @hi][= @n][/for]', ['hi' => '+3']));

// --- seeded floats must be finite ---------------------------------------------
throws('seeded INF is rejected', fn () => Ldt::render('[= @p]', ['p' => INF]));
throws('seeded NAN is rejected', fn () => Ldt::render('[= @p]', ['p' => NAN]));

// --- filter args: raw references, lazy default ---------------------------------
check('array fallback via default:', 'a,b', Ldt::render('[= @missing | default: @items | join: ","]', ['items' => ['a', 'b']]));
check('default arg is lazy when value is truthy', 'set', Ldt::render('[= @x | default: @a / @b]', ['x' => 'set', 'a' => '1', 'b' => '0']));
throws('array arg to a scalar-expecting filter', fn () => Ldt::render('[= @x | truncate: @arr]', ['x' => 'hello', 'arr' => ['a']]));
throws('array separator for join', fn () => Ldt::render('[= @items | join: @items]', ['items' => ['a', 'b']]));

// --- pointed messages for a misplaced [else]/[elseif] ---------------------------
$msg = '';
try {
    Ldt::render('[if 1]a[else]b[else]c[/if]');
} catch (\Ldtlang\SyntaxError $e) {
    $msg = $e->getMessage();
}
check('duplicate [else] message', '1', str_contains($msg, 'duplicate [else]') ? '1' : $msg);
$msg = '';
try {
    Ldt::render('[if 1]a[else]b[elseif 1]c[/if]');
} catch (\Ldtlang\SyntaxError $e) {
    $msg = $e->getMessage();
}
check('[elseif] after [else] message', '1', str_contains($msg, 'cannot follow [else]') ? '1' : $msg);

// --- renderFile strips a leading UTF-8 BOM --------------------------------------
$bomFile = tempnam(sys_get_temp_dir(), 'ldt');
file_put_contents($bomFile, "\xEF\xBB\xBFx[= @a]");
check('renderFile strips a leading UTF-8 BOM', 'x1', Ldt::renderFile($bomFile, ['a' => '1']));
unlink($bomFile);

// --- a backslash before end-of-line is itself literal -------------------------
check('backslash before a newline stays literal', "a\\\nb", Ldt::render("a\\\nb"));
check('backslash before CRLF stays literal', "a\\\r\nb", Ldt::render("a\\\r\nb"));
check('expression string keeps backslash before a newline', 'Y', Ldt::render("[if @x == \"a\\\nb\"]Y[else]N[/if]", ['x' => "a\\\nb"]));

// --- loop is reserved as a [for] variable name ----------------------------------
errorAt('[for loop in ...] is rejected', 1, 6, fn () => Ldt::render('[for loop in @i]x[/for]', ['i' => ['a']]));
errorAt('[for k, loop in ...] is rejected', 1, 9, fn () => Ldt::render('[for k, loop in @i]x[/for]', ['i' => ['a']]));
check('a user variable named loop still shadows/restores', '12mine', Ldt::render('[set loop = mine][for n in 1 to 2][= @loop.index][/for][= @loop]'));

// --- truncate counts bytes but never splits UTF-8 --------------------------------
check('truncate drops an incomplete multibyte tail', 'h~', Ldt::render('[= @s | truncate: 2, "~"]', ['s' => 'héllo']));
check('truncate keeps a complete multibyte char at the cut', 'hé', Ldt::render('[= @s | truncate: 3]', ['s' => 'héllo']));
check('truncate drops an incomplete 4-byte emoji', '', Ldt::render('[= @s | truncate: 3]', ['s' => "\u{1F600}x"]));
check('truncate ascii is unchanged', 'he~', Ldt::render('[= @s | truncate: 2, "~"]', ['s' => 'hello']));

// --- range bounds are overflow-checked; ranges stream lazily ---------------------
errorAt('literal range bound past PHP_INT_MAX is rejected', 1, 11, fn () =>
    Ldt::render('[for n in 9223372036854775810 to 9223372036854775811]x[/for]'));
$msg = '';
try {
    Ldt::render('[for n in 1 to @b]x[/for]', ['b' => '99999999999999999999']);
} catch (\Ldtlang\SyntaxError $e) {
    $msg = $e->getMessage();
}
check('ref range bound past PHP_INT_MAX is rejected', '1', str_contains($msg, 'out of the integer range') ? '1' : $msg);
check(
    'range ending at PHP_INT_MAX terminates',
    '9223372036854775806,9223372036854775807,',
    Ldt::render('[for n in 9223372036854775806 to 9223372036854775807][= @n],[/for]'),
);
check(
    'range ending at PHP_INT_MIN terminates',
    '-9223372036854775807,-9223372036854775808,',
    Ldt::render('[for n in -9223372036854775807 to -9223372036854775808][= @n],[/for]'),
);
check(
    'ranges stream lazily (huge range + break)',
    'done',
    Ldt::render('[for n in 1 to 9000000000000000000][break][/for]done'),
);
check(
    'loop metadata intact on streamed ranges',
    '1/3 2/3 3/3=last ',
    Ldt::render('[for n in 5 to 7][= @loop.index]/[= @loop.count][if @loop.last]=last[/if] [/for]'),
);

// --- a closer of the wrong kind names the block it interrupts --------------------
$msg = '';
try {
    Ldt::render('[if 1]a[/for]');
} catch (\Ldtlang\SyntaxError $e) {
    $msg = $e->getMessage();
}
check('[/for] inside [if] names the open block', '1', str_contains($msg, 'inside [if] opened at 1:1') ? '1' : $msg);
$msg = '';
try {
    Ldt::render('x[for n in 1 to 2]a[/if]');
} catch (\Ldtlang\SyntaxError $e) {
    $msg = $e->getMessage();
}
check('[/if] inside [for] names the open block', '1', str_contains($msg, 'inside [for] opened at 1:2') ? '1' : $msg);
$msg = '';
try {
    Ldt::render('a[/if]');
} catch (\Ldtlang\SyntaxError $e) {
    $msg = $e->getMessage();
}
check('top-level stray closer keeps the plain message', '1', str_contains($msg, 'without matching opener') ? '1' : $msg);

// --- the trimmer keeps exact TEXT coordinates -------------------------------------
$toks = \Ldtlang\Trimmer::trim(\Ldtlang\Lexer::tokenize("X[set a = 1]\nY"));
$last = end($toks);
check('post-trim TEXT keeps its coordinates', 'TEXT@1:13', "{$last->type}@{$last->line}:{$last->col}");

fwrite(STDOUT, "\n$pass passed, $fail failed\n");
exit($fail === 0 ? 0 : 1);
