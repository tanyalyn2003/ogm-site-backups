<?php

/**
 * Shared phone-based customer merge + dedupe (used by customers-api.php and CLI).
 */

/** Normalize phone to digits for dedupe (US: strips leading 1 on 11-digit numbers). */
function customersApiNormalizePhoneDigits($raw) {
    $d = preg_replace('/\D/', '', (string)$raw);
    if ($d === '') {
        return '';
    }
    if (strlen($d) === 11 && $d[0] === '1') {
        $d = substr($d, 1);
    }

    return strlen($d) >= 7 ? $d : '';
}

function customersApiCustomerPrimaryPhoneKey(array $cust) {
    $p = customersApiNormalizePhoneDigits($cust['phone'] ?? '');
    if ($p !== '') {
        return $p;
    }

    return customersApiNormalizePhoneDigits($cust['phone2'] ?? '');
}

/** Normalize email for exact-match dedupe. */
function customersApiNormalizeEmail($raw) {
    $email = strtolower(trim((string)$raw));
    if ($email === '' || !str_contains($email, '@')) {
        return '';
    }

    return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
}

function customersApiCustomerPrimaryEmailKey(array $cust) {
    $e = customersApiNormalizeEmail($cust['email'] ?? '');
    if ($e !== '') {
        return $e;
    }

    return customersApiNormalizeEmail($cust['email2'] ?? '');
}

function customersApiCustomerRichnessScore(array $cust) {
    $q = (isset($cust['quotes']) && is_array($cust['quotes'])) ? count($cust['quotes']) : 0;
    $j = (isset($cust['clickupJobs']) && is_array($cust['clickupJobs'])) ? count($cust['clickupJobs']) : 0;

    return $q * 10 + $j;
}

function customersApiMergeQuoteLists($into, $from) {
    if (!is_array($into)) {
        $into = [];
    }
    if (!is_array($from)) {
        return $into;
    }
    $seen = [];
    foreach ($into as $q) {
        if (!is_array($q)) {
            continue;
        }
        $k = strtoupper(trim((string)($q['invoiceNum'] ?? $q['quoteNumber'] ?? '')));
        if ($k !== '') {
            $seen[$k] = true;
        }
    }
    foreach ($from as $q) {
        if (!is_array($q)) {
            continue;
        }
        $k = strtoupper(trim((string)($q['invoiceNum'] ?? $q['quoteNumber'] ?? '')));
        if ($k !== '' && isset($seen[$k])) {
            continue;
        }
        $into[] = $q;
        if ($k !== '') {
            $seen[$k] = true;
        }
    }

    return $into;
}

function customersApiMergeClickupJobs($into, $from) {
    if (!is_array($into)) {
        $into = [];
    }
    if (!is_array($from)) {
        return $into;
    }
    $seen = [];
    foreach ($into as $e) {
        if (is_array($e) && isset($e['taskId'])) {
            $seen[(string)$e['taskId']] = true;
        }
    }
    foreach ($from as $e) {
        if (!is_array($e) || !isset($e['taskId'])) {
            continue;
        }
        $tid = (string)$e['taskId'];
        if (isset($seen[$tid])) {
            continue;
        }
        $into[] = $e;
        $seen[$tid] = true;
    }

    return $into;
}

function customersApiMergeNotesLog($into, $from) {
    if (!is_array($into)) {
        $into = [];
    }
    if (!is_array($from)) {
        return $into;
    }

    return array_merge($into, $from);
}

function customersApiMergeDonorIntoCanonical(array &$canonical, array $donor) {
    $keys = ['firstName', 'lastName', 'phone', 'phone2', 'email', 'email2', 'svcStreet', 'svcCity', 'billStreet', 'billCity', 'jobName', 'rep', 'referral', 'source'];
    foreach ($keys as $k) {
        $cv = isset($canonical[$k]) ? trim((string)$canonical[$k]) : '';
        $dv = isset($donor[$k]) ? trim((string)$donor[$k]) : '';
        if ($cv === '' && $dv !== '') {
            $canonical[$k] = $donor[$k];
        }
    }
    $cnRaw = $canonical['notes'] ?? null;
    $dnRaw = $donor['notes'] ?? null;
    if (is_array($cnRaw) || is_array($dnRaw)) {
        $ca = [];
        $da = [];
        if (is_array($cnRaw)) {
            $ca = $cnRaw;
        } elseif ($cnRaw !== null && trim((string)$cnRaw) !== '') {
            $ca = [['text' => (string)$cnRaw]];
        }
        if (is_array($dnRaw)) {
            $da = $dnRaw;
        } elseif ($dnRaw !== null && trim((string)$dnRaw) !== '') {
            $da = [['text' => (string)$dnRaw]];
        }
        $canonical['notes'] = array_merge($ca, $da);
    } else {
        $cn = isset($canonical['notes']) ? trim((string)$canonical['notes']) : '';
        $dn = isset($donor['notes']) ? trim((string)$donor['notes']) : '';
        if ($cn === '' && $dn !== '') {
            $canonical['notes'] = $donor['notes'];
        } elseif ($cn !== '' && $dn !== '' && $cn !== $dn) {
            $canonical['notes'] = $cn . "\n\n— merged —\n\n" . $dn;
        }
    }
    $cs = isset($canonical['status']) ? trim((string)$canonical['status']) : '';
    $ds = isset($donor['status']) ? trim((string)$donor['status']) : '';
    if ($cs === '' && $ds !== '') {
        $canonical['status'] = $donor['status'];
    }

    $canonical['quotes'] = customersApiMergeQuoteLists($canonical['quotes'] ?? [], $donor['quotes'] ?? []);
    $canonical['clickupJobs'] = customersApiMergeClickupJobs($canonical['clickupJobs'] ?? [], $donor['clickupJobs'] ?? []);
    $canonical['notesLog'] = customersApiMergeNotesLog($canonical['notesLog'] ?? [], $donor['notesLog'] ?? []);
}

