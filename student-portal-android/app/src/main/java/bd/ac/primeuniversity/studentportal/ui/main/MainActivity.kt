package bd.ac.primeuniversity.studentportal.ui.main

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.core.view.ViewCompat
import androidx.core.view.WindowInsetsCompat
import androidx.core.view.updatePadding
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.databinding.ActivityMainBinding
import bd.ac.primeuniversity.studentportal.messaging.NotificationHelper
import bd.ac.primeuniversity.studentportal.messaging.PushRegistrar
import bd.ac.primeuniversity.studentportal.ui.dashboard.DashboardFragment
import bd.ac.primeuniversity.studentportal.ui.finances.FinancesFragment
import bd.ac.primeuniversity.studentportal.ui.login.LoginActivity
import bd.ac.primeuniversity.studentportal.ui.notices.NoticesFragment
import bd.ac.primeuniversity.studentportal.ui.notifications.NotificationsActivity
import bd.ac.primeuniversity.studentportal.ui.profile.ProfileFragment
import bd.ac.primeuniversity.studentportal.util.AppResult
import bd.ac.primeuniversity.studentportal.util.UpdateChecker
import kotlinx.coroutines.launch

/** Host screen with a bottom navigation bar and four tabs. */
class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private val app: PrimeApp by lazy { application as PrimeApp }

    private val fragments = LinkedHashMap<Int, Fragment>()
    private var activeId = R.id.nav_dashboard

    private val notificationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { /* Registration proceeds regardless; user can enable later in settings. */ }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        // Edge-to-edge is enforced when targeting API 35+: keep content below
        // the status bar / display cutout and keep the nav bar clear of the
        // system nav bar.
        ViewCompat.setOnApplyWindowInsetsListener(binding.root) { _, insets ->
            val bars = insets.getInsets(
                WindowInsetsCompat.Type.systemBars() or WindowInsetsCompat.Type.displayCutout()
            )
            binding.fragmentContainer.updatePadding(top = bars.top)
            binding.bottomNav.updatePadding(bottom = bars.bottom)
            insets
        }
        // The first insets pass can be dispatched before the listener is
        // attached on some devices, leaving the header under the status bar.
        // Explicitly request a pass so the padding is always applied.
        ViewCompat.requestApplyInsets(binding.root)

        val tabIds = listOf(
            R.id.nav_dashboard, R.id.nav_notices, R.id.nav_finances, R.id.nav_profile
        )

        if (savedInstanceState == null) {
            // First launch: create and attach all fragments, IndexedStack style.
            supportFragmentManager.beginTransaction().apply {
                tabIds.forEach { id ->
                    val fragment = newFragment(id)
                    fragments[id] = fragment
                    add(R.id.fragmentContainer, fragment, id.toString())
                    if (id != activeId) hide(fragment)
                }
            }.commit()
        } else {
            // Restored: reuse the fragments the FragmentManager already recreated.
            activeId = savedInstanceState.getInt(STATE_ACTIVE, R.id.nav_dashboard)
            tabIds.forEach { id ->
                val fragment = supportFragmentManager.findFragmentByTag(id.toString())
                    ?: newFragment(id).also {
                        supportFragmentManager.beginTransaction()
                            .add(R.id.fragmentContainer, it, id.toString())
                            .apply { if (id != activeId) hide(it) }
                            .commit()
                    }
                fragments[id] = fragment
            }
        }

        binding.bottomNav.setOnItemSelectedListener { item ->
            switchTo(item.itemId)
            true
        }
        binding.bottomNav.selectedItemId = activeId

        // Ask for notification permission (Android 13+) and register this device
        // for push notifications from the admin panel.
        maybeRequestNotificationPermission()
        PushRegistrar.registerCurrentToken(this)

        // Tapping a push notification (delivered in the foreground) routes the
        // user straight to the announcements inbox.
        if (savedInstanceState == null &&
            intent.getBooleanExtra(NotificationHelper.EXTRA_OPEN_INBOX, false)
        ) {
            startActivity(Intent(this, NotificationsActivity::class.java))
        }

        // Tapping a support-ticket push (new reply / status change) routes the
        // user straight to that ticket's comment thread.
        val pushTicketId = intent.getIntExtra(NotificationHelper.EXTRA_TICKET_ID, 0)
        if (savedInstanceState == null && pushTicketId > 0) {
            startActivity(
                Intent(
                    this,
                    bd.ac.primeuniversity.studentportal.ui.support.SupportTicketDetailActivity::class.java
                ).putExtra(
                    bd.ac.primeuniversity.studentportal.ui.support.SupportTicketDetailActivity.EXTRA_TICKET_ID,
                    pushTicketId
                )
            )
        }

        // Self-hosted distribution: prompt when the server has a newer APK.
        UpdateChecker.maybePromptForUpdate(this)
    }

    private fun maybeRequestNotificationPermission() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.TIRAMISU) return
        val granted = ContextCompat.checkSelfPermission(
            this, Manifest.permission.POST_NOTIFICATIONS
        ) == PackageManager.PERMISSION_GRANTED
        if (!granted) {
            notificationPermissionLauncher.launch(Manifest.permission.POST_NOTIFICATIONS)
        }
    }

    private fun newFragment(id: Int): Fragment = when (id) {
        R.id.nav_notices -> NoticesFragment()
        R.id.nav_finances -> FinancesFragment()
        R.id.nav_profile -> ProfileFragment()
        else -> DashboardFragment()
    }

    override fun onResume() {
        super.onResume()
        refreshSession()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        outState.putInt(STATE_ACTIVE, activeId)
    }

    /** Allow child fragments to jump to another tab (e.g. "View all"). */
    fun selectTab(itemId: Int) {
        binding.bottomNav.selectedItemId = itemId
    }

    private fun switchTo(id: Int) {
        if (id == activeId) return
        val target = fragments[id] ?: return
        val current = fragments[activeId] ?: return
        supportFragmentManager.beginTransaction()
            .setCustomAnimations(R.anim.fade_in, R.anim.fade_out)
            .hide(current)
            .show(target)
            .commit()
        activeId = id
    }

    private fun refreshSession() {
        lifecycleScope.launch {
            when (val result = app.repository.me()) {
                is AppResult.Success ->
                    app.setSession(result.data.student, result.data.stats)
                is AppResult.Error ->
                    if (result.unauthorized) logout()
            }
        }
    }

    fun logout() {
        lifecycleScope.launch {
            app.repository.logout()
            app.clearSession()
            startActivity(Intent(this@MainActivity, LoginActivity::class.java))
            finishAffinity()
        }
    }

    companion object {
        private const val STATE_ACTIVE = "active_tab"
    }
}
