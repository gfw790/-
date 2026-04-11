<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>직원 등록</title>
</head>
<body>
    <h1>직원 등록</h1>

    <form method="POST" action="/employees">
        @csrf

        사번: <input type="text" name="emp_no"><br><br>
        이름: <input type="text" name="employee_name"><br><br>
        부서: <input type="text" name="department_name"><br><br>
        직급: <input type="text" name="job_title"><br><br>
        입사일: <input type="date" name="hire_date"><br><br>
        상태: <input type="text" name="employment_status" value="재직"><br><br>
        연락처: <input type="text" name="phone"><br><br>
        메모: <textarea name="memo"></textarea><br><br>

        <button type="submit">저장</button>
    </form>
</body>
</html>