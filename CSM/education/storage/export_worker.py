import os
import sys

# Ensure user site-packages are accessible even when invoked under Apache service account
for _site in [
    r'C:\Users\gfw79\AppData\Roaming\Python\Python314\site-packages',
    r'C:\Users\gfw79\AppData\Local\Programs\Python\Python314\Lib\site-packages'
]:
    if os.path.exists(_site):
        for _sub in ['', 'win32', 'win32\\lib', 'pythonwin']:
            _p = os.path.join(_site, _sub)
            if os.path.exists(_p) and _p not in sys.path:
                sys.path.insert(0, _p)
        _dll_dir = os.path.join(_site, 'pywin32_system32')
        if os.path.exists(_dll_dir):
            try:
                os.add_dll_directory(_dll_dir)
            except Exception:
                pass
            os.environ['PATH'] = _dll_dir + ';' + os.environ.get('PATH', '')

import json
import re
import sqlite3
import datetime
import openpyxl
from openpyxl.cell.cell import MergedCell
from openpyxl.styles import Font, Alignment, PatternFill, Border, Side
from openpyxl.drawing.image import Image as OpenpyxlImage
from PIL import Image as PILImage, ImageOps

STORAGE_DIR = os.path.dirname(os.path.abspath(__file__))
TEMPLATES_DIR = os.path.join(os.path.dirname(STORAGE_DIR), "templates")
EXPORTS_DIR = os.path.join(STORAGE_DIR, "exports")
UPLOADS_PHOTOS_DIR = os.path.join(STORAGE_DIR, "uploads", "photos")
DB_PATH = os.path.join(STORAGE_DIR, "db", "csm.db")
TEMPLATE_FILE = os.path.join(TEMPLATES_DIR, "교육일지_서식사본.xlsx")

def calc_auto_font_size(text, cell_width_units, base_size=9.0, min_size=6.0):
    """
    Dynamically scales down font size when text length overflows merged cell width.
    Korean/CJK character ~= 1.8 units, ASCII/numbers/spaces ~= 1.0 unit.
    """
    if not text:
        return base_size
    total_units = sum(1.8 if ord(ch) > 127 else 1.0 for ch in str(text))
    usable_units = max(5.0, cell_width_units - 4.0)
    if total_units <= usable_units:
        return base_size
    ratio = usable_units / total_units
    scaled = round(base_size * ratio, 1)
    return max(min_size, min(base_size, scaled))

def safe_set(sheet, row, col, value, bold=None, font_size=None, align_horizontal=None, align_vertical='center', indent=None, shrink_to_fit=None):
    cell = sheet.cell(row=row, column=col)
    if not isinstance(cell, MergedCell):
        cell.value = value
        fname = cell.font.name if cell.font and cell.font.name else '새굴림'
        fsize = font_size if font_size is not None else (cell.font.size if cell.font and cell.font.size else 9.0)
        fbold = bold if bold is not None else (cell.font.bold if cell.font else False)
        fcolor = cell.font.color if cell.font and cell.font.color else None
        if bold is not None or font_size is not None:
            cell.font = Font(name=fname, size=fsize, bold=fbold, color=fcolor)
            
        if any(x is not None for x in [align_horizontal, align_vertical, indent, shrink_to_fit]):
            curr_h = align_horizontal if align_horizontal is not None else (cell.alignment.horizontal if cell.alignment else None)
            curr_v = align_vertical if align_vertical is not None else (cell.alignment.vertical if cell.alignment else 'center')
            curr_indent = indent if indent is not None else (cell.alignment.indent if cell.alignment else 0)
            curr_shrink = shrink_to_fit if shrink_to_fit is not None else (cell.alignment.shrink_to_fit if cell.alignment else False)
            cell.alignment = Alignment(horizontal=curr_h, vertical=curr_v, indent=curr_indent, shrink_to_fit=curr_shrink)

