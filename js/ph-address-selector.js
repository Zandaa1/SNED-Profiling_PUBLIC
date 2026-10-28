/**
 * Philippine Geographic Address Selector Data & Helper
 * Supports dynamic dropdown cascade for Region -> Province -> City -> Barangay
 * Fetches data from local JSON files under js/phjson/ covering the entire Philippines.
 *
 * NCR provinces and cities are resolved synchronously from built-in lookup tables so
 * the cascade works immediately without waiting for JSON to load.  Barangays for all
 * cities (including NCR) are fetched asynchronously from the per-city JSON files.
 */

// ── Sync reference data (regions & NCR geography) ─────────────────────────────

const SYNC_REGIONS = [
  { region_name: "Region I (Ilocos Region)", region_code: "01" },
  { region_name: "Region II (Cagayan Valley)", region_code: "02" },
  { region_name: "Region III (Central Luzon)", region_code: "03" },
  { region_name: "Region IV-A (CALABARZON)", region_code: "04" },
  { region_name: "Region IV-B (MIMAROPA)", region_code: "17" },
  { region_name: "Region V (Bicol Region)", region_code: "05" },
  { region_name: "Region VI (Western Visayas)", region_code: "06" },
  { region_name: "Region VII (Central Visayas)", region_code: "07" },
  { region_name: "Region VIII (Eastern Visayas)", region_code: "08" },
  { region_name: "Region IX (Zamboanga Peninzula)", region_code: "09" },
  { region_name: "Region X (Northern Mindanao)", region_code: "10" },
  { region_name: "Region XI (Davao Region)", region_code: "11" },
  { region_name: "Region XII (SOCCSKSARGEN)", region_code: "12" },
  { region_name: "National Capital Region (NCR)", region_code: "13" },
  { region_name: "Cordillera Administrative Region (CAR)", region_code: "14" },
  {
    region_name: "Autonomous Region in Muslim Mindanao (ARMM)",
    region_code: "15",
  },
  { region_name: "Region XIII (Caraga)", region_code: "16" },
];

// NCR districts / pseudo-provinces (matches province.json exactly)
const SYNC_NCR_PROVINCES = [
  {
    province_name: "Ncr, City Of Manila, First District",
    province_code: "1339",
    region_code: "13",
  },
  { province_name: "City Of Manila", province_code: "1339", region_code: "13" },
  {
    province_name: "Ncr, Second District",
    province_code: "1374",
    region_code: "13",
  },
  {
    province_name: "Ncr, Third District",
    province_code: "1375",
    region_code: "13",
  },
  {
    province_name: "Ncr, Fourth District",
    province_code: "1376",
    region_code: "13",
  },
];

