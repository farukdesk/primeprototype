package bd.ac.primeuniversity.studentportal.util

import android.content.Intent
import android.net.Uri
import androidx.appcompat.app.AppCompatActivity
import androidx.lifecycle.lifecycleScope
import bd.ac.primeuniversity.studentportal.BuildConfig
import bd.ac.primeuniversity.studentportal.PrimeApp
import bd.ac.primeuniversity.studentportal.R
import com.google.android.material.dialog.MaterialAlertDialogBuilder
import kotlinx.coroutines.launch

/**
 * Update prompt shown right after sign-in (MainActivity / StaffMainActivity).
 *
 * Asks the server (admin/api/app-version.php) for the latest published
 * versionCode and, when it is newer than this build, shows a dialog:
 *  - Self-hosted builds open the APK download link published by the server.
 *  - Google Play builds open the app's Play Store listing instead – prompting
 *    users to update via Play is allowed; only self-updating APKs are not
 *    (Device and Network Abuse policy).
 * When the server marks the update as forced, the dialog cannot be dismissed.
 */
object UpdateChecker {

    private var checkedThisProcess = false

    fun maybePromptForUpdate(activity: AppCompatActivity) {
        if (checkedThisProcess) return
        checkedThisProcess = true

        val app = activity.application as? PrimeApp ?: return
        activity.lifecycleScope.launch {
            val result = app.repository.getLatestAppVersion()
            if (result !is AppResult.Success) return@launch

            val latest = result.data
            if (latest.versionCode <= BuildConfig.VERSION_CODE) return@launch
            // Self-hosted builds need a published APK URL; Play builds fall
            // back to the Play Store listing, so no URL is required.
            if (BuildConfig.SELF_UPDATE_ENABLED && latest.apkUrl.isBlank()) return@launch
            if (activity.isFinishing || activity.isDestroyed) return@launch

            val message = buildString {
                append(activity.getString(R.string.update_body, latest.versionName))
                if (latest.notes.isNotBlank()) {
                    append("\n\n")
                    append(latest.notes)
                }
            }

            val dialog = MaterialAlertDialogBuilder(activity)
                .setTitle(R.string.update_title)
                .setMessage(message)
                .setCancelable(!latest.force)
                .setPositiveButton(R.string.update_now) { _, _ ->
                    openUpdateTarget(activity, latest.apkUrl)
                }
            if (!latest.force) {
                dialog.setNegativeButton(R.string.update_later, null)
            }
            dialog.show()
        }
    }

    /**
     * Self-hosted flavor: open the APK download link.
     * Play flavor: open the Play Store listing (market:// first, then the
     * web URL when the Play Store app is unavailable).
     */
    private fun openUpdateTarget(activity: AppCompatActivity, apkUrl: String) {
        if (BuildConfig.SELF_UPDATE_ENABLED && apkUrl.isNotBlank()) {
            try {
                activity.startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(apkUrl)))
            } catch (_: Exception) {
                // No browser available; ignore.
            }
            return
        }

        val packageName = BuildConfig.APPLICATION_ID.removeSuffix(".debug")
        try {
            activity.startActivity(
                Intent(Intent.ACTION_VIEW, Uri.parse("market://details?id=$packageName"))
            )
        } catch (_: Exception) {
            try {
                activity.startActivity(
                    Intent(
                        Intent.ACTION_VIEW,
                        Uri.parse("https://play.google.com/store/apps/details?id=$packageName")
                    )
                )
            } catch (_: Exception) {
                // Neither the Play Store app nor a browser is available; ignore.
            }
        }
    }
}