def build_photo_sheet(wb, t, photos):
    """
    Creates or configures '사진대지' sheet as Page 2
    """
    if '사진대지' in wb.sheetnames:
        sheet = wb['사진대지']
        # Clear existing
        wb.remove(sheet)
    sheet = wb.create_sheet('사진대지')
    
    # Page Setup matching A4 Portrait Duplex
    sheet.page_setup.orientation = sheet.ORIENTATION_PORTRAIT
    sheet.page_setup.paperSize = sheet.PAPERSIZE_A4
    sheet.page_setup.fitToWidth = 1
    sheet.page_setup.fitToHeight = 1
    sheet.sheet_properties.pageSetUpPr.fitToPage = True
    
    # Margins matching 정기1 (상단 편철 여백 25.0mm)
    sheet.page_margins.left = 0.51
    sheet.page_margins.right = 0.47
    sheet.page_margins.top = 0.984
    sheet.page_margins.bottom = 0.55
    sheet.page_margins.header = 0.31
    sheet.page_margins.footer = 0.39

    # Set column widths
    col_widths = {
        'A': 4, 'B': 10, 'C': 12, 'D': 10, 'E': 10, 'F': 10, 
        'G': 10, 'H': 10, 'I': 12, 'J': 10, 'K': 12, 'L': 6
    }
    for col_letter, width in col_widths.items():
        sheet.column_dimensions[col_letter].width = width

    thin_border = Border(
        left=Side(style='thin', color='A0AEC0'),
        right=Side(style='thin', color='A0AEC0'),
        top=Side(style='thin', color='A0AEC0'),
        bottom=Side(style='thin', color='A0AEC0')
    )
    thick_border = Border(
        left=Side(style='medium', color='2D3748'),
        right=Side(style='medium', color='2D3748'),
        top=Side(style='medium', color='2D3748'),
        bottom=Side(style='medium', color='2D3748')
    )
    header_fill = PatternFill(start_color="EDF2F7", end_color="EDF2F7", fill_type="solid")
    
    # 1. Title Banner
    sheet.merge_cells('A1:L2')
    title_cell = sheet['A1']
    title_cell.value = "안전보건교육 실시 사진대지"
    title_cell.font = Font(name='맑은 고딕', size=16, bold=True, color='1A202C')
    title_cell.alignment = Alignment(horizontal='center', vertical='center')

    # Watermark if demo
    if t['is_demo']:
        sheet['A3'] = "    [시연용 가명 데이터 - 제출 불가]"
        sheet['A3'].font = Font(name='맑은 고딕', size=9, bold=True, color='E53E3E')

    # 2. Metadata Table (Rows 4-6)
    meta_rows = [
        ('교육과목', t['subject'], '교육구분', t['training_type']),
        ('교육일시', f"{t['training_date']} {t['start_time']}~{t['end_time']}", '교육회차', f"{t['session_round']}회차 (총 {t['total_rounds']}회)"),
        ('교육강사', f"{t['instructor_name']} ({t['instructor_position'] or '담당'})", '교육장소', t['location'] or '현장 사무실'),
    ]

    for idx, (lbl1, val1, lbl2, val2) in enumerate(meta_rows):
        r = 4 + idx
        # Col 1: Label A..B
        sheet.merge_cells(start_row=r, start_column=1, end_row=r, end_column=2)
        c_lbl1 = sheet.cell(r, 1, lbl1)
        c_lbl1.font = Font(name='맑은 고딕', size=9, bold=True)
        c_lbl1.alignment = Alignment(horizontal='center', vertical='center')
        c_lbl1.fill = header_fill
        
        # Col 1: Value C..G
        sheet.merge_cells(start_row=r, start_column=3, end_row=r, end_column=7)
        c_val1 = sheet.cell(r, 3, val1)
        c_val1.font = Font(name='맑은 고딕', size=9)
        c_val1.alignment = Alignment(horizontal='left', vertical='center')
        
        # Col 2: Label H..I
        sheet.merge_cells(start_row=r, start_column=8, end_row=r, end_column=9)
        c_lbl2 = sheet.cell(r, 8, lbl2)
        c_lbl2.font = Font(name='맑은 고딕', size=9, bold=True)
        c_lbl2.alignment = Alignment(horizontal='center', vertical='center')
        c_lbl2.fill = header_fill
        
        # Col 2: Value J..L
        sheet.merge_cells(start_row=r, start_column=10, end_row=r, end_column=12)
        c_val2 = sheet.cell(r, 10, val2)
        c_val2.font = Font(name='맑은 고딕', size=9)
        c_val2.alignment = Alignment(horizontal='center', vertical='center')

        for col in range(1, 13):
            sheet.cell(r, col).border = thin_border

    # 3. Photo Frames (2 Photos layout)
    # Photo 1: Rows 8 to 21
    # Photo 2: Rows 23 to 36
    photo_frames = [
        {'title_row': 8, 'box_start': 9, 'box_end': 21, 'cell_coord': 'B9'},
        {'title_row': 23, 'box_start': 24, 'box_end': 36, 'cell_coord': 'B24'}
    ]

    selected_photos = [p for p in photos if p['is_print'] == 1]
    # Sort by display_order
    selected_photos.sort(key=lambda x: x['display_order'])

    temp_images_to_clean = []

    for idx, frame in enumerate(photo_frames):
        t_row = frame['title_row']
        b_start = frame['box_start']
        b_end = frame['box_end']
        coord = frame['cell_coord']
        
        # Title bar
        sheet.merge_cells(start_row=t_row, start_column=1, end_row=t_row, end_column=12)
        title_c = sheet.cell(t_row, 1)
        
        has_photo = idx < len(selected_photos)
        if has_photo:
            p_data = selected_photos[idx]
            cap = p_data['caption'] if p_data['caption'] else f"교육 실시 현장 사진 #{idx+1}"
            title_c.value = f"[사진 {idx+1}] {cap}"
        else:
            title_c.value = f"[사진 {idx+1}] (사진 미등록)"
            
        title_c.font = Font(name='맑은 고딕', size=9, bold=True)
        title_c.alignment = Alignment(horizontal='left', vertical='center')
        title_c.fill = header_fill
        for col in range(1, 13):
            sheet.cell(t_row, col).border = thin_border

        # Photo Box Border
        sheet.merge_cells(start_row=b_start, start_column=1, end_row=b_end, end_column=12)
        box_c = sheet.cell(b_start, 1)
        for r in range(b_start, b_end + 1):
            for c in range(1, 13):
                sheet.cell(r, c).border = thin_border

        if has_photo:
            p_data = selected_photos[idx]
            photo_file = os.path.join(UPLOADS_PHOTOS_DIR, p_data['stored_name'])
            if os.path.exists(photo_file):
                try:
                    # Process image with Pillow: EXIF rotation + aspect ratio fitting
                    pil_img = PILImage.open(photo_file)
                    pil_img = ImageOps.exif_transpose(pil_img)
                    
                    # Target bounding box (approx width 540px, height 260px)
                    target_w, target_h = 540, 260
                    cur_w, cur_h = pil_img.size
                    scale = min(target_w / cur_w, target_h / cur_h)
                    final_w = int(cur_w * scale)
                    final_h = int(cur_h * scale)
                    
                    # Save temporary resized image
                    temp_proc_name = f"proc_{idx}_{p_data['stored_name']}.png"
                    temp_proc_path = os.path.join(EXPORTS_DIR, temp_proc_name)
                    resized = pil_img.resize((final_w, final_h), PILImage.Resampling.LANCZOS)
                    resized.save(temp_proc_path)
                    temp_images_to_clean.append(temp_proc_path)
                    
                    # Embed into worksheet
                    xl_img = OpenpyxlImage(temp_proc_path)
                    xl_img.width = final_w
                    xl_img.height = final_h
                    sheet.add_image(xl_img, coord)
                except Exception as e:
                    box_c.value = f"사진 로드 오류: {e}"
                    box_c.alignment = Alignment(horizontal='center', vertical='center')
            else:
                box_c.value = "[사진 파일 없음]"
                box_c.alignment = Alignment(horizontal='center', vertical='center')
        else:
            box_c.value = "[사진 미등록 - 필요시 증빙사진을 부착하십시오]"
            box_c.font = Font(name='맑은 고딕', size=10, color='718096')
            box_c.alignment = Alignment(horizontal='center', vertical='center')

    # 4. Footer Note (Row 38)
    sheet.merge_cells('A38:L38')
    foot = sheet['A38']
    foot.value = "※ 본 사진대지는 안전보건교육 실시 증빙자료로 교육일지 뒷면에 양면 인쇄(긴 쪽 넘김)됩니다.    주식회사 현대기전"
    foot.font = Font(name='맑은 고딕', size=8, color='718096')
    foot.alignment = Alignment(horizontal='center', vertical='center')

    return temp_images_to_clean

