<?php $__env->startSection('title', '계정 수정'); ?>
<?php $__env->startSection('page-title', '계정 수정'); ?>
<?php $__env->startSection('content'); ?>
<div class="card" style="max-width:480px;">
    <form method="POST" action="<?php echo e(route('users.update', $user)); ?>">
        <?php echo csrf_field(); ?> <?php echo method_field('PUT'); ?>
        <div class="form-group">
            <label>아이디 <span class="required">*</span></label>
            <input type="text" name="username" class="form-control" value="<?php echo e(old('username', $user->username)); ?>">
            <?php $__errorArgs = ['username'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="form-error"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>
        <div class="form-group">
            <label>이름 <span class="required">*</span></label>
            <input type="text" name="name" class="form-control" value="<?php echo e(old('name', $user->name)); ?>">
            <?php $__errorArgs = ['name'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="form-error"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>
        <div class="form-group">
            <label>새 비밀번호 <span class="text-light" style="font-weight:400;">(변경 시에만 입력)</span></label>
            <input type="password" name="password" class="form-control">
            <?php $__errorArgs = ['password'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="form-error"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
        </div>
        <div class="form-group">
            <label>새 비밀번호 확인</label>
            <input type="password" name="password_confirmation" class="form-control">
        </div>
        <div class="flex gap-2">
            <button type="submit" class="btn btn-primary">저장</button>
            <a href="<?php echo e(route('users.index')); ?>" class="btn btn-secondary">취소</a>
        </div>
    </form>
</div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH A:\risk_server\project\hr-server\resources\views/users/edit.blade.php ENDPATH**/ ?>