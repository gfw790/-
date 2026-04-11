<?php $__env->startSection('title', '대시보드'); ?>
<?php $__env->startSection('page-title', '대시보드'); ?>
<?php $__env->startSection('content'); ?>
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-users"></i></div><div class="stat-info"><div class="stat-value"><?php echo e($stats['total']); ?></div><div class="stat-label">전체 직원</div></div></div>
        <div class="stat-card"><div class="stat-icon green"><i class="fas fa-user-check"></i></div><div class="stat-info"><div class="stat-value"><?php echo e($stats['active']); ?></div><div class="stat-label">재직 중</div></div></div>
        <div class="stat-card"><div class="stat-icon yellow"><i class="fas fa-user-clock"></i></div><div class="stat-info"><div class="stat-value"><?php echo e($stats['on_leave']); ?></div><div class="stat-label">휴직 중</div></div></div>
        <div class="stat-card"><div class="stat-icon red"><i class="fas fa-user-minus"></i></div><div class="stat-info"><div class="stat-value"><?php echo e($stats['resigned']); ?></div><div class="stat-label">퇴직</div></div></div>
        <div class="stat-card"><div class="stat-icon purple"><i class="fas fa-sitemap"></i></div><div class="stat-info"><div class="stat-value"><?php echo e($stats['dept_count']); ?></div><div class="stat-label">활성 부서</div></div></div>
    </div>
    <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px;">
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-chart-bar"></i> 부서별 재직 인원</h3></div>
            <div class="table-wrap"><table><thead><tr><th>부서</th><th style="text-align:right">인원</th><th style="width:50%">비율</th></tr></thead><tbody>
                <?php $__currentLoopData = $byDepartment; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $dept): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>
                    <tr>
                        <td><?php echo e($dept->name); ?></td>
                        <td style="text-align:right; font-weight:600;"><?php echo e($dept->employees_count); ?>명</td>
                        <td><?php $pct = $stats['active'] > 0 ? round($dept->employees_count / $stats['active'] * 100) : 0; ?>
                            <div style="background:#e2e8f0; border-radius:4px; height:20px; overflow:hidden;"><div style="background:var(--primary); height:100%; width:<?php echo e($pct); ?>%; border-radius:4px;"></div></div>
                        </td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>
            </tbody></table></div>
        </div>
        <div class="card">
            <div class="card-header"><h3><i class="fas fa-user-plus"></i> 최근 등록 직원</h3><a href="<?php echo e(route('employees.index')); ?>" class="btn btn-secondary btn-sm">전체보기</a></div>
            <div class="table-wrap"><table><thead><tr><th>사번</th><th>이름</th><th>부서</th><th>입사일</th></tr></thead><tbody>
                <?php $__empty_1 = true; $__currentLoopData = $recentEmployees; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $emp): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                    <tr>
                        <td class="text-light"><?php echo e($emp->employee_number); ?></td>
                        <td><a href="<?php echo e(route('employees.show', $emp)); ?>" style="color:var(--primary); text-decoration:none; font-weight:500;"><?php echo e($emp->name); ?></a></td>
                        <td><?php echo e($emp->department?->name ?? '-'); ?></td>
                        <td class="text-light"><?php echo e($emp->hire_date->format('Y-m-d')); ?></td>
                    </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                    <tr><td colspan="4" class="text-center text-light">등록된 직원이 없습니다.</td></tr>
                <?php endif; ?>
            </tbody></table></div>
        </div>
    </div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH A:\risk_server\project\hr-server\resources\views/dashboard.blade.php ENDPATH**/ ?>