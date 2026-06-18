<?php

/**
 * Shared customer / company name search normalization.
 * Treats &, "and", punctuation, and spacing variants as equivalent (e.g. C&F / C and F / candf).
 */

function ogmSearchNormalizeText(string $s): string {
    $s = strtolower(trim($s));
    if ($s === '') {
        return '';
    }
    $s = preg_replace('/\band\b/u', ' ', $s);
    $s = str_replace('&', ' ', $s);
    $s = preg_replace('/[^a-z0-9\s]/u', ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);

    return trim($s);
}

function ogmSearchNormalizeCompact(string $s): string {
    return str_replace(' ', '', ogmSearchNormalizeText($s));
}

function ogmSearchQueryTokens(string $query): array {
    $norm = ogmSearchNormalizeText($query);
    if ($norm === '') {
        return [];
    }

    return array_values(array_filter(
        preg_split('/\s+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [],
        static fn($t) => $t !== ''
    ));
}

function ogmSearchDigits(string $s): string {
    return preg_replace('/[^0-9]/', '', $s) ?? '';
}

/** True when query is only digits and spaces (phone-style search). */
function ogmSearchIsDigitOrientedQuery(string $query): bool {
    $trimmed = trim($query);
    if ($trimmed === '') {
        return false;
    }

    return (bool) preg_match('/^[0-9\s]+$/', $trimmed) && strlen(ogmSearchDigits($trimmed)) >= 3;
}

/**
 * Digit groups: no spaces → one contiguous run; spaces → separate parts.
 * "0308" → ["0308"]; "910 0308" → ["910","0308"].
 *
 * @return list<string>
 */
function ogmSearchQueryDigitParts(string $query): array {
    $trimmed = trim($query);
    if ($trimmed === '') {
        return [];
    }
    if (preg_match('/\s/u', $trimmed)) {
        $parts = [];
        foreach (preg_split('/\s+/u', $trimmed, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $chunk) {
            $d = ogmSearchDigits($chunk);
            if ($d !== '') {
                $parts[] = $d;
            }
        }

        return $parts;
    }
    $d = ogmSearchDigits($trimmed);

    return $d !== '' ? [$d] : [];
}

/** @param list<string> $parts @param list<string> $phones */
function ogmSearchPhoneDigitsMatch(array $parts, array $phones): bool {
    foreach ($phones as $phone) {
        $pd = ogmSearchDigits((string) $phone);
        if ($pd === '') {
            continue;
        }
        $allHit = true;
        foreach ($parts as $part) {
            if (!str_contains($pd, $part)) {
                $allHit = false;
                break;
            }
        }
        if ($allHit) {
            return true;
        }
    }

    return false;
}

function ogmSearchFieldMatchesPart(string $field, string $part): bool {
    if ($field === '') {
        return false;
    }
    if (preg_match('/^[0-9]+$/', $part)) {
        $fd = ogmSearchDigits($field);
        if ($fd !== '' && str_contains($fd, $part)) {
            return true;
        }
    }

    return ogmSearchMatchesQuery($field, $part);
}

/** @return list<string> */
function ogmSearchCustomerFieldValues(array $data): array {
    $fields = [
        $data['firstName'] ?? '',
        $data['lastName'] ?? '',
        $data['name'] ?? '',
        $data['phone'] ?? '',
        $data['phone2'] ?? '',
        $data['email'] ?? '',
        $data['email2'] ?? '',
        $data['svcStreet'] ?? '',
        $data['svcCity'] ?? '',
        $data['billStreet'] ?? '',
        $data['billCity'] ?? '',
        $data['jobName'] ?? '',
        $data['notes'] ?? '',
        $data['id'] ?? '',
    ];
    if (is_array($data['searchAliases'] ?? null)) {
        foreach ($data['searchAliases'] as $alias) {
            $fields[] = (string) $alias;
        }
    }
    if (is_array($data['quotes'] ?? null)) {
        foreach ($data['quotes'] as $quote) {
            if (!is_array($quote)) {
                continue;
            }
            foreach (['invoiceNum', 'quoteNumber', 'jobName'] as $key) {
                if (!empty($quote[$key])) {
                    $fields[] = (string) $quote[$key];
                }
            }
        }
    }

    return array_values(array_filter(array_map('strval', $fields), static fn($s) => trim($s) !== ''));
}

function ogmSearchMatchesDigitOrientedCustomer(array $data, string $query): bool {
    $parts = ogmSearchQueryDigitParts($query);
    if (!$parts) {
        return false;
    }
    if (ogmSearchPhoneDigitsMatch($parts, [$data['phone'] ?? '', $data['phone2'] ?? ''])) {
        return true;
    }
    $fields = ogmSearchCustomerFieldValues($data);
    foreach ($parts as $part) {
        $partHit = false;
        foreach ($fields as $field) {
            if (ogmSearchFieldMatchesPart($field, $part)) {
                $partHit = true;
                break;
            }
        }
        if (!$partHit) {
            return false;
        }
    }

    return true;
}

function ogmSearchFieldScore(string $value, string $query, int $exact, int $prefix, int $contains): int {
    $q = ogmSearchNormalizeText($query);
    if ($q === '') {
        return 0;
    }
    $blob = ogmSearchNormalizeText($value);
    if ($blob === '') {
        return 0;
    }
    if ($blob === $q) {
        return $exact;
    }
    if (str_starts_with($blob, $q)) {
        return $prefix;
    }
    if (str_contains($blob, $q)) {
        return $contains;
    }
    $qCompact = ogmSearchNormalizeCompact($query);
    $blobCompact = ogmSearchNormalizeCompact($value);
    if ($qCompact !== '' && $blobCompact !== '') {
        if (preg_match('/^[0-9]+$/', $qCompact)) {
            $fd = ogmSearchDigits($value);
            if ($fd !== '' && str_contains($fd, $qCompact)) {
                return max(1, $contains - 10);
            }
        } elseif (str_contains($blobCompact, $qCompact)) {
            return max(1, $contains - 10);
        }
    }

    $tokens = ogmSearchQueryTokens($query);
    if (!$tokens) {
        return 0;
    }
    $blobTokens = ogmSearchQueryTokens($value);
    $matched = 0;
    $prefixMatched = 0;
    foreach ($tokens as $token) {
        $hit = false;
        foreach ($blobTokens as $blobToken) {
            if (str_starts_with($blobToken, $token)) {
                $hit = true;
                $prefixMatched++;
                break;
            }
            if (strlen($token) >= 2 && str_contains($blobToken, $token)) {
                $hit = true;
                break;
            }
        }
        if ($hit) {
            $matched++;
        }
    }
    if ($matched === count($tokens)) {
        return $prefixMatched === count($tokens) ? max(1, $prefix - 25) : max(1, $contains - 20);
    }

    return 0;
}

function ogmSearchMatchesCustomerRecord(array $data, string $query): bool {
    if (ogmSearchIsDigitOrientedQuery($query)) {
        return ogmSearchMatchesDigitOrientedCustomer($data, $query);
    }

    return ogmSearchMatchesQuery(implode(' ', ogmSearchCustomerFieldValues($data)), $query);
}

function ogmSearchScoreCustomerRecord(array $data, string $query): int {
    $q = ogmSearchNormalizeText($query);
    if ($q === '') {
        return 0;
    }
    if (ogmSearchIsDigitOrientedQuery($query)) {
        return ogmSearchMatchesDigitOrientedCustomer($data, $query) ? 200 : 0;
    }

    $score = 0;
    $parts = ogmSearchQueryDigitParts($query);
    if (count($parts) === 1 && strlen($parts[0]) >= 4) {
        foreach ([$data['phone'] ?? '', $data['phone2'] ?? ''] as $phone) {
            $digits = ogmSearchDigits((string) $phone);
            if ($digits !== '' && str_contains($digits, $parts[0])) {
                $score = max($score, str_ends_with($digits, $parts[0]) ? 260 : 230);
            }
        }
    }

    $first = trim((string) ($data['firstName'] ?? ''));
    $last = trim((string) ($data['lastName'] ?? ''));
    $full = trim($first . ' ' . $last);
    $rev = trim($last . ' ' . $first);
    $nameFields = [$full, $rev, (string) ($data['name'] ?? ''), (string) ($data['jobName'] ?? '')];
    if (is_array($data['searchAliases'] ?? null)) {
        foreach ($data['searchAliases'] as $alias) {
            $nameFields[] = (string) $alias;
        }
    }
    foreach ($nameFields as $name) {
        $score = max($score, ogmSearchFieldScore((string) $name, $query, 240, 200, 150));
    }
    foreach ([$data['email'] ?? '', $data['email2'] ?? ''] as $email) {
        $score = max($score, ogmSearchFieldScore((string) $email, $query, 120, 95, 70));
    }
    foreach ([$data['svcStreet'] ?? '', $data['svcCity'] ?? '', $data['billStreet'] ?? '', $data['billCity'] ?? ''] as $addr) {
        $score = max($score, ogmSearchFieldScore((string) $addr, $query, 90, 75, 55));
    }
    foreach ([$data['notes'] ?? '', $data['id'] ?? ''] as $field) {
        $score = max($score, ogmSearchFieldScore((string) $field, $query, 70, 55, 35));
    }
    if (is_array($data['quotes'] ?? null)) {
        foreach ($data['quotes'] as $quote) {
            if (!is_array($quote)) {
                continue;
            }
            foreach ([$quote['invoiceNum'] ?? '', $quote['quoteNumber'] ?? '', $quote['jobName'] ?? ''] as $field) {
                $score = max($score, ogmSearchFieldScore((string) $field, $query, 110, 85, 60));
            }
        }
    }

    return max($score, ogmSearchScoreQuery(implode(' ', ogmSearchCustomerFieldValues($data)), $query));
}

function ogmSearchMatchesQuery(string $haystack, string $query): bool {
    $q = ogmSearchNormalizeText($query);
    if ($q === '') {
        return true;
    }
    $blob = ogmSearchNormalizeText($haystack);
    if ($blob === '') {
        return false;
    }
    if (str_contains($blob, $q)) {
        return true;
    }
    $qCompact = ogmSearchNormalizeCompact($query);
    $blobCompact = ogmSearchNormalizeCompact($haystack);
    // All-digit compact match only within a single field — never across merged blobs.
    if ($qCompact !== '' && $blobCompact !== '' && !preg_match('/^[0-9]+$/', $qCompact) && str_contains($blobCompact, $qCompact)) {
        return true;
    }
    $tokens = ogmSearchQueryTokens($query);
    if (!$tokens) {
        return false;
    }
    foreach ($tokens as $token) {
        if (str_contains($blob, $token)) {
            continue;
        }
        if (preg_match('/^[0-9]+$/', $token)) {
            $hd = ogmSearchDigits($haystack);
            if (strlen($token) >= 2 && $hd !== '' && str_contains($hd, $token)) {
                continue;
            }
        } elseif (strlen($token) >= 2 && str_contains($blobCompact, $token)) {
            continue;
        }
        return false;
    }

    return true;
}

function ogmSearchScoreQuery(string $haystack, string $query): int {
    $q = ogmSearchNormalizeText($query);
    if ($q === '') {
        return 0;
    }
    $blob = ogmSearchNormalizeText($haystack);
    $blobCompact = ogmSearchNormalizeCompact($haystack);
    $qCompact = ogmSearchNormalizeCompact($query);
    $score = 0;
    if ($blob !== '' && str_contains($blob, $q)) {
        $score += 5;
    }
    if ($qCompact !== '' && $blobCompact !== '' && !preg_match('/^[0-9]+$/', $qCompact) && str_contains($blobCompact, $qCompact)) {
        $score += 5;
    }
    foreach (ogmSearchQueryTokens($query) as $term) {
        if ($blob !== '' && str_contains($blob, $term)) {
            $score += substr_count($blob, $term);
        } elseif (preg_match('/^[0-9]+$/', $term)) {
            $hd = ogmSearchDigits($haystack);
            if (strlen($term) >= 2 && $hd !== '' && str_contains($hd, $term)) {
                $score += 1;
            }
        } elseif (strlen($term) >= 2 && str_contains($blobCompact, $term)) {
            $score += 1;
        }
    }

    return $score;
}

/** Common words stripped from natural-language AI search queries. */
function ogmSearchStopWords(): array {
    return [
        'a', 'an', 'the', 'and', 'or', 'but', 'im', 'i', 'am', 'me', 'my', 'we', 'our', 'you', 'your',
        'looking', 'look', 'find', 'search', 'searched', 'email', 'emails', 'mail', 'message', 'messages',
        'from', 'about', 'with', 'to', 'in', 'on', 'at', 'for', 'of', 'by', 'is', 'are', 'was', 'were', 'be',
        'that', 'this', 'these', 'those', 'do', 'does', 'did', 'have', 'has', 'had', 'any', 'some', 'all',
        'please', 'help', 'need', 'want', 'show', 'get', 'give', 'tell', 'what', 'when', 'where', 'who',
        'how', 'which', 'there', 'here', 'can', 'could', 'would', 'should', 'may', 'might', 'will',
        'customer', 'customers', 'client', 'clients', 'history', 'saved', 'relevant',
    ];
}

/** Pull the name/company fragment after "from", "by", "about", etc. */
function ogmSearchExtractFromClause(string $query): string {
    if (preg_match('/\b(?:from|by|regarding|re|about)\s+(.+)$/iu', trim($query), $m)) {
        return trim($m[1]);
    }

    return '';
}

function ogmSearchSignificantTokens(string $query): array {
    $chunks = [];
    $fromClause = ogmSearchExtractFromClause($query);
    if ($fromClause !== '') {
        $chunks[] = $fromClause;
    }
    $chunks[] = $query;

    $stop = array_flip(ogmSearchStopWords());
    $tokens = [];
    foreach ($chunks as $chunk) {
        foreach (ogmSearchQueryTokens($chunk) as $t) {
            if (isset($stop[$t])) {
                continue;
            }
            $tokens[$t] = true;
        }
    }
    $out = array_keys($tokens);
    if ($out) {
        return $out;
    }
    foreach (ogmSearchQueryTokens($query) as $t) {
        if (strlen($t) >= 2) {
            $out[] = $t;
        }
    }

    return $out;
}

/** Score natural-language queries (AI email search) — boosts "from C and F" style phrases. */
function ogmSearchScoreNaturalQuery(string $haystack, string $query): int {
    $blob = ogmSearchNormalizeText($haystack);
    if ($blob === '') {
        return 0;
    }
    $blobCompact = ogmSearchNormalizeCompact($haystack);

    $score = ogmSearchScoreQuery($haystack, $query);

    $fromClause = ogmSearchExtractFromClause($query);
    if ($fromClause !== '') {
        $score += ogmSearchScoreQuery($haystack, $fromClause) * 2;
        $fc = ogmSearchNormalizeText($fromClause);
        if ($fc !== '' && str_contains($blob, $fc)) {
            $score += 20;
        }
        $fcCompact = ogmSearchNormalizeCompact($fromClause);
        if ($fcCompact !== '' && str_contains($blobCompact, $fcCompact)) {
            $score += 20;
        }
    }

    $sig = ogmSearchSignificantTokens($query);
    if (!$sig) {
        return $score;
    }

    $sigPhrase = ogmSearchNormalizeText(implode(' ', $sig));
    if ($sigPhrase !== '' && str_contains($blob, $sigPhrase)) {
        $score += 12;
    }

    $matchedSig = 0;
    foreach ($sig as $term) {
        if (str_contains($blob, $term)) {
            $score += 4;
            $matchedSig++;
        } elseif (strlen($term) >= 2 && str_contains($blobCompact, $term)) {
            $score += 3;
            $matchedSig++;
        } elseif (strlen($term) === 1 && str_contains($blobCompact, $term)) {
            $score += 2;
            $matchedSig++;
        }
    }

    if ($matchedSig === 0) {
        if ($fromClause !== '' && $score > 0) {
            return $score;
        }
        return 0;
    }
    if (count($sig) <= 4 && $matchedSig < max(1, (int) ceil(count($sig) * 0.5))) {
        return 0;
    }

    return $score;
}