function customersApiDedupeResolveTarget($id, array $redirects) {
    $guard = 0;
    while (isset($redirects[$id]) && $guard < 64) {
        $id = $redirects[$id];
        $guard++;
    }

    return $id;
}

function customersApiExecuteDedupeByKey($customersDir, $summariesDir, $dedupeLabel, callable $keyFn) {
    $rootDir = dirname($customersDir);
    $backupRoot = $rootDir . DIRECTORY_SEPARATOR . '_dedupe_backups';
    $backupDir = $backupRoot . DIRECTORY_SEPARATOR . $dedupeLabel . '-dedupe-' . date('Ymd-His');
    $backupCustomersDir = $backupDir . DIRECTORY_SEPARATOR . 'customers';
    $backupSummariesDir = $backupDir . DIRECTORY_SEPARATOR . 'quote_summaries';

    $backupFileOnce = function ($srcPath, $kind) use ($backupCustomersDir, $backupSummariesDir) {
        static $copied = [];
        if (!is_file($srcPath)) {
            return;
        }
        $destDir = $kind === 'summary' ? $backupSummariesDir : $backupCustomersDir;
        if (!is_dir($destDir)) {
            @mkdir($destDir, 0755, true);
        }
        $key = $kind . '|' . basename($srcPath);
        if (isset($copied[$key])) {
            return;
        }
        @copy($srcPath, $destDir . DIRECTORY_SEPARATOR . basename($srcPath));
        $copied[$key] = true;
    };

    $glob = glob($customersDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    $byKey = [];
    foreach ($glob as $path) {
        $base = basename($path);
        if ($base === '' || ($base[0] ?? '') === '_') {
            continue;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            continue;
        }
        $cust = json_decode((string)$raw, true);
        if (!is_array($cust) || empty($cust['id'])) {
            continue;
        }
        $key = $keyFn($cust);
        if ($key === '') {
            continue;
        }
        if (!isset($byKey[$key])) {
            $byKey[$key] = [];
        }
        $byKey[$key][] = ['path' => $path, 'cust' => $cust];
    }

    $redirects = [];
    $removedIds = [];
    $mergedGroups = 0;

    foreach ($byKey as $_pk => $group) {
        if (count($group) < 2) {
            continue;
        }
        usort($group, function ($a, $b) {
            $sa = customersApiCustomerRichnessScore($a['cust']);
            $sb = customersApiCustomerRichnessScore($b['cust']);
            if ($sa !== $sb) {
                return $sb <=> $sa;
            }

            return strcmp((string)$a['cust']['id'], (string)$b['cust']['id']);
        });

        $winner = $group[0];
        $canonical = $winner['cust'];
        $winnerPath = $winner['path'];
        $backupFileOnce($winnerPath, 'customer');

        for ($i = 1; $i < count($group); $i++) {
            customersApiMergeDonorIntoCanonical($canonical, $group[$i]['cust']);
            $deadId = (string)$group[$i]['cust']['id'];
            $redirects[$deadId] = (string)$canonical['id'];
            $backupFileOnce($group[$i]['path'], 'customer');
            @unlink($group[$i]['path']);
            $removedIds[] = $deadId;
        }

        $canonical['updatedAt'] = date('F j, Y');
        @file_put_contents($winnerPath, json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        $mergedGroups++;
    }

    $remappedSummaries = 0;
    $sumGlob = glob($summariesDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
    foreach ($sumGlob as $spath) {
        $sbase = basename($spath);
        if ($sbase === '' || ($sbase[0] ?? '') === '_') {
            continue;
        }
        $sraw = @file_get_contents($spath);
        if ($sraw === false || $sraw === '') {
            continue;
        }
        $sum = json_decode((string)$sraw, true);
        if (!is_array($sum)) {
            continue;
        }
        $lid = isset($sum['linkedCustomerId']) ? (string)$sum['linkedCustomerId'] : '';
        if ($lid === '') {
            continue;
        }
        $resolved = customersApiDedupeResolveTarget($lid, $redirects);
        if ($resolved !== $lid) {
            $backupFileOnce($spath, 'summary');
            $sum['linkedCustomerId'] = $resolved;
            @file_put_contents($spath, json_encode($sum, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
            $remappedSummaries++;
        }
    }

    return [
        'mergedGroups'       => $mergedGroups,
        'removedCustomerIds' => array_values(array_unique($removedIds)),
        'remappedSummaries'  => $remappedSummaries,
        'backupDir'          => $backupDir,
    ];
}

/**
 * @return array{mergedGroups:int,removedCustomerIds:list<string>,remappedSummaries:int,backupDir:string}
 */
function customersApiExecutePhoneDedupe($customersDir, $summariesDir) {
    return customersApiExecuteDedupeByKey($customersDir, $summariesDir, 'phone', 'customersApiCustomerPrimaryPhoneKey');
}

/**
 * @return array{mergedGroups:int,removedCustomerIds:list<string>,remappedSummaries:int,backupDir:string}
 */
function customersApiExecuteEmailDedupe($customersDir, $summariesDir) {
    return customersApiExecuteDedupeByKey($customersDir, $summariesDir, 'email', 'customersApiCustomerPrimaryEmailKey');
}
