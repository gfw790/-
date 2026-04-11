<?php $__env->startSection('title', '계정 관리'); ?>
<?php $__env->startSection('page-title', '계정 관리'); ?>
<?php $__env->startSection('top-actions'); ?>
    <a href="<?php echo e(route('users.create')); ?>" class="btn btn-primary"><i class="fas fa-plus"></i> 계정 추가</a>
<?php $__env->stopSection(); ?>
<?php $__env->startSection('content'); ?>

<?php if(session('error')): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle"></i> <?php echo e(session('error')); ?></div>
<?php endif; ?>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>아이디</th>
                    <th>이름</th>
                    <th>등록일</th>
                    <th style="width:140px;"></th>
                </tr>
            </thead>
            <tbody>
                <?php $__empty_1 = true; $__currentLoopData = $users; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $user): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); $__empty_1 = false; ?>
                <tr>
                    <td><?php echo e($user->username); ?>

                        <?php if($user->id === auth()->id()): ?>
                            <span class="badge" style="background:#dbeafe;color:#1d4ed8;margin-left:6px;">나</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo e($user->name); ?></td>
                    <td><?php echo e($user->created_at->format('Y-m-d')); ?></td>
                    <td>
                        <div class="flex gap-2">
                            <a href="<?php echo e(route('users.edit', $user)); ?>" class="btn btn-secondary btn-sm"><i class="fas fa-edit"></i> 수정</a>
                            <?php if($user->id !== auth()->id()): ?>
                            <form method="POST" action="<?php echo e(route('users.destroy', $user)); ?>"
                                  onsubmit="return confirm('<?php echo e($user->name); ?> 계정을 삭제할까요?')">
                                <?php echo csrf_field(); ?> <?php echo method_field('DELETE'); ?>
                                <button class="btn btn-danger btn-sm"><i class="fas fa-trash"></i> 삭제</button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); if ($__empty_1): ?>
                <tr><td colspan="4" class="text-center text-light" style="padding:32px;">등록된 계정이 없습니다.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH A:\risk_server\project\hr-server\resources\views/users/index.blade.php ENDPATH**/ ?>