<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>로그인 — 인사관리 시스템</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+KR:wght@300;400;500;600;700&display=swap');
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Noto Sans KR', sans-serif;
            background: #0f172a;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .login-card {
            background: #fff;
            border-radius: 16px;
            padding: 48px 40px;
            width: 100%;
            max-width: 400px;
            box-shadow: 0 25px 50px rgba(0,0,0,.4);
        }
        .login-header {
            text-align: center;
            margin-bottom: 36px;
        }
        .login-header .icon {
            width: 56px; height: 56px;
            background: #2563eb;
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
            color: #fff;
            margin-bottom: 16px;
        }
        .login-header h1 { font-size: 22px; font-weight: 700; color: #0f172a; }
        .login-header p { font-size: 13px; color: #64748b; margin-top: 6px; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; font-size: 13px; font-weight: 500; color: #374151; margin-bottom: 6px; }
        .input-wrap { position: relative; }
        .input-wrap i { position: absolute; left: 12px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 14px; }
        .form-control {
            width: 100%; padding: 10px 12px 10px 36px;
            border: 1px solid #e2e8f0; border-radius: 8px;
            font-size: 14px; font-family: 'Noto Sans KR', sans-serif;
            transition: border-color .15s;
            background: #f8fafc;
        }
        .form-control:focus { outline: none; border-color: #2563eb; background: #fff; box-shadow: 0 0 0 3px rgba(37,99,235,.1); }
        .form-error { color: #dc2626; font-size: 12px; margin-top: 5px; }
        .remember-row { display: flex; align-items: center; gap: 8px; margin-bottom: 24px; }
        .remember-row input[type=checkbox] { width: 16px; height: 16px; accent-color: #2563eb; cursor: pointer; }
        .remember-row label { font-size: 13px; color: #64748b; cursor: pointer; }
        .btn-login {
            width: 100%; padding: 12px;
            background: #2563eb; color: #fff;
            border: none; border-radius: 8px;
            font-size: 15px; font-weight: 600;
            font-family: 'Noto Sans KR', sans-serif;
            cursor: pointer; transition: background .15s;
        }
        .btn-login:hover { background: #1d4ed8; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="login-header">
            <div class="icon"><i class="fas fa-building"></i></div>
            <h1>HR Manager</h1>
            <p>인사관리 시스템에 로그인하세요</p>
        </div>
        <form method="POST" action="<?php echo e(route('login')); ?>">
            <?php echo csrf_field(); ?>
            <div class="form-group">
                <label>아이디</label>
                <div class="input-wrap">
                    <i class="fas fa-user"></i>
                    <input type="text" name="username" class="form-control"
                           value="<?php echo e(old('username')); ?>" placeholder="아이디 입력" autofocus>
                </div>
                <?php $__errorArgs = ['username'];
$__bag = $errors->getBag($__errorArgs[1] ?? 'default');
if ($__bag->has($__errorArgs[0])) :
if (isset($message)) { $__messageOriginal = $message; }
$message = $__bag->first($__errorArgs[0]); ?>
                    <div class="form-error"><?php echo e($message); ?></div>
                <?php unset($message);
if (isset($__messageOriginal)) { $message = $__messageOriginal; }
endif;
unset($__errorArgs, $__bag); ?>
            </div>
            <div class="form-group">
                <label>비밀번호</label>
                <div class="input-wrap">
                    <i class="fas fa-lock"></i>
                    <input type="password" name="password" class="form-control" placeholder="비밀번호 입력">
                </div>
            </div>
            <div class="remember-row">
                <input type="checkbox" name="remember" id="remember">
                <label for="remember">로그인 상태 유지</label>
            </div>
            <button type="submit" class="btn-login">로그인</button>
        </form>
    </div>
</body>
</html>
<?php /**PATH A:\risk_server\project\hr-server\resources\views/auth/login.blade.php ENDPATH**/ ?>