// All NCR cities / municipalities with 6-digit city_codes matching the barangay JSON filenames.
// This replaces the old SYNC_NCR_CITIES which only had 4 entries with wrong 9-digit codes.
const NCR_CITIES = [
  // ── First District (Manila districts) ──────────────────────────────────────
  { city_name: "Tondo I / Ii", city_code: "133901", province_code: "1339" },
  { city_name: "Binondo", city_code: "133902", province_code: "1339" },
  { city_name: "Quiapo", city_code: "133903", province_code: "1339" },
  { city_name: "San Nicolas", city_code: "133904", province_code: "1339" },
  { city_name: "Santa Cruz", city_code: "133905", province_code: "1339" },
  { city_name: "Sampaloc", city_code: "133906", province_code: "1339" },
  { city_name: "San Miguel", city_code: "133907", province_code: "1339" },
  { city_name: "Ermita", city_code: "133908", province_code: "1339" },
  { city_name: "Intramuros", city_code: "133909", province_code: "1339" },
  { city_name: "Malate", city_code: "133910", province_code: "1339" },
  { city_name: "Paco", city_code: "133911", province_code: "1339" },
  { city_name: "Pandacan", city_code: "133912", province_code: "1339" },
  { city_name: "Port Area", city_code: "133913", province_code: "1339" },
  { city_name: "Santa Ana", city_code: "133914", province_code: "1339" },
  // ── Second District ─────────────────────────────────────────────────────────
  {
    city_name: "City Of Mandaluyong",
    city_code: "137401",
    province_code: "1374",
  },
  { city_name: "City Of Marikina", city_code: "137402", province_code: "1374" },
  { city_name: "City Of Pasig", city_code: "137403", province_code: "1374" },
  { city_name: "Quezon City", city_code: "137404", province_code: "1374" },
  { city_name: "City Of San Juan", city_code: "137405", province_code: "1374" },
  // ── Third District ──────────────────────────────────────────────────────────
  { city_name: "Caloocan City", city_code: "137501", province_code: "1375" },
  { city_name: "City Of Malabon", city_code: "137502", province_code: "1375" },
  { city_name: "City Of Navotas", city_code: "137503", province_code: "1375" },
  {
    city_name: "City Of Valenzuela",
    city_code: "137504",
    province_code: "1375",
  },
  // ── Fourth District ─────────────────────────────────────────────────────────
  {
    city_name: "City Of Las Piñas",
    city_code: "137601",
    province_code: "1376",
  },
  { city_name: "City Of Makati", city_code: "137602", province_code: "1376" },
  {
    city_name: "City Of Muntinlupa",
    city_code: "137603",
    province_code: "1376",
  },
  {
    city_name: "City Of Parañaque",
    city_code: "137604",
    province_code: "1376",
  },
  { city_name: "Pasay City", city_code: "137605", province_code: "1376" },
  { city_name: "Pateros", city_code: "137606", province_code: "1376" },
  { city_name: "Taguig City", city_code: "137607", province_code: "1376" },
];

// ── Async address data (provinces + cities for non-NCR regions) ────────────────

let regionsData = SYNC_REGIONS;
let provincesData = null;
let citiesData = null;

let addressDataPromise = null;
function loadAddressData() {
  if (!addressDataPromise) {
    addressDataPromise = Promise.all([
      fetch("js/phjson/region.json").then((r) => r.json()),
      fetch("js/phjson/province.json").then((r) => r.json()),
      fetch("js/phjson/city.json").then((r) => r.json()),
    ])
      .then(([regions, provinces, cities]) => {
        regionsData = regions;
        provincesData = provinces;
        citiesData = cities;
      })
      .catch((err) => {
        console.error(
          "Failed to load address data from local JSON files.",
          err,
        );
      });
  }
  return addressDataPromise;
}

// ── Name-normalisation helpers ─────────────────────────────────────────────────

function normalizeRegionName(name) {
  if (!name) return "";
  let n = name.trim().toLowerCase();
  if (n === "ncr" || n.includes("national capital region")) return "ncr";
  if (n === "r3" || n.includes("region iii") || n.includes("central luzon"))
    return "r3";
  if (n === "r4a" || n.includes("region iv-a") || n.includes("calabarzon"))
    return "r4a";
  return n;
}

function normalizeProvinceName(name) {
  if (!name) return "";
  let n = name.trim().toLowerCase();
  // Collapse all NCR district labels to a single sentinel so we can detect NCR easily
  if (
    n === "metro manila" ||
    n.includes("ncr,") ||
    n === "city of manila" ||
    n.includes("first district") ||
    n.includes("second district") ||
    n.includes("third district") ||
    n.includes("fourth district")
  )
    return "metro manila";
  return n;
}

function normalizeCityName(name) {
  if (!name) return "";
  return name
    .replace(/^City\s+Of\s+/i, "")
    .replace(/\s+City$/i, "")
    .trim()
    .toLowerCase();
}

function normalizeBarangayName(name) {
  if (!name) return "";
  return name.trim().toLowerCase();
}

// ── Loading-state helpers ──────────────────────────────────────────────────────

function showLoadingState(prefix) {
  const regSel = document.getElementById(prefix + "_region");
  const provSel = document.getElementById(prefix + "_province");
  const citySel = document.getElementById(prefix + "_city");
  const brgySel = document.getElementById(
    prefix === "g" ? "barangay" : prefix + "_barangay",
  );

  if (regSel) {
    regSel.innerHTML = '<option value="">Loading address data...</option>';
    regSel.disabled = true;
  }
  if (provSel) {
    provSel.innerHTML = '<option value="">Loading...</option>';
    provSel.disabled = true;
  }
  if (citySel) {
    citySel.innerHTML = '<option value="">Loading...</option>';
    citySel.disabled = true;
  }
  if (brgySel) {
    brgySel.innerHTML = '<option value="">Loading...</option>';
    brgySel.disabled = true;
  }
}

