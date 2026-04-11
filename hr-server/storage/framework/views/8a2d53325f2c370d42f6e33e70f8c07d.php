<?php $__env->startSection('title', '직원 등록'); ?>
<?php $__env->startSection('page-title', '직원 등록'); ?>
<?php $__env->startSection('content'); ?>
    <div class="card" style="max-width:720px;">
        <form action="<?php echo e(route('employees.store')); ?>" method="POST"><?php echo csrf_field(); ?>
            <h4 style="font-size:14px; font-weight:600; color:var(--text-light); margin-bottom:16px;"><i class="fas fa-id-card"></i> 기본 정보</h4>
            <div class="form-row">
                <div class="form-group"><label>사번 <span class="required">*</span></label><input type="text" name="employee_number" class="form-control" value="<?php echo e(old('employee_number')); ?>" placeholder="예: EMP-009" required><?php $__errorArgs = ['employee_number'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="form-error"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?></div>
                <div class="form-group"><label>이름 <span class="required">*</span></label><input type="text" name="name" class="form-control" value="<?php echo e(old('name')); ?>" required></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>이메일</label><input type="email" name="email" class="form-control" value="<?php echo e(old('email')); ?>"><?php $__errorArgs = ['email'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="form-error"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?></div>
                <div class="form-group"><label>연락처 <span class="required">*</span></label><input type="text" name="phone" class="form-control" value="<?php echo e(old('phone')); ?>" placeholder="010-0000-0000" required><?php $__errorArgs = ['phone'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?><div class="form-error"><?php echo e($message); ?></div><?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>생년월일</label><input type="date" name="birth_date" class="form-control" value="<?php echo e(old('birth_date')); ?>"></div>
                <div class="form-group"><label>주소</label><input type="text" name="address" class="form-control" value="<?php echo e(old('address')); ?>"></div>
            </div>
            <hr style="border:none; border-top:1px solid var(--border); margin:24px 0;">
            <h4 style="font-size:14px; font-weight:600; color:var(--text-light); margin-bottom:16px;"><i class="fas fa-briefcase"></i> 근무 정보</h4>
            <div class="form-row">
                <div class="form-group"><label>소속 부서</label><select name="department_id" class="form-control"><option value="">선택하세요</option><?php $__currentLoopData = $departments; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $dept): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($dept->id); ?>" <?php echo e(old('department_id') == $dept->id ? 'selected' : ''); ?>><?php echo e($dept->name); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?></select></div>
                <div class="form-group"><label>직급 <span class="required">*</span></label><select name="job_title" class="form-control" required><option value="">선택하세요</option><?php $__currentLoopData = ['대표','이사','부장','차장','과장','대리','주임','사원']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $pos): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($pos); ?>" <?php echo e(old('job_title') === $pos ? 'selected' : ''); ?>><?php echo e($pos); ?></option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?></select></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>직책</label><input type="text" name="job_title" class="form-control" value="<?php echo e(old('job_title')); ?>" placeholder="예: 팀장, 본부장"></div>
                <div class="form-group"><label>재직 상태</label><select name="status" class="form-control"><option value="재직" <?php echo e(old('status') === '재직' ? 'selected' : ''); ?>>재직</option><option value="휴직" <?php echo e(old('status') === '휴직' ? 'selected' : ''); ?>>휴직</option><option value="퇴직" <?php echo e(old('status') === '퇴직' ? 'selected' : ''); ?>>퇴직</option></select></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>입사일 <span class="required">*</span></label><input type="date" name="hire_date" class="form-control" value="<?php echo e(old('hire_date', date('Y-m-d'))); ?>" required></div>
                <div class="form-group"><label>퇴사일</label><input type="date" name="resign_date" class="form-control" value="<?php echo e(old('resign_date')); ?>"></div>
            </div>
            <div class="form-group"><label>비고</label><textarea name="notes" class="form-control"><?php echo e(old('notes')); ?></textarea></div>
            <hr style="border:none; border-top:1px solid var(--border); margin:24px 0;">
            <h4 style="font-size:14px; font-weight:600; color:var(--text-light); margin-bottom:16px;"><i class="fas fa-tshirt"></i> 신체 정보</h4>
            <div class="form-row">
                <div class="form-group"><label>혈액형</label><select name="blood_type" class="form-control"><option value="">선택하세요</option><?php $__currentLoopData = ['A','B','O','AB']; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $bt): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?><option value="<?php echo e($bt); ?>" <?php echo e(old('blood_type') === $bt ? 'selected' : ''); ?>><?php echo e($bt); ?>형</option><?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?></select></div>
                <div class="form-group"><label>신발 사이즈</label><input type="text" name="shoe_size" class="form-control" value="<?php echo e(old('shoe_size')); ?>" placeholder="예: 270"></div>
            </div>
            <div class="form-row">
                <div class="form-group"><label>상의 사이즈</label><input type="text" name="top_size" class="form-control" value="<?php echo e(old('top_size')); ?>" placeholder="예: 105, XL"></div>
                <div class="form-group"><label>하의 사이즈</label><input type="text" name="bottom_size" class="form-control" value="<?php echo e(old('bottom_size')); ?>" placeholder="예: 32, L"></div>
            </div>
            <div class="flex gap-2"><button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> 등록</button><a href="<?php echo e(route('employees.index')); ?>" class="btn btn-secondary">취소</a></div>
        </form>
    </div>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layouts.app', array_diff_key(get_defined_vars(), ['__data' => 1, '__path' => 1]))->render(); ?><?php /**PATH A:\risk_server\project\hr-server\resources\views/employees/create.blade.php ENDPATH**/ ?>