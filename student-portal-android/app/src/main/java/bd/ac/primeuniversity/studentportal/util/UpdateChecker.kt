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
 * Self-hosted update prompt. Asks the server (admin/api/app-version.php) for
 * the latest published versionCode and, when it is newer than this build,
 * shows a dialog that opens the APK download link. When the server marks the
 * update as forced, the dialog cannot be dismissed.
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
            if (latest.versionCode <= BuildConfig.VERSION_CODE || latest.apkUrl.isBlank()) {
                return@launch
            }
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
                    try {
                        activity.startActivity(
                            Intent(Intent.ACTION_VIEW, Uri.parse(latest.apkUrl))
                        )
                    } catch (_: Exception) {
                        // No browser available; ignore.
                    }
                }
            if (!latest.force) {
                dialog.setNegativeButton(R.string.update_later, null)
            }
            dialog.show()
        }
    }
}
