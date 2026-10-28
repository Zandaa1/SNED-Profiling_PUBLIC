const { test } = require('@playwright/test');

const LOCAL_WRITE_HOSTS = new Set(['sped.host', 'localhost', '127.0.0.1', '::1']);

function baseUrl() {
  return process.env.E2E_BASE_URL || 'http://localhost:8000';
}

function isLocalWriteHost(urlString = baseUrl()) {
  try {
    const url = new URL(urlString);
    return LOCAL_WRITE_HOSTS.has(url.hostname);
  } catch (_error) {
    return false;
  }
}

function requireLocalWrites() {
  const url = baseUrl();
  test.skip(!isLocalWriteHost(url), `Write tests only run on local hosts. Current E2E_BASE_URL=${url}`);
}

module.exports = { baseUrl, isLocalWriteHost, requireLocalWrites };
