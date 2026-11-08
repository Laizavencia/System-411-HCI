<?php
// includes/sidebar_admin.php  — reusable admin sidebar
// Requires site_settings.php to already be loaded (done via db.php chain).
$currentPage = basename($_SERVER['PHP_SELF']);
$adminName   = $_SESSION['admin_name'] ?? 'Admin';
$adminRole   = $_SESSION['admin_role'] ?? 'Staff';

$siteName = getSiteName();
$siteLogo = getSiteLogo();
if (!empty($_GET['notification_id'])) {
    dbExecute("UPDATE admin_notifications SET is_read=1 WHERE id=?", [(int)$_GET['notification_id']]);
}
$adminNotifications = getAdminNotifications(6);
$adminNotificationCount = getUnreadAdminNotificationCount();

$navItems = [
    ['href'=>'dashboard.php',    'icon'=>'🏠', 'label'=>'Dashboard'],
    ['href'=>'my_inhabitants.php',   'icon'=>'👥', 'label'=>'My Inhabitant Profile'],
    ['href'=>'issuance.php',     'icon'=>'🧾', 'label'=>'Issuance / Documents'],
    ['href'=>'document_templates.php','icon'=>'📋', 'label'=>'Document Templates'],
    ['href'=>'announcements.php','icon'=>'📢', 'label'=>'Announcements'],
    ['href'=>'appointments.php', 'icon'=>'📅', 'label'=>'Appointments'],
    ['href'=>'incidents.php',    'icon'=>'🛡️', 'label'=>'Incidents & Complaints'],
    ['href'=>'health_monitoring.php', 'icon'=>'❤️', 'label'=>'Health Monitoring'],
    ['href'=>'mapping.php',      'icon'=>'📍', 'label'=>'Geographic Mapping'],
    ['href'=>'reports.php',      'icon'=>'📊', 'label'=>'Reports Management'],
    ['href'=>'users.php',        'icon'=>'🔒', 'label'=>'User Management'],
    ['href'=>'settings.php',     'icon'=>'⚙️', 'label'=>'Settings'],
];
?>
<aside class="sidebar">
  <div class="sidebar-header">
    <div class="logo-box">
      <img src="<?= $siteLogo ?>" alt="Logo">
    </div>
    <div>
      <h3>Barangay Admin</h3>
      <p class="text-muted"><?= $siteName ?></p>
    </div>
  </div>

  <!-- Logged-in admin info -->
  <div style="margin:0 4px 16px;padding:10px 14px;background:var(--primary-light);border-radius:10px;border-left:3px solid var(--primary)">
    <div style="font-size:13px;font-weight:600;color:var(--primary)"><?= htmlspecialchars($adminName) ?></div>
    <div style="font-size:11px;color:var(--text-light)"><?= htmlspecialchars($adminRole) ?></div>
  </div>

  <div style="margin:0 4px 16px;position:relative">
    <button id="adminNotificationToggle" type="button" style="width:100%;display:flex;align-items:center;justify-content:space-between;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:#fff;cursor:pointer;font-weight:600;color:var(--text)">
      <span>🔔 Notifications</span>
      <?php if ($adminNotificationCount > 0): ?>
        <span style="display:inline-flex;align-items:center;justify-content:center;min-width:22px;height:22px;padding:0 6px;border-radius:999px;background:#ef4444;color:#fff;font-size:11px;font-weight:700"><?= $adminNotificationCount ?></span>
      <?php endif; ?>
    </button>
    <div id="adminNotificationList" style="display:none;position:absolute;left:0;right:0;top:calc(100% + 8px);background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 10px 25px rgba(15,23,42,0.12);padding:8px;z-index:20;max-height:320px;overflow:auto">
      <?php if ($adminNotifications): foreach ($adminNotifications as $notification): ?>
        <?php $notificationHref = $notification['link'] ?: 'dashboard.php'; $notificationHref .= (strpos($notificationHref, '?') === false ? '?' : '&') . 'notification_id=' . (int)$notification['id']; ?>
        <a href="<?= htmlspecialchars($notificationHref) ?>" style="display:block;padding:10px 10px;border-radius:8px;text-decoration:none;color:inherit;border-left:3px solid <?= $notification['type'] === 'appointment' ? '#3b82f6' : ($notification['type'] === 'document' ? '#10b981' : ($notification['type'] === 'incident' ? '#f97316' : '#8b5cf6')) ?>;background:<?= !empty($notification['is_read']) ? '#ffffff' : '#f8fafc' ?>;margin-bottom:8px">
          <div style="font-size:11px;font-weight:700;letter-spacing:0.04em;text-transform:uppercase;color:var(--text-light)"><?= htmlspecialchars(ucfirst($notification['type'])) ?></div>
          <div style="font-size:13px;font-weight:600;margin:4px 0 3px"><?= htmlspecialchars($notification['title']) ?></div>
          <div style="font-size:12px;color:var(--text-light);line-height:1.4"><?= htmlspecialchars($notification['message']) ?></div>
          <div style="font-size:11px;color:var(--text-light);margin-top:5px"><?= date('M d, Y g:i A', strtotime($notification['created_at'])) ?></div>
        </a>
      <?php endforeach; else: ?>
        <div style="padding:12px;color:var(--text-light);font-size:13px;text-align:center">No notifications yet.</div>
      <?php endif; ?>
    </div>
  </div>

  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const toggle = document.getElementById('adminNotificationToggle');
      const panel = document.getElementById('adminNotificationList');
      if (!toggle || !panel) return;
      toggle.addEventListener('click', function(event) {
        event.stopPropagation();
        const visible = panel.style.display === 'block';
        panel.style.display = visible ? 'none' : 'block';
      });
      document.addEventListener('click', function(event) {
        if (!panel.contains(event.target) && !toggle.contains(event.target)) {
          panel.style.display = 'none';
        }
      });
    });
  </script>

  <ul class="menu">
    <?php foreach ($navItems as $item): ?>
    <li>
      <a href="<?= $item['href'] ?>" class="<?= $currentPage === $item['href'] ? 'active' : '' ?>">
        <div class="icon"><?= $item['icon'] ?></div>
        <span><?= $item['label'] ?></span>
      </a>
    </li>
    <?php endforeach; ?>
    <li>
      <a href="logout.php" style="color:#ef4444">
        <div class="icon">🚪</div><span>Sign Out</span>
      </a>
    </li>
  </ul>
</aside>
