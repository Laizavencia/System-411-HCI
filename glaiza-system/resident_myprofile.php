<?php
// ============================================================
// resident_myprofile.php  — Resident: View/update profile
// ============================================================
session_start();
require_once 'includes/db.php';
requireResidentLogin();

$residentId = $_SESSION['resident_id'];
$success = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_profile') {
        $firstName  = trim($_POST['first_name']  ?? '');
        $lastName   = trim($_POST['last_name']   ?? '');
        $dob        = trim($_POST['date_of_birth'] ?? '');
        $phone      = trim($_POST['phone']      ?? '');
        $street     = trim($_POST['street']     ?? '');
        $zone       = trim($_POST['zone']       ?? '');
        $house      = trim($_POST['house']      ?? '');
        $occupation = trim($_POST['occupation'] ?? '');
        $workplace  = trim($_POST['workplace']  ?? '');
        $gender     = trim($_POST['gender']     ?? '');
        $status     = trim($_POST['status']     ?? '');

        if (!$firstName || !$lastName) {
            $error = 'First name and last name are required.';
        } else {
            dbExecute(
                "UPDATE residents SET first_name=?, last_name=?, date_of_birth=?, gender=?, phone=?, street_address=?, zone_purok=?, house_number=?,
                 occupation=?, workplace=?, status=?, updated_at=NOW() WHERE id=?",
                [$firstName, $lastName, $dob ?: null, $gender ?: 'Other', $phone, $street, $zone, $house, $occupation, $workplace, $status ?: 'Pending', $residentId]
            );
            logActivity('Updated resident profile', 'Profile', 'resident');
            $success = 'Profile updated successfully.';
        }
    }

    if ($action === 'change_password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password']     ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        $r = dbFetchOne("SELECT password_hash FROM residents WHERE id=?", [$residentId]);
        if (!password_verify($current, $r['password_hash'])) {
            $error = 'Current password is incorrect.';
        } elseif (strlen($new) < 8) {
            $error = 'New password must be at least 8 characters.';
        } elseif ($new !== $confirm) {
            $error = 'New passwords do not match.';
        } else {
            dbExecute(
                "UPDATE residents SET password_hash=? WHERE id=?",
                [password_hash($new, PASSWORD_BCRYPT), $residentId]
            );
            $success = 'Password updated successfully.';
        }
    }
}

$resident = dbFetchOne(
    "SELECT *, TIMESTAMPDIFF(YEAR,date_of_birth,CURDATE()) AS age FROM residents WHERE id=?",
    [$residentId]
);

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>My Profile — Barangay Inosluban</title>
  <link rel="stylesheet" href="assets/css/style.css">
  <?php renderThemeStyle(); ?>
