function nowToken() {
  return new Date().toISOString().replace(/[-:.TZ]/g, '').slice(0, 14);
}

function generatedLrn() {
  return `98${Date.now().toString().slice(-10)}`;
}

function currentSchoolYear(now = new Date()) {
  const year = now.getFullYear();
  const month = now.getMonth() + 1;
  return month >= 6 ? `${year}-${year + 1}` : `${year - 1}-${year}`;
}

function learnerData(overrides = {}) {
  const token = nowToken();
  return {
    lrn: generatedLrn(),
    firstName: `PWTEST_${token}`,
    lastName: 'Learner',
    middleName: 'E2E',
    birthday: '2015-01-15',
    sex: 'Male',
    schoolId: process.env.E2E_SCHOOL_ID || '1',
    schoolYear: currentSchoolYear(),
    guardianFirstName: 'Guardian',
    guardianLastName: `PWTEST_${token}`,
    guardianContact: '09171234567',
    houseNoStreet: '24 PWTEST Street',
    ...overrides,
  };
}

module.exports = { currentSchoolYear, generatedLrn, learnerData, nowToken };
