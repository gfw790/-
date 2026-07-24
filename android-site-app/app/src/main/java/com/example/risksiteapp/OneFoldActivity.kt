package com.example.risksiteapp

import android.os.Bundle
import android.text.InputType
import android.widget.ArrayAdapter
import android.widget.EditText
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import com.google.android.material.textfield.MaterialAutoCompleteTextView
import kotlin.math.abs
import kotlin.math.roundToInt
import kotlin.math.tan

class OneFoldActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_one_fold)

        val angleValueText = findViewById<TextView>(R.id.angleValueText)
        val resultValueText = findViewById<TextView>(R.id.resultValueText)
        val anglePickerView = findViewById<AnglePickerView>(R.id.anglePickerView)
        val trayWidthInput = findViewById<MaterialAutoCompleteTextView>(R.id.trayWidthInput)
        val trayWidthOptions = resources.getStringArray(R.array.tray_width_options)
        var currentAngle = 0

        trayWidthInput.setAdapter(
            ArrayAdapter(
                this,
                android.R.layout.simple_list_item_1,
                trayWidthOptions
            )
        )
        trayWidthInput.isSaveEnabled = false
        trayWidthInput.setText("", false)
        anglePickerView.setAngle(0)
        angleValueText.text = getString(R.string.angle_value_default)

        fun hasTrayWidthSelection(): Boolean {
            return trayWidthInput.text?.toString()?.isNotBlank() == true
        }

        fun showTrayRequiredMessage() {
            Toast.makeText(this, R.string.tray_width_required_message, Toast.LENGTH_SHORT).show()
        }

        fun syncAngleInteractionState() {
            anglePickerView.isAngleInteractionEnabled = hasTrayWidthSelection()
        }

        fun updateResult() {
            if (!hasTrayWidthSelection()) {
                resultValueText.text = getString(R.string.result_value_default)
                return
            }
            val trayWidth = trayWidthInput.text?.toString()?.toDoubleOrNull() ?: 0.0
            val angleRadians = Math.toRadians(abs(currentAngle) / 2.0)
            val result = trayWidth * tan(angleRadians)
            resultValueText.text = getString(R.string.result_sentence_format, result.roundToInt())
        }

        anglePickerView.onInteractionBlocked = {
            showTrayRequiredMessage()
        }

        trayWidthInput.setOnItemClickListener { _, _, _, _ ->
            syncAngleInteractionState()
            updateResult()
        }

        anglePickerView.onAngleChanged = { angle ->
            currentAngle = angle
            angleValueText.text = getString(R.string.angle_value_format, angle)
            updateResult()
        }

        angleValueText.setOnClickListener {
            if (!hasTrayWidthSelection()) {
                showTrayRequiredMessage()
                return@setOnClickListener
            }

            val input = EditText(this).apply {
                inputType = InputType.TYPE_CLASS_NUMBER or InputType.TYPE_NUMBER_FLAG_SIGNED
                setText(currentAngle.toString())
                setSelection(text?.length ?: 0)
                hint = getString(R.string.angle_input_hint)
            }

            MaterialAlertDialogBuilder(this)
                .setTitle(R.string.angle_input_title)
                .setMessage(R.string.angle_input_message)
                .setView(input)
                .setPositiveButton(R.string.angle_input_confirm) { _, _ ->
                    val enteredAngle = input.text?.toString()?.toIntOrNull()
                    if (enteredAngle != null) {
                        val clampedAngle = enteredAngle.coerceIn(-90, 90)
                        anglePickerView.setAngle(clampedAngle)
                    }
                }
                .setNegativeButton(R.string.angle_input_cancel, null)
                .show()
        }

        trayWidthInput.post {
            trayWidthInput.setText("", false)
            currentAngle = 0
            anglePickerView.setAngle(0)
            angleValueText.text = getString(R.string.angle_value_default)
            syncAngleInteractionState()
            updateResult()
        }

        syncAngleInteractionState()
        updateResult()
    }
}
