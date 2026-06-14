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
$reportHref = adminUrl('report.php');
if ($scheduledWindow) {
  $reportHref .= '?start=' . rawurlencode($scheduledWindow['start']->format('Y-m-d')) . '&end=' . rawurlencode($scheduledWindow['end']->format('Y-m-d'));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="ogm-leads-ui" content="2026-05-16">
  <base href="<?php echo adminEscape(adminWebBase()); ?>">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500&family=DM+Mono:wght@300;400;500&display=swap" rel="stylesheet">
  <title>Lead Dashboard | Olive Glass & Marble</title>
  <style>
:root{
  --cream:#faf8f3;--warm:#f5f2ea;
  --s50:#f7f5ef;--s100:#eae6da;--s200:#d4cfc0;
  --s300:#b8b09c;--s500:#7a7260;--s700:#4a4538;--s900:#1c1917;
  --gold:#9e7c3a;--gold-l:#c4a05a;--gold-p:#f0e6cc;
  --green:#16a34a;--red:#dc2626;
  --new:#d97706;--partial:#7c5c2d;--contacted:#1d6fa5;
  --quoted:#7745c6;--won:#18794e;--closed:#70757d;
}
*{box-sizing:border-box;margin:0;padding:0}
body{
  background:var(--cream);color:var(--s900);
  font-family:'DM Sans',sans-serif;font-weight:300;font-size:14px;
  line-height:1.5;min-height:100vh;
}

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

.shell{max-width:1360px;margin:0 auto;padding:24px 32px 60px}

.button-link,button{
  border:none;border-radius:2px;background:var(--s900);color:#fff;
  font:inherit;font-size:11px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;
  padding:9px 18px;cursor:pointer;text-decoration:none;
  display:inline-flex;align-items:center;justify-content:center;transition:background .15s;
}
.button-link:hover,button:hover{background:var(--s700)}
.secondary-link{background:transparent;color:var(--s700);border:1px solid var(--s200)}
.secondary-link:hover{background:var(--s50);color:var(--s900)}
.danger-button{background:var(--red)}
.danger-button:hover{background:#b91c1c}
.ghost-button{background:transparent;color:var(--s700);border:1px solid var(--s200)}
.ghost-button:hover{background:var(--s50);color:var(--s900)}

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

.flash{
  margin-bottom:16px;padding:12px 16px;border-radius:2px;
  background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.2);
  color:var(--green);font-size:13px;
}

.overview-grid{
  display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
  gap:12px;margin-bottom:20px;
}
.overview-card{padding:20px 24px}
.overview-card>summary{list-style:none;cursor:pointer}
.overview-card>summary::-webkit-details-marker{display:none}
.overview-value{
  display:block;margin-top:8px;
  font-family:'DM Mono',monospace;font-size:clamp(28px,4vw,36px);
  font-weight:400;color:var(--gold);line-height:1;
}
.overview-note{margin-top:10px;color:var(--s500);line-height:1.6;font-size:13px}
.overview-breakdown,.overview-metrics{display:grid;gap:10px;margin-top:14px}
.overview-metrics{grid-template-columns:repeat(auto-fit,minmax(100px,1fr))}
.overview-link{
  display:inline-flex;align-items:center;gap:6px;margin-top:14px;
  font-size:11px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;
  color:var(--gold);text-decoration:none;
}
.overview-link:hover{color:var(--gold-l)}

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

.filters,.card,.empty,.bulk-toolbar{
  background:#fff;border:1px solid var(--s100);border-radius:4px;
}
.filters{padding:18px 20px;margin-bottom:16px}
.filters form{
  display:grid;
  grid-template-columns:1.6fr repeat(2,minmax(170px,.7fr)) auto;
  gap:10px;margin-top:14px;
}
.view-switch{
  display:flex;gap:6px;flex-wrap:wrap;
  border-bottom:1px solid var(--s200);padding-bottom:0;
}
.view-tab{
  display:inline-flex;align-items:center;padding:8px 16px;
  font-size:11px;font-weight:500;letter-spacing:.1em;text-transform:uppercase;
  color:var(--s500);text-decoration:none;
  border-radius:2px 2px 0 0;border:1px solid transparent;border-bottom:none;
  background:transparent;transition:all .15s;
}
.view-tab:hover{color:var(--gold);background:var(--s50)}
.view-tab.is-active{
  color:var(--gold-l);background:var(--s50);
  border-color:var(--s200);border-bottom-color:#fff;
}

.results-meta{color:var(--s500);font-size:13px}
.results-bar{
  display:flex;justify-content:space-between;align-items:center;
  gap:12px;flex-wrap:wrap;margin-bottom:12px;
}
.lead-list{display:grid;gap:12px}
.card{padding:20px 24px}
.lead-card{padding:0;overflow:hidden}
.lead-card.is-trashed{opacity:.82}
.lead-card>summary{
  list-style:none;cursor:pointer;padding:16px 20px;
}
.lead-card>summary::-webkit-details-marker{display:none}
.lead-summary-line{
  display:grid;grid-template-columns:auto minmax(0,1.4fr) minmax(0,1fr);
  gap:14px;align-items:center;
}
.lead-select-cell{display:flex;align-items:center;justify-content:center}
.lead-select-cell input{width:16px;height:16px;margin:0;cursor:pointer}
.lead-summary-main{min-width:0}
.lead-summary-name{
  font-size:14px;font-weight:500;overflow-wrap:anywhere;color:var(--s900);
}
.lead-summary-contact{
  margin-top:4px;color:var(--s500);font-size:12px;overflow-wrap:anywhere;
}
.lead-summary-meta{
  display:flex;justify-content:flex-end;align-items:center;
  gap:8px;flex-wrap:wrap;min-width:0;
}
.lead-summary-time{color:var(--s300);font-size:11px;white-space:nowrap}
.lead-card-panel{
  padding:0 20px 20px;border-top:1px solid var(--s100);
}
.card-top{
  display:flex;justify-content:space-between;gap:16px;
  align-items:start;margin-bottom:14px;
}
.title-group{min-width:0}
.title-group h2{
  margin:0 0 6px;font-family:'Cormorant Garamond',serif;
  font-size:20px;font-weight:400;letter-spacing:.04em;
  overflow-wrap:anywhere;
}
.meta-row,.detail-grid{display:grid;gap:10px}
.meta-row{grid-template-columns:repeat(auto-fit,minmax(180px,1fr));margin-bottom:14px}
.detail-grid{grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin-bottom:14px}
.meta-box,.detail-box{
  min-width:0;padding:12px 14px;border-radius:2px;
  background:var(--s50);border:1px solid var(--s100);
  overflow-wrap:anywhere;word-break:break-word;font-size:13px;
}
.badge-row{display:flex;flex-wrap:wrap;gap:6px}
.badge{
  display:inline-flex;align-items:center;border-radius:2px;
  padding:4px 10px;font-size:10px;font-weight:500;
  letter-spacing:.08em;text-transform:uppercase;
  background:var(--gold-p);color:var(--s700);border:1px solid var(--s100);
}
.status-new{background:rgba(217,119,6,.1);color:var(--new);border-color:rgba(217,119,6,.2)}
.status-partial{background:rgba(124,92,45,.1);color:var(--partial);border-color:rgba(124,92,45,.2)}
.status-contacted{background:rgba(29,111,165,.1);color:var(--contacted);border-color:rgba(29,111,165,.2)}
.status-quoted{background:rgba(119,69,198,.1);color:var(--quoted);border-color:rgba(119,69,198,.2)}
.status-won{background:rgba(24,121,78,.1);color:var(--won);border-color:rgba(24,121,78,.2)}
.status-closed{background:rgba(112,117,125,.12);color:var(--closed);border-color:rgba(112,117,125,.2)}
.summary{
  margin-bottom:14px;line-height:1.6;white-space:pre-wrap;
  max-height:220px;overflow:auto;font-size:13px;color:var(--s700);
}
.lead-subdetails{margin-bottom:14px}
.lead-subdetails summary{cursor:pointer;font-weight:500;color:var(--gold);font-size:13px}
.transcript-body{
  margin-top:10px;white-space:pre-wrap;line-height:1.7;
  max-height:320px;overflow:auto;padding-right:6px;font-size:13px;color:var(--s700);
}
.history{
  margin:14px 0 0;padding:0;list-style:none;display:grid;gap:8px;
}
.history li{
  padding:10px 12px;border-radius:2px;
  background:var(--s50);border:1px solid var(--s100);font-size:13px;
}
.history strong{display:inline-block;margin-right:8px;font-weight:500}
.card form{
  display:grid;gap:10px;margin-top:14px;padding-top:14px;
  border-top:1px solid var(--s100);
}
.bulk-toolbar{
  display:grid;
  grid-template-columns:auto minmax(180px,220px) minmax(180px,220px) minmax(180px,220px) auto;
  gap:10px;align-items:end;margin-bottom:16px;padding:18px 20px;
}
.bulk-select-all{
  display:inline-flex;align-items:center;gap:8px;
  font-size:12px;font-weight:500;color:var(--s900);white-space:nowrap;
}
.bulk-select-all input{width:16px;height:16px;margin:0;cursor:pointer}
.bulk-helper{margin-top:4px;color:var(--s300);font-size:11px;line-height:1.5}
.lead-actions{
  display:flex;justify-content:space-between;gap:10px;
  align-items:center;flex-wrap:wrap;
}
.action-cluster{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.lead-actions form{margin:0;padding:0;border:0}
.empty{
  padding:40px 20px;text-align:center;color:var(--s500);font-size:13px;
}
a.inline-link{
  display:inline-block;max-width:100%;color:var(--gold);
  text-decoration:none;font-weight:500;overflow-wrap:anywhere;word-break:break-word;
}
a.inline-link:hover{color:var(--gold-l)}

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
  .overview-grid{grid-template-columns:1fr}
  .filters form{grid-template-columns:1fr}
  .view-switch{flex-direction:column;gap:4px;border-bottom:none}
  .view-tab{border-radius:2px;border:1px solid var(--s200);width:100%}
  .view-tab.is-active{border-bottom-color:var(--s200)}
  .bulk-toolbar{grid-template-columns:1fr}
  .results-bar{align-items:stretch}
  .card-top{flex-direction:column}
  .lead-summary-line{grid-template-columns:auto 1fr}
  .lead-summary-meta{justify-content:flex-start;grid-column:1/-1}
  .rep-row,.login-row{grid-template-columns:1fr}
}

@media print{
  .ogm-nav-bar,.page-header,.admin-menu{display:none!important}
  body{background:#fff}
  .shell{padding:0}
  .card,.filters,.overview-card,.bulk-toolbar{break-inside:avoid;box-shadow:none}
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
html[data-ogm-theme="dark"] .filters,
html[data-ogm-theme="dark"] .card,
html[data-ogm-theme="dark"] .empty,
html[data-ogm-theme="dark"] .bulk-toolbar,
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
html[data-ogm-theme="dark"] .overview-note,
html[data-ogm-theme="dark"] .results-meta,
html[data-ogm-theme="dark"] .lead-summary-contact,
html[data-ogm-theme="dark"] .field-hint,
html[data-ogm-theme="dark"] .bulk-helper,
html[data-ogm-theme="dark"] .lead-summary-time{color:var(--s500)}
html[data-ogm-theme="dark"] .mini-metric,
html[data-ogm-theme="dark"] .meta-box,
html[data-ogm-theme="dark"] .detail-box,
html[data-ogm-theme="dark"] .rep-row,
html[data-ogm-theme="dark"] .login-row,
html[data-ogm-theme="dark"] .history li{
  background:var(--s50);border-color:rgba(255,255,255,.08);
}
html[data-ogm-theme="dark"] .mini-metric strong,
html[data-ogm-theme="dark"] .lead-summary-name,
html[data-ogm-theme="dark"] .title-group h2,
html[data-ogm-theme="dark"] .bulk-select-all{color:#f1f5f9}
html[data-ogm-theme="dark"] .overview-value{color:var(--gold-l)}
html[data-ogm-theme="dark"] .overview-link,
html[data-ogm-theme="dark"] a.inline-link,
html[data-ogm-theme="dark"] .lead-subdetails summary{color:var(--gold-l)}
html[data-ogm-theme="dark"] .summary,
html[data-ogm-theme="dark"] .transcript-body{color:#cbd5e1}
html[data-ogm-theme="dark"] .view-switch{border-bottom-color:rgba(255,255,255,.08)}
html[data-ogm-theme="dark"] .view-tab{color:var(--s500)}
html[data-ogm-theme="dark"] .view-tab:hover{color:var(--gold-l);background:var(--s50)}
html[data-ogm-theme="dark"] .view-tab.is-active{
  color:var(--gold-l);background:var(--s50);
  border-color:rgba(255,255,255,.08);border-bottom-color:#1e293b;
}
html[data-ogm-theme="dark"] .lead-card-panel{border-top-color:rgba(255,255,255,.08)}
html[data-ogm-theme="dark"] .card form{border-top-color:rgba(255,255,255,.08)}
html[data-ogm-theme="dark"] .section-divider{border-top-color:rgba(255,255,255,.08)}
html[data-ogm-theme="dark"] .badge{
  background:var(--gold-p);color:#d4c4a0;border-color:rgba(255,255,255,.08);
}
html[data-ogm-theme="dark"] .status-new{background:rgba(217,119,6,.2);color:#fbbf24;border-color:rgba(251,191,36,.35)}
html[data-ogm-theme="dark"] .status-partial{background:rgba(196,160,90,.15);color:#d4b87a;border-color:rgba(196,160,90,.3)}
html[data-ogm-theme="dark"] .status-contacted{background:rgba(56,189,248,.15);color:#7dd3fc;border-color:rgba(56,189,248,.3)}
html[data-ogm-theme="dark"] .status-quoted{background:rgba(167,139,250,.15);color:#c4b5fd;border-color:rgba(167,139,250,.3)}
html[data-ogm-theme="dark"] .status-won{background:rgba(52,211,153,.15);color:#6ee7b7;border-color:rgba(52,211,153,.3)}
html[data-ogm-theme="dark"] .status-closed{background:rgba(148,163,184,.15);color:#cbd5e1;border-color:rgba(148,163,184,.25)}
html[data-ogm-theme="dark"] .secondary-link,
html[data-ogm-theme="dark"] .ghost-button{
  color:var(--s500);border-color:rgba(255,255,255,.12);
}
html[data-ogm-theme="dark"] .secondary-link:hover,
html[data-ogm-theme="dark"] .ghost-button:hover{
  background:var(--s50);color:#f1f5f9;
}
html[data-ogm-theme="dark"] .checkbox-row{color:var(--s500)}
html[data-ogm-theme="dark"] .flash{
  background:rgba(22,163,74,.12);border-color:rgba(22,163,74,.35);color:#86efac;
}
  </style>
  <script>try{if(localStorage.getItem('ogm-theme')==='dark')document.documentElement.setAttribute('data-ogm-theme','dark');}catch(e){}</script>
  <link rel="stylesheet" href="../ogm-theme-toggle.css?v=20260516p">
  <script src="../ogm-theme-toggle.js?v=20260516o" defer></script>
  <link rel="stylesheet" href="../ogm-accessibility.css?v=20260516c">
  <script src="../ogm-accessibility.js?v=20260516o" defer></script>
</head>
<body>

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

<div class="page-header">
  <div class="ph-title">Lead Dashboard</div>
  <div class="ph-right">
    <span class="ph-user"><?php echo adminEscape((string) ($currentUser['display_name'] ?? $currentUser['username'] ?? '')); ?></span>
    <a class="button-link secondary-link" href="<?php echo adminEscape($reportHref); ?>" target="_blank" rel="noopener noreferrer">Print Report</a>
    <a class="button-link" href="logout.php" style="background:transparent;border:1px solid rgba(255,255,255,.2);color:rgba(255,255,255,.6)">Log out</a>
  </div>
</div>

<div class="shell">

    <nav class="admin-menu" aria-label="Dashboard menu">
      <a class="menu-link is-active" href="index.php">Dashboard</a>
      <a class="menu-link" href="analytics.php">Analytics</a>
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
