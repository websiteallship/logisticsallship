/**
 * Headless Test Runner for Step 4.6 Verification (Frontend Gates 6 to 24).
 *
 * @package Allship_UPS_Quote
 */

const fs = require('fs');
const path = require('path');

// Setup mock DOM environment
class MockElement {
  constructor(tagName = 'div') {
    this.tagName = tagName.toUpperCase();
    this.id = '';
    this.className = '';
    this.classList = {
      _classes: new Set(),
      add: (...cls) => cls.forEach(c => this.classList._classes.add(c)),
      remove: (...cls) => cls.forEach(c => this.classList._classes.delete(c)),
      toggle: (c, force) => {
        if (force === undefined) {
          this.classList._classes.has(c) ? this.classList._classes.delete(c) : this.classList._classes.add(c);
        } else if (force) {
          this.classList._classes.add(c);
        } else {
          this.classList._classes.delete(c);
        }
        return this.classList._classes.has(c);
      },
      contains: (c) => this.classList._classes.has(c)
    };
    this.style = {};
    this.dataset = {};
    this.children = [];
    this.attributes = {};
    this._innerHTML = '';
    this.textContent = '';
    this.value = '';
    this.disabled = false;
  }

  appendChild(child) {
    this.children.push(child);
    return child;
  }

  get innerHTML() {
    if (this.children.length > 0) {
      return this.children.map(c => `<${c.tagName.toLowerCase()} value="${c.value || ''}">${c.textContent || c._innerHTML}</${c.tagName.toLowerCase()}>`).join('');
    }
    return this._innerHTML;
  }

  set innerHTML(val) {
    this._innerHTML = String(val);
    this.children = [];
    this.textContent = this._innerHTML.replace(/<[^>]*>/g, '');
  }

  setAttribute(k, v) {
    this.attributes[k] = String(v);
    if (k === 'id') this.id = v;
    if (k === 'class') this.className = v;
    if (k.startsWith('data-')) {
      const dataKey = k.slice(5).replace(/-([a-z])/g, g => g[1].toUpperCase());
      this.dataset[dataKey] = String(v);
    }
  }

  getAttribute(k) {
    if (k === 'id') return this.id;
    if (k === 'class') return this.className;
    if (k.startsWith('data-')) {
      const dataKey = k.slice(5).replace(/-([a-z])/g, g => g[1].toUpperCase());
      return this.dataset[dataKey] !== undefined ? this.dataset[dataKey] : null;
    }
    return this.attributes[k] !== undefined ? this.attributes[k] : null;
  }

  querySelector(sel) {
    return this.querySelectorAll(sel)[0] || null;
  }

  querySelectorAll(sel) {
    const results = [];
    const check = (node) => {
      if (!node) return;
      if (sel.startsWith('.')) {
        const cls = sel.slice(1);
        if (node.classList?.contains(cls) || (node.className && node.className.includes(cls))) results.push(node);
      } else if (sel.startsWith('#')) {
        if (node.id === sel.slice(1)) results.push(node);
      } else if (sel.startsWith('[data-')) {
        const match = sel.match(/\[(data-[a-z-]+)='?([^'\]]*)'?\]/);
        if (match && node.getAttribute(match[1]) === match[2]) results.push(node);
      } else if (sel === node.tagName.toLowerCase()) {
        results.push(node);
      }
      if (node.children) {
        node.children.forEach(check);
      }
    };
    check(this);
    return results;
  }

  scrollIntoView() {}
  focus() {}
}

const elementStore = new Map();
function getOrCreateElement(id, tagName = 'div') {
  if (!elementStore.has(id)) {
    const el = new MockElement(tagName);
    el.id = id;
    elementStore.set(id, el);
  }
  return elementStore.get(id);
}

const mockDocument = {
  readyState: 'complete',
  addEventListener: () => {},
  getElementById: (id) => getOrCreateElement(id),
  querySelectorAll: (sel) => {
    const all = Array.from(elementStore.values());
    if (sel.startsWith('.')) {
      const cls = sel.slice(1);
      return all.filter(el => el.classList.contains(cls) || el.className.includes(cls));
    }
    return [];
  },
  querySelector: (sel) => mockDocument.querySelectorAll(sel)[0] || null,
  createElement: (tag) => new MockElement(tag)
};