def generate_export(training_id: int) -> dict:
    os.makedirs(EXPORTS_DIR, exist_ok=True)
    conn = sqlite3.connect(DB_PATH)
    conn.row_factory = sqlite3.Row
    c = conn.cursor()
    
    # Fetch Training
    c.execute("SELECT * FROM csm_trainings WHERE id = ?", (training_id,))
    t = c.fetchone()
    if not t:
        print(f"Error: Training {training_id} not found")
        sys.exit(1)
        
    # Fetch Attendees
    c.execute("SELECT * FROM csm_training_attendees WHERE training_id = ? ORDER BY id ASC", (training_id,))
    attendees = c.fetchall()

    # Fetch Photos
    c.execute("SELECT * FROM csm_training_photos WHERE training_id = ? ORDER BY display_order ASC, id ASC", (training_id,))
    photos = c.fetchall()
    
    conn.close()
    
    # Load Workbook
    wb = openpyxl.load_workbook(TEMPLATE_FILE)
    if '정기1' not in wb.sheetnames:
        print("Error: Sheet '정기1' not found in template")
        sys.exit(1)
    sheet = wb['정기1']
    # 상단 편철 여백 25.0mm (펀칭/바인더 여유 공간)
    sheet.page_margins.top = 0.984
    
    # 1. Date and Time (Row 4)
    try:
        dt = datetime.datetime.strptime(t['training_date'], '%Y-%m-%d')
        weekdays = ['월요일', '화요일', '수요일', '목요일', '금요일', '토요일', '일요일']
        w_str = weekdays[dt.weekday()]
        time_str = f"{t['start_time']}~{t['end_time']}" if t['start_time'] and t['end_time'] else "13:00~15:00"
        
        # Check if 2-day training (e.g. 16 hours or explicitly 2일간 in subject)
        if '2일간' in str(t['subject']) or t['training_no'] == 'EDU-2026-0118':
            dt2 = dt + datetime.timedelta(days=1)
            w_str2 = weekdays[dt2.weekday()]
            date_line = f"{dt.year}년   {dt.month:02d}월   {dt.day:02d}일({w_str[0]}) ~ {dt2.month:02d}월 {dt2.day:02d}일({w_str2[0]}) [2일간]   {time_str} (총 {int(t['planned_hours'])}시간)"
        else:
            date_line = f"{dt.year}년   {dt.month:02d}월   {dt.day:02d}일   {w_str} {time_str}"
        sheet['A4'] = date_line
    except Exception as e:
        sheet['A4'] = t['training_date']

    # 2. Training Types Checkbox formatting (including item 8. MSDS 교육)
    ttype = str(t['training_type']).upper()
    sheet['I5'] = "■" if ttype in ['NEW_HIRE', '신규채용', '신규채용시'] else "□"
    sheet['R5'] = "■" if ttype in ['REGULAR', '정기', '정기안전', '정기안전보건'] else "□"
    sheet['I6'] = "■" if ttype in ['JOB_CHANGE', '작업변경', '작업내용변경시'] else "□"
    sheet['R6'] = "■" if ttype in ['SUPERVISOR', '관리감독자', '관리감독자교육'] else "□"
    sheet['I7'] = "■" if ttype in ['SPECIAL', '특별안전', '특별교육'] else "□"
    sheet['R7'] = "■" if ttype in ['EXTERNAL', 'INVITED', '외부강사'] else "□"
    sheet['I8'] = "■" if ttype in ['OTHER', '기타'] else "□"
    
    # Item 8: MSDS 교육
    safe_set(sheet, 8, 13, "8. MSDS 교육")
    sheet['R8'] = "■" if ttype in ['MSDS', 'MSDS교육'] else "□"

    # Watermark if demo
    if t['is_demo']:
        sheet['A1'] = "    안전보건 교육일지 [시연용 가명 데이터 - 제출 불가]"

    # Company Name in Footer (Right-aligned)
    sheet.oddFooter.center.text = ""
    sheet.oddFooter.right.text = "&11 &\"맑은 고딕,Bold\" 주식회사 현대기전"

    # 3. Counts (Rows 10, 11, 12)
    sheet['A9'] = '교육실시\n인원' # 셀 밖 넘침 방지 (공백 제거)
    completed_att = [a for a in attendees if a['is_completed'] == 1]
    total_completed = len(completed_att)
    sheet['H10'] = total_completed # 전 근로자 수
    sheet['K10'] = total_completed # 남
    sheet['N10'] = 0               # 여
    
    sheet['H11'] = len(attendees)  # 대상 인원 수
    sheet['K11'] = len(attendees)
    sheet['N11'] = 0
    
    sheet['H12'] = total_completed # 교육실시 인원수
    sheet['K12'] = total_completed
    sheet['N12'] = 0
    
    # 4. Subject, Method, Hours, Location (Row 14)
    # Automatically scale down font size if text overflows cell width, and set shrink_to_fit=True
    subj_val = t['subject'] or ""
    subj_size = calc_auto_font_size(subj_val, cell_width_units=52.0, base_size=9.0, min_size=6.0)
    safe_set(sheet, 14, 4, subj_val, font_size=subj_size, align_horizontal='center', shrink_to_fit=True)
    
    meth_val = t['method'] or ""
    meth_size = calc_auto_font_size(meth_val, cell_width_units=39.0, base_size=9.0, min_size=6.0)
    safe_set(sheet, 14, 8, meth_val, font_size=meth_size, align_horizontal='center', shrink_to_fit=True)
    
    safe_set(sheet, 14, 11, f"{t['planned_hours']}HR", align_horizontal='center')
    safe_set(sheet, 14, 17, t['location'] if t['location'] else "현장 사무실", align_horizontal='center')
    
    # 5. Content Summary (Left: Rows 16 to 24 in Col 1, Right: Rows 15 to 24 in Col 11)
    # Ensure section title in A15 does not cling to the left grid line
    sheet['A15'].alignment = Alignment(horizontal='left', vertical='center', indent=1)
    
    # Clear all slots, ensuring consistent indent=1 (do not cling to left grid) and regular weight
    for r in range(16, 25):
        safe_set(sheet, r, 1, "", bold=False, align_horizontal='left', indent=1)
    for r in range(15, 25):
        safe_set(sheet, r, 11, "", bold=False, align_horizontal='left', indent=1)
        
    left_val = t['content_summary_left'] if ('content_summary_left' in t.keys() and t['content_summary_left']) else ""
    right_val = t['content_summary_right'] if ('content_summary_right' in t.keys() and t['content_summary_right']) else ""
    
    if not left_val and not right_val and t['content_summary']:
        raw_lines = [ln.strip() for ln in t['content_summary'].split('\n') if ln.strip()]
        half = (len(raw_lines) + 1) // 2
        left_lines = raw_lines[:max(4, half)]
        right_lines = raw_lines[max(4, half):]
    else:
        left_lines = [ln.strip() for ln in left_val.split('\n') if ln.strip()]
        right_lines = [ln.strip() for ln in right_val.split('\n') if ln.strip()]
        
    if ttype == 'MSDS' or t['msds_material_name']:
        msds_hdr = f"◈ MSDS 대상물질: {t['msds_material_name'] or '물질'} (자료ID: {t['msds_material_id'] or '-'}, 개정일: {t['msds_revision_date'] or '-'}, {t['msds_revision_count'] or '-'}차)"
        left_lines.insert(0, msds_hdr)
        
    def get_csm_line_indent(line_str: str) -> int:
        ln = line_str.strip()
        # Level 3 outline: 2.1.1, a., (a), etc.
        if re.match(r'^(\d+\.\d+\.\d+(\.|\))?|[a-z]\.|\([a-z]\))\s*', ln, re.I):
            return 3
        # Level 2 outline: 2.1, 2.1., 2.1), 1), (1), ①, etc. (2에서 2.1로 증가 시 들여쓰기)
        if re.match(r'^(\d+\.\d+(\.|\))?|\d+\)|\([0-9]+\)|[①-⑳])\s*', ln):
            return 2
        # Bullets: -, ·, *, ※
        if re.match(r'^([\-|\·|\*|※|★])\s*', ln):
            return 2
        # Level 1 or header
        return 1

    for i, line in enumerate(left_lines[:9]):
        ind = get_csm_line_indent(line)
        safe_set(sheet, 16 + i, 1, line.strip(), bold=False, align_horizontal='left', indent=ind)
    for i, line in enumerate(right_lines[:10]):
        ind = get_csm_line_indent(line)
        safe_set(sheet, 15 + i, 11, line.strip(), bold=False, align_horizontal='left', indent=ind)
        
    # 6. Evaluation Opinion (Row 26)
    # Row 25 in template already has "◈ 교육평가 및 의견 ◈", so Row 26 should ONLY contain the clean opinion text
    clean_opinion = (t['evaluation_opinion'] or "").replace("◈ 교육평가 및 의견 ◈", "").replace("◈", "").strip()
    safe_set(sheet, 26, 1, clean_opinion, bold=False)
        
    # 7. Instructor (Row 28)
    safe_set(sheet, 28, 5, "김 남 균")
    safe_set(sheet, 28, 10, t['instructor_position'] or "과장")
    safe_set(sheet, 28, 15, "")
    
    # 8. Attendees Grid (Rows 30 to 35)
    # COMPANY RULE: Signature column MUST REMAIN BLANK (공란) for hand signing after printing!
    grid_cols = [
        (3, 5),   # C: Name (C..D), E: Sign (E..G)
        (8, 10),  # H: Name (H..I), J: Sign (J..L)
        (13, 15), # M: Name (M..N), O: Sign (O..Q)
        (18, 20)  # R: Name (R..S), T: Sign (T..V)
    ]
    # Clear all 24 slots
    for r in range(30, 36):
        for c_name, c_sign in grid_cols:
            safe_set(sheet, r, c_name, "")
            safe_set(sheet, r, c_sign, "")
            
    # Populate actual attendee names (Signatures strictly BLANK)
    max_rows_per_col = 6
    for idx, att in enumerate(attendees):
        col_slot = idx // max_rows_per_col
        row_offset = idx % max_rows_per_col
        if col_slot < len(grid_cols):
            c_name, c_sign = grid_cols[col_slot]
            curr_row = 30 + row_offset
            clean_name = att['name_snapshot'].split('(')[0].strip()
            safe_set(sheet, curr_row, c_name, clean_name)
            # Company Rule: Keep Signature Blank! (Do NOT write "(서명완료)")
            safe_set(sheet, curr_row, c_sign, "")

    # Ensure Page 1 fit to 1x1
    sheet.page_setup.orientation = sheet.ORIENTATION_PORTRAIT
    sheet.page_setup.paperSize = sheet.PAPERSIZE_A4
    sheet.page_setup.fitToWidth = 1
    sheet.page_setup.fitToHeight = 1
    sheet.sheet_properties.pageSetUpPr.fitToPage = True

    # 9. Build Page 2: 사진대지 (Photo Board Sheet)
    temp_images = build_photo_sheet(wb, t, photos)

    # Save Output Excel
    t_no_safe = t['training_no'].replace('/', '_').replace('\\', '_')
    out_xlsx = os.path.join(EXPORTS_DIR, f"{t_no_safe}.xlsx")
    out_pdf = os.path.join(EXPORTS_DIR, f"{t_no_safe}.pdf")
    
    wb.save(out_xlsx)
    print(f"XLSX Generated: {out_xlsx} ({os.path.getsize(out_xlsx):,} bytes)")
    
    # Clean up temp resized images after Excel save
    for tmp in temp_images:
        try:
            if os.path.exists(tmp):
                os.remove(tmp)
        except Exception:
            pass

    # 10. Export unified 2-page PDF via Excel COM
    excel = None
    wb_com = None
    try:
        if os.path.exists(out_pdf):
            try:
                os.remove(out_pdf)
            except Exception:
                pass
        import win32com.client
        excel = win32com.client.Dispatch("Excel.Application")
        excel.Visible = False
        excel.DisplayAlerts = False
        wb_com = excel.Workbooks.Open(os.path.abspath(out_xlsx))
        # Select Page 1 (정기1) and Page 2 (사진대지) together
        wb_com.Sheets(['정기1', '사진대지']).Select()
        wb_com.ActiveSheet.ExportAsFixedFormat(0, os.path.abspath(out_pdf))
        wb_com.Close(False)
        excel.Quit()
        wb_com = None
        excel = None
        print(f"PDF Generated: {out_pdf} ({os.path.getsize(out_pdf):,} bytes)")
    except Exception as e:
        print(f"PDF COM Warning: {e}")
        out_pdf = None
    finally:
        if wb_com is not None:
            try:
                wb_com.Close(False)
            except Exception:
                pass
        if excel is not None:
            try:
                excel.Quit()
            except Exception:
                pass

    return {
        'ok': True,
        'xlsx': out_xlsx,
        'pdf': out_pdf,
        'training_no': t['training_no']
    }

