package com.example.risksiteapp

import android.content.Context
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.Path
import android.graphics.RectF
import android.util.AttributeSet
import android.util.TypedValue
import android.view.View
import kotlin.math.cos
import kotlin.math.sin

class TwoFoldGuideView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null
) : View(context, attrs) {

    private val density = resources.displayMetrics.density

    private val backgroundPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.parseColor("#F4E4D6")
        style = Paint.Style.FILL
    }

    private val panelPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.parseColor("#FFFDFB")
        style = Paint.Style.FILL
    }

    private val trayPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.parseColor("#2F2A25")
        style = Paint.Style.STROKE
        strokeWidth = dp(2f)
    }

    private val guidePaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.parseColor("#7C5E47")
        style = Paint.Style.STROKE
        strokeWidth = dp(1.4f)
    }

    private val accentPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.parseColor("#E24B2D")
        style = Paint.Style.STROKE
        strokeWidth = dp(1.8f)
    }

    private val labelPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.parseColor("#3B3028")
        textSize = sp(11f)
    }

    private val accentLabelPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.parseColor("#C2410C")
        textSize = sp(11f)
        isFakeBoldText = true
    }

    override fun onMeasure(widthMeasureSpec: Int, heightMeasureSpec: Int) {
        val desiredHeight = dp(360f).toInt()
        setMeasuredDimension(
            resolveSize(suggestedMinimumWidth, widthMeasureSpec),
            resolveSize(desiredHeight, heightMeasureSpec)
        )
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)

        val outer = RectF(0f, 0f, width.toFloat(), height.toFloat())
        canvas.drawRoundRect(outer, dp(26f), dp(26f), backgroundPaint)

        val topRect = RectF(dp(12f), dp(12f), width - dp(12f), height * 0.46f)
        val bottomRect = RectF(dp(12f), height * 0.52f, width - dp(12f), height - dp(12f))

        canvas.drawRoundRect(topRect, dp(18f), dp(18f), panelPaint)
        canvas.drawRoundRect(bottomRect, dp(18f), dp(18f), panelPaint)

        drawTopDiagram(canvas, topRect)
        drawBottomDiagram(canvas, bottomRect)
    }

    private fun drawTopDiagram(canvas: Canvas, rect: RectF) {
        canvas.drawText("1. 절단 위치 안내", rect.left + dp(12f), rect.top + dp(18f), accentLabelPaint)

        val leftX = rect.left + dp(26f)
        val lowerY = rect.bottom - dp(42f)
        val shelfY = rect.top + dp(56f)
        val center1X = rect.left + rect.width() * 0.42f
        val center2X = rect.left + rect.width() * 0.73f
        val rightX = rect.right - dp(18f)
        val topY = rect.top + dp(26f)

        val shape = Path().apply {
            moveTo(leftX, lowerY)
            lineTo(leftX, shelfY)
            lineTo(center1X, shelfY)
            lineTo(center1X, topY + dp(18f))
            lineTo(rightX, topY + dp(18f))
            lineTo(rightX, topY)
            lineTo(center1X + dp(34f), topY)
        }
        canvas.drawPath(shape, trayPaint)

        canvas.drawLine(center1X, rect.bottom - dp(18f), center1X, shelfY - dp(2f), accentPaint)
        canvas.drawLine(center2X, rect.bottom - dp(18f), center2X, topY + dp(18f), accentPaint)

        drawHorizontalDimension(
            canvas = canvas,
            x1 = center1X,
            x2 = center2X,
            y = shelfY + dp(28f),
            label = context.getString(R.string.guide_center_distance)
        )

        drawVerticalCutLabel(
            canvas = canvas,
            x = center1X,
            yTop = rect.top + dp(12f),
            yBottom = shelfY - dp(6f),
            label = context.getString(R.string.guide_cut_result_1),
            leftSide = true
        )

        drawVerticalCutLabel(
            canvas = canvas,
            x = center2X,
            yTop = shelfY + dp(2f),
            yBottom = topY + dp(18f),
            label = context.getString(R.string.guide_cut_result_2),
            leftSide = false
        )

        drawPointerLabel(
            canvas = canvas,
            fromX = center1X,
            fromY = rect.bottom - dp(18f),
            toX = center1X - dp(42f),
            toY = rect.bottom - dp(6f),
            label = context.getString(R.string.guide_center_point_1)
        )

        drawPointerLabel(
            canvas = canvas,
            fromX = center2X,
            fromY = rect.bottom - dp(18f),
            toX = center2X + dp(8f),
            toY = rect.bottom - dp(6f),
            label = context.getString(R.string.guide_center_point_2)
        )
    }

    private fun drawBottomDiagram(canvas: Canvas, rect: RectF) {
        canvas.drawText("2. 입력값 안내", rect.left + dp(12f), rect.top + dp(18f), accentLabelPaint)

        val lowerLeft = rect.left + dp(28f)
        val lowerRight = rect.left + rect.width() * 0.42f
        val upperLeft = rect.left + rect.width() * 0.56f
        val upperRight = rect.right - dp(18f)

        val lowerBottom = rect.bottom - dp(26f)
        val lowerTop = lowerBottom - dp(38f)
        val upperBottom = rect.top + dp(72f)
        val upperTop = upperBottom - dp(38f)

        val trayPath = Path().apply {
            moveTo(lowerLeft, lowerBottom)
            lineTo(lowerLeft, lowerTop)
            lineTo(lowerRight, lowerTop)
            lineTo(upperLeft, upperBottom)
            lineTo(upperRight, upperBottom)
            lineTo(upperRight, upperTop)
            lineTo(upperLeft - dp(24f), upperTop)
            lineTo(lowerRight - dp(18f), lowerBottom)
            close()
        }
        canvas.drawPath(trayPath, trayPaint)

        drawVerticalDimension(
            canvas = canvas,
            x = lowerLeft - dp(18f),
            y1 = lowerTop,
            y2 = lowerBottom,
            label = context.getString(R.string.guide_tray_size)
        )

        drawVerticalDimension(
            canvas = canvas,
            x = upperRight + dp(18f),
            y1 = upperTop,
            y2 = lowerBottom,
            label = context.getString(R.string.guide_tray_gap)
        )

        drawAngleArc(
            canvas = canvas,
            cx = lowerRight - dp(10f),
            cy = lowerBottom - dp(2f),
            radius = dp(34f),
            startDegrees = -96f,
            sweepDegrees = 44f,
            label = context.getString(R.string.guide_angle_label_1),
            labelX = rect.left + dp(16f),
            labelY = rect.top + dp(76f)
        )

        drawAngleArc(
            canvas = canvas,
            cx = upperLeft + dp(10f),
            cy = upperBottom + dp(4f),
            radius = dp(30f),
            startDegrees = 84f,
            sweepDegrees = -38f,
            label = context.getString(R.string.guide_angle_label_2),
            labelX = rect.left + rect.width() * 0.64f,
            labelY = rect.top + dp(110f)
        )
    }

    private fun drawHorizontalDimension(
        canvas: Canvas,
        x1: Float,
        x2: Float,
        y: Float,
        label: String
    ) {
        canvas.drawLine(x1, y, x2, y, accentPaint)
        canvas.drawLine(x1, y - dp(6f), x1, y + dp(6f), accentPaint)
        canvas.drawLine(x2, y - dp(6f), x2, y + dp(6f), accentPaint)
        drawArrowHead(canvas, x1, y, 0f, accentPaint)
        drawArrowHead(canvas, x2, y, 180f, accentPaint)
        canvas.drawText(label, x1 + dp(8f), y - dp(8f), accentLabelPaint)
    }

    private fun drawVerticalCutLabel(
        canvas: Canvas,
        x: Float,
        yTop: Float,
        yBottom: Float,
        label: String,
        leftSide: Boolean
    ) {
        canvas.drawLine(x, yTop, x, yBottom, accentPaint)
        val textX = if (leftSide) x - dp(64f) else x + dp(8f)
        val textY = yTop + (yBottom - yTop) * 0.45f
        canvas.drawText(label, textX, textY, accentLabelPaint)
    }

    private fun drawPointerLabel(
        canvas: Canvas,
        fromX: Float,
        fromY: Float,
        toX: Float,
        toY: Float,
        label: String
    ) {
        canvas.drawLine(fromX, fromY, toX, toY, guidePaint)
        canvas.drawText(label, toX + dp(4f), toY + dp(2f), labelPaint)
    }

    private fun drawVerticalDimension(
        canvas: Canvas,
        x: Float,
        y1: Float,
        y2: Float,
        label: String
    ) {
        canvas.drawLine(x, y1, x, y2, guidePaint)
        canvas.drawLine(x - dp(5f), y1, x + dp(5f), y1, guidePaint)
        canvas.drawLine(x - dp(5f), y2, x + dp(5f), y2, guidePaint)
        drawArrowHead(canvas, x, y1, 90f, guidePaint)
        drawArrowHead(canvas, x, y2, -90f, guidePaint)
        canvas.save()
        val pivotY = (y1 + y2) / 2f
        canvas.rotate(-90f, x - dp(10f), pivotY)
        canvas.drawText(label, x - dp(10f), pivotY, labelPaint)
        canvas.restore()
    }

    private fun drawAngleArc(
        canvas: Canvas,
        cx: Float,
        cy: Float,
        radius: Float,
        startDegrees: Float,
        sweepDegrees: Float,
        label: String,
        labelX: Float,
        labelY: Float
    ) {
        val rect = RectF(cx - radius, cy - radius, cx + radius, cy + radius)
        canvas.drawArc(rect, startDegrees, sweepDegrees, false, guidePaint)
        val endDegrees = startDegrees + sweepDegrees
        val endRadians = Math.toRadians(endDegrees.toDouble())
        val endX = cx + radius * cos(endRadians).toFloat()
        val endY = cy + radius * sin(endRadians).toFloat()
        drawArrowHead(canvas, endX, endY, endDegrees + if (sweepDegrees < 0) -90f else 90f, guidePaint)
        canvas.drawText(label, labelX, labelY, labelPaint)
    }

    private fun drawArrowHead(canvas: Canvas, x: Float, y: Float, degrees: Float, paint: Paint) {
        val size = dp(6f)
        val radians = Math.toRadians(degrees.toDouble())
        val leftRadians = radians + Math.toRadians(150.0)
        val rightRadians = radians - Math.toRadians(150.0)
        canvas.drawLine(
            x,
            y,
            x + size * cos(leftRadians).toFloat(),
            y + size * sin(leftRadians).toFloat(),
            paint
        )
        canvas.drawLine(
            x,
            y,
            x + size * cos(rightRadians).toFloat(),
            y + size * sin(rightRadians).toFloat(),
            paint
        )
    }

    private fun dp(value: Float): Float = value * density

    private fun sp(value: Float): Float {
        return TypedValue.applyDimension(
            TypedValue.COMPLEX_UNIT_SP,
            value,
            resources.displayMetrics
        )
    }
}
