import http from 'k6/http';
import { check, group, sleep } from 'k6';
import { Trend, Rate } from 'k6/metrics';

const BASE_URL = (__ENV.BASE_URL || 'http://localhost').replace(/\/$/, '');
const HOME_PATH = __ENV.HOME_PATH || '/';
const CATEGORY_PATH = __ENV.CATEGORY_PATH || '/categoria/sample-category';
const PRODUCT_PATH = __ENV.PRODUCT_PATH || '/prodotto/sample-product';
const SEARCH_PATH = __ENV.SEARCH_PATH || '/?s=sample';

const DURATION = __ENV.DURATION || '2m';
const RATE = parseInt(__ENV.RATE || '10', 10);
const PREALLOCATED_VUS = parseInt(__ENV.VUS || '20', 10);
const MAX_VUS = parseInt(__ENV.MAX_VUS || '50', 10);

const homeTrend = new Trend('page_home_duration', true);
const categoryTrend = new Trend('page_category_duration', true);
const productTrend = new Trend('page_product_duration', true);
const searchTrend = new Trend('page_search_duration', true);
const errorRate = new Rate('http_error_rate');

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

function hit(path, trend, tag) {
  const url = `${BASE_URL}${path}`;
  const res = http.get(url, { tags: { page: tag } });
  const ok = check(res, {
    'status is < 400': (r) => r.status < 400,
  });
  errorRate.add(!ok);
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