function removeLoadingState(prefix) {
  const regSel = document.getElementById(prefix + "_region");
  const provSel = document.getElementById(prefix + "_province");
  const citySel = document.getElementById(prefix + "_city");
  const brgySel = document.getElementById(
    prefix === "g" ? "barangay" : prefix + "_barangay",
  );

  if (regSel) regSel.disabled = false;
  if (provSel) provSel.disabled = false;
  if (citySel) citySel.disabled = false;
  if (brgySel) brgySel.disabled = false;
}

// ── Cascade population functions ───────────────────────────────────────────────

function populateRegions(regSel, regInput) {
  regSel.innerHTML = '<option value="">Select Region</option>';
  if (regionsData) {
    regionsData.forEach((r) => {
      regSel.innerHTML += `<option value="${r.region_name}">${r.region_name}</option>`;
    });
  }
  regSel.innerHTML += '<option value="other">Other Region</option>';
}

function populateProvinces(regionName, provSel, provInput) {
  provSel.innerHTML = '<option value="">Select Province</option>';

  // NCR: always use the built-in sync list
  if (normalizeRegionName(regionName) === "ncr") {
    SYNC_NCR_PROVINCES.forEach((p) => {
      provSel.innerHTML += `<option value="${p.province_name}">${p.province_name}</option>`;
    });
    provSel.innerHTML += '<option value="other">Other Province</option>';
    return;
  }

  if (regionsData && provincesData) {
    const regionObj = regionsData.find(
      (r) =>
        normalizeRegionName(r.region_name) === normalizeRegionName(regionName),
    );
    if (regionObj) {
      const provinces = provincesData.filter(
        (p) => p.region_code === regionObj.region_code,
      );
      provinces.forEach((p) => {
        provSel.innerHTML += `<option value="${p.province_name}">${p.province_name}</option>`;
      });
    }
  }
  provSel.innerHTML += '<option value="other">Other Province</option>';
}

function populateCities(provinceName, citySel, cityInput) {
  citySel.innerHTML = '<option value="">Select City/Municipality</option>';

  // NCR: look up the exact province_code then filter NCR_CITIES
  if (normalizeProvinceName(provinceName) === "metro manila") {
    const normalizedInput = provinceName.trim().toLowerCase();

    // Exact case-insensitive match to distinguish the four NCR districts
    const provObj =
      SYNC_NCR_PROVINCES.find(
        (p) => p.province_name.trim().toLowerCase() === normalizedInput,
      ) ||
      (provincesData &&
        provincesData.find(
          (p) => p.province_name.trim().toLowerCase() === normalizedInput,
        ));
    const provCode = provObj ? provObj.province_code : null;

    if (provCode) {
      NCR_CITIES.filter((c) => c.province_code === provCode).forEach((c) => {
        citySel.innerHTML += `<option value="${c.city_name}">${c.city_name}</option>`;
      });
    } else {
      // Fallback: show all NCR cities when district cannot be resolved
      NCR_CITIES.forEach((c) => {
        citySel.innerHTML += `<option value="${c.city_name}">${c.city_name}</option>`;
      });
    }
    citySel.innerHTML +=
      '<option value="other">Other City/Municipality</option>';
    return;
  }

  if (provincesData && citiesData) {
    const provinceObj = provincesData.find(
      (p) =>
        normalizeProvinceName(p.province_name) ===
        normalizeProvinceName(provinceName),
    );
    if (provinceObj) {
      const cities = citiesData.filter(
        (c) => c.province_code === provinceObj.province_code,
      );
      cities.forEach((c) => {
        citySel.innerHTML += `<option value="${c.city_name}">${c.city_name}</option>`;
      });
    }
  }
  citySel.innerHTML += '<option value="other">Other City/Municipality</option>';
}

