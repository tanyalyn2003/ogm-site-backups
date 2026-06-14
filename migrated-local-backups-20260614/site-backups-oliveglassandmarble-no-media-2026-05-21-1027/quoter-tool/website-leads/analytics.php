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
  <meta name="ogm-analytics-ui" content="2026-05-16">
  <base href="<?php echo adminEscape(adminWebBase()); ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
  <title>Analytics | Olive Glass & Marble</title>
  <style>
:root{
  --cream:#faf8f3;--warm:#f5f2ea;
  --s50:#f7f5ef;--s100:#eae6da;--s200:#d4cfc0;
  --s300:#b8b09c;--s500:#7a7260;--s700:#4a4538;--s900:#1c1917;
  --gold:#9e7c3a;--gold-l:#c4a05a;--gold-p:#f0e6cc;
  --green:#16a34a;--red:#dc2626;
}
*{box-sizing:border-box;margin:0;padding:0}
body{
  background:var(--cream);color:var(--s900);
  font-family:'DM Sans',sans-serif;font-weight:300;font-size:14px;
  line-height:1.5;min-height:100vh;
}

/* ── OGM NAV BAR ── */
.ogm-nav-bar{
  background:#0c0f14;height:44px;display:flex;align-items:center;
  justify-content:space-between;padding:0 32px;
  border-bottom:1px solid rgba(158,124,58,.25);
}
.ogm-nav-logo{
  font-family:'Cormorant Garamond',serif;font-size:18px;font-weight:300;
  letter-spacing:.16em;color:#fff;margin-right:20px;
}
.ogm-nav-left{display:flex;align-items:center}
.ogm-nav-links{display:flex;gap:2px;flex-wrap:wrap}
.ogm-nav-link{
  padding:6px 14px;font-size:11px;letter-spacing:.12em;text-transform:uppercase;
  color:rgba(255,255,255,.4);text-decoration:none;border-radius:2px;
  transition:all .15s;border:1px solid transparent;
}
.ogm-nav-link:hover{color:rgba(255,255,255,.75);background:rgba(255,255,255,.05)}
.ogm-nav-link.active{color:#c4a05a;border-color:rgba(196,160,90,.3);background:rgba(196,160,90,.07)}
.ogm-nav-badge{font-size:9px;letter-spacing:.18em;text-transform:uppercase;color:#334155}

/* ── PAGE HEADER ── */
.page-header{
  background:var(--s900);padding:0 32px;height:58px;
  display:flex;align-items:center;justify-content:space-between;
}
.ph-title{
  font-family:'Cormorant Garamond',serif;font-size:24px;font-weight:300;
  letter-spacing:.08em;color:#fff;
}
.ph-right{display:flex;gap:8px;align-items:center}
.ph-user{font-size:10px;letter-spacing:.16em;text-transform:uppercase;color:var(--s300)}

/* ── SHELL ── */
.shell{max-width:1360px;margin:0 auto;padding:24px 32px 60px}

/* ── BUTTONS ── */
.button-link,button{
  border:none;border-radius:2px;background:var(--s900);color:#fff;
  font:inherit;font-size:11px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;
  padding:9px 18px;cursor:pointer;text-decoration:none;
  display:inline-flex;align-items:center;justify-content:center;transition:background .15s;
}
.button-link:hover,button:hover{background:var(--s700)}
.secondary-link{background:transparent;color:var(--s700);border:1px solid var(--s200)}
.secondary-link:hover{background:var(--s50);color:var(--s900)}

/* ── ADMIN MENU (same as index.php) ── */
.admin-menu{
  display:flex;align-items:stretch;gap:6px;
  margin-bottom:20px;border-bottom:1px solid var(--s200);flex-wrap:wrap;
}
.menu-group{position:relative}
.menu-group>summary,
.menu-link{
  list-style:none;display:inline-flex;align-items:center;gap:8px;
  padding:8px 16px;cursor:pointer;font-size:11px;font-weight:500;
  letter-spacing:.1em;text-transform:uppercase;color:var(--s500);
  border-radius:2px 2px 0 0;border:1px solid transparent;border-bottom:none;
  background:transparent;text-decoration:none;transition:all .15s;
}
.menu-group>summary::-webkit-details-marker{display:none}
.menu-group>summary::after{content:'▾';font-size:.8rem;opacity:.6;margin-left:4px}
.menu-link:hover,
.menu-group>summary:hover{color:var(--gold);background:var(--s50)}
.menu-link.is-active,
.menu-group[open]>summary{
  color:var(--gold-l);background:var(--s50);
  border-color:var(--s200);border-bottom-color:var(--cream);
}
.menu-panel{
  position:absolute;top:calc(100%);left:0;z-index:50;
  width:min(680px,calc(100vw - 64px));
  padding:20px 22px 22px;background:#fff;
  border:1px solid var(--s200);border-radius:0 4px 4px 4px;
  box-shadow:0 12px 40px rgba(28,25,23,.12);
}
.team-note{margin:0 0 16px;color:var(--s500);line-height:1.6;font-size:13px}

/* ── SHARED FORM ELEMENTS ── */
.eyebrow{
  display:block;margin-bottom:5px;
  font-size:9px;font-weight:500;letter-spacing:.2em;text-transform:uppercase;
  color:var(--s300);
}
label{
  display:block;font-size:10px;font-weight:500;
  letter-spacing:.12em;text-transform:uppercase;color:var(--s500);margin-bottom:5px;
}
input,select,textarea{
  width:100%;border:1px solid var(--s200);border-radius:2px;
  padding:9px 11px;font:inherit;font-size:13px;color:var(--s900);
  background:#fff;outline:none;transition:border-color .15s;
}
input:focus,select:focus,textarea:focus{border-color:var(--gold)}
textarea{min-height:100px;resize:vertical}
.checkbox-row{
  display:inline-flex;align-items:center;gap:8px;
  font-size:12px;font-weight:400;color:var(--s500);cursor:pointer;white-space:nowrap;
}
.checkbox-row input{width:auto;margin:0}
.field-hint{margin-top:5px;color:var(--s300);font-size:11px;line-height:1.5}
.section-divider{margin-top:8px;padding-top:14px;border-top:1px solid var(--s100)}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px}
.team-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap;margin-top:8px}
.rep-grid{display:grid;gap:10px;margin-bottom:12px}
.rep-row{
  display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:end;
  padding:10px 12px;border-radius:2px;background:var(--s50);border:1px solid var(--s100);
}
.login-grid{display:grid;gap:10px;margin-bottom:12px}
.login-row{
  display:grid;
  grid-template-columns:minmax(0,1fr) minmax(130px,.8fr) minmax(0,1fr) auto;
  gap:10px;align-items:end;
  padding:10px 12px;border-radius:2px;background:var(--s50);border:1px solid var(--s100);
}

