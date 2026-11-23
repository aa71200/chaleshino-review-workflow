<?php
declare(strict_types=1);

namespace Chaleshino\CallReview;

require __DIR__ . '/bootstrap.php';
// This capability is defined by the full plugin bootstrap.
const MANAGE = 'chlr_manage_calls';

$src = dirname(__DIR__) . '/src';
require "$src/class-store.php";
require "$src/class-workflow.php";
Store::activate();
global $wpdb;
$tables = Store::tables();
$admin = \portfolio_user('administrator');
$judge = \portfolio_user();
$neverAssigned = \portfolio_user();
wp_set_current_user($admin);

function insert_fixture(string $table, array $row): int {
    global $wpdb;
    if (false === $wpdb->insert($table, $row)) {
        throw new \RuntimeException('Could not insert the synthetic fixture.');
    }
    return (int) $wpdb->insert_id;
}

$callId = insert_fixture($tables['calls'], [
    'title' => 'Demo poster call',
    'instructions' => '',
    'opens_at' => gmdate('Y-m-d H:i:s', time() - 7200),
    'submissions_end' => gmdate('Y-m-d H:i:s', time() - 3600),
    'judging_end' => gmdate('Y-m-d H:i:s', time() + 3600),
    'is_public' => 1,
]);

$entries = [];
foreach ([1, 2, 3] as $number) {
    $entryId = insert_fixture($tables['entries'], [
        'call_id' => $callId, 'owner_id' => \portfolio_user(), 'receipt' => bin2hex(random_bytes(12)),
        'title' => "Demo entry $number", 'description' => '',
        'file_key' => '', 'file_mime' => '', 'file_bytes' => 0, 'status' => 'approved',
        'reason' => '', 'submitted_at' => Workflow::now(),
    ]);
    if (false === $wpdb->insert($tables['assignments'], [
        'entry_id' => $entryId, 'judge_id' => $judge, 'assigned_by' => $admin, 'updated_at' => Workflow::now(),
    ])) {
        throw new \RuntimeException('Could not assign the demo judge.');
    }
    $entries[] = $entryId;
}

wp_set_current_user($neverAssigned);
\portfolio_check(is_wp_error(Workflow::score($entries[0], ['score' => '88'])), 'A judge without an assignment cannot score the entry');
wp_set_current_user($judge);
\portfolio_check(is_wp_error(Workflow::score($entries[0], ['score' => '100.01'])), 'Scores above 100 are rejected');
\portfolio_check(is_wp_error(Workflow::score($entries[0], ['score' => '91.125'])), 'Scores with three decimal places are rejected');
foreach ([$entries[0] => '۹۱٫۲۵', $entries[1] => '91.25'] as $entryId => $score) {
    if (is_wp_error(Workflow::score($entryId, ['score' => $score]))) {
        throw new \RuntimeException('The assigned judge could not save a valid score.');
    }
}
$incomplete = Workflow::ranking($callId);
\portfolio_check(!is_wp_error($incomplete) && $incomplete['incomplete'] === 1 && array_column($incomplete['rows'], 'rank') === [1, 1], 'Persian digits are normalised and matching scores tie, with one entry still incomplete');
if (is_wp_error(Workflow::score($entries[2], ['score' => '85']))) {
    throw new \RuntimeException('Could not complete the third score.');
}
$final = Workflow::ranking($callId);
\portfolio_check(!is_wp_error($final) && $final['incomplete'] === 0 && array_column($final['rows'], 'rank') === [1, 1, 3], 'Complete scoring produces competition ranks 1, 1 and 3');

wp_set_current_user($admin);
if (false === $wpdb->update($tables['calls'], ['judging_end' => gmdate('Y-m-d H:i:s', time() - 1)], ['id' => $callId])) {
    throw new \RuntimeException('Could not close the demo judging window.');
}
$stale = Workflow::results($callId, 'publish', Workflow::fingerprint($incomplete));
\portfolio_check(is_wp_error($stale) && $stale->get_error_data()['status'] === 409, 'A stale preview cannot be published');
$wpdb->update($tables['assignments'], ['score' => null], ['entry_id' => $entries[2], 'judge_id' => $judge]);
$incomplete = Workflow::ranking($callId);
$blocked = Workflow::results($callId, 'publish', Workflow::fingerprint($incomplete));
\portfolio_check(is_wp_error($blocked) && $blocked->get_error_data()['status'] === 409, 'An incomplete ranking cannot be published even with a matching preview');
$wpdb->update($tables['assignments'], ['score' => '85.00'], ['entry_id' => $entries[2], 'judge_id' => $judge]);
wp_set_current_user($judge);
$unauthorized = Workflow::results($callId, 'publish', Workflow::fingerprint($final));
\portfolio_check(is_wp_error($unauthorized) && $unauthorized->get_error_data()['status'] === 403 && 0 === (int) Store::call($callId)->result_revision, 'An assigned judge cannot publish a result');
wp_set_current_user($admin);
$result = Workflow::results($callId, 'publish', Workflow::fingerprint($final));
$public = Workflow::published(Store::call($callId));
\portfolio_check(!is_wp_error($result) && $result['revision'] === 1 && $public === $final['rows'], 'The current reviewed ranking is saved as the published snapshot');
wp_set_current_user($judge);
$unauthorized = Workflow::results($callId, 'revoke', '');
\portfolio_check(is_wp_error($unauthorized) && $unauthorized->get_error_data()['status'] === 403 && $public === Workflow::published(Store::call($callId)), 'An assigned judge cannot revoke a published result');
wp_set_current_user($admin);
$revoked = Workflow::results($callId, 'revoke', '');
$audit = $wpdb->get_row($wpdb->prepare('SELECT revoked_by, revoked_at FROM %i WHERE call_id=%d AND revision=1', $tables['results'], $callId));
\portfolio_check(!is_wp_error($revoked) && [] === Workflow::published(Store::call($callId)) && $admin === (int) $audit->revoked_by && !empty($audit->revoked_at), 'Revocation removes the public result and records the actor and time');
$republished = Workflow::results($callId, 'publish', Workflow::fingerprint($final));
\portfolio_check(!is_wp_error($republished) && 2 === $republished['revision'] && $public === Workflow::published(Store::call($callId)), 'Republishing creates the next revision');
