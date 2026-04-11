<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = [
        'emp_no',
        'employee_name',
        'department_name',
        'job_title',
        'hire_date',
        'employment_status',
        'phone',
        'memo',
    ];
}