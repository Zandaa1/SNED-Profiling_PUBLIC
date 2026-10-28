const fs = require('fs');
const path = require('path');

const STATE_FILE = path.join(__dirname, '..', '.e2e-fixtures.json');

function loadFixtures() {
  if (!fs.existsSync(STATE_FILE)) {
    return null;
  }
  return JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
}

function getRoleCredentials(role) {
  const fixtures = loadFixtures();
  if (fixtures?.users?.[role]) {
    return fixtures.users[role];
  }
  const usernameMap = {
    superadmin: 'pwtest_superadmin',
    admin_office: 'pwtest_admin',
    school_coordinator: 'pwtest_coord',
    teacher: 'pwtest_teacher',
  };
  return {
    username: usernameMap[role] || 'pwtest_superadmin',
    password: process.env.E2E_PASSWORD || 'PWTest123!',
  };
}

function getLearnership() {
  return loadFixtures()?.learners || { schoolId: '', gradedLrn: '', nonGradedLrn: '', anyLrn: '' };
}

module.exports = { getLearnership, getRoleCredentials, loadFixtures };
