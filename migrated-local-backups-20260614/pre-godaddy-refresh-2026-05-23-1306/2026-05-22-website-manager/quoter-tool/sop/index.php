<?php
require_once __DIR__ . DIRECTORY_SEPARATOR . 'lib.php';

sopSendNoIndexHeaders();
sopStartSession();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  sopRequireLogin();

  $departmentId = trim((string) ($_POST['department_id'] ?? ''));
  $pageId = trim((string) ($_POST['page_id'] ?? ''));
  $editTarget = '';

  if (!sopVerifyCsrfToken($_POST['csrf_token'] ?? '')) {
    sopSetFlash('error', 'The security token expired. Please try again.');
    header('Location: ' . sopBuildPortalUrl($departmentId, $pageId, $editTarget));
    exit;
  }

  $action = trim((string) ($_POST['action'] ?? ''));

  try {
    if ($action === 'add_department') {
      sopRequireManager();
      sopHistoryPushUndo(sopHistorySnapshot($departmentId, $pageId));
      $departmentId = sopAddDepartment($_POST['title'] ?? '', $_POST['summary'] ?? '');
      $pageId = '';
      $editTarget = 'department';
      sopSetFlash('success', 'Department tab added.');
    } elseif ($action === 'quick_add_department') {
      sopRequireManager();
      sopHistoryPushUndo(sopHistorySnapshot($departmentId, $pageId));
      $departmentId = sopCreateDepartmentDraft();
      $pageId = '';
      $editTarget = 'department';
      sopSetFlash('success', 'Department tab added.');
    } elseif ($action === 'update_department') {
      sopRequireManager();
      sopHistoryPushUndo(sopHistorySnapshot($departmentId, $pageId));
      sopUpdateDepartment($departmentId, $_POST['title'] ?? '', $_POST['summary'] ?? '');
      sopSetFlash('success', 'Top tab updated.');
    } elseif ($action === 'delete_department') {
      sopRequireManager();
      sopHistoryPushUndo(sopHistorySnapshot($departmentId, $pageId));
      $selection = sopDeleteDepartment($departmentId);
      $departmentId = (string) ($selection['department_id'] ?? '');
      $pageId = (string) ($selection['page_id'] ?? '');
      sopSetFlash('success', 'Top tab deleted.');
    } elseif ($action === 'add_page') {
      sopRequireManager();
      sopHistoryPushUndo(sopHistorySnapshot($departmentId, $pageId));
      $pageId = sopAddPage($departmentId, $_POST['title'] ?? '', $_POST['summary'] ?? '', $_POST['doc_url'] ?? '');
      $editTarget = 'page';
      sopSetFlash('success', 'Side tab added.');
    } elseif ($action === 'quick_add_page') {
      sopRequireManager();
      sopHistoryPushUndo(sopHistorySnapshot($departmentId, $pageId));
      $pageId = sopCreatePageDraft($departmentId);
      $editTarget = 'page';
      sopSetFlash('success', 'Side tab added.');
    } elseif ($action === 'update_page_resources') {
      sopRequireManager();
      sopHistoryPushUndo(sopHistorySnapshot($departmentId, $pageId));
      sopUpdatePageLinks($departmentId, $pageId, $_POST['doc_url'] ?? '', $_POST['sheet_url'] ?? '');
      sopSetFlash('success', 'Page links saved.');
    } elseif ($action === 'connect_page_doc') {
      sopRequireManager();
      sopHistoryPushUndo(sopHistorySnapshot($departmentId, $pageId));
      sopUpdatePageDocUrl($departmentId, $pageId, $_POST['doc_url'] ?? '');
      sopSetFlash('success', 'Google Docs link connected to the selected page.');
    } elseif ($action === 'update_page') {
      sopRequireManager();
      sopHistoryPushUndo(sopHistorySnapshot($departmentId, $pageId));
      sopUpdatePage($departmentId, $pageId, $_POST['title'] ?? '', $_POST['summary'] ?? '', $_POST['doc_url'] ?? '');
      sopSetFlash('success', 'Selected side tab updated.');
    } elseif ($action === 'delete_page') {
      sopRequireManager();
      sopHistoryPushUndo(sopHistorySnapshot($departmentId, $pageId));
      $selection = sopDeletePage($departmentId, $pageId);
      $departmentId = (string) ($selection['department_id'] ?? '');
      $pageId = (string) ($selection['page_id'] ?? '');
      sopSetFlash('success', 'Side tab deleted.');
    } elseif ($action === 'upsert_user') {
      sopRequireManager();
      sopHistoryPushUndo(sopHistorySnapshot($departmentId, $pageId));
      sopUpsertUser($_POST['username'] ?? '', $_POST['display_name'] ?? '', $_POST['role'] ?? '', $_POST['password'] ?? '');
      sopSetFlash('success', 'User access saved.');
    } elseif ($action === 'delete_user') {
      sopRequireManager();
      sopHistoryPushUndo(sopHistorySnapshot($departmentId, $pageId));
      sopDeleteUser($_POST['username'] ?? '');
      sopSetFlash('success', 'User deleted.');
    } elseif ($action === 'undo_change') {
      sopRequireManager();
      $selection = sopUndoHistory($departmentId, $pageId);
      $departmentId = (string) ($selection['department_id'] ?? '');
      $pageId = (string) ($selection['page_id'] ?? '');
      sopSetFlash('success', 'Undid the last manager change.');
    } elseif ($action === 'redo_change') {
      sopRequireManager();
      $selection = sopRedoHistory($departmentId, $pageId);
      $departmentId = (string) ($selection['department_id'] ?? '');
      $pageId = (string) ($selection['page_id'] ?? '');
      sopSetFlash('success', 'Redid the last manager change.');
    } else {
      sopSetFlash('error', 'Unknown action.');
    }
  } catch (RuntimeException $exception) {
    sopSetFlash('error', $exception->getMessage());
  }

  $data = sopReadData();
  [$resolvedDepartment] = sopResolveDepartment($data, $departmentId);
  $departmentId = (string) ($resolvedDepartment['id'] ?? '');
  [$resolvedPage] = sopResolvePage($resolvedDepartment ?? [], $pageId);
  $pageId = (string) ($resolvedPage['id'] ?? '');

  header('Location: ' . sopBuildPortalUrl($departmentId, $pageId, $editTarget));
  exit;
}

