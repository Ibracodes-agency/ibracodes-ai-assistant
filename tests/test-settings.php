<?php
require_once __DIR__ . '/lib.php';

use WSA\Settings;

$snapshot = Settings::all();

$d = Settings::defaults();
wsa_assert_same(['page', 'post'], $d['content_post_types'], 'content types default to pages and posts');
wsa_assert_same('all', $d['content_scope'], 'scope defaults to all published');
wsa_assert_same('search', $d['retrieval'], 'retrieval defaults to search');
wsa_assert_same(false, $d['leads_enabled'], 'leads off by default');
wsa_assert_same(180, $d['leads_retention_days'], 'lead retention 180 days');

$saved = Settings::update([
    'content_post_types' => ['page', 'nonexistent_type', '<script>'],
    'content_scope' => 'selected',
    'content_pages' => ['5', 'abc', 5, 0],
    'retrieval' => 'embeddings',
    'leads_enabled' => '1',
    'leads_email' => 'not an email',
    'leads_retention_days' => 9999,
    'leads_when' => "when <b>someone</b> wants a quote",
    'privacy_note' => '<em>Your details</em> go to the owner',
]);
wsa_assert_same(['page'], $saved['content_post_types'], 'unknown post types dropped');
wsa_assert_same('selected', $saved['content_scope'], 'scope accepted');
wsa_assert_same([5], $saved['content_pages'], 'page ids cleaned and deduplicated');
wsa_assert_same('embeddings', $saved['retrieval'], 'retrieval accepted');
wsa_assert_same(true, $saved['leads_enabled'], 'leads toggle cast');
wsa_assert_same(get_option('admin_email'), $saved['leads_email'], 'bad lead email falls back to the admin email');
wsa_assert_same(365, $saved['leads_retention_days'], 'lead retention capped at 365');
wsa_assert_same('when someone wants a quote', $saved['leads_when'], 'leads_when stripped of tags');
wsa_assert_same('Your details go to the owner', $saved['privacy_note'], 'privacy note stripped of tags');

Settings::update($snapshot);
wsa_done(__FILE__);
