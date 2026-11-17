<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../tbm/phpqrcode/qrlib.php';

$user = csm_current_user();
$escape = static fn($v): string => htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');

// Determine Full Target URL for QR Code
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
$baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/CSM/'), '/\\');
$targetUrl = $scheme . '://' . $host . $baseDir . '/safety_suggestion_submit.php';

// Generate QR Code Base64
ob_start();
QRcode::png($targetUrl, null, QR_ECLEVEL_H, 8, 2);
$qrImageBase64 = 'data:image/png;base64,' . base64_encode(ob_get_clean());

// Logo base64
$logoPath = __DIR__ . '/assets/policy-logo.png';
if (!file_exists($logoPath)) {
    $logoPath = dirname(__DIR__) . '/safety_manual/assets/policy-logo.png';
}
$logoBase64 = '';
if (file_exists($logoPath)) {
    $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
}
?>
<!doctype html>
<html lang="ko">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>현장 부착용 안전제안 모바일 접수창구 QR 포스터 · 현대기전</title>
  <style>
    @page {
      size: A4 portrait;
      margin: 0;
    }
    :root {
      --top-pad: 16mm;
      --poster-primary: #1e3a8a;
      --poster-accent: #0284c7;
      --poster-gold: #d97706;
      --poster-green: #059669;
    }
    * {
      box-sizing: border-box;
      -webkit-print-color-adjust: exact;
      print-color-adjust: exact;
    }
    body {
      margin: 0;
      padding: 0;
      background: #f1f5f9;
      font-family: -apple-system, BlinkMacSystemFont, "Malgun Gothic", "맑은 고딕", Pretendard, sans-serif;
      color: #0f172a;
    }

    /* On-Screen Toolbar */
    .print-toolbar {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      height: 48px;
      background: #0f172a;
      color: #ffffff;
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 24px;
      z-index: 9999;
      box-shadow: 0 2px 10px rgba(0,0,0,0.25);
      font-size: 13px;
    }
    .print-toolbar button, .print-toolbar a {
      background: var(--poster-accent);
      color: #fff;
      border: none;
      padding: 6px 14px;
      border-radius: 6px;
      font-weight: 700;
      cursor: pointer;
      text-decoration: none;
      font-size: 13px;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .print-toolbar button.btn-secondary {
      background: #475569;
    }

    .page-wrapper {
      padding-top: 60px;
      padding-bottom: 40px;
      display: flex;
      justify-content: center;
    }
    .page {
      width: 210mm;
      height: 297mm;
      max-height: 297mm;
      background: #ffffff;
      margin: 0 auto;
      box-shadow: 0 4px 20px rgba(0,0,0,0.12);
      position: relative;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      padding: var(--top-pad) 14mm 10mm 14mm;
    }

    /* Poster Inner Container */
    .poster-box {
      border: 3.5px solid var(--poster-primary);
      border-radius: 12px;
      padding: 10mm 10mm 8mm 10mm;
      flex: 1;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      position: relative;
      background: #ffffff;
    }

    /* Inner Border Accent */
    .poster-box::before {
      content: "";
      position: absolute;
      top: 3px;
      left: 3px;
      right: 3px;
      bottom: 3px;
      border: 1px solid #93c5fd;
      border-radius: 8px;
      pointer-events: none;
    }

    .poster-header {
      text-align: center;
      margin-bottom: 4mm;
    }
    .poster-badge {
      display: inline-block;
      background: var(--poster-primary);
      color: #ffffff;
      font-size: 11pt;
      font-weight: 800;
      letter-spacing: 2px;
      padding: 4px 18px;
      border-radius: 999px;
      margin-bottom: 8px;
    }
    .poster-title {
      font-size: 26pt;
      font-weight: 900;
      color: #0f172a;
      letter-spacing: -1px;
      margin: 4px 0 6px 0;
      line-height: 1.25;
    }
    .poster-title .highlight {
      color: var(--poster-primary);
      position: relative;
    }
    .poster-sub {
      font-size: 13pt;
      font-weight: 700;
      color: var(--poster-gold);
      margin: 0;
      letter-spacing: -0.3px;
    }

    /* QR Focus Zone */
    .qr-card-zone {
      background: #f8fafc;
      border: 2px solid #e2e8f0;
      border-radius: 16px;
      padding: 8mm 6mm;
      text-align: center;
      margin: 4mm 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      box-shadow: inset 0 2px 4px rgba(0,0,0,0.03);
    }
    .qr-frame {
      background: #ffffff;
      padding: 12px;
      border-radius: 16px;
      border: 3px solid var(--poster-primary);
      box-shadow: 0 4px 12px rgba(30, 58, 138, 0.12);
      display: inline-block;
      margin-bottom: 10px;
    }
    .qr-frame img {
      width: 58mm;
      height: 58mm;
      display: block;
    }
    .qr-guide-text {
      font-size: 12pt;
      font-weight: 800;
      color: var(--poster-primary);
      margin-top: 4px;
    }
    .qr-guide-sub {
      font-size: 9.5pt;
      color: #64748b;
      margin-top: 3px;
      word-break: break-all;
    }

    /* Steps Grid */
    .steps-grid {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 3mm;
      margin: 4mm 0;
    }
    .step-item {
      background: #ffffff;
      border: 1.5px solid #cbd5e1;
      border-radius: 10px;
      padding: 4mm 3mm;
      text-align: center;
      position: relative;
    }
    .step-num {
      width: 24px;
      height: 24px;
      background: var(--poster-primary);
      color: #ffffff;
      border-radius: 999px;
      font-size: 10pt;
      font-weight: 800;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 6px auto;
    }
    .step-title {
      font-size: 10pt;
      font-weight: 800;
      color: #1e293b;
      margin-bottom: 3px;
    }
    .step-desc {
      font-size: 8pt;
      color: #64748b;
      line-height: 1.35;
    }

    /* Reward & Slogan Box */
    .reward-box {
      background: linear-gradient(135deg, #eff6ff 0%, #f0fdf4 100%);
      border: 1.5px solid #a7f3d0;
      border-radius: 10px;
      padding: 4mm 6mm;
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-top: 2mm;
    }
    .reward-left {
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .reward-icon {
      font-size: 26pt;
      line-height: 1;
    }
    .reward-title {
      font-size: 11pt;
      font-weight: 800;
      color: #065f46;
    }
    .reward-sub {
      font-size: 9pt;
      color: #334155;
      margin-top: 2px;
    }

    /* Footer outside poster box */
    .poster-footer {
      height: 12mm;
      display: flex;
      align-items: center;
      justify-content: space-between;
      border-top: 1px solid #cbd5e1;
      padding: 0 2mm;
      margin-top: 3mm;
    }
    .footer-brand {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 10.5pt;
      font-weight: 800;
      color: var(--poster-primary);
    }
    .footer-brand img {
      height: 7mm;
      width: auto;
    }
    .footer-right {
      font-size: 8pt;
      color: #64748b;
      text-align: right;
    }

    @media print {
      .print-toolbar {
        display: none !important;
      }
      .page-wrapper {
        padding: 0 !important;
      }
      .page {
        box-shadow: none !important;
        margin: 0 !important;
        width: 210mm !important;
        height: 297mm !important;
      }
    }
  </style>
</head>
<body>

<!-- Top Toolbar for Screen -->
<div class="print-toolbar">
  <div style="font-weight:700;">
    📢 안전제안 모바일 접수창구 A4 현장 부착용 포스터
  </div>
  <div style="display:flex;gap:8px;">
    <button type="button" class="btn-secondary" onclick="setPad('10mm')">여백 10mm</button>
    <button type="button" class="btn-secondary" onclick="setPad('16mm')">기본 16mm</button>
    <button type="button" class="btn-secondary" onclick="setPad('22mm')">여백 22mm</button>
    <a href="safety_suggestion_submit.php" target="_blank" class="btn-secondary">📱 모바일폼 열기</a>
    <button type="button" onclick="window.print()">🖨️ A4 포스터 인쇄</button>
  </div>
</div>

<div class="page-wrapper">
  <div class="page">
    <div class="poster-box">
      <!-- Header -->
      <div class="poster-header">
        <div class="poster-badge">CSM 1.3 · 근로자 안전참여</div>
        <h1 class="poster-title">
          스마트폰으로 바로 찍는<br>
          <span class="highlight">산업안전보건 제안 접수창구</span>
        </h1>
        <p class="poster-sub">🛡️ "안전은 행동입니다! 여러분의 작은 제안이 안전한 현장을 만듭니다."</p>
      </div>

      <!-- QR Card -->
      <div class="qr-card-zone">
        <div class="qr-frame">
          <img src="<?= $qrImageBase64 ?>" alt="안전제안 모바일 접수창구 QR">
        </div>
        <div class="qr-guide-text">
          📷 스마트폰 카메라로 QR코드를 스캔하세요!
        </div>
        <div class="qr-guide-sub">
          별도 앱 설치 없이 카메라로 바로 열어 사진과 함께 제안할 수 있습니다.<br>
          접속 주소: <?= $escape($targetUrl) ?>
        </div>
      </div>

      <!-- 4 Steps -->
      <div class="steps-grid">
        <div class="step-item">
          <div class="step-num">1</div>
          <div class="step-title">카메라 실행</div>
          <div class="step-desc">휴대폰 기본 카메라 앱을 켭니다.</div>
        </div>
        <div class="step-item">
          <div class="step-num">2</div>
          <div class="step-title">QR코드 스캔</div>
          <div class="step-desc">화면 상단 노란색 링크를 터치합니다.</div>
        </div>
        <div class="step-item">
          <div class="step-num">3</div>
          <div class="step-title">사진 &amp; 제안 작성</div>
          <div class="step-desc">현장 위험 사진과 개선 의견을 입력합니다.</div>
        </div>
        <div class="step-item">
          <div class="step-num">4</div>
          <div class="step-title">조치 &amp; 포상</div>
          <div class="step-desc">개선조치 이행 및 우수 제안자 포상금 지급!</div>
        </div>
      </div>

      <!-- Reward Callout -->
      <div class="reward-box">
        <div class="reward-left">
          <div class="reward-icon">🏆</div>
          <div>
            <div class="reward-title">우수 안전제안자 매월 '이달의 안전인' 선정 및 포상</div>
            <div class="reward-sub">위험요인을 적극 발굴하고 개선 아이디어를 제안해주신 분께 소정의 상품 및 포상금을 수여합니다.</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Company Footer Outside Document -->
    <div class="poster-footer">
      <div class="footer-brand">
        <?php if ($logoBase64): ?>
          <img src="<?= $logoBase64 ?>" alt="Logo">
        <?php endif; ?>
        <span>주식회사 현대기전</span>
      </div>
      <div class="footer-right">
        산업안전보건위원회 · 안전보건관리시스템 (CSM)<br>
        게시일자: <?= substr($today, 0, 4) ?>년 연중 상시 접수
      </div>
    </div>
  </div>
</div>

<script>
function setPad(pad) {
  document.documentElement.style.setProperty('--top-pad', pad);
}
</script>

</body>
</html>
