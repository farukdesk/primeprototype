package bd.ac.primeuniversity.studentportal.ui.staff

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.os.Bundle
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.databinding.ActivityStaffMainBinding
import bd.ac.primeuniversity.studentportal.messaging.NotificationHelper
import bd.ac.primeuniversity.studentportal.messaging.PushRegistrar
import bd.ac.primeuniversity.studentportal.ui.login.LoginActivity
import bd.ac.primeuniversity.studentportal.ui.notices.NoticesFragment
import bd.ac.primeuniversity.studentportal.ui.notifications.NotificationsActivity
import bd.ac.primeuniversity.studentportal.util.AppResult
import bd.ac.primeuniversity.studentportal.util.UpdateChecker
import kotlinx.coroutines.launch

/** Host screen for the staff/employee view: Home, Notices and Profile tabs. */
class StaffMainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityStaffMainBinding
    private val app: PrimeApp by lazy { application as PrimeApp }

    private val fragments = LinkedHashMap<Int, Fragment>()
    private var activeId = R.id.nav_dashboard

    private val notificationPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestPermission()
    ) { /* Registration proceeds regardless; user can enable later in settings. */ }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityStaffMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

        val tabIds = listOf(R.id.nav_dashboard, R.id.nav_notices, R.id.nav_profile)

        if (savedInstanceState == null) {
            supportFragmentManager.beginTransaction().apply {
                tabIds.forEach { id ->
                    val fragment = newFragment(id)
                    fragments[id] = fragment
                    add(R.id.fragmentContainer, fragment, id.toString())
                    if (id != activeId) hide(fragment)
                }
            }.commit()
        } else {
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

        // Tapping a push notification routes the user to the announcements inbox.
        if (savedInstanceState == null &&
            intent.getBooleanExtra(NotificationHelper.EXTRA_OPEN_INBOX, false)
        ) {
            startActivity(Intent(this, NotificationsActivity::class.java))
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
        R.id.nav_profile -> StaffProfileFragment()
        else -> StaffDashboardFragment()
    }

    override fun onResume() {
        super.onResume()
        refreshSession()
    }

    override fun onSaveInstanceState(outState: Bundle) {
        super.onSaveInstanceState(outState)
        outState.putInt(STATE_ACTIVE, activeId)
    }

    /** Allow child fragments to jump to another tab (e.g. the bell icon). */
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
        if (id == R.id.nav_notices) markNoticesSeen()
    }

    /**
     * Remembers how many notices existed when the employee opened the Notices
     * tab, so the dashboard bell badge only counts notices published since.
     */
    private fun markNoticesSeen() {
        val total = app.currentStaff.value?.stats?.noticesUniversity ?: return
        app.repository.storage.seenStaffNotices = total
        (fragments[R.id.nav_dashboard] as? StaffDashboardFragment)?.refreshBadge()
    }

    private fun refreshSession() {
        lifecycleScope.launch {
            when (val result = app.repository.staffMe()) {
                is AppResult.Success -> app.setStaffSession(result.data)
                is AppResult.Error -> if (result.unauthorized) logout()
            }
        }
    }

    fun logout() {
        lifecycleScope.launch {
            app.repository.logout()
            app.clearSession()
            startActivity(Intent(this@StaffMainActivity, LoginActivity::class.java))
            finishAffinity()
        }
    }

    companion object {
        private const val STATE_ACTIVE = "active_tab"
    }
}
