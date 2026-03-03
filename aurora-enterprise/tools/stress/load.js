import http from 'k6/http';
import { check, group, sleep, fail } from 'k6';
import { Trend, Rate, Counter } from 'k6/metrics';

const BASE_URL = (__ENV.BASE_URL || 'http://localhost').replace(/\/$/, '');
const HOME_PATH = __ENV.HOME_PATH || '/';
const CATEGORY_PATH = __ENV.CATEGORY_PATH || '/categoria/sample-category';
const PRODUCT_PATH = __ENV.PRODUCT_PATH || '/prodotto/sample-product';
const SEARCH_PATH = __ENV.SEARCH_PATH || '/?s=sample';

const DURATION = __ENV.DURATION || '2m';
const RATE = parseInt(__ENV.RATE || '10', 10);
const PREALLOCATED_VUS = parseInt(__ENV.VUS || '20', 10);
const MAX_VUS = parseInt(__ENV.MAX_VUS || '50', 10);
const MAX_ERROR_SAMPLES = parseInt(__ENV.MAX_ERROR_SAMPLES || '20', 10);

const homeTrend = new Trend('page_home_duration', true);
const categoryTrend = new Trend('page_category_duration', true);
const productTrend = new Trend('page_product_duration', true);
const searchTrend = new Trend('page_search_duration', true);
const errorRate = new Rate('http_error_rate');
const httpStatusCount = new Counter('http_status_count');
const httpErrorCount = new Counter('http_error_count');
const status200 = new Counter('http_status_200');
const status301 = new Counter('http_status_301');
const status302 = new Counter('http_status_302');
const status400 = new Counter('http_status_400');
const status401 = new Counter('http_status_401');
const status403 = new Counter('http_status_403');
const status404 = new Counter('http_status_404');
const status429 = new Counter('http_status_429');
const status500 = new Counter('http_status_500');
const status502 = new Counter('http_status_502');
const status503 = new Counter('http_status_503');
const status504 = new Counter('http_status_504');
const status0 = new Counter('http_status_s0');
const statusOther = new Counter('http_status_other');
const errorEndpointHome = new Counter('http_error_endpoint_home');
const errorEndpointCategory = new Counter('http_error_endpoint_category');
const errorEndpointProduct = new Counter('http_error_endpoint_product');
const errorEndpointSearch = new Counter('http_error_endpoint_search');

const errorSamples = [];

const ENDPOINTS = [
  { label: 'home', path: HOME_PATH },
  { label: 'category', path: CATEGORY_PATH },
  { label: 'product', path: PRODUCT_PATH },
  { label: 'search', path: SEARCH_PATH },
];

export const options = {
  summaryTrendStats: ['avg', 'min', 'med', 'max', 'p(90)', 'p(95)', 'p(99)'],
  scenarios: {
    load: {
      executor: 'constant-arrival-rate',
      rate: RATE,
      timeUnit: '1s',
      duration: DURATION,
      preAllocatedVUs: PREALLOCATED_VUS,
      maxVUs: MAX_VUS,
    },
  },
  thresholds: {
    http_req_failed: ['rate<0.05'],
    http_error_rate: ['rate<0.05'],
    page_home_duration: ['p(95)<800', 'p(99)<1500'],
    page_category_duration: ['p(95)<1000', 'p(99)<2000'],
    page_product_duration: ['p(95)<1200', 'p(99)<2500'],
    page_search_duration: ['p(95)<1500', 'p(99)<3000'],
  },
  userAgent: 'aurora-stress-k6/1.0',
};

export function setup() {
  for (const endpoint of ENDPOINTS) {
    const url = `${BASE_URL}${endpoint.path}`;
    const res = http.get(url, { tags: { page: endpoint.label, phase: 'precheck' } });
    if (res.status === 0 || res.status >= 400) {
      fail(`[precheck] ${endpoint.label} ${url} status=${res.status}`);
    }
  }
  return { ok: true };
}

function recordErrorSample(sample) {
  if (typeof __VU === 'undefined' || __VU !== 1) {
    return;
  }
  if (errorSamples.length >= MAX_ERROR_SAMPLES) {
    return;
  }
  errorSamples.push(sample);
  console.log(`__ERROR_SAMPLE__${JSON.stringify(sample)}`);
}

function hit(path, trend, tag) {
  const url = `${BASE_URL}${path}`;
  const res = http.get(url, { tags: { page: tag } });
  const status = res.status || 0;
  httpStatusCount.add(1, { status: String(status), endpoint: tag });
  if (status === 200) status200.add(1);
  else if (status === 301) status301.add(1);
  else if (status === 302) status302.add(1);
  else if (status === 400) status400.add(1);
  else if (status === 401) status401.add(1);
  else if (status === 403) status403.add(1);
  else if (status === 404) status404.add(1);
  else if (status === 429) status429.add(1);
  else if (status === 500) status500.add(1);
  else if (status === 502) status502.add(1);
  else if (status === 503) status503.add(1);
  else if (status === 504) status504.add(1);
  else if (status === 0) status0.add(1);
  else statusOther.add(1);
  const ok = check(res, {
    'status is < 400': (r) => r.status < 400,
  });
  errorRate.add(!ok);
  if (!ok) {
    const reason = status === 0 ? (res.error_code || 'status_0') : `http_${status}`;
    httpErrorCount.add(1, { status: String(status), endpoint: tag, reason });
    const sample = {
      endpoint: tag,
      url,
      status,
      error_code: res.error_code || null,
      error: res.error || null,
      body_snippet: status >= 400 && typeof res.body === 'string' ? res.body.slice(0, 200) : null,
    };
    if (tag === 'home') errorEndpointHome.add(1);
    else if (tag === 'category') errorEndpointCategory.add(1);
    else if (tag === 'product') errorEndpointProduct.add(1);
    else if (tag === 'search') errorEndpointSearch.add(1);
    recordErrorSample(sample);
  }
  trend.add(res.timings.duration);
}

export default function () {
  const r = Math.random();
  if (r < 0.40) {
    group('home', () => hit(HOME_PATH, homeTrend, 'home'));
  } else if (r < 0.65) {
    group('category', () => hit(CATEGORY_PATH, categoryTrend, 'category'));
  } else if (r < 0.85) {
    group('product', () => hit(PRODUCT_PATH, productTrend, 'product'));
  } else {
    group('search', () => hit(SEARCH_PATH, searchTrend, 'search'));
  }
  sleep(0.2 + Math.random() * 0.8);
}
