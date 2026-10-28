const { getLearnership } = require('./fixtures');

async function getCsrfToken(page) {
  const token = await page.evaluate(() => {
    const el = document.querySelector('input[name="csrf_token"]');
    return el ? el.value : null;
  });
  return token;
}

async function getCsrfTokenFromPage(page, url) {
  await page.goto(url);
  return getCsrfToken(page);
}

async function apiGet(page, url) {
  const response = await page.request.get(url);
  return response;
}

async function apiPostForm(page, url, data) {
  const response = await page.request.post(url, { form: data });
  return response;
}

async function apiPostJson(page, url, data) {
  const response = await page.request.post(url, {
    headers: { 'Content-Type': 'application/json' },
    data: JSON.stringify(data),
  });
  return response;
}

function fixtureLrn() {
  const learnership = getLearnership();
  return learnership.anyLrn || process.env.E2E_LEARNER_LRN || '';
}

function fixtureSchoolId() {
  const learnership = getLearnership();
  return learnership.schoolId || '';
}

module.exports = {
  getCsrfToken,
  getCsrfTokenFromPage,
  apiGet,
  apiPostForm,
  apiPostJson,
  fixtureLrn,
  fixtureSchoolId,
};
