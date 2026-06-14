<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

adminSendNoIndexHeaders();
adminRequireLogin();

$currentUser = adminCurrentUser();
$repList = adminReadRepList();
$adminUsers = adminReadUsers();
$pushoverSettings = ogmReadPushoverSettings();
$metaSettings = ogmReadMetaSettings();
$protectedUsernames = array_fill_keys(adminProtectedUsernames(), true);
$currentUsername = adminNormalizeUsername((string) ($currentUser['username'] ?? ''));
$pushoverStatusText = ogmPushoverIsEnabled($pushoverSettings)
  ? 'Enabled for full submitted leads. Incoming Meta messages can use this same Pushover destination.'
  : (ogmPushoverHasCredentials($pushoverSettings) ? 'Saved but currently turned off.' : 'Not configured yet.');
$metaStatusText = ogmMetaStatusText($metaSettings);
$metaCallbackUrl = ogmMetaCallbackUrl();
$allLeads = adminBuildLeads();
$activeOverviewLeads = adminFilterLeads($allLeads, [
  'view' => 'active',
  'status' => '',
  'owner' => '',
  'q' => '',
]);
$overviewSummary = adminBuildLeadSummary($activeOverviewLeads);
$leadHistoryRecords = adminReadLeadHistoryRecords();
$leadHistoryCounts = adminBuildLeadHistoryCounts($leadHistoryRecords);
$trafficOverview = adminBuildTrafficPerformanceOverview($leadHistoryRecords);
$trafficSnapshot = adminBuildTrafficSnapshot();
$flashTeamSaved = isset($_GET['team_saved']) && $_GET['team_saved'] === '1';
$flashUsersSaved = isset($_GET['users_saved']) && $_GET['users_saved'] === '1';
$flashUsersError = trim((string) ($_GET['users_error'] ?? ''));
$flashAlertsSaved = isset($_GET['alerts_saved']) && $_GET['alerts_saved'] === '1';
$flashAlertsTest = isset($_GET['alerts_test']) && $_GET['alerts_test'] === '1';
$flashAlertsError = trim((string) ($_GET['alerts_error'] ?? ''));
$flashMetaSaved = isset($_GET['meta_saved']) && $_GET['meta_saved'] === '1';
$flashMetaError = trim((string) ($_GET['meta_error'] ?? ''));
$scheduledWindow = adminScheduledReportWindow();
$reportHref = 'report.php';
if ($scheduledWindow) {
  $reportHref .= '?start=' . rawurlencode($scheduledWindow['start']->format('Y-m-d')) . '&end=' . rawurlencode($scheduledWindow['end']->format('Y-m-d'));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Analytics | Olive Glass & Marble</title>
  <style>
    :root {
      color-scheme: light;
      --bg: #f4ede2;
      --panel: rgba(255, 250, 242, 0.95);
      --panel-strong: #fff;
      --line: #dbcab4;
      --text: #2f2820;
      --muted: #6e5e4f;
      --accent: #155247;
      --accent-strong: #0f3b33;
      --shadow: 0 24px 60px rgba(49, 35, 18, 0.12);
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: "Avenir Next", "Segoe UI", sans-serif;
      color: var(--text);
      background:
        radial-gradient(circle at top right, rgba(201, 168, 124, 0.25), transparent 30%),
        radial-gradient(circle at bottom left, rgba(21, 82, 71, 0.14), transparent 30%),
        linear-gradient(180deg, #f9f4eb 0%, var(--bg) 100%);
    }

    .shell {
      max-width: 1360px;
      margin: 0 auto;
      padding: 24px;
    }

    .topbar {
      display: flex;
      justify-content: space-between;
      align-items: end;
      gap: 20px;
      margin-bottom: 14px;
    }

    h1 {
      margin: 0;
      font-size: clamp(2rem, 4vw, 3rem);
      line-height: 1;
    }

    .subhead {
      margin-top: 8px;
      color: var(--muted);
      max-width: 780px;
      line-height: 1.6;
    }

    .topbar-actions {
      display: flex;
      flex-direction: column;
      align-items: flex-end;
      gap: 8px;
      color: var(--muted);
    }

    .topbar-controls {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 10px;
      flex-wrap: wrap;
    }

    .topbar-meta {
      font-size: 0.96rem;
      text-align: right;
    }

    .button-link,
    button {
      border: 0;
      border-radius: 999px;
      background: linear-gradient(135deg, var(--accent) 0%, var(--accent-strong) 100%);
      color: #fff;
      font: inherit;
      font-weight: 700;
      padding: 11px 18px;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .secondary-link {
      background: rgba(255, 255, 255, 0.72);
      color: var(--text);
      border: 1px solid rgba(219, 202, 180, 0.9);
    }

    .admin-menu {
      display: flex;
      align-items: stretch;
      gap: 10px;
      margin-bottom: 18px;
      padding: 0 4px;
      border-bottom: 1px solid rgba(219, 202, 180, 0.9);
    }

    .menu-group {
      position: relative;
    }

    .menu-group > summary {
      list-style: none;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 12px 14px;
      cursor: pointer;
      font-weight: 800;
      color: var(--accent-strong);
      border-radius: 14px 14px 0 0;
      border: 1px solid rgba(21, 82, 71, 0.2);
      background: linear-gradient(135deg, var(--menu-start, #9bc5af) 0%, var(--menu-end, #78ad95) 100%);
      box-shadow: 0 12px 24px rgba(15, 59, 51, 0.14);
      transition: background 160ms ease, color 160ms ease, box-shadow 160ms ease, transform 160ms ease;
    }

    .menu-group > summary::-webkit-details-marker {
      display: none;
    }

    .menu-group > summary::after {
      content: "▾";
      font-size: 0.9rem;
      color: currentColor;
    }

    .menu-link {
      display: inline-flex;
      align-items: center;
      padding: 12px 14px;
      border-radius: 14px 14px 0 0;
      text-decoration: none;
      font-weight: 800;
      color: var(--accent-strong);
      border: 1px solid rgba(21, 82, 71, 0.2);
      background: linear-gradient(135deg, var(--menu-start, #9bc5af) 0%, var(--menu-end, #78ad95) 100%);
      box-shadow: 0 12px 24px rgba(15, 59, 51, 0.14);
      transition: background 160ms ease, color 160ms ease, box-shadow 160ms ease, transform 160ms ease;
    }

    .menu-dashboard {
      --menu-start: #96c4a7;
      --menu-end: #76a98c;
      --menu-active-start: #155247;
      --menu-active-end: #0f3b33;
    }

    .menu-analytics {
      --menu-start: #a4ceb1;
      --menu-end: #84b597;
      --menu-active-start: #1f6453;
      --menu-active-end: #14483d;
    }

    .menu-group-reps > summary {
      --menu-start: #b2d7bb;
      --menu-end: #92c19e;
      --menu-active-start: #2a745d;
      --menu-active-end: #1b5745;
    }

    .menu-group-logins > summary {
      --menu-start: #c0dfc6;
      --menu-end: #9fceb0;
      --menu-active-start: #37836a;
      --menu-active-end: #225b49;
    }

    .menu-group-alerts > summary {
      --menu-start: #d0e6d0;
      --menu-end: #afd0af;
      --menu-active-start: #477a4e;
      --menu-active-end: #2b5932;
    }

    .menu-link:hover,
    .menu-group > summary:hover {
      transform: translateY(-1px);
      box-shadow: 0 14px 28px rgba(15, 59, 51, 0.18);
    }

    .menu-link.is-active,
    .menu-group[open] > summary {
      color: #fff;
      border-color: rgba(15, 59, 51, 0.55);
      background: linear-gradient(135deg, var(--menu-active-start, #155247) 0%, var(--menu-active-end, #0f3b33) 100%);
      box-shadow: 0 18px 34px rgba(15, 59, 51, 0.28);
      transform: translateY(-1px);
    }

    .menu-panel {
      position: absolute;
      top: calc(100% - 1px);
      left: 0;
      z-index: 5;
      width: min(720px, calc(100vw - 48px));
      padding: 20px 22px 22px;
      background: var(--panel-strong);
      border: 1px solid rgba(219, 202, 180, 0.92);
      border-radius: 0 18px 18px 18px;
      box-shadow: var(--shadow);
    }

    .panel,
    .metric-card,
    .list-panel {
      background: var(--panel);
      border: 1px solid rgba(219, 202, 180, 0.85);
      border-radius: 22px;
      box-shadow: var(--shadow);
    }

    .panel {
      padding: 22px;
      margin-bottom: 18px;
    }

    .eyebrow {
      display: block;
      margin-bottom: 6px;
      font-size: 0.74rem;
      font-weight: 800;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--muted);
    }

    .section-head {
      display: flex;
      justify-content: space-between;
      align-items: end;
      gap: 16px;
      margin-bottom: 16px;
    }

    .section-head h2 {
      margin: 0;
      font-size: 1.45rem;
    }

    .section-note {
      color: var(--muted);
      line-height: 1.6;
      max-width: 860px;
    }

    .metrics-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
      gap: 14px;
    }

    .trend-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
      gap: 16px;
    }

    .trend-card {
      padding: 22px;
      background: rgba(255, 255, 255, 0.76);
      border: 1px solid rgba(219, 202, 180, 0.88);
      border-radius: 22px;
      box-shadow: 0 18px 40px rgba(49, 35, 18, 0.08);
    }

    .trend-card h3 {
      margin: 0;
      font-size: 1.5rem;
      line-height: 1.1;
    }

    .trend-value-row {
      display: flex;
      align-items: baseline;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 12px;
    }

    .trend-value {
      font-size: clamp(2rem, 4vw, 3.2rem);
      line-height: 1;
      letter-spacing: -0.03em;
    }

    .trend-change {
      font-weight: 800;
      font-size: 1rem;
    }

    .trend-change.is-up {
      color: #14724a;
    }

    .trend-change.is-down {
      color: #a23535;
    }

    .trend-change.is-flat {
      color: var(--muted);
    }

    .trend-subvalue {
      margin-top: 12px;
      color: var(--muted);
      line-height: 1.55;
    }

    .trend-chart {
      margin-top: 18px;
    }

    .trend-chart svg {
      display: block;
      width: 100%;
      height: 220px;
    }

    .trend-legend {
      margin-top: 8px;
      color: var(--muted);
      font-size: 0.9rem;
      font-weight: 700;
    }

    .metric-card {
      padding: 18px;
    }

    .metric-card strong {
      display: block;
      margin-top: 6px;
      font-size: 1.9rem;
      line-height: 1;
    }

    .metric-subvalue {
      display: block;
      margin-top: 8px;
      color: var(--muted);
      font-weight: 600;
      line-height: 1.5;
    }

    .split-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
      gap: 14px;
    }

    .mini-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
      gap: 12px;
      margin-top: 14px;
    }

    .queue-grid {
      margin-top: 14px;
    }

    .queue-grid + .queue-grid {
      margin-top: 12px;
    }

    .queue-grid-primary {
      grid-template-columns: repeat(4, minmax(0, 1fr));
    }

    .queue-grid-secondary {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

    .mini-metric {
      padding: 12px 14px;
      border-radius: 16px;
      background: rgba(255, 255, 255, 0.64);
      border: 1px solid rgba(219, 202, 180, 0.72);
    }

    .mini-metric span {
      display: block;
      color: var(--muted);
      font-size: 0.82rem;
      font-weight: 700;
    }

    .mini-metric strong {
      display: block;
      margin-top: 6px;
      font-size: 1.45rem;
      line-height: 1;
    }

    .list-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
      gap: 14px;
    }

    .list-panel {
      padding: 18px;
    }

    .traffic-list {
      list-style: none;
      margin: 0;
      padding: 0;
      display: grid;
      gap: 10px;
    }

    .traffic-list li {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      align-items: start;
      gap: 12px;
      padding: 12px 14px;
      border-radius: 14px;
      background: rgba(255, 255, 255, 0.62);
      border: 1px solid rgba(219, 202, 180, 0.7);
    }

    .traffic-list-label {
      min-width: 0;
      line-height: 1.45;
      overflow-wrap: break-word;
      word-break: normal;
    }

    .traffic-list-label small {
      display: block;
      margin-top: 4px;
      color: var(--muted);
      font-size: 0.8rem;
    }

    .traffic-count {
      font-weight: 800;
      white-space: nowrap;
      text-align: right;
      align-self: start;
    }

    .recent-visits-list li {
      grid-template-columns: 1fr;
      gap: 8px;
    }

    .recent-visits-list .traffic-count {
      order: -1;
      text-align: left;
      white-space: normal;
    }

    .flash {
      margin-bottom: 18px;
      padding: 14px 16px;
      border-radius: 18px;
      background: rgba(24, 121, 78, 0.1);
      border: 1px solid rgba(24, 121, 78, 0.18);
      color: #155134;
      font-weight: 700;
    }

    .team-note {
      margin: 0 0 16px;
      color: var(--muted);
      line-height: 1.6;
    }

    .rep-grid {
      display: grid;
      gap: 12px;
      margin-bottom: 14px;
    }

    .rep-row {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 12px;
      align-items: end;
      padding: 12px 14px;
      border-radius: 16px;
      background: rgba(255, 255, 255, 0.62);
      border: 1px solid rgba(219, 202, 180, 0.7);
    }

    .login-grid {
      display: grid;
      gap: 12px;
      margin-bottom: 14px;
    }

    .login-row {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(150px, 0.8fr) minmax(0, 1fr) auto;
      gap: 12px;
      align-items: end;
      padding: 12px 14px;
      border-radius: 16px;
      background: rgba(255, 255, 255, 0.62);
      border: 1px solid rgba(219, 202, 180, 0.7);
    }

    .field-hint {
      margin-top: 6px;
      color: var(--muted);
      font-size: 0.8rem;
      line-height: 1.5;
    }

    .section-divider {
      margin-top: 6px;
      padding-top: 16px;
      border-top: 1px solid rgba(219, 202, 180, 0.9);
    }

    label {
      display: block;
      font-size: 0.88rem;
      font-weight: 700;
      margin-bottom: 6px;
    }

    input,
    select,
    textarea {
      width: 100%;
      border: 1px solid var(--line);
      border-radius: 14px;
      padding: 12px 14px;
      font: inherit;
      color: var(--text);
      background: #fff;
    }

    textarea {
      min-height: 110px;
      resize: vertical;
    }

    .checkbox-row {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-weight: 700;
      color: var(--muted);
      white-space: nowrap;
    }

    .checkbox-row input {
      width: auto;
      margin: 0;
    }

    .team-actions {
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      flex-wrap: wrap;
    }

    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
    }

    .empty-note {
      color: var(--muted);
      line-height: 1.6;
    }

    @media (max-width: 900px) {
      .topbar {
        flex-direction: column;
        align-items: start;
      }

      .topbar-actions {
        width: 100%;
        align-items: flex-start;
      }

      .topbar-controls {
        justify-content: flex-start;
      }

      .topbar-meta {
        text-align: left;
      }

      .admin-menu {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
        border-bottom: 0;
        padding: 0;
      }

      .menu-link,
      .menu-group {
        width: 100%;
      }

      .menu-link {
        justify-content: space-between;
        border-radius: 16px;
      }

      .menu-group > summary {
        width: 100%;
        justify-content: space-between;
        border-radius: 16px;
      }

      .menu-panel {
        position: static;
        width: 100%;
        margin-top: 8px;
        margin-bottom: 12px;
        border-radius: 18px;
      }

      .section-head {
        flex-direction: column;
        align-items: start;
      }

      .traffic-list li {
        grid-template-columns: 1fr;
      }

      .traffic-count {
        white-space: normal;
        text-align: left;
      }

      .rep-row {
        grid-template-columns: 1fr;
        align-items: start;
      }

      .login-row {
        grid-template-columns: 1fr;
        align-items: start;
      }

      .queue-grid-primary,
      .queue-grid-secondary {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }
  </style>
</head>
<body>
  <div class="shell">
    <header class="topbar">
      <div>
        <h1>Analytics</h1>
      </div>
      <div class="topbar-actions">
        <div class="topbar-controls">
          <a class="button-link secondary-link" href="index.php">Dashboard</a>
          <a class="button-link secondary-link" href="<?php echo adminEscape($reportHref); ?>" target="_blank" rel="noopener noreferrer">Print Report</a>
          <a class="button-link" href="logout.php">Log out</a>
        </div>
        <div class="topbar-meta">Signed in as <?php echo adminEscape((string) ($currentUser['display_name'] ?? $currentUser['username'] ?? '')); ?></div>
      </div>
    </header>

    <nav class="admin-menu" aria-label="Analytics menu">
      <a class="menu-link menu-dashboard" href="index.php">Dashboard</a>
      <a class="menu-link menu-analytics is-active" href="analytics.php">Analytics</a>
      <details class="menu-group menu-group-reps">
        <summary>Manage Sales Reps</summary>
        <div class="menu-panel">
          <p class="team-note">Rename, add, or remove reps here. Renaming updates existing assignments. Removing a rep clears that owner from assigned leads.</p>
          <form method="post" action="update-team.php">
            <input type="hidden" name="csrf_token" value="<?php echo adminEscape(adminCsrfToken()); ?>">
            <input type="hidden" name="return_to" value="analytics.php">
            <div class="rep-grid">
              <?php if ($repList): ?>
                <?php foreach ($repList as $index => $rep): ?>
                  <div class="rep-row">
                    <div>
                      <input type="hidden" name="existing_names[]" value="<?php echo adminEscape($rep); ?>">
                      <label for="rep-name-<?php echo adminEscape((string) $index); ?>">Rep Name</label>
                      <input id="rep-name-<?php echo adminEscape((string) $index); ?>" type="text" name="rep_names[]" value="<?php echo adminEscape($rep); ?>">
                    </div>
                    <label class="checkbox-row">
                      <input type="checkbox" name="delete_names[]" value="<?php echo adminEscape($rep); ?>">
                      Remove
                    </label>
                  </div>
                <?php endforeach; ?>
              <?php else: ?>
                <div class="mini-metric">No reps added yet. Use the field below to add your first names.</div>
              <?php endif; ?>
            </div>
            <div>
              <label for="new-rep-names">Add New Reps</label>
              <textarea id="new-rep-names" name="new_rep_names" placeholder="One new rep per line"></textarea>
            </div>
            <div class="team-actions">
              <button type="submit">Save</button>
            </div>
          </form>
        </div>
      </details>
      <details class="menu-group menu-group-logins">
        <summary>Manage Logins</summary>
        <div class="menu-panel">
          <p class="team-note">Create new rep logins, change passwords, and remove extra accounts here. The shared <strong>sales</strong> login stays as a protected fallback.</p>
          <form method="post" action="update-users.php">
            <input type="hidden" name="csrf_token" value="<?php echo adminEscape(adminCsrfToken()); ?>">
            <input type="hidden" name="return_to" value="analytics.php">
            <div class="login-grid">
              <?php foreach ($adminUsers as $username => $user): ?>
                <div class="login-row">
                  <div>
                    <label for="login-display-<?php echo adminEscape($username); ?>">Display Name</label>
                    <input id="login-display-<?php echo adminEscape($username); ?>" type="text" name="display_names[<?php echo adminEscape($username); ?>]" value="<?php echo adminEscape((string) ($user['display_name'] ?? $username)); ?>">
                  </div>
                  <div>
                    <label for="login-username-<?php echo adminEscape($username); ?>">Username</label>
                    <input id="login-username-<?php echo adminEscape($username); ?>" type="text" value="<?php echo adminEscape($username); ?>" readonly>
                  </div>
                  <div>
                    <label for="login-password-<?php echo adminEscape($username); ?>">New Password</label>
                    <input id="login-password-<?php echo adminEscape($username); ?>" type="password" name="reset_passwords[<?php echo adminEscape($username); ?>]" autocomplete="new-password" placeholder="Leave blank to keep current">
                  </div>
                  <?php $isProtectedUser = isset($protectedUsernames[$username]); ?>
                  <label class="checkbox-row">
                    <input type="checkbox" name="delete_usernames[]" value="<?php echo adminEscape($username); ?>"<?php echo $isProtectedUser || $currentUsername === $username ? ' disabled' : ''; ?>>
                    <?php
                      if ($isProtectedUser) {
                        echo 'Keep';
                      } elseif ($currentUsername === $username) {
                        echo 'Current Login';
                      } else {
                        echo 'Remove';
                      }
                    ?>
                  </label>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="section-divider">
              <span class="eyebrow">Add New Login</span>
              <div class="form-grid">
                <div>
                  <label for="new-user-display-analytics">Display Name</label>
                  <input id="new-user-display-analytics" type="text" name="new_display_name" placeholder="Sed">
                </div>
                <div>
                  <label for="new-user-username-analytics">Username</label>
                  <input id="new-user-username-analytics" type="text" name="new_username" placeholder="sed" autocomplete="username">
                  <div class="field-hint">Use letters, numbers, dots, dashes, or underscores.</div>
                </div>
                <div>
                  <label for="new-user-password-analytics">Password</label>
                  <input id="new-user-password-analytics" type="password" name="new_password" autocomplete="new-password" placeholder="Set a password">
                </div>
              </div>
            </div>
            <div class="team-actions">
              <button type="submit">Save Logins</button>
            </div>
          </form>
        </div>
      </details>
      <details class="menu-group menu-group-alerts">
        <summary>Lead Alerts</summary>
        <div class="menu-panel">
          <p class="team-note">Send Pushover notifications to mobile devices for full submitted leads. Incoming Meta messages can use the same destination when Meta Messages is enabled.</p>
          <div class="mini-metric" style="margin-bottom: 16px;">
            <span>Current Status</span>
            <strong style="font-size: 1rem; margin-top: 10px; line-height: 1.4;"><?php echo adminEscape($pushoverStatusText); ?></strong>
          </div>
          <form method="post" action="update-alerts.php">
            <input type="hidden" name="csrf_token" value="<?php echo adminEscape(adminCsrfToken()); ?>">
            <input type="hidden" name="return_to" value="analytics.php">
            <label class="checkbox-row">
              <input type="checkbox" name="pushover_enabled" value="1"<?php echo !empty($pushoverSettings['enabled']) ? ' checked' : ''; ?>>
              Enable Pushover for full leads
            </label>
            <div class="form-grid">
              <div>
                <label for="pushover-token-analytics">App Token</label>
                <input id="pushover-token-analytics" type="text" name="pushover_token" value="<?php echo adminEscape((string) ($pushoverSettings['token'] ?? '')); ?>" placeholder="Pushover application token" autocomplete="off">
                <div class="field-hint">Use the API token from your Pushover application.</div>
              </div>
              <div>
                <label for="pushover-user-analytics">User or Group Key</label>
                <input id="pushover-user-analytics" type="text" name="pushover_user" value="<?php echo adminEscape((string) ($pushoverSettings['user'] ?? '')); ?>" placeholder="Pushover user or group key" autocomplete="off">
                <div class="field-hint">Use a user key for one person or a group key for a team.</div>
              </div>
            </div>
            <div class="form-grid">
              <div>
                <label for="pushover-device-analytics">Device (Optional)</label>
                <input id="pushover-device-analytics" type="text" name="pushover_device" value="<?php echo adminEscape((string) ($pushoverSettings['device'] ?? '')); ?>" placeholder="Leave blank for all devices" autocomplete="off">
                <div class="field-hint">Leave blank to notify every device on that Pushover account.</div>
              </div>
              <div>
                <label for="pushover-sound-analytics">Sound (Optional)</label>
                <input id="pushover-sound-analytics" type="text" name="pushover_sound" value="<?php echo adminEscape((string) ($pushoverSettings['sound'] ?? '')); ?>" placeholder="Use account default" autocomplete="off">
                <div class="field-hint">Optional Pushover sound name. Leave blank to use the app default.</div>
              </div>
              <div>
                <label for="pushover-priority-analytics">Priority</label>
                <select id="pushover-priority-analytics" name="pushover_priority">
                  <option value="0"<?php echo (int) ($pushoverSettings['priority'] ?? 0) === 0 ? ' selected' : ''; ?>>Normal</option>
                  <option value="1"<?php echo (int) ($pushoverSettings['priority'] ?? 0) === 1 ? ' selected' : ''; ?>>High</option>
                </select>
                <div class="field-hint">Normal is usually best. High is more aggressive.</div>
              </div>
            </div>
            <div class="team-actions">
              <button type="submit">Save</button>
              <button type="submit" name="send_test" value="1">Save &amp; Test</button>
            </div>
          </form>
        </div>
      </details>
      <details class="menu-group menu-group-alerts">
        <summary>Meta Messages</summary>
        <div class="menu-panel">
          <p class="team-note">Receive Facebook Page messages and Instagram DMs in the dashboard. Reps can still answer inside Meta Business Suite, Messenger, or Instagram.</p>
          <div class="mini-metric" style="margin-bottom: 16px;">
            <span>Current Status</span>
            <strong style="font-size: 1rem; margin-top: 10px; line-height: 1.4;"><?php echo adminEscape($metaStatusText); ?></strong>
          </div>
          <form method="post" action="update-meta.php">
            <input type="hidden" name="csrf_token" value="<?php echo adminEscape(adminCsrfToken()); ?>">
            <input type="hidden" name="return_to" value="analytics.php">
            <label class="checkbox-row">
              <input type="checkbox" name="meta_enabled" value="1"<?php echo !empty($metaSettings['enabled']) ? ' checked' : ''; ?>>
              Enable Meta message intake
            </label>
            <label class="checkbox-row">
              <input type="checkbox" name="meta_social_push_enabled" value="1"<?php echo !empty($metaSettings['social_push_enabled']) ? ' checked' : ''; ?>>
              Send Pushover alerts for incoming Meta messages
            </label>
            <div class="form-grid">
              <div>
                <label for="meta-callback-analytics">Callback URL</label>
                <input id="meta-callback-analytics" type="text" value="<?php echo adminEscape($metaCallbackUrl); ?>" readonly>
                <div class="field-hint">Paste this into the Meta developer webhook callback field.</div>
              </div>
              <div>
                <label for="meta-verify-token-analytics">Verify Token</label>
                <input id="meta-verify-token-analytics" type="text" name="meta_verify_token" value="<?php echo adminEscape((string) ($metaSettings['verify_token'] ?? '')); ?>" autocomplete="off">
                <div class="field-hint">Use this exact value in the Meta webhook verify token field.</div>
              </div>
            </div>
            <div class="form-grid">
              <div>
                <label for="meta-secret-analytics">App Secret</label>
                <input id="meta-secret-analytics" type="password" name="meta_app_secret" value="<?php echo adminEscape((string) ($metaSettings['app_secret'] ?? '')); ?>" autocomplete="off">
                <div class="field-hint">Used to validate incoming Meta webhooks.</div>
              </div>
              <div>
                <label for="meta-page-id-analytics">Facebook Page ID</label>
                <input id="meta-page-id-analytics" type="text" name="meta_facebook_page_id" value="<?php echo adminEscape((string) ($metaSettings['facebook_page_id'] ?? '')); ?>" autocomplete="off">
                <div class="field-hint">Optional but recommended for cleaner filtering.</div>
              </div>
              <div>
                <label for="meta-ig-id-analytics">Instagram Account ID</label>
                <input id="meta-ig-id-analytics" type="text" name="meta_instagram_account_id" value="<?php echo adminEscape((string) ($metaSettings['instagram_account_id'] ?? '')); ?>" autocomplete="off">
                <div class="field-hint">Optional but recommended for cleaner filtering.</div>
              </div>
            </div>
            <div class="team-actions">
              <button type="submit">Save</button>
            </div>
          </form>
        </div>
      </details>
    </nav>

    <?php if ($flashTeamSaved): ?>
      <div class="flash">Sales rep list saved.</div>
    <?php endif; ?>

    <?php if ($flashUsersSaved): ?>
      <div class="flash">Login accounts saved.</div>
    <?php endif; ?>

    <?php if ($flashUsersError !== ''): ?>
      <div class="flash" style="background: rgba(162, 53, 53, 0.1); border-color: rgba(162, 53, 53, 0.18); color: #7d2424;"><?php echo adminEscape($flashUsersError); ?></div>
    <?php endif; ?>

    <?php if ($flashAlertsSaved): ?>
      <div class="flash"><?php echo $flashAlertsTest ? 'Lead alerts saved and test push sent.' : 'Lead alerts saved.'; ?></div>
    <?php endif; ?>

    <?php if ($flashAlertsError !== ''): ?>
      <div class="flash" style="background: rgba(162, 53, 53, 0.1); border-color: rgba(162, 53, 53, 0.18); color: #7d2424;"><?php echo adminEscape($flashAlertsError); ?></div>
    <?php endif; ?>

    <?php if ($flashMetaSaved): ?>
      <div class="flash">Meta message settings saved.</div>
    <?php endif; ?>

    <?php if ($flashMetaError !== ''): ?>
      <div class="flash" style="background: rgba(162, 53, 53, 0.1); border-color: rgba(162, 53, 53, 0.18); color: #7d2424;"><?php echo adminEscape($flashMetaError); ?></div>
    <?php endif; ?>

    <section class="panel">
      <div class="section-head">
        <div>
          <h2>Lead Intake</h2>
          <div class="section-note">These counts track unique website leads by first capture date. They stay here even if a lead later gets moved to trash or permanently deleted from the working queue.</div>
        </div>
      </div>
      <div class="metrics-grid">
        <article class="metric-card">
          <span class="eyebrow">Today</span>
          <strong><?php echo adminEscape((string) ($leadHistoryCounts['today'] ?? 0)); ?></strong>
          <span class="metric-subvalue">New leads captured today</span>
        </article>
        <article class="metric-card">
          <span class="eyebrow">This Week</span>
          <strong><?php echo adminEscape((string) ($leadHistoryCounts['week'] ?? 0)); ?></strong>
          <span class="metric-subvalue">Since Monday</span>
        </article>
        <article class="metric-card">
          <span class="eyebrow">This Month</span>
          <strong><?php echo adminEscape((string) ($leadHistoryCounts['month'] ?? 0)); ?></strong>
          <span class="metric-subvalue">Month-to-date</span>
        </article>
        <article class="metric-card">
          <span class="eyebrow">This Year</span>
          <strong><?php echo adminEscape((string) ($leadHistoryCounts['year'] ?? 0)); ?></strong>
          <span class="metric-subvalue">Year-to-date</span>
        </article>
        <article class="metric-card">
          <span class="eyebrow">All Time</span>
          <strong><?php echo adminEscape((string) ($leadHistoryCounts['all_time'] ?? 0)); ?></strong>
          <span class="metric-subvalue">Historical lead total</span>
        </article>
      </div>
    </section>

    <section class="split-grid">
      <section class="panel">
        <span class="eyebrow">Lead Mix</span>
        <div class="section-note">Historical website lead breakdown across all time.</div>
        <div class="mini-grid">
          <div class="mini-metric">
            <span>Full Leads</span>
            <strong><?php echo adminEscape((string) ($leadHistoryCounts['full'] ?? 0)); ?></strong>
          </div>
          <div class="mini-metric">
            <span>Partial Only</span>
            <strong><?php echo adminEscape((string) ($leadHistoryCounts['partial_only'] ?? 0)); ?></strong>
          </div>
        </div>
      </section>

      <section class="panel">
        <span class="eyebrow">Current Queue</span>
        <div class="section-note">Live working numbers from the active lead dashboard.</div>
        <div class="mini-grid queue-grid queue-grid-primary">
          <div class="mini-metric">
            <span>Active Leads</span>
            <strong><?php echo adminEscape((string) ($overviewSummary['total'] ?? 0)); ?></strong>
          </div>
          <div class="mini-metric">
            <span>Unassigned</span>
            <strong><?php echo adminEscape((string) ($overviewSummary['unassigned'] ?? 0)); ?></strong>
          </div>
          <div class="mini-metric">
            <span>Assigned</span>
            <strong><?php echo adminEscape((string) ($overviewSummary['assigned'] ?? 0)); ?></strong>
          </div>
          <div class="mini-metric">
            <span>Contacted</span>
            <strong><?php echo adminEscape((string) ($overviewSummary['contacted'] ?? 0)); ?></strong>
          </div>
        </div>
        <div class="mini-grid queue-grid queue-grid-secondary">
          <div class="mini-metric">
            <span>Quoted</span>
            <strong><?php echo adminEscape((string) ($overviewSummary['quoted'] ?? 0)); ?></strong>
          </div>
          <div class="mini-metric">
            <span>Won</span>
            <strong><?php echo adminEscape((string) ($overviewSummary['won'] ?? 0)); ?></strong>
          </div>
        </div>
      </section>
    </section>

    <section class="panel">
      <div class="section-head">
        <div>
          <h2>Website Performance</h2>
          <div class="section-note">
            <?php if (($trafficSnapshot['total_views'] ?? 0) > 0): ?>
              First-party tracking started <?php echo adminEscape(adminFormatTimestamp((string) ($trafficSnapshot['started_at'] ?? ''))); ?>. These cards chart the last 30 days and use website-specific metrics like contact actions, content interactions, and lead captures.
            <?php else: ?>
              Tracking is live now. This area will begin filling in as people browse the website.
            <?php endif; ?>
          </div>
        </div>
      </div>
      <div class="section-note" style="margin-bottom: 18px;">Performance window: <?php echo adminEscape((string) ($trafficOverview['period_label'] ?? 'Last 30 days')); ?></div>
      <div class="trend-grid">
        <?php foreach ((array) ($trafficOverview['cards'] ?? []) as $card): ?>
          <?php $change = (array) ($card['change'] ?? []); ?>
          <article class="trend-card">
            <span class="eyebrow"><?php echo adminEscape((string) ($card['title'] ?? 'Metric')); ?></span>
            <div class="trend-value-row">
              <strong class="trend-value"><?php echo adminEscape((string) ($card['display_value'] ?? '0')); ?></strong>
              <span class="trend-change is-<?php echo adminEscape((string) ($change['direction'] ?? 'flat')); ?>">
                <?php echo adminEscape((string) (($change['symbol'] ?? '→') . ' ' . ($change['label'] ?? 'No change'))); ?>
              </span>
            </div>
            <div class="trend-subvalue"><?php echo adminEscape((string) ($card['subtitle'] ?? '')); ?></div>
            <div class="trend-chart">
              <?php echo adminRenderTrendChartSvg((array) ($card['series'] ?? []), (array) ($card['axis_labels'] ?? []), [
                'tick_format' => (string) ($card['format'] ?? 'count'),
              ]); ?>
            </div>
            <div class="trend-legend"><?php echo adminEscape((string) ($card['legend'] ?? '')); ?></div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>

    <section class="list-grid">
      <section class="list-panel">
        <span class="eyebrow">Top Pages · 7 Days</span>
        <?php if (!empty($trafficSnapshot['seven_days']['top_pages'])): ?>
          <ul class="traffic-list">
            <?php foreach ($trafficSnapshot['seven_days']['top_pages'] as $page): ?>
              <li>
                <span class="traffic-list-label"><?php echo adminEscape((string) ($page['label'] ?? $page['path'] ?? '/')); ?></span>
                <span class="traffic-count"><?php echo adminEscape((string) ($page['count'] ?? 0)); ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="empty-note">No page-view data yet.</div>
        <?php endif; ?>
      </section>

      <section class="list-panel">
        <span class="eyebrow">Top Engagement · 30 Days</span>
        <?php if (!empty($trafficSnapshot['thirty_days']['top_engagement'])): ?>
          <ul class="traffic-list">
            <?php foreach ($trafficSnapshot['thirty_days']['top_engagement'] as $page): ?>
              <li>
                <span class="traffic-list-label">
                  <?php echo adminEscape((string) ($page['label'] ?? $page['path'] ?? '/')); ?>
                  <small>Total engaged <?php echo adminEscape((string) ($page['total_minutes'] ?? 0)); ?> min</small>
                </span>
                <span class="traffic-count"><?php echo adminEscape(adminFormatDurationSeconds((int) ($page['avg_seconds'] ?? 0))); ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="empty-note">Engagement timing appears after visitors begin browsing and leaving pages.</div>
        <?php endif; ?>
      </section>

      <section class="list-panel">
        <span class="eyebrow">Top Actions · 30 Days</span>
        <?php if (!empty($trafficSnapshot['thirty_days']['top_actions'])): ?>
          <ul class="traffic-list">
            <?php foreach ($trafficSnapshot['thirty_days']['top_actions'] as $action): ?>
              <li>
                <span class="traffic-list-label"><?php echo adminEscape((string) ($action['label'] ?? 'Interaction')); ?></span>
                <span class="traffic-count"><?php echo adminEscape((string) ($action['count'] ?? 0)); ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="empty-note">Tracked click and contact actions will appear here as people interact with the site.</div>
        <?php endif; ?>
      </section>

      <section class="list-panel">
        <span class="eyebrow">Traffic Sources · 30 Days</span>
        <?php if (!empty($trafficSnapshot['thirty_days']['top_sources'])): ?>
          <ul class="traffic-list">
            <?php foreach ($trafficSnapshot['thirty_days']['top_sources'] as $source): ?>
              <li>
                <span class="traffic-list-label"><?php echo adminEscape((string) ($source['label'] ?? 'Direct')); ?></span>
                <span class="traffic-count"><?php echo adminEscape((string) ($source['count'] ?? 0)); ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="empty-note">Source data will appear after traffic arrives.</div>
        <?php endif; ?>
      </section>

      <section class="list-panel">
        <span class="eyebrow">Recent Visits</span>
        <?php if (!empty($trafficSnapshot['recent_views'])): ?>
          <ul class="traffic-list recent-visits-list">
            <?php foreach ($trafficSnapshot['recent_views'] as $view): ?>
              <li>
                <span class="traffic-list-label">
                  <?php echo adminEscape(adminTrafficPathLabel((string) ($view['path'] ?? '/'), (string) ($view['title'] ?? ''))); ?>
                  <small><?php echo adminEscape(adminTrafficSourceLabel($view)); ?></small>
                </span>
                <span class="traffic-count"><?php echo adminEscape(adminFormatTimestamp((string) ($view['timestamp'] ?? ''))); ?></span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php else: ?>
          <div class="empty-note">Recent activity will appear here once tracking begins.</div>
        <?php endif; ?>
      </section>
    </section>
  </div>
</body>
</html>
