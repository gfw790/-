package com.example.risksiteapp

import android.app.Dialog
import android.os.Bundle
import android.text.Editable
import android.text.TextWatcher
import android.widget.ArrayAdapter
import android.widget.EditText
import android.widget.HorizontalScrollView
import android.widget.ImageButton
import android.widget.ImageView
import android.widget.ScrollView
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.textfield.MaterialAutoCompleteTextView
import com.google.android.material.textfield.TextInputLayout
import kotlin.math.abs
import kotlin.math.ceil
import kotlin.math.cos
import kotlin.math.floor
import kotlin.math.roundToInt
import kotlin.math.sin
import kotlin.math.tan

class TwoFoldActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_two_fold)

        val traySizeInput = findViewById<MaterialAutoCompleteTextView>(R.id.twoFoldTraySizeInput)
        val trayOptions = resources.getStringArray(R.array.tray_width_options)
        val guideImageView = findViewById<ImageView>(R.id.twoFoldGuideImageView)
        val parallelAngleInputLayout = findViewById<TextInputLayout>(R.id.parallelAngleInputLayout)
        val trayGapInputLayout = findViewById<TextInputLayout>(R.id.trayGapInputLayout)
        val parallelAngleInput = findViewById<EditText>(R.id.parallelAngleInput)
        val trayGapInput = findViewById<EditText>(R.id.trayGapInput)
        findViewById<ImageButton>(R.id.backButton).setOnClickListener { finish() }
        val finalAngleText = findViewById<TextView>(R.id.finalAngleValueText)
        val cutPoint1Text = findViewById<TextView>(R.id.cutPoint1ValueText)
        val cutPoint2Text = findViewById<TextView>(R.id.cutPoint2ValueText)
        val centerDistanceText = findViewById<TextView>(R.id.centerDistanceValueText)
        val horizontalReductionText = findViewById<TextView>(R.id.horizontalReductionValueText)
        val connectorWarningText = findViewById<TextView>(R.id.connectorWarningText)

        val connectorWingLength = 121.0
        val installationClearance = 10.0

        traySizeInput.setAdapter(
            ArrayAdapter(
                this,
                android.R.layout.simple_list_item_1,
                trayOptions
            )
        )
        traySizeInput.isSaveEnabled = false
        traySizeInput.setText("", false)

        fun readNumber(input: EditText): Double? {
            return input.text?.toString()?.trim()?.takeIf { it.isNotEmpty() }?.toDoubleOrNull()
        }

        fun selectedTraySize(): Double? {
            return traySizeInput.text?.toString()?.trim()?.takeIf { it.isNotEmpty() }?.toDoubleOrNull()
        }

        fun cutPoint(traySize: Double, angle: Double): Int {
            val radians = Math.toRadians(abs(angle) / 2.0)
            return (traySize * tan(radians)).roundToInt()
        }

        fun showGuideImageDialog() {
            val dialog = Dialog(this, android.R.style.Theme_Black_NoTitleBar_Fullscreen)
            val scrollView = ScrollView(this).apply {
                setBackgroundColor(0xCC000000.toInt())
            }
            val horizontalScrollView = HorizontalScrollView(this).apply {
                isFillViewport = true
            }
            val imageView = ImageView(this).apply {
                setImageResource(R.drawable.two_fold_guide_reference)
                adjustViewBounds = true
                contentDescription = getString(R.string.two_fold_guide_dialog_description)
                setPadding(24, 24, 24, 24)
                setOnClickListener { dialog.dismiss() }
            }

            horizontalScrollView.addView(imageView)
            scrollView.addView(horizontalScrollView)
            dialog.setContentView(scrollView)
            dialog.show()
        }

        fun updateResult() {
            val traySize = selectedTraySize()
            if (traySize == null) {
                parallelAngleInputLayout.error = null
                trayGapInputLayout.error = null
                finalAngleText.text = getString(R.string.two_fold_final_angle_default)
                cutPoint1Text.text = getString(R.string.two_fold_cut_default_1)
                cutPoint2Text.text = getString(R.string.two_fold_cut_default_2)
                centerDistanceText.text = getString(R.string.two_fold_center_distance_default)
                horizontalReductionText.text = getString(R.string.two_fold_horizontal_reduction_default)
                connectorWarningText.text = getString(R.string.two_fold_connector_warning_default)
                return
            }

            val angle = readNumber(parallelAngleInput)?.coerceIn(0.0, 90.0) ?: 0.0
            val trayGap = readNumber(trayGapInput)
            val cut = cutPoint(traySize, angle)
            val minimumGap = traySize - cut.toDouble()

            finalAngleText.text = getString(R.string.two_fold_final_angle_format, 180)
            cutPoint1Text.text = getString(
                R.string.two_fold_cut_sentence_1,
                cut
            )
            cutPoint2Text.text = getString(
                R.string.two_fold_cut_sentence_2,
                cut
            )

            if (trayGap != null && trayGap <= minimumGap) {
                trayGapInputLayout.error = getString(R.string.two_fold_gap_error)
                centerDistanceText.text = getString(R.string.two_fold_center_distance_default)
                horizontalReductionText.text = getString(R.string.two_fold_horizontal_reduction_default)
                connectorWarningText.text = getString(R.string.two_fold_connector_warning_invalid)
                return
            }

            trayGapInputLayout.error = null

            val rise = (trayGap ?: 0.0) - traySize + cut
            val centerDistanceRaw = if (trayGap != null && angle > 0.0 && rise > 0.0) {
                rise / sin(Math.toRadians(angle))
            } else {
                Double.NaN
            }
            val centerDistance = if (centerDistanceRaw.isFinite()) centerDistanceRaw.roundToInt() else 0
            centerDistanceText.text = getString(
                R.string.two_fold_center_distance_format,
                centerDistance
            )
            val horizontalReduction = if (centerDistanceRaw.isFinite()) {
                centerDistanceRaw * (1 - cos(Math.toRadians(abs(angle))))
            } else {
                Double.NaN
            }
            val horizontalReductionDisplay = if (horizontalReduction.isFinite()) {
                horizontalReduction.roundToInt()
            } else {
                0
            }
            horizontalReductionText.text = getString(
                R.string.two_fold_horizontal_reduction_format,
                horizontalReductionDisplay
            )
            connectorWarningText.text = when {
                !centerDistanceRaw.isFinite() || cut < 0 || centerDistanceRaw <= 0.0 -> {
                    getString(R.string.two_fold_connector_warning_invalid)
                }
                centerDistanceRaw - cut <= 0.0 -> {
                    getString(R.string.two_fold_connector_warning_overlap)
                }
                centerDistanceRaw - cut < connectorWingLength -> {
                    val shortage = connectorWingLength - (centerDistanceRaw - cut)
                    getString(
                        R.string.two_fold_connector_warning_shortage,
                        ceil(shortage).toInt()
                    )
                }
                centerDistanceRaw - cut < connectorWingLength + installationClearance -> {
                    val remaining = floor((centerDistanceRaw - cut) - connectorWingLength).toInt()
                    getString(R.string.two_fold_connector_warning_clearance, remaining)
                }
                else -> {
                    val spare = floor((centerDistanceRaw - cut) - (connectorWingLength + installationClearance)).toInt()
                    getString(R.string.two_fold_connector_warning_ok, spare)
                }
            }
        }

        val watcher = object : TextWatcher {
            override fun beforeTextChanged(s: CharSequence?, start: Int, count: Int, after: Int) = Unit
            override fun onTextChanged(s: CharSequence?, start: Int, before: Int, count: Int) = Unit
            override fun afterTextChanged(s: Editable?) {
                updateResult()
            }
        }

        listOf(parallelAngleInput, trayGapInput).forEach {
            it.addTextChangedListener(watcher)
        }

        guideImageView.setOnClickListener {
            showGuideImageDialog()
        }
        traySizeInput.setOnItemClickListener { _, _, _, _ -> updateResult() }
        updateResult()
    }
}
