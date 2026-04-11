<!DOCTYPE html>
<html lang="ko">

<head>
    <meta charset="UTF-8">
    <title>직원 수정</title>
</head>

<body>
    <h1>직원 수정</h1>

    <form method="POST" action="/employees/{{ $employee->id }}/update">
        @csrf

        사번: <input type="text" name="emp_no" value="{{ $employee->emp_no }}"><br><br>
        이름: <input type="text" name="employee_name" value="{{ $employee->employee_name }}"><br><br>
        부서: <input type="text" name="department_name" value="{{ $employee->department_name }}"><br><br>
        직급: <input type="text" name="job_title" value="{{ $employee->job_title }}"><br><br>
        입사일: <input type="date" name="hire_date" value="{{ $employee->hire_date }}"><br><br>
        상태: <input type="text" name="employment_status" value="{{ $employee->employment_status }}"><br><br>
        연락처: <input type="text" name="phone" value="{{ $employee->phone }}"><br><br>
        메모: <textarea name="memo">{{ $employee->memo }}</textarea><br><br>

        <button type="submit">수정 저장</button>
    </form>
</body>

</html>