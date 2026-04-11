<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\Department;

class DashboardController extends Controller
{
    public function index()
    {
        $stats = [
            'total'     => Employee::count(),
            'active'    => Employee::where('status', '재직')->count(),
            'on_leave'  => Employee::where('status', '휴직')->count(),
            'resigned'  => Employee::where('status', '퇴직')->count(),
            'dept_count'=> Department::active()->count(),
        ];

        $byDepartment = Department::active()
            ->withCount(['employees' => fn($q) => $q->where('status', '재직')])
            ->orderByDesc('employees_count')
            ->get();

        $recentEmployees = Employee::with('department')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get();

        return view('dashboard', compact('stats', 'byDepartment', 'recentEmployees'));
    }
}