if (!sopIsLoggedIn()) {
  header('Location: ../index.php?next=' . rawurlencode('/quoter-tool/sop/'));
  exit;
}

$data = sopReadData();
[$currentDepartment, $currentDepartmentIndex] = sopResolveDepartment($data, $_GET['department'] ?? '');
[$currentPage, $currentPageIndex] = sopResolvePage($currentDepartment ?? [], $_GET['page'] ?? '');
$currentUser = sopCurrentUser();
$isManager = sopIsManager();
$flash = sopConsumeFlash();
$departments = is_array($data['departments'] ?? null) ? $data['departments'] : [];
$users = $isManager ? sopReadUsers() : [];
$pageSummary = trim((string) ($currentPage['summary'] ?? ''));
$pageSummary = $pageSummary !== '' ? $pageSummary : 'Paste a Google Doc or Excel workbook link and this SOP step will stay synced here automatically.';
$embedUrl = sopGooglePreviewUrl($currentPage['doc_url'] ?? '');
$editUrl = sopGoogleEditUrl($currentPage['doc_url'] ?? '');
$sheetEmbedUrl = sopExcelEmbedUrl($currentPage['sheet_url'] ?? '');
$sheetOpenUrl = sopExcelOpenUrl($currentPage['sheet_url'] ?? '');
$pageColor = trim((string) ($currentPage['color'] ?? '')) ?: sopColorAt($currentPageIndex >= 0 ? $currentPageIndex : 0);
$departmentColor = trim((string) ($currentDepartment['color'] ?? '')) ?: sopColorAt($currentDepartmentIndex >= 0 ? $currentDepartmentIndex : 0);
$currentDepartmentTitle = (string) ($currentDepartment['title'] ?? 'Department');
$currentPageTitle = (string) ($currentPage['title'] ?? 'Overview');
$currentDepartmentSummary = (string) ($currentDepartment['summary'] ?? '');
$currentPageDocUrl = (string) ($currentPage['doc_url'] ?? '');
$currentPageSheetUrl = (string) ($currentPage['sheet_url'] ?? '');
$hasEmbeddedResource = ($embedUrl !== '' || $sheetEmbedUrl !== '');
$hasSavedPageLinks = ($currentPageDocUrl !== '' || $currentPageSheetUrl !== '');
$autoEditTarget = trim((string) ($_GET['edit'] ?? ''));
$canUndo = $isManager && sopHistoryCanUndo();
$canRedo = $isManager && sopHistoryCanRedo();
$styleVersion = (string) (@filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'styles.css') ?: time());
$scriptVersion = (string) (@filemtime(__DIR__ . DIRECTORY_SEPARATOR . 'app.js') ?: time());
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?php echo sopEscape($data['portal_title'] ?? 'OGM SOP & Document Handler'); ?></title>
  <link rel="stylesheet" href="styles.css?v=<?php echo sopEscape($styleVersion); ?>">
  <script src="app.js?v=<?php echo sopEscape($scriptVersion); ?>" defer></script>
