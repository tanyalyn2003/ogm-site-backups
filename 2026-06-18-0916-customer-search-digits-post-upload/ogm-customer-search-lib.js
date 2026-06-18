/**
 * Shared customer / company search normalization (C&F, C and F, candf, etc.).
 * Digit queries without spaces match contiguously (e.g. phone last-4 "0308");
 * spaces split digit groups (e.g. "910 0308" matches area code + last four).
 */
(function (global) {
  'use strict';

  function normalizeText(s) {
    var t = String(s || '').toLowerCase().trim();
    if (!t) return '';
    t = t.replace(/\band\b/g, ' ').replace(/&/g, ' ');
    t = t.replace(/[^a-z0-9\s]/g, ' ').replace(/\s+/g, ' ').trim();
    return t;
  }

  function normalizeCompact(s) {
    return normalizeText(s).replace(/\s/g, '');
  }

  function digits(s) {
    return String(s || '').replace(/[^0-9]/g, '');
  }

  function queryTokens(query) {
    var norm = normalizeText(query);
    if (!norm) return [];
    return norm.split(/\s+/).filter(Boolean);
  }

  /** True when query is only digits and spaces (phone-style search). */
  function isDigitOrientedQuery(query) {
    var trimmed = String(query || '').trim();
    if (!trimmed) return false;
    return /^[0-9\s]+$/.test(trimmed) && digits(trimmed).length >= 3;
  }

  /**
   * Digit groups for matching: no spaces → one contiguous run; spaces → separate parts.
   * "0308" → ["0308"]; "910 0308" → ["910","0308"]; "0 3 0 8" → ["0","3","0","8"].
   */
  function queryDigitParts(query) {
    var trimmed = String(query || '').trim();
    if (!trimmed) return [];
    if (/\s/.test(trimmed)) {
      return trimmed.split(/\s+/).map(digits).filter(function (d) { return d.length > 0; });
    }
    var d = digits(trimmed);
    return d ? [d] : [];
  }

  function phoneDigitsMatch(parts, phones) {
    for (var p = 0; p < phones.length; p++) {
      var pd = digits(phones[p]);
      if (!pd) continue;
      var allHit = true;
      for (var i = 0; i < parts.length; i++) {
        if (pd.indexOf(parts[i]) === -1) {
          allHit = false;
          break;
        }
      }
      if (allHit) return true;
    }
    return false;
  }

  function fieldMatchesPart(field, part) {
    var val = String(field || '');
    if (!val) return false;
    if (/^[0-9]+$/.test(part)) {
      var fd = digits(val);
      if (fd && fd.indexOf(part) !== -1) return true;
    }
    return matchesQuery(val, part);
  }

  function customerFieldValues(c) {
    c = c || {};
    var fields = [
      c.firstName, c.lastName, c.name, c.phone, c.phone2, c.email, c.email2,
      c.svcStreet, c.svcCity, c.billStreet, c.billCity, c.jobName, c.notes, c.id
    ];
    if (Array.isArray(c.searchAliases)) {
      fields = fields.concat(c.searchAliases);
    }
    if (Array.isArray(c.quotes)) {
      c.quotes.forEach(function (q) {
        if (!q) return;
        if (q.invoiceNum) fields.push(q.invoiceNum);
        if (q.quoteNumber) fields.push(q.quoteNumber);
        if (q.jobName) fields.push(q.jobName);
      });
    }
    return fields.filter(Boolean);
  }

  function matchesDigitOrientedCustomer(c, query) {
    var parts = queryDigitParts(query);
    if (!parts.length) return false;
    if (phoneDigitsMatch(parts, [c.phone, c.phone2])) return true;

    var fields = customerFieldValues(c);
    for (var i = 0; i < parts.length; i++) {
      var part = parts[i];
      var partHit = false;
      for (var f = 0; f < fields.length; f++) {
        if (fieldMatchesPart(fields[f], part)) {
          partHit = true;
          break;
        }
      }
      if (!partHit) return false;
    }
    return true;
  }

  function fieldScore(value, query, exact, prefix, contains) {
    var q = normalizeText(query);
    if (!q) return 0;
    var blob = normalizeText(value);
    if (!blob) return 0;
    if (blob === q) return exact;
    if (blob.indexOf(q) === 0) return prefix;
    if (blob.indexOf(q) !== -1) return contains;
    var qCompact = normalizeCompact(query);
    var blobCompact = normalizeCompact(value);
    if (qCompact && blobCompact && /^[0-9]+$/.test(qCompact)) {
      var fd = digits(value);
      if (fd && fd.indexOf(qCompact) !== -1) return Math.max(1, contains - 10);
    } else if (qCompact && blobCompact && blobCompact.indexOf(qCompact) !== -1) {
      return Math.max(1, contains - 10);
    }

    var tokens = queryTokens(query);
    if (!tokens.length) return 0;
    var blobTokens = queryTokens(value);
    var matched = 0;
    var prefixMatched = 0;
    tokens.forEach(function (token) {
      var hit = false;
      for (var i = 0; i < blobTokens.length; i++) {
        var bt = blobTokens[i];
        if (bt.indexOf(token) === 0) {
          hit = true;
          prefixMatched++;
          break;
        }
        if (token.length >= 2 && bt.indexOf(token) !== -1) {
          hit = true;
          break;
        }
      }
      if (hit) matched++;
    });
    if (matched === tokens.length) {
      return prefixMatched === tokens.length ? Math.max(1, prefix - 25) : Math.max(1, contains - 20);
    }
    return 0;
  }

  function matchesQuery(haystack, query) {
    var q = normalizeText(query);
    if (!q) return true;
    var blob = normalizeText(haystack);
    if (!blob) return false;
    if (blob.indexOf(q) !== -1) return true;

    var qCompact = normalizeCompact(query);
    var blobCompact = normalizeCompact(haystack);
    // All-digit compact match only within a single field — never across merged blobs.
    if (qCompact && blobCompact && !/^[0-9]+$/.test(qCompact) && blobCompact.indexOf(qCompact) !== -1) {
      return true;
    }

    var tokens = queryTokens(query);
    if (!tokens.length) return false;
    for (var i = 0; i < tokens.length; i++) {
      var token = tokens[i];
      if (blob.indexOf(token) !== -1) continue;
      if (/^[0-9]+$/.test(token)) {
        var hd = digits(haystack);
        if (token.length >= 2 && hd && hd.indexOf(token) !== -1) continue;
      } else if (token.length >= 2 && blobCompact.indexOf(token) !== -1) {
        continue;
      }
      return false;
    }
    return true;
  }

  function scoreQuery(haystack, query) {
    var q = normalizeText(query);
    if (!q) return 0;
    var blob = normalizeText(haystack);
    if (!blob) return 0;
    var score = 0;
    if (blob.indexOf(q) !== -1) score += 5;
    var qCompact = normalizeCompact(query);
    var blobCompact = normalizeCompact(haystack);
    if (qCompact && blobCompact && !/^[0-9]+$/.test(qCompact) && blobCompact.indexOf(qCompact) !== -1) {
      score += 5;
    }
    queryTokens(query).forEach(function (term) {
      if (blob.indexOf(term) !== -1) score += 1;
      else if (/^[0-9]+$/.test(term)) {
        var hd = digits(haystack);
        if (term.length >= 2 && hd && hd.indexOf(term) !== -1) score += 1;
      } else if (term.length >= 2 && blobCompact.indexOf(term) !== -1) {
        score += 1;
      }
    });
    return score;
  }

  function customerSearchBlob(c) {
    return customerFieldValues(c).join(' ');
  }

  function matchesCustomer(c, query) {
    if (isDigitOrientedQuery(query)) {
      return matchesDigitOrientedCustomer(c, query);
    }
    return matchesQuery(customerSearchBlob(c), query);
  }

  function scoreCustomer(c, query) {
    c = c || {};
    if (!normalizeText(query)) return 0;
    if (isDigitOrientedQuery(query)) {
      return matchesDigitOrientedCustomer(c, query) ? 200 : 0;
    }

    var score = 0;
    var parts = queryDigitParts(query);
    if (parts.length === 1 && parts[0].length >= 4) {
      [c.phone, c.phone2].forEach(function (phone) {
        var pd = digits(phone);
        if (pd && pd.indexOf(parts[0]) !== -1) {
          score = Math.max(score, pd.slice(-parts[0].length) === parts[0] ? 260 : 230);
        }
      });
    }

    var first = String(c.firstName || '').trim();
    var last = String(c.lastName || '').trim();
    var full = [first, last].filter(Boolean).join(' ');
    var rev = [last, first].filter(Boolean).join(' ');
    var nameFields = [full, rev, c.name, c.jobName];
    if (Array.isArray(c.searchAliases)) nameFields = nameFields.concat(c.searchAliases);
    nameFields.forEach(function (name) {
      score = Math.max(score, fieldScore(name, query, 240, 200, 150));
    });
    [c.email, c.email2].forEach(function (email) {
      score = Math.max(score, fieldScore(email, query, 120, 95, 70));
    });
    [c.svcStreet, c.svcCity, c.billStreet, c.billCity].forEach(function (addr) {
      score = Math.max(score, fieldScore(addr, query, 90, 75, 55));
    });
    [c.notes, c.id].forEach(function (field) {
      score = Math.max(score, fieldScore(field, query, 70, 55, 35));
    });
    if (Array.isArray(c.quotes)) {
      c.quotes.forEach(function (q) {
        if (!q) return;
        [q.invoiceNum, q.quoteNumber, q.jobName].forEach(function (field) {
          score = Math.max(score, fieldScore(field, query, 110, 85, 60));
        });
      });
    }
    return Math.max(score, scoreQuery(customerSearchBlob(c), query));
  }

  global.OgmCustomerSearch = {
    normalizeText: normalizeText,
    normalizeCompact: normalizeCompact,
    queryTokens: queryTokens,
    digits: digits,
    isDigitOrientedQuery: isDigitOrientedQuery,
    queryDigitParts: queryDigitParts,
    matchesQuery: matchesQuery,
    scoreQuery: scoreQuery,
    fieldScore: fieldScore,
    customerSearchBlob: customerSearchBlob,
    matchesCustomer: matchesCustomer,
    scoreCustomer: scoreCustomer
  };
})(typeof window !== 'undefined' ? window : globalThis);
