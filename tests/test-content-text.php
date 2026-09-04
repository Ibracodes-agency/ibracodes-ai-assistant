<?php
require_once __DIR__ . '/lib.php';

use WSA\Content;

$id = wsa_make_post('Warranty', "<!-- wp:paragraph --><p>Every camera carries a <strong>three year</strong> warranty.</p><!-- /wp:paragraph -->\n[gallery]\n<script>alert(1)</script><p>Installation is included in Tel Aviv.</p>");
$text = Content::text_for($id);
wsa_assert(str_contains($text, 'three year warranty'), 'blocks rendered and tags stripped');
wsa_assert(! str_contains($text, '<'), 'no markup survives');
wsa_assert(! str_contains($text, 'alert(1)'), 'script bodies removed, not just their tags');
wsa_assert(str_contains($text, 'Installation is included'), 'later paragraphs kept');

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