/**
 * Populate the barangay dropdown.
 * NCR cities are resolved directly from NCR_CITIES (no async data needed).
 * All other cities use province.json + city.json to find the city_code.
 * Returns a Promise that resolves when population is complete.
 */
function populateBarangays(cityName, brgySel, brgyInput, provinceName) {
  brgySel.innerHTML = '<option value="">Select Barangay</option>';

  // ── NCR fast path: city_code is in NCR_CITIES, no loadAddressData() needed ──
  const ncrCity = NCR_CITIES.find(
    (c) => normalizeCityName(c.city_name) === normalizeCityName(cityName),
  );
  if (ncrCity) {
    brgySel.innerHTML = '<option value="">Loading...</option>';
    brgySel.disabled = true;
    return fetch(`js/phjson/barangays/${ncrCity.city_code}.json`)
      .then((r) => r.json())
      .then((barangays) => {
        brgySel.innerHTML = '<option value="">Select Barangay</option>';
        brgySel.disabled = false;
        // Normalise a known variant spelling used in the source data
        barangays
          .map((b) => (b === "Hen. T. De Leon" ? "Gen. T. de Leon" : b))
          .forEach((b) => {
            brgySel.innerHTML += `<option value="${b}">${b}</option>`;
          });
        brgySel.innerHTML += '<option value="other">Other Barangay</option>';
      })
      .catch((err) => {
        console.error(
          `Failed to load barangays for ${cityName} (${ncrCity.city_code})`,
          err,
        );
        brgySel.innerHTML = '<option value="">Select Barangay</option>';
        brgySel.disabled = false;
        brgySel.innerHTML += '<option value="other">Other Barangay</option>';
      });
  }

  // ── Non-NCR: look up city_code from the async-loaded data ─────────────────
  if (provincesData && citiesData) {
    const provinceObj = provincesData.find(
      (p) =>
        normalizeProvinceName(p.province_name) ===
        normalizeProvinceName(provinceName),
    );
    if (provinceObj) {
      const cityObj = citiesData.find(
        (c) =>
          normalizeCityName(c.city_name) === normalizeCityName(cityName) &&
          c.province_code === provinceObj.province_code,
      );
      if (cityObj) {
        brgySel.innerHTML = '<option value="">Loading...</option>';
        brgySel.disabled = true;
        return fetch(`js/phjson/barangays/${cityObj.city_code}.json`)
          .then((r) => r.json())
          .then((barangays) => {
            brgySel.innerHTML = '<option value="">Select Barangay</option>';
            brgySel.disabled = false;
            barangays.forEach((b) => {
              brgySel.innerHTML += `<option value="${b}">${b}</option>`;
            });
            brgySel.innerHTML +=
              '<option value="other">Other Barangay</option>';
          })
          .catch((err) => {
            console.error(
              `Failed to load barangays for city code ${cityObj.city_code}`,
              err,
            );
            brgySel.innerHTML = '<option value="">Select Barangay</option>';
            brgySel.disabled = false;
            brgySel.innerHTML +=
              '<option value="other">Other Barangay</option>';
          });
      }
    }
  }

  brgySel.innerHTML += '<option value="other">Other Barangay</option>';
  return Promise.resolve();
}

// ── initAddressCascader ────────────────────────────────────────────────────────

/**
 * Setup address cascading controls
 * @param {string} prefix Selector prefix (e.g. "g", "mo", "fa")
 */
