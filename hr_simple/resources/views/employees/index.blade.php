<!DOCTYPE html>
<html lang="ko">

<div style="display:flex; justify-content:space-between; align-items:center;">
    <h1>직원관리</h1>
    <a href="/employees/create">+ 직원 등록</a>
</div>

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>직원 목록</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 40px;
        }

        h1 {
            margin-bottom: 20px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            border: 1px solid #ccc;
            padding: 10px;
            text-align: left;
        }

        th {
            background-color: #f3f3f3;
        }
    </style>
</head>

<body>
    <h1>직원 목록</h1>
    <form method="GET" action="/employees" style="margin-bottom:20px;">
        <input type="text" name="keyword" placeholder="이름, 사번 검색">

        <select name="status">
            <option value="">전체</option>
            <option value="재직">재직</option>
            <option value="퇴사">퇴사</option>
        </select>

        <button type="submit">검색</button>
    </form>
    <table>
        <thead>
            <tr>
                <th>사번</th>
                <th>이름</th>
                <th>부서</th>
                <th>직급</th>
                <th>입사일</th>
                <th>상태</th>
                <th>연락처</th>
                <th>관리</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($employees as $employee)
                <tr style="{{ $employee->employment_status == '퇴사' ? 'background-color:#f8f9fa; opacity:0.6;' : '' }}">

                    <td>{{ $employee->emp_no }}</td>
                    <td>{{ $employee->employee_name }}</td>
                    <td>{{ $employee->department_name }}</td>
                    <td>{{ $employee->job_title }}</td>
                    <td>{{ $employee->hire_date }}</td>
                    <td>
                        @if($employee->employment_status == '재직')
                            <span style="color:green; font-weight:bold;">재직</span>
                        @else
                            <span style="color:gray;">퇴사</span>
                        @endif
                    </td>
                    <td>{{ $employee->phone }}</td>
                    <td>
                        <a href="/employees/{{ $employee->id }}/edit">수정</a>

                        <form method="POST" action="/employees/{{ $employee->id }}/retire" style="display:inline;">
                            @csrf
                            <button type="submit" onclick="return confirm('퇴사 처리할까요?')">[퇴사]</button>
                        </form>
                    </td>

                </tr>
            @empty
                <tr>
                    <td colspan="9">등록된 직원이 없습니다.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</body>

</html>