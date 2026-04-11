<?php

namespace App\Http\Controllers;

use App\Models\Department;
use Illuminate\Http\Request;

class DepartmentController extends Controller
{
    public function index()
    {
        $departments = Department::active()
            ->withCount(['employees' => fn($q) => $q->where('status', '재직')])
            ->with('parent')
            ->orderBy('sort_order')
            ->get();

        return view('departments.index', compact('departments'));
    }

    public function tree()
    {
        $tree = Department::active()
            ->root()
            ->with([
                'childrenRecursive' => fn($q) => $q->with([
                    'employees' => fn($q) => $q->where('status', '재직')->orderByRaw("FIELD(job_title,'대표이사','실장','부장','과장','차장','반장','대리','주임','사원')")->orderBy('name'),
                ]),
                'employees' => fn($q) => $q->where('status', '재직')->orderByRaw("FIELD(job_title,'대표이사','실장','부장','과장','차장','반장','대리','주임','사원')")->orderBy('name'),
            ])
            ->orderBy('sort_order')
            ->get();

        $totalEmployees = \App\Models\Employee::where('status', '재직')->count();

        return view('departments.tree', compact('tree', 'totalEmployees'));
    }

    public function create()
    {
        $parents = Department::active()->orderBy('sort_order')->get();
        return view('departments.create', compact('parents'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'code'        => 'required|string|max:20|unique:departments,code',
            'parent_id'   => 'nullable|exists:departments,id',
            'description' => 'nullable|string',
            'sort_order'  => 'integer|min:0',
            'is_active'   => 'boolean',
        ], [
            'name.required' => '부서명을 입력해주세요.',
            'code.required' => '부서코드를 입력해주세요.',
            'code.unique'   => '이미 존재하는 부서코드입니다.',
        ]);

        Department::create($validated);

        return redirect()->route('departments.index')
                         ->with('success', '부서가 생성되었습니다.');
    }

    public function edit(Department $department)
    {
        $parents = Department::active()
            ->where('id', '!=', $department->id)
            ->orderBy('sort_order')
            ->get();

        return view('departments.edit', compact('department', 'parents'));
    }

    public function update(Request $request, Department $department)
    {
        $validated = $request->validate([
            'name'        => 'required|string|max:100',
            'code'        => 'required|string|max:20|unique:departments,code,' . $department->id,
            'parent_id'   => 'nullable|exists:departments,id',
            'description' => 'nullable|string',
            'sort_order'  => 'integer|min:0',
            'is_active'   => 'boolean',
        ]);

        if (isset($validated['parent_id']) && $validated['parent_id'] == $department->id) {
            return back()->withErrors(['parent_id' => '자기 자신을 상위 부서로 지정할 수 없습니다.']);
        }

        $department->update($validated);

        return redirect()->route('departments.index')
                         ->with('success', '부서 정보가 수정되었습니다.');
    }

    public function destroy(Department $department)
    {
        $department->update(['is_active' => false]);

        return redirect()->route('departments.index')
                         ->with('success', '부서가 비활성화되었습니다.');
    }
}