/* ── FLASH ── */
.flash{
  margin-bottom:16px;padding:12px 16px;border-radius:2px;
  background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.2);
  color:var(--green);font-size:13px;
}

/* ── PANEL (analytics section card) ── */
.panel{
  background:#fff;border:1px solid var(--s100);border-radius:4px;
  padding:20px 24px;margin-bottom:16px;
}
.section-head{
  display:flex;justify-content:space-between;
  align-items:flex-end;gap:16px;margin-bottom:16px;
}
.section-head h2{
  margin:0;font-family:'Cormorant Garamond',serif;
  font-size:22px;font-weight:400;letter-spacing:.04em;color:var(--s900);
}
.section-note{color:var(--s500);font-size:12px;line-height:1.7;max-width:860px}

/* ── METRICS GRID (Today / This Week / This Month etc.) ── */
.metrics-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px;
}
.metric-card{
  background:var(--s50);border:1px solid var(--s100);border-radius:2px;
  padding:16px 18px;
}
.metric-card strong{
  display:block;margin-top:5px;
  font-family:'DM Mono',monospace;font-size:28px;font-weight:400;
  color:var(--gold);line-height:1;
}
.metric-subvalue{
  display:block;margin-top:8px;color:var(--s300);
  font-size:11px;line-height:1.5;
}

