<?php $__env->startSection('title', '직원 관리'); ?>
<?php $__env->startSection('page-title', '직원 관리'); ?>
<?php $__env->startSection('top-actions'); ?>
    <a href="<?php echo e(route('employees.create')); ?>" class="btn btn-primary"><i class="fas fa-user-plus"></i> 직원 등록</a>
<?php $__env->stopSection(); ?>
<?php $__env->startSection('content'); ?>
    <div class="card">
        <form method="GET" action="<?php echo e(route('employees.index')); ?>" class="filter-bar">
            <input type="text" name="search" class="form-control search-input" placeholder="이름, 사번, 이메일 검색..." value="<?php echo e(request('search')); ?>">
            <select name="department_id" class="form-control"><option value="">전체 부서</option><?php $__currentLoopData = $departments; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $dept): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($dept->id); ?>" <?php echo e(request('department_id') == $dept->id ? 'selected' : ''); ?>><?php echo e($dept->name); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?></select>
            <select name="status" class="form-control"><option value="">전체 상태</option><option value="재직" <?php echo e(request('status') === '재직' ? 'selected' : ''); ?>>재직</option><option value="휴직" <?php echo e(request('status') === '휴직' ? 'selected' : ''); ?>>휴직</option><option value="퇴직" <?php echo e(request('status') === '퇴직' ? 'selected' : ''); ?>>퇴직</option></select>
            <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-search"></i> 검색</button>
            <a href="<?php echo e(route('employees.index')); ?>" class="btn btn-secondary btn-sm">초기화</a>
        </form>
        <div class="table-wrap"><table><thead><tr>
            <th>사번</th><th>이름</th><th>부서</th><th>직급</th><th>직책</th><th>입사일</th><th>상태</th><th style="width:120px">관리</th>
        </tr></thead><tbody>
            <?php $__empty_1 = true; $__currentLoopData = $employees; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $emp): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <tr>
                    <td><code style="background:#f1f5f9; padding:2px 8px; border-radius:4px; font-size:13px;"><?php echo e($emp->employee_number); ?></code></td>
                    <td><a href="<?php echo e(route('employees.show', $emp)); ?>" style="color:var(--primary); text-decoration:none; font-weight:500;"><?php echo e($emp->name); ?></a></td>
                    <td><?php echo e($emp->department?->name ?? '-'); ?></td>
                    <td><?php echo e($emp->job_title); ?></td>
                    <td class="text-light"><?php echo e($emp->job_title ?? '-'); ?></td>
                    <td class="text-light"><?php echo e($emp->hire_date->format('Y-m-d')); ?></td>
                    <td><?php $bc = match($emp->status) { '재직'=>'badge-active', '휴직'=>'badge-leave', '퇴직'=>'badge-resign', default=>'' }; ?><span class="badge <?php echo e($bc); ?>"><?php echo e($emp->status); ?></span></td>
                    <td><div class="flex gap-2">
                        <a href="<?php echo e(route('employees.edit', $emp)); ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i></a>
                        <form action="<?php echo e(route('employees.destroy', $emp)); ?>" method="POST" onsubmit="return confirm('<?php echo e($emp->name); ?> 직원을 삭제하시겠습니까?')"><?php echo csrf_field(); ?> <?php echo method_field('DELETE'); ?><button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button></form>
                    </div></td>
                </tr>
            <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <tr><td colspan="8" class="empty-state"><i class="fas fa-users"></i>등록된 직원이 없습니다.</td></tr>
            <?php endif; ?>
        </tbody></table></div>
        <?php if($employees->hasPages()): ?><div class="pagination-wrap"><?php echo e($employees->links()); ?></div><?php endif; ?>
        <div class="text-light" style="font-size:13px; padding-top:8px;">총 <?php echo e($employees->total()); ?>명 중 <?php echo e($employees->firstItem()); ?>~<?php echo e($employees->lastItem()); ?></div>
    </div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH A:\risk_server\project\hr-server\resources\views/employees/index.blade.php ENDPATH**/ ?>