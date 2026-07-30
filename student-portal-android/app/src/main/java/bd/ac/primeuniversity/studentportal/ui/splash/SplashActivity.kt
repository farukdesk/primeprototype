package bd.ac.primeuniversity.studentportal.ui.splash

import android.content.Intent
import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.ui.login.LoginActivity
import bd.ac.primeuniversity.studentportal.ui.main.MainActivity
import bd.ac.primeuniversity.studentportal.ui.staff.StaffMainActivity
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

/**
 * Boot screen. Restores the saved session (if any) for the stored role
 * (student or staff), then routes to the right home screen or the login.
 */
class SplashActivity : AppCompatActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        // The splash theme already draws the branded background + logo.
        val app = application as PrimeApp

        lifecycleScope.launch {
            if (!app.repository.hasToken) {
                goTo(LoginActivity::class.java)
                return@launch
            }

            if (app.repository.isStaff) {
                when (val result = app.repository.staffMe()) {
                    is AppResult.Success -> {
                        app.setStaffSession(result.data)
                        goTo(StaffMainActivity::class.java)
                    }
                    is AppResult.Error -> {
                        if (result.unauthorized) app.clearSession()
                        goTo(LoginActivity::class.java)
                    }
                }
                return@launch
            }

            when (val result = app.repository.me()) {
                is AppResult.Success -> {
                    app.setSession(result.data.student, result.data.stats)
                    goTo(MainActivity::class.java)
                }
                is AppResult.Error -> {
                    if (result.unauthorized) {
                        app.clearSession()
                        goTo(LoginActivity::class.java)
                    } else {
                        // Network hiccup but token exists: allow offline entry.
                        goTo(LoginActivity::class.java)
                    }
                }
            }
        }
    }

    private fun goTo(target: Class<*>) {
        startActivity(Intent(this, target))
        finish()
    }
}
