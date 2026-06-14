<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

adminSendNoIndexHeaders();
adminRequireLogin();

$scheduledWindow = adminScheduledReportWindow();
$defaultWindow = $scheduledWindow;

if ($defaultWindow === null) {
  $yesterday = adminNow()->modify('yesterday');
  $defaultWindow = [
    'start' => $yesterday->setTime(0, 0, 0),
    'end' => $yesterday->setTime(23, 59, 59),
    'key' => adminBuildReportKey($yesterday->setTime(0, 0, 0), $yesterday->setTime(23, 59, 59)),
    'label' => adminBuildReportLabel($yesterday->setTime(0, 0, 0), $yesterday->setTime(23, 59, 59)),
  ];
}

$customWindow = adminCustomReportWindow($_GET['start'] ?? '', $_GET['end'] ?? '');
$window = $customWindow ?: $defaultWindow;

$allLeads = adminBuildLeads();
$report = adminBuildReportData($allLeads, $window['start'], $window['end'], $window['label']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Lead Report | Olive Glass & Marble</title>
  <style>
    :root {
      --bg: #f6f0e6;
      --panel: #fffaf3;
      --line: #dbc9af;
      --text: #30281f;
      --muted: #6c5d4d;
      --accent: #155247;
    }

    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: "Avenir Next", "Segoe UI", sans-serif;
      background: var(--bg);
      color: var(--text);
      padding: 24px;
    }

    .shell {
      max-width: 1100px;
      margin: 0 auto;
    }

    .toolbar,
    .report-card {
      background: var(--panel);
      border: 1px solid var(--line);
      border-radius: 22px;
      padding: 20px;
      margin-bottom: 18px;
    }

    .toolbar form {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 220px)) auto auto;
      gap: 12px;
      align-items: end;
    }

    label {
      display: block;
      margin-bottom: 6px;
      font-size: 0.9rem;
      font-weight: 700;
    }

    input {
      width: 100%;
      padding: 12px 14px;
      border: 1px solid var(--line);
      border-radius: 14px;
      font: inherit;
    }

    .button-link,
    button {
      border: 0;
      border-radius: 999px;
      padding: 12px 18px;
      font: inherit;
      font-weight: 700;
      color: #fff;
      background: linear-gradient(135deg, #155247 0%, #0f3b33 100%);
      text-decoration: none;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .secondary {
      background: rgba(255,255,255,0.75);
      color: var(--text);
      border: 1px solid var(--line);
    }

    h1 {
      margin: 0 0 8px;
      font-size: 2.2rem;
    }

    .subhead {
      margin: 0 0 12px;
      color: var(--muted);
      line-height: 1.6;
    }

    .report-card h1,
    .report-card h2 {
      color: var(--text);
    }

    @media print {
      body {
        background: #fff;
        padding: 0;
      }

      .toolbar {
        display: none;
      }

      .report-card {
        border: 0;
        margin: 0;
        padding: 0;
      }
    }

    @media (max-width: 900px) {
      .toolbar form {
        grid-template-columns: 1fr;
      }
    }
  </style>
  <link rel="stylesheet" href="../ogm-accessibility.css?v=20260516c">
  <script src="../ogm-accessibility.js?v=20260516o" defer></script>
</head>
<body>
  <div class="shell">
    <section class="toolbar">
      <h1>Lead Report</h1>
      <p class="subhead">Print a daily report or load a custom date range.</p>
      <form method="get" action="report.php">
        <div>
          <label for="start">Start Date</label>
          <input id="start" type="date" name="start" value="<?php echo adminEscape($window['start']->format('Y-m-d')); ?>">
        </div>
        <div>
          <label for="end">End Date</label>
          <input id="end" type="date" name="end" value="<?php echo adminEscape($window['end']->format('Y-m-d')); ?>">
        </div>
        <div>
          <button type="submit">Load Report</button>
        </div>
        <div>
          <button class="secondary" type="button" onclick="window.print()">Print</button>
        </div>
      </form>
    </section>

    <section class="report-card">
      <?php echo adminRenderReportHtmlBody($report); ?>
    </section>
  </div>
</body>
</html>
