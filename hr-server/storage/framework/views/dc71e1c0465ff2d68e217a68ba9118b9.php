<?php $__env->startSection('title', '부서 관리'); ?>
<?php $__env->startSection('page-title', '부서 관리'); ?>
<?php $__env->startSection('top-actions'); ?>
    <a href="<?php echo e(route('departments.create')); ?>" class="btn btn-primary"><i class="fas fa-plus"></i> 부서 추가</a>
<?php $__env->stopSection(); ?>
<?php $__env->startSection('content'); ?>
    <div class="card"><div class="table-wrap"><table><thead><tr>
        <th>부서코드</th><th>부서명</th><th>상위 부서</th><th>재직 인원</th><th>정렬순서</th><th>상태</th><th style="width:120px">관리</th>
    </tr></thead><tbody>
        <?php $__empty_1 = true; $__currentLoopData = $departments; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $dept): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
            <tr>
                <td><code style="background:#f1f5f9; padding:2px 8px; border-radius:4px; font-size:13px;"><?php echo e($dept->code); ?></code></td>
                <td style="font-weight:500;"><?php echo e($dept->name); ?></td>
                <td class="text-light"><?php echo e($dept->parent?->name ?? '-'); ?></td>
                <td><?php echo e($dept->employees_count); ?>명</td>
                <td class="text-light"><?php echo e($dept->sort_order); ?></td>
                <td><span class="badge <?php echo e($dept->is_active ? 'badge-active' : 'badge-resign'); ?>"><?php echo e($dept->is_active ? '활성' : '비활성'); ?></span></td>
                <td><div class="flex gap-2">
                    <a href="<?php echo e(route('departments.edit', $dept)); ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i></a>
                    <form action="<?php echo e(route('departments.destroy', $dept)); ?>" method="POST" onsubmit="return confirm('<?php echo e($dept->name); ?> 부서를 비활성화하시겠습니까?')"><?php echo csrf_field(); ?> <?php echo method_field('DELETE'); ?>
                        <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-ban"></i></button>
                    </form>
                </div></td>
            </tr>
        <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
            <tr><td colspan="7" class="empty-state"><i class="fas fa-sitemap"></i>등록된 부서가 없습니다.</td></tr>
        <?php endif; ?>
    </tbody></table></div></div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH A:\risk_server\project\hr-server\resources\views/departments/index.blade.php ENDPATH**/ ?>