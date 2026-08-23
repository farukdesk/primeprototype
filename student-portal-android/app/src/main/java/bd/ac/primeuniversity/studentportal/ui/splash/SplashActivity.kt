package bd.ac.primeuniversity.studentportal.ui.splash

import android.content.Intent
import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.messaging.NotificationHelper
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
        val next = Intent(this, target)
        // Forward push-tap routing. Extras are set directly when a
        // foreground-delivered notification is tapped, and arrive as FCM data
        // extras ("type", "ticket_id") when a background (system-tray)
        // notification is tapped.
        val ticketId = intent.getIntExtra(NotificationHelper.EXTRA_OPEN_TICKET_ID, 0)
            .takeIf { it > 0 }
            ?: if (intent.getStringExtra("type") == "support_ticket") {
                intent.getStringExtra("ticket_id")?.toIntOrNull() ?: 0
            } else {
                0
            }
        val fromPush = intent.getBooleanExtra(NotificationHelper.EXTRA_OPEN_INBOX, false) ||
            intent.getStringExtra("type") == "app_notification"
        if (ticketId > 0) {
            next.putExtra(NotificationHelper.EXTRA_OPEN_TICKET_ID, ticketId)
        } else if (fromPush) {
            next.putExtra(NotificationHelper.EXTRA_OPEN_INBOX, true)
        }
        startActivity(next)
        finish()
    }
}