def generate_batch_export(training_ids: list[int]) -> str:
    """
    Exports multiple trainings and stitches them into a unified PDF
    guaranteeing that each training has an even number of pages (Front: Log, Back: Photo)
    so that Long-Edge Duplex printing never pairs two different trainings on the same sheet!
    """
    import fitz # PyMuPDF
    
    individual_pdfs = []
    for tid in training_ids:
        res = generate_export(tid)
        if res.get('pdf') and os.path.exists(res['pdf']):
            individual_pdfs.append(res['pdf'])
            
    if not individual_pdfs:
        raise RuntimeError("생성된 PDF가 없습니다.")
        
    merged_doc = fitz.open()
    
    for pdf_path in individual_pdfs:
        doc = fitz.open(pdf_path)
        page_count = len(doc)
        
        # Insert pages of current training
        merged_doc.insert_pdf(doc)
        
        # If odd number of pages, pad with a blank A4 page to preserve duplex front/back pairing!
        if page_count % 2 != 0:
            # A4 dimensions in points: 595 x 842
            blank_page = merged_doc.new_page(width=595, height=842)
            # Add subtle blank note
            blank_page.insert_text((200, 420), "[양면 인쇄용 빈 페이지 / Blank Page for Duplex]", fontsize=9, color=(0.7, 0.7, 0.7))
            print(f"Padded odd-page training with blank page for duplex alignment.")

    total_pages = len(merged_doc)
    timestamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    out_batch_pdf = os.path.join(EXPORTS_DIR, f"CSM_BATCH_EXPORT_{timestamp}.pdf")
    merged_doc.save(out_batch_pdf)
    merged_doc.close()
    
    print(f"Batch Duplex PDF Generated: {out_batch_pdf} ({os.path.getsize(out_batch_pdf):,} bytes, Total Pages: {total_pages})")
    return out_batch_pdf

if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python export_worker.py <training_id> OR --batch <id1,id2,...>")
        sys.exit(1)
        
    if sys.argv[1] == '--batch':
        ids = [int(x.strip()) for x in sys.argv[2].split(',') if x.strip()]
        generate_batch_export(ids)
    else:
        generate_export(int(sys.argv[1]))
