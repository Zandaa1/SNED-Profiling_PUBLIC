const fs = require('fs');
const path = require('path');
const { execFileSync } = require('child_process');

const ROOT = path.resolve(__dirname, '..');
const STATE_FILE = path.join(__dirname, '.e2e-fixtures.json');

function phpEscape(value) {
  return String(value).replace(/\\/g, '\\\\').replace(/'/g, "\\'");
}

function runPhp(code) {
  return execFileSync('php', ['-d', 'display_errors=1', '-r', code], {
    cwd: ROOT,
    encoding: 'utf8',
    stdio: ['ignore', 'pipe', 'pipe'],
    env: process.env,
  }).trim();
}

function seedUser(username, password, role, schoolId = null) {
  const fullName = `PWTEST ${role.toUpperCase()}`;
  const schoolIdPhp = schoolId === null ? 'NULL' : String(parseInt(schoolId, 10));
  const php = `require "config.php";
    $username='${phpEscape(username)}';
    $password='${phpEscape(password)}';
    $full='${phpEscape(fullName)}';
    $role='${phpEscape(role)}';
    $schoolId = ${schoolIdPhp};
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
    $stmt->bind_param('s', $username);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    if ($row) {
      $id = (int)$row['id'];
      if ($schoolId === NULL) {
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, password = ?, role = ?, school_id = NULL, google_2fa_enabled = 0, data_privacy_acknowledged = 1 WHERE id = ?");
        $stmt->bind_param('sssi', $full, $hash, $role, $id);
      } else {
        $stmt = $conn->prepare("UPDATE users SET full_name = ?, password = ?, role = ?, school_id = ?, google_2fa_enabled = 0, data_privacy_acknowledged = 1 WHERE id = ?");
        $stmt->bind_param('sssii', $full, $hash, $role, $schoolId, $id);
      }
      $stmt->execute();
    } else {
      if ($schoolId === NULL) {
        $stmt = $conn->prepare("INSERT INTO users (full_name, username, password, role, school_id, google_2fa_enabled, data_privacy_acknowledged) VALUES (?, ?, ?, ?, NULL, 0, 1)");
        $stmt->bind_param('ssss', $full, $username, $hash, $role);
      } else {
        $stmt = $conn->prepare("INSERT INTO users (full_name, username, password, role, school_id, google_2fa_enabled, data_privacy_acknowledged) VALUES (?, ?, ?, ?, ?, 0, 1)");
        $stmt->bind_param('ssssi', $full, $username, $hash, $role, $schoolId);
      }
      $stmt->execute();
    }
    echo 'ok';`;
  runPhp(php);
}

function loadFixtureLearners() {
  const php = `require "config.php";
    $school = $conn->query("SELECT id FROM schools WHERE is_deleted = 0 ORDER BY id ASC LIMIT 1")->fetch_assoc();
    $schoolId = $school ? (int)$school['id'] : 0;
    $graded = null;
    $nongraded = null;
    if ($schoolId > 0) {
      $stmt = $conn->prepare("SELECT e.lrn FROM enrollments e WHERE e.school_id = ? AND e.is_deleted = 0 AND e.is_historical = 0 AND e.placement_type = 'Graded' ORDER BY e.id ASC LIMIT 1");
      $stmt->bind_param('i', $schoolId);
      $stmt->execute();
      $graded = $stmt->get_result()->fetch_assoc();
      $stmt = $conn->prepare("SELECT e.lrn FROM enrollments e WHERE e.school_id = ? AND e.is_deleted = 0 AND e.is_historical = 0 AND e.placement_type = 'Non-Graded' ORDER BY e.id ASC LIMIT 1");
      $stmt->bind_param('i', $schoolId);
      $stmt->execute();
      $nongraded = $stmt->get_result()->fetch_assoc();
    }
    echo json_encode([
      'schoolId' => $schoolId,
      'gradedLrn' => $graded['lrn'] ?? '',
      'nonGradedLrn' => $nongraded['lrn'] ?? '',
      'anyLrn' => $graded['lrn'] ?? ($nongraded['lrn'] ?? ''),
    ], JSON_THROW_ON_ERROR);`;
  return JSON.parse(runPhp(php));
}

module.exports = async () => {
  const passwords = 'PWTest123!';
  seedUser('pwtest_superadmin', passwords, 'superadmin');
  seedUser('pwtest_admin', passwords, 'admin_office');
  const fixtureSchool = loadFixtureLearners().schoolId || null;
  seedUser('pwtest_coord', passwords, 'school_coordinator', fixtureSchool);
  seedUser('pwtest_teacher', passwords, 'teacher', fixtureSchool);

  const fixtures = {
    baseUrl: process.env.E2E_BASE_URL || 'http://127.0.0.1:8000',
    password: passwords,
    users: {
      superadmin: { username: 'pwtest_superadmin', password: passwords },
      admin_office: { username: 'pwtest_admin', password: passwords },
      school_coordinator: { username: 'pwtest_coord', password: passwords },
      teacher: { username: 'pwtest_teacher', password: passwords },
    },
    learners: loadFixtureLearners(),
  };

  fs.writeFileSync(STATE_FILE, JSON.stringify(fixtures, null, 2));
};
