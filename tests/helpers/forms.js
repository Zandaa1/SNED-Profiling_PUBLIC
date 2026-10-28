async function fillIfVisible(locator, value) {
  if (await locator.count()) {
    await locator.fill(value);
  }
}

async function selectIfOptionExists(locator, value) {
  if (!(await locator.count())) return false;
  const option = locator.locator(`option[value="${value.replace(/"/g, '\\"')}"]`);
  if (await option.count()) {
    await locator.selectOption(value);
    return true;
  }
  return false;
}

async function selectFirstRealOption(locator) {
  const values = await locator.locator('option').evaluateAll((options) => options
    .map((option) => option.value)
    .filter((value) => value && value !== ''));
  if (values.length > 0) {
    await locator.selectOption(values[0]);
    return values[0];
  }
  return '';
}

async function fillCustomAddress(page, prefix, values = {}) {
  const address = {
    region: 'NCR',
    province: 'Metro Manila',
    city: 'City Of Valenzuela',
    barangay: 'PWTEST Barangay',
    ...values,
  };

  const fieldMap = prefix === 'g'
    ? { region: '#g_region', province: '#g_province', city: '#g_city', barangay: '#barangay', barangayCustom: '#g_barangay_custom' }
    : { region: `#${prefix}_region`, province: `#${prefix}_province`, city: `#${prefix}_city`, barangay: `#${prefix}_barangay`, barangayCustom: `#${prefix}_barangay_custom` };

  const region = page.locator(fieldMap.region);
  if (await selectIfOptionExists(region, 'other')) {
    await page.locator(`#${prefix}_region_custom`).fill(address.region);
  } else if (await region.count()) {
    await selectFirstRealOption(region);
  }

  const province = page.locator(fieldMap.province);
  if (await selectIfOptionExists(province, 'other')) {
    await page.locator(`#${prefix}_province_custom`).fill(address.province);
  } else if (await province.count()) {
    await selectFirstRealOption(province);
  }

  const city = page.locator(fieldMap.city);
  if (await selectIfOptionExists(city, 'other')) {
    await page.locator(`#${prefix}_city_custom`).fill(address.city);
  } else if (await city.count()) {
    await selectFirstRealOption(city);
  }

  const barangay = page.locator(fieldMap.barangay);
  if (await selectIfOptionExists(barangay, 'other')) {
    await page.locator(fieldMap.barangayCustom).fill(address.barangay);
  } else if (await barangay.count()) {
    await selectFirstRealOption(barangay);
  }
}

module.exports = { fillCustomAddress, fillIfVisible, selectFirstRealOption, selectIfOptionExists };