function initAddressCascader(prefix) {
  const regSel = document.getElementById(prefix + "_region");
  const provSel = document.getElementById(prefix + "_province");
  const citySel = document.getElementById(prefix + "_city");
  const brgySel = document.getElementById(
    prefix === "g" ? "barangay" : prefix + "_barangay",
  );

  const regInput = document.getElementById(prefix + "_region_custom");
  const provInput = document.getElementById(prefix + "_province_custom");
  const cityInput = document.getElementById(prefix + "_city_custom");
  const brgyInput = document.getElementById(prefix + "_barangay_custom");

  if (!regSel) return;

  // 1. Synchronous default state (NCR / Third District / Valenzuela) ──────────

  regSel.innerHTML = '<option value="">Select Region</option>';
  SYNC_REGIONS.forEach((r) => {
    regSel.innerHTML += `<option value="${r.region_name}">${r.region_name}</option>`;
  });
  regSel.innerHTML += '<option value="other">Other Region</option>';
  regSel.value = "National Capital Region (NCR)";
  regInput.classList.add("d-none");
  regInput.required = false;
  regInput.value = "";

  provSel.innerHTML = '<option value="">Select Province</option>';
  SYNC_NCR_PROVINCES.forEach((p) => {
    provSel.innerHTML += `<option value="${p.province_name}">${p.province_name}</option>`;
  });
  provSel.innerHTML += '<option value="other">Other Province</option>';
  provSel.value = "Ncr, Third District";
  provInput.classList.add("d-none");
  provInput.required = false;
  provInput.value = "";

  citySel.innerHTML = '<option value="">Select City/Municipality</option>';
  NCR_CITIES.filter((c) => c.province_code === "1375").forEach((c) => {
    citySel.innerHTML += `<option value="${c.city_name}">${c.city_name}</option>`;
  });
  citySel.innerHTML += '<option value="other">Other City/Municipality</option>';
  citySel.value = "City Of Valenzuela";
  cityInput.classList.add("d-none");
  cityInput.required = false;
  cityInput.value = "";

  // Barangay default: async-loaded from 137504.json (NCR_CITIES lookup, no wait needed)
  brgySel.innerHTML = '<option value="">Loading...</option>';
  brgySel.disabled = true;
  brgyInput.classList.add("d-none");
  brgyInput.required = false;
  brgyInput.value = "";

  // 2. Kick off background data load and populate Valenzuela barangays ────────
  loadAddressData();
  populateBarangays(
    "City Of Valenzuela",
    brgySel,
    brgyInput,
    "Ncr, Third District",
  );

  // 3. Helper: run action only after province/city data is available ──────────
  function runWithData(action) {
    if (provincesData && citiesData) {
      action();
    } else {
      provSel.disabled = true;
      citySel.disabled = true;
      brgySel.disabled = true;
      loadAddressData().then(() => {
        provSel.disabled = false;
        citySel.disabled = false;
        brgySel.disabled = false;
        action();
      });
    }
  }

  // 4. Event listeners ────────────────────────────────────────────────────────

  regSel.addEventListener("change", function () {
    const regVal = this.value;
    if (regVal === "other") {
      regInput.classList.remove("d-none");
      regInput.required = true;
      provSel.innerHTML = '<option value="other">Other Province</option>';
      provSel.value = "other";
      provSel.dispatchEvent(new Event("change"));
    } else if (normalizeRegionName(regVal) === "ncr") {
      regInput.classList.add("d-none");
      regInput.required = false;
      regInput.value = "";
      populateProvinces(regSel.value, provSel, provInput);
      provSel.value = "";
      provSel.dispatchEvent(new Event("change"));
    } else {
      regInput.classList.add("d-none");
      regInput.required = false;
      regInput.value = "";
      runWithData(() => {
        populateProvinces(regSel.value, provSel, provInput);
        provSel.value = "";
        provSel.dispatchEvent(new Event("change"));
      });
    }
  });

  provSel.addEventListener("change", function () {
    const provVal = this.value;
    if (provVal === "other") {
      provInput.classList.remove("d-none");
      provInput.required = true;
      citySel.innerHTML =
        '<option value="other">Other City/Municipality</option>';
      citySel.value = "other";
      citySel.dispatchEvent(new Event("change"));
    } else if (normalizeProvinceName(provVal) === "metro manila") {
      provInput.classList.add("d-none");
      provInput.required = false;
      provInput.value = "";
      // NCR cities resolved sync from NCR_CITIES
      populateCities(provSel.value, citySel, cityInput);
      citySel.value = "";
      citySel.dispatchEvent(new Event("change"));
    } else {
      provInput.classList.add("d-none");
      provInput.required = false;
      provInput.value = "";
      runWithData(() => {
        populateCities(provSel.value, citySel, cityInput);
        citySel.value = "";
        citySel.dispatchEvent(new Event("change"));
      });
    }
  });

  citySel.addEventListener("change", function () {
    const cityVal = this.value;
    if (cityVal === "other") {
      cityInput.classList.remove("d-none");
      cityInput.required = true;
      brgySel.innerHTML = '<option value="other">Other Barangay</option>';
      brgySel.value = "other";
      brgySel.dispatchEvent(new Event("change"));
    } else {
      cityInput.classList.add("d-none");
      cityInput.required = false;
      cityInput.value = "";

      // NCR: NCR_CITIES has the city_code so no data loading needed
      const isNcrCity = NCR_CITIES.some(
        (c) => normalizeCityName(c.city_name) === normalizeCityName(cityVal),
      );
      if (isNcrCity) {
        populateBarangays(
          citySel.value,
          brgySel,
          brgyInput,
          provSel.value,
        ).then(() => {
          brgySel.value = "";
          brgySel.dispatchEvent(new Event("change"));
        });
      } else {
        runWithData(() => {
          populateBarangays(
            citySel.value,
            brgySel,
            brgyInput,
            provSel.value,
          ).then(() => {
            brgySel.value = "";
            brgySel.dispatchEvent(new Event("change"));
          });
        });
      }
    }
  });

  brgySel.addEventListener("change", function () {
    const brgyVal = this.value;
    if (brgyVal === "other") {
      brgyInput.classList.remove("d-none");
      brgyInput.required = true;
    } else {
      brgyInput.classList.add("d-none");
      brgyInput.required = false;
      brgyInput.value = "";
    }
  });
}