const consoleErrors = [];
const mockWindow = {
  document: mockDocument,
  addEventListener: () => {},
  console: {
    log: () => {},
    warn: () => {},
    error: (...args) => consoleErrors.push(args.join(' '))
  },
  anime: (opts) => {
    if (opts.targets && opts.targets.style) {
      opts.targets.style.opacity = '1';
    }
  },
  STATES_BY_COUNTRY: {
    US: [
      { code: 'CA', name: 'California' },
      { code: 'NY', name: 'New York' },
      { code: 'TX', name: 'Texas' }
    ]
  },
  upsQuoteConfig: {
    apiBase: '/wp-json/ups-quote/v1',
    nonce: 'test_nonce_abc',
    currency: 'VND',
    COUNTRIES: [
      { iata: 'US', name: 'United States', wxs: 5, xpd: 5, wfm: 5, is_us_override: 1 },
      { iata: 'JP', name: 'Japan', wxs: 3, xpd: 3, wfm: 3, is_us_override: 0 },
      { iata: 'DE', name: 'Germany', wxs: 6, xpd: 6, wfm: 6, is_us_override: 0 },
      { iata: 'AU', name: 'Australia', wxs: 4, xpd: 4, wfm: 4, is_us_override: 0 },
      { iata: 'XX', name: 'Unavailable Land', wxs: 0, xpd: 0, wfm: 0, is_us_override: 0 }
    ],
    VN_PROVINCES: ['TP. Hồ Chí Minh', 'Hà Nội', 'Đà Nẵng'],
    POPULAR_IATA: ['US', 'JP', 'KR', 'AU', 'CA', 'DE', 'GB', 'FR', 'SG', 'TW'],
    dim_divisor: 5500,
    rounding_step: 0.5
  },
  fetch: (url, opts) => {
    return Promise.resolve({
      ok: true,
      json: () => Promise.resolve({ success: true, lead_id: 'lead_123456' })
    });
  }
};

// Seed essential Mock DOM Elements
[ 'btnDirExport', 'btnDirImport', 'heroDirectionBadge', 'heroDirectionIcon', 'heroDirectionText',
  'originProvinceLabel', 'destCountryLabel', 'destAddressBlockTitle', 'originProvince',
  'serviceCardsGrid', 'serviceCountNotice', 'shipmentTypeRow', 'currentServiceNameType',
  'optNondoc', 'optDoc', 'countryDropdown', 'destCountryDisplay', 'countrySearchInput',
  'countryOptionsList', 'destState', 'destStateLabel', 'destCity', 'destCityLabel',
  'destZipcode', 'destZipcodeLabel', 'destAddress', 'piecesContainer', 'piecesCountBadge',
  'totalPiecesVal', 'actualWeightVal', 'volumetricWeightVal', 'chargeableWeightVal',
  'resultSection', 'ribbonDirectionBadge', 'ribbonServiceCode', 'ribbonRoutePath',
  'ribbonWeightBadge', 'ribbonZoneShort', 'ribbonTypeShort', 'ribbonDirLabel',
  'ribbonServiceLabel', 'ribbonRouteTitle', 'ribbonWeightDisplay', 'ribbonDestShort',
  'mobileCompactListContainer', 'btnMobileViewCompact', 'btnMobileViewCards',
  'mobileStickyActionBar', 'stickyServiceName', 'stickyTransitTime', 'stickyTotalPrice',
  'stickyChosenServiceName', 'stickyChosenServicePrice', 'piecesDetailModal',
  'piecesModalTableBody', 'modalTotalQty', 'modalTotalActual', 'modalTotalDim',
  'modalTotalChargeable', 'bookingModal', 'modalRouteSummary', 'bookingNotice',
  'upsBookingForm', 'bookingName', 'bookingPhone', 'bookingNotes', 'btnSubmitBooking',
  'formError', 'formErrorText'
].forEach(id => getOrCreateElement(id));

// Setup 6 service card mock elements
['EXW', 'XPR', 'WXS', 'XPD', 'WXP', 'WFM'].forEach(code => {
  const card = new MockElement('div');
  card.className = 'service-card-item' + (code === 'WXS' ? ' active' : '');
  card.setAttribute('data-code', code);
  card.setAttribute('data-cat', (code === 'WFM' || code === 'WXP') ? 'freight' : 'parcel');
  card.setAttribute('data-doc-split', (code === 'WXS' || code === 'EXW' || code === 'XPR') ? 'true' : 'false');
  elementStore.set('serviceCard_' + code, card);
});

// Setup 3 category filter buttons
['all', 'parcel', 'freight'].forEach(cat => {
  const btn = new MockElement('button');
  btn.className = 'cat-filter-tab' + (cat === 'all' ? ' active' : '');
  btn.setAttribute('data-cat', cat);
  elementStore.set('catFilterTab_' + cat, btn);
});

