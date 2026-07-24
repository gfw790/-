package com.example.risksiteapp

import android.content.Intent
import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import com.google.android.material.button.MaterialButton

class MainActivity : AppCompatActivity() {
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        findViewById<MaterialButton>(R.id.oneFoldButton).setOnClickListener {
            startActivity(Intent(this, OneFoldActivity::class.java))
        }

        findViewById<MaterialButton>(R.id.twoFoldButton).setOnClickListener {
            startActivity(Intent(this, TwoFoldActivity::class.java))
        }
    }
}
