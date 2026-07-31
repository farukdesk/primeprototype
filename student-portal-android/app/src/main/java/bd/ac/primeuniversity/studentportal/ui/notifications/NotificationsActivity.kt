package bd.ac.primeuniversity.studentportal.ui.notifications

import android.content.Intent
import android.net.Uri
import android.os.Bundle
import android.view.View
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import androidx.recyclerview.widget.LinearLayoutManager
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import bd.ac.primeuniversity.studentportal.data.model.AppNotification
import bd.ac.primeuniversity.studentportal.databinding.ActivityNotificationsBinding
import bd.ac.primeuniversity.studentportal.util.AppResult
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import kotlinx.coroutines.launch

/**
 * Announcements inbox. Lists every push notification published from the admin
 * panel's "App Notification" module (admin/api/student/notifications.php) so
 * students can read announcements inside the app, even after dismissing the
 * system notification or installing the app later.
 */
class NotificationsActivity : AppCompatActivity() {

    private lateinit var binding: ActivityNotificationsBinding
    private val app: PrimeApp by lazy { application as PrimeApp }
    private val adapter = AppNotificationAdapter { showDetail(it) }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        binding = ActivityNotificationsBinding.inflate(layoutInflater)
        setContentView(binding.root)

        binding.btnBack.setOnClickListener { finish() }

        binding.list.layoutManager = LinearLayoutManager(this)
        binding.list.adapter = adapter

        binding.swipeRefresh.setColorSchemeResources(
            R.color.primary, R.color.accent, R.color.info
        )
        binding.swipeRefresh.setOnRefreshListener { load() }

        load(initial = true)
    }

    private fun load(initial: Boolean = false) {
        if (initial) binding.progress.visibility = View.VISIBLE
        binding.emptyState.visibility = View.GONE

        lifecycleScope.launch {
            when (val result = app.repository.getAppNotifications()) {
                is AppResult.Success -> {
                    adapter.submitList(result.data.notifications)
                    binding.emptyState.visibility =
                        if (result.data.notifications.isEmpty()) View.VISIBLE else View.GONE
                }
                is AppResult.Error -> {
                    Toast.makeText(this@NotificationsActivity, result.message, Toast.LENGTH_LONG)
                        .show()
                    if (adapter.itemCount == 0) binding.emptyState.visibility = View.VISIBLE
                }
            }
            binding.progress.visibility = View.GONE
            binding.swipeRefresh.isRefreshing = false
        }
    }

    /** Full announcement in a dialog, with an optional external-link action. */
    private fun showDetail(notification: AppNotification) {
        val dialog = MaterialAlertDialogBuilder(this)
            .setTitle(notification.title)
            .setMessage(notification.body)
            .setPositiveButton(R.string.close, null)

        if (notification.hasLink) {
            dialog.setNeutralButton(R.string.announcements_open_link) { _, _ ->
                try {
                    startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(notification.url)))
                } catch (_: Exception) {
                    // No browser available; ignore.
                }
            }
        }

        dialog.show()
    }
}