</head>
<body class="portal-shell">
  <div class="screen-wash"></div>
  <nav class="sop-ogm-nav" aria-label="OGM tools">
    <a href="../hub.php">⌂ Hub</a>
    <a href="../index.php">✦ Stone</a>
    <a href="../glass-quoter.php">◈ Glass</a>
    <a href="../shower-builder.php">🚿 Shower</a>
    <a href="../customer-db.php">◎ Customers</a>
    <a href="../job-tracking.php">◈ Jobs</a>
    <a href="../invoice-manager.php">◻ Invoices</a>
    <a href="../sales-reports.php">◑ Reports</a>
    <a class="is-active" href="index.php">▣ SOP</a>
  </nav>
  <header class="portal-topbar">
    <div class="portal-topbar__copy">
      <p class="eyebrow">OGM Internal Knowledge Base</p>
      <h1>SOP &amp; Document Handler</h1>
      <p class="lede">Paste Google Docs and Excel links once, and the live SOP stays updated here without rewriting page code.</p>
    </div>
    <div class="portal-topbar__actions">
      <div class="user-pill">
        <span class="user-pill__role"><?php echo sopEscape(ucfirst((string) ($currentUser['role'] ?? 'employee'))); ?></span>
        <strong><?php echo sopEscape((string) ($currentUser['display_name'] ?? 'Team Member')); ?></strong>
      </div>
      <a class="button button--ghost" href="../logout.php">Log Out</a>
    </div>
  </header>

  <?php if (is_array($flash) && !empty($flash['message'])): ?>
    <div class="flash flash--<?php echo sopEscape((string) ($flash['type'] ?? 'error')); ?>">
      <?php echo sopEscape((string) $flash['message']); ?>
    </div>
  <?php endif; ?>

  <?php if ($isManager): ?>
    <form id="quick-add-department-form" method="post" action="index.php" hidden>
      <input type="hidden" name="action" value="quick_add_department">
      <input type="hidden" name="csrf_token" value="<?php echo sopEscape(sopCsrfToken()); ?>">
      <input type="hidden" name="department_id" value="<?php echo sopEscape((string) ($currentDepartment['id'] ?? '')); ?>">
      <input type="hidden" name="page_id" value="<?php echo sopEscape((string) ($currentPage['id'] ?? '')); ?>">
    </form>

    <form id="quick-add-page-form" method="post" action="index.php" hidden>
      <input type="hidden" name="action" value="quick_add_page">
      <input type="hidden" name="csrf_token" value="<?php echo sopEscape(sopCsrfToken()); ?>">
      <input type="hidden" name="department_id" value="<?php echo sopEscape((string) ($currentDepartment['id'] ?? '')); ?>">
      <input type="hidden" name="page_id" value="<?php echo sopEscape((string) ($currentPage['id'] ?? '')); ?>">
    </form>

    <form id="undo-history-form" method="post" action="index.php" hidden>
      <input type="hidden" name="action" value="undo_change">
      <input type="hidden" name="csrf_token" value="<?php echo sopEscape(sopCsrfToken()); ?>">
      <input type="hidden" name="department_id" value="<?php echo sopEscape((string) ($currentDepartment['id'] ?? '')); ?>">
      <input type="hidden" name="page_id" value="<?php echo sopEscape((string) ($currentPage['id'] ?? '')); ?>">
    </form>

    <form id="redo-history-form" method="post" action="index.php" hidden>
      <input type="hidden" name="action" value="redo_change">
      <input type="hidden" name="csrf_token" value="<?php echo sopEscape(sopCsrfToken()); ?>">
      <input type="hidden" name="department_id" value="<?php echo sopEscape((string) ($currentDepartment['id'] ?? '')); ?>">
      <input type="hidden" name="page_id" value="<?php echo sopEscape((string) ($currentPage['id'] ?? '')); ?>">
    </form>

    <div class="history-dock" data-history-dock>
      <button
        class="history-dock__button"
        type="submit"
        form="undo-history-form"
        title="Undo (Cmd/Ctrl+Z)"
        aria-label="Undo"
        data-history-trigger="undo"
        <?php echo $canUndo ? '' : 'disabled'; ?>
      >
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M9 7L4 12L9 17" />
          <path d="M5 12H14C17.3 12 20 14.7 20 18" />
        </svg>
      </button>
      <button
        class="history-dock__button"
        type="submit"
        form="redo-history-form"
        title="Redo (Cmd/Ctrl+Shift+Z)"
        aria-label="Redo"
        data-history-trigger="redo"
        <?php echo $canRedo ? '' : 'disabled'; ?>
      >
        <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
          <path d="M15 7L20 12L15 17" />
          <path d="M19 12H10C6.7 12 4 14.7 4 18" />
        </svg>
      </button>
    </div>
  <?php endif; ?>

  <main class="portal-grid">
    <nav class="cabinet-tabs" data-cabinet-tabs>
    <?php foreach ($departments as $department): ?>
      <?php
        [$departmentPage] = sopResolvePage($department, '');
        $tabUrl = sopBuildPortalUrl((string) ($department['id'] ?? ''), (string) ($departmentPage['id'] ?? ''));
        $isDepartmentActive = (($department['id'] ?? '') === ($currentDepartment['id'] ?? ''));
        $departmentEditPageId = $isDepartmentActive ? (string) ($currentPage['id'] ?? '') : (string) ($departmentPage['id'] ?? '');
      ?>
      <div class="cabinet-tab-wrap<?php echo $isDepartmentActive ? ' cabinet-tab-wrap--active' : ''; ?><?php echo $isManager ? ' cabinet-tab-wrap--manager' : ''; ?>" data-inline-editor<?php echo ($isDepartmentActive && $autoEditTarget === 'department') ? ' data-auto-edit="true"' : ''; ?>>
        <a
          class="cabinet-tab<?php echo $isDepartmentActive ? ' cabinet-tab--active' : ''; ?>"
          href="<?php echo sopEscape($tabUrl); ?>"
          style="--tab-color: <?php echo sopEscape((string) ($department['color'] ?? sopColorAt(0))); ?>;"
          data-inline-view
        >
          <span class="cabinet-tab__kicker">Department</span>
          <strong class="cabinet-tab__title"><?php echo sopEscape((string) ($department['title'] ?? 'Department')); ?></strong>
        </a>

        <?php if ($isManager): ?>
          <form
            class="cabinet-tab cabinet-tab--edit<?php echo $isDepartmentActive ? ' cabinet-tab--active' : ''; ?>"
            method="post"
            action="index.php"
            style="--tab-color: <?php echo sopEscape((string) ($department['color'] ?? sopColorAt(0))); ?>;"
            data-inline-form
          >
            <input type="hidden" name="action" value="update_department">
            <input type="hidden" name="csrf_token" value="<?php echo sopEscape(sopCsrfToken()); ?>">
            <input type="hidden" name="department_id" value="<?php echo sopEscape((string) ($department['id'] ?? '')); ?>">
            <input type="hidden" name="page_id" value="<?php echo sopEscape($departmentEditPageId); ?>">
            <input type="hidden" name="summary" value="<?php echo sopEscape((string) ($department['summary'] ?? '')); ?>">

            <span class="cabinet-tab__kicker">Department</span>
            <label class="cabinet-tab__edit-label">
              <span class="sr-only">Department title</span>
              <input class="cabinet-tab__input" name="title" type="text" value="<?php echo sopEscape((string) ($department['title'] ?? 'Department')); ?>" data-inline-primary required>
            </label>
            <div class="cabinet-tab__edit-actions">
              <button
                class="tab-delete-button"
                type="submit"
                form="delete-department-<?php echo sopEscape((string) ($department['id'] ?? '')); ?>"
                onclick="return confirm('Delete this top tab?');"
              >Delete</button>
            </div>
          </form>

          <form id="delete-department-<?php echo sopEscape((string) ($department['id'] ?? '')); ?>" method="post" action="index.php" hidden>
            <input type="hidden" name="action" value="delete_department">
            <input type="hidden" name="csrf_token" value="<?php echo sopEscape(sopCsrfToken()); ?>">
            <input type="hidden" name="department_id" value="<?php echo sopEscape((string) ($department['id'] ?? '')); ?>">
            <input type="hidden" name="page_id" value="<?php echo sopEscape($departmentEditPageId); ?>">
          </form>

          <button
            class="tab-lock-toggle"
            type="button"
            data-inline-toggle
            data-lock-state="locked"
            aria-label="Click the lock to make changes."
            title="Click the lock to make changes."
            data-locked-label="Click the lock to make changes."
            data-unlocked-label="Click the lock to prevent further changes."
          >
            <span class="tab-lock-toggle__icon tab-lock-toggle__icon--locked" aria-hidden="true">
              <svg class="lock-illustration" viewBox="0 0 24 24" focusable="false">
                <path class="lock-illustration__shackle" d="M7 10V7.7C7 4.8 9.2 2.5 12 2.5C14.8 2.5 17 4.8 17 7.7V10" />
                <rect class="lock-illustration__body" x="5.2" y="10" width="13.6" height="11.2" rx="2.2" />
                <path class="lock-illustration__detail" d="M8 13.4H16M8 16.3H16M8 19.2H16" />
              </svg>
            </span>
            <span class="tab-lock-toggle__icon tab-lock-toggle__icon--unlocked" aria-hidden="true">
              <svg class="lock-illustration" viewBox="0 0 24 24" focusable="false">
                <path class="lock-illustration__shackle" d="M16 10V7.6C16 4.8 13.9 2.5 11.3 2.5C8.7 2.5 6.6 4.8 6.6 7.6" />
                <path class="lock-illustration__shackle lock-illustration__shackle--open" d="M6.6 7.6V10" />
                <rect class="lock-illustration__body" x="5.2" y="10" width="13.6" height="11.2" rx="2.2" />
                <path class="lock-illustration__detail" d="M8 13.4H16M8 16.3H16M8 19.2H16" />
              </svg>
            </span>
          </button>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if ($isManager): ?>
      <button class="cabinet-tab cabinet-tab--add" type="submit" form="quick-add-department-form" style="--tab-color: #7f8c8d;">
        <span class="cabinet-tab__kicker">Add</span>
        <strong class="cabinet-tab__title">+</strong>
      </button>
    <?php endif; ?>
    </nav>

    <aside class="step-rail">
      <div class="step-rail__header">
        <p class="eyebrow" style="--eyebrow-color: <?php echo sopEscape($departmentColor); ?>;">Department</p>
        <h2><?php echo sopEscape((string) ($currentDepartment['title'] ?? 'Department')); ?></h2>
        <p><?php echo sopEscape((string) ($currentDepartment['summary'] ?? '')); ?></p>
      </div>

      <?php foreach ((array) ($currentDepartment['pages'] ?? []) as $index => $page): ?>
        <?php
          $stepUrl = sopBuildPortalUrl((string) ($currentDepartment['id'] ?? ''), (string) ($page['id'] ?? ''));
          $isPageActive = (($page['id'] ?? '') === ($currentPage['id'] ?? ''));
          $stepColor = trim((string) ($page['color'] ?? '')) ?: sopColorAt($index);
        ?>
        <div class="step-card-wrap<?php echo $isPageActive ? ' step-card-wrap--active' : ''; ?><?php echo $isManager ? ' step-card-wrap--manager' : ''; ?>" data-inline-editor<?php echo ($isPageActive && $autoEditTarget === 'page') ? ' data-auto-edit="true"' : ''; ?>>
          <a
            class="step-card<?php echo $isPageActive ? ' step-card--active' : ''; ?>"
            href="<?php echo sopEscape($stepUrl); ?>"
            style="--step-color: <?php echo sopEscape($stepColor); ?>;"
            data-inline-view
          >
            <span class="step-card__copy">
              <span class="step-card__kicker">Section</span>
              <strong class="step-card__title"><?php echo sopEscape((string) ($page['title'] ?? 'Untitled')); ?></strong>
              <span class="step-card__summary"><?php echo sopEscape((string) ($page['summary'] ?? '')); ?></span>
            </span>
            <span class="step-card__badge">
              <span class="step-card__badge-label">Step</span>
              <strong><?php echo sopEscape(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)); ?></strong>
            </span>
          </a>

          <?php if ($isManager): ?>
            <form
              class="step-card step-card--edit<?php echo $isPageActive ? ' step-card--active' : ''; ?>"
              method="post"
              action="index.php"
              style="--step-color: <?php echo sopEscape($stepColor); ?>;"
              data-inline-form
            >
              <input type="hidden" name="action" value="update_page">
              <input type="hidden" name="csrf_token" value="<?php echo sopEscape(sopCsrfToken()); ?>">
              <input type="hidden" name="department_id" value="<?php echo sopEscape((string) ($currentDepartment['id'] ?? '')); ?>">
              <input type="hidden" name="page_id" value="<?php echo sopEscape((string) ($page['id'] ?? '')); ?>">
              <input type="hidden" name="doc_url" value="<?php echo sopEscape((string) ($page['doc_url'] ?? '')); ?>">

              <span class="step-card__copy">
                <span class="step-card__kicker">Section</span>
                <label class="step-card__edit-label">
                  <span class="sr-only">Side tab title</span>
                  <input class="step-card__input" name="title" type="text" value="<?php echo sopEscape((string) ($page['title'] ?? 'Untitled')); ?>" data-inline-primary required>
                </label>
                <label class="step-card__summary-edit">
                  <span class="sr-only">Side tab summary</span>
                  <textarea name="summary" rows="3"><?php echo sopEscape((string) ($page['summary'] ?? '')); ?></textarea>
                </label>
                <div class="step-card__edit-actions">
                  <button
                    class="tab-delete-button tab-delete-button--step"
                    type="submit"
                    form="delete-page-<?php echo sopEscape((string) ($currentDepartment['id'] ?? '')); ?>-<?php echo sopEscape((string) ($page['id'] ?? '')); ?>"
                    onclick="return confirm('Delete this side tab?');"
                  >Delete</button>
                </div>
              </span>
              <span class="step-card__badge">
                <span class="step-card__badge-label">Step</span>
                <strong><?php echo sopEscape(str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT)); ?></strong>
              </span>
            </form>

            <form id="delete-page-<?php echo sopEscape((string) ($currentDepartment['id'] ?? '')); ?>-<?php echo sopEscape((string) ($page['id'] ?? '')); ?>" method="post" action="index.php" hidden>
              <input type="hidden" name="action" value="delete_page">
              <input type="hidden" name="csrf_token" value="<?php echo sopEscape(sopCsrfToken()); ?>">
              <input type="hidden" name="department_id" value="<?php echo sopEscape((string) ($currentDepartment['id'] ?? '')); ?>">
              <input type="hidden" name="page_id" value="<?php echo sopEscape((string) ($page['id'] ?? '')); ?>">
            </form>

            <button
              class="tab-lock-toggle tab-lock-toggle--step"
              type="button"
              data-inline-toggle
              data-lock-state="locked"
              aria-label="Click the lock to make changes."
              title="Click the lock to make changes."
              data-locked-label="Click the lock to make changes."
              data-unlocked-label="Click the lock to prevent further changes."
            >
              <span class="tab-lock-toggle__icon tab-lock-toggle__icon--locked" aria-hidden="true">
                <svg class="lock-illustration" viewBox="0 0 24 24" focusable="false">
                  <path class="lock-illustration__shackle" d="M7 10V7.7C7 4.8 9.2 2.5 12 2.5C14.8 2.5 17 4.8 17 7.7V10" />
                  <rect class="lock-illustration__body" x="5.2" y="10" width="13.6" height="11.2" rx="2.2" />
                  <path class="lock-illustration__detail" d="M8 13.4H16M8 16.3H16M8 19.2H16" />
                </svg>
              </span>
              <span class="tab-lock-toggle__icon tab-lock-toggle__icon--unlocked" aria-hidden="true">
                <svg class="lock-illustration" viewBox="0 0 24 24" focusable="false">
                  <path class="lock-illustration__shackle" d="M16 10V7.6C16 4.8 13.9 2.5 11.3 2.5C8.7 2.5 6.6 4.8 6.6 7.6" />
                  <path class="lock-illustration__shackle lock-illustration__shackle--open" d="M6.6 7.6V10" />
                  <rect class="lock-illustration__body" x="5.2" y="10" width="13.6" height="11.2" rx="2.2" />
                  <path class="lock-illustration__detail" d="M8 13.4H16M8 16.3H16M8 19.2H16" />
                </svg>
              </span>
            </button>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php if ($isManager): ?>
        <button class="step-card step-card--add" type="submit" form="quick-add-page-form" style="--step-color: #7f8c8d;">
          <span class="step-card__copy">
            <span class="step-card__kicker">Add</span>
            <strong class="step-card__title">New Side Tab</strong>
            <span class="step-card__summary">Create another SOP page inside this department.</span>
          </span>
          <span class="step-card__badge">
            <span class="step-card__badge-label">Add</span>
            <strong>+</strong>
          </span>
        </button>
      <?php endif; ?>
    </aside>

    <section class="workspace-panel">
      <div class="workspace-panel__header">
        <div class="workspace-panel__intro">
          <p class="eyebrow" style="--eyebrow-color: <?php echo sopEscape($pageColor); ?>;">SOP Page</p>
          <h2><?php echo sopEscape((string) ($currentPage['title'] ?? 'Untitled')); ?></h2>
          <p><?php echo sopEscape($pageSummary); ?></p>
        </div>

        <div class="workspace-panel__meta">
          <span class="meta-pill">Updated <?php echo sopEscape(sopFormatTimestamp((string) ($currentPage['updated_at'] ?? ''))); ?></span>
          <?php if ($isManager && $editUrl !== ''): ?>
            <a class="button button--secondary" href="<?php echo sopEscape($editUrl); ?>" target="_blank" rel="noopener noreferrer">Open Google Doc</a>
          <?php endif; ?>
          <?php if ($isManager && $sheetOpenUrl !== ''): ?>
            <a class="button button--secondary" href="<?php echo sopEscape($sheetOpenUrl); ?>" target="_blank" rel="noopener noreferrer">Open Spreadsheet</a>
          <?php endif; ?>
        </div>
      </div>

      <div class="target-map">
        <div class="target-map__item">
          <span class="target-map__label">Top Tab</span>
          <strong class="target-map__value"><?php echo sopEscape($currentDepartmentTitle); ?></strong>
        </div>
        <div class="target-map__arrow" aria-hidden="true">/</div>
        <div class="target-map__item">
          <span class="target-map__label">Side Tab</span>
          <strong class="target-map__value"><?php echo sopEscape($currentPageTitle); ?></strong>
        </div>
        <?php if (!$isManager && $embedUrl !== ''): ?>
          <div class="target-map__zoom" data-doc-zoom-controls>
            <button
              class="zoom-button"
              type="button"
              title="Zoom out"
              aria-label="Zoom out document"
              data-doc-zoom-trigger="out"
            >
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="10.5" cy="10.5" r="5.5"></circle>
                <path d="M15.2 15.2L20 20"></path>
                <path d="M8 10.5H13"></path>
              </svg>
            </button>
            <button
              class="zoom-button"
              type="button"
              title="Zoom in"
              aria-label="Zoom in document"
              data-doc-zoom-trigger="in"
            >
              <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <circle cx="10.5" cy="10.5" r="5.5"></circle>
                <path d="M15.2 15.2L20 20"></path>
                <path d="M8 10.5H13"></path>
                <path d="M10.5 8V13"></path>
              </svg>
            </button>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($isManager): ?>
        <details class="manager-inline-panel" id="manager-connect-inline"<?php echo $hasSavedPageLinks ? '' : ' open'; ?>>
          <summary>Connect or replace the live links for the selected page</summary>
          <form class="connect-form" method="post" action="index.php" data-doc-dropzone>
            <input type="hidden" name="action" value="update_page_resources">
            <input type="hidden" name="csrf_token" value="<?php echo sopEscape(sopCsrfToken()); ?>">
            <input type="hidden" name="department_id" value="<?php echo sopEscape((string) ($currentDepartment['id'] ?? '')); ?>">
            <input type="hidden" name="page_id" value="<?php echo sopEscape((string) ($currentPage['id'] ?? '')); ?>">

            <div class="doc-dropzone" data-drop-surface tabindex="0">
              <strong>Paste or drag a Google Docs link here</strong>
              <span>Selected top tab: <?php echo sopEscape($currentDepartmentTitle); ?></span>
              <span>Selected side tab: <?php echo sopEscape($currentPageTitle); ?></span>
            </div>

            <label class="connect-form__label">
              <span>Google Doc Link</span>
              <input name="doc_url" type="url" value="<?php echo sopEscape($currentPageDocUrl); ?>" placeholder="Paste the Google Docs edit link here" data-doc-input>
            </label>

            <label class="connect-form__label">
              <span>Excel Embed or Workbook Link</span>
              <input name="sheet_url" type="url" value="<?php echo sopEscape($currentPageSheetUrl); ?>" placeholder="Paste the Excel embed link, workbook link, or iframe src here">
            </label>

            <p class="manager-help">Use Microsoft 365's Excel embed link for the cleanest in-page workbook view.</p>

            <div class="connect-form__actions">
              <button class="button" type="submit">Save Page Links</button>
            </div>
          </form>
        </details>
      <?php endif; ?>

      <?php if ($hasEmbeddedResource): ?>
        <div class="resource-stack">
          <?php if ($embedUrl !== ''): ?>
            <div class="doc-frame" data-doc-zoom-frame data-doc-zoom-level="0">
              <iframe
                title="<?php echo sopEscape((string) ($currentPage['title'] ?? 'Google Doc')); ?>"
                src="<?php echo sopEscape($embedUrl); ?>"
                loading="lazy"
                referrerpolicy="no-referrer"
                data-doc-iframe
              ></iframe>
              <button class="doc-frame__top-button" type="button" data-doc-top-trigger title="Back to top" aria-label="Back to top">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                  <path d="M12 6L7 11"></path>
                  <path d="M12 6L17 11"></path>
                  <path d="M12 6V18"></path>
                </svg>
              </button>
            </div>
          <?php endif; ?>

          <?php if ($sheetEmbedUrl !== ''): ?>
            <section class="sheet-panel">
              <div class="sheet-panel__header">
                <div class="sheet-panel__copy">
                  <p class="eyebrow" style="--eyebrow-color: <?php echo sopEscape($pageColor); ?>;">Spreadsheet</p>
                  <h3>Live Workbook</h3>
                  <p>Review the live Excel workbook connected to this SOP page.</p>
                </div>
              </div>
              <div class="sheet-frame">
                <iframe
                  title="<?php echo sopEscape((string) ($currentPage['title'] ?? 'Workbook')); ?>"
                  src="<?php echo sopEscape($sheetEmbedUrl); ?>"
                  loading="lazy"
                  referrerpolicy="no-referrer"
                ></iframe>
              </div>
            </section>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="doc-placeholder">
          <div class="doc-placeholder__panel">
            <p class="eyebrow" style="--eyebrow-color: <?php echo sopEscape($pageColor); ?>;">Live Links</p>
            <h3>This page is ready for a live SOP link.</h3>
            <p>Connect a Google Doc or an Excel workbook for this section and the page will render it as part of the workflow automatically.</p>
            <p class="doc-placeholder__note"><?php echo $isManager ? 'Use the link panel above to connect the page.' : 'This page does not have a live document or spreadsheet connected yet.'; ?></p>
          </div>
        </div>
      <?php endif; ?>
    </section>
  </main>

  <?php if ($isManager): ?>
    <section class="manager-console" id="manager-console">
      <div class="manager-console__header">
        <div>
          <p class="eyebrow">Manager Controls</p>
          <h2>User access</h2>
          <p>Use the + buttons above to add tabs and the lock icons on the tabs themselves to edit them. Google Doc and spreadsheet links stay connected from the overview panel.</p>
        </div>
      </div>

      <div class="manager-grid">
        <details class="manager-card" id="manager-users">
          <summary>User Access</summary>
          <form class="form-grid" method="post" action="index.php" data-user-form>
            <input type="hidden" name="action" value="upsert_user">
            <input type="hidden" name="csrf_token" value="<?php echo sopEscape(sopCsrfToken()); ?>">
            <input type="hidden" name="department_id" value="<?php echo sopEscape((string) ($currentDepartment['id'] ?? '')); ?>">
            <input type="hidden" name="page_id" value="<?php echo sopEscape((string) ($currentPage['id'] ?? '')); ?>">

            <label>
              <span>User ID</span>
              <input name="username" type="text" placeholder="example_user" required data-user-field="username">
            </label>

            <label>
              <span>Display Name</span>
              <input name="display_name" type="text" placeholder="Example User" required data-user-field="display_name">
            </label>

            <label>
              <span>Role</span>
              <select name="role" data-user-field="role">
                <option value="employee">Employee</option>
                <option value="manager">Manager</option>
              </select>
            </label>

            <label>
              <span>Password</span>
              <input name="password" type="text" placeholder="Leave blank to keep current password" data-user-field="password">
            </label>

            <p class="manager-help form-span-2 user-form-status" data-user-form-label>Creating a new user.</p>

            <p class="manager-help form-span-2">Employees can view SOP pages only. Managers can change users, passcodes, departments, side tabs, and live document links.</p>

            <div class="form-actions form-span-2">
              <button class="button" type="submit" data-user-submit>Save User</button>
              <button class="button button--secondary" type="button" data-user-cancel hidden>Cancel Edit</button>
            </div>
          </form>

          <div class="user-list">
            <?php foreach ($users as $username => $user): ?>
              <div class="user-list__item">
                <div class="user-list__identity">
                  <strong><?php echo sopEscape((string) ($user['display_name'] ?? $username)); ?></strong>
                  <span><?php echo sopEscape($username); ?></span>
                </div>
                <div class="user-list__actions">
                  <span class="role-badge role-badge--<?php echo sopEscape((string) ($user['role'] ?? 'employee')); ?>">
                    <?php echo sopEscape(ucfirst((string) ($user['role'] ?? 'employee'))); ?>
                  </span>
                  <button
                    class="button button--secondary button--small"
                    type="button"
                    data-user-edit
                    data-username="<?php echo sopEscape($username); ?>"
                    data-display-name="<?php echo sopEscape((string) ($user['display_name'] ?? $username)); ?>"
                    data-role="<?php echo sopEscape((string) ($user['role'] ?? 'employee')); ?>"
                  >Edit</button>
                  <form method="post" action="index.php" onsubmit="return confirm('Delete this user?');">
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="csrf_token" value="<?php echo sopEscape(sopCsrfToken()); ?>">
                    <input type="hidden" name="department_id" value="<?php echo sopEscape((string) ($currentDepartment['id'] ?? '')); ?>">
                    <input type="hidden" name="page_id" value="<?php echo sopEscape((string) ($currentPage['id'] ?? '')); ?>">
                    <input type="hidden" name="username" value="<?php echo sopEscape($username); ?>">
                    <button class="button button--danger button--small" type="submit">Delete</button>
                  </form>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </details>
      </div>
    </section>
  <?php endif; ?>
</body>
</html>