/* ── TREND GRID (comparison cards with SVG charts) ── */
.trend-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:12px;
}
.trend-card{
  background:var(--s50);border:1px solid var(--s100);border-radius:2px;padding:20px;
}
.trend-card h3{
  margin:0;font-family:'Cormorant Garamond',serif;
  font-size:18px;font-weight:400;letter-spacing:.04em;color:var(--s700);
}
.trend-value-row{
  display:flex;align-items:baseline;gap:10px;flex-wrap:wrap;margin-top:10px;
}
.trend-value{
  font-family:'DM Mono',monospace;
  font-size:clamp(28px,4vw,40px);font-weight:400;line-height:1;color:var(--s900);
}
.trend-change{font-size:13px;font-weight:500}
.trend-change.is-up{color:var(--green)}
.trend-change.is-down{color:var(--red)}
.trend-change.is-flat{color:var(--s300)}
.trend-subvalue{margin-top:10px;color:var(--s500);font-size:12px;line-height:1.6}
.trend-chart{margin-top:16px}
.trend-chart svg{display:block;width:100%;height:200px}
.trend-legend{margin-top:6px;color:var(--s300);font-size:11px;font-weight:500}

/* ── SPLIT GRID (lead mix + queue side by side) ── */
.split-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));
  gap:12px;margin-bottom:16px;
}

/* ── MINI GRID / MINI METRIC (small stat boxes) ── */
.mini-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(100px,1fr));
  gap:10px;margin-top:12px;
}
.mini-metric{
  padding:12px 14px;border-radius:2px;
  background:var(--s50);border:1px solid var(--s100);
}
.mini-metric span{
  display:block;color:var(--s300);
  font-size:9px;letter-spacing:.14em;text-transform:uppercase;font-weight:500;
}
.mini-metric strong{
  display:block;margin-top:5px;
  font-family:'DM Mono',monospace;font-size:20px;font-weight:400;
  color:var(--s900);line-height:1;
}

/* ── QUEUE GRIDS (status breakdown) ── */
.queue-grid{margin-top:12px;display:grid;gap:10px}
.queue-grid+.queue-grid{margin-top:10px}
.queue-grid-primary{grid-template-columns:repeat(4,minmax(0,1fr))}
.queue-grid-secondary{grid-template-columns:repeat(2,minmax(0,1fr))}