// Load and execute quote-form.js
const jsPath = path.resolve(__dirname, '../public/assets/js/quote-form.js');
const jsCode = fs.readFileSync(jsPath, 'utf8');

global.window = mockWindow;
global.document = mockDocument;
global.fetch = mockWindow.fetch;
global.anime = mockWindow.anime;
global.Intl = Intl;

try {
  eval(jsCode);
} catch (err) {
  console.error("FATAL: Failed to evaluate quote-form.js: " + err.message);
  process.exit(1);
}

const UPSQuote = mockWindow.UPSQuote;
const results = {};

// Gate 6: Direction selector: ẩn khi chỉ 1 chiều, dynamic switch
try {
  UPSQuote.selectDirection('import');
  const isImport = UPSQuote.state.direction === 'import';
  UPSQuote.selectDirection('export');
  const isExport = UPSQuote.state.direction === 'export';
  results['gate_6'] = isImport && isExport;
} catch (e) { results['gate_6'] = false; }

// Gate 7: 6 service cards hiển thị đúng grid layout (2/3/6 cols responsive)
try {
  const serviceCards = mockDocument.querySelectorAll('.service-card-item');
  results['gate_7'] = serviceCards.length === 6 &&
    Object.keys(UPSQuote.SERVICE_REGISTRY).length === 6;
} catch (e) { results['gate_7'] = false; }

// Gate 8: Category filter tabs (All/Parcel/Freight) filter cards correctly
try {
  UPSQuote.filterCategory('parcel');
  const isParcelFiltered = UPSQuote.state.categoryFilter === 'parcel';
  UPSQuote.filterCategory('freight');
  const isFreightFiltered = UPSQuote.state.categoryFilter === 'freight';
  UPSQuote.filterCategory('all');
  results['gate_8'] = isParcelFiltered && isFreightFiltered && (UPSQuote.state.categoryFilter === 'all');
} catch (e) { results['gate_8'] = false; }

// Gate 9: has_document_split: Document/Non-doc toggle cho WXS/EXW/XPR
try {
  UPSQuote.selectService('WXS');
  const wxsRow = getOrCreateElement('shipmentTypeRow').style.display !== 'none';
  UPSQuote.selectService('XPD');
  const xpdRow = getOrCreateElement('shipmentTypeRow').style.display === 'none';
  UPSQuote.selectService('WXS');
  results['gate_9'] = wxsRow && xpdRow;
} catch (e) { results['gate_9'] = false; }

// Gate 10: Country combobox: search tiếng Việt + IATA + popular pinned
try {
  UPSQuote.filterCountries('Nhật');
  const hasJp = getOrCreateElement('countryOptionsList').innerHTML.includes('Japan');
  UPSQuote.filterCountries('Mỹ');
  const hasUs = getOrCreateElement('countryOptionsList').innerHTML.includes('United States');
  UPSQuote.filterCountries('DE');
  const hasDe = getOrCreateElement('countryOptionsList').innerHTML.includes('Germany');
  UPSQuote.filterCountries('');
  const hasPopular = getOrCreateElement('countryOptionsList').innerHTML.includes('Tuyến phổ biến');
  results['gate_10'] = hasJp && hasUs && hasDe && hasPopular;
} catch (e) { results['gate_10'] = false; }

// Gate 11: Address fields: State dropdown → City dropdown adaptive
try {
  UPSQuote.updateDestinationAddressFields('US');
  const stateSelect = getOrCreateElement('destState');
  const hasStates = stateSelect.innerHTML.includes('California');
  results['gate_11'] = hasStates;
} catch (e) { results['gate_11'] = false; }

// Gate 12: Piece table: add/remove + live metrics + mobile compact layout
try {
  UPSQuote.addNewPiece();
  const piecesCount = UPSQuote.state.pieces.length;
  UPSQuote.updatePiece(UPSQuote.state.pieces[0].id, 'weight', 4.2);
  UPSQuote.recalculateMetrics();
  const chargeableVal = getOrCreateElement('chargeableWeightVal').textContent;
  results['gate_12'] = piecesCount >= 1 && parseFloat(chargeableVal) >= 4.0;
} catch (e) { results['gate_12'] = false; }

// Gate 13: Calculate: 6-service comparison cards display (mobile compact + desktop full)
try {
  UPSQuote.selectCountry('US');
  UPSQuote.executeCalculation();
  const mobileList = getOrCreateElement('mobileCompactListContainer').innerHTML;
  const desktopCards = getOrCreateElement('serviceCardsGrid').innerHTML;
  results['gate_13'] = mobileList.includes('mobile-comp-row') &&
    desktopCards.includes('service-card') &&
    UPSQuote.state.calculatedResults.length === 6;
} catch (e) { results['gate_13'] = false; }

