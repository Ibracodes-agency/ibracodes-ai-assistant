<?php
require_once __DIR__ . '/lib.php';

use Ibracodes\AI_Assistant\Settings;

$snapshot = Settings::all();

$d = Settings::defaults();
ibraai_assert_same(['page', 'post'], $d['content_post_types'], 'content types default to pages and posts');
ibraai_assert_same('all', $d['content_scope'], 'scope defaults to all published');
ibraai_assert_same('search', $d['retrieval'], 'retrieval defaults to search');
ibraai_assert_same(false, $d['leads_enabled'], 'leads off by default');
ibraai_assert_same(180, $d['leads_retention_days'], 'lead retention 180 days');

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
ibraai_assert_same(['page'], $saved['content_post_types'], 'unknown post types dropped');
ibraai_assert_same('selected', $saved['content_scope'], 'scope accepted');
ibraai_assert_same([5], $saved['content_pages'], 'page ids cleaned and deduplicated');
ibraai_assert_same('embeddings', $saved['retrieval'], 'retrieval accepted');
ibraai_assert_same(true, $saved['leads_enabled'], 'leads toggle cast');
ibraai_assert_same(get_option('admin_email'), $saved['leads_email'], 'bad lead email falls back to the admin email');
ibraai_assert_same(365, $saved['leads_retention_days'], 'lead retention capped at 365');
ibraai_assert_same('when someone wants a quote', $saved['leads_when'], 'leads_when stripped of tags');
ibraai_assert_same('Your details go to the owner', $saved['privacy_note'], 'privacy note stripped of tags');

// ---- a malformed submission posts an array where a scalar belongs
$before = Settings::all();
$raised = [];
set_error_handler(static function (int $level, string $message) use (&$raised): bool {
    if ($level & (E_WARNING | E_NOTICE | E_USER_WARNING)) {
        $raised[] = $message;
    }

    return true;
});
try {
    $saved = Settings::update([
        'model' => ['gpt-5'],
        'title' => ['y'],
        'store_facts' => ['a', 'b'],
    ]);
    $threw = '';
} catch (Throwable $e) {
    $threw = get_class($e) . ': ' . $e->getMessage();
    $saved = Settings::all();
} finally {
    restore_error_handler();
}
ibraai_assert_same('', $threw, 'an array on a scalar field raises nothing');
ibraai_assert_same([], $raised, 'an array on a scalar field warns about nothing');
ibraai_assert_same($before['model'], $saved['model'], 'the stored model survives an array posted for it');
ibraai_assert_same($before['title'], $saved['title'], 'the stored title survives an array posted for it');
ibraai_assert_same($before['store_facts'], $saved['store_facts'], 'the stored store facts survive an array posted for them');

// the three list fields are the ones that do take an array
$saved = Settings::update(['content_post_types' => ['page', 'post']]);
ibraai_assert_same(['page', 'post'], $saved['content_post_types'], 'the list fields still take a list');

Settings::update($snapshot);
ibraai_done(__FILE__);