</head>
<body>
<div class="app">
  <?php include 'includes/sidebar_resident.php'; ?>
  <main class="main">
    <div class="topbar">
      <div>
        <h3>My Profile</h3>
        <div class="text-muted">View and update your personal information</div>
      </div>
    </div>

    <?php if ($success): ?><div style="background:#dcfce7;color:#166534;padding:12px 16px;border-radius:8px;margin-bottom:16px"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error):   ?><div style="background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:16px"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST">
      <input type="hidden" name="action" value="update_profile">
      <div class="card">
        <h3>Personal Information <span style="font-size:13px;color:var(--primary);font-weight:400">(editable anytime)</span></h3>
        <div class="form-grid" style="gap:18px;margin-bottom:18px">
          <div class="form-group"><label>First Name</label><input type="text" name="first_name" class="form-control" value="<?= htmlspecialchars($resident['first_name']) ?>"></div>
          <div class="form-group"><label>Last Name</label><input type="text" name="last_name" class="form-control" value="<?= htmlspecialchars($resident['last_name']) ?>"></div>
          <div class="form-group"><label>Email</label><input type="email" value="<?= htmlspecialchars($resident['email']) ?>" readonly></div>
          <div class="form-group"><label>Barangay ID</label><input type="text" value="<?= htmlspecialchars($resident['barangay_id']) ?>" readonly></div>
          <div class="form-group"><label>Date of Birth</label><input type="date" id="residentDob" name="date_of_birth" class="form-control" value="<?= htmlspecialchars($resident['date_of_birth'] ?? '') ?>" onchange="updateResidentAge()"></div>
          <div class="form-group"><label>Age</label><input type="text" id="residentAge" class="form-control" value="<?= htmlspecialchars($resident['age'] ?? '') ?>" readonly style="background:#f3f4f6"></div>
          <div class="form-group"><label>Phone</label><input type="text" name="phone" class="form-control" value="<?= htmlspecialchars($resident['phone'] ?? '') ?>" placeholder="09xx xxx xxxx"></div>
          <div class="form-group">
            <label>Gender</label>
            <select name="gender" class="form-control">
              <option value="Male" <?= ($resident['gender'] === 'Male') ? 'selected' : '' ?>>Male</option>
              <option value="Female" <?= ($resident['gender'] === 'Female') ? 'selected' : '' ?>>Female</option>
              <option value="Other" <?= ($resident['gender'] === 'Other') ? 'selected' : '' ?>>Other</option>
            </select>
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="status" class="form-control">
              <option value="Verified" <?= ($resident['status'] === 'Verified') ? 'selected' : '' ?>>Verified</option>
              <option value="Pending" <?= ($resident['status'] === 'Pending') ? 'selected' : '' ?>>Pending</option>
              <option value="Inactive" <?= ($resident['status'] === 'Inactive') ? 'selected' : '' ?>>Inactive</option>
            </select>
          </div>
        </div>
      </div>

      <div class="card">
        <h3>Residence &amp; Employment <span style="font-size:13px;color:var(--primary);font-weight:400">(editable)</span></h3>
        <div class="form-grid" style="gap:18px">
          <div class="form-group"><label>Street Address</label><input type="text" name="street" class="form-control" value="<?= htmlspecialchars($resident['street_address'] ?? '') ?>"></div>
          <div class="form-group">
            <label>Zone / Purok</label>
            <select name="zone" class="form-control">
              <option value="">Select...</option>
              <?php
              $puroks = dbFetchAll("SELECT name FROM puroks WHERE type='zone' ORDER BY name");
              $zones  = $puroks ?: [['name'=>'Zone 1'],['name'=>'Zone 2'],['name'=>'Zone 3'],['name'=>'Zone 4']];
              foreach ($zones as $z) {
                  $sel = ($resident['zone_purok'] === $z['name']) ? 'selected' : '';
                  echo "<option value=\"".htmlspecialchars($z['name'])."\" $sel>".htmlspecialchars($z['name'])."</option>";
              }
              ?>
            </select>
          </div>
          <div class="form-group"><label>House Number</label><input type="text" name="house" class="form-control" value="<?= htmlspecialchars($resident['house_number'] ?? '') ?>"></div>
          <div class="form-group"><label>Occupation</label><input type="text" name="occupation" class="form-control" value="<?= htmlspecialchars($resident['occupation'] ?? '') ?>"></div>
          <div class="form-group"><label>Workplace</label><input type="text" name="workplace" class="form-control" value="<?= htmlspecialchars($resident['workplace'] ?? '') ?>"></div>
        </div>
        <button class="btn btn-primary" type="submit" style="margin-top:16px">💾 Save Changes</button>
      </div>
    </form>

    <!-- Change Password -->
    <form method="POST">
      <input type="hidden" name="action" value="change_password">
      <div class="card">
        <h3>Account Security</h3>
        <div class="form-grid">
          <div class="form-group">
            <label>Current Password</label>
            <div style="position:relative">
              <input type="password" name="current_password" id="residentCurrentPwd" class="form-control" placeholder="Enter current password" style="padding-right:44px">
              <span onclick="togglePwd('residentCurrentPwd', this)" aria-label="Show password" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);cursor:pointer;user-select:none;display:flex;color:#6b7280">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.6 18.6 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </span>
            </div>
          </div>
          <div class="form-group">
            <label>New Password</label>
            <div style="position:relative">
              <input type="password" name="new_password" id="residentNewPwd" class="form-control" placeholder="Min. 8 characters" style="padding-right:44px">
              <span onclick="togglePwd('residentNewPwd', this)" aria-label="Show password" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);cursor:pointer;user-select:none;display:flex;color:#6b7280">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.6 18.6 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </span>
            </div>
          </div>
          <div class="form-group">
            <label>Confirm New Password</label>
            <div style="position:relative">
              <input type="password" name="confirm_password" id="residentConfirmPwd" class="form-control" placeholder="Repeat new password" style="padding-right:44px">
              <span onclick="togglePwd('residentConfirmPwd', this)" aria-label="Show password" style="position:absolute;right:12px;top:50%;transform:translateY(-50%);cursor:pointer;user-select:none;display:flex;color:#6b7280">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.6 18.6 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
              </span>
            </div>
          </div>
        </div>
        <button class="btn btn-secondary btn-sm" type="submit" style="margin-top:8px">🔒 Change Password</button>
      </div>
    </form>
  </main>
</div>

<script src="assets/js/app.js"></script>
<script>
const EYE_OPEN  = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8Z"/><circle cx="12" cy="12" r="3"/></svg>';
const EYE_OFF   = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 20c-7 0-11-8-11-8a18.6 18.6 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
function togglePwd(id, icon) {
  const input = document.getElementById(id);
  if (!input) return;
  if (input.type === 'password') {
    input.type = 'text';
    icon.innerHTML = EYE_OPEN;
    icon.setAttribute('aria-label', 'Hide password');
  } else {
    input.type = 'password';
    icon.innerHTML = EYE_OFF;
    icon.setAttribute('aria-label', 'Show password');
  }
}

function getAgeFromDob(dob) {
  if (!dob) return '';
  const birth = new Date(dob);
  if (Number.isNaN(birth.getTime())) return '';
  const today = new Date();
  let age = today.getFullYear() - birth.getFullYear();
  const monthDiff = today.getMonth() - birth.getMonth();
  if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
    age--;
  }
  return String(age);
}

function updateResidentAge() {
  const dobInput = document.getElementById('residentDob');
  const ageInput = document.getElementById('residentAge');
  if (!dobInput || !ageInput) return;
  ageInput.value = getAgeFromDob(dobInput.value);
}

document.addEventListener('DOMContentLoaded', updateResidentAge);
</script>
</body>
</html>