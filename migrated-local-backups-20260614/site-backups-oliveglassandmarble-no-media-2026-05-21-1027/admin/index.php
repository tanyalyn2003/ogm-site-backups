<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

adminSendNoIndexHeaders();
adminRequireLogin();

$currentUser = adminCurrentUser();
$statusOptions = adminStatusOptions();
$allLeads = adminBuildLeads();
$activeOverviewLeads = adminFilterLeads($allLeads, [
  'view' => 'active',
  'status' => '',
  'owner' => '',
  'q' => '',
]);
$overviewSummary = adminBuildLeadSummary($activeOverviewLeads);
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

$filters = [
  'view' => trim((string) ($_GET['view'] ?? 'active')),
  'status' => trim((string) ($_GET['status'] ?? '')),
  'owner' => trim((string) ($_GET['owner'] ?? '')),
  'q' => trim((string) ($_GET['q'] ?? '')),
];

if (!in_array($filters['view'], ['active', 'trash'], true)) {
  $filters['view'] = 'active';
}

$leads = adminFilterLeads($allLeads, $filters);
$flashSaved = isset($_GET['saved']) && $_GET['saved'] === '1';
$flashTeamSaved = isset($_GET['team_saved']) && $_GET['team_saved'] === '1';
$flashTrashSaved = isset($_GET['trash_saved']) && $_GET['trash_saved'] === '1';
$flashBulkSaved = isset($_GET['bulk_saved']) && $_GET['bulk_saved'] === '1';
$flashBulkError = isset($_GET['bulk_error']) && $_GET['bulk_error'] === '1';
$flashDeleted = isset($_GET['deleted']) && $_GET['deleted'] === '1';
$flashTrashEmpty = isset($_GET['trash_empty']) && $_GET['trash_empty'] === '1';
$flashUsersSaved = isset($_GET['users_saved']) && $_GET['users_saved'] === '1';
$flashUsersError = trim((string) ($_GET['users_error'] ?? ''));
$flashAlertsSaved = isset($_GET['alerts_saved']) && $_GET['alerts_saved'] === '1';
$flashAlertsTest = isset($_GET['alerts_test']) && $_GET['alerts_test'] === '1';
$flashAlertsError = trim((string) ($_GET['alerts_error'] ?? ''));
$flashMetaSaved = isset($_GET['meta_saved']) && $_GET['meta_saved'] === '1';
$flashMetaError = trim((string) ($_GET['meta_error'] ?? ''));
$bulkCount = max(0, (int) ($_GET['bulk_count'] ?? 0));
$deletedCount = max(0, (int) ($_GET['deleted_count'] ?? 0));
$filterQuery = $_GET;
unset($filterQuery['saved']);
unset($filterQuery['team_saved']);
unset($filterQuery['trash_saved']);
unset($filterQuery['bulk_saved']);
unset($filterQuery['bulk_error']);
unset($filterQuery['bulk_count']);
unset($filterQuery['deleted']);
unset($filterQuery['deleted_count']);
unset($filterQuery['trash_empty']);
unset($filterQuery['users_saved']);
unset($filterQuery['users_error']);
unset($filterQuery['alerts_saved']);
unset($filterQuery['alerts_test']);
unset($filterQuery['alerts_error']);
unset($filterQuery['meta_saved']);
unset($filterQuery['meta_error']);
unset($filterQuery['open']);
$returnQuery = http_build_query($filterQuery);

