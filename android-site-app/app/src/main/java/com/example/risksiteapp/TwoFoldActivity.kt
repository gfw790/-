package com.example.risksiteapp

import android.app.Dialog
import android.os.Bundle
import android.text.Editable
import android.text.TextWatcher
import android.widget.ArrayAdapter
import android.widget.EditText
import android.widget.HorizontalScrollView
import android.widget.ImageView
import android.widget.ScrollView
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.textfield.MaterialAutoCompleteTextView
import com.google.android.material.textfield.TextInputLayout
import kotlin.math.abs
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
        val finalAngleText = findViewById<TextView>(R.id.finalAngleValueText)
        val cutPoint1Text = findViewById<TextView>(R.id.cutPoint1ValueText)
        val cutPoint2Text = findViewById<TextView>(R.id.cutPoint2ValueText)
        val centerDistanceText = findViewById<TextView>(R.id.centerDistanceValueText)

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
                return
            }

            val angle = readNumber(parallelAngleInput)?.coerceIn(0.0, 90.0) ?: 0.0
            val trayGap = readNumber(trayGapInput)
            val minimumGap = traySize * 2.0

            finalAngleText.text = getString(R.string.two_fold_final_angle_format, 180)
            cutPoint1Text.text = getString(
                R.string.two_fold_cut_sentence_1,
                cutPoint(traySize, angle)
            )
            cutPoint2Text.text = getString(
                R.string.two_fold_cut_sentence_2,
                cutPoint(traySize, angle)
            )

            if (trayGap != null && trayGap <= minimumGap) {
                trayGapInputLayout.error = getString(R.string.two_fold_gap_error)
                centerDistanceText.text = getString(R.string.two_fold_center_distance_default)
                return
            }

            trayGapInputLayout.error = null

            val rise = (trayGap ?: 0.0) - (traySize * 2.0)
            val centerDistance = if (trayGap != null && angle > 0.0 && rise > 0.0) {
                (rise / sin(Math.toRadians(angle))).roundToInt()
            } else {
                0
            }
            centerDistanceText.text = getString(
                R.string.two_fold_center_distance_format,
                centerDistance
            )
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