/* ── LIST PANELS + TRAFFIC LISTS ── */
.list-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px;
}
.list-panel{background:#fff;border:1px solid var(--s100);border-radius:4px;padding:18px 20px}
.traffic-list{list-style:none;margin:0;padding:0;display:grid;gap:8px}
.traffic-list li{
  display:grid;grid-template-columns:minmax(0,1fr) auto;
  align-items:start;gap:10px;
  padding:10px 12px;border-radius:2px;
  background:var(--s50);border:1px solid var(--s100);font-size:13px;
}
.traffic-list-label{min-width:0;line-height:1.5;overflow-wrap:break-word}
.traffic-list-label small{
  display:block;margin-top:3px;color:var(--s300);font-size:11px;
}
.traffic-count{font-family:'DM Mono',monospace;font-weight:400;white-space:nowrap;text-align:right}
.recent-visits-list li{grid-template-columns:1fr;gap:6px}
.recent-visits-list .traffic-count{order:-1;text-align:left;white-space:normal}

/* ── EMPTY NOTE ── */
.empty-note{color:var(--s300);line-height:1.6;font-size:13px}

/* ── RESPONSIVE ── */
@media(max-width:900px){
  .ogm-nav-bar{padding:0 16px}
  .page-header{padding:0 16px}
  .shell{padding:16px 16px 60px}
  .ogm-nav-links{display:none}
  .admin-menu{flex-direction:column;gap:4px;border-bottom:none}
  .menu-link,.menu-group{width:100%}
  .menu-link{border-radius:2px;border:1px solid var(--s200);padding:10px 14px}
  .menu-group>summary{border-radius:2px;border:1px solid var(--s200);padding:10px 14px;width:100%}
  .menu-panel{position:static;width:100%;margin-top:6px;border-radius:2px}
  .trend-grid,.split-grid,.list-grid{grid-template-columns:1fr}
  .queue-grid-primary{grid-template-columns:repeat(2,1fr)}
  .rep-row,.login-row{grid-template-columns:1fr}
}

@media print{
  .ogm-nav-bar,.page-header,.admin-menu{display:none!important}
  body{background:#fff}
  .shell{padding:0}
  .panel,.trend-card,.list-panel{break-inside:avoid;box-shadow:none}
}

/* ── Dark theme (ogm-theme-toggle) ── */
html[data-ogm-theme="dark"]{
  color-scheme:dark;
  --cream:#0c1018;--warm:#0f141d;
  --s50:#141b28;--s100:#1b2434;--s200:#2a3548;
  --s300:#8b97ab;--s500:#b3bfce;
  --gold-p:#252016;
}
html[data-ogm-theme="dark"] body{
  background:var(--cream);color:#e6edf5;
}
html[data-ogm-theme="dark"] .panel,
html[data-ogm-theme="dark"] .list-panel,
html[data-ogm-theme="dark"] .menu-panel{
  background:#1e293b;
  border-color:rgba(255,255,255,.08);
  color:#e6edf5;
}
html[data-ogm-theme="dark"] .admin-menu{
  border-bottom-color:rgba(255,255,255,.08);
}
html[data-ogm-theme="dark"] .menu-link,
html[data-ogm-theme="dark"] .menu-group>summary{color:var(--s500)}
html[data-ogm-theme="dark"] .menu-link:hover,
html[data-ogm-theme="dark"] .menu-group>summary:hover{
  color:var(--gold-l);background:var(--s50);
}
html[data-ogm-theme="dark"] .menu-link.is-active,
html[data-ogm-theme="dark"] .menu-group[open]>summary{
  color:var(--gold-l);background:var(--s50);
  border-color:rgba(255,255,255,.08);border-bottom-color:var(--cream);
}
html[data-ogm-theme="dark"] .menu-panel{box-shadow:0 12px 40px rgba(0,0,0,.35)}
html[data-ogm-theme="dark"] input,
html[data-ogm-theme="dark"] select,
html[data-ogm-theme="dark"] textarea{
  background:#1a2436;color:#e6edf5;
  border-color:rgba(255,255,255,.12);
}
html[data-ogm-theme="dark"] input:focus,
html[data-ogm-theme="dark"] select:focus,
html[data-ogm-theme="dark"] textarea:focus{border-color:var(--gold-l)}
html[data-ogm-theme="dark"] label,
html[data-ogm-theme="dark"] .eyebrow{color:var(--s300)}
html[data-ogm-theme="dark"] .team-note,
html[data-ogm-theme="dark"] .section-note,
html[data-ogm-theme="dark"] .field-hint,
html[data-ogm-theme="dark"] .trend-subvalue,
html[data-ogm-theme="dark"] .metric-subvalue,
html[data-ogm-theme="dark"] .empty-note{color:var(--s500)}
html[data-ogm-theme="dark"] .metric-card,
html[data-ogm-theme="dark"] .trend-card,
html[data-ogm-theme="dark"] .mini-metric,
html[data-ogm-theme="dark"] .rep-row,
html[data-ogm-theme="dark"] .login-row,
html[data-ogm-theme="dark"] .traffic-list li{
  background:var(--s50);border-color:rgba(255,255,255,.08);
}
html[data-ogm-theme="dark"] .section-head h2,
html[data-ogm-theme="dark"] .trend-card h3,
html[data-ogm-theme="dark"] .mini-metric strong{color:#f1f5f9}
html[data-ogm-theme="dark"] .metric-card strong,
html[data-ogm-theme="dark"] .overview-value{color:var(--gold-l)}
html[data-ogm-theme="dark"] .trend-value{color:#f1f5f9}
html[data-ogm-theme="dark"] .trend-legend,
html[data-ogm-theme="dark"] .traffic-list-label small{color:var(--s300)}
html[data-ogm-theme="dark"] .traffic-count{color:var(--gold-l)}
html[data-ogm-theme="dark"] .section-divider{border-top-color:rgba(255,255,255,.08)}
html[data-ogm-theme="dark"] .secondary-link{
  color:var(--s500);border-color:rgba(255,255,255,.12);
}
html[data-ogm-theme="dark"] .secondary-link:hover{
  background:var(--s50);color:#f1f5f9;
}
html[data-ogm-theme="dark"] .checkbox-row{color:var(--s500)}
html[data-ogm-theme="dark"] .flash{
  background:rgba(22,163,74,.12);border-color:rgba(22,163,74,.35);color:#86efac;
}
html[data-ogm-theme="dark"] .trend-change.is-up{color:#86efac}
html[data-ogm-theme="dark"] .trend-change.is-down{color:#fca5a5}
html[data-ogm-theme="dark"] .trend-change.is-flat{color:var(--s300)}
  </style>
  <script>try{if(localStorage.getItem('ogm-theme')==='dark')document.documentElement.setAttribute('data-ogm-theme','dark');}catch(e){}</script>
  <link rel="stylesheet" href="../ogm-theme-toggle.css?v=20260516p">
  <script src="../ogm-theme-toggle.js?v=20260516o" defer></script>
  <link rel="stylesheet" href="../ogm-accessibility.css?v=20260516c">
  <script src="../ogm-accessibility.js?v=20260516o" defer></script>
</head>
<body>

<!-- OGM NAV BAR -->
<div class="ogm-nav-bar">
  <div class="ogm-nav-left">
    <span class="ogm-nav-logo">OGM</span>
    <nav class="ogm-nav-links">
      <a class="ogm-nav-link" href="../hub.php">⌂ Hub</a>
      <a class="ogm-nav-link" href="../index.php">✦ Stone</a>
      <a class="ogm-nav-link" href="../glass-quoter.php">◈ Glass</a>
      <a class="ogm-nav-link" href="../shower-builder.php">🚿 Shower</a>
      <a class="ogm-nav-link" href="../customer-db.php">◎ Customers</a>
      <a class="ogm-nav-link" href="../job-tracking.php">◈ Jobs</a>
      <a class="ogm-nav-link" href="../message-center.php">✉ Messages</a>
      <a class="ogm-nav-link" href="../invoice-manager.php">◻ Invoices</a>
      <a class="ogm-nav-link" href="../sales-reports.php">◑ Reports</a>
      <a class="ogm-nav-link" href="../calendar.php">📅 Schedule</a>
      <a class="ogm-nav-link active" href="index.php">◉ Leads</a>
    </nav>
  </div>
  <div class="ogm-nav-right">
    <span class="ogm-nav-badge">Internal — Not Client Facing</span>
  </div>
</div>

<!-- PAGE HEADER -->
<div class="page-header">
  <div class="ph-title">Analytics</div>
  <div class="ph-right">
    <span class="ph-user">
      <?php echo adminEscape((string) ($currentUser['display_name'] ?? $currentUser['username'] ?? '')); ?>
    </span>
    <a class="button-link secondary-link" href="index.php">Dashboard</a>
    <a class="button-link secondary-link" href="<?php echo adminEscape($reportHref); ?>" target="_blank" rel="noopener noreferrer">Print Report</a>
    <a class="button-link" href="logout.php" style="background:transparent;border:1px solid rgba(255,255,255,.2);color:rgba(255,255,255,.6)">Log out</a>
  </div>
</div>

<div class="shell">

    <nav class="admin-menu" aria-label="Analytics menu">
      <a class="menu-link" href="index.php">Dashboard</a>
      <a class="menu-link is-active" href="analytics.php">Analytics</a>
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
                'line_color' => '#c4a05a',
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