// ── loadSavedAddress ───────────────────────────────────────────────────────────

/**
 * Load saved address values into cascading selectors.
 * @param {string} prefix Selector prefix (e.g. "g", "mo", "fa")
 * @param {object} data   Object with { region, province, city, barangay }
 */
function loadSavedAddress(prefix, data) {
  if (!data) return;

  const regSel = document.getElementById(prefix + "_region");
  const provSel = document.getElementById(prefix + "_province");
  const citySel = document.getElementById(prefix + "_city");
  const brgySel = document.getElementById(
    prefix === "g" ? "barangay" : prefix + "_barangay",
  );

  const regInput = document.getElementById(prefix + "_region_custom");
  const provInput = document.getElementById(prefix + "_province_custom");
  const cityInput = document.getElementById(prefix + "_city_custom");
  const brgyInput = document.getElementById(prefix + "_barangay_custom");

  if (!regSel) return;

  // Normalise legacy / shorthand region names stored in older records
  if (data.region === "NCR") data.region = "National Capital Region (NCR)";
  if (data.region === "R3") data.region = "Region III (Central Luzon)";
  if (data.region === "R4A") data.region = "Region IV-A (CALABARZON)";

  // Map "Metro Manila" province to the correct NCR district using city lookup
  if (data.province === "Metro Manila" && data.city) {
    const ncrCity = NCR_CITIES.find(
      (c) => normalizeCityName(c.city_name) === normalizeCityName(data.city),
    );
    if (ncrCity) {
      const provObj = SYNC_NCR_PROVINCES.find(
        (p) => p.province_code === ncrCity.province_code,
      );
      if (provObj) data.province = provObj.province_name;
    }
  }

  // ── Fast path: any NCR address (province + city resolved sync) ──────────────
  const isNcrAddress =
    data.region &&
    normalizeRegionName(data.region) === "ncr" &&
    data.province &&
    normalizeProvinceName(data.province) === "metro manila";

  if (isNcrAddress) {
    // Region
    const matchReg = Array.from(regSel.options).find(
      (o) => normalizeRegionName(o.value) === "ncr",
    );
    if (matchReg) regSel.value = matchReg.value;

    // Province — use exact match to preserve district identity
    populateProvinces(regSel.value, provSel, provInput);
    const matchProv = Array.from(provSel.options).find(
      (o) =>
        o.value.trim().toLowerCase() === data.province.trim().toLowerCase(),
    );
    if (matchProv) {
      provSel.value = matchProv.value;
    } else {
      provSel.value = "other";
      provInput.classList.remove("d-none");
      provInput.required = true;
      provInput.value = data.province;
    }

    // City — resolved sync from NCR_CITIES
    if (provSel.value !== "other") {
      populateCities(provSel.value, citySel, cityInput);
    }
    if (data.city) {
      const matchCity = Array.from(citySel.options).find(
        (o) => normalizeCityName(o.value) === normalizeCityName(data.city),
      );
      if (matchCity) {
        citySel.value = matchCity.value;
      } else {
        citySel.value = "other";
        cityInput.classList.remove("d-none");
        cityInput.required = true;
        cityInput.value = data.city;
      }
    }

    // Barangay — async fetch via NCR_CITIES city_code (no loadAddressData needed)
    if (citySel.value && citySel.value !== "other" && data.barangay) {
      populateBarangays(citySel.value, brgySel, brgyInput, provSel.value).then(
        () => {
          const matchBrgy = Array.from(brgySel.options).find(
            (o) =>
              normalizeBarangayName(o.value) ===
              normalizeBarangayName(data.barangay),
          );
          if (matchBrgy) {
            brgySel.value = matchBrgy.value;
          } else {
            brgySel.value = "other";
            brgyInput.classList.remove("d-none");
            brgyInput.required = true;
            brgyInput.value = data.barangay;
          }
        },
      );
    }
    return;
  }

  // ── Async path: non-NCR addresses ───────────────────────────────────────────
  loadAddressData().then(() => {
    // Remap "Metro Manila" province for non-NCR records that somehow landed here
    if (
      data.province === "Metro Manila" &&
      data.city &&
      citiesData &&
      provincesData
    ) {
      const cityObj = citiesData.find(
        (c) => normalizeCityName(c.city_name) === normalizeCityName(data.city),
      );
      if (cityObj) {
        const provObj = provincesData.find(
          (p) => p.province_code === cityObj.province_code,
        );
        if (provObj) data.province = provObj.province_name;
      }
    }

    let p1 = Promise.resolve();

    if (data.region) {
      const matchOpt = Array.from(regSel.options).find(
        (o) =>
          normalizeRegionName(o.value) === normalizeRegionName(data.region),
      );
      if (matchOpt) {
        regSel.value = matchOpt.value;
        populateProvinces(regSel.value, provSel, provInput);
      } else {
        regSel.value = "other";
        regInput.classList.remove("d-none");
        regInput.required = true;
        regInput.value = data.region;
        provSel.innerHTML = '<option value="other">Other Province</option>';
        provSel.value = "other";
      }
    }

    if (data.province) {
      const matchOpt = Array.from(provSel.options).find(
        (o) =>
          normalizeProvinceName(o.value) ===
          normalizeProvinceName(data.province),
      );
      if (matchOpt) {
        provSel.value = matchOpt.value;
        populateCities(provSel.value, citySel, cityInput);
      } else {
        provSel.value = "other";
        provInput.classList.remove("d-none");
        provInput.required = true;
        provInput.value = data.province;
        citySel.innerHTML =
          '<option value="other">Other City/Municipality</option>';
        citySel.value = "other";
      }
    }

    if (data.city) {
      const matchOpt = Array.from(citySel.options).find(
        (o) => normalizeCityName(o.value) === normalizeCityName(data.city),
      );
      if (matchOpt) {
        citySel.value = matchOpt.value;
        p1 = populateBarangays(
          citySel.value,
          brgySel,
          brgyInput,
          provSel.value,
        );
      } else {
        citySel.value = "other";
        cityInput.classList.remove("d-none");
        cityInput.required = true;
        cityInput.value = data.city;
        brgySel.innerHTML = '<option value="other">Other Barangay</option>';
        brgySel.value = "other";
      }
    }

    p1.then(() => {
      if (data.barangay) {
        const matchOpt = Array.from(brgySel.options).find(
          (o) =>
            normalizeBarangayName(o.value) ===
            normalizeBarangayName(data.barangay),
        );
        if (matchOpt) {
          brgySel.value = matchOpt.value;
        } else {
          brgySel.value = "other";
          brgyInput.classList.remove("d-none");
          brgyInput.required = true;
          brgyInput.value = data.barangay;
        }
      }
    });
  });
}