$ownerOptions = adminOwnerOptions($filters['owner']);
$openLeadKey = trim((string) ($_GET['open'] ?? ''));
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
  <title>Sales Dashboard | Olive Glass & Marble</title>
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
      --sand: #ddc4a0;
      --new: #d97706;
      --partial: #7c5c2d;
      --contacted: #1d6fa5;
      --quoted: #7745c6;
      --won: #18794e;
      --closed: #70757d;
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
      max-width: 720px;
      line-height: 1.5;
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

    .danger-button {
      background: linear-gradient(135deg, #a23535 0%, #7d2424 100%);
    }

    .ghost-button {
      background: rgba(255, 255, 255, 0.75);
      color: var(--text);
      border: 1px solid rgba(219, 202, 180, 0.9);
    }

    .overview-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
      gap: 14px;
      margin-bottom: 18px;
    }

    .overview-card {
      padding: 18px;
    }

    .overview-card > summary {
      list-style: none;
      cursor: pointer;
    }

    .overview-card > summary::-webkit-details-marker {
      display: none;
    }

    .overview-value {
      display: block;
      margin-top: 6px;
      font-size: 2rem;
      line-height: 1;
    }

    .overview-note {
      margin-top: 10px;
      color: var(--muted);
      line-height: 1.6;
    }

    .overview-breakdown,
    .overview-metrics {
      display: grid;
      gap: 12px;
      margin-top: 16px;
    }

    .overview-metrics {
      grid-template-columns: repeat(auto-fit, minmax(110px, 1fr));
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
      font-size: 1.6rem;
      line-height: 1;
    }

    .overview-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      margin-top: 14px;
      font-weight: 800;
      color: var(--accent);
      text-decoration: none;
    }

    .stat,
    .filters,
    .card {
      background: var(--panel);
      border: 1px solid rgba(219, 202, 180, 0.85);
      border-radius: 22px;
      box-shadow: var(--shadow);
    }

    .filters {
      padding: 18px;
      margin-bottom: 18px;
    }

    .filters form {
      display: grid;
      grid-template-columns: 1.6fr repeat(2, minmax(170px, 0.7fr)) auto;
      gap: 12px;
    }

    .view-switch {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-bottom: 18px;
      padding: 0 4px;
      border-bottom: 1px solid rgba(219, 202, 180, 0.9);
    }

    .view-switch a {
      text-decoration: none;
    }

    .view-tab {
      display: inline-flex;
      align-items: center;
      padding: 12px 14px;
      border-radius: 14px 14px 0 0;
      font-weight: 800;
      color: var(--accent-strong);
      border: 1px solid rgba(21, 82, 71, 0.2);
      background: linear-gradient(135deg, var(--view-start, #9bc5af) 0%, var(--view-end, #78ad95) 100%);
      box-shadow: 0 12px 24px rgba(15, 59, 51, 0.14);
      transition: background 160ms ease, color 160ms ease, box-shadow 160ms ease, transform 160ms ease;
    }

    .view-tab-active {
      --view-start: #96c4a7;
      --view-end: #76a98c;
      --view-active-start: #155247;
      --view-active-end: #0f3b33;
    }

    .view-tab-trash {
      --view-start: #b2d7bb;
      --view-end: #92c19e;
      --view-active-start: #2a745d;
      --view-active-end: #1b5745;
    }

    .view-tab:hover {
      transform: translateY(-1px);
      box-shadow: 0 14px 28px rgba(15, 59, 51, 0.18);
    }

    .view-tab.is-active {
      color: #fff;
      border-color: rgba(15, 59, 51, 0.55);
      background: linear-gradient(135deg, var(--view-active-start, #155247) 0%, var(--view-active-end, #0f3b33) 100%);
      box-shadow: 0 18px 34px rgba(15, 59, 51, 0.28);
      transform: translateY(-1px);
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

    .flash {
      margin-bottom: 18px;
      padding: 14px 16px;
      border-radius: 18px;
      background: rgba(24, 121, 78, 0.1);
      border: 1px solid rgba(24, 121, 78, 0.18);
      color: #155134;
      font-weight: 700;
    }

    .results-meta {
      color: var(--muted);
      font-weight: 600;
    }

    .results-bar {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      margin-bottom: 12px;
    }

    .lead-list {
      display: grid;
      gap: 18px;
    }

    .card {
      padding: 22px;
    }

    .lead-card {
      padding: 0;
      overflow: hidden;
    }

    .lead-card.is-trashed {
      opacity: 0.88;
    }

    .lead-card > summary {
      list-style: none;
      cursor: pointer;
      padding: 18px 22px;
    }

    .lead-card > summary::-webkit-details-marker {
      display: none;
    }

    .lead-summary-line {
      display: grid;
      grid-template-columns: auto minmax(0, 1.4fr) minmax(0, 1fr);
      gap: 16px;
      align-items: center;
    }

    .lead-select-cell {
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .lead-select-cell input {
      width: 18px;
      height: 18px;
      margin: 0;
      cursor: pointer;
    }

    .lead-summary-main {
      min-width: 0;
    }

    .lead-summary-name {
      font-size: 1.08rem;
      font-weight: 800;
      overflow-wrap: anywhere;
    }

    .lead-summary-contact {
      margin-top: 5px;
      color: var(--muted);
      font-weight: 600;
      overflow-wrap: anywhere;
    }

    .lead-summary-meta {
      display: flex;
      justify-content: flex-end;
      align-items: center;
      gap: 10px;
      flex-wrap: wrap;
      min-width: 0;
    }

    .lead-summary-time {
      color: var(--muted);
      font-weight: 600;
      white-space: nowrap;
    }

    .lead-card-panel {
      padding: 0 22px 22px;
      border-top: 1px solid rgba(219, 202, 180, 0.9);
    }

    .card-top {
      display: flex;
      justify-content: space-between;
      gap: 18px;
      align-items: start;
      margin-bottom: 16px;
    }

    .title-group {
      min-width: 0;
    }

    .title-group h2 {
      margin: 0 0 8px;
      font-size: 1.35rem;
      overflow-wrap: anywhere;
    }

    .meta-row,
    .detail-grid {
      display: grid;
      gap: 10px 16px;
    }

    .meta-row {
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      margin-bottom: 16px;
    }

    .detail-grid {
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      margin-bottom: 16px;
    }

    .meta-box,
    .detail-box {
      min-width: 0;
      padding: 14px 16px;
      border-radius: 16px;
      background: rgba(255, 255, 255, 0.65);
      border: 1px solid rgba(219, 202, 180, 0.7);
      overflow-wrap: anywhere;
      word-break: break-word;
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

    .badge-row {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .badge {
      display: inline-flex;
      align-items: center;
      border-radius: 999px;
      padding: 7px 12px;
      font-size: 0.8rem;
      font-weight: 800;
      letter-spacing: 0.02em;
      background: rgba(221, 196, 160, 0.28);
      color: var(--text);
    }

    .status-new { background: rgba(217, 119, 6, 0.12); color: var(--new); }
    .status-partial { background: rgba(124, 92, 45, 0.12); color: var(--partial); }
    .status-contacted { background: rgba(29, 111, 165, 0.12); color: var(--contacted); }
    .status-quoted { background: rgba(119, 69, 198, 0.12); color: var(--quoted); }
    .status-won { background: rgba(24, 121, 78, 0.12); color: var(--won); }
    .status-closed { background: rgba(112, 117, 125, 0.14); color: var(--closed); }

    .summary {
      margin-bottom: 16px;
      line-height: 1.6;
      white-space: pre-wrap;
      max-height: 220px;
      overflow: auto;
    }

    .lead-subdetails {
      margin-bottom: 16px;
    }

    .lead-subdetails summary {
      cursor: pointer;
      font-weight: 700;
      color: var(--accent);
    }

    .transcript-body {
      margin-top: 12px;
      white-space: pre-wrap;
      line-height: 1.7;
      max-height: 320px;
      overflow: auto;
      padding-right: 6px;
    }

    .history {
      margin: 18px 0 0;
      padding: 0;
      list-style: none;
      display: grid;
      gap: 10px;
    }

    .history li {
      padding: 12px 14px;
      border-radius: 14px;
      background: rgba(255, 255, 255, 0.62);
      border: 1px solid rgba(219, 202, 180, 0.7);
    }

    .history strong {
      display: inline-block;
      margin-right: 8px;
    }

    .card form {
      display: grid;
      gap: 12px;
      margin-top: 18px;
      padding-top: 16px;
      border-top: 1px solid rgba(219, 202, 180, 0.9);
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

    .bulk-toolbar {
      display: grid;
      grid-template-columns: auto minmax(180px, 220px) minmax(180px, 220px) minmax(180px, 220px) auto;
      gap: 12px;
      align-items: end;
      margin-bottom: 18px;
      padding: 18px;
    }

    .bulk-select-all {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      font-weight: 800;
      color: var(--text);
      white-space: nowrap;
    }

    .bulk-select-all input {
      width: 18px;
      height: 18px;
      margin: 0;
      cursor: pointer;
    }

    .bulk-helper {
      margin-top: 6px;
      color: var(--muted);
      font-size: 0.86rem;
      line-height: 1.5;
    }

    .lead-actions {
      display: flex;
      justify-content: space-between;
      gap: 12px;
      align-items: center;
      flex-wrap: wrap;
    }

    .action-cluster {
      display: flex;
      gap: 12px;
      align-items: center;
      flex-wrap: wrap;
    }

    .lead-actions form {
      margin: 0;
      padding: 0;
      border: 0;
    }

    .form-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 12px;
    }

    .empty {
      padding: 48px 20px;
      text-align: center;
      color: var(--muted);
      background: var(--panel);
      border: 1px solid rgba(219, 202, 180, 0.85);
      border-radius: 22px;
      box-shadow: var(--shadow);
    }

    a.inline-link {
      display: inline-block;
      max-width: 100%;
      color: var(--accent);
      text-decoration: none;
      font-weight: 700;
      overflow-wrap: anywhere;
      word-break: break-word;
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

      .filters form {
        grid-template-columns: 1fr;
      }

      .view-switch {
        flex-direction: column;
        align-items: stretch;
        gap: 8px;
        border-bottom: 0;
        padding: 0;
      }

      .view-tab {
        width: 100%;
        justify-content: space-between;
        border-radius: 16px;
      }

      .bulk-toolbar {
        grid-template-columns: 1fr;
      }

      .results-bar {
        align-items: stretch;
      }

      .card-top {
        flex-direction: column;
      }

      .lead-summary-line {
        grid-template-columns: auto 1fr;
      }

      .lead-summary-meta {
        justify-content: flex-start;
        grid-column: 1 / -1;
      }

      .rep-row {
        grid-template-columns: 1fr;
        align-items: start;
      }

      .login-row {
        grid-template-columns: 1fr;
        align-items: start;
      }
    }
  </style>
</head>
<body>
  <div class="shell">
    <header class="topbar">
      <div>
        <h1>Lead Dashboard</h1>
      </div>
      <div class="topbar-actions">
        <div class="topbar-controls">
          <a class="button-link secondary-link" href="analytics.php">Analytics</a>
          <a class="button-link secondary-link" href="<?php echo adminEscape($reportHref); ?>" target="_blank" rel="noopener noreferrer">Print Report</a>
          <a class="button-link" href="logout.php">Log out</a>
        </div>
        <div class="topbar-meta">Signed in as <?php echo adminEscape((string) ($currentUser['display_name'] ?? $currentUser['username'] ?? '')); ?></div>
      </div>
    </header>

    <nav class="admin-menu" aria-label="Dashboard menu">
      <a class="menu-link menu-dashboard is-active" href="index.php">Dashboard</a>
      <a class="menu-link menu-analytics" href="analytics.php">Analytics</a>
      <details class="menu-group menu-group-reps">
        <summary>Manage Sales Reps</summary>
        <div class="menu-panel">
          <p class="team-note">Rename, add, or remove reps here. Renaming updates existing assignments. Removing a rep clears that owner from assigned leads.</p>
          <form method="post" action="update-team.php">
            <input type="hidden" name="csrf_token" value="<?php echo adminEscape(adminCsrfToken()); ?>">
            <input type="hidden" name="return_to" value="index.php">
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
                <div class="meta-box">No reps added yet. Use the field below to add your first names.</div>
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
            <input type="hidden" name="return_to" value="index.php">
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
                  <label for="new-user-display-index">Display Name</label>
                  <input id="new-user-display-index" type="text" name="new_display_name" placeholder="Sed">
                </div>
                <div>
                  <label for="new-user-username-index">Username</label>
                  <input id="new-user-username-index" type="text" name="new_username" placeholder="sed" autocomplete="username">
                  <div class="field-hint">Use letters, numbers, dots, dashes, or underscores.</div>
                </div>
                <div>
                  <label for="new-user-password-index">Password</label>
                  <input id="new-user-password-index" type="password" name="new_password" autocomplete="new-password" placeholder="Set a password">
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
          <div class="meta-box" style="margin-bottom: 16px;">
            <span class="eyebrow">Current Status</span>
            <?php echo adminEscape($pushoverStatusText); ?>
          </div>
          <form method="post" action="update-alerts.php">
            <input type="hidden" name="csrf_token" value="<?php echo adminEscape(adminCsrfToken()); ?>">
            <input type="hidden" name="return_to" value="index.php">
            <label class="checkbox-row">
              <input type="checkbox" name="pushover_enabled" value="1"<?php echo !empty($pushoverSettings['enabled']) ? ' checked' : ''; ?>>
              Enable Pushover for full leads
            </label>
            <div class="form-grid">
              <div>
                <label for="pushover-token-index">App Token</label>
                <input id="pushover-token-index" type="text" name="pushover_token" value="<?php echo adminEscape((string) ($pushoverSettings['token'] ?? '')); ?>" placeholder="Pushover application token" autocomplete="off">
                <div class="field-hint">Use the API token from your Pushover application.</div>
              </div>
              <div>
                <label for="pushover-user-index">User or Group Key</label>
                <input id="pushover-user-index" type="text" name="pushover_user" value="<?php echo adminEscape((string) ($pushoverSettings['user'] ?? '')); ?>" placeholder="Pushover user or group key" autocomplete="off">
                <div class="field-hint">Use a user key for one person or a group key for a team.</div>
              </div>
            </div>
            <div class="form-grid">
              <div>
                <label for="pushover-device-index">Device (Optional)</label>
                <input id="pushover-device-index" type="text" name="pushover_device" value="<?php echo adminEscape((string) ($pushoverSettings['device'] ?? '')); ?>" placeholder="Leave blank for all devices" autocomplete="off">
                <div class="field-hint">Leave blank to notify every device on that Pushover account.</div>
              </div>
              <div>
                <label for="pushover-sound-index">Sound (Optional)</label>
                <input id="pushover-sound-index" type="text" name="pushover_sound" value="<?php echo adminEscape((string) ($pushoverSettings['sound'] ?? '')); ?>" placeholder="Use account default" autocomplete="off">
                <div class="field-hint">Optional Pushover sound name. Leave blank to use the app default.</div>
              </div>
              <div>
                <label for="pushover-priority-index">Priority</label>
                <select id="pushover-priority-index" name="pushover_priority">
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
          <p class="team-note">Use this to receive Facebook Page messages and Instagram DMs in the dashboard, with optional Pushover alerts. Replies stay in Meta Business Suite, Messenger, or Instagram.</p>
          <div class="meta-box" style="margin-bottom: 16px;">
            <span class="eyebrow">Current Status</span>
            <?php echo adminEscape($metaStatusText); ?>
          </div>
          <form method="post" action="update-meta.php">
            <input type="hidden" name="csrf_token" value="<?php echo adminEscape(adminCsrfToken()); ?>">
            <input type="hidden" name="return_to" value="index.php">
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
                <label for="meta-callback-index">Callback URL</label>
                <input id="meta-callback-index" type="text" value="<?php echo adminEscape($metaCallbackUrl); ?>" readonly>
                <div class="field-hint">Paste this into the Meta developer webhook callback field.</div>
              </div>
              <div>
                <label for="meta-verify-token-index">Verify Token</label>
                <input id="meta-verify-token-index" type="text" name="meta_verify_token" value="<?php echo adminEscape((string) ($metaSettings['verify_token'] ?? '')); ?>" autocomplete="off">
                <div class="field-hint">Use this same value in the Meta webhook verify token field.</div>
              </div>
            </div>
            <div class="form-grid">
              <div>
                <label for="meta-secret-index">App Secret</label>
                <input id="meta-secret-index" type="password" name="meta_app_secret" value="<?php echo adminEscape((string) ($metaSettings['app_secret'] ?? '')); ?>" autocomplete="off">
                <div class="field-hint">Used to verify Meta webhook signatures.</div>
              </div>
              <div>
                <label for="meta-page-id-index">Facebook Page ID</label>
                <input id="meta-page-id-index" type="text" name="meta_facebook_page_id" value="<?php echo adminEscape((string) ($metaSettings['facebook_page_id'] ?? '')); ?>" autocomplete="off">
                <div class="field-hint">Optional but recommended. Helps filter your own Page replies.</div>
              </div>
              <div>
                <label for="meta-ig-id-index">Instagram Account ID</label>
                <input id="meta-ig-id-index" type="text" name="meta_instagram_account_id" value="<?php echo adminEscape((string) ($metaSettings['instagram_account_id'] ?? '')); ?>" autocomplete="off">
                <div class="field-hint">Optional but recommended. Helps suppress your own Instagram replies.</div>
              </div>
            </div>
            <div class="team-actions">
              <button type="submit">Save</button>
            </div>
          </form>
        </div>
      </details>
    </nav>

    <section class="overview-grid">
      <article class="card overview-card">
        <div>
          <span class="eyebrow">Website Leads</span>
          <strong class="overview-value"><?php echo adminEscape((string) $overviewSummary['total']); ?></strong>
          <div class="overview-note">Active leads in the queue, with the full versus partial breakdown shown here.</div>
        </div>
        <div class="overview-breakdown">
          <div class="overview-metrics">
            <div class="mini-metric">
              <span>Full Leads</span>
              <strong><?php echo adminEscape((string) $overviewSummary['full']); ?></strong>
            </div>
            <div class="mini-metric">
              <span>Partial Only</span>
              <strong><?php echo adminEscape((string) $overviewSummary['partial_only']); ?></strong>
            </div>
          </div>
          <a class="overview-link" href="analytics.php">View intake history and traffic</a>
        </div>
      </article>

      <article class="card overview-card">
        <span class="eyebrow">Sales Follow-Up</span>
        <strong class="overview-value"><?php echo adminEscape((string) ($overviewSummary['unassigned'] + $overviewSummary['assigned'])); ?></strong>
        <div class="overview-note">Quick view of who still needs an owner and which leads have already been touched.</div>
        <div class="overview-metrics">
          <div class="mini-metric">
            <span>Unassigned</span>
            <strong><?php echo adminEscape((string) $overviewSummary['unassigned']); ?></strong>
          </div>
          <div class="mini-metric">
            <span>Assigned</span>
            <strong><?php echo adminEscape((string) $overviewSummary['assigned']); ?></strong>
          </div>
          <div class="mini-metric">
            <span>Contacted</span>
            <strong><?php echo adminEscape((string) $overviewSummary['contacted']); ?></strong>
          </div>
        </div>
      </article>

      <article class="card overview-card">
        <span class="eyebrow">Pipeline</span>
        <strong class="overview-value"><?php echo adminEscape((string) ($overviewSummary['quoted'] + $overviewSummary['won'])); ?></strong>
        <div class="overview-note">Keep the high-level quote and win counts here, even if detailed pricing still lives in your other software.</div>
        <div class="overview-metrics">
          <div class="mini-metric">
            <span>Quoted</span>
            <strong><?php echo adminEscape((string) $overviewSummary['quoted']); ?></strong>
          </div>
          <div class="mini-metric">
            <span>Won</span>
            <strong><?php echo adminEscape((string) $overviewSummary['won']); ?></strong>
          </div>
        </div>
      </article>
    </section>

    <section class="filters">
      <div class="view-switch">
        <a class="view-tab view-tab-active<?php echo $filters['view'] === 'trash' ? '' : ' is-active'; ?>" href="index.php?view=active">Active Leads</a>
        <a class="view-tab view-tab-trash<?php echo $filters['view'] === 'trash' ? ' is-active' : ''; ?>" href="index.php?view=trash">Trash</a>
      </div>
      <form method="get" action="index.php">
        <input type="hidden" name="view" value="<?php echo adminEscape($filters['view']); ?>">
        <div>
          <label for="q">Search</label>
          <input id="q" type="text" name="q" value="<?php echo adminEscape($filters['q']); ?>" placeholder="Name, email, phone, city, material, notes...">
        </div>
        <div>
          <label for="status">Status</label>
          <select id="status" name="status">
            <option value="">All statuses</option>
            <?php foreach ($statusOptions as $status => $label): ?>
              <option value="<?php echo adminEscape($status); ?>"<?php echo $filters['status'] === $status ? ' selected' : ''; ?>><?php echo adminEscape($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="owner">Owner</label>
          <select id="owner" name="owner">
            <option value="">All owners</option>
            <?php foreach ($ownerOptions as $owner): ?>
              <option value="<?php echo adminEscape($owner); ?>"<?php echo $filters['owner'] === $owner ? ' selected' : ''; ?>><?php echo adminEscape($owner); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="display:flex; align-items:end;">
          <button type="submit">Apply Filters</button>
        </div>
      </form>
    </section>

    <?php if ($flashSaved): ?>
      <div class="flash">Lead status saved.</div>
    <?php endif; ?>

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

    <?php if ($flashTrashSaved): ?>
      <div class="flash">Lead updated in trash.</div>
    <?php endif; ?>

    <?php if ($flashBulkSaved): ?>
      <div class="flash">Bulk action applied to <?php echo adminEscape((string) $bulkCount); ?> lead<?php echo $bulkCount === 1 ? '' : 's'; ?>.</div>
    <?php endif; ?>

    <?php if ($flashBulkError): ?>
      <div class="flash" style="background: rgba(162, 53, 53, 0.1); border-color: rgba(162, 53, 53, 0.18); color: #7d2424;">Select at least one lead and a valid bulk action.</div>
    <?php endif; ?>

    <?php if ($flashDeleted): ?>
      <div class="flash">Permanently deleted <?php echo adminEscape((string) $deletedCount); ?> lead<?php echo $deletedCount === 1 ? '' : 's'; ?>.</div>
    <?php endif; ?>

    <?php if ($flashTrashEmpty): ?>
      <div class="flash">Trash is already empty.</div>
    <?php endif; ?>

    <div class="results-bar">
      <div class="results-meta"><?php echo adminEscape((string) count($leads)); ?> lead<?php echo count($leads) === 1 ? '' : 's'; ?> shown in <?php echo $filters['view'] === 'trash' ? 'trash' : 'active leads'; ?>.</div>
      <?php if ($filters['view'] === 'trash'): ?>
        <form method="post" action="empty-trash.php" onsubmit="return confirm('Permanently delete every lead in trash? This cannot be undone.');" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?php echo adminEscape(adminCsrfToken()); ?>">
          <input type="hidden" name="return_query" value="<?php echo adminEscape($returnQuery !== '' ? $returnQuery : 'view=trash'); ?>">
          <button class="danger-button" type="submit">Empty Trash</button>
        </form>
      <?php endif; ?>
    </div>

    <?php if (!$leads): ?>
      <div class="empty">No leads match the current filters.</div>
    <?php else: ?>
      <form id="bulk-leads-form" class="card bulk-toolbar" method="post" action="bulk-leads.php">
        <input type="hidden" name="csrf_token" value="<?php echo adminEscape(adminCsrfToken()); ?>">
        <input type="hidden" name="return_query" value="<?php echo adminEscape($returnQuery); ?>">
        <div>
          <label class="bulk-select-all" for="select-all-leads">
            <input id="select-all-leads" type="checkbox" data-select-all>
            Select All
          </label>
          <div class="bulk-helper">Applies to the visible leads on this page.</div>
        </div>
        <div>
          <label for="bulk-action">Bulk Action</label>
          <select id="bulk-action" name="bulk_action">
            <option value="">Choose action</option>
            <option value="assign_owner">Assign Rep</option>
            <option value="clear_owner">Clear Rep</option>
            <option value="change_status">Change Status</option>
            <option value="trash">Move to Trash</option>
            <option value="restore">Restore from Trash</option>
            <?php if ($filters['view'] === 'trash'): ?>
              <option value="delete_permanently">Delete Permanently</option>
            <?php endif; ?>
          </select>
        </div>
        <div data-bulk-owner-wrap hidden>
          <label for="bulk-owner">Rep</label>
          <select id="bulk-owner" name="bulk_owner">
            <option value="">Choose rep</option>
            <?php foreach ($repList as $owner): ?>
              <option value="<?php echo adminEscape($owner); ?>"><?php echo adminEscape($owner); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div data-bulk-status-wrap hidden>
          <label for="bulk-status">Status</label>
          <select id="bulk-status" name="bulk_status">
            <option value="">Choose status</option>
            <?php foreach ($statusOptions as $status => $label): ?>
              <option value="<?php echo adminEscape($status); ?>"><?php echo adminEscape($label); ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="display:flex; align-items:end; justify-content:flex-end;">
          <button type="submit">Apply</button>
        </div>
      </form>

      <section class="lead-list">
        <?php foreach ($leads as $lead): ?>
          <?php $leadOwnerOptions = adminOwnerOptions($lead['owner']); ?>
          <?php $leadIsOpen = $openLeadKey === $lead['lead_key']; ?>
          <details class="card lead-card<?php echo $lead['is_trashed'] ? ' is-trashed' : ''; ?>" id="lead-<?php echo adminEscape($lead['lead_key']); ?>"<?php echo $leadIsOpen ? ' open' : ''; ?>>
            <summary>
              <div class="lead-summary-line">
                <div class="lead-select-cell">
                  <input
                    class="lead-select"
                    type="checkbox"
                    name="lead_keys[]"
                    value="<?php echo adminEscape($lead['lead_key']); ?>"
                    form="bulk-leads-form"
                    aria-label="Select <?php echo adminEscape($lead['name'] !== '' ? $lead['name'] : $lead['primary_contact']); ?>"
                  >
                </div>
                <div class="lead-summary-main">
                  <div class="lead-summary-name"><?php echo adminEscape($lead['name'] !== '' ? $lead['name'] : ($lead['email'] !== '' ? $lead['email'] : ($lead['phone'] !== '' ? $lead['phone'] : 'Unnamed lead'))); ?></div>
                  <div class="lead-summary-contact">
                    <?php echo adminEscape($lead['primary_contact']); ?>
                    <?php if ($lead['project_type'] !== '' || $lead['city'] !== ''): ?>
                      <?php echo ' | ' . adminEscape($lead['project_type'] !== '' ? $lead['project_type'] : 'Project not set'); ?>
                      <?php if ($lead['city'] !== ''): ?>
                        <?php echo ' | ' . adminEscape($lead['city']); ?>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                </div>
                <div class="lead-summary-meta">
                  <span class="badge"><?php echo adminEscape(adminLeadBadgeText($lead)); ?></span>
                  <span class="badge status-<?php echo adminEscape($lead['status']); ?>"><?php echo adminEscape($statusOptions[$lead['status']] ?? ucfirst($lead['status'])); ?></span>
                  <?php if ($lead['owner'] !== ''): ?>
                    <span class="badge">Owner: <?php echo adminEscape($lead['owner']); ?></span>
                  <?php endif; ?>
                  <?php if ($lead['is_trashed']): ?>
                    <span class="badge">In Trash</span>
                  <?php endif; ?>
                  <span class="lead-summary-time"><?php echo adminEscape(adminFormatTimestamp($lead['last_seen'])); ?></span>
                </div>
              </div>
            </summary>
            <div class="lead-card-panel">
              <div class="meta-row">
                <div class="meta-box"><span class="eyebrow">Email</span><?php echo $lead['email'] !== '' ? adminEscape($lead['email']) : 'Not provided'; ?></div>
                <div class="meta-box"><span class="eyebrow">Phone</span><?php echo $lead['phone'] !== '' ? adminEscape($lead['phone']) : 'Not provided'; ?></div>
                <div class="meta-box"><span class="eyebrow">Project</span><?php echo $lead['project_type'] !== '' ? adminEscape($lead['project_type']) : 'Not provided'; ?></div>
                <div class="meta-box"><span class="eyebrow">City</span><?php echo $lead['city'] !== '' ? adminEscape($lead['city']) : 'Not provided'; ?></div>
              </div>

              <div class="detail-grid">
                <div class="detail-box"><span class="eyebrow">Space / Area</span><?php echo $lead['space_type'] !== '' ? adminEscape($lead['space_type']) : 'Not provided'; ?></div>
                <div class="detail-box"><span class="eyebrow">Material Interest</span><?php echo $lead['material_interest'] !== '' ? adminEscape($lead['material_interest']) : 'Not provided'; ?></div>
                <div class="detail-box"><span class="eyebrow">Timeline</span><?php echo $lead['timeline'] !== '' ? adminEscape($lead['timeline']) : 'Not provided'; ?></div>
                <div class="detail-box"><span class="eyebrow">Source</span><?php echo $lead['source'] !== '' ? adminEscape($lead['source']) : 'Not provided'; ?></div>
              </div>

              <?php if ($lead['summary'] !== ''): ?>
                <div class="meta-box summary">
                  <span class="eyebrow">Lead Summary</span>
                  <?php echo adminEscape($lead['summary']); ?>
                </div>
              <?php endif; ?>

              <?php if ($lead['chat_transcript'] !== ''): ?>
                <details class="meta-box lead-subdetails">
                  <summary><?php echo !empty($lead['is_social_message']) ? 'Message Thread' : 'Full Chat Transcript'; ?></summary>
                  <div class="transcript-body"><?php echo adminEscape($lead['chat_transcript']); ?></div>
                </details>
              <?php endif; ?>

              <div class="detail-grid">
                <?php if ($lead['customer_type'] !== ''): ?>
                  <div class="detail-box"><span class="eyebrow">Customer Type</span><?php echo adminEscape($lead['customer_type']); ?></div>
                <?php endif; ?>
                <?php if ($lead['build_type'] !== ''): ?>
                  <div class="detail-box"><span class="eyebrow">Build Type</span><?php echo adminEscape($lead['build_type']); ?></div>
                <?php endif; ?>
                <?php if ($lead['measurements'] !== ''): ?>
                  <div class="detail-box"><span class="eyebrow">Measurements / Plans</span><?php echo adminEscape($lead['measurements']); ?></div>
                <?php endif; ?>
                <?php if ($lead['project_scope'] !== ''): ?>
                  <div class="detail-box"><span class="eyebrow">Project Scope</span><?php echo adminEscape($lead['project_scope']); ?></div>
                <?php endif; ?>
                <?php if ($lead['plans_ready'] !== ''): ?>
                  <div class="detail-box"><span class="eyebrow">Plans Ready</span><?php echo adminEscape($lead['plans_ready']); ?></div>
                <?php endif; ?>
                <?php if ($lead['pricing_or_scheduling'] !== ''): ?>
                  <div class="detail-box"><span class="eyebrow">Pricing / Scheduling</span><?php echo adminEscape($lead['pricing_or_scheduling']); ?></div>
                <?php endif; ?>
                <?php if ($lead['home_or_commercial'] !== ''): ?>
                  <div class="detail-box"><span class="eyebrow">Home or Commercial</span><?php echo adminEscape($lead['home_or_commercial']); ?></div>
                <?php endif; ?>
                <?php if (!empty($lead['attachment_names'])): ?>
                  <div class="detail-box"><span class="eyebrow">Attachments</span><?php echo adminEscape(implode(', ', $lead['attachment_names'])); ?></div>
                <?php endif; ?>
                <?php if ($lead['page_url'] !== ''): ?>
                  <div class="detail-box">
                    <span class="eyebrow">Page URL</span>
                    <a class="inline-link" href="<?php echo adminEscape($lead['page_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo adminEscape($lead['page_url']); ?></a>
                  </div>
                <?php endif; ?>
                <?php if ($lead['is_trashed']): ?>
                  <div class="detail-box">
                    <span class="eyebrow">Trash</span>
                    <?php echo adminEscape($lead['trashed_at'] !== '' ? adminFormatTimestamp($lead['trashed_at']) : 'In trash'); ?>
                    <?php if ($lead['trashed_by'] !== ''): ?>
                      <?php echo '<br>By ' . adminEscape($lead['trashed_by']); ?>
                    <?php endif; ?>
                  </div>
                <?php endif; ?>
              </div>

              <ul class="history">
                <?php foreach (array_slice($lead['entries'], 0, 4) as $entry): ?>
                  <li>
                    <strong><?php echo adminEscape($entry['entry_type'] === 'full' ? 'Full submission' : ($entry['entry_type'] === 'social' ? 'Incoming social message' : 'Partial capture')); ?></strong>
                    <?php echo adminEscape(adminFormatTimestamp($entry['timestamp'])); ?>
                    <?php if ($entry['entry_type'] === 'full' && $entry['mail_status'] !== ''): ?>
                      <span class="eyebrow" style="display:inline-block; margin:0 0 0 10px;">Mail: <?php echo adminEscape($entry['mail_status']); ?></span>
                    <?php endif; ?>
                  </li>
                <?php endforeach; ?>
              </ul>

              <form method="post" action="update-lead.php">
                <input type="hidden" name="csrf_token" value="<?php echo adminEscape(adminCsrfToken()); ?>">
                <input type="hidden" name="lead_key" value="<?php echo adminEscape($lead['lead_key']); ?>">
                <input type="hidden" name="return_query" value="<?php echo adminEscape($returnQuery); ?>">
                <div class="form-grid">
                  <div>
                    <label for="status-<?php echo adminEscape($lead['lead_key']); ?>">Status</label>
                    <select id="status-<?php echo adminEscape($lead['lead_key']); ?>" name="status">
                      <?php foreach ($statusOptions as $status => $label): ?>
                        <option value="<?php echo adminEscape($status); ?>"<?php echo $lead['status'] === $status ? ' selected' : ''; ?>><?php echo adminEscape($label); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div>
                    <label for="owner-<?php echo adminEscape($lead['lead_key']); ?>">Owner</label>
                    <select id="owner-<?php echo adminEscape($lead['lead_key']); ?>" name="owner">
                      <option value="Unassigned"<?php echo $lead['owner'] === '' ? ' selected' : ''; ?>>Unassigned</option>
                      <?php foreach ($leadOwnerOptions as $owner): ?>
                        <?php if ($owner === 'Unassigned') continue; ?>
                        <option value="<?php echo adminEscape($owner); ?>"<?php echo $lead['owner'] === $owner ? ' selected' : ''; ?>><?php echo adminEscape($owner); ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                </div>
                <div>
                  <label for="notes-<?php echo adminEscape($lead['lead_key']); ?>">Rep Notes</label>
                  <textarea id="notes-<?php echo adminEscape($lead['lead_key']); ?>" name="notes" placeholder="Add follow-up notes, pricing status, appointment details, or next step."><?php echo adminEscape($lead['notes']); ?></textarea>
                </div>
                <div class="form-grid">
                  <div class="meta-box">
                    <span class="eyebrow">Updated</span>
                    <?php echo $lead['updated_at'] !== '' ? adminEscape(adminFormatTimestamp($lead['updated_at'])) : 'Not yet updated'; ?>
                  </div>
                  <div class="meta-box">
                    <span class="eyebrow">Updated By</span>
                    <?php echo $lead['updated_by'] !== '' ? adminEscape($lead['updated_by']) : 'Not yet updated'; ?>
                  </div>
                </div>
                <div class="lead-actions">
                  <div>
                    <button type="submit">Save Lead</button>
                  </div>
                </div>
              </form>

              <div class="lead-actions">
                <div></div>
                <div class="action-cluster">
                  <form method="post" action="trash-lead.php">
                    <input type="hidden" name="csrf_token" value="<?php echo adminEscape(adminCsrfToken()); ?>">
                    <input type="hidden" name="lead_key" value="<?php echo adminEscape($lead['lead_key']); ?>">
                    <input type="hidden" name="return_query" value="<?php echo adminEscape($returnQuery); ?>">
                    <?php if ($lead['is_trashed']): ?>
                      <input type="hidden" name="action" value="restore">
                      <button class="ghost-button" type="submit">Restore Lead</button>
                    <?php else: ?>
                      <input type="hidden" name="action" value="trash">
                      <button class="danger-button" type="submit">Move to Trash</button>
                    <?php endif; ?>
                  </form>
                  <?php if ($lead['is_trashed']): ?>
                    <form method="post" action="delete-lead.php" onsubmit="return confirm('Permanently delete this lead? This cannot be undone.');">
                      <input type="hidden" name="csrf_token" value="<?php echo adminEscape(adminCsrfToken()); ?>">
                      <input type="hidden" name="lead_key" value="<?php echo adminEscape($lead['lead_key']); ?>">
                      <input type="hidden" name="return_query" value="<?php echo adminEscape($returnQuery !== '' ? $returnQuery : 'view=trash'); ?>">
                      <button class="danger-button" type="submit">Delete Permanently</button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </details>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </div>
  <script>
    (function () {
      var selectAll = document.querySelector('[data-select-all]');
      var leadCheckboxes = Array.prototype.slice.call(document.querySelectorAll('.lead-select'));
      var actionSelect = document.getElementById('bulk-action');
      var ownerWrap = document.querySelector('[data-bulk-owner-wrap]');
      var statusWrap = document.querySelector('[data-bulk-status-wrap]');

      function syncSelectAllState() {
        if (!selectAll) {
          return;
        }

        var checkedCount = leadCheckboxes.filter(function (checkbox) {
          return checkbox.checked;
        }).length;

        selectAll.checked = leadCheckboxes.length > 0 && checkedCount === leadCheckboxes.length;
        selectAll.indeterminate = checkedCount > 0 && checkedCount < leadCheckboxes.length;
      }

      function syncBulkFields() {
        if (!actionSelect) {
          return;
        }

        var action = actionSelect.value;

        if (ownerWrap) {
          ownerWrap.hidden = action !== 'assign_owner';
        }

        if (statusWrap) {
          statusWrap.hidden = action !== 'change_status';
        }
      }

      leadCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('click', function (event) {
          event.stopPropagation();
        });

        checkbox.addEventListener('keydown', function (event) {
          event.stopPropagation();
        });

        checkbox.addEventListener('change', syncSelectAllState);
      });

      if (selectAll) {
        selectAll.addEventListener('click', function (event) {
          event.stopPropagation();
        });

        selectAll.addEventListener('keydown', function (event) {
          event.stopPropagation();
        });

        selectAll.addEventListener('change', function () {
          leadCheckboxes.forEach(function (checkbox) {
            checkbox.checked = selectAll.checked;
          });
          syncSelectAllState();
        });
      }

      if (actionSelect) {
        actionSelect.addEventListener('change', syncBulkFields);
      }

      syncSelectAllState();
      syncBulkFields();
    }());
  </script>
</body>
</html>
