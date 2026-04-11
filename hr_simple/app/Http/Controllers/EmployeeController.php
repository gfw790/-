<?php

namespace App\Http\Controllers;

use App\Models\Employee;

class EmployeeController extends Controller
{
    public function index()
    {
        $employees = Employee::orderBy('id', 'desc')->get();

        return view('employees.index', compact('employees'));
    }
    public function create()
    {
        return view('employees.create');
    }

    public function store()
    {
        \App\Models\Employee::create(request()->all());

        return redirect('/employees');
    }

    public function edit($id)
    {
        $employee = \App\Models\Employee::findOrFail($id);

        return view('employees.edit', compact('employee'));
    }

    public function update($id)
    {
        $employee = \App\Models\Employee::findOrFail($id);

        $employee->update(request()->all());

        return redirect('/employees');
    }

    public function retire($id)
    {
        $employee = \App\Models\Employee::findOrFail($id);
        $employee->update([
            'employment_status' => '퇴사',
        ]);

        return redirect('/employees');
    }
}