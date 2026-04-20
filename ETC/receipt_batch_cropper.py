import base64
import os
import re
import shutil
import threading
from datetime import datetime
from dataclasses import dataclass
from typing import List, Optional, Tuple

import cv2
import numpy as np
import tkinter as tk
from tkinter import filedialog, messagebox, ttk

try:
    import pytesseract
except ImportError:
    pytesseract = None


ALLOWED_EXTENSIONS = {".jpg", ".jpeg", ".png", ".bmp", ".webp", ".tif", ".tiff"}
DEFAULT_OUTPUT_EXTENSION = ".jpg"
LABELED_TRANSACTION_DATE_PATTERN = re.compile(
    r"(?P<label>거래일자|거래일시|일자)\s*[<>{}\[\]():;=,_\-.]*\s*"
    r"(?P<year>\d{2,4})\s*[/.\-]\s*(?P<mm>\d{2})\s*[/.\-]\s*(?P<dd>\d{2})"
    r"(?:\s+(?P<hh>\d{2})\s*[:.]\s*(?P<mi>\d{2})(?:\s*[:.]\s*(?P<ss>\d{2}))?)?"
)
GENERIC_TRANSACTION_DATE_PATTERN = re.compile(
    r"(?<!\d)(?P<year>\d{2,4})\s*[/.\-]\s*(?P<mm>\d{2})\s*[/.\-]\s*(?P<dd>\d{2})"
    r"(?:\s+(?P<hh>\d{2})\s*[:.]\s*(?P<mi>\d{2})(?:\s*[:.]\s*(?P<ss>\d{2}))?)?(?!\d)"
)
TESSERACT_CANDIDATE_PATHS = [
    r"A:\Tesseract-OCR\tesseract.exe",
    r"C:\Program Files\Tesseract-OCR\tesseract.exe",
    r"C:\Program Files (x86)\Tesseract-OCR\tesseract.exe",
]
_TESSERACT_CONFIGURED = False


@dataclass
class CropOptions:
    margin: int = 0
    add_white_border: bool = False
    white_border_size: int = 20
    auto_rotate_vertical: bool = True
    save_failures_copy: bool = True
    min_area_ratio: float = 0.08
    max_candidates: int = 12


# -----------------------------
# Image processing helpers
# -----------------------------
def order_points(pts: np.ndarray) -> np.ndarray:
    rect = np.zeros((4, 2), dtype="float32")
    s = pts.sum(axis=1)
    rect[0] = pts[np.argmin(s)]  # top-left
    rect[2] = pts[np.argmax(s)]  # bottom-right

    diff = np.diff(pts, axis=1)
    rect[1] = pts[np.argmin(diff)]  # top-right
    rect[3] = pts[np.argmax(diff)]  # bottom-left
    return rect


def four_point_transform(image: np.ndarray, pts: np.ndarray) -> np.ndarray:
    rect = order_points(pts)
    (tl, tr, br, bl) = rect

    width_a = np.linalg.norm(br - bl)
    width_b = np.linalg.norm(tr - tl)
    max_width = max(int(width_a), int(width_b), 1)

    height_a = np.linalg.norm(tr - br)
    height_b = np.linalg.norm(tl - bl)
    max_height = max(int(height_a), int(height_b), 1)

    dst = np.array(
        [[0, 0], [max_width - 1, 0], [max_width - 1, max_height - 1], [0, max_height - 1]],
        dtype="float32",
    )

    m = cv2.getPerspectiveTransform(rect, dst)
    warped = cv2.warpPerspective(image, m, (max_width, max_height))
    return warped


def expand_quad_from_center(pts: np.ndarray, margin: float, img_shape: Tuple[int, int, int]) -> np.ndarray:
    h, w = img_shape[:2]
    center = pts.mean(axis=0)
    expanded = []
    for p in pts:
        vec = p - center
        norm = np.linalg.norm(vec)
        if norm == 0:
            expanded.append(p)
        else:
            expanded.append(p + (vec / norm) * margin)
    expanded = np.array(expanded, dtype=np.float32)
    expanded[:, 0] = np.clip(expanded[:, 0], 0, w - 1)
    expanded[:, 1] = np.clip(expanded[:, 1], 0, h - 1)
    return expanded


def preprocess_for_edges(image: np.ndarray) -> Tuple[np.ndarray, np.ndarray]:
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    gray = cv2.GaussianBlur(gray, (5, 5), 0)

    # Slight contrast boost to help boundary detection on pale backgrounds
    gray = cv2.convertScaleAbs(gray, alpha=1.15, beta=0)

    edges = cv2.Canny(gray, 60, 180)
    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (5, 5))
    edges = cv2.morphologyEx(edges, cv2.MORPH_CLOSE, kernel, iterations=2)
    edges = cv2.dilate(edges, kernel, iterations=1)
    return gray, edges


def scaled_kernel_size(length: int, divisor: int, minimum: int, maximum: int) -> int:
    size = max(minimum, min(length // divisor, maximum))
    if size % 2 == 0:
        size += 1
    return max(minimum, min(size, maximum))


def build_dark_content_mask(image: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    blur = cv2.GaussianBlur(gray, (5, 5), 0)

    bg_level = float(np.percentile(blur, 92))
    dark_cutoff = int(np.clip(bg_level - 18, 150, 235))
    return cv2.inRange(blur, 0, dark_cutoff)


def build_component_content_mask(image: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    blur = cv2.GaussianBlur(gray, (5, 5), 0)

    bg_level = float(np.percentile(blur, 90))
    dark_cutoff = int(np.clip(bg_level - 24, 110, 220))
    mask = cv2.inRange(blur, 0, dark_cutoff)

    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (3, 3))
    mask = cv2.morphologyEx(mask, cv2.MORPH_OPEN, kernel, iterations=1)
    return mask


def build_content_mask(image: np.ndarray) -> np.ndarray:
    h, w = image.shape[:2]
    dark_mask = build_dark_content_mask(image)
    close_kernel = cv2.getStructuringElement(
        cv2.MORPH_RECT,
        (
            scaled_kernel_size(w, divisor=24, minimum=17, maximum=51),
            scaled_kernel_size(h, divisor=24, minimum=17, maximum=51),
        ),
    )
    dilate_kernel = cv2.getStructuringElement(
        cv2.MORPH_RECT,
        (
            scaled_kernel_size(w, divisor=36, minimum=9, maximum=31),
            scaled_kernel_size(h, divisor=36, minimum=9, maximum=31),
        ),
    )

    merged = cv2.morphologyEx(dark_mask, cv2.MORPH_CLOSE, close_kernel, iterations=1)
    merged = cv2.dilate(merged, dilate_kernel, iterations=1)
    return merged


def smooth_projection(values: np.ndarray, window: int) -> np.ndarray:
    if window <= 1:
        return values.astype(np.float32)
    kernel = np.ones(window, dtype=np.float32) / float(window)
    return np.convolve(values.astype(np.float32), kernel, mode="same")


def find_dominant_span(
    profile: np.ndarray,
    relative_threshold: float,
    absolute_threshold: float,
    merge_gap: int,
) -> Optional[Tuple[int, int]]:
    if profile.size == 0:
        return None

    threshold = max(float(absolute_threshold), float(profile.max()) * float(relative_threshold))
    active = np.where(profile >= threshold)[0]
    if active.size == 0:
        return None

    spans = []
    start = prev = int(active[0])
    for index in active[1:]:
        index = int(index)
        if index - prev <= merge_gap:
            prev = index
            continue
        spans.append((start, prev))
        start = prev = index
    spans.append((start, prev))

    best_span = max(spans, key=lambda span: float(profile[span[0] : span[1] + 1].sum()))
    return best_span


def find_projection_content_bbox(
    image: np.ndarray,
    min_area_ratio: float = 0.0015,
    aspect_ratio_range: Tuple[float, float] = (0.08, 2.5),
) -> Optional[Tuple[int, int, int, int]]:
    h, w = image.shape[:2]
    dark_mask = build_dark_content_mask(image)
    if cv2.countNonZero(dark_mask) == 0:
        return None

    col_profile = smooth_projection(
        dark_mask.sum(axis=0) / 255.0,
        window=scaled_kernel_size(w, divisor=60, minimum=9, maximum=41),
    )
    col_span = find_dominant_span(
        col_profile,
        relative_threshold=0.20,
        absolute_threshold=3.0,
        merge_gap=max(8, w // 80),
    )
    if col_span is None:
        return None

    core_x0, core_x1 = col_span
    row_source_x0 = max(0, core_x0 - max(12, w // 40))
    row_source_x1 = min(w, core_x1 + max(12, w // 40) + 1)

    row_profile = smooth_projection(
        dark_mask[:, row_source_x0:row_source_x1].sum(axis=1) / 255.0,
        window=scaled_kernel_size(h, divisor=45, minimum=13, maximum=61),
    )
    row_span = find_dominant_span(
        row_profile,
        relative_threshold=0.08,
        absolute_threshold=3.0,
        merge_gap=max(18, h // 15),
    )
    if row_span is None:
        return None

    core_y0, core_y1 = row_span

    refined_col_profile = smooth_projection(
        dark_mask[core_y0 : core_y1 + 1, :].sum(axis=0) / 255.0,
        window=scaled_kernel_size(w, divisor=60, minimum=9, maximum=41),
    )
    refined_col_span = find_dominant_span(
        refined_col_profile,
        relative_threshold=0.12,
        absolute_threshold=3.0,
        merge_gap=max(8, w // 70),
    )
    if refined_col_span is not None:
        core_x0, core_x1 = refined_col_span

    content_w = max(1, core_x1 - core_x0 + 1)
    content_h = max(1, core_y1 - core_y0 + 1)
    pad_x = max(12, int(content_w * 0.14), int(w * 0.02))
    pad_y = max(12, int(content_h * 0.18), int(h * 0.02))

    x0 = max(0, core_x0 - pad_x)
    y0 = max(0, core_y0 - pad_y)
    x1 = min(w - 1, core_x1 + pad_x)
    y1 = min(h - 1, core_y1 + pad_y)

    bw = x1 - x0 + 1
    bh = y1 - y0 + 1
    if (bw * bh) < (w * h * min_area_ratio):
        return None

    aspect_ratio = bw / float(max(bh, 1))
    if not aspect_ratio_range[0] <= aspect_ratio <= aspect_ratio_range[1]:
        return None

    return x0, y0, bw, bh


def find_component_content_bbox(
    image: np.ndarray,
    min_area_ratio: float = 0.0015,
    aspect_ratio_range: Tuple[float, float] = (0.08, 2.5),
) -> Optional[Tuple[int, int, int, int]]:
    h, w = image.shape[:2]
    dark_mask = build_component_content_mask(image)
    if cv2.countNonZero(dark_mask) == 0:
        return None

    min_component_area = max(20, int(w * h * 0.00001))
    num_labels, _, stats, _ = cv2.connectedComponentsWithStats(dark_mask, connectivity=8)

    boxes = []
    for index in range(1, num_labels):
        x, y, bw, bh, area = map(int, stats[index])
        if area < min_component_area:
            continue
        if bw < 3 or bh < 5:
            continue
        if bw > w * 0.35 and bh < h * 0.03:
            continue
        if bh > h * 0.35 and bw < w * 0.02:
            continue
        boxes.append((x, y, bw, bh))

    if not boxes:
        return None

    x0 = min(x for x, _, _, _ in boxes)
    y0 = min(y for _, y, _, _ in boxes)
    x1 = max(x + bw for x, _, bw, _ in boxes)
    y1 = max(y + bh for _, y, _, bh in boxes)

    content_w = max(1, x1 - x0)
    content_h = max(1, y1 - y0)
    pad_x = max(12, int(content_w * 0.05), int(w * 0.012))
    pad_y = max(18, int(content_h * 0.10), int(h * 0.012))

    x0 = max(0, x0 - pad_x)
    y0 = max(0, y0 - pad_y)
    x1 = min(w, x1 + pad_x)
    y1 = min(h, y1 + pad_y)

    bw = x1 - x0
    bh = y1 - y0
    if (bw * bh) < (w * h * min_area_ratio):
        return None

    if bw > int(w * 0.9) and bh > int(h * 0.9):
        return None

    aspect_ratio = bw / float(max(bh, 1))
    if not aspect_ratio_range[0] <= aspect_ratio <= aspect_ratio_range[1]:
        return None

    return x0, y0, bw, bh


def find_content_bbox(
    image: np.ndarray,
    min_area_ratio: float = 0.0015,
    aspect_ratio_range: Tuple[float, float] = (0.08, 2.5),
) -> Optional[Tuple[int, int, int, int]]:
    component_bbox = find_component_content_bbox(
        image,
        min_area_ratio=min_area_ratio,
        aspect_ratio_range=aspect_ratio_range,
    )
    if component_bbox is not None:
        return component_bbox

    projection_bbox = find_projection_content_bbox(
        image,
        min_area_ratio=min_area_ratio,
        aspect_ratio_range=aspect_ratio_range,
    )
    if projection_bbox is not None:
        return projection_bbox

    h, w = image.shape[:2]
    merged = build_content_mask(image)
    if cv2.countNonZero(merged) == 0:
        return None

    contours, _ = cv2.findContours(merged, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    if not contours:
        return None

    img_area = h * w
    best_bbox = None
    best_score = -1.0

    for cnt in contours:
        x, y, bw, bh = cv2.boundingRect(cnt)
        area = bw * bh
        if area < img_area * min_area_ratio:
            continue

        aspect_ratio = bw / max(bh, 1)
        if not aspect_ratio_range[0] <= aspect_ratio <= aspect_ratio_range[1]:
            continue

        region = merged[y : y + bh, x : x + bw]
        fill_ratio = cv2.countNonZero(region) / float(max(area, 1))
        score = (area / float(img_area)) * 0.75 + fill_ratio * 0.25

        if score > best_score:
            best_score = score
            best_bbox = (x, y, bw, bh)

    return best_bbox


def find_profile_edge(
    profile: np.ndarray,
    threshold: float,
    from_start: bool,
    run_length: int = 5,
) -> Optional[int]:
    if profile.size < run_length:
        return None

    if from_start:
        index_range = range(0, profile.size - run_length + 1)
    else:
        index_range = range(profile.size - run_length, -1, -1)

    for index in index_range:
        segment = profile[index : index + run_length]
        if np.all(segment >= threshold):
            return index if from_start else index + run_length - 1
    return None


def trim_low_variance_borders(image: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY).astype(np.float32)
    h, w = gray.shape
    if h < 80 or w < 80:
        return image

    col_std = np.array(
        [gray[:, max(0, x - 3) : min(w, x + 4)].std() for x in range(w)],
        dtype=np.float32,
    )
    row_std = np.array(
        [gray[max(0, y - 3) : min(h, y + 4), :].std() for y in range(h)],
        dtype=np.float32,
    )

    col_profile = smooth_projection(
        col_std,
        window=scaled_kernel_size(w, divisor=25, minimum=15, maximum=61),
    )
    row_profile = smooth_projection(
        row_std,
        window=scaled_kernel_size(h, divisor=25, minimum=15, maximum=61),
    )

    center_profile = np.concatenate(
        [
            col_profile[w // 4 : (w * 3) // 4],
            row_profile[h // 4 : (h * 3) // 4],
        ]
    )
    if center_profile.size == 0:
        return image

    threshold = max(4.5, float(np.median(center_profile)) * 0.18)
    left = find_profile_edge(col_profile, threshold, from_start=True, run_length=5)
    right = find_profile_edge(col_profile, threshold, from_start=False, run_length=5)
    top = find_profile_edge(row_profile, threshold, from_start=True, run_length=5)
    bottom = find_profile_edge(row_profile, threshold, from_start=False, run_length=5)

    if None in {left, right, top, bottom}:
        return image

    pad = max(2, min(h, w) // 100)
    x0 = max(0, left - pad)
    y0 = max(0, top - pad)
    x1 = min(w, right + pad + 1)
    y1 = min(h, bottom + pad + 1)

    trimmed_w = x1 - x0
    trimmed_h = y1 - y0
    if trimmed_w < int(w * 0.55) or trimmed_h < int(h * 0.55):
        return image

    if trimmed_w >= w and trimmed_h >= h:
        return image

    return image[y0:y1, x0:x1]


def detect_uniform_edge_trim(
    gray: np.ndarray,
    axis: str,
    max_trim: int,
    min_mean: float,
    max_std: float,
) -> int:
    best_trim = 0
    h, w = gray.shape

    for trim in range(1, max_trim + 1):
        if axis == "right":
            strip = gray[:, w - trim :]
        else:
            strip = gray[h - trim :, :]

        if strip.size == 0:
            break

        if float(strip.mean()) >= min_mean and float(strip.std()) <= max_std:
            best_trim = trim

    return best_trim


def fine_tune_right_bottom_borders(image: np.ndarray) -> np.ndarray:
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY).astype(np.float32)
    h, w = gray.shape
    if h < 120 or w < 120:
        return image

    min_mean = max(220.0, float(np.percentile(gray, 85)) - 30.0)
    right_trim = detect_uniform_edge_trim(
        gray,
        axis="right",
        max_trim=max(1, min(4, w // 100)),
        min_mean=min_mean,
        max_std=10.0,
    )
    bottom_trim = detect_uniform_edge_trim(
        gray,
        axis="bottom",
        max_trim=max(2, min(6, h // 100)),
        min_mean=min_mean,
        max_std=8.0,
    )

    if right_trim == 0 and bottom_trim == 0:
        return image

    x1 = w - right_trim
    y1 = h - bottom_trim
    if x1 < int(w * 0.85) or y1 < int(h * 0.85):
        return image

    return image[:y1, :x1]


def score_quad(quad: np.ndarray, image_shape: Tuple[int, int, int]) -> float:
    h, w = image_shape[:2]
    area = cv2.contourArea(quad.astype(np.float32))
    if area <= 0:
        return -1

    area_ratio = area / float(w * h)
    if area_ratio < 0.03:
        return -1

    rect = order_points(quad.astype(np.float32))
    tl, tr, br, bl = rect
    width_top = np.linalg.norm(tr - tl)
    width_bottom = np.linalg.norm(br - bl)
    height_left = np.linalg.norm(bl - tl)
    height_right = np.linalg.norm(br - tr)

    width_consistency = 1.0 - min(abs(width_top - width_bottom) / max(width_top, width_bottom, 1), 1.0)
    height_consistency = 1.0 - min(abs(height_left - height_right) / max(height_left, height_right, 1), 1.0)

    bbox = cv2.boundingRect(quad.astype(np.int32))
    x, y, bw, bh = bbox
    border_penalty = 0.0
    if x <= 3 or y <= 3 or x + bw >= w - 3 or y + bh >= h - 3:
        border_penalty = 0.08

    rectangularity = area / max(bw * bh, 1)
    score = (area_ratio * 0.62) + (rectangularity * 0.22) + (width_consistency * 0.08) + (height_consistency * 0.08) - border_penalty
    return score


def score_candidate_quad(image: np.ndarray, quad: np.ndarray) -> float:
    base_score = score_quad(quad, image.shape)
    if base_score < 0:
        return base_score

    try:
        warped = four_point_transform(image, quad)
    except cv2.error:
        return base_score - 0.25

    if warped.shape[0] < 10 or warped.shape[1] < 10:
        return base_score - 0.25

    content_bbox = find_content_bbox(
        warped,
        min_area_ratio=0.0015,
        aspect_ratio_range=(0.08, 3.0),
    )
    if content_bbox is None:
        return base_score - 0.12

    x, y, bw, bh = content_bbox
    crop_area = warped.shape[0] * warped.shape[1]
    bbox_ratio = (bw * bh) / float(max(crop_area, 1))
    width_ratio = bw / float(max(warped.shape[1], 1))
    height_ratio = bh / float(max(warped.shape[0], 1))

    content_bonus = (bbox_ratio * 0.22) + (width_ratio * 0.08) + (height_ratio * 0.10)
    blank_penalty = 0.0
    if bbox_ratio < 0.55:
        blank_penalty += (0.55 - bbox_ratio) * 2.00
    if width_ratio < 0.72:
        blank_penalty += (0.72 - width_ratio) * 0.90
    if height_ratio < 0.72:
        blank_penalty += (0.72 - height_ratio) * 0.70

    return base_score + content_bonus - blank_penalty


def detect_content_based_quad(image: np.ndarray, min_area_ratio: float = 0.01) -> Optional[np.ndarray]:
    bbox = find_content_bbox(
        image,
        min_area_ratio=min_area_ratio,
        aspect_ratio_range=(0.12, 1.4),
    )
    if bbox is None:
        return None

    x, y, bw, bh = bbox
    return np.array(
        [[x, y], [x + bw, y], [x + bw, y + bh], [x, y + bh]],
        dtype=np.float32,
    )


def quad_bounding_box(quad: np.ndarray) -> Tuple[float, float, float, float]:
    ordered = order_points(quad.astype(np.float32))
    x_min = float(np.min(ordered[:, 0]))
    y_min = float(np.min(ordered[:, 1]))
    x_max = float(np.max(ordered[:, 0]))
    y_max = float(np.max(ordered[:, 1]))
    return x_min, y_min, x_max, y_max


def quad_iou(quad_a: np.ndarray, quad_b: np.ndarray) -> float:
    ax0, ay0, ax1, ay1 = quad_bounding_box(quad_a)
    bx0, by0, bx1, by1 = quad_bounding_box(quad_b)

    inter_x0 = max(ax0, bx0)
    inter_y0 = max(ay0, by0)
    inter_x1 = min(ax1, bx1)
    inter_y1 = min(ay1, by1)
    inter_w = max(0.0, inter_x1 - inter_x0)
    inter_h = max(0.0, inter_y1 - inter_y0)
    intersection = inter_w * inter_h
    if intersection <= 0.0:
        return 0.0

    area_a = max(0.0, (ax1 - ax0) * (ay1 - ay0))
    area_b = max(0.0, (bx1 - bx0) * (by1 - by0))
    union = area_a + area_b - intersection
    if union <= 0.0:
        return 0.0

    return intersection / union


def sort_receipt_quads_reading_order(quads: List[np.ndarray]) -> List[np.ndarray]:
    return sorted(
        [order_points(quad.astype(np.float32)) for quad in quads],
        key=lambda quad: (float(np.mean(quad[:, 1])), float(np.mean(quad[:, 0]))),
    )


def detect_receipt_quads(image: np.ndarray, min_area_ratio: float = 0.08, max_candidates: int = 12) -> List[np.ndarray]:
    h, w = image.shape[:2]
    gray, edges = preprocess_for_edges(image)
    img_area = h * w

    contours, _ = cv2.findContours(edges, cv2.RETR_LIST, cv2.CHAIN_APPROX_SIMPLE)
    contours = sorted(contours, key=cv2.contourArea, reverse=True)

    candidates: List[np.ndarray] = []

    for cnt in contours[: max_candidates * 8]:
        area = cv2.contourArea(cnt)
        if area < img_area * min_area_ratio:
            continue

        peri = cv2.arcLength(cnt, True)
        approx = cv2.approxPolyDP(cnt, 0.02 * peri, True)
        if len(approx) == 4:
            candidates.append(approx.reshape(4, 2).astype(np.float32))

    for cnt in contours[: max_candidates * 8]:
        area = cv2.contourArea(cnt)
        if area < img_area * min_area_ratio:
            continue
        rect = cv2.minAreaRect(cnt)
        box = cv2.boxPoints(rect)
        candidates.append(box.astype(np.float32))

    _, th = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
    kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (7, 7))
    th = cv2.morphologyEx(th, cv2.MORPH_CLOSE, kernel, iterations=2)
    contours2, _ = cv2.findContours(th, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    contours2 = sorted(contours2, key=cv2.contourArea, reverse=True)
    for cnt in contours2[: max_candidates * 4]:
        area = cv2.contourArea(cnt)
        if area < img_area * min_area_ratio:
            continue
        rect = cv2.minAreaRect(cnt)
        box = cv2.boxPoints(rect)
        candidates.append(box.astype(np.float32))

    content_quad = detect_content_based_quad(image, min_area_ratio=min(0.01, min_area_ratio))
    if content_quad is not None:
        candidates.append(content_quad)

    if not candidates:
        return []

    scored_candidates: List[Tuple[float, np.ndarray]] = []
    for quad in candidates:
        ordered = order_points(quad.astype(np.float32))
        score = score_candidate_quad(image, ordered)
        if score < 0:
            continue
        scored_candidates.append((score, ordered))

    if not scored_candidates:
        return []

    deduped: List[np.ndarray] = []
    for _, quad in sorted(scored_candidates, key=lambda item: item[0], reverse=True):
        if any(
            quad_iou(quad, existing) >= 0.72
            or np.allclose(quad, existing, atol=12.0)
            for existing in deduped
        ):
            continue
        deduped.append(quad)
        if len(deduped) >= min(max_candidates, 8):
            break

    return sort_receipt_quads_reading_order(deduped)


def detect_receipt_quad(image: np.ndarray, min_area_ratio: float = 0.08, max_candidates: int = 12) -> Optional[np.ndarray]:
    candidates = detect_receipt_quads(
        image,
        min_area_ratio=min_area_ratio,
        max_candidates=max_candidates,
    )
    if not candidates:
        return None

    best_quad = None
    best_score = -1.0
    for quad in candidates:
        score = score_candidate_quad(image, quad)
        if score > best_score:
            best_score = score
            best_quad = quad

    return best_quad


def trim_warped_receipt(image: np.ndarray) -> np.ndarray:
    h, w = image.shape[:2]
    content_bbox = find_content_bbox(
        image,
        min_area_ratio=0.0015,
        aspect_ratio_range=(0.05, 3.5),
    )
    if content_bbox is None:
        return image

    x, y, bw, bh = content_bbox
    pad_x = max(12, int(w * 0.04), int(bw * 0.08))
    pad_y = max(12, int(h * 0.03), int(bh * 0.06))

    x0 = max(0, x - pad_x)
    y0 = max(0, y - pad_y)
    x1 = min(w, x + bw + pad_x)
    y1 = min(h, y + bh + pad_y)

    if x0 == 0 and y0 == 0 and x1 == w and y1 == h:
        return image

    trimmed = image[y0:y1, x0:x1]
    if trimmed.shape[0] < int(h * 0.45) or trimmed.shape[1] < int(w * 0.30):
        return image

    return trimmed


def score_receipt_text_orientation(image: np.ndarray) -> float:
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    blur = cv2.GaussianBlur(gray, (5, 5), 0)
    dark_cutoff = int(np.clip(np.percentile(blur, 88) - 20, 90, 220))
    mask = cv2.inRange(blur, 0, dark_cutoff)
    if cv2.countNonZero(mask) == 0:
        h, w = image.shape[:2]
        return 0.1 if h >= w else 0.0

    row_profile = smooth_projection(
        mask.sum(axis=1) / 255.0,
        window=scaled_kernel_size(mask.shape[0], divisor=45, minimum=9, maximum=41),
    )
    col_profile = smooth_projection(
        mask.sum(axis=0) / 255.0,
        window=scaled_kernel_size(mask.shape[1], divisor=45, minimum=9, maximum=41),
    )

    row_std = float(np.std(row_profile))
    col_std = float(np.std(col_profile))
    h, w = image.shape[:2]
    portrait_bonus = 0.12 if h >= w else 0.0
    return (row_std - col_std) + portrait_bonus


def normalize_receipt_orientation(image: np.ndarray) -> np.ndarray:
    candidates = [
        image,
        cv2.rotate(image, cv2.ROTATE_90_CLOCKWISE),
        cv2.rotate(image, cv2.ROTATE_180),
        cv2.rotate(image, cv2.ROTATE_90_COUNTERCLOCKWISE),
    ]

    best_image = candidates[0]
    best_score = score_receipt_text_orientation(candidates[0])
    for candidate in candidates[1:]:
        score = score_receipt_text_orientation(candidate)
        if score > best_score:
            best_score = score
            best_image = candidate

    return best_image


def crop_receipt_from_quad(image: np.ndarray, quad: np.ndarray, options: CropOptions) -> np.ndarray:
    working_quad = order_points(quad.astype(np.float32))

    if options.margin > 0:
        working_quad = expand_quad_from_center(working_quad, options.margin, image.shape)

    warped = four_point_transform(image, working_quad)

    warped = trim_warped_receipt(warped)
    warped = trim_low_variance_borders(warped)
    warped = fine_tune_right_bottom_borders(warped)

    if options.auto_rotate_vertical:
        warped = normalize_receipt_orientation(warped)

    if options.add_white_border and options.white_border_size > 0:
        b = options.white_border_size
        warped = cv2.copyMakeBorder(
            warped, b, b, b, b, cv2.BORDER_CONSTANT, value=(255, 255, 255)
        )

    return warped


def crop_receipt(image: np.ndarray, options: CropOptions) -> Tuple[np.ndarray, bool]:
    quad = detect_receipt_quad(
        image,
        min_area_ratio=options.min_area_ratio,
        max_candidates=options.max_candidates,
    )

    if quad is None:
        return image.copy(), False

    return crop_receipt_from_quad(image, quad, options), True


def safe_output_path(output_dir: str, input_path: str, suffix: str = "_crop") -> str:
    base = os.path.basename(input_path)
    name, ext = os.path.splitext(base)
    ext = ".jpg" if ext.lower() not in {".jpg", ".jpeg", ".png", ".webp", ".bmp", ".tif", ".tiff"} else ext
    return os.path.join(output_dir, f"{name}{suffix}{ext}")


def configure_tesseract() -> bool:
    global _TESSERACT_CONFIGURED
    if pytesseract is None:
        return False

    if _TESSERACT_CONFIGURED:
        return True

    candidates = [path for path in TESSERACT_CANDIDATE_PATHS if os.path.exists(path)]
    path_from_env = shutil.which("tesseract")
    if path_from_env:
        candidates.append(path_from_env)

    for path in candidates:
        try:
            pytesseract.pytesseract.tesseract_cmd = path
            pytesseract.get_tesseract_version()
            _TESSERACT_CONFIGURED = True
            return True
        except Exception:
            continue

    return False


def generate_datetime_ocr_variants(image: np.ndarray) -> List[np.ndarray]:
    gray = cv2.cvtColor(image, cv2.COLOR_BGR2GRAY)
    up2 = cv2.resize(gray, None, fx=2, fy=2, interpolation=cv2.INTER_CUBIC)
    up2_th = cv2.threshold(up2, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)[1]
    adapt = cv2.adaptiveThreshold(
        up2,
        255,
        cv2.ADAPTIVE_THRESH_GAUSSIAN_C,
        cv2.THRESH_BINARY,
        31,
        11,
    )
    top_height = max(1, int(image.shape[0] * 0.45))
    top_gray = gray[:top_height, :]
    top_up2 = cv2.resize(top_gray, None, fx=2, fy=2, interpolation=cv2.INTER_CUBIC)
    top_up2_th = cv2.threshold(top_up2, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)[1]

    base_variants = [up2_th, gray, adapt, top_up2_th]
    rotated_variants: List[np.ndarray] = []
    for variant in base_variants:
        rotated_variants.append(variant)
        rotated_variants.append(cv2.rotate(variant, cv2.ROTATE_90_CLOCKWISE))
        rotated_variants.append(cv2.rotate(variant, cv2.ROTATE_90_COUNTERCLOCKWISE))

    return rotated_variants


def normalize_ocr_datetime_text(text: str) -> str:
    replacements = str.maketrans(
        {
            "O": "0",
            "o": "0",
            "I": "1",
            "l": "1",
            "|": "1",
            "：": ":",
            "．": ".",
            "／": "/",
            "〈": "<",
            "〉": ">",
            "《": "<",
            "》": ">",
        }
    )
    return text.translate(replacements)


def build_transaction_datetime_from_match(match: re.Match[str]) -> Optional[datetime]:
    year = int(match.group("year"))
    mm = int(match.group("mm"))
    dd = int(match.group("dd"))
    hh = int(match.group("hh") or 0)
    mi = int(match.group("mi") or 0)
    ss = int(match.group("ss") or 0)

    if year < 100:
        year += 2000

    if not (1 <= mm <= 12 and 1 <= dd <= 31):
        return None
    if not (0 <= hh <= 23 and 0 <= mi <= 59 and 0 <= ss <= 59):
        return None

    try:
        return datetime(year, mm, dd, hh, mi, ss)
    except ValueError:
        return None


def parse_transaction_datetime_candidate(text: str) -> Optional[datetime]:
    normalized = normalize_ocr_datetime_text(text)

    prioritized_matches = list(LABELED_TRANSACTION_DATE_PATTERN.finditer(normalized))
    if prioritized_matches:
        for match in prioritized_matches:
            candidate = build_transaction_datetime_from_match(match)
            if candidate is not None:
                return candidate

    fallback_matches = list(GENERIC_TRANSACTION_DATE_PATTERN.finditer(normalized))
    ranked_matches = sorted(
        fallback_matches,
        key=lambda match: (
            0 if len(match.group("year")) == 4 else 1,
            0 if match.group("hh") is not None else 1,
            match.start(),
        ),
    )
    for match in ranked_matches:
        candidate = build_transaction_datetime_from_match(match)
        if candidate is not None:
            return candidate

    return None


def extract_transaction_datetime(image: np.ndarray) -> Optional[datetime]:
    if not configure_tesseract():
        return None

    ocr_configs = [
        "--oem 1 --psm 6",
        "--oem 1 --psm 11",
    ]

    for variant in generate_datetime_ocr_variants(image):
        for ocr_config in ocr_configs:
            try:
                text = pytesseract.image_to_string(
                    variant,
                    lang="kor+eng",
                    config=ocr_config,
                    timeout=15,
                )
            except Exception:
                continue

            candidate = parse_transaction_datetime_candidate(text)
            if candidate is not None:
                return candidate

    return None


def make_unique_output_path(output_dir: str, base_name: str, ext: str = DEFAULT_OUTPUT_EXTENSION) -> str:
    safe_name = re.sub(r'[<>:"/\\|?*]+', "_", base_name).strip().strip(".")
    if not safe_name:
        safe_name = "receipt"

    candidate = os.path.join(output_dir, f"{safe_name}{ext}")
    suffix = 2
    while os.path.exists(candidate):
        candidate = os.path.join(output_dir, f"{safe_name}_{suffix}{ext}")
        suffix += 1
    return candidate


def build_receipt_output_path(output_dir: str, input_path: str, image: np.ndarray) -> Tuple[str, Optional[datetime]]:
    transaction_dt = extract_transaction_datetime(image)
    if transaction_dt is not None:
        return make_unique_output_path(
            output_dir,
            transaction_dt.strftime("%Y%m%d_%H%M%S"),
            DEFAULT_OUTPUT_EXTENSION,
        ), transaction_dt

    return safe_output_path(output_dir, input_path, "_crop"), None


def save_image(path: str, image: np.ndarray) -> None:
    ext = os.path.splitext(path)[1].lower()
    if ext == ".jpeg":
        ext = ".jpg"
    params = []
    if ext in {".jpg", ".jpeg"}:
        params = [cv2.IMWRITE_JPEG_QUALITY, 95]
    elif ext == ".png":
        params = [cv2.IMWRITE_PNG_COMPRESSION, 2]

    directory = os.path.dirname(path)
    if directory:
        os.makedirs(directory, exist_ok=True)

    ok, encoded = cv2.imencode(ext, image, params)
    if not ok:
        raise OSError(f"이미지 인코딩 실패: {path}")

    try:
        encoded.tofile(path)
    except OSError as exc:
        raise OSError(f"이미지 저장 실패: {path}") from exc


# -----------------------------
# GUI
# -----------------------------
class ReceiptPreviewDialog:
    def __init__(
        self,
        parent: tk.Tk,
        image: np.ndarray,
        quads: List[np.ndarray],
        options: CropOptions,
        source_name: str,
    ):
        self.parent = parent
        self.image = image
        self.options = options
        self.source_name = source_name
        self.cancelled = True
        self.confirmed_crops: List[np.ndarray] = []
        self.active_handle_index: Optional[int] = None
        self.canvas_photo: Optional[tk.PhotoImage] = None
        self.preview_photo: Optional[tk.PhotoImage] = None
        self.display_scale = 1.0
        self.offset_x = 0
        self.offset_y = 0

        h, w = image.shape[:2]
        default_quad = np.array([[0, 0], [w - 1, 0], [w - 1, h - 1], [0, h - 1]], dtype=np.float32)
        self.candidate_quads = [order_points(quad.astype(np.float32)) for quad in quads] or [default_quad]
        self.current_index = 0
        self.current_quad = self.candidate_quads[0].copy()

        self.window = tk.Toplevel(parent)
        self.window.title(f"영수증 미리보기 - {source_name}")
        self.window.geometry("1220x820")
        self.window.minsize(1040, 720)
        self.window.transient(parent)
        self.window.grab_set()
        self.window.protocol("WM_DELETE_WINDOW", self.cancel)

        self.title_var = tk.StringVar()
        self.hint_var = tk.StringVar()
        self.count_var = tk.StringVar()

        self._build_ui()
        self.window.update_idletasks()
        self.load_candidate(0)

    def _build_ui(self):
        outer = ttk.Frame(self.window, padding=10)
        outer.pack(fill="both", expand=True)

        header = ttk.Frame(outer)
        header.pack(fill="x", pady=(0, 8))
        ttk.Label(header, textvariable=self.title_var, font=("Malgun Gothic", 11, "bold")).pack(anchor="w")
        ttk.Label(header, textvariable=self.hint_var, foreground="#555").pack(anchor="w", pady=(4, 0))
        ttk.Label(header, textvariable=self.count_var, foreground="#1f3a5f").pack(anchor="w", pady=(2, 0))

        body = ttk.Panedwindow(outer, orient=tk.HORIZONTAL)
        body.pack(fill="both", expand=True)

        left = ttk.Frame(body, padding=(0, 0, 10, 0))
        right = ttk.Frame(body, padding=(10, 0, 0, 0))
        body.add(left, weight=5)
        body.add(right, weight=2)

        ttk.Label(left, text="원본 이미지에서 꼭지점을 드래그하여 경계를 확정하세요.").pack(anchor="w", pady=(0, 6))
        self.canvas = tk.Canvas(left, bg="#101820", highlightthickness=1, highlightbackground="#444")
        self.canvas.pack(fill="both", expand=True)
        self.canvas.bind("<Configure>", self.on_canvas_resize)
        self.canvas.bind("<ButtonPress-1>", self.on_mouse_down)
        self.canvas.bind("<B1-Motion>", self.on_mouse_drag)
        self.canvas.bind("<ButtonRelease-1>", self.on_mouse_up)

        ttk.Label(right, text="결과 미리보기").pack(anchor="w", pady=(0, 6))
        self.preview_label = ttk.Label(right, relief="solid", anchor="center")
        self.preview_label.pack(fill="both", expand=True)

        preview_help = (
            "- 사각형이 기울어져 있어도 결과는 직사각형으로 저장됩니다.\n"
            "- 영수증이 여러 장이면 하나 확정 후 다음 버튼으로 넘어갑니다.\n"
            "- 마지막 후보에서는 완료 버튼으로 저장 단계로 넘어갑니다."
        )
        ttk.Label(right, text=preview_help, justify="left", wraplength=260).pack(anchor="w", pady=(8, 0))

        foot = ttk.Frame(outer)
        foot.pack(fill="x", pady=(10, 0))
        ttk.Button(foot, text="현재 후보 초기화", command=self.reset_current_quad).pack(side="left")
        ttk.Button(foot, text="건너뛰기", command=self.skip_current).pack(side="left", padx=(8, 0))
        ttk.Button(foot, text="취소", command=self.cancel).pack(side="right")
        self.confirm_button = ttk.Button(foot, text="다음", command=self.confirm_current)
        self.confirm_button.pack(side="right", padx=(0, 8))

    def make_photoimage(self, image: np.ndarray, max_width: int, max_height: int) -> Tuple[tk.PhotoImage, float]:
        h, w = image.shape[:2]
        scale = min(max_width / max(w, 1), max_height / max(h, 1), 1.0)
        resized = image
        if scale < 1.0:
            resized = cv2.resize(image, None, fx=scale, fy=scale, interpolation=cv2.INTER_AREA)
        ok, encoded = cv2.imencode(".png", resized)
        if not ok:
            raise OSError("미리보기 이미지 인코딩 실패")
        data = base64.b64encode(encoded.tobytes()).decode("ascii")
        return tk.PhotoImage(data=data), scale

    def image_to_canvas(self, point: np.ndarray) -> Tuple[float, float]:
        x = float(point[0]) * self.display_scale + self.offset_x
        y = float(point[1]) * self.display_scale + self.offset_y
        return x, y

    def canvas_to_image(self, x: float, y: float) -> Tuple[float, float]:
        h, w = self.image.shape[:2]
        img_x = (x - self.offset_x) / max(self.display_scale, 1e-6)
        img_y = (y - self.offset_y) / max(self.display_scale, 1e-6)
        return float(np.clip(img_x, 0, w - 1)), float(np.clip(img_y, 0, h - 1))

    def load_candidate(self, index: int):
        self.current_index = index
        self.current_quad = self.candidate_quads[index].copy()
        total = len(self.candidate_quads)
        self.title_var.set(f"{self.source_name}  |  영수증 후보 {index + 1}/{total}")
        self.hint_var.set("꼭지점 4개를 영수증 모서리에 맞춘 뒤 다음 또는 완료를 누르세요.")
        self.count_var.set(f"현재까지 확정: {len(self.confirmed_crops)}건")
        self.confirm_button.configure(text="완료" if index >= total - 1 else "다음")
        self.render_canvas()
        self.update_preview()

    def render_canvas(self):
        if not self.canvas.winfo_exists():
            return
        canvas_w = max(200, self.canvas.winfo_width())
        canvas_h = max(200, self.canvas.winfo_height())
        self.canvas.delete("all")
        self.canvas_photo, self.display_scale = self.make_photoimage(self.image, canvas_w - 20, canvas_h - 20)
        display_w = self.canvas_photo.width()
        display_h = self.canvas_photo.height()
        self.offset_x = max(0, (canvas_w - display_w) // 2)
        self.offset_y = max(0, (canvas_h - display_h) // 2)
        self.canvas.create_image(self.offset_x, self.offset_y, anchor="nw", image=self.canvas_photo)

        points = [self.image_to_canvas(point) for point in self.current_quad]
        flat_points = [coord for point in points for coord in point]
        self.canvas.create_polygon(*flat_points, outline="#32c1ff", width=3, fill="#32c1ff", stipple="gray25")
        for index, (x, y) in enumerate(points):
            self.canvas.create_oval(x - 7, y - 7, x + 7, y + 7, fill="#ffb000", outline="#ffffff", width=2)
            self.canvas.create_text(x, y - 14, text=str(index + 1), fill="#ffffff", font=("Malgun Gothic", 9, "bold"))

    def update_preview(self):
        try:
            cropped = crop_receipt_from_quad(self.image, self.current_quad, self.options)
            self.preview_photo, _ = self.make_photoimage(cropped, 320, 560)
            self.preview_label.configure(image=self.preview_photo, text="")
        except Exception:
            self.preview_photo = None
            self.preview_label.configure(image="", text="미리보기 생성 실패")

    def on_canvas_resize(self, _event):
        self.render_canvas()

    def on_mouse_down(self, event):
        self.active_handle_index = None
        nearest_distance = float("inf")
        for index, point in enumerate(self.current_quad):
            canvas_x, canvas_y = self.image_to_canvas(point)
            distance = (canvas_x - event.x) ** 2 + (canvas_y - event.y) ** 2
            if distance <= 18 ** 2 and distance < nearest_distance:
                nearest_distance = distance
                self.active_handle_index = index

    def on_mouse_drag(self, event):
        if self.active_handle_index is None:
            return
        img_x, img_y = self.canvas_to_image(event.x, event.y)
        self.current_quad[self.active_handle_index] = [img_x, img_y]
        self.render_canvas()
        self.update_preview()

    def on_mouse_up(self, _event):
        self.active_handle_index = None

    def reset_current_quad(self):
        self.current_quad = self.candidate_quads[self.current_index].copy()
        self.render_canvas()
        self.update_preview()

    def confirm_current(self):
        try:
            cropped = crop_receipt_from_quad(self.image, self.current_quad, self.options)
        except Exception as exc:
            messagebox.showerror("오류", f"현재 꼭지점으로 영수증을 보정할 수 없습니다.\n{exc}", parent=self.window)
            return

        self.confirmed_crops.append(cropped)
        if self.current_index >= len(self.candidate_quads) - 1:
            self.cancelled = False
            self.window.destroy()
            return
        self.load_candidate(self.current_index + 1)

    def skip_current(self):
        if self.current_index >= len(self.candidate_quads) - 1:
            self.cancelled = False
            self.window.destroy()
            return
        self.load_candidate(self.current_index + 1)

    def cancel(self):
        self.cancelled = True
        self.window.destroy()


class ReceiptBatchCropperApp:
    def __init__(self, root: tk.Tk):
        self.root = root
        self.root.title("영수증 일괄 자동 크롭")
        self.root.geometry("900x650")

        self.files: List[str] = []
        self.output_dir = tk.StringVar()
        self.margin_var = tk.IntVar(value=0)
        self.border_var = tk.BooleanVar(value=False)
        self.border_size_var = tk.IntVar(value=20)
        self.rotate_var = tk.BooleanVar(value=True)
        self.copy_failures_var = tk.BooleanVar(value=True)
        self.progress_var = tk.DoubleVar(value=0)
        self.status_var = tk.StringVar(value="대기 중")
        self.is_processing = False

        self._build_ui()

    def _build_ui(self):
        top = ttk.Frame(self.root, padding=10)
        top.pack(fill="x")

        btn_frame = ttk.Frame(top)
        btn_frame.pack(fill="x")
        ttk.Button(btn_frame, text="파일 추가", command=self.add_files).pack(side="left", padx=4)
        ttk.Button(btn_frame, text="폴더에서 불러오기", command=self.add_folder).pack(side="left", padx=4)
        ttk.Button(btn_frame, text="목록 비우기", command=self.clear_files).pack(side="left", padx=4)
        ttk.Button(btn_frame, text="출력 폴더 선택", command=self.select_output_dir).pack(side="left", padx=4)

        output_frame = ttk.Frame(top)
        output_frame.pack(fill="x", pady=(10, 0))
        ttk.Label(output_frame, text="출력 폴더:").pack(side="left")
        ttk.Entry(output_frame, textvariable=self.output_dir).pack(side="left", fill="x", expand=True, padx=8)

        main = ttk.Panedwindow(self.root, orient=tk.HORIZONTAL)
        main.pack(fill="both", expand=True, padx=10, pady=10)
        left = ttk.Frame(main, padding=5)
        right = ttk.Frame(main, padding=5)
        main.add(left, weight=3)
        main.add(right, weight=2)

        ttk.Label(left, text="처리할 파일 목록").pack(anchor="w")
        self.file_listbox = tk.Listbox(left, selectmode=tk.EXTENDED)
        self.file_listbox.pack(fill="both", expand=True, pady=5)
        ttk.Button(left, text="선택 항목 제거", command=self.remove_selected).pack(anchor="e", pady=(0, 8))

        option_frame = ttk.LabelFrame(right, text="옵션", padding=10)
        option_frame.pack(fill="x")
        row1 = ttk.Frame(option_frame)
        row1.pack(fill="x", pady=4)
        ttk.Label(row1, text="영수증 바깥 여백(px)").pack(side="left")
        ttk.Spinbox(row1, from_=0, to=200, textvariable=self.margin_var, width=8).pack(side="right")
        ttk.Checkbutton(option_frame, text="잘라낸 결과에 흰 여백 추가", variable=self.border_var).pack(anchor="w", pady=4)
        row2 = ttk.Frame(option_frame)
        row2.pack(fill="x", pady=4)
        ttk.Label(row2, text="흰 여백(px)").pack(side="left")
        ttk.Spinbox(row2, from_=0, to=200, textvariable=self.border_size_var, width=8).pack(side="right")
        ttk.Checkbutton(option_frame, text="세로 방향으로 자동 회전", variable=self.rotate_var).pack(anchor="w", pady=4)
        ttk.Checkbutton(option_frame, text="실패한 파일도 원본 복사 저장", variable=self.copy_failures_var).pack(anchor="w", pady=4)

        help_frame = ttk.LabelFrame(right, text="설명", padding=10)
        help_frame.pack(fill="both", expand=True, pady=(10, 0))
        help_text = (
            "- 사진 여러 장을 넣으면 영수증 후보를 찾아 미리보기로 하나씩 확정합니다.\n"
            "- 꼭지점 4개를 드래그해서 영수증 경계를 직접 확정할 수 있습니다.\n"
            "- 후보가 여러 개면 다음 버튼으로 넘기고 마지막 후보에서는 완료합니다.\n"
            "- 사각형이 기울거나 왜곡되어도 저장 결과는 직사각형으로 보정됩니다.\n"
            "- 저장 파일명은 거래일자/거래일시를 읽어 YYYYMMDD_HHMMSS.jpg 형태로 만듭니다.\n"
            "- 거래일자, 거래일시, 일자 표기와 YYYY/MM/DD 형식 날짜를 인식합니다.\n"
            "- OCR로 거래일자/거래일시를 못 읽으면 원본 파일명_crop 으로 저장합니다."
        )
        ttk.Label(help_frame, text=help_text, justify="left", wraplength=260).pack(anchor="w")

        bottom = ttk.Frame(self.root, padding=(10, 0, 10, 10))
        bottom.pack(fill="x")
        self.progress = ttk.Progressbar(bottom, variable=self.progress_var, maximum=100)
        self.progress.pack(fill="x", pady=(0, 8))
        status_row = ttk.Frame(bottom)
        status_row.pack(fill="x")
        ttk.Label(status_row, textvariable=self.status_var).pack(side="left")
        self.run_button = ttk.Button(status_row, text="실행", command=self.start_processing)
        self.run_button.pack(side="right")

        log_frame = ttk.LabelFrame(self.root, text="처리 로그", padding=8)
        log_frame.pack(fill="both", expand=False, padx=10, pady=(0, 10))
        self.log_text = tk.Text(log_frame, height=10)
        self.log_text.pack(fill="both", expand=True)
        self.log_text.configure(state="disabled")

    def log(self, text: str):
        self.log_text.configure(state="normal")
        self.log_text.insert("end", text + "\n")
        self.log_text.see("end")
        self.log_text.configure(state="disabled")
        self.root.update_idletasks()

    def add_files(self):
        paths = filedialog.askopenfilenames(
            title="사진 파일 선택",
            filetypes=[("Image files", "*.jpg *.jpeg *.png *.bmp *.webp *.tif *.tiff")],
        )
        if not paths:
            return
        added = 0
        for path in paths:
            if path not in self.files:
                self.files.append(path)
                self.file_listbox.insert("end", path)
                added += 1
        self.log(f"파일 {added}개 추가")

    def add_folder(self):
        folder = filedialog.askdirectory(title="사진 폴더 선택")
        if not folder:
            return
        added = 0
        for name in sorted(os.listdir(folder)):
            path = os.path.join(folder, name)
            if not os.path.isfile(path):
                continue
            if os.path.splitext(name)[1].lower() not in ALLOWED_EXTENSIONS:
                continue
            if path not in self.files:
                self.files.append(path)
                self.file_listbox.insert("end", path)
                added += 1
        self.log(f"폴더에서 파일 {added}개 추가")

    def clear_files(self):
        self.files.clear()
        self.file_listbox.delete(0, "end")
        self.log("목록 비움")

    def remove_selected(self):
        selected = list(self.file_listbox.curselection())
        if not selected:
            return
        for idx in reversed(selected):
            self.file_listbox.delete(idx)
            del self.files[idx]
        self.log(f"선택 항목 {len(selected)}개 제거")

    def select_output_dir(self):
        folder = filedialog.askdirectory(title="출력 폴더 선택")
        if folder:
            self.output_dir.set(folder)
            self.log(f"출력 폴더 선택: {folder}")

    def start_processing(self):
        if self.is_processing:
            return
        if not self.files:
            messagebox.showwarning("알림", "먼저 처리할 사진 파일을 추가하세요.")
            return
        out_dir = self.output_dir.get().strip()
        if not out_dir:
            messagebox.showwarning("알림", "출력 폴더를 선택하세요.")
            return
        os.makedirs(out_dir, exist_ok=True)

        options = CropOptions(
            margin=max(0, self.margin_var.get()),
            add_white_border=self.border_var.get(),
            white_border_size=max(0, self.border_size_var.get()),
            auto_rotate_vertical=self.rotate_var.get(),
            save_failures_copy=self.copy_failures_var.get(),
        )

        self.is_processing = True
        self.run_button.configure(state="disabled")
        try:
            self.process_batch(self.files.copy(), out_dir, options)
        finally:
            self.is_processing = False
            self.run_button.configure(state="normal")

    def process_batch(self, files: List[str], out_dir: str, options: CropOptions):
        total = len(files)
        success = 0
        failed = 0
        cancelled = False
        self.progress_var.set(0)
        self.status_var.set("처리 시작")
        self.log("=== 처리 시작 ===")

        for index, path in enumerate(files, start=1):
            base_name = os.path.basename(path)
            self.status_var.set(f"미리보기 준비 중: {index}/{total} - {base_name}")
            self.root.update_idletasks()
            try:
                img = cv2.imdecode(np.fromfile(path, dtype=np.uint8), cv2.IMREAD_COLOR)
                if img is None:
                    raise ValueError("이미지를 읽을 수 없습니다.")

                quads = detect_receipt_quads(
                    img,
                    min_area_ratio=options.min_area_ratio,
                    max_candidates=options.max_candidates,
                )
                if not quads:
                    self.log(f"[안내] {base_name} - 자동 검출 후보가 없어 전체 화면 기준으로 수동 조정을 시작합니다.")

                self.status_var.set(f"미리보기 확인: {index}/{total} - {base_name}")
                dialog = ReceiptPreviewDialog(self.root, img, quads, options, base_name)
                self.root.wait_window(dialog.window)

                if dialog.cancelled:
                    cancelled = True
                    self.log("[중단] 사용자가 미리보기 단계에서 작업을 취소했습니다.")
                    break

                if not dialog.confirmed_crops:
                    failed += 1
                    if options.save_failures_copy:
                        fail_path = safe_output_path(out_dir, path, "_fail")
                        save_image(fail_path, img)
                        self.log(f"[실패] {base_name} -> {os.path.basename(fail_path)} (확정된 영수증 없음, 원본 저장)")
                    else:
                        self.log(f"[실패] {base_name} (확정된 영수증 없음)")
                    self.progress_var.set((index / total) * 100)
                    self.root.update_idletasks()
                    continue

                for receipt_index, cropped in enumerate(dialog.confirmed_crops, start=1):
                    save_path, transaction_dt = build_receipt_output_path(out_dir, path, cropped)
                    save_image(save_path, cropped)
                    success += 1
                    suffix = f" #{receipt_index}" if len(dialog.confirmed_crops) > 1 else ""
                    if transaction_dt is not None:
                        self.log(
                            f"[성공] {base_name}{suffix} -> {os.path.basename(save_path)} "
                            f"(거래일자/일시: {transaction_dt.strftime('%Y-%m-%d %H:%M:%S')})"
                        )
                    else:
                        self.log(
                            f"[성공] {base_name}{suffix} -> {os.path.basename(save_path)} "
                            "(거래일자/일시 OCR 실패, 기본 파일명 사용)"
                        )

            except Exception as exc:
                failed += 1
                self.log(f"[오류] {base_name} - {exc}")

            self.progress_var.set((index / total) * 100)
            self.root.update_idletasks()

        if cancelled:
            self.status_var.set(f"중단 - 성공 {success}개 / 실패 {failed}개")
            self.log("=== 사용자 중단 ===")
            self.log(f"성공: {success}개")
            self.log(f"실패: {failed}개")
            messagebox.showinfo("중단", f"작업이 중단되었습니다.\n성공: {success}개\n실패: {failed}개")
            return

        self.status_var.set(f"완료 - 성공 {success}개 / 실패 {failed}개")
        self.log("=== 처리 완료 ===")
        self.log(f"성공: {success}개")
        self.log(f"실패: {failed}개")
        messagebox.showinfo("완료", f"처리 완료\n성공: {success}개\n실패: {failed}개")


def main():
    root = tk.Tk()
    try:
        root.iconbitmap(default="")
    except Exception:
        pass
    app = ReceiptBatchCropperApp(root)
    root.mainloop()


if __name__ == "__main__":
    main()
