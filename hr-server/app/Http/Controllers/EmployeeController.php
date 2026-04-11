<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Department;
use Illuminate\Http\Request;

class EmployeeController extends Controller
{
    public function index(Request $request)
    {
        $query = Employee::with('department');

        if ($search = $request->input('search')) {
            $query->search($search);
        }
        if ($deptId = $request->input('department_id')) {
            $query->where('department_id', $deptId);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $employees   = $query->orderBy('employee_number')->paginate(15)->withQueryString();
        $departments = Department::active()->orderBy('sort_order')->get();

        return view('employees.index', compact('employees', 'departments'));
    }

    public function show(Employee $employee)
    {
        $employee->load('department');
        return view('employees.show', compact('employee'));
    }

    public function create()
    {
        $departments = Department::active()->orderBy('sort_order')->get();
        return view('employees.create', compact('departments'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'employee_number' => 'required|string|max:20|unique:employees,employee_number',
            'name'            => 'required|string|max:50',
            'email'           => 'nullable|email|max:100|unique:employees,email',
            'phone'           => 'required|string|max:20',
            'job_title'        => 'required|string|max:50',
            'job_title'       => 'nullable|string|max:100',
            'department_id'   => 'nullable|exists:departments,id',
            'hire_date'       => 'required|date',
            'resign_date'     => 'nullable|date|after:hire_date',
            'status'          => 'in:재직,휴직,퇴직',
            'address'         => 'nullable|string|max:255',
            'birth_date'      => 'nullable|date',
            'notes'           => 'nullable|string',
            'blood_type'      => 'nullable|in:A,B,O,AB',
            'shoe_size'       => 'nullable|string|max:10',
            'top_size'        => 'nullable|string|max:10',
            'bottom_size'     => 'nullable|string|max:10',
        ], [
            'employee_number.required' => '사번을 입력해주세요.',
            'employee_number.unique'   => '이미 존재하는 사번입니다.',
            'name.required'            => '이름을 입력해주세요.',
            'phone.required'           => '연락처를 입력해주세요.',
            'email.unique'             => '이미 사용 중인 이메일입니다.',
            'job_title.required'        => '직급을 입력해주세요.',
            'hire_date.required'       => '입사일을 입력해주세요.',
        ]);

        Employee::create($validated);

        return redirect()->route('employees.index')
                         ->with('success', '직원이 등록되었습니다.');
    }

    public function edit(Employee $employee)
    {
        $departments = Department::active()->orderBy('sort_order')->get();
        return view('employees.edit', compact('employee', 'departments'));
    }

    public function update(Request $request, Employee $employee)
    {
        $validated = $request->validate([
            'name'          => 'required|string|max:50',
            'email'         => 'nullable|email|max:100|unique:employees,email,' . $employee->id,
            'phone'         => 'required|string|max:20',
            'job_title'      => 'required|string|max:50',
            'job_title'     => 'nullable|string|max:100',
            'department_id' => 'nullable|exists:departments,id',
            'hire_date'     => 'required|date',
            'resign_date'   => 'nullable|date|after:hire_date',
            'status'        => 'in:재직,휴직,퇴직',
            'address'       => 'nullable|string|max:255',
            'birth_date'    => 'nullable|date',
            'notes'         => 'nullable|string',
            'blood_type'    => 'nullable|in:A,B,O,AB',
            'shoe_size'     => 'nullable|string|max:10',
            'top_size'      => 'nullable|string|max:10',
            'bottom_size'   => 'nullable|string|max:10',
        ]);

        $employee->update($validated);

        return redirect()->route('employees.show', $employee)
                         ->with('success', '직원 정보가 수정되었습니다.');
    }

    public function destroy(Employee $employee)
    {
        $employee->delete();

        return redirect()->route('employees.index')
                         ->with('success', '직원이 삭제되었습니다.');
    }
}
