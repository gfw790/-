package com.example.risksiteapp

import android.content.Context
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.RectF
import android.util.AttributeSet
import android.view.MotionEvent
import android.view.View
import kotlin.math.abs
import kotlin.math.atan2
import kotlin.math.cos
import kotlin.math.min
import kotlin.math.roundToInt
import kotlin.math.sin

class AnglePickerView @JvmOverloads constructor(
    context: Context,
    attrs: AttributeSet? = null
) : View(context, attrs) {
    var onAngleChanged: ((Int) -> Unit)? = null
    var onInteractionBlocked: (() -> Unit)? = null
    var isAngleInteractionEnabled: Boolean = true

    private var angleDegrees = 0f
    private var hasShownBlockedMessageForTouch = false
    private val density = resources.displayMetrics.density

    private val baseLinePaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.parseColor("#292524")
        strokeWidth = 6f * density
        strokeCap = Paint.Cap.ROUND
    }

    private val activeLinePaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.parseColor("#C2410C")
        strokeWidth = 8f * density
        strokeCap = Paint.Cap.ROUND
    }

    private val guideArcPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.parseColor("#FDBA74")
        style = Paint.Style.STROKE
        strokeWidth = 3f * density
    }

    private val centerDotPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.parseColor("#7C2D12")
        style = Paint.Style.FILL
    }

    private val labelPaint = Paint(Paint.ANTI_ALIAS_FLAG).apply {
        color = Color.parseColor("#1C1917")
        textAlign = Paint.Align.CENTER
        textSize = 20f * density
    }

    init {
        contentDescription = resources.getString(R.string.angle_picker_description)
    }

    override fun onDraw(canvas: Canvas) {
        super.onDraw(canvas)

        val centerX = width / 2f
        val centerY = height * 0.66f
        val lineLength = min(width, height) * 0.3f
        val arcRadius = lineLength * 0.52f

        canvas.drawLine(centerX - lineLength, centerY, centerX + lineLength, centerY, baseLinePaint)

        val radians = Math.toRadians(angleDegrees.toDouble())
        val armEndX = centerX + (cos(radians) * lineLength).toFloat()
        val armEndY = centerY - (sin(radians) * lineLength).toFloat()

        canvas.drawLine(centerX, centerY, armEndX, armEndY, activeLinePaint)

        val arcBounds = RectF(
            centerX - arcRadius,
            centerY - arcRadius,
            centerX + arcRadius,
            centerY + arcRadius
        )
        canvas.drawArc(arcBounds, -90f, 180f, false, guideArcPaint)
        canvas.drawCircle(centerX, centerY, 7f * density, centerDotPaint)
        canvas.drawCircle(armEndX, armEndY, 10f * density, activeLinePaint)

        canvas.drawText(
            resources.getString(R.string.angle_canvas_format, angleDegrees.roundToInt()),
            centerX,
            centerY - lineLength - (12f * density),
            labelPaint
        )

        canvas.drawText(
            resources.getString(R.string.angle_range_hint),
            centerX,
            centerY + (42f * density),
            labelPaint
        )
    }

    override fun onTouchEvent(event: MotionEvent): Boolean {
        if (!isAngleInteractionEnabled) {
            when (event.actionMasked) {
                MotionEvent.ACTION_DOWN -> {
                    hasShownBlockedMessageForTouch = true
                    onInteractionBlocked?.invoke()
                    return true
                }
                MotionEvent.ACTION_MOVE,
                MotionEvent.ACTION_UP,
                MotionEvent.ACTION_CANCEL -> {
                    if (event.actionMasked == MotionEvent.ACTION_UP ||
                        event.actionMasked == MotionEvent.ACTION_CANCEL
                    ) {
                        hasShownBlockedMessageForTouch = false
                    }
                    return true
                }
            }
        }

        when (event.actionMasked) {
            MotionEvent.ACTION_DOWN,
            MotionEvent.ACTION_MOVE -> {
                parent?.requestDisallowInterceptTouchEvent(true)
                updateAngle(event.x, event.y)
                return true
            }
            MotionEvent.ACTION_UP -> {
                parent?.requestDisallowInterceptTouchEvent(false)
                performClick()
                return true
            }
            MotionEvent.ACTION_CANCEL -> {
                parent?.requestDisallowInterceptTouchEvent(false)
                hasShownBlockedMessageForTouch = false
                return true
            }
        }
        return super.onTouchEvent(event)
    }

    override fun performClick(): Boolean {
        return super.performClick()
    }

    fun setAngle(angle: Int) {
        val clampedAngle = angle.coerceIn(-90, 90).toFloat()
        if (clampedAngle != angleDegrees) {
            angleDegrees = clampedAngle
            onAngleChanged?.invoke(angleDegrees.roundToInt())
            invalidate()
        }
    }

    fun getAngle(): Int = angleDegrees.roundToInt()

    private fun updateAngle(touchX: Float, touchY: Float) {
        val centerX = width / 2f
        val centerY = height * 0.66f
        val rawAngle = Math.toDegrees(
            atan2((centerY - touchY).toDouble(), (touchX - centerX).toDouble())
        ).toFloat()
        val normalizedAngle = if (abs(rawAngle) > 90f) {
            if (rawAngle > 0f) {
                90f
            } else {
                -90f
            }
        } else {
            rawAngle
        }
        val clampedAngle = normalizedAngle.coerceIn(-90f, 90f)

        if (clampedAngle != angleDegrees) {
            angleDegrees = clampedAngle
            onAngleChanged?.invoke(angleDegrees.roundToInt())
            invalidate()
        }
    }
}
