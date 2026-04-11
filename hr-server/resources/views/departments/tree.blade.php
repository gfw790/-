@extends('layouts.app')
@section('title', '조직도')
@section('page-title', '조직도')
@section('top-actions')
    <button onclick="window.print()" class="btn btn-secondary"><i class="fas fa-print"></i> 인쇄</button>
@endsection
@section('content')
<style>
/* ── 화면 ── */
.org-wrap { overflow-x:auto; padding:16px 8px 40px; }

.org-header {
    text-align:right; font-size:13px;
    color:var(--text-light); margin-bottom:48px; letter-spacing:.3px;
}

/* 박스 */
.org-box {
    min-width:190px; background:#fff; border-radius:10px;
    box-shadow:0 2px 12px rgba(0,0,0,.10); border:1px solid #d1dce8;
}
.org-box-title {
    font-weight:700; font-size:14px; padding:9px 14px;
    text-align:center; letter-spacing:.5px; border-radius:10px 10px 0 0;
}
.org-box-title.root { background:linear-gradient(135deg,#2c5f8a,#4a8fc4); color:#fff; }
.org-box-title.child { background:linear-gradient(135deg,#4a8fc4,#7ab8dc); color:#fff; }

/* 직원 행 */
.org-employee {
    display:flex; align-items:center; gap:0; padding:0;
    border-top:1px solid #eaf0f6;
}
.org-employee .rank-cell {
    width:44px; min-width:44px; background:#f4f8fc; border-right:1px solid #eaf0f6;
    text-align:center; padding:8px 4px; font-size:11.5px; color:#5a7a9a; font-weight:600;
    align-self:stretch; display:flex; align-items:center; justify-content:center;
}
.org-employee .info-cell {
    padding:7px 12px; display:flex; flex-direction:column; gap:1px;
}
.org-employee .emp-name { font-weight:700; font-size:13.5px; color:#1a2e42; }
.org-employee .emp-phone { font-size:11.5px; color:#1a2e42; letter-spacing:.3px; }

/* QR - 숨김 */
.emp-qr { display:none !important; }

/* 연결선 */
.org-vline { width:2px; background:#b0c8e0; margin:0 auto; }
.org-row { display:flex; justify-content:center; gap:36px; position:relative; }
.org-row::before {
    content:''; position:absolute; top:0; height:2px; background:#b0c8e0;
    left:var(--hl-left); right:var(--rl-right);
}
.org-col { display:flex; flex-direction:column; align-items:center; }
.org-col-vline { width:2px; height:48px; background:#b0c8e0; }

/* ── 인쇄 ── */
@media print {
    @page { size:A4 portrait; margin:14mm 12mm; }

    .sidebar, .top-bar, .btn { display:none !important; }
    .main-content { margin-left:0 !important; }
    .page-content { padding:0 !important; }
    .card { border:none !important; padding:0 !important; box-shadow:none !important; margin:0 !important;
            display:block !important; }

    .print-title {
        display:block !important; text-align:center; font-size:24pt;
        font-weight:900; letter-spacing:10px; margin-bottom:6mm; margin-top:8mm; color:#1a2e42;
    }
    .org-header { font-size:9.5pt; margin-bottom:10mm; color:#555; }

    .org-box {
        border-radius:0 !important; box-shadow:none !important;
        border:1.5pt solid #2c5f8a !important;
        overflow:visible !important; display:inline-table !important;
        -webkit-print-color-adjust:exact; print-color-adjust:exact;
    }
    .org-box .org-employee:last-child { border-bottom:1.5pt solid #2c5f8a !important; }
    .org-box-title {
        border-radius:0 !important; font-size:10.5pt !important; padding:5px 10px !important;
        -webkit-print-color-adjust:exact; print-color-adjust:exact;
    }
    .org-box-title.root { background:linear-gradient(135deg,#2c5f8a,#4a8fc4) !important; color:#fff !important; }
    .org-box-title.child { background:linear-gradient(135deg,#4a8fc4,#7ab8dc) !important; color:#fff !important; }

    .org-employee { border-top:0.5pt solid #d8e8f4 !important; align-items:center !important; }
    .org-employee .rank-cell {
        width:32px !important; min-width:32px !important; font-size:8pt !important;
        padding:4px 2px !important; background:#f0f6fc !important;
        border-right:0.5pt solid #d8e8f4 !important; align-self:stretch !important;
        -webkit-print-color-adjust:exact; print-color-adjust:exact;
    }
    .org-employee .info-cell { padding:4px 7px !important; flex-direction:row !important; align-items:center !important; justify-content:space-between !important; flex:1 !important; overflow:hidden !important; }
    .org-employee .emp-texts { display:flex; flex-direction:column; gap:1px; }
    .org-employee .emp-name { font-size:9pt !important; }
    .org-employee .emp-phone { display:block !important; font-size:8pt !important; color:#000 !important; }

    .org-vline { background:#7ab8dc !important; height:48px !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .org-col-vline { background:#7ab8dc !important; height:48px !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .org-row::before { background:#7ab8dc !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .org-row { gap:20px !important; }
}
</style>


<div class="card org-wrap">
    <div class="print-title" style="display:none;">조 직 도</div>
    <div class="org-header">
        {{ now()->format('Y년 m월 d일') }}(총{{ $totalEmployees }}명)
    </div>

    @foreach($tree as $root)
    <div style="display:flex; flex-direction:column; align-items:center;">

        <div class="org-box" style="min-width:210px;">
            <div class="org-box-title root">{{ $root->name }}</div>
            @foreach($root->employees as $emp)
                <div class="org-employee">
                    <span class="rank-cell">{{ $emp->job_title }}</span>
                    <span class="info-cell">
                        <span class="emp-texts">
                            <span class="emp-name">{{ $emp->name }}</span>
                            <span class="emp-phone">{{ $emp->phone }}</span>
                        </span>
                        @if($emp->phone)
                            <span class="emp-qr" id="qr-{{ $emp->id }}" data-phone="{{ $emp->phone }}"></span>
                        @endif
                    </span>
                </div>
            @endforeach
        </div>

        @if($root->childrenRecursive->isNotEmpty())
        <div class="org-vline" style="height:48px;"></div>

        @php $children = $root->childrenRecursive; @endphp
        <div class="org-row" id="org-row">
            @foreach($children as $child)
            <div class="org-col">
                <div class="org-col-vline"></div>
                <div class="org-box">
                    <div class="org-box-title child">{{ $child->name }}</div>
                    @foreach($child->employees as $emp)
                        <div class="org-employee">
                            <span class="rank-cell">{{ $emp->job_title }}</span>
                            <span class="info-cell">
                                <span class="emp-texts">
                                    <span class="emp-name">{{ $emp->name }}</span>
                                    <span class="emp-phone">{{ $emp->phone }}</span>
                                </span>
                                @if($emp->phone)
                                    <span class="emp-qr" id="qr-{{ $emp->id }}" data-phone="{{ $emp->phone }}"></span>
                                @endif
                            </span>
                        </div>
                    @endforeach
                    @if($child->employees->isEmpty())
                        <div style="padding:14px; font-size:13px; color:var(--text-light); text-align:center;">직원 없음</div>
                    @endif
                </div>
            </div>
            @endforeach
        </div>
        @endif

    </div>
    @endforeach
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // 연결선 계산
    const row = document.getElementById('org-row');
    if (!row) return;
    const cols = row.querySelectorAll('.org-col');
    if (cols.length < 2) return;
    const rowRect = row.getBoundingClientRect();
    const first = cols[0].getBoundingClientRect();
    const last  = cols[cols.length - 1].getBoundingClientRect();
    row.style.setProperty('--hl-left',  (first.left + first.width / 2 - rowRect.left) + 'px');
    row.style.setProperty('--rl-right', (rowRect.right - (last.left + last.width / 2)) + 'px');
});
</script>
@endsection
