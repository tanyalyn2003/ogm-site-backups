// Canonical live nav script. Top-level pages load this file from assets/js/nav-dropdown.js.
document.addEventListener('DOMContentLoaded', function () {
  const searchCorrections = {
    warrenty: 'warranty',
    waranty: 'warranty',
    maintainance: 'maintenance',
    quartsite: 'quartzite',
    champher: 'chamfer'
  };

  const normalizeSearchText = function (value) {
    const normalized = (value || '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();

    if (!normalized) {
      return '';
    }

    return normalized
      .split(' ')
      .map((token) => searchCorrections[token] || token)
      .join(' ');
  };

  const searchAliasesByHref = {
    'index.html': ['home', 'homepage'],
    'countertops.html': ['stone', 'surfaces', 'kitchen countertops', 'bathroom countertops', 'quiz', 'countertop quiz', 'countertops quiz', 'perfect countertop', 'perfect match', 'countertop guide'],
    'our-process-countertops.html': ['templating', 'fabrication', 'installation'],
    'our-process-glass.html': ['glass installation', 'shower process'],
    'glass.html': ['mirror', 'shower glass', 'bath enclosures', 'metal finishes', 'flat glass'],
    'products.html': ['sinks'],
    'gallery.html': ['portfolio', 'photos', 'inspiration'],
    'commercial.html': ['builder', 'multi-family', 'business'],
    'resources.html': ['guides', 'care', 'maintenance', 'faq'],
    'contact.html': ['estimate', 'consultation', 'schedule'],
    'about.html': ['our story', 'family owned'],
    'backsplash.html': ['backsplashes', 'full height splash'],
    'edge-profiles.html': ['edge', 'edges', 'edging'],
    'granite-countertops.html': ['granite'],
    'quartz-countertops.html': ['quartz'],
    'marble-countertops.html': ['marble'],
    'quartzite-countertops.html': ['quartzite'],
    'sintered-stone-countertops.html': ['dekton', 'sintered stone'],
    'cultured-marble-countertops.html': ['cultured marble']
  };

  const supplementalSearchEntries = [
    {
      href: 'countertops.html#countertops-quiz-start',
      label: 'Countertop Match Quiz',
      section: 'Countertops Overview',
      aliases: ['quiz', 'countertop quiz', 'countertops quiz', 'on page quiz', 'find your perfect countertop', 'perfect countertop', 'perfect match', 'best fit countertop', 'material quiz']
    },
    {
      href: 'countertops.html#countertops-perfect-match',
      label: 'Perfect Countertop Match',
      section: 'Countertops Overview',
      aliases: ['quiz result', 'quiz results', 'perfect countertop result', 'best countertop match', 'your perfect countertop', 'countertop match result']
    },
    {
      href: 'resources.html#areas-serve',
      label: 'Areas We Serve',
      section: 'Client Resources',
      aliases: ['service area', 'service areas', 'areas served', 'coverage', 'north carolina', 'fayetteville', 'pinehurst', 'southern pines']
    },
    {
      href: 'resources.html#comparison-chart',
      label: 'Comparison Chart',
      section: 'Client Resources',
      aliases: ['material guide', 'compare materials', 'granite vs quartz', 'granite vs marble', 'quartzite vs quartz', 'durability', 'heat resistance', 'pricing']
    },
    {
      href: 'resources.html#edge-profiles',
      label: 'Countertop Edge Profiles',
      section: 'Client Resources',
      aliases: ['countertop edges', 'edge profiles', 'eased edge', 'beveled edge', 'chamfer edge', 'champher edge', 'radius edge', 'demi bullnose', 'bullnose', 'ogee', 'mitered edge', 'laminated edge']
    },
    {
      href: 'resources.html#care-maintenance',
      label: 'Care & Maintenance',
      section: 'Client Resources',
      aliases: ['care guide', 'countertop care', 'countertop maintenance', 'cleaning', 'sealing', 'seal', 'sealer', 'stain removal', 'maintenance schedule', 'granite care', 'quartz care', 'quartzite care', 'marble care', 'maintainance']
    },
    {
      href: 'resources.html#installation',
      label: 'Installation Guide',
      section: 'Client Resources',
      aliases: ['installation', 'install', 'before installation', 'installation day', 'after installation', 'prepare your space', 'quality check']
    },
    {
      href: 'resources.html#warranty',
      label: 'Warranty Coverage',
      section: 'Client Resources',
      aliases: ['warranty', 'warrenty', 'waranty', 'guarantee', 'fabrication warranty', 'installation warranty', 'after sales support', 'after-sales support', 'our promise']
    },
    {
      href: 'resources.html#faq',
      label: 'F.A.Q.',
      section: 'Client Resources',
      aliases: ['faq', 'faqs', 'frequently asked questions', 'questions', 'answers']
    },
    {
      href: 'resources.html#faq',
      label: 'Materials & Product Selection FAQ',
      section: 'Client Resources',
      aliases: ['materials', 'product selection', 'granite', 'quartz', 'marble', 'quartzite', 'outdoor kitchens', 'heat resistance']
    },
    {
      href: 'resources.html#faq',
      label: 'Pricing & Estimates FAQ',
      section: 'Client Resources',
      aliases: ['pricing', 'prices', 'estimate', 'estimates', 'quote', 'quotes', 'cost', 'costs']
    },
    {
      href: 'resources.html#faq',
      label: 'Timelines & Process FAQ',
      section: 'Client Resources',
      aliases: ['timeline', 'timelines', 'process', 'templating', 'fabrication', 'lead time', 'turnaround']
    },
    {
      href: 'resources.html#faq',
      label: 'Sinks, Faucets & Cooktops FAQ',
      section: 'Client Resources',
      aliases: ['sink', 'sinks', 'faucet', 'faucets', 'cooktop', 'cooktops']
    },
    {
      href: 'resources.html#faq',
      label: 'Delivery & Installation FAQ',
      section: 'Client Resources',
      aliases: ['delivery', 'installation', 'install', 'install day', 'job site prep']
    },
    {
      href: 'resources.html#faq',
      label: 'Warranty & Service FAQ',
      section: 'Client Resources',
      aliases: ['warranty service', 'service', 'repair', 'support', 'coverage']
    },
    {
      href: 'resources.html#request-quote',
      label: 'Request a Quote',
      section: 'Client Resources',
      aliases: ['request quote', 'get a quote', 'estimate', 'consultation', 'schedule']
    }
  ];

  const toSmallImageUrl = function (url) {
    if (!url || /^data:/i.test(url)) {
      return url;
    }

    if (/^(https?:)?\/\//i.test(url)) {
      return url;
    }

    const urlParts = url.split('?');
    const base = urlParts[0];
    const query = urlParts.length > 1 ? urlParts.slice(1).join('?') : '';

    if (
      !/\.(jpe?g|png|gif)$/i.test(base) ||
      /-(sm|card|home|mobile|lcp|64)\.(jpe?g|png|gif)$/i.test(base)
    ) {
      return url;
    }

    const smallBase = base.replace(/\.(jpe?g|png|gif)$/i, '-sm.$1');
    return query ? `${smallBase}?${query}` : smallBase;
  };

  const isHeroPriorityImage = function (img) {
    if (!img) {
      return false;
    }

    if (
      img.classList.contains('logo-image') ||
      img.closest('.nav-left, .logo-mark, .logo-text')
    ) {
      return true;
    }

    const fetchPriority = (img.getAttribute('fetchpriority') || '').toLowerCase();
    const loading = (img.getAttribute('loading') || '').toLowerCase();
    if (fetchPriority === 'high' || loading === 'eager') {
      return true;
    }

    return Boolean(img.closest([
      '.hero',
      '.granite-hero',
      '.edge-hero',
      '.commercial-hero',
      '.process-hero',
      '.page-hero',
      '.hero-section',
      '.hero-banner',
      '.hero-wrapper',
      '.hero-wrap'
    ].join(',')));
  };

  const swapImageSources = function () {
    const images = document.querySelectorAll('img[src]');
    images.forEach((img) => {
      if (isHeroPriorityImage(img)) {
        return;
      }

      const currentSrc = img.getAttribute('src');
      const smallSrc = toSmallImageUrl(currentSrc);
      if (smallSrc && smallSrc !== currentSrc) {
        img.setAttribute('src', smallSrc);
      }
    });

    const styledElements = document.querySelectorAll('[style*="url("]');
    styledElements.forEach((element) => {
      if (element.closest([
        '.hero',
        '.granite-hero',
        '.edge-hero',
        '.commercial-hero',
        '.process-hero',
        '.page-hero',
        '.hero-section',
        '.hero-banner',
        '.hero-wrapper',
        '.hero-wrap'
      ].join(','))) {
        return;
      }

      const styleValue = element.getAttribute('style');
      if (!styleValue || !styleValue.includes('url(')) {
        return;
      }

      const updatedStyle = styleValue.replace(/url\((['"]?)([^'")]+)\1\)/gi, function (match, quote, url) {
        const smallUrl = toSmallImageUrl(url);
        if (smallUrl === url) {
          return match;
        }
        const wrapper = quote || '';
        return `url(${wrapper}${smallUrl}${wrapper})`;
      });

      if (updatedStyle !== styleValue) {
        element.setAttribute('style', updatedStyle);
      }
    });
  };

  swapImageSources();

  const mobileQuery = window.matchMedia('(max-width: 768px)');
  const mobileOnlyNavSections = document.querySelectorAll('.mobile-nav-header, .mobile-nav-footer');
  const isMobileNavViewport = function () {
    return mobileQuery.matches;
  };

  const syncMobileOnlyNavSections = function () {
    const isMobile = isMobileNavViewport();
    mobileOnlyNavSections.forEach((section) => {
      const mobileDisplay = section.classList.contains('mobile-nav-header') ? 'flex' : 'block';
      section.hidden = !isMobile;
      section.setAttribute('aria-hidden', isMobile ? 'false' : 'true');
      section.style.display = isMobile ? mobileDisplay : 'none';
    });
  };

  syncMobileOnlyNavSections();

  const appendSearchEntry = function (entries, seen, entry) {
    const href = (entry.href || '').trim();
    const label = (entry.label || '').trim();
    const section = (entry.section || '').trim();
    const aliases = Array.isArray(entry.aliases) ? entry.aliases : [];
    const extraTerms = Array.isArray(entry.extraTerms) ? entry.extraTerms : [];
    const key = `${label.toLowerCase()}|${href}`;

    if (!href || !label || seen.has(key)) {
      return;
    }

    seen.add(key);
    entries.push({
      href,
      label,
      section,
      searchText: normalizeSearchText([label, section, href, aliases.join(' '), extraTerms.join(' ')].join(' '))
    });
  };

  const buildNavSearchIndex = function (navLinks) {
    const seen = new Set();
    const links = navLinks.querySelectorAll('a[href]');
    const entries = [];

    links.forEach((link) => {
      const href = (link.getAttribute('href') || '').trim();
      if (!href || /^(https?:|mailto:|tel:)/i.test(href)) {
        return;
      }

      if (link.closest('.mobile-nav-social')) {
        return;
      }

      const label = link.textContent.replace(/\s+/g, ' ').trim();
      if (!label) {
        return;
      }

      const dropdown = link.closest('.nav-dropdown');
      const topLevelLabel = dropdown
        ? dropdown.querySelector('.nav-dropdown-toggle')?.textContent.replace(/\s+/g, ' ').trim()
        : '';
      const section = link.closest('.mega-column')
        ? link.closest('.mega-column').querySelector('.mega-heading')?.textContent.replace(/\s+/g, ' ').trim()
        : '';
      const aliases = searchAliasesByHref[href] || [];
      appendSearchEntry(entries, seen, {
        href,
        label,
        section: section || topLevelLabel || '',
        aliases,
        extraTerms: [section, topLevelLabel]
      });
    });

    supplementalSearchEntries.forEach((entry) => {
      appendSearchEntry(entries, seen, entry);
    });

    return entries;
  };

  const getSearchMatches = function (searchIndex, query) {
    const normalizedQuery = normalizeSearchText(query);
    if (normalizedQuery.length < 2) {
      return [];
    }

    const terms = normalizedQuery.split(' ');

    return searchIndex
      .map((entry) => {
        const normalizedLabel = normalizeSearchText(entry.label);
        const allTermsMatch = terms.every((term) => entry.searchText.includes(term));
        if (!allTermsMatch) {
          return null;
        }

        let score = 0;
        if (normalizedLabel === normalizedQuery) {
          score += 120;
        }
        if (normalizedLabel.startsWith(normalizedQuery)) {
          score += 80;
        }
        if (entry.searchText.includes(normalizedQuery)) {
          score += 40;
        }

        terms.forEach((term) => {
          if (normalizedLabel.includes(term)) {
            score += 14;
          } else {
            score += 6;
          }
        });

        return {
          ...entry,
          score
        };
      })
      .filter(Boolean)
      .sort((left, right) => {
        if (right.score !== left.score) {
          return right.score - left.score;
        }
        return left.label.localeCompare(right.label);
      })
      .slice(0, 6);
  };

  const initSearchContainer = function (searchContainer, options) {
    const settings = options || {};
    const navLinks = settings.navLinks || searchContainer.closest('.nav-links') || document.querySelector('.nav-links');
    const input = searchContainer.querySelector('input');
    const button = searchContainer.querySelector('button');
    if (!navLinks || !input || !button) {
      return null;
    }

    const searchIndex = buildNavSearchIndex(navLinks);
    if (!searchIndex.length) {
      return null;
    }

    const resultsPanel = document.createElement('div');
    resultsPanel.className = 'mobile-nav-search-results';
    resultsPanel.hidden = true;
    resultsPanel.setAttribute('aria-live', 'polite');
    searchContainer.appendChild(resultsPanel);

    const hideResults = function () {
      resultsPanel.hidden = true;
      resultsPanel.innerHTML = '';
    };

    const renderResults = function (matches, rawQuery) {
      const query = (rawQuery || '').trim();
      if (normalizeSearchText(query).length < 2) {
        hideResults();
        return;
      }

      resultsPanel.innerHTML = '';

      if (!matches.length) {
        const emptyState = document.createElement('div');
        emptyState.className = 'mobile-nav-search-empty';
        emptyState.textContent = `No results for "${query}"`;
        resultsPanel.appendChild(emptyState);
      } else {
        matches.forEach((match) => {
          const resultLink = document.createElement('a');
          resultLink.className = 'mobile-nav-search-result';
          resultLink.href = match.href;

          const title = document.createElement('span');
          title.className = 'mobile-nav-search-result-title';
          title.textContent = match.label;
          resultLink.appendChild(title);

          if (match.section) {
            const meta = document.createElement('span');
            meta.className = 'mobile-nav-search-result-meta';
            meta.textContent = match.section;
            resultLink.appendChild(meta);
          }

          resultLink.addEventListener('click', function () {
            hideResults();
            if (typeof settings.onNavigate === 'function') {
              settings.onNavigate(match);
            }
          });

          resultsPanel.appendChild(resultLink);
        });
      }

      resultsPanel.hidden = false;
    };

    const submitSearch = function () {
      const matches = getSearchMatches(searchIndex, input.value);
      if (!matches.length) {
        renderResults([], input.value);
        return;
      }

      if (typeof settings.onNavigate === 'function') {
        settings.onNavigate(matches[0]);
      }
      window.location.href = matches[0].href;
    };

    input.addEventListener('input', function () {
      renderResults(getSearchMatches(searchIndex, input.value), input.value);
    });

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Enter') {
        event.preventDefault();
        submitSearch();
      } else if (event.key === 'Escape') {
        if (resultsPanel.hidden) {
          if (typeof settings.onEscape === 'function') {
            settings.onEscape();
          }
        } else {
          hideResults();
        }
      }
    });

    button.addEventListener('click', function () {
      submitSearch();
    });

    document.addEventListener('click', function (event) {
      if (!searchContainer.contains(event.target)) {
        hideResults();
      }
    });

    return {
      input,
      hideResults
    };
  };

  document.querySelectorAll('.mobile-nav-search').forEach(function (searchContainer) {
    const navLinks = searchContainer.closest('.nav-links');
    initSearchContainer(searchContainer, {
      navLinks,
      onNavigate: function () {
        if (navLinks) {
          navLinks.classList.remove('active');
        }
      }
    });
  });

  const initDesktopNavSearch = function () {
    const navCta = document.querySelector('.nav-cta');
    const navLinks = document.querySelector('.nav-links');
    if (!navCta || !navLinks || navCta.querySelector('.desktop-nav-search')) {
      return;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'desktop-nav-search';
    wrapper.innerHTML = [
      '<button class="desktop-nav-search-toggle" type="button" aria-label="Open search" aria-expanded="false">',
      '  <i class="fa-solid fa-magnifying-glass"></i>',
      '</button>',
      '<div class="desktop-nav-search-panel" hidden>',
      '  <div class="desktop-nav-search-form">',
      '    <input type="text" placeholder="Search the site" aria-label="Search the site" />',
      '    <button type="button" aria-label="Search">',
      '      <i class="fa-solid fa-magnifying-glass"></i>',
      '    </button>',
      '  </div>',
      '</div>'
    ].join('');
    navCta.prepend(wrapper);

    const toggle = wrapper.querySelector('.desktop-nav-search-toggle');
    const panel = wrapper.querySelector('.desktop-nav-search-panel');
    const searchContainer = wrapper.querySelector('.desktop-nav-search-form');
    let searchApi = null;

    const closePanel = function () {
      wrapper.classList.remove('is-open');
      panel.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      if (searchApi) {
        searchApi.hideResults();
      }
    };

    const openPanel = function () {
      if (mobileQuery.matches) {
        return;
      }
      wrapper.classList.add('is-open');
      panel.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
      if (searchApi) {
        searchApi.input.focus();
        searchApi.input.select();
      }
    };

    searchApi = initSearchContainer(searchContainer, {
      navLinks,
      onNavigate: function () {
        closePanel();
      },
      onEscape: function () {
        closePanel();
      }
    });

    if (!searchApi) {
      wrapper.remove();
      return;
    }

    toggle.addEventListener('click', function () {
      if (wrapper.classList.contains('is-open')) {
        closePanel();
      } else {
        openPanel();
      }
    });

    document.addEventListener('click', function (event) {
      if (!wrapper.contains(event.target)) {
        closePanel();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && wrapper.classList.contains('is-open')) {
        closePanel();
      }
    });

    const syncDesktopSearch = function () {
      if (mobileQuery.matches) {
        closePanel();
      }
    };

    if (typeof mobileQuery.addEventListener === 'function') {
      mobileQuery.addEventListener('change', syncDesktopSearch);
    } else if (typeof mobileQuery.addListener === 'function') {
      mobileQuery.addListener(syncDesktopSearch);
    }
  };

  initDesktopNavSearch();

  let mobileCta = document.querySelector('.floating-cta');
  if (!mobileCta) {
    mobileCta = document.createElement('a');
    mobileCta.href = 'contact.html';
    mobileCta.className = 'floating-cta mobile-hidden';
    mobileCta.setAttribute('aria-label', 'Schedule your consultation today');
    mobileCta.textContent = 'Schedule Your Consultation Today';
    document.body.appendChild(mobileCta);
  } else if (!mobileCta.classList.contains('mobile-hidden')) {
    mobileCta.classList.add('mobile-hidden');
  }

  const getHeroSection = function () {
    const selector = [
      '.hero',
      '.hero-section',
      '.page-hero',
      '.hero-banner',
      '.hero-slider',
      '.hero-area',
      '.hero-wrapper',
      '.hero-wrap',
      '.hero-block',
      '.banner',
      '.page-banner',
      'section[id*="hero"]',
      'section[class*="hero"]'
    ].join(',');
    return document.querySelector(selector);
  };

  const isMobileViewport = function () {
    const isNarrow = window.innerWidth <= 1200;
    const isTouch = navigator.maxTouchPoints > 0 || navigator.msMaxTouchPoints > 0;
    const isCoarse = window.matchMedia && window.matchMedia('(pointer: coarse)').matches;
    const isUA = /Mobi|Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
    return isNarrow || isTouch || isCoarse || isUA;
  };

  const setMobileMode = function () {
    if (isMobileViewport()) {
      document.body.classList.add('mobile-cta-mode');
      if (mobileCta) {
        mobileCta.style.left = '50%';
        mobileCta.style.right = 'auto';
        mobileCta.style.transform = 'translateX(-50%)';
        mobileCta.style.bottom = '1rem';
      }
    } else {
      document.body.classList.remove('mobile-cta-mode');
      if (mobileCta) {
        mobileCta.style.left = '';
        mobileCta.style.right = '';
        mobileCta.style.transform = '';
        mobileCta.style.bottom = '';
      }
    }
  };

  const updateMobileCta = function () {
    if (!mobileCta) {
      return;
    }

    if (!isMobileViewport()) {
      mobileCta.classList.add('is-visible');
      return;
    }

    const heroSection = getHeroSection();
    if (!heroSection) {
      mobileCta.classList.add('is-visible');
      return;
    }

    const scrollY = window.scrollY || window.pageYOffset || 0;
    const heroRect = heroSection.getBoundingClientRect();
    const heroHeight = heroRect.height || heroSection.offsetHeight || heroSection.scrollHeight || 0;
    const minHeroHeight = 240;
    const safeHeroHeight = heroHeight < minHeroHeight ? minHeroHeight : heroHeight;
    const heroBottom = heroSection.offsetTop + safeHeroHeight;

    if (scrollY > heroBottom - 20) {
      mobileCta.classList.add('is-visible');
    } else {
      mobileCta.classList.remove('is-visible');
    }
  };

  setMobileMode();
  requestAnimationFrame(function() {
    updateMobileCta();
  });
  setTimeout(function () {
    setMobileMode();
    updateMobileCta();
  }, 350);
  window.addEventListener('scroll', updateMobileCta, { passive: true });
  window.addEventListener('load', updateMobileCta, { passive: true });
  window.addEventListener('resize', function () {
    setMobileMode();
    updateMobileCta();
  }, { passive: true });

  const dropdowns = document.querySelectorAll('.nav-dropdown');
  const navClose = document.querySelector('.mobile-nav-close');
  const navLinks = document.querySelector('.nav-links');

  if (navClose && navLinks) {
    navClose.addEventListener('click', function () {
      navLinks.classList.remove('active');
    });
  }

  if (dropdowns.length) {
    const hoverQuery = window.matchMedia('(hover: hover)');

    const setExpanded = function (toggle, isExpanded) {
      toggle.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
    };

    const closeDropdowns = function (exceptDropdown) {
      dropdowns.forEach((dropdown) => {
        if (dropdown === exceptDropdown) {
          return;
        }
        dropdown.classList.remove('open');
        const toggle = dropdown.querySelector('.nav-dropdown-toggle');
        if (toggle) {
          setExpanded(toggle, false);
        }
      });
    };

    const updateForViewport = function () {
      const isMobile = mobileQuery.matches;
      dropdowns.forEach((dropdown) => {
        dropdown.hidden = false;
      });

      if (isMobile) {
        closeDropdowns();
      }
    };

    dropdowns.forEach((dropdown) => {
      const toggle = dropdown.querySelector('.nav-dropdown-toggle');
      if (!toggle) {
        return;
      }

      let closeTimer;

      const openDropdown = function () {
        closeDropdowns(dropdown);
        dropdown.classList.add('open');
        setExpanded(toggle, true);
      };

      const closeDropdown = function () {
        dropdown.classList.remove('open');
        setExpanded(toggle, false);
      };

      if (hoverQuery.matches) {
        dropdown.addEventListener('mouseenter', function () {
          if (mobileQuery.matches) {
            return;
          }
          clearTimeout(closeTimer);
          openDropdown();
        });

        dropdown.addEventListener('mouseleave', function () {
          if (mobileQuery.matches) {
            return;
          }
          clearTimeout(closeTimer);
          closeTimer = setTimeout(closeDropdown, 140);
        });
      }

      toggle.addEventListener('click', function (event) {
        event.preventDefault();
        if (dropdown.classList.contains('open')) {
          closeDropdown();
        } else {
          openDropdown();
        }
      });
    });

    updateForViewport();

    document.addEventListener('click', function (event) {
      if (!event.target.closest('.nav-dropdown')) {
        closeDropdowns();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        closeDropdowns();
      }
    });

    if (typeof mobileQuery.addEventListener === 'function') {
      mobileQuery.addEventListener('change', updateForViewport);
    } else if (typeof mobileQuery.addListener === 'function') {
      mobileQuery.addListener(updateForViewport);
    }
  }

  if (typeof mobileQuery.addEventListener === 'function') {
    mobileQuery.addEventListener('change', syncMobileOnlyNavSections);
  } else if (typeof mobileQuery.addListener === 'function') {
    mobileQuery.addListener(syncMobileOnlyNavSections);
  }

  window.addEventListener('load', syncMobileOnlyNavSections);
  window.addEventListener('pageshow', syncMobileOnlyNavSections);
  window.addEventListener('resize', syncMobileOnlyNavSections);
  window.addEventListener('orientationchange', syncMobileOnlyNavSections);
});
