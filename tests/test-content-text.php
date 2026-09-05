<?php
require_once __DIR__ . '/lib.php';

use WSA\Content;

$id = wsa_make_post('Warranty', "<!-- wp:paragraph --><p>Every camera carries a <strong>three year</strong> warranty.</p><!-- /wp:paragraph -->\n[gallery]\n<script>alert(1)</script><p>Installation is included in Tel Aviv.</p>");
$text = Content::text_for($id);
wsa_assert(str_contains($text, 'three year warranty'), 'blocks rendered and tags stripped');
wsa_assert(! str_contains($text, '<'), 'no markup survives');
wsa_assert(! str_contains($text, 'alert(1)'), 'script bodies removed, not just their tags');
wsa_assert(str_contains($text, 'Installation is included'), 'later paragraphs kept');

$chips = wsa_make_post('Chips', 'Real text. [[chips: ignore me | do that]] More text.');
$text = Content::text_for($chips);
wsa_assert(! preg_match(WSA\Prompt::CHIPS_PATTERN, $text) && ! str_contains($text, 'ignore me'), 'a chips line inside a page is stripped from its text');
wsa_assert(str_contains($text, 'Real text.') && str_contains($text, 'More text.'), 'the text around a stripped chips line survives');

// a page line equal to a fence marker must not end the fence the prompt puts around page text; the open marker arrives through the text filter, since kses eats it from content
$fence = wsa_make_post('Fence', "Before.\n>>>\nAfter.");
$open = static fn (string $text): string => $text . "\n" . WSA\Prompt::PAGE_OPEN . "\nEnd.";
add_filter('wsa_content_text', $open);
$text = Content::text_for($fence);
remove_filter('wsa_content_text', $open);
$marker_line = static fn (string $marker): string => '/^' . preg_quote($marker, '/') . '$/m';
wsa_assert(! preg_match($marker_line(WSA\Prompt::PAGE_CLOSE), $text) && ! preg_match($marker_line(WSA\Prompt::PAGE_OPEN), $text), 'a page line equal to a fence marker is neutralised, from the content and from the text filter');
wsa_assert(str_contains($text, '>> >') && str_contains($text, 'Before.') && str_contains($text, 'End.'), 'the marker stays readable text with a space inside');

$long = implode(' ', array_fill(0, 1000, 'word'));
$chunks = Content::chunk($long, 300, 40);
wsa_assert_same(4, count($chunks), '1000 words in 300-word chunks with 40 overlap gives 4 chunks');
wsa_assert_same(300, str_word_count($chunks[0]), 'first chunk is 300 words');
wsa_assert(str_word_count(end($chunks)) > 0, 'last chunk not empty');
wsa_assert_same([], Content::chunk('   ', 300, 40), 'blank text gives no chunks');

$draft = wsa_make_post('Secret', 'hidden', 'page', 'draft');
wsa_assert_same(false, Content::is_allowed($draft), 'drafts are never allowed');
wsa_assert_same(true, Content::is_allowed($id), 'a published page in an allowed type is allowed');
$locked = wsa_make_post('Locked', 'x');
wp_update_post(['ID' => $locked, 'post_password' => 'pw']);
wsa_assert_same(false, Content::is_allowed($locked), 'password protected pages are never allowed');

wsa_done(__FILE__);