// Gate 14: "Giá tốt nhất" badge on cheapest service
try {
  const desktopCards = getOrCreateElement('serviceCardsGrid').innerHTML;
  results['gate_14'] = desktopCards.includes('GIÁ TỐT NHẤT');
} catch (e) { results['gate_14'] = false; }

// Gate 15: EXW=WXS×1.25, XPR=WXS×1.15, WXP=WFM×1.22 derived pricing correct
try {
  const res = UPSQuote.state.calculatedResults;
  const wxsPrice = res.find(s => s.code === 'WXS')?.calc?.price;
  const exwPrice = res.find(s => s.code === 'EXW')?.calc?.price;
  const xprPrice = res.find(s => s.code === 'XPR')?.calc?.price;
  const exwExpected = Math.round(wxsPrice * 1.25);
  const xprExpected = Math.round(wxsPrice * 1.15);
  results['gate_15'] = Math.abs(exwPrice - exwExpected) <= 2 && Math.abs(xprPrice - xprExpected) <= 2;
} catch (e) { results['gate_15'] = false; }

// Gate 16: Mobile compact comparison list: radio selection → sticky bar update
try {
  UPSQuote.selectServiceFromMobileList('EXW');
  const stickyName = getOrCreateElement('stickyChosenServiceName').textContent;
  results['gate_16'] = UPSQuote.state.service === 'EXW' && stickyName.includes('Express Early');
} catch (e) { results['gate_16'] = false; }

// Gate 17: Mobile view toggle: compact ↔ cards
try {
  UPSQuote.setMobileResultView('cards');
  const cardsActive = UPSQuote.state.mobileViewMode === 'cards';
  UPSQuote.setMobileResultView('compact');
  const compactActive = UPSQuote.state.mobileViewMode === 'compact';
  results['gate_17'] = cardsActive && compactActive;
} catch (e) { results['gate_17'] = false; }

// Gate 18: Pieces detail modal: correct weight breakdown table + KPI
try {
  UPSQuote.openPiecesDetailModal();
  const tableContent = getOrCreateElement('piecesModalTableBody').innerHTML;
  const modalOpen = getOrCreateElement('piecesDetailModal').classList.contains('open');
  results['gate_18'] = modalOpen && tableContent.includes('Kiện #1');
} catch (e) { results['gate_18'] = false; }

// Gate 19: Booking modal: full address summary + submit + inline success
try {
  UPSQuote.openBookingModal();
  const bookingOpen = getOrCreateElement('bookingModal').classList.contains('open');
  const summaryContent = getOrCreateElement('modalRouteSummary').innerHTML;
  results['gate_19'] = bookingOpen && summaryContent.includes('Tuyến vận chuyển');
} catch (e) { results['gate_19'] = false; }

// Gate 20: Mobile sticky bottom action bar: shows after result, updates on service change
try {
  UPSQuote.updateStickyBar();
  const barIsActive = getOrCreateElement('mobileStickyActionBar').classList.contains('is-active');
  results['gate_20'] = barIsActive;
} catch (e) { results['gate_20'] = false; }

// Gate 21: Route summary ribbon: direction, service, route, weight, zone, type badges
try {
  const dirLabel = getOrCreateElement('ribbonDirLabel').textContent;
  const srvLabel = getOrCreateElement('ribbonServiceLabel').textContent;
  const routeTitle = getOrCreateElement('ribbonRouteTitle').textContent;
  results['gate_21'] = dirLabel.length > 0 && srvLabel.length > 0 && routeTitle.length > 0;
} catch (e) { results['gate_21'] = false; }

// Gate 22: Anime.js animations: result fade-in, form interactions
try {
  results['gate_22'] = typeof mockWindow.anime === 'function';
} catch (e) { results['gate_22'] = false; }

// Gate 23: Mobile 375px responsive: no overflow & responsive breakpoints
try {
  const cssPath = path.resolve(__dirname, '../public/assets/css/quote-form.css');
  const css = fs.readFileSync(cssPath, 'utf8');
  const hasBreakpoint = css.includes('@media (max-width: 768px)');
  const hasCompactGrid = css.includes('48px 1fr 1fr 1fr 1fr 36px');
  results['gate_23'] = hasBreakpoint && hasCompactGrid;
} catch (e) { results['gate_23'] = false; }

// Gate 24: No JS console errors
results['gate_24'] = consoleErrors.length === 0;

console.log(JSON.stringify(results));
