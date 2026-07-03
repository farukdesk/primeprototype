package bd.ac.primeuniversity.studentportal.ui.main

import android.content.Intent
import android.os.Bundle
import androidx.appcompat.app.AppCompatActivity
import androidx.fragment.app.Fragment
import androidx.lifecycle.lifecycleScope
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.databinding.ActivityMainBinding
import bd.ac.primeuniversity.studentportal.ui.dashboard.DashboardFragment
import bd.ac.primeuniversity.studentportal.ui.finances.FinancesFragment
import bd.ac.primeuniversity.studentportal.ui.login.LoginActivity
import bd.ac.primeuniversity.studentportal.ui.notices.NoticesFragment
import bd.ac.primeuniversity.studentportal.ui.profile.ProfileFragment
import bd.ac.primeuniversity.studentportal.util.AppResult
import kotlinx.coroutines.launch

/** Host screen with a bottom navigation bar and four tabs. */
class MainActivity : AppCompatActivity() {

    private lateinit var binding: ActivityMainBinding
    private val app: PrimeApp by lazy { application as PrimeApp }

    private val fragments = LinkedHashMap<Int, Fragment>()
    private var activeId = R.id.nav_dashboard

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityMainBinding.inflate(layoutInflater)
        setContentView(binding.root)

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
