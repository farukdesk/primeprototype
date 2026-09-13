<?php
/**
 * SS Portal – lookup data from GET /admin/api/v1/reference-data.php,
 * cached in ssp_cache for pu_api.reference_cache_ttl seconds.
 */

require_once __DIR__ . '/pu_api_client.php';

/**
 * @return array{data: ?array, fetched_at: ?string, error: ?string, from_cache: bool}
 */
function ssp_reference_data(bool $force = false): array
{
    static $memo = null;
    if ($memo !== null && !$force) {
        return $memo;
    }

    $ttl    = (int)ssp_config('pu_api.reference_cache_ttl', 21600);
    $db     = ssp_db();
    $cached = null;
    $row    = null;

    $st = $db->prepare('SELECT v, updated_at FROM ssp_cache WHERE k = ? LIMIT 1');
    $st->execute(['reference_data']);
    $row = $st->fetch() ?: null;
    if ($row) {
        $cached = json_decode((string)$row['v'], true);
        if (!is_array($cached)) {
            $cached = null;
        }
        if (!$force && $cached !== null && strtotime((string)$row['updated_at']) + $ttl > time()) {
            return $memo = ['data' => $cached, 'fetched_at' => $row['updated_at'], 'error' => null, 'from_cache' => true];
        }
    }

    if (!ssp_api()->isConfigured()) {
        return $memo = ['data' => $cached, 'fetched_at' => $row['updated_at'] ?? null,
            'error' => 'PU API key is not configured (config.php → pu_api.api_key).', 'from_cache' => true];
    }

    $resp = ssp_api()->referenceData();
    if ($resp['ok'] && is_array($resp['body']['data'] ?? null)) {
        $data = $resp['body']['data'];
        $db->prepare('INSERT INTO ssp_cache (k, v, updated_at) VALUES (?, ?, NOW())
                      ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)')
           ->execute(['reference_data', json_encode($data, JSON_UNESCAPED_UNICODE)]);
        return $memo = ['data' => $data, 'fetched_at' => ssp_now(), 'error' => null, 'from_cache' => false];
    }

    $error = $resp['message'] !== '' ? $resp['message'] : 'HTTP ' . $resp['status'] . ' ' . $resp['code'];
    return $memo = ['data' => $cached, 'fetched_at' => $row['updated_at'] ?? null,
        'error' => '[' . $resp['code'] . '] ' . $error, 'from_cache' => true];
}

/**
 * Human labels for the department / program values stored in a payload
 * (the payload may carry an id, a code or an exact name – all accepted by the API).
 *
 * @return array{department: ?string, program: ?string}
 */
function ssp_ref_labels(?array $ref, array $payload): array
{
    $dept = isset($payload['department']) ? (string)$payload['department'] : null;
    $prog = isset($payload['program']) ? (string)$payload['program'] : null;

    foreach ((array)($ref['departments'] ?? []) as $d) {
        $matches = $dept !== null && (
            (string)($d['id'] ?? '') === $dept
            || strcasecmp((string)($d['code'] ?? ''), $dept) === 0
            || strcasecmp((string)($d['name'] ?? ''), $dept) === 0
        );
        if (!$matches) {
            continue;
        }
        $dept = (string)($d['code'] ?: $d['name']);
        foreach ((array)($d['programs'] ?? []) as $p) {
            if ($prog !== null && ((string)($p['id'] ?? '') === $prog || strcasecmp((string)($p['name'] ?? ''), $prog) === 0)) {
                $prog = (string)$p['name'];
                break;
            }
        }
        break;
    }
    return ['department' => $dept, 'program' => $prog];
}
