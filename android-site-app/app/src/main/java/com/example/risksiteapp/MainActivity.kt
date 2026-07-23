package com.example.risksiteapp

import android.os.Bundle
import android.widget.TextView
import androidx.appcompat.app.AppCompatActivity

class MainActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        val angleValueText = findViewById<TextView>(R.id.angleValueText)
        val anglePickerView = findViewById<AnglePickerView>(R.id.anglePickerView)

        anglePickerView.onAngleChanged = { angle ->
            angleValueText.text = getString(R.string.angle_value_format, angle)
        }
    }
}
