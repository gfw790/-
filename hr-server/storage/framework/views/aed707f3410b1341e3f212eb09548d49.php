<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $__env->yieldContent('title', '인사관리 시스템'); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@300;400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --primary: #2563eb; --primary-dark: #1d4ed8; --primary-light: #dbeafe;
            --sidebar-bg: #0f172a; --sidebar-hover: #1e293b; --sidebar-active: #2563eb;
            --text: #1e293b; --text-light: #64748b; --bg: #f1f5f9;
            --card: #ffffff; --border: #e2e8f0;
            --success: #16a34a; --warning: #d97706; --danger: #dc2626;
        }
        body { font-family: 'Noto Sans KR', sans-serif; background: var(--bg); color: var(--text); display: flex; min-height: 100vh; }
        .sidebar { width: 260px; background: var(--sidebar-bg); color: #e2e8f0; job_title: fixed; top: 0; left: 0; bottom: 0; display: flex; flex-direction: column; z-index: 100; }
        .sidebar-brand { padding: 24px 20px; border-bottom: 1px solid rgba(255,255,255,.08); }
        .sidebar-brand h1 { font-size: 18px; font-weight: 700; color: #fff; letter-spacing: -0.5px; }
        .sidebar-brand span { font-size: 11px; color: #64748b; display: block; margin-top: 4px; }
        .sidebar-nav { padding: 16px 12px; flex: 1; }
        .sidebar-nav .nav-label { font-size: 11px; font-weight: 600; color: #475569; text-transform: uppercase; letter-spacing: 0.5px; padding: 12px 12px 6px; }
        .nav-link { display: flex; align-items: center; gap: 12px; padding: 10px 12px; color: #94a3b8; text-decoration: none; border-radius: 8px; font-size: 14px; transition: all .15s; margin-bottom: 2px; }
        .nav-link:hover { background: var(--sidebar-hover); color: #e2e8f0; }
        .nav-link.active { background: var(--sidebar-active); color: #fff; font-weight: 500; }
        .nav-link i { width: 20px; text-align: center; font-size: 15px; }
        .main-content { margin-left: 260px; flex: 1; min-height: 100vh; }
        .top-bar { background: var(--card); border-bottom: 1px solid var(--border); padding: 16px 32px; display: flex; justify-content: space-between; align-items: center; job_title: sticky; top: 0; z-index: 50; }
        .top-bar h2 { font-size: 18px; font-weight: 600; }
        .page-content { padding: 24px 32px; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 24px; margin-bottom: 24px; }
        .card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
        .card-header h3 { font-size: 16px; font-weight: 600; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 20px; display: flex; align-items: center; gap: 16px; }
        .stat-icon { width: 48px; height: 48px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 20px; }
        .stat-icon.blue { background: #dbeafe; color: #2563eb; }
        .stat-icon.green { background: #dcfce7; color: #16a34a; }
        .stat-icon.yellow { background: #fef3c7; color: #d97706; }
        .stat-icon.red { background: #fee2e2; color: #dc2626; }
        .stat-icon.purple { background: #f3e8ff; color: #9333ea; }
        .stat-info .stat-value { font-size: 24px; font-weight: 700; line-height: 1; }
        .stat-info .stat-label { font-size: 13px; color: var(--text-light); margin-top: 4px; }
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        thead th { background: #f8fafc; padding: 12px 16px; text-align: left; font-weight: 600; font-size: 13px; color: var(--text-light); border-bottom: 2px solid var(--border); white-space: nowrap; }
        tbody td { padding: 12px 16px; border-bottom: 1px solid var(--border); vertical-align: middle; }
        tbody tr:hover { background: #f8fafc; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
        .badge-active { background: #dcfce7; color: #166534; }
        .badge-leave { background: #fef3c7; color: #92400e; }
        .badge-resign { background: #fee2e2; color: #991b1b; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border: none; border-radius: 8px; font-size: 13px; font-weight: 500; cursor: pointer; text-decoration: none; transition: all .15s; font-family: 'Noto Sans KR', sans-serif; }
        .btn-primary { background: var(--primary); color: #fff; }
        .btn-primary:hover { background: var(--primary-dark); }
        .btn-secondary { background: #f1f5f9; color: var(--text); border: 1px solid var(--border); }
        .btn-secondary:hover { background: #e2e8f0; }
        .btn-danger { background: #fee2e2; color: #dc2626; }
        .btn-danger:hover { background: #fecaca; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        .form-group { margin-bottom: 20px; }
        .form-group label { display: block; font-size: 13px; font-weight: 500; color: var(--text); margin-bottom: 6px; }
        .form-group label .required { color: var(--danger); }
        .form-control { width: 100%; padding: 9px 12px; border: 1px solid var(--border); border-radius: 8px; font-size: 14px; font-family: 'Noto Sans KR', sans-serif; transition: border-color .15s; background: #fff; }
        .form-control:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
        select.form-control { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-job_title: right 12px center; padding-right: 32px; }
        textarea.form-control { resize: vertical; min-height: 80px; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
        .form-error { color: var(--danger); font-size: 12px; margin-top: 4px; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; display: flex; align-items: center; gap: 8px; }
        .alert-success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }
        .pagination-wrap { display: flex; justify-content: center; align-items: center; gap: 4px; padding: 16px 0; }
        .pagination-wrap a, .pagination-wrap span { display: inline-flex; align-items: center; justify-content: center; min-width: 36px; height: 36px; padding: 0 8px; border-radius: 8px; font-size: 13px; text-decoration: none; color: var(--text); border: 1px solid var(--border); background: #fff; }
        .pagination-wrap span[aria-current] { background: var(--primary); color: #fff; border-color: var(--primary); }
        .pagination-wrap a:hover { background: #f1f5f9; }
        .filter-bar { display: flex; gap: 12px; align-items: center; flex-wrap: wrap; margin-bottom: 20px; }
        .filter-bar .form-control { width: auto; min-width: 160px; }
        .filter-bar .search-input { min-width: 240px; }
        .org-tree ul { list-style: none; padding-left: 28px; }
        .org-tree > ul { padding-left: 0; }
        .tree-node { padding: 10px 16px; margin: 4px 0; background: #f8fafc; border: 1px solid var(--border); border-radius: 8px; display: flex; align-items: center; justify-content: space-between; transition: all .15s; }
        .tree-node:hover { border-color: var(--primary); background: var(--primary-light); }
        .tree-node .dept-name { font-weight: 500; font-size: 14px; }
        .tree-node .dept-code { font-size: 12px; color: var(--text-light); margin-left: 8px; }
        .tree-node .dept-count { font-size: 12px; color: var(--text-light); background: #e2e8f0; padding: 2px 8px; border-radius: 10px; }
        .text-light { color: var(--text-light); }
        .text-center { text-align: center; }
        .flex { display: flex; }
        .gap-2 { gap: 8px; }
        .empty-state { text-align: center; padding: 48px 16px; color: var(--text-light); }
        .empty-state i { font-size: 48px; margin-bottom: 12px; display: block; }
    </style>
</head>
<body>
    <aside class="sidebar">
        <div class="sidebar-brand">
            <h1><i class="fas fa-building"></i> HR Manager</h1>
            <span>인사관리 시스템 v1.0</span>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-label">메인</div>
            <a href="<?php echo e(route('dashboard')); ?>" class="nav-link <?php echo e(request()->routeIs('dashboard') ? 'active' : ''); ?>">
                <i class="fas fa-chart-pie"></i> 대시보드
            </a>
            <div class="nav-label">인사 관리</div>
            <a href="<?php echo e(route('employees.index')); ?>" class="nav-link <?php echo e(request()->routeIs('employees.*') ? 'active' : ''); ?>">
                <i class="fas fa-users"></i> 직원 관리
            </a>
            <div class="nav-label">조직 관리</div>
            <a href="<?php echo e(route('departments.index')); ?>" class="nav-link <?php echo e(request()->routeIs('departments.index', 'departments.create', 'departments.edit') ? 'active' : ''); ?>">
                <i class="fas fa-sitemap"></i> 부서 관리
            </a>
            <a href="<?php echo e(route('departments.tree')); ?>" class="nav-link <?php echo e(request()->routeIs('departments.tree') ? 'active' : ''); ?>">
                <i class="fas fa-project-diagram"></i> 조직도
            </a>
        </nav>
        <div style="padding:16px 12px; border-top:1px solid rgba(255,255,255,.08);">
            <a href="<?php echo e(route('users.index')); ?>" style="font-size:12px; color:#94a3b8; margin-bottom:8px; padding:4px; border-radius:6px; display:block; text-decoration:none; transition:all .15s;"
               onmouseover="this.style.background='rgba(255,255,255,.08)'; this.style.color='#e2e8f0';"
               onmouseout="this.style.background=''; this.style.color='#94a3b8';">
                <i class="fas fa-user-circle" style="margin-right:6px;"></i><?php echo e(auth()->user()->name ?? auth()->user()->username); ?>

            </a>
            <form method="POST" action="<?php echo e(route('logout')); ?>">
                <?php echo csrf_field(); ?>
                <button type="submit" style="width:100%; background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.1); color:#94a3b8; padding:8px 12px; border-radius:8px; font-size:13px; font-family:inherit; cursor:pointer; text-align:left; display:flex; align-items:center; gap:8px; transition:all .15s;"
                        onmouseover="this.style.background='rgba(255,255,255,.12)'; this.style.color='#e2e8f0';"
                        onmouseout="this.style.background='rgba(255,255,255,.06)'; this.style.color='#94a3b8';">
                    <i class="fas fa-sign-out-alt"></i> 로그아웃
                </button>
            </form>
        </div>
    </aside>
    <main class="main-content">
        <div class="top-bar">
            <h2><?php echo $__env->yieldContent('page-title', '대시보드'); ?></h2>
            <div><?php echo $__env->yieldContent('top-actions'); ?></div>
        </div>
        <div class="page-content">
            <?php if(session('success')): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo e(session('success')); ?></div>
            <?php endif; ?>
            <?php if($errors->any()): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo e($errors->first()); ?></div>
            <?php endif; ?>
            <?php echo $__env->yieldContent('content'); ?>
        </div>
    </main>
</body>
</html>
<?php /**PATH A:\risk_server\project\hr-server\resources\views/layouts/app.blade.php ENDPATH**/ ?